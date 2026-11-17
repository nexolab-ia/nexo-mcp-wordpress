<?php
/**
 * Endpoint de carga directa de archivos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Media;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\TransportGuard;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;

/**
 * Recibe un archivo por HTTP y lo entrega al gateway de medios.
 *
 * Existe porque MCP viaja en JSON-RPC y no tiene canal binario: meter una
 * imagen en un parámetro obliga a codificarla en base64, que multiplica el
 * tamaño y, en un agente, pasa entera por su ventana de contexto. Para un
 * lote de fotos eso es inviable, así que la carga pesada entra por aquí.
 *
 * Toda la seguridad es prestada a propósito: `TransportGuard` para llegar al
 * servidor, `PermissionPolicy` para poder escribir y `UploadValidator` para
 * aceptar el archivo. Nada se revalida por segunda vez con reglas propias.
 */
final class UploadController {

	private const NAMESPACE = 'nexo-mcp/v1';

	/**
	 * Controles de acceso al transporte.
	 *
	 * @var TransportGuard
	 */
	private TransportGuard $guard;

	/**
	 * Política de capacidades.
	 *
	 * @var PermissionPolicy
	 */
	private PermissionPolicy $policy;

	/**
	 * Gateway de medios.
	 *
	 * @var ContentAdminGateway
	 */
	private ContentAdminGateway $content;

	/**
	 * Auditoría persistente.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Techo de subidas, compartido con la herramienta MCP.
	 *
	 * @var UploadThrottle
	 */
	private UploadThrottle $throttle;

	/**
	 * Enlaces de subida de un solo uso.
	 *
	 * @var UploadTicket
	 */
	private UploadTicket $tickets;

	/**
	 * Ticket canjeado en esta petición, si entró con uno.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $ticket = null;

	/**
	 * Construye el controlador.
	 *
	 * @param TransportGuard      $guard    Controles de transporte.
	 * @param PermissionPolicy    $policy   Política de capacidades.
	 * @param ContentAdminGateway $content  Gateway de medios.
	 * @param AuditLogger         $audit    Auditoría persistente.
	 * @param UploadThrottle|null $throttle Techo de subidas.
	 * @param UploadTicket|null   $tickets  Enlaces de subida.
	 */
	public function __construct(
		TransportGuard $guard,
		PermissionPolicy $policy,
		ContentAdminGateway $content,
		AuditLogger $audit,
		?UploadThrottle $throttle = null,
		?UploadTicket $tickets = null
	) {
		$this->guard    = $guard;
		$this->policy   = $policy;
		$this->content  = $content;
		$this->audit    = $audit;
		$this->throttle = $throttle ?? new UploadThrottle();
		$this->tickets  = $tickets ?? new UploadTicket();
	}

	/**
	 * Engancha el registro de la ruta.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declara la ruta de carga.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'filename' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'alt'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Estado HTTP que corresponde a cada denegación del transporte.
	 *
	 * `TransportGuard` nació para el endpoint MCP, donde el adaptador traduce
	 * el error a JSON-RPC y el estado da igual. Aquí es una ruta REST normal:
	 * un WP_Error sin estado sale como 500, y un cliente que recibe «error del
	 * servidor» cuando en realidad le falta el token no tiene forma de saberlo.
	 */
	private const ESTADOS = array(
		'nexo_mcp_server_disabled'         => 503,
		'nexo_mcp_registration_required'   => 403,
		'nexo_mcp_https_required'          => 403,
		'nexo_mcp_authentication_required' => 401,
		'nexo_mcp_insufficient_scope'      => 403,
		'nexo_mcp_connection_forbidden'    => 403,
		'nexo_mcp_rate_limit_exceeded'     => 429,
	);

