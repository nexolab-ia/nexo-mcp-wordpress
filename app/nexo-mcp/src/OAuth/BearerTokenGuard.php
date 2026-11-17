<?php
/**
 * Validación de tokens Bearer en el transporte MCP.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

/**
 * Autentica solicitudes MCP portadoras de un access token.
 *
 * Actúa como resource server: además de comprobar que el token exista y siga
 * vigente, verifica que haya sido emitido para este servidor. Un token válido
 * para otro recurso se rechaza aunque provenga del mismo emisor.
 */
final class BearerTokenGuard {

	/**
	 * Rutas REST en las que el token del conector es una credencial válida.
	 *
	 * El transporte MCP y la subida de medios. Sin distinguir mayúsculas,
	 * igual que el enrutador de WordPress.
	 */
	private const CONNECTOR_ROUTES = '#^/nexo-mcp/v1/(?:mcp|media)/?$#i';

	/**
	 * Scopes del token en curso.
	 *
	 * El contexto de autenticación (este campo, `$authenticated` y `$failure`)
	 * sólo lo fija `authenticate()` al validar un token real. No hay API pública
	 * para escribirlo: cualquier código cargado en WordPress podría llamarla y
	 * declararse conectado con todos los scopes.
	 *
	 * @var array<int, string>
	 */
	private static array $scopes = array();

	/**
	 * Indica si la solicitud actual se autenticó con Bearer.
	 *
	 * @var bool
	 */
	private static bool $authenticated = false;

	/**
	 * Motivo del rechazo de la solicitud en curso.
	 *
	 * Distinguir «no llegó ningún token» de «el token ya no sirve» es lo único
	 * que permite al cliente refrescar en silencio en lugar de abrir otra vez
	 * la ventana de autorización. RFC 6750 §3.1.
	 *
	 * @var string
	 */
	private static string $failure = '';

	/**
	 * Almacenamiento persistente.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Construye el guardia.
	 *
	 * @param TokenRepository $repository Almacenamiento persistente.
	 */
	public function __construct( TokenRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Engancha la autenticación y el desafío de las respuestas.
	 */
	public function register(): void {
		/*
		 * Prioridad 30, después de que WordPress resuelva la sesión.
		 *
		 * Adelantarlo parece buena idea —así el token se comprueba antes que
		 * las contraseñas de aplicación— pero rompe la autenticación: en ese
		 * momento WordPress todavía no puede construir el objeto del usuario,
		 * de modo que devuelve cero y ya no vuelve a preguntar. El token se
		 * valida bien y aun así la petición acaba sin permisos.
		 */
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 30 );
		add_filter( 'rest_pre_dispatch', array( $this, 'confine_token' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_challenge_header' ), 10, 3 );
		add_filter( 'rest_exposed_cors_headers', array( $this, 'expose_mcp_headers' ), 10, 2 );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'allow_mcp_headers' ), 10, 2 );
	}

	/**
	 * Expone los encabezados que un cliente MCP ejecutado en navegador necesita
	 * para descubrir OAuth y conservar la sesión del transporte.
	 *
	 * @param array<int, string> $headers Encabezados ya expuestos por WordPress.
	 * @param mixed              $request Solicitud REST actual.
	 * @return array<int, string>
	 */
	public function expose_mcp_headers( array $headers, $request ): array {
		if ( ! $this->is_mcp_request( $request ) ) {
			return $headers;
		}

		return array_values(
			array_unique(
				array_merge( $headers, array( 'WWW-Authenticate', 'Mcp-Session-Id', 'Mcp-Protocol-Version' ) )
			)
		);
	}

	/**
	 * Permite los encabezados de negociación y sesión en preflight CORS.
	 *
	 * @param array<int, string> $headers Encabezados ya permitidos por WordPress.
	 * @param mixed              $request Solicitud REST actual.
	 * @return array<int, string>
	 */
	public function allow_mcp_headers( array $headers, $request ): array {
		if ( ! $this->is_mcp_request( $request ) ) {
			return $headers;
		}

		return array_values(
			array_unique(
				array_merge( $headers, array( 'Mcp-Protocol-Version', 'Mcp-Session-Id' ) )
			)
		);
	}

