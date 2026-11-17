<?php
/**
 * Envío de reportes y sugerencias desde el panel.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use RuntimeException;
use NexoDev\NexoMCP\Builders\BuilderRegistry;
use NexoDev\NexoMCP\Registration\RegistrationClient;
use NexoDev\NexoMCP\Registration\RegistrationState;
use NexoDev\NexoMCP\Security\RateLimiter;

/**
 * Permite al propietario del sitio enviar errores y sugerencias al servicio configurado.
 *
 * Sólo las instalaciones verificadas pueden reportar: la credencial es el par
 * `installation_id` + `token` que ya emitió el registro.
 */
final class ReportController {

	public const NONCE_ACTION = 'nexo_mcp_send_report';
	public const FORM_ACTION  = 'nexo_mcp_send_report';
	public const ANCHOR       = 'nexo-mcp-reportar';

	private const NOTICE_TRANSIENT   = 'nexo_mcp_report_notice_';
	private const MAX_REPORTS_HOUR   = 5;
	private const MIN_MESSAGE_LENGTH = 10;
	private const MAX_MESSAGE_LENGTH = 2000;
	private const KINDS              = array( 'error', 'sugerencia' );

	/**
	 * Estado local del registro obligatorio.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $state;

	/**
	 * Cliente HTTPS del servicio configurado.
	 *
	 * @var RegistrationClient
	 */
	private RegistrationClient $client;

	/**
	 * Límite de solicitudes respaldado por base de datos.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Construye el controlador.
	 *
	 * @param RegistrationState  $state        Estado local del registro.
	 * @param RegistrationClient $client       Cliente remoto.
	 * @param RateLimiter|null   $rate_limiter Límite de solicitudes.
	 */
	public function __construct(
		RegistrationState $state,
		RegistrationClient $client,
		?RateLimiter $rate_limiter = null
	) {
		$this->state        = $state;
		$this->client       = $client;
		$this->rate_limiter = $rate_limiter ?? new RateLimiter();
	}

	/**
	 * Registra la acción administrativa.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Máximo de caracteres admitido por el formulario.
	 */
	public static function max_message_length(): int {
		return self::MAX_MESSAGE_LENGTH;
	}

	/**
	 * Mínimo de caracteres admitido por el formulario.
	 */
	public static function min_message_length(): int {
		return self::MIN_MESSAGE_LENGTH;
	}

	/**
	 * Valida el envío y lo entrega al servicio configurado.
	 */
	public function handle_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para completar esta acción.', 'nexo-mcp' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->state->is_verified() ) {
			$this->redirect_with_notice(
				'error',
				__( 'Primero verifica el email de esta instalación más arriba en esta página.', 'nexo-mcp' )
			);
		}

		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( (string) $_POST['kind'] ) ) : '';
		$message = isset( $_POST['message'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) )
			: '';

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$this->redirect_with_notice(
				'error',
				__( 'Elige si es un error o una sugerencia.', 'nexo-mcp' ),
				$message
			);
		}

		$length = mb_strlen( $message );

		if ( $length < self::MIN_MESSAGE_LENGTH || $length > self::MAX_MESSAGE_LENGTH ) {
			$this->redirect_with_notice(
				'error',
				sprintf(
					/* translators: 1: mínimo de caracteres, 2: máximo de caracteres. */
					__( 'Escribe entre %1$d y %2$d caracteres.', 'nexo-mcp' ),
					self::MIN_MESSAGE_LENGTH,
					self::MAX_MESSAGE_LENGTH
				),
				$message,
				$kind
			);
		}

		// El techo local acompaña al del servicio: evita que esta pantalla
		// dispare decenas de solicitudes antes de que el servidor las rechace.
		$limit = $this->rate_limiter->consume(
			'report:' . get_current_user_id(),
			'admin_report',
			self::MAX_REPORTS_HOUR,
			HOUR_IN_SECONDS
		);

		if ( is_wp_error( $limit ) || ! $limit->allowed ) {
			$this->redirect_with_notice(
				'error',
				__( 'Ya enviaste varios reportes en la última hora. Inténtalo más tarde.', 'nexo-mcp' ),
				$message,
				$kind
			);
		}

		try {
			$this->client->report(
				$this->state->installation_id(),
				$this->state->token(),
				$kind,
				$message,
				$this->context()
			);
		} catch ( RuntimeException $exception ) {
			$this->redirect_with_notice( 'error', $exception->getMessage(), $message, $kind );
		}

		$this->redirect_with_notice(
			'success',
			__( 'Reporte enviado. Gracias por ayudar a mejorar el conector.', 'nexo-mcp' )
		);
	}

	/**
	 * Contexto técnico acotado, sin secretos ni registros crudos.
	 *
	 * @return array<string, string>
	 */
	private function context(): array {
		return array(
			'pluginVersion'    => NEXO_MCP_VERSION,
			'wordpressVersion' => (string) get_bloginfo( 'version' ),
			'phpVersion'       => PHP_VERSION,
			'builders'         => implode( ', ', BuilderRegistry::active_slugs() ),
		);
	}

	/**
	 * Guarda el resultado y vuelve al bloque del panel.
	 *
	 * @param string $type    Tipo de aviso.
	 * @param string $message Mensaje para el administrador.
	 * @param string $draft   Texto que conviene no perder tras un error.
	 * @param string $kind    Tipo de reporte ya elegido.
	 */
	private function redirect_with_notice(
		string $type,
		string $message,
		string $draft = '',
		string $kind = ''
	): never {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
				'draft'   => $draft,
				'kind'    => in_array( $kind, self::KINDS, true ) ? $kind : '',
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			admin_url( 'admin.php?page=' . AdminPage::PAGE_SLUG ) . '#' . self::ANCHOR
		);
		exit;
	}

	/**
	 * Lee y consume el aviso pendiente.
	 *
	 * @return array{type: string, message: string, draft: string, kind: string}|null
	 */
	public static function take_notice(): ?array {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'type'    => isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error',
			'message' => (string) $notice['message'],
			'draft'   => isset( $notice['draft'] ) ? (string) $notice['draft'] : '',
			'kind'    => isset( $notice['kind'] ) ? (string) $notice['kind'] : '',
		);
	}
}
