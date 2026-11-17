<?php
/**
 * Resolución y registro de clientes OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

/**
 * Obtiene la identidad de un cliente por cualquiera de los mecanismos admitidos.
 *
 * La especificación prioriza Client ID Metadata Documents: el `client_id` es una
 * URL HTTPS desde la que se descarga el documento de metadatos. El registro
 * dinámico (RFC 7591) queda como respaldo deprecado para clientes antiguos.
 */
final class ClientRegistry {

	/**
	 * Tamaño máximo aceptado para un documento CIMD.
	 */
	private const MAX_DOCUMENT_BYTES = 32768;

	/**
	 * Longitud máxima de un identificador de cliente.
	 */
	private const MAX_CLIENT_ID_LENGTH = 191;

	/**
	 * Vigencia del caché de documentos CIMD, en segundos.
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Techo de clientes OAuth almacenados por sitio.
	 */
	private const MAX_CLIENTS = 1000;

	/**
	 * Margen que se respeta al liberar espacio bajo presión, en segundos.
	 */
	private const CAPACITY_GRACE = 3600;

	/**
	 * Almacenamiento persistente.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Construye el registro.
	 *
	 * @param TokenRepository $repository Almacenamiento persistente.
	 */
	public function __construct( TokenRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Resuelve un cliente por su identificador.
	 *
	 * @param string $client_id Identificador recibido.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function resolve( string $client_id ) {
		if ( '' === $client_id || strlen( $client_id ) > self::MAX_CLIENT_ID_LENGTH ) {
			return new \WP_Error( 'invalid_client', __( 'Identificador de cliente inválido.', 'nexo-mcp' ) );
		}

		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return $this->resolve_metadata_document( $client_id );
		}

		$stored = $this->repository->find_client( $client_id );

		if ( null === $stored ) {
			return new \WP_Error( 'invalid_client', __( 'Cliente no registrado.', 'nexo-mcp' ) );
		}

		return $stored;
	}

	/**
	 * Descarga y valida un Client ID Metadata Document.
	 *
	 * @param string $client_id URL HTTPS que identifica al cliente.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function resolve_metadata_document( string $client_id ) {
		$cache_key = 'nexo_mcp_cimd_' . md5( $client_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$parsed = wp_parse_url( $client_id );

		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) || isset( $parsed['fragment'] ) ) {
			return new \WP_Error( 'invalid_client', __( 'La URL del cliente no es válida.', 'nexo-mcp' ) );
		}

		// wp_safe_remote_get bloquea destinos internos y evita SSRF hacia la red local.
		$response = wp_safe_remote_get(
			$client_id,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'invalid_client', __( 'No fue posible obtener los metadatos del cliente.', 'nexo-mcp' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'invalid_client', __( 'El documento de metadatos del cliente no está disponible.', 'nexo-mcp' ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_DOCUMENT_BYTES ) {
			return new \WP_Error( 'invalid_client', __( 'El documento de metadatos del cliente es demasiado grande.', 'nexo-mcp' ) );
		}

		$document = json_decode( $body, true );

		if ( ! is_array( $document ) ) {
			return new \WP_Error( 'invalid_client', __( 'El documento de metadatos del cliente no es JSON válido.', 'nexo-mcp' ) );
		}

		// El documento debe reclamar exactamente la misma URL que lo identifica.
		if ( ! isset( $document['client_id'] ) || $client_id !== (string) $document['client_id'] ) {
			return new \WP_Error( 'invalid_client', __( 'El documento de metadatos no coincide con el identificador solicitado.', 'nexo-mcp' ) );
		}

		$redirect_uris = $this->sanitize_redirect_uris( $document['redirect_uris'] ?? array() );

		if ( array() === $redirect_uris ) {
			return new \WP_Error( 'invalid_client', __( 'El cliente no declara URIs de retorno válidas.', 'nexo-mcp' ) );
		}

		$client = array(
			'client_id'     => $client_id,
			'client_name'   => isset( $document['client_name'] )
				? sanitize_text_field( (string) $document['client_name'] )
				: $client_id,
			'redirect_uris' => $redirect_uris,
			'metadata_url'  => $client_id,
		);

		set_transient( $cache_key, $client, self::CACHE_TTL );
		$this->repository->store_client( $client_id, $client['client_name'], $redirect_uris, $client_id );

		return $client;
	}

	/**
	 * Registra un cliente mediante registro dinámico.
	 *
	 * @param array<string, mixed> $request Metadatos enviados por el cliente.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function register_dynamic( array $request ) {
		$redirect_uris = $this->sanitize_redirect_uris( $request['redirect_uris'] ?? array() );

		if ( array() === $redirect_uris ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				__( 'Debes declarar al menos una URI de retorno válida.', 'nexo-mcp' )
			);
		}

		$sin_espacio = $this->ensure_capacity();

		if ( null !== $sin_espacio ) {
			return $sin_espacio;
		}

		$client_id   = 'nexo-' . TokenRepository::generate_secret( 16 );
		$client_name = isset( $request['client_name'] )
			? sanitize_text_field( (string) $request['client_name'] )
			: __( 'Cliente MCP', 'nexo-mcp' );

		if ( ! $this->repository->store_client( $client_id, $client_name, $redirect_uris ) ) {
			return new \WP_Error( 'server_error', __( 'No fue posible registrar el cliente.', 'nexo-mcp' ) );
		}

		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Comprueba que quede sitio para un cliente más.
	 *
	 * El registro dinámico es público y sin autenticar: lo único que lo frena
	 * es un limitador por IP, y varias IP bastan para llenar la tabla. Un techo
	 * absoluto es la última barrera. Antes de rechazar nada se intenta liberar
	 * espacio podando huérfanos con un margen corto: una hora sobra para que un
	 * alta legítima llegue a canjear su primer código.
	 *
	 * @return \WP_Error|null Error cuando no queda sitio, null cuando sí.
	 */
	private function ensure_capacity(): ?\WP_Error {
		/**
		 * Permite ajustar el techo de clientes OAuth por sitio.
		 *
		 * Un valor muy alto lo desactiva en la práctica.
		 *
		 * @param int $limit Clientes admitidos.
		 */
		$limit = max( 1, (int) apply_filters( 'nexo_mcp_max_oauth_clients', self::MAX_CLIENTS ) );

		if ( $this->repository->count_clients() < $limit ) {
			return null;
		}

		$this->repository->purge_orphan_clients( self::CAPACITY_GRACE );

		if ( $this->repository->count_clients() < $limit ) {
			return null;
		}

		return new \WP_Error(
			'server_error',
			__( 'El sitio alcanzó el máximo de clientes OAuth registrados.', 'nexo-mcp' )
		);
	}

