<?php
/**
 * Endpoints REST del servidor de autorización.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Security\ClientIpResolver;
use NexoDev\NexoMCP\Security\HttpsPolicy;
use NexoDev\NexoMCP\Security\RateLimiter;

/**
 * Emite, refresca y revoca tokens, y admite el registro dinámico de clientes.
 */
final class TokenController {

	/**
	 * Espacio de nombres REST del conector.
	 */
	private const NAMESPACE = 'nexo-mcp/v1';

	/**
	 * Ventana, en segundos, dentro de la cual un canje repetido se considera
	 * el reenvío de una petición perdida y no una reutilización hostil.
	 */
	private const RETRY_WINDOW = 20;

	/**
	 * Ventana, en segundos, durante la cual un refresh ya rotado sigue
	 * atendiendo reintentos.
	 *
	 * Más larga que la del código porque aquí el reintento no lo dispara una
	 * persona sino el cliente, y sus tiempos de espera de red rondan el medio
	 * minuto.
	 */
	private const REFRESH_GRACE = 60;

	/**
	 * Registro de clientes.
	 *
	 * @var ClientRegistry
	 */
	private ClientRegistry $clients;

	/**
	 * Almacenamiento persistente.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Política HTTPS.
	 *
	 * @var HttpsPolicy
	 */
	private HttpsPolicy $https_policy;

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
	 * Construye los endpoints.
	 *
	 * @param ClientRegistry  $clients      Registro de clientes.
	 * @param TokenRepository $repository   Almacenamiento persistente.
	 * @param HttpsPolicy     $https_policy Política HTTPS.
	 * @param RateLimiter     $rate_limiter Limitador persistente.
	 * @param AuditLogger     $audit_logger Auditoría segura.
	 */
	public function __construct(
		ClientRegistry $clients,
		TokenRepository $repository,
		HttpsPolicy $https_policy,
		RateLimiter $rate_limiter,
		AuditLogger $audit_logger
	) {
		$this->clients      = $clients;
		$this->repository   = $repository;
		$this->https_policy = $https_policy;
		$this->rate_limiter = $rate_limiter;
		$this->audit_logger = $audit_logger;
		$this->ip_resolver  = new ClientIpResolver();
	}

