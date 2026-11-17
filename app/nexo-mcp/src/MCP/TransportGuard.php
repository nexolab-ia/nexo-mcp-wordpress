<?php
/**
 * Guardia de acceso al transporte MCP.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Authentication\AuthenticationContext;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Registration\RegistrationState;
use NexoDev\NexoMCP\Security\ClientIpResolver;
use NexoDev\NexoMCP\Security\HttpsPolicy;
use NexoDev\NexoMCP\Security\RateLimiter;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Aplica seguridad antes de que el adaptador procese JSON-RPC.
 */
final class TransportGuard {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Política HTTPS.
	 *
	 * @var HttpsPolicy
	 */
	private HttpsPolicy $https_policy;

	/**
	 * Política de capacidades.
	 *
	 * @var PermissionPolicy
	 */
	private PermissionPolicy $permission_policy;

	/**
	 * Limitador persistente.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Auditoría persistente.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Resolución de IP.
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $ip_resolver;

	/**
	 * Estado del registro obligatorio.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $registration_state;

	/**
	 * Construye la cadena de controles del transporte.
	 *
	 * @param Settings               $settings          Configuración operativa.
	 * @param HttpsPolicy            $https_policy      Política HTTPS.
	 * @param PermissionPolicy       $permission_policy Política de capacidades.
	 * @param RateLimiter            $rate_limiter       Limitador persistente.
	 * @param AuditLogger            $audit_logger       Auditoría segura.
	 * @param RegistrationState|null $registration_state Estado del registro obligatorio.
	 */
	public function __construct(
		Settings $settings,
		HttpsPolicy $https_policy,
		PermissionPolicy $permission_policy,
		RateLimiter $rate_limiter,
		AuditLogger $audit_logger,
		?RegistrationState $registration_state = null
	) {
		$this->settings           = $settings;
		$this->https_policy       = $https_policy;
		$this->permission_policy  = $permission_policy;
		$this->rate_limiter       = $rate_limiter;
		$this->audit_logger       = $audit_logger;
		$this->registration_state = $registration_state ?? new RegistrationState();
		$this->ip_resolver        = new ClientIpResolver();
	}

	/**
	 * Autoriza una solicitud HTTP autenticada con Application Password.
	 *
	 * @return bool|\WP_Error
	 */
	public function authorize() {
		return $this->check( false );
	}

	/**
	 * Autoriza una subida que entró con un ticket de un solo uso (ADR-030).
	 *
	 * Es el mismo recorrido que `authorize()`; lo único distinto es que el
	 * ticket, ya canjeado por `UploadController`, cuenta como credencial. El
	 * transporte MCP llama a `authorize()`, así que un ticket nunca lo abre.
	 *
	 * @return bool|\WP_Error
	 */
	public function authorize_upload_ticket() {
		return $this->check( true );
	}

	/**
	 * Recorre la cadena de controles del transporte.
	 *
	 * @param bool $ticket Si la petición se autenticó con un ticket de subida.
	 * @return bool|\WP_Error
	 */
	private function check( bool $ticket ) {
		if ( ! (bool) $this->settings->get( 'server_enabled' )
			|| ! (bool) $this->settings->get( 'wordpress_tools_enabled' )
		) {
			return $this->deny( 'nexo_mcp_server_disabled', 'Servidor MCP desactivado.' );
		}

		// La verificación del email es requisito de uso. Se comprueba antes que la
		// autenticación para que ocultar el panel no sea el único control: un token
		// emitido antes de desconectar el registro tampoco puede seguir operando.
		if ( ! $this->registration_state->meets_requirement() ) {
			return $this->deny(
				'nexo_mcp_registration_required',
				'Esta instalación no ha verificado su email en el panel Nexo MCP.'
			);
		}

		if ( ! $this->https_policy->allows_current_request() ) {
			return $this->deny( 'nexo_mcp_https_required', 'Se requiere HTTPS.' );
		}

		// Se admiten dos formas de autenticación: un token Bearer emitido por el
		// servidor de autorización propio, o una Application Password de WordPress.
		// La subida de medios admite además su ticket de un solo uso.
		$bearer = BearerTokenGuard::is_authenticated();

		if ( ! is_user_logged_in() || ( ! $bearer && ! $ticket && null === AuthenticationContext::credential_uuid() ) ) {
			return $this->deny( 'nexo_mcp_authentication_required', 'Se requiere un token de acceso o una Application Password válida.' );
		}

		if ( $bearer && ! ScopeMap::satisfies( BearerTokenGuard::scopes(), array( ScopeMap::CONNECT ) ) ) {
			return $this->deny( 'nexo_mcp_insufficient_scope', 'El token no incluye el scope de conexión.' );
		}

		if ( ! $this->permission_policy->can_connect() ) {
			return $this->deny( 'nexo_mcp_connection_forbidden', 'El usuario no tiene acceso MCP.' );
		}

		$identity = (string) get_current_user_id() . '|' . ( $this->ip_resolver->resolve() ?? 'unknown' );

		/**
		 * Permite ajustar el techo de solicitudes por minuto del transporte.
		 *
		 * Un agente trabajando encadena decenas de llamadas seguidas —listar,
		 * leer varias entradas, editar— así que un techo pensado para uso manual
		 * lo cortaría en mitad de una tarea legítima.
		 *
		 * @param int $limit Solicitudes permitidas por minuto.
		 */
		$per_minute = (int) apply_filters( 'nexo_mcp_transport_rate_limit', 240 );
		$limit      = $this->rate_limiter->consume( $identity, 'transport', $per_minute, MINUTE_IN_SECONDS );

		if ( is_wp_error( $limit ) ) {
			$this->audit_logger->log( 'transport', 'read', 'error', array(), $limit );
			return $limit;
		}

		if ( ! $limit->allowed ) {
			$error = new \WP_Error(
				'nexo_mcp_rate_limit_exceeded',
				__( 'Demasiadas solicitudes. Intenta nuevamente más tarde.', 'nexo-mcp' ),
				array( 'retry_after' => $limit->retry_after )
			);
			$this->audit_logger->log( 'transport', 'read', 'limited', array(), $error );
			return $error;
		}

		return true;
	}

	/**
	 * Registra y devuelve una denegación segura.
	 *
	 * @param string $code    Código estable.
	 * @param string $message Mensaje seguro.
	 */
	private function deny( string $code, string $message ): \WP_Error {
		$error = new \WP_Error( $code, $message );
		$this->audit_logger->log( 'transport', 'read', 'denied', array(), $error );

		return $error;
	}
}
