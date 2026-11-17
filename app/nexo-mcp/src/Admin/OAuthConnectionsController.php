<?php
/**
 * Operaciones AJAX sobre las conexiones OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\OAuth\PersonalTokenIssuer;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\OAuth\TokenRepository;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Permite al propietario revocar accesos concedidos por OAuth.
 */
final class OAuthConnectionsController {

	public const NONCE_ACTION = 'nexo_mcp_oauth_connections';

	/**
	 * Acción de admin-post que cambia los alcances de una conexión.
	 */
	public const SCOPES_ACTION = 'nexo_mcp_set_connection_scopes';

	/**
	 * Transitorio del aviso que se muestra al volver al panel.
	 */
	public const SCOPES_NOTICE = 'nexo_mcp_scopes_notice_';

	/**
	 * Almacenamiento de tokens.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Registro de seguridad.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Construye el controlador.
	 *
	 * @param TokenRepository $repository Almacenamiento de tokens.
	 * @param AuditLogger     $audit      Auditoría.
	 */
	public function __construct( TokenRepository $repository, AuditLogger $audit ) {
		$this->repository = $repository;
		$this->audit      = $audit;
	}

	/**
	 * Registra el endpoint AJAX.
	 */
	public function register(): void {
		add_action( 'wp_ajax_nexo_mcp_revoke_oauth_connection', array( $this, 'revoke' ) );
		add_action( 'wp_ajax_nexo_mcp_issue_personal_token', array( $this, 'issue_token' ) );
		add_action( 'wp_ajax_nexo_mcp_set_connection_profile', array( $this, 'set_connection_profile' ) );
		add_action( 'admin_post_' . self::SCOPES_ACTION, array( $this, 'set_connection_scopes' ) );
	}

	/**
	 * Define el alcance que solicitarán las conexiones OAuth nuevas.
	 */
	public function set_connection_profile(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' )
			|| ! current_user_can( Capabilities::MANAGE_CREDENTIALS )
		) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos para cambiar el perfil de conexión.', 'nexo-mcp' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
		$profile = isset( $_POST['profile'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
			? sanitize_key( wp_unslash( (string) $_POST['profile'] ) )
			: '';

		if ( ! in_array( $profile, array( 'read', 'manage' ), true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'El perfil de conexión no es válido.', 'nexo-mcp' ) ),
				400
			);
		}

		$stored = get_option( Settings::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored['read_only'] = 'read' === $profile;
		update_option( Settings::OPTION_NAME, $stored, false );

		$scopes = ScopeMap::for_profile( $profile, new Settings() );

		$this->audit->log(
			'oauth.connection_profile',
			'admin',
			'success',
			array(
				'user_id' => get_current_user_id(),
				'profile' => $profile,
			)
		);

		wp_send_json_success(
			array(
				'profile' => $profile,
				'scopes'  => ScopeMap::to_string( $scopes ),
				'message' => $this->profile_message( $profile ),
			)
		);
	}

	/**
	 * Redacta la confirmación del cambio de perfil.
	 *
	 * El perfil rige de inmediato también para las conexiones ya autorizadas,
	 * sin reconectar la IA (ADR-027).
	 *
	 * @param string $profile Perfil recién guardado.
	 */
	private function profile_message( string $profile ): string {
		if ( 'read' === $profile ) {
			return __( 'Listo. La IA sólo podrá revisar el sitio.', 'nexo-mcp' );
		}

		return __( 'Listo. La IA podrá revisar y editar mediante los módulos habilitados.', 'nexo-mcp' );
	}

	/**
	 * Emite un token de acceso para pegarlo directamente en un cliente.
	 *
	 * Es la salida para los clientes que no completan bien el flujo OAuth. El
	 * token sale una sola vez en esta respuesta: después queda su hash, igual
	 * que con cualquier otra credencial.
	 */
	public function issue_token(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' )
			|| ! current_user_can( Capabilities::MANAGE_CREDENTIALS )
		) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos para emitir tokens.', 'nexo-mcp' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
		$etiqueta = isset( $_POST['label'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
			? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) )
			: '';

		$usuario = get_current_user_id();
		$token   = ( new PersonalTokenIssuer( $this->repository ) )->issue( $usuario, $etiqueta );