	/**
	 * Entrega los scopes concedidos al token en curso.
	 *
	 * @return array<int, string>
	 */
	public static function scopes(): array {
		return self::$scopes;
	}

	/**
	 * Indica si la solicitud en curso llegó con un token válido.
	 */
	public static function is_authenticated(): bool {
		return self::$authenticated;
	}

	/**
	 * Resuelve el usuario a partir del token Bearer.
	 *
	 * @param int|false $user_id Usuario determinado por filtros previos.
	 *
	 * @return int|false
	 */
	public function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = $this->extract_token();

		if ( '' === $token ) {
			return $user_id;
		}

		/*
		 * El token acredita al cliente frente al conector y nada más. Aceptarlo
		 * en cualquier petición lo convertía en una credencial de toda la API de
		 * WordPress —y de admin-ajax—, con todas las capacidades del usuario y
		 * sin pasar por el modo sólo lectura ni por los módulos del panel.
		 */
		if ( ! self::is_connector_route( self::requested_route() ) ) {
			return $user_id;
		}

		$record = $this->repository->find_active_token( $token, TokenRepository::TYPE_ACCESS );

		if ( null === $record ) {
			// Llegó un token y no sirve: caducado, revocado o desconocido. Los
			// tres casos son el mismo para el cliente, que debe refrescar.
			self::$failure = 'invalid_token';

			return $user_id;
		}

		/*
		 * Enlace de audiencia: el token debe haber sido emitido para este
		 * recurso. Se admite también la dirección de túnel o proxy declarada por
		 * el propietario, porque es con ella con la que se autorizó. Sin esto, un
		 * token emitido a través del túnel se aceptaba al nacer y se rechazaba
		 * después en cada llamada, sin más señal que un 401 sin explicación.
		 */
		if ( ! DiscoveryController::accepts_resource( (string) $record['resource'] ) ) {
			self::$failure = 'invalid_token';

			return $user_id;
		}

		self::$scopes        = ScopeMap::parse( (string) $record['scope'] );
		self::$authenticated = true;

		$this->repository->touch_token( (string) $record['token_hash'] );