	/**
	 * Decide si la solicitud puede subir un archivo.
	 *
	 * @param \WP_REST_Request|null $request Solicitud en curso.
	 * @return true|\WP_Error
	 */
	public function check_permission( ?\WP_REST_Request $request = null ) {
		$ticket = null === $request ? null : $request->get_param( 'ticket' );

		// El ticket que entrega upload-media es la alternativa al token para
		// quien no lo tiene a mano, como un agente que sube con curl (ADR-030).
		// Sólo se mira en esta ruta: no es un token y no abre ninguna otra.
		if ( null !== $ticket ) {
			$canje = $this->redeem_ticket( is_string( $ticket ) ? $ticket : '' );

			if ( is_wp_error( $canje ) ) {
				return $canje;
			}
		}

		// TransportGuard resuelve de una vez el servidor apagado, el email sin
		// verificar, el HTTPS y la autenticación. Ese último punto importa
		// especialmente aquí: exige Bearer, Application Password o el ticket,
		// así que una cookie de sesión no basta y el endpoint no es alcanzable
		// por CSRF.
		$autorizado = null === $ticket ? $this->guard->authorize() : $this->guard->authorize_upload_ticket();

		if ( is_wp_error( $autorizado ) ) {
			return $this->con_estado( $autorizado );
		}

		if ( ! $this->policy->can_module_write(
			'content_tools_enabled',
			Capabilities::CONTENT_ADVANCED,
			'upload_files'
		) ) {
			$error = new \WP_Error(
				'nexo_mcp_media_upload_forbidden',
				__( 'No tienes permiso para subir archivos, o el sitio está en modo sólo lectura.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);

			$this->audit->log( 'media-upload', 'write', 'denied', array(), $error );

			return $error;
		}

		return true;
	}

	/**
	 * Canjea el ticket y deja a su titular como usuario de esta petición.
	 *
	 * WordPress evalúa el permiso dos veces por petición (ver
	 * `consume_rate_limit()`). El ticket se gasta en la primera y se recuerda
	 * para la segunda: gastarlo otra vez la negaría y dejaría un rechazo falso
	 * en la auditoría de cada subida buena. Cada instancia atiende una sola
	 * petición.
	 *
	 * @param string $ticket Ticket recibido en la URL.
	 * @return true|\WP_Error
	 */
	private function redeem_ticket( string $ticket ) {
		if ( null === $this->ticket ) {
			$datos = $this->tickets->consume( $ticket );

			if ( null === $datos ) {
				$error = new \WP_Error(
					'nexo_mcp_upload_ticket_invalid',
					__( 'El enlace de subida no es válido, venció o ya se usó. Pide otro con upload-media.', 'nexo-mcp' ),
					array( 'status' => 401 )
				);

				$this->audit->log( 'media-upload', 'write', 'denied', array(), $error );

				return $error;
			}

			$this->ticket = $datos;
		}

		wp_set_current_user( (int) $this->ticket['user_id'] );

		return true;
	}

	/**
	 * Pone el estado HTTP que corresponde a una denegación del transporte.
	 *
	 * @param \WP_Error $error   Error recibido del guardia.
	 * @param int       $defecto Estado a usar si el código no está en el mapa.
	 */
	private function con_estado( \WP_Error $error, int $defecto = 403 ): \WP_Error {
		$datos = $error->get_error_data();
		$datos = is_array( $datos ) ? $datos : array();

		if ( isset( $datos['status'] ) ) {
			return $error;
		}

		$datos['status'] = self::ESTADOS[ $error->get_error_code() ] ?? $defecto;

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $datos );
	}

	/**
	 * Aplica el techo de subidas, que se comparte con la herramienta MCP.
	 *
	 * Se consume aquí y no en `check_permission()` a propósito: WordPress
	 * evalúa el `permission_callback` **dos veces** por petición, así que un
	 * contador puesto ahí gasta el doble y convierte un techo de 30 en 15.
	 * Un callback de permisos debe poder repetirse sin consecuencias.
	 *
	 * @return true|\WP_Error
	 */
	private function consume_rate_limit() {
		$limite = $this->throttle->consume();

		if ( is_wp_error( $limite ) ) {
			$estado = 'nexo_mcp_rate_limit_exceeded' === $limite->get_error_code() ? 'limited' : 'error';

			$this->audit->log( 'media-upload', 'write', $estado, array(), $limite );

			return $limite;
		}

		return true;
	}

	/**
	 * Recibe el cuerpo binario y lo deja en la biblioteca.
	 *
	 * @param \WP_REST_Request $request Solicitud validada.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_upload( \WP_REST_Request $request ) {
		$inicio = microtime( true );
		$limite = $this->consume_rate_limit();

		if ( is_wp_error( $limite ) ) {
			return $limite;
		}

		// `curl -F file=@…`, la forma que entrega el enlace de upload-media:
		// PHP ya dejó el archivo en su temporal y el cuerpo llega vacío.
		$archivo = $request->get_file_params()['file'] ?? null;

		if ( is_array( $archivo ) ) {
			return $this->recibir_multipart( $request, $archivo, $inicio );
		}

		$cuerpo = $request->get_body();

		if ( '' === $cuerpo ) {
			return $this->fallo(
				new \WP_Error(
					'nexo_mcp_media_body_empty',
					__( 'No llegó ningún archivo. Envía el contenido en el cuerpo de la solicitud; si el archivo es grande, revisa post_max_size en el servidor.', 'nexo-mcp' ),
					array( 'status' => 400 )
				),
				$inicio
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temporal = wp_tempnam( 'nexo-upload' );

		if ( '' === $temporal ) {
			return $this->fallo(
				new \WP_Error(
					'nexo_mcp_media_tempfile',
					__( 'No se pudo crear el archivo temporal.', 'nexo-mcp' ),
					array( 'status' => 500 )
				),
				$inicio
			);
		}

		$escritos = file_put_contents( $temporal, $cuerpo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Volcado del cuerpo recibido a un temporal local, no hay API de WordPress para esto.

		if ( false === $escritos ) {
			wp_delete_file( $temporal );

			return $this->fallo(
				new \WP_Error(
					'nexo_mcp_media_tempfile',
					__( 'No se pudo escribir el archivo temporal.', 'nexo-mcp' ),
					array( 'status' => 500 )
				),
				$inicio
			);
		}

		return $this->guardar( $request, $temporal, '', $inicio );
	}

	/**
	 * Toma el archivo de una subida multipart.
	 *
	 * @param \WP_REST_Request     $request Solicitud validada.
	 * @param array<string, mixed> $archivo Entrada del campo `file`.
	 * @param float                $inicio  Marca de inicio.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function recibir_multipart( \WP_REST_Request $request, array $archivo, float $inicio ) {
		$temporal = is_string( $archivo['tmp_name'] ?? null ) ? $archivo['tmp_name'] : '';

		// is_uploaded_file() confirma que la ruta la dejó PHP al recibir el
		// archivo y no la escribió quien llama.
		if ( UPLOAD_ERR_OK !== (int) ( $archivo['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $temporal ) ) {
			return $this->fallo(
				new \WP_Error(
					'nexo_mcp_media_body_empty',
					__( 'No llegó ningún archivo en el campo file. Si el archivo es grande, revisa upload_max_filesize en el servidor.', 'nexo-mcp' ),
					array( 'status' => 400 )
				),
				$inicio
			);
		}

		return $this->guardar( $request, $temporal, is_string( $archivo['name'] ?? null ) ? $archivo['name'] : '', $inicio );
	}

	/**
	 * Entrega el archivo al gateway y responde.
	 *
	 * @param \WP_REST_Request $request  Solicitud validada.
	 * @param string           $temporal Archivo temporal en disco.
	 * @param string           $nombre   Nombre que traía el archivo, si lo traía.
	 * @param float            $inicio   Marca de inicio.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function guardar( \WP_REST_Request $request, string $temporal, string $nombre, float $inicio ) {
		$filename = (string) $request->get_param( 'filename' );

		// Lo aprobado al emitir el ticket manda sobre lo que traiga el POST.
		$metadatos = array_merge(
			array(
				'filename' => '' !== $filename ? $filename : $nombre,
				'title'    => $request->get_param( 'title' ),
				'alt'      => $request->get_param( 'alt' ),
			),
			$this->ticket['params'] ?? array()
		);

		// A partir de aquí manda el gateway: valida los bytes y adjunta. El
		// Content-Type que mandó el cliente no se mira en ningún momento.
		$resultado = $this->content->store_upload( $temporal, $metadatos );

		if ( is_wp_error( $resultado ) ) {
			return $this->fallo( $resultado, $inicio );
		}

		$this->audit->log(
			'media-upload',
			'write',
			'success',
			array( 'filename' => $metadatos['filename'] ),
			array( 'id' => $resultado['id'] ?? 0 ),
			( microtime( true ) - $inicio ) * 1000
		);

		return new \WP_REST_Response( $resultado, 201 );
	}

	/**
	 * Registra el fallo y lo devuelve tal cual.
	 *
	 * @param \WP_Error $error  Error producido.
	 * @param float     $inicio Marca de inicio.
	 */
	private function fallo( \WP_Error $error, float $inicio ): \WP_Error {
		$this->audit->log(
			'media-upload',
			'write',
			'error',
			array(),
			$error,
			( microtime( true ) - $inicio ) * 1000
		);

		// Un archivo rechazado por su tipo, su peso o sus dimensiones es un
		// error de quien lo manda, no del servidor: sale 400 salvo que el
		// error ya traiga su propio estado.
		return $this->con_estado( $error, 400 );
	}
}