	/**
	 * Registra las rutas REST.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declara las rutas del servidor de autorización.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		// Conjunto de claves vacío: el documento OIDC exige un `jwks_uri` válido, pero no se emiten id_tokens.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/jwks',
			array(
				'methods'             => 'GET',
				'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( array( 'keys' => array() ), 200 ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Atiende la emisión de tokens.
	 *
	 * @param \WP_REST_Request $request Solicitud entrante.
	 */
	public function handle_token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->https_policy->allows_current_request() ) {
			return $this->error( 'invalid_request', 'Se requiere HTTPS.', 400 );
		}

		$throttle = $this->throttle( 'oauth_token', 30 );

		if ( null !== $throttle ) {
			return $throttle;
		}

		$grant_type = (string) $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return $this->grant_authorization_code( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->grant_refresh_token( $request );
		}

		return $this->error( 'unsupported_grant_type', 'Tipo de concesión no admitido.', 400 );
	}

	/**
	 * Canjea un authorization code por un par de tokens.
	 *
	 * @param \WP_REST_Request $request Solicitud entrante.
	 */
	private function grant_authorization_code( \WP_REST_Request $request ): \WP_REST_Response {
		$code          = (string) $request->get_param( 'code' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );
		$resource      = (string) $request->get_param( 'resource' );

		if ( '' === $code || '' === $client_id || '' === $code_verifier ) {
			return $this->error( 'invalid_request', 'Faltan parámetros obligatorios.', 400 );
		}

		// El código se consume antes de validar el resto. Un canje fallido —PKCE
		// incorrecto, cliente que no corresponde, URI distinta— delata una posible
		// captura, así que el código queda quemado y no admite un segundo intento.
		$record = $this->repository->consume_code( $code );

		if ( null === $record ) {
			$reused = $this->repository->find_consumed_code( $code );

			if ( null !== $reused ) {
				// Un reintento honesto —un timeout de red y su reenvío— llega a los
				// pocos segundos, desde el mismo cliente y con el mismo verificador.
				// Tratarlo como robo desconectaría al usuario sin motivo, así que
				// sólo se revoca el linaje cuando esas señales no coinciden.
				$benign = $this->looks_like_retry( $reused, $client_id, $code_verifier );

				if ( ! $benign ) {
					$this->repository->revoke_lineage( (string) $reused['code_hash'] );
				}

				$this->audit_logger->log(
					'oauth',
					'read',
					'denied',
					array(
						'reason'    => $benign ? 'authorization_code_retry' : 'authorization_code_reuse',
						'client_id' => $client_id,
					)
				);
			}

			return $this->error( 'invalid_grant', 'El código de autorización no es válido.', 400 );
		}

		if ( ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			return $this->error( 'invalid_grant', 'El código no pertenece a este cliente.', 400 );
		}

		if ( '' !== $redirect_uri && ! hash_equals( (string) $record['redirect_uri'], $redirect_uri ) ) {
			return $this->error( 'invalid_grant', 'La URI de retorno no coincide.', 400 );
		}

		if ( ! Pkce::verify( $code_verifier, (string) $record['code_challenge'] ) ) {
			return $this->error( 'invalid_grant', 'La verificación PKCE falló.', 400 );
		}

		$audience = (string) $record['resource'];

		// La barra final se ignora igual que al autorizar: el cliente que canjea
		// es el mismo que autorizó, pero no siempre reconstruye la dirección con
		// idéntica forma, y esa diferencia no cambia a qué recurso apunta.
		if ( '' !== $resource
			&& ! hash_equals( untrailingslashit( $audience ), untrailingslashit( trim( $resource ) ) )
		) {
			return $this->error( 'invalid_target', 'El recurso solicitado no coincide con el autorizado.', 400 );
		}

		$scopes = ScopeMap::parse( (string) $record['scope'] );

		return $this->issue_tokens(
			$client_id,
			(int) $record['user_id'],
			$scopes,
			$audience,
			(string) $record['code_hash']
		);
	}

	/**
	 * Rota un refresh token por un par nuevo.
	 *
	 * @param \WP_REST_Request $request Solicitud entrante.
	 */
	private function grant_refresh_token( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		$client_id     = (string) $request->get_param( 'client_id' );

		if ( '' === $refresh_token || '' === $client_id ) {
			return $this->error( 'invalid_request', 'Faltan parámetros obligatorios.', 400 );
		}

		$record = $this->repository->find_active_token( $refresh_token, TokenRepository::TYPE_REFRESH );

		if ( null === $record ) {
			return $this->replay_refresh_token( $refresh_token, $client_id );
		}

		if ( ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			return $this->error( 'invalid_grant', 'El token de refresco no es válido.', 400 );
		}

		// Rotación: el refresh usado queda inutilizable, con la fecha anotada
		// para poder atender un reintento en los segundos siguientes.
		$this->repository->rotate_token( $refresh_token );

		$granted   = ScopeMap::parse( (string) $record['scope'] );
		$requested = (string) $request->get_param( 'scope' );

		if ( '' !== $requested ) {
			// Un refresh nunca amplía el alcance: sólo puede reducirlo.
			$narrowed = array_values( array_intersect( ScopeMap::parse( $requested ), $granted ) );
			$granted  = array() === $narrowed ? $granted : $narrowed;
		}

		return $this->issue_tokens(
			$client_id,
			(int) $record['user_id'],
			$granted,
			(string) $record['resource'],
			(string) $record['code_hash']
		);
	}

	/**
	 * Atiende un refresh token que ya fue canjeado.
	 *
	 * Un reintento honesto —el reenvío de una petición que expiró, o dos
	 * llamadas que salen a la vez— llega a los pocos segundos y desde el mismo
	 * cliente. Sin esta ventana ese segundo intento derribaba la sesión entera
	 * y obligaba a autorizar de nuevo, que es justo lo que hay que evitar.
	 *
	 * Pasado el plazo, la reaparición del token ya no se explica por la red:
	 * delata una copia, y entonces cae todo el linaje. OAuth 2.1 §4.14.2.
	 *
	 * @param string $refresh_token Valor recibido del cliente.
	 * @param string $client_id     Cliente que lo presenta.
	 */
	private function replay_refresh_token( string $refresh_token, string $client_id ): \WP_REST_Response {
		$rotated = $this->repository->find_rotated_token( $refresh_token, TokenRepository::TYPE_REFRESH );

		if ( null === $rotated || ! hash_equals( (string) $rotated['client_id'], $client_id ) ) {
			return $this->error( 'invalid_grant', 'El token de refresco no es válido.', 400 );
		}

		$stamp = trim( (string) $rotated['last_used_at'] );

		// Sin fecha no hubo rotación: el token lo revocó su dueño desde el panel
		// o una desconexión. No es un robo, así que no arrastra a nadie más.
		//
		// Ojo: la fecha se comprueba antes de interpretarla porque `strtotime()`
		// resuelve una cadena vacía con zona horaria como el instante actual, no
		// como un fallo. Fiarse de su valor de retorno colaba cada revocación
		// manual por la puerta del reintento.
		$rotated_at = '' === $stamp ? false : strtotime( $stamp . ' UTC' );

		if ( false === $rotated_at ) {
			return $this->error( 'invalid_grant', 'El token de refresco no es válido.', 400 );
		}

		if ( ( time() - $rotated_at ) > self::REFRESH_GRACE ) {
			$this->repository->revoke_lineage( (string) $rotated['code_hash'] );

			$this->audit_logger->log(
				'oauth',
				'read',
				'denied',
				array(
					'reason'    => 'refresh_token_reuse',
					'client_id' => $client_id,
				)
			);

			return $this->error( 'invalid_grant', 'El token de refresco no es válido.', 400 );
		}

		$this->audit_logger->log(
			'oauth',
			'read',
			'success',
			array(
				'reason'    => 'refresh_token_retry',
				'client_id' => $client_id,
			)
		);

		return $this->issue_tokens(
			$client_id,
			(int) $rotated['user_id'],
			ScopeMap::parse( (string) $rotated['scope'] ),
			(string) $rotated['resource'],
			(string) $rotated['code_hash']
		);
	}

	/**
	 * Emite y persiste un par de tokens.
	 *
	 * @param string             $client_id Cliente titular.
	 * @param int                $user_id   Usuario representado.
	 * @param array<int, string> $scopes    Scopes concedidos.
	 * @param string             $audience  Recurso destinatario.
	 * @param string             $code_hash Código de origen.
	 */
	private function issue_tokens(
		string $client_id,
		int $user_id,
		array $scopes,
		string $audience,
		string $code_hash
	): \WP_REST_Response {
		// El titular pudo perder capacidades entre el consentimiento y el canje.
		$scopes = ScopeMap::filter_by_user( $scopes, $user_id );

		if ( ! in_array( ScopeMap::CONNECT, $scopes, true ) ) {
			return $this->error( 'invalid_grant', 'El usuario ya no tiene acceso MCP.', 400 );
		}

		$access  = TokenRepository::generate_secret( 32 );
		$refresh = TokenRepository::generate_secret( 32 );

		$stored = $this->repository->store_token(
			$access,
			TokenRepository::TYPE_ACCESS,
			$client_id,
			$user_id,
			$scopes,
			$audience,
			$code_hash,
			TokenRepository::ACCESS_TTL
		);

		$stored = $stored && $this->repository->store_token(
			$refresh,
			TokenRepository::TYPE_REFRESH,
			$client_id,
			$user_id,
			$scopes,
			$audience,
			$code_hash,
			TokenRepository::REFRESH_TTL
		);

		if ( ! $stored ) {
			return $this->error( 'server_error', 'No fue posible emitir el token.', 500 );
		}

		$this->audit_logger->log(
			'oauth',
			'write',
			'success',
			array(
				'client_id' => $client_id,
				'scope'     => ScopeMap::to_string( $scopes ),
			)
		);

		return $this->respond(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => TokenRepository::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => ScopeMap::to_string( $scopes ),
			)
		);
	}

	/**
	 * Atiende el registro dinámico de clientes.
	 *
	 * @param \WP_REST_Request $request Solicitud entrante.
	 */
	public function handle_register( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->https_policy->allows_current_request() ) {
			return $this->error( 'invalid_request', 'Se requiere HTTPS.', 400 );
		}

		/**
		 * Permite ajustar el techo de registros dinámicos por minuto e IP.
		 *
		 * Los clientes alojados —claude.ai entre ellos— no registran desde el
		 * equipo de cada usuario sino desde su propia infraestructura: varias
		 * personas del mismo sitio llegan con la misma IP y comparten este
		 * contador sin saberlo. Un techo pensado para una sola persona las
		 * bloquea entre sí.
		 *
		 * @param int $limit Registros permitidos por minuto.
		 */
		$limit = (int) apply_filters( 'nexo_mcp_register_rate_limit', 20 );

		$throttle = $this->throttle( 'oauth_register', $limit );

		if ( null !== $throttle ) {
			return $throttle;
		}

		$parameters = $request->get_json_params();

		if ( ! is_array( $parameters ) ) {
			$parameters = $request->get_params();
		}

		$client = $this->clients->register_dynamic( $parameters );

		if ( is_wp_error( $client ) ) {
			return $this->error(
				$client->get_error_code(),
				$client->get_error_message(),
				400
			);
		}

		return $this->respond( $client, 201 );
	}

	/**
	 * Atiende la revocación de un token.
	 *
	 * @param \WP_REST_Request $request Solicitud entrante.
	 */
	public function handle_revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$throttle = $this->throttle( 'oauth_revoke', 30 );

		if ( null !== $throttle ) {
			return $throttle;
		}

		$token = (string) $request->get_param( 'token' );

		if ( '' !== $token ) {
			$this->repository->revoke_token( $token );
		}

		// RFC 7009 exige responder 200 aunque el token no exista, para no
		// revelar si un valor concreto era válido.
		return $this->respond( array() );
	}

	/**
	 * Distingue el reenvío de una petición perdida de una reutilización hostil.
	 *
	 * Quien captura un código no conoce el verificador PKCE ni suele llegar en la
	 * misma ventana de segundos que el cliente legítimo. Exigir las tres señales
	 * juntas evita cortar sesiones sanas sin abrir la puerta a un código robado.
	 *
	 * @param array<string, mixed> $consumed      Código ya consumido.
	 * @param string               $client_id     Cliente que reintenta.
	 * @param string               $code_verifier Verificador presentado.
	 */
	private function looks_like_retry( array $consumed, string $client_id, string $code_verifier ): bool {
		if ( ! hash_equals( (string) $consumed['client_id'], $client_id ) ) {
			return false;
		}

		if ( ! Pkce::verify( $code_verifier, (string) $consumed['code_challenge'] ) ) {
			return false;
		}

		$consumed_at = strtotime( (string) $consumed['consumed_at'] . ' UTC' );

		return false !== $consumed_at && ( time() - $consumed_at ) <= self::RETRY_WINDOW;
	}

	/**
	 * Aplica límite de solicitudes por IP.
	 *
	 * @param string $bucket Identificador del contador.
	 * @param int    $limit  Máximo por minuto.
	 */
	private function throttle( string $bucket, int $limit ): ?\WP_REST_Response {
		$identity = 'anon|' . ( $this->ip_resolver->resolve() ?? 'unknown' );
		$result   = $this->rate_limiter->consume( $identity, $bucket, $limit, MINUTE_IN_SECONDS );

		if ( is_wp_error( $result ) ) {
			return $this->error( 'server_error', 'No fue posible validar el límite de solicitudes.', 500 );
		}

		if ( ! $result->allowed ) {
			$response = $this->error( 'invalid_request', 'Demasiadas solicitudes.', 429 );

			// Sin este encabezado el cliente no sabe cuánto esperar: reintenta de
			// inmediato, vuelve a chocar y se mantiene fuera él solo. RFC 6585 §4.
			$response->header( 'Retry-After', (string) $result->retry_after );

			return $response;
		}

		return null;
	}

	/**
	 * Construye una respuesta correcta sin caché.
	 *
	 * @param array<string, mixed> $payload Cuerpo de la respuesta.
	 * @param int                  $status  Código HTTP.
	 */
	private function respond( array $payload, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $payload, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Construye una respuesta de error conforme a OAuth.
	 *
	 * @param string $code        Código de error.
	 * @param string $description Descripción segura.
	 * @param int    $status      Código HTTP.
	 */
	private function error( string $code, string $description, int $status ): \WP_REST_Response {
		return $this->respond(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}
}