		return (int) $record['user_id'];
	}

	/**
	 * Corta una ruta ajena al conector si la petición entró con token.
	 *
	 * La autenticación ya se limita a las rutas propias; esto repite el control
	 * con la ruta que WordPress terminó atendiendo, por si difiere de la pedida
	 * (lotes de /batch/v1, reescrituras del servidor).
	 *
	 * @param mixed            $result  Respuesta adelantada por otro filtro.
	 * @param \WP_REST_Server  $server  Servidor REST.
	 * @param \WP_REST_Request $request Solicitud a despachar.
	 *
	 * @return mixed
	 */
	public function confine_token( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! self::$authenticated || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( self::is_connector_route( (string) $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error(
			'nexo_mcp_token_out_of_scope',
			__( 'El token de Nexo MCP sólo sirve para el conector.', 'nexo-mcp' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Indica si una ruta REST pertenece al conector.
	 *
	 * @param string $route Ruta REST, con o sin barra inicial.
	 */
	private static function is_connector_route( string $route ): bool {
		return '' !== $route && 1 === preg_match( self::CONNECTOR_ROUTES, '/' . ltrim( $route, '/' ) );
	}

	/**
	 * Ruta REST que pide la petición en curso, resuelta como la resolverá WordPress.
	 *
	 * Se calcula antes de que WordPress enrute, así que imita su orden: el
	 * `rest_route` de la query gana sobre el del cuerpo y éste sobre la ruta del
	 * enlace permanente (WP::parse_request). La API REST sólo la atiende el
	 * controlador frontal, así que admin-ajax, wp-admin, wp-login o xmlrpc no
	 * califican aunque traigan `rest_route`.
	 */
	private static function requested_route(): string {
		$script = isset( $_SERVER['SCRIPT_FILENAME'] )
			? basename( str_replace( '\\', '/', sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_FILENAME'] ) ) ) )
			: '';

		if ( 'index.php' !== $script
			|| ( defined( 'WP_ADMIN' ) && WP_ADMIN )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
		) {
			return '';
		}

		// phpcs:disable WordPress.Security.NonceVerification -- Sólo se lee la ruta pedida; no hay acción que verificar.
		if ( isset( $_GET['rest_route'] ) ) {
			return is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : '';
		}

		if ( isset( $_POST['rest_route'] ) ) {
			return is_string( $_POST['rest_route'] ) ? sanitize_text_field( wp_unslash( $_POST['rest_route'] ) ) : '';
		}
		// phpcs:enable

		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$at     = strpos( $path, $prefix );

		return false === $at ? '' : substr( $path, $at + strlen( $prefix ) - 1 );
	}

	/**
	 * Añade el desafío de autorización a las respuestas rechazadas.
	 *
	 * @param \WP_REST_Response $response Respuesta generada.
	 * @param \WP_REST_Server   $server   Servidor REST.
	 * @param \WP_REST_Request  $request  Solicitud atendida.
	 */
	public function add_challenge_header( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		if ( ! $request instanceof \WP_REST_Request
			|| false === strpos( (string) $request->get_route(), '/nexo-mcp/v1/mcp' )
		) {
			return $response;
		}

		$status = $response->get_status();

		if ( 401 !== $status && 403 !== $status ) {
			return $response;
		}

		$metadata = DiscoveryController::protected_resource_url();
		$scopes   = ScopeMap::to_string( ScopeMap::advertised() );

		if ( 403 === $status ) {
			/*
			 * `insufficient_scope` le dice al cliente MCP que vuelva a pedir
			 * autorización, así que sólo se anuncia cuando el problema es de
			 * verdad de scope: un token sin `mcp:connect`. El resto de los 403
			 * —servidor apagado, registro pendiente, límites del panel— no se
			 * arreglan reconectando, y el desafío hacía que la IA lo intentara.
			 */
			if ( self::$authenticated && ! ScopeMap::satisfies( self::$scopes, array( ScopeMap::CONNECT ) ) ) {
				$response->header(
					'WWW-Authenticate',
					sprintf(
						'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"',
						$scopes,
						$metadata
					)
				);
			}

			return $response;
		}

		if ( 'invalid_token' === self::$failure ) {
			$response->header(
				'WWW-Authenticate',
				sprintf(
					'Bearer error="invalid_token", error_description="El token de acceso caducó o fue revocado.", resource_metadata="%s", scope="%s"',
					$metadata,
					$scopes
				)
			);

			return $response;
		}

		$response->header(
			'WWW-Authenticate',
			sprintf( 'Bearer resource_metadata="%s", scope="%s"', $metadata, $scopes )
		);

		return $response;
	}

	/**
	 * Extrae el token del encabezado de autorización.
	 *
	 * Se contemplan las variantes que algunos servidores exponen cuando el
	 * encabezado original no llega a PHP de forma directa.
	 */
	private function extract_token(): string {
		$candidates = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );
		$header     = '';

		foreach ( $candidates as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				break;
			}
		}

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();

			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
						$header = sanitize_text_field( (string) $value );
						break;
					}
				}
			}
		}

		if ( '' === $header || 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	/**
	 * Indica si la solicitud pertenece al endpoint MCP de Nexo.
	 *
	 * @param mixed $request Solicitud REST potencial.
	 */
	private function is_mcp_request( $request ): bool {
		return $request instanceof \WP_REST_Request
			&& false !== strpos( (string) $request->get_route(), '/nexo-mcp/v1/mcp' );
	}
}
