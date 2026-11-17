<?php
/**
 * Documentos de descubrimiento OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

use NexoDev\NexoMCP\Security\LocalEnvironment;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Publica los metadatos que permiten a un cliente MCP descubrir cómo autorizarse.
 *
 * Expone dos documentos exigidos por la especificación: el del recurso protegido
 * (RFC 9728), que apunta al servidor de autorización, y el del propio servidor de
 * autorización (RFC 8414), que describe endpoints y capacidades.
 *
 * La intercepción ocurre en `parse_request` para no depender de reglas de
 * reescritura ni de un vaciado de permalinks tras la activación.
 */
final class DiscoveryController {

	/**
	 * Ruta del documento de recurso protegido.
	 */
	public const PROTECTED_RESOURCE_PATH = '.well-known/oauth-protected-resource';

	/**
	 * Ruta del documento del servidor de autorización.
	 */
	public const AUTHORIZATION_SERVER_PATH = '.well-known/oauth-authorization-server';

	/**
	 * Ruta del documento OpenID Connect Discovery.
	 *
	 * Con WordPress en una subcarpeta (midominio.cl/nuevo) el SDK oficial de MCP
	 * sólo prueba, bajo el path del issuer, esta ruta: las de OAuth caen en la
	 * raíz del dominio, fuera de WordPress. Servirla evita el 404 que hacía que
	 * el cliente terminara registrándose en `/nuevo/register`.
	 */
	public const OPENID_CONFIGURATION_PATH = '.well-known/openid-configuration';

	/**
	 * Ruta relativa del endpoint MCP protegido.
	 */
	public const RESOURCE_PATH = 'wp-json/nexo-mcp/v1/mcp';

	/**
	 * Engancha la intercepción temprana de solicitudes.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Entrega el identificador canónico del recurso protegido.
	 *
	 * Se usa como audiencia de los tokens y como valor del parámetro `resource`
	 * definido en RFC 8707. Se emite sin barra final por interoperabilidad.
	 */
	public static function resource_identifier(): string {
		return untrailingslashit( home_url( '/' . self::RESOURCE_PATH ) );
	}

	/**
	 * Entrega el issuer del servidor de autorización.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Entrega las direcciones con las que este conector se reconoce.
	 *
	 * El identificador canónico sale de la dirección configurada del sitio, que
	 * WordPress lee de su base de datos y no de la petición. Cuando el sitio se
	 * publica a través de un túnel o un proxy, el cliente conoce el conector por
	 * la dirección de ese intermediario y la envía como `resource`: para él es
	 * la dirección verdadera, y no tiene forma de adivinar la interna.
	 *
	 * Sólo se admite la que el propietario haya declarado en el panel. Aceptar
	 * la que trae la petición sería dejar que quien llama elija la audiencia de
	 * su propio token, que es justo lo que esta comprobación existe para impedir.
	 *
	 * @return array<int, string>
	 */
	public static function accepted_resources(): array {
		$accepted = array( self::resource_identifier() );
		$declared = untrailingslashit( (string) ( new Settings() )->get( 'proxy_resource' ) );

		if ( '' !== $declared && ! in_array( $declared, $accepted, true ) ) {
			$accepted[] = $declared;
		}

		return $accepted;
	}