	/**
	 * Valida que una URI de retorno esté registrada.
	 *
	 * La comparación es literal salvo por el puerto de las direcciones de bucle
	 * local. RFC 8252, sección 7.3, obliga al servidor de autorización a admitir
	 * cualquier puerto en ese caso, porque una aplicación de escritorio pide al
	 * sistema operativo un puerto efímero distinto en cada arranque y no puede
	 * declararlo por adelantado. Esquema, host y ruta se siguen exigiendo
	 * idénticos, de modo que anexar rutas o cambiar de destino sigue vedado.
	 *
	 * @param array<string, mixed> $client       Cliente resuelto.
	 * @param string               $redirect_uri URI recibida.
	 */
	public function is_registered_redirect_uri( array $client, string $redirect_uri ): bool {
		if ( '' === $redirect_uri ) {
			return false;
		}

		$registered = isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] )
			? $client['redirect_uris']
			: array();

		foreach ( $registered as $candidate ) {
			if ( hash_equals( (string) $candidate, $redirect_uri ) ) {
				return true;
			}

			if ( $this->loopback_matches( (string) $candidate, $redirect_uri ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compara dos URIs de bucle local ignorando únicamente el puerto.
	 *
	 * @param string $registered URI declarada por el cliente.
	 * @param string $requested  URI recibida en la solicitud.
	 */
	private function loopback_matches( string $registered, string $requested ): bool {
		$declared = wp_parse_url( $registered );
		$incoming = wp_parse_url( $requested );

		if ( ! is_array( $declared ) || ! is_array( $incoming ) ) {
			return false;
		}

		// La excepción sólo aplica cuando ambos extremos son de bucle local.
		if ( ! $this->is_loopback_host( (string) ( $declared['host'] ?? '' ) )
			|| ! $this->is_loopback_host( (string) ( $incoming['host'] ?? '' ) )
		) {
			return false;
		}

		// Un fragmento o una consulta añadida delatan un intento de desvío.
		if ( isset( $incoming['fragment'] ) ) {
			return false;
		}

		$same_scheme = strtolower( (string) ( $declared['scheme'] ?? '' ) )
			=== strtolower( (string) ( $incoming['scheme'] ?? '' ) );
		$same_host   = strtolower( (string) $declared['host'] ) === strtolower( (string) $incoming['host'] );
		$same_path   = ( $declared['path'] ?? '/' ) === ( $incoming['path'] ?? '/' );
		$same_query  = ( $declared['query'] ?? '' ) === ( $incoming['query'] ?? '' );

		return $same_scheme && $same_host && $same_path && $same_query;
	}

	/**
	 * Indica si un host corresponde a la propia máquina del usuario.
	 *
	 * @param string $host Host evaluado.
	 */
	private function is_loopback_host( string $host ): bool {
		return in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
	}

	/**
	 * Normaliza y filtra una lista de URIs de retorno.
	 *
	 * Se aceptan destinos HTTPS y bucles locales, que es el caso habitual de un
	 * cliente de escritorio o de línea de comandos. Se rechazan esquemas
	 * arbitrarios y cualquier URI con fragmento.
	 *
	 * @param mixed $value Lista recibida.
	 *
	 * @return array<int, string>
	 */
	private function sanitize_redirect_uris( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$valid = array();

		foreach ( $value as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate ) {
				continue;
			}

			$parsed = wp_parse_url( $candidate );

			if ( ! is_array( $parsed ) || isset( $parsed['fragment'] ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
				continue;
			}

			$scheme = strtolower( (string) $parsed['scheme'] );
			$host   = strtolower( (string) $parsed['host'] );

			$is_loopback = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

			if ( 'https' === $scheme || ( 'http' === $scheme && $is_loopback ) ) {
				$valid[] = $candidate;
			}
		}

		return array_values( array_unique( $valid ) );
	}
}
