<?php
/**
 * Acciones de la licencia: activar, revalidar y desconectar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use RuntimeException;
use NexoDev\NexoMCP\Licensing\LicenseClient;
use NexoDev\NexoMCP\Registration\RegistrationState;
use NexoDev\NexoMCP\Updates\UpdateState;

/**
 * Traduce lo que responde la plataforma en acciones sobre el estado local.
 *
 * Aquí no se decide nada sobre cupos ni planes: eso vive en la plataforma. Lo
 * único que este controlador resuelve es qué hacer cuando no hay respuesta.
 */
final class LicenseController {

	public const NONCE_ACTION = 'nexo_mcp_license';

	/**
	 * Evento de revalidación periódica.
	 */
	public const CRON_HOOK = 'nexo_mcp_license_check';

	/**
	 * Horas que puede tener la última comprobación antes de repetirla al
	 * abrir el panel.
	 *
	 * El cron diario no basta: una licencia revocada seguiría operando hasta
	 * veinticuatro horas, y quien entra al panel espera ver el estado real, no
	 * el de ayer. Con este margen la comprobación no se dispara en cada carga.
	 */
	private const STALE_HOURS = 6;

	/**
	 * Estado local de la instalación.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $state;

	/**
	 * Cliente del servicio de licencias.
	 *
	 * @var LicenseClient
	 */
	private LicenseClient $client;

	/**
	 * Información local de versiones disponibles.
	 *
	 * @var UpdateState
	 */
	private UpdateState $updates;

	/**
	 * Construye el controlador.
	 *
	 * @param RegistrationState $state   Estado local.
	 * @param LicenseClient     $client  Cliente remoto.
	 * @param UpdateState|null  $updates Información de versiones.
	 */
	public function __construct(
		RegistrationState $state,
		LicenseClient $client,
		?UpdateState $updates = null
	) {
		$this->state   = $state;
		$this->client  = $client;
		$this->updates = $updates ?? new UpdateState();
	}

