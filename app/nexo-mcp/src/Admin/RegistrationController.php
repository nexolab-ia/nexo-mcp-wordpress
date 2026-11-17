<?php
/**
 * Acciones AJAX del registro obligatorio.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use RuntimeException;
use NexoDev\NexoMCP\Registration\RegistrationClient;
use NexoDev\NexoMCP\Registration\RegistrationState;

/**
 * Valida permisos y mantiene los secretos fuera del navegador.
 */
final class RegistrationController {

	public const NONCE_ACTION = 'nexo_mcp_registration';

	/**
	 * Estado local del registro.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $state;

	/**
	 * Cliente HTTPS del registro.
	 *
	 * @var RegistrationClient
	 */
	private RegistrationClient $client;

	/**
	 * Construye el controlador.
	 *
	 * @param RegistrationState  $state  Estado local.
	 * @param RegistrationClient $client Cliente remoto.
	 */
	public function __construct( RegistrationState $state, RegistrationClient $client ) {
		$this->state  = $state;
		$this->client = $client;
	}

	/**
	 * Registra las acciones administrativas.
	 */
	public function register(): void {
		add_action( 'wp_ajax_nexo_mcp_request_registration_code', array( $this, 'request_code' ) );
		add_action( 'wp_ajax_nexo_mcp_verify_registration_code', array( $this, 'verify_code' ) );
		add_action( 'wp_ajax_nexo_mcp_check_registration', array( $this, 'check' ) );
		add_action( 'wp_ajax_nexo_mcp_deactivate_registration', array( $this, 'deactivate' ) );
	}

	/**
	 * Solicita el OTP después de consentimiento explícito.
	 */
	public function request_code(): void {
		$this->authorize();

		$email   = $this->posted_email();
		$consent = isset( $_POST['consent'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado en authorize().
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST['consent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $consent ) {
			$this->send_error( __( 'Debes aceptar la política de privacidad.', 'nexo-mcp' ) );
		}

		try {
			$this->client->request_code( $email, $this->state->installation_id() );
		} catch ( RuntimeException $exception ) {
			$this->send_error( $exception->getMessage() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Código enviado. Revisa tu bandeja de entrada.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Verifica el OTP y persiste el token sólo en WordPress.
	 */
	public function verify_code(): void {
		$this->authorize();

		$email = $this->posted_email();
		$code  = isset( $_POST['code'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado en authorize().
			? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			$this->send_error( __( 'Ingresa los seis dígitos del código.', 'nexo-mcp' ) );
		}

		try {
			$result = $this->client->verify_code( $email, $this->state->installation_id(), $code );
		} catch ( RuntimeException $exception ) {
			$this->send_error( $exception->getMessage() );
		}

		$token           = isset( $result['token'] ) && is_string( $result['token'] ) ? $result['token'] : '';
		$consent_version = isset( $result['consentVersion'] ) && is_string( $result['consentVersion'] )
			? $result['consentVersion']
			: '';

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) || '' === $consent_version ) {
			$this->send_error( __( 'La verificación no entregó una credencial válida.', 'nexo-mcp' ) );
		}

		$this->state->verify( $email, $token, $consent_version );

		wp_send_json_success(
			array(
				'message' => __( 'Email verificado. Tu instalación quedó registrada.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Actualiza la última verificación bajo demanda.
	 */
	public function check(): void {
		$this->authorize();

		if ( ! $this->state->is_verified() ) {
			$this->send_error( __( 'Esta instalación todavía no está verificada.', 'nexo-mcp' ) );
		}

		try {
			$this->client->check( $this->state->installation_id(), $this->state->token() );
		} catch ( RuntimeException $exception ) {
			$this->send_error( $exception->getMessage() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Registro verificado correctamente.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Revoca la instalación remota y elimina el token local.
	 */
	public function deactivate(): void {
		$this->authorize();

		if ( $this->state->is_verified() ) {
			try {
				$this->client->deactivate( $this->state->installation_id(), $this->state->token() );
			} catch ( RuntimeException $exception ) {
				$this->send_error( $exception->getMessage() );
			}
		}

		$this->state->clear();

		wp_send_json_success(
			array(
				'message' => __( 'Registro desconectado y token revocado.', 'nexo-mcp' ),
			)
		);
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
	}

	/**
	 * Obtiene un email válido.
	 */
	private function posted_email(): string {
		$email = isset( $_POST['email'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado por el caller.
			? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! is_email( $email ) ) {
			$this->send_error( __( 'Ingresa un email válido.', 'nexo-mcp' ) );
		}

		return $email;
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
