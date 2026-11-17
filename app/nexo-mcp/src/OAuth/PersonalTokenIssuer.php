<?php
/**
 * Emisión de tokens de acceso personales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

/**
 * Emite tokens de acceso sin pasar por el flujo de autorización.
 *
 * Existen porque no todos los clientes MCP implementan OAuth igual de bien.
 * Codex, por ejemplo, completa la autorización y después no usa el token que
 * recibió: el sitio queda conectado y sin herramientas, y la persona no tiene
 * forma de saber por qué.
 *
 * Un token que se pega y funciona no depende de que cada cliente haga bien su
 * parte. Se emite desde el panel, se revoca desde el mismo sitio que las demás
 * conexiones, y para el resto del plugin es un token de acceso corriente: lo
 * valida el mismo guardia, con los mismos scopes y la misma audiencia.
 */
final class PersonalTokenIssuer {

	/**
	 * Vida del token, en segundos.
	 *
	 * Un año. Lo bastante largo para que nadie tenga que renovarlo mientras
	 * trabaja, y lo bastante corto para que un token olvidado en un equipo que
	 * ya no se usa acabe caducando solo.
	 */
	public const TTL = YEAR_IN_SECONDS;

	/**
	 * Prefijo del valor entregado.
	 *
	 * Sirve para reconocerlo de un vistazo en una configuración, y para que un
	 * escáner de secretos lo detecte si acaba en un repositorio por descuido.
	 */
	private const PREFIX = 'nexomcp_';

	/**
	 * Almacenamiento de clientes y tokens.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Construye el emisor.
	 *
	 * @param TokenRepository $repository Almacenamiento compartido con OAuth.
	 */
	public function __construct( TokenRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Emite un token para el usuario indicado.
	 *
	 * @param int    $user_id Usuario propietario.
	 * @param string $label   Nombre visible de la conexión.
	 * @return string|null El token en claro, o null si no se pudo emitir.
	 */
	public function issue( int $user_id, string $label ): ?string {
		// Se parte de lo que el sitio anuncia, no de todo lo que el servidor
		// reconoce: un módulo apagado en el panel no debe colarse en el token
		// por la puerta de atrás. Y encima se recorta a lo que ese usuario
		// puede hacer, igual que en el consentimiento del flujo normal.
		$scopes = ScopeMap::filter_by_user( ScopeMap::advertised(), $user_id );

		if ( ! in_array( ScopeMap::CONNECT, $scopes, true ) ) {
			return null;
		}

		$client_id = 'nexo-personal-' . wp_generate_password( 12, false, false );
		$nombre    = '' !== trim( $label ) ? sanitize_text_field( $label ) : __( 'Token personal', 'nexo-mcp' );

		// Se registra como cliente para que la conexión aparezca con nombre en
		// el panel y se pueda revocar junto a las demás, en vez de quedar como
		// una fila suelta que nadie sabe de dónde salió.
		$this->repository->store_client( $client_id, $nombre, array( home_url( '/' ) ) );

		$token = self::PREFIX . self::random_value();

		$guardado = $this->repository->store_token(
			$token,
			TokenRepository::TYPE_ACCESS,
			$client_id,
			$user_id,
			$scopes,
			DiscoveryController::resource_identifier(),
			'',
			self::TTL
		);

		return $guardado ? $token : null;
	}

	/**
	 * Indica si un valor tiene forma de token personal.
	 *
	 * @param string $token Valor a comprobar.
	 */
	public static function looks_like_one( string $token ): bool {
		return 0 === strpos( $token, self::PREFIX );
	}

	/**
	 * Genera la parte aleatoria del token.
	 *
	 * Son 32 bytes de fuente criptográfica en base64url: sin relleno ni
	 * caracteres que haya que escapar al pegarlo en una terminal o un archivo
	 * de configuración.
	 */
	private static function random_value(): string {
		$bytes = function_exists( 'random_bytes' )
			? random_bytes( 32 )
			: wp_generate_password( 32, true, true );

		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación de transporte, no ofuscación.
	}
}