	/**
	 * Engancha las acciones administrativas y la revalidación diaria.
	 */
	public function register(): void {
		add_action( 'wp_ajax_nexo_mcp_activate_license', array( $this, 'activate' ) );
		add_action( 'wp_ajax_nexo_mcp_recheck_license', array( $this, 'recheck' ) );
		add_action( 'wp_ajax_nexo_mcp_check_updates', array( $this, 'check_updates' ) );
		add_action( 'wp_ajax_nexo_mcp_release_license', array( $this, 'release' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( 'load-toplevel_page_' . AdminPage::PAGE_SLUG, array( $this, 'refresh_if_stale' ) );

		// Al entrar a Plugins, y al pulsar «Buscar actualizaciones», se
		// aprovecha para preguntar si la información de versiones quedó vieja.
		// Es donde el usuario espera ver el aviso, así que es donde tiene que
		// estar al día.
		add_action( 'load-plugins.php', array( $this, 'refresh_updates_if_stale' ) );
		add_action( 'load-update-core.php', array( $this, 'refresh_updates_if_stale' ) );

		add_action( 'upgrader_process_complete', array( $this, 'report_after_update' ), 10, 2 );
	}

	/**
	 * Avisa a la plataforma en cuanto este plugin se actualiza.
	 *
	 * Sin esto, la versión que consta en la plataforma es la anterior hasta la
	 * siguiente revalidación, que puede tardar un día. Quien atiende un reporte
	 * de fallo estaría mirando una versión que ese sitio ya no ejecuta, y esa
	 * confusión cuesta más que la consulta que se ahorra.
	 *
	 * @param object               $upgrader Instancia del actualizador.
	 * @param array<string, mixed> $extra    Detalles de la operación.
	 */
	public function report_after_update( $upgrader, $extra ): void {
		unset( $upgrader );

		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}

		if ( 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		$archivo  = plugin_basename( NEXO_MCP_FILE );
		$afectado = in_array( $archivo, (array) ( $extra['plugins'] ?? array() ), true );

		if ( ! $afectado || ! $this->state->is_verified() ) {
			return;
		}

		// La versión en memoria sigue siendo la anterior: el archivo nuevo se
		// carga en la siguiente petición. Se aplaza el aviso para que informe
		// la versión que realmente va a ejecutarse.
		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
	}

	/**
	 * Refresca la información de versiones si lleva más de medio día sin
	 * comprobarse, o si el administrador la pidió a propósito.
	 *
	 * Cuando alguien pulsa «Buscar de nuevo» en la pantalla de actualizaciones,
	 * WordPress añade `force-check` a la URL. Respetar el caché de medio día en
	 * ese momento es contradecir una orden explícita: la persona está ahí
	 * justamente porque sabe que hay algo nuevo y no lo ve.
	 */
	public function refresh_updates_if_stale(): void {
		if ( ! $this->state->is_verified() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sólo decide si se consulta antes de tiempo; no cambia nada por sí solo.
		$forzado = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );

		if ( $forzado || $this->updates->is_stale() ) {
			$this->refresh();
		}
	}

	/**
	 * Revalida al abrir el panel si la última comprobación quedó vieja.
	 *
	 * Sin esto, una licencia revocada —o un registro borrado del otro lado—
	 * seguiría dando por bueno el estado local hasta que corriera el cron.
	 * Quien abre el panel quiere ver lo que pasa ahora.
	 */
	public function refresh_if_stale(): void {
		if ( ! $this->state->is_verified() ) {
			return;
		}

		$ultima = $this->state->all()['last_check'];

		// Sin marca previa se comprueba: es una instalación que viene del
		// registro anterior y todavía no ha hablado con el servicio nuevo.
		if ( '' !== $ultima ) {
			$momento = strtotime( $ultima . ' UTC' );

			if ( false !== $momento && ( time() - $momento ) < self::STALE_HOURS * HOUR_IN_SECONDS ) {
				return;
			}
		}

		$this->refresh();
	}

	/**
	 * Programa la revalidación diaria si todavía no lo está.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cancela la revalidación diaria.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Canjea la clave que pegó el administrador.
	 */
	public function activate(): void {
		$this->authorize();

		$clave = isset( $_POST['license_key'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado en authorize().
			? sanitize_text_field( wp_unslash( (string) $_POST['license_key'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( '' === $clave ) {
			$this->send_error( __( 'Pega la clave de licencia que te entregó tu proveedor.', 'nexo-mcp' ) );
		}

		try {
			$respuesta = $this->client->activate( $clave, $this->state->installation_id() );
		} catch ( RuntimeException $exception ) {
			$this->send_error( $this->humanize( $exception ) );
		}

		$token    = isset( $respuesta['token'] ) && is_string( $respuesta['token'] ) ? $respuesta['token'] : '';
		$consent  = isset( $respuesta['consentVersion'] ) && is_string( $respuesta['consentVersion'] )
			? $respuesta['consentVersion']
			: '';
		$licencia = isset( $respuesta['license'] ) && is_array( $respuesta['license'] )
			? $respuesta['license']
			: array();

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{40,50}$/', $token ) || '' === $consent || array() === $licencia ) {
			$this->send_error( __( 'La activación no entregó una credencial válida.', 'nexo-mcp' ) );
		}

		$email = isset( $licencia['email'] ) && is_string( $licencia['email'] ) ? $licencia['email'] : '';

		$this->state->activate_with_license(
			$email,
			$token,
			$consent,
			$this->prefix_of( $clave ),
			$licencia
		);

		$cuenta = ! empty( $licencia['countsSlot'] );

		wp_send_json_success(
			array(
				'message' => $cuenta
					? sprintf(
						/* translators: 1: sitios usados, 2: sitios permitidos. */
						__( 'Listo. Este sitio quedó activado: llevas %1$d de %2$d.', 'nexo-mcp' ),
						(int) ( $licencia['used'] ?? 0 ),
						(int) ( $licencia['limit'] ?? 0 )
					)
					: __( 'Listo. Es un entorno de pruebas, así que no consume ninguno de tus sitios.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Vuelve a preguntar por el estado de la licencia bajo demanda.
	 */
	public function recheck(): void {
		$this->authorize();

		if ( ! $this->state->is_verified() ) {
			$this->send_error( __( 'Este sitio todavía no tiene una licencia activada.', 'nexo-mcp' ) );
		}

		$resultado = $this->refresh();

		if ( 'ok' !== $resultado ) {
			$this->send_error(
				'offline' === $resultado
					? sprintf(
						/* translators: %d: días restantes. */
						__( 'No fue posible contactar la plataforma. El conector sigue funcionando %d días más.', 'nexo-mcp' ),
						$this->state->grace_days_left()
					)
					: __( 'La licencia ya no está activa para este sitio.', 'nexo-mcp' )
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'Licencia confirmada.', 'nexo-mcp' ) )
		);
	}

	/**
	 * Busca una versión nueva al momento, desde la pestaña Actualizaciones.
	 *
	 * Es la misma consulta que revalida la licencia —la respuesta de la
	 * plataforma trae qué versión le toca al sitio—, pero contesta con lo que
	 * importa en esa pestaña: si hay versión nueva y qué trae, para dibujarla
	 * en el panel sin recargar la página.
	 */
	public function check_updates(): void {
		$this->authorize();

		if ( ! $this->state->is_verified() ) {
			$this->send_error( __( 'Este sitio todavía no tiene una licencia activada, así que no recibe versiones nuevas.', 'nexo-mcp' ) );
		}

		$resultado = $this->refresh();

		if ( 'offline' === $resultado ) {
			$this->send_error( __( 'No fue posible contactar la plataforma para buscar versiones. Inténtalo en unos minutos.', 'nexo-mcp' ) );
		}

		if ( 'ok' !== $resultado ) {
			$this->send_error( __( 'La licencia de este sitio ya no está activa, así que no recibe versiones nuevas.', 'nexo-mcp' ) );
		}

		$update    = $this->updates->all();
		$published = '' !== $update['published'] ? strtotime( $update['published'] ) : false;

		wp_send_json_success(
			array(
				'available'  => $this->updates->has_update(),
				'installed'  => NEXO_MCP_VERSION,
				'version'    => $update['version'],
				'published'  => false !== $published ? gmdate( 'd-m-Y', $published ) : '',
				'checkedAt'  => $update['checked_at'],
				'changelog'  => array_values( array_filter( array_map( 'trim', explode( "\n", wp_strip_all_tags( $update['changelog'] ) ) ) ) ),
				'updatesUrl' => admin_url( 'plugins.php' ),
			)
		);
	}

	/**
	 * Libera el cupo de este sitio y borra la credencial local.
	 */
	public function release(): void {
		$this->authorize();

		if ( $this->state->is_verified() ) {
			try {
				$this->client->release( $this->state->installation_id(), $this->state->token() );
			} catch ( RuntimeException $exception ) {
				// Un fallo de red no debe dejar el sitio atrapado: se limpia
				// igual y el cupo se recupera desde el panel web.
				if ( ! $this->is_inconclusive( $exception ) ) {
					$this->send_error( $this->humanize( $exception ) );
				}
			}
		}

		$this->state->clear();

		// Sin licencia no hay canal de actualizaciones: dejar la oferta guardada
		// haría que WordPress siguiera anunciando una versión que este sitio ya
		// no tiene derecho a descargar.
		$this->updates->forget();
		delete_site_transient( 'update_plugins' );

		wp_send_json_success(
			array( 'message' => __( 'Este sitio quedó desconectado y su cupo liberado.', 'nexo-mcp' ) )
		);
	}

	/**
	 * Revalidación automática diaria.
	 */
	public function run_scheduled_check(): void {
		if ( $this->state->is_verified() ) {
			$this->refresh();
		}
	}

	/**
	 * Pregunta a la plataforma y actualiza el estado local.
	 *
	 * Distingue los dos fallos posibles, que exigen reacciones opuestas: si la
	 * plataforma responde que la licencia no vale, se corta; si no responde, se
	 * cuenta el margen y se sigue trabajando.
	 *
	 * @return string `ok`, `offline` o `revoked`.
	 */
	private function refresh(): string {
		if ( ! \NexoDev\NexoMCP\Licensing\ApiEndpoint::is_configured() ) {
			return 'ok';
		}

		try {
			$respuesta = $this->client->status( $this->state->installation_id(), $this->state->token() );
		} catch ( RuntimeException $exception ) {
			if ( $this->is_inconclusive( $exception ) ) {
				$this->state->mark_offline();

				return 'offline';
			}

			$this->state->clear();
			$this->updates->forget();
			delete_site_transient( 'update_plugins' );

			return 'revoked';
		}

		$this->state->store_license(
			isset( $respuesta['license'] ) && is_array( $respuesta['license'] ) ? $respuesta['license'] : array(),
			isset( $respuesta['latestVersion'] ) && is_string( $respuesta['latestVersion'] )
				? $respuesta['latestVersion']
				: ''
		);

		// La misma respuesta trae qué versión le toca a este sitio. Guardarla
		// aquí evita una segunda consulta y un segundo cron para lo mismo.
		$this->updates->store(
			isset( $respuesta['update'] ) && is_array( $respuesta['update'] )
				? $respuesta['update']
				: null
		);

		// WordPress guarda su lista de actualizaciones en caché. Si no se
		// descarta, el aviso no aparece hasta que a WordPress le toque
		// revisar por su cuenta, que puede ser doce horas después.
		delete_site_transient( 'update_plugins' );

		return 'ok';
	}

	/**
	 * Convierte el fallo del cliente en un mensaje para el administrador.
	 *
	 * @param RuntimeException $exception Fallo capturado.
	 */
	private function humanize( RuntimeException $exception ): string {
		if ( LicenseClient::UNREACHABLE === $exception->getMessage() ) {
			return __( 'No fue posible contactar el servicio de licencias. Revisa que este servidor tenga salida a internet e inténtalo de nuevo.', 'nexo-mcp' );
		}

		if ( LicenseClient::SERVICE_ERROR === $exception->getMessage() ) {
			return __( 'El servicio de licencias respondió con un error. No es tu conexión ni tu clave: vuelve a intentarlo en un momento y, si sigue igual, avísanos.', 'nexo-mcp' );
		}

		return $exception->getMessage();
	}

	/**
	 * Indica si el fallo impide confirmar, en vez de negar la licencia.
	 *
	 * Los dos casos —sin respuesta y con respuesta rota— dejan el conector
	 * trabajando dentro del margen. Sólo un rechazo explícito lo detiene.
	 *
	 * @param RuntimeException $exception Fallo capturado.
	 */
	private function is_inconclusive( RuntimeException $exception ): bool {
		return in_array(
			$exception->getMessage(),
			array( LicenseClient::UNREACHABLE, LicenseClient::SERVICE_ERROR ),
			true
		);
	}

	/**
	 * Prefijo visible de la clave, para mostrarla sin exponerla entera.
	 *
	 * @param string $clave Clave completa.
	 */
	private function prefix_of( string $clave ): string {
		$limpia = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', $clave ) ?? '' );

		return substr( $limpia, 0, 5 ) . '-' . substr( $limpia, 5, 5 );
	}

	/**
	 * Verifica nonce y capacidad administrativa.
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos para completar esta acción.', 'nexo-mcp' ) ),
				403
			);
		}

		if ( ! \NexoDev\NexoMCP\Licensing\ApiEndpoint::is_configured() ) {
			$this->send_error( __( 'No hay un servicio de licencias configurado. Define la constante NEXO_MCP_API_URL en wp-config.php para usar esta función; mientras tanto, el conector funciona completo.', 'nexo-mcp' ) );
		}
	}

	/**
	 * Devuelve un error JSON consistente.
	 *
	 * @param string $message Mensaje seguro para el administrador.
	 */
	private function send_error( string $message ): never {
		wp_send_json_error(
			array( 'message' => sanitize_text_field( $message ) ),
			400
		);
	}
}
