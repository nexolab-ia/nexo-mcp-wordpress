<?php
/**
 * Operaciones AJAX de credenciales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Authentication\ApplicationPasswordManager;
use NexoDev\NexoMCP\Authorization\Capabilities;

/**
 * Expone acciones administrativas con nonce y capability.
 */
final class CredentialController {

	public const NONCE_ACTION = 'nexo_mcp_credentials';

	/**
	 * Gestor de Application Passwords.
	 *
	 * @var ApplicationPasswordManager
	 */
	private ApplicationPasswordManager $manager;

	/**
	 * Registro de seguridad.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Construye el controlador.
	 *
	 * @param ApplicationPasswordManager $manager Gestor de Application Passwords.
	 * @param AuditLogger                $audit   Auditoría.
	 */
	public function __construct( ApplicationPasswordManager $manager, AuditLogger $audit ) {
		$this->manager = $manager;
		$this->audit   = $audit;
	}

	/**
	 * Registra endpoints AJAX sólo para usuarios autenticados.
	 */
	public function register(): void {
		add_action( 'wp_ajax_nexo_mcp_create_credential', array( $this, 'create' ) );
		add_action( 'wp_ajax_nexo_mcp_revoke_credential', array( $this, 'revoke' ) );
		add_action( 'wp_ajax_nexo_mcp_rotate_credential', array( $this, 'rotate' ) );
	}

	/**
	 * Crea una credencial.
	 */
	public function create(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->authorize();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio de create().
		$user_id = $this->posted_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio de create().
		$label = isset( $_POST['label'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio de create().
			? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) )
			: '';
		$result = $this->manager->create( $user_id, $label );

		if ( is_wp_error( $result ) ) {
			$this->send_error( 'credential.create', $result, array( 'user_id' => $user_id ) );
		}

		$user = get_userdata( $user_id );
		$this->audit->log( 'credential.create', 'admin', 'success', array( 'user_id' => $user_id ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Credencial creada. Cópiala ahora; no volverá a mostrarse.', 'nexo-mcp' ),
				'password' => $result['password'],
				'uuid'     => (string) $result['item']['uuid'],
				'name'     => (string) $result['item']['name'],
				'username' => false !== $user ? $user->user_login : '',
			)
		);
	}

	/**
	 * Revoca una credencial.
	 */
	public function revoke(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->authorize();

		$user_id = $this->posted_user_id();
		$uuid    = $this->posted_uuid();
		$result  = $this->manager->revoke( $user_id, $uuid );

		if ( is_wp_error( $result ) ) {
			$this->send_error( 'credential.revoke', $result, compact( 'user_id', 'uuid' ) );
		}

		$this->audit->log( 'credential.revoke', 'admin', 'success', compact( 'user_id', 'uuid' ) );
		wp_send_json_success( array( 'message' => __( 'Credencial revocada.', 'nexo-mcp' ) ) );
	}

	/**
	 * Rota una credencial y devuelve el nuevo secreto una sola vez.
	 */
	public function rotate(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->authorize();

		$user_id = $this->posted_user_id();
		$uuid    = $this->posted_uuid();
		$result  = $this->manager->rotate( $user_id, $uuid );

		if ( is_wp_error( $result ) ) {
			$this->send_error( 'credential.rotate', $result, compact( 'user_id', 'uuid' ) );
		}

		$user = get_userdata( $user_id );
		$this->audit->log( 'credential.rotate', 'admin', 'success', compact( 'user_id', 'uuid' ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Credencial rotada. La anterior ya no funciona.', 'nexo-mcp' ),
				'password' => $result['password'],
				'uuid'     => (string) $result['item']['uuid'],
				'name'     => (string) $result['item']['name'],
				'username' => false !== $user ? $user->user_login : '',
			)
		);
	}

	/**
	 * Valida capacidad después de comprobar el nonce.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREDENTIALS ) ) {
			$this->audit->log( 'credential.denied', 'admin', 'denied' );
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos para administrar credenciales.', 'nexo-mcp' ) ),
				403
			);
		}
	}

	/**
	 * Obtiene un ID de usuario positivo.
	 */
	private function posted_user_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado por el método AJAX público.
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		if ( 0 === $user_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Selecciona un usuario válido.', 'nexo-mcp' ) ),
				400
			);
		}

		return $user_id;
	}

	/**
	 * Obtiene un UUID validado de POST.
	 */
	private function posted_uuid(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado por el método AJAX público.
		$uuid = isset( $_POST['uuid'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado por el método AJAX público.
			? sanitize_text_field( wp_unslash( (string) $_POST['uuid'] ) )
			: '';

		if ( ! wp_is_uuid( $uuid ) ) {
			wp_send_json_error(
				array( 'message' => __( 'La credencial no es válida.', 'nexo-mcp' ) ),
				400
			);
		}

		return $uuid;
	}

	/**
	 * Audita y responde un WP_Error.
	 *
	 * @param string               $tool       Acción.
	 * @param \WP_Error            $error      Error seguro.
	 * @param array<string, mixed> $parameters Contexto no secreto.
	 */
	private function send_error( string $tool, \WP_Error $error, array $parameters ): void {
		$this->audit->log( $tool, 'admin', 'error', $parameters, array( 'code' => $error->get_error_code() ) );
		wp_send_json_error( array( 'message' => $error->get_error_message() ), 400 );
	}
}
