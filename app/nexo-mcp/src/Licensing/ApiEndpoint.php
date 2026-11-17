<?php
/**
 * Dirección del servicio de licencias, registro y actualizaciones.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Licensing;

/**
 * Resuelve a qué servidor habla el plugin.
 *
 * Nexo MCP no trae ningún servidor de fábrica. La dirección se define en
 * `wp-config.php` con la constante `NEXO_MCP_API_URL` (o con el filtro
 * `nexo_mcp_api_url`). Si no está configurada, el plugin funciona completo:
 * no exige licencia ni registro, no busca actualizaciones remotas y las
 * pestañas de Licencia, Actualizaciones y Soporte lo indican.
 */
final class ApiEndpoint {

	/**
	 * Nombre de la constante que configura el servicio.
	 */
	public const CONSTANT = 'NEXO_MCP_API_URL';

	/**
	 * Devuelve la base del servicio, sin barra final, o cadena vacía.
	 *
	 * Un valor sin HTTPS o mal formado se descarta: la clave de licencia y el
	 * token viajan por aquí, y una base en texto plano los expondría en la red.
	 */
	public static function base(): string {
		$base = defined( self::CONSTANT ) ? (string) constant( self::CONSTANT ) : '';

		/**
		 * Filtra la base del servicio de licencias.
		 *
		 * @param string $base URL base, sin barra final.
		 */
		$base = (string) apply_filters( 'nexo_mcp_api_url', $base );
		$base = untrailingslashit( trim( $base ) );

		if ( ! self::is_usable( $base ) ) {
			return '';
		}

		return $base;
	}

	/**
	 * Indica si hay un servicio de licencias utilizable configurado.
	 */
	public static function is_configured(): bool {
		return '' !== self::base();
	}

	/**
	 * Construye la URL de un recurso del servicio.
	 *
	 * @param string $path Ruta relativa, con o sin barra inicial.
	 * @throws \RuntimeException Si no hay servicio configurado.
	 */
	public static function url( string $path ): string {
		$base = self::base();

		if ( '' === $base ) {
			throw new \RuntimeException( 'not_configured' );
		}

		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Se mantiene por compatibilidad con el panel: un servicio configurado
	 * siempre es «propio», porque no existe uno de fábrica.
	 */
	public static function is_custom(): bool {
		return self::is_configured();
	}

	/**
	 * Comprueba que la base sea una URL HTTPS utilizable.
	 *
	 * @param string $base Valor a validar.
	 */
	private static function is_usable( string $base ): bool {
		if ( '' === $base ) {
			return false;
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		// Se admite HTTP sólo contra la máquina local, para poder levantar un
		// servicio de pruebas sin certificado. Cualquier otro host exige HTTPS.
		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme
			&& in_array( strtolower( (string) $parts['host'] ), array( 'localhost', '127.0.0.1', 'host.docker.internal' ), true );
	}
}