		if ( null === $token ) {
			$this->audit->log( 'oauth.personal_token', 'admin', 'denied', array( 'user_id' => $usuario ) );

			wp_send_json_error(
				array( 'message' => __( 'Tu usuario no tiene permisos MCP para emitir un token.', 'nexo-mcp' ) ),
				403
			);
		}

		$this->audit->log(
			'oauth.personal_token',
			'admin',
			'success',
			array(
				'user_id' => $usuario,
				'label'   => $etiqueta,
			)
		);

		wp_send_json_success(
			array(
				'token'    => $token,
				'endpoint' => rest_url( 'nexo-mcp/v1/mcp' ),
				'expires'  => gmdate( 'Y-m-d', time() + PersonalTokenIssuer::TTL ),
				'message'  => __( 'Token emitido. Cópialo ahora: no se vuelve a mostrar.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Cambia los alcances concedidos a una conexión existente.
	 *
	 * Es la edición de «alcances por conexión»: el dueño puede restringir o
	 * ampliar lo que un cliente concreto puede hacer sin obligarlo a
	 * reconectarse. Nunca se concede un scope que el titular no podría ejercer.
	 */
	public function set_connection_scopes(): void {
		check_admin_referer( self::SCOPES_ACTION );

		if ( ! current_user_can( 'manage_options' )
			|| ! current_user_can( Capabilities::MANAGE_CREDENTIALS )
		) {
			wp_die( esc_html__( 'No tienes permisos para administrar conexiones.', 'nexo-mcp' ), 403 );
		}

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
		$user_id   = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$requested = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['scopes'] ) )
			: array();

		$back = add_query_arg( 'tab', 'accesos', admin_url( 'admin.php?page=' . AdminPage::PAGE_SLUG ) );

		if ( '' === $client_id || 0 === $user_id ) {
			set_transient( self::SCOPES_NOTICE . get_current_user_id(), array( 'type' => 'error', 'message' => __( 'Conexión no identificada.', 'nexo-mcp' ) ), MINUTE_IN_SECONDS );
			wp_safe_redirect( $back );
			exit;
		}

		$scopes = ScopeMap::filter_by_user( ScopeMap::parse( implode( ' ', $requested ) ), $user_id );

		if ( ! in_array( ScopeMap::CONNECT, $scopes, true ) ) {
			array_unshift( $scopes, ScopeMap::CONNECT );
		}

		$updated = $this->repository->update_connection_scope( $user_id, $client_id, ScopeMap::to_string( $scopes ) );

		$this->audit->log(
			'oauth.connection_scopes',
			'admin',
			'success',
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
				'scopes'    => ScopeMap::to_string( $scopes ),
			)
		);

		set_transient(
			self::SCOPES_NOTICE . get_current_user_id(),
			array(
				'type'    => $updated > 0 ? 'success' : 'warning',
				'message' => $updated > 0
					? __( 'Alcances actualizados. Rigen desde la próxima llamada del cliente.', 'nexo-mcp' )
					: __( 'La conexión ya no tiene tokens vigentes; no se cambió nada.', 'nexo-mcp' ),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Recupera y borra el aviso del último cambio de alcances.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take_scopes_notice(): ?array {
		$key    = self::SCOPES_NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}

	/**
	 * Revoca todos los tokens de un cliente para un usuario.
	 */
	public function revoke(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' )
			|| ! current_user_can( Capabilities::MANAGE_CREDENTIALS )
		) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos para administrar conexiones.', 'nexo-mcp' ) ),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
		$client_id = isset( $_POST['client_id'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
			? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) )
			: '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verificado al inicio del método.
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		if ( '' === $client_id || 0 === $user_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Conexión no identificada.', 'nexo-mcp' ) ),
				400
			);
		}

		$this->repository->revoke_for_user( $user_id, $client_id );

		$this->audit->log(
			'oauth.revoke',
			'admin',
			'success',
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
			)
		);

		wp_send_json_success(
			array( 'message' => __( 'Conexión revocada. El cliente perdió el acceso de inmediato.', 'nexo-mcp' ) )
		);
	}
}