	/**
	 * Indica si una dirección identifica a este conector.
	 *
	 * La barra final se ignora a ambos lados: RFC 8707 pide un identificador
	 * absoluto, no una forma exacta, y los clientes difieren en ese detalle.
	 *
	 * @param string $candidate Valor recibido en `resource`.
	 */
	public static function accepts_resource( string $candidate ): bool {
		$normalized = untrailingslashit( trim( $candidate ) );

		foreach ( self::accepted_resources() as $accepted ) {
			if ( hash_equals( $accepted, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Valida la dirección pública declarada para un túnel o proxy.
	 *
	 * Devuelve la dirección normalizada, o cadena vacía si no sirve. Se exige
	 * HTTPS porque el conector sólo opera sobre HTTPS, y se rechazan las
	 * credenciales embebidas y todo lo que no sea ruta: un identificador de
	 * recurso es una dirección, no una consulta.
	 *
	 * @param string $raw Valor escrito por el propietario.
	 */
	public static function sanitize_resource_alias( string $raw ): string {
		$value = untrailingslashit( trim( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$scheme  = strtolower( (string) $parts['scheme'] );
		$permits = array( 'https' );

		if ( LocalEnvironment::bypass_allowed() ) {
			$permits[] = 'http';
		}

		if ( ! in_array( $scheme, $permits, true ) ) {
			return '';
		}

		return untrailingslashit( esc_url_raw( $value, $permits ) );
	}

	/**
	 * Entrega la URL del documento de recurso protegido.
	 */
	public static function protected_resource_url(): string {
		return home_url( '/' . self::PROTECTED_RESOURCE_PATH . '/' . self::RESOURCE_PATH );
	}

	/**
	 * Entrega la URL del documento del servidor de autorización.
	 */
	public static function authorization_server_url(): string {
		return home_url( '/' . self::AUTHORIZATION_SERVER_PATH );
	}

	/**
	 * Entrega la URL del documento OpenID Connect Discovery.
	 */
	public static function openid_configuration_url(): string {
		return home_url( '/' . self::OPENID_CONFIGURATION_PATH );
	}

	/**
	 * Entrega la URL del conjunto de claves (vacío: el conector no emite id_tokens).
	 */
	public static function jwks_endpoint(): string {
		return home_url( '/wp-json/nexo-mcp/v1/oauth/jwks' );
	}

	/**
	 * Entrega la URL del endpoint de autorización.
	 */
	public static function authorization_endpoint(): string {
		return home_url( '/' . AuthorizationController::PATH );
	}

	/**
	 * Entrega la URL del endpoint de tokens.
	 */
	public static function token_endpoint(): string {
		return home_url( '/wp-json/nexo-mcp/v1/oauth/token' );
	}

	/**
	 * Entrega la URL del endpoint de registro dinámico.
	 */
	public static function registration_endpoint(): string {
		return home_url( '/wp-json/nexo-mcp/v1/oauth/register' );
	}

	/**
	 * Entrega la URL del endpoint de revocación.
	 */
	public static function revocation_endpoint(): string {
		return home_url( '/wp-json/nexo-mcp/v1/oauth/revoke' );
	}

	/**
	 * Atiende la solicitud cuando corresponde a un documento de descubrimiento.
	 */
	public function maybe_serve(): void {
		$path = $this->current_path();

		if ( '' === $path ) {
			return;
		}

		if ( self::AUTHORIZATION_SERVER_PATH === $path ) {
			$this->send( $this->authorization_server_metadata() );
		}

		if ( self::OPENID_CONFIGURATION_PATH === $path ) {
			$this->send( $this->openid_configuration_metadata() );
		}

		// RFC 9728 admite insertar la ruta del recurso tras el nombre del documento.
		if ( self::PROTECTED_RESOURCE_PATH === $path
			|| 0 === strpos( $path, self::PROTECTED_RESOURCE_PATH . '/' )
		) {
			$this->send( $this->protected_resource_metadata() );
		}
	}

	/**
	 * Construye el documento de recurso protegido.
	 *
	 * @return array<string, mixed>
	 */
	private function protected_resource_metadata(): array {
		return array(
			'resource'                 => self::resource_identifier(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => ScopeMap::advertised(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation'   => admin_url( 'admin.php?page=' . \NexoDev\NexoMCP\Admin\AdminPage::PAGE_SLUG . '&tab=endpoint' ),
		);
	}

	/**
	 * Construye el documento del servidor de autorización.
	 *
	 * @return array<string, mixed>
	 */
	private function authorization_server_metadata(): array {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorization_endpoint(),
			'token_endpoint'                        => self::token_endpoint(),
			'registration_endpoint'                 => self::registration_endpoint(),
			'revocation_endpoint'                   => self::revocation_endpoint(),
			'scopes_supported'                      => ScopeMap::advertised(),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),

			/*
			 * Codex CLI 0.146 descarta `iss` al reenviar el callback OAuth y
			 * luego falla si el servidor anuncia RFC 9207. La respuesta de
			 * autorización sigue incluyendo `iss`; sólo se desactiva el anuncio
			 * hasta que el cliente preserve ese parámetro correctamente.
			 */
			'authorization_response_iss_parameter_supported' => false,
			'client_id_metadata_document_supported' => true,
		);
	}

	/**
	 * Construye el documento OpenID Connect Discovery.
	 *
	 * Es el mismo documento del servidor de autorización, con el mismo issuer,
	 * más los tres campos que el esquema OIDC del SDK exige como obligatorios y
	 * sin los cuales rechaza el documento. El conector no emite id_tokens ni
	 * tiene claves: `jwks_uri` apunta a un conjunto vacío sólo para cumplir el
	 * esquema.
	 *
	 * @return array<string, mixed>
	 */
	private function openid_configuration_metadata(): array {
		return array_merge(
			$this->authorization_server_metadata(),
			array(
				'subject_types_supported'               => array( 'public' ),
				'id_token_signing_alg_values_supported' => array( 'RS256' ),
				'jwks_uri'                              => self::jwks_endpoint(),
			)
		);
	}

	/**
	 * Normaliza la ruta solicitada sin query string ni barras sobrantes.
	 *
	 * El valor sólo se compara contra rutas conocidas, nunca se emite ni se usa
	 * para construir consultas, por lo que se conserva sin sanitizar el texto
	 * para no alterar su codificación.
	 */
	private function current_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sólo se compara contra rutas fijas; sanitize_text_field() destruiría el percent-encoding.
		$uri = wp_unslash( (string) $_SERVER['REQUEST_URI'] );

		if ( '' === $uri ) {
			return '';
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( rawurldecode( $path ), '/' );
	}

	/**
	 * Emite un documento JSON y termina la solicitud.
	 *
	 * @param array<string, mixed> $document Metadatos publicados.
	 */
	private function send( array $document ): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );

			/*
			 * Estos metadatos dependen de la configuración del sitio: los permisos
			 * anunciados salen de los módulos habilitados. Con una hora de caché,
			 * encender un módulo no tenía efecto visible —los plugins de caché y
			 * los proxies seguían entregando la lista anterior y el cliente pedía
			 * permisos obsoletos—, así que se pide expresamente no almacenarlos.
			 */
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version' );
			header( 'Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, Mcp-Protocol-Version' );
		}

		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}
}
