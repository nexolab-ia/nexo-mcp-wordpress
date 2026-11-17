<?php
/**
 * Reconocimiento de un entorno de desarrollo de verdad.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Decide si el conector puede relajar sus exigencias.
 *
 * Un sitio local sin salida a internet no puede verificar su email ni hablar
 * con la plataforma, así que necesita un escape para funcionar. El problema es
 * quién lo abre: `NEXO_MCP_ALLOW_INSECURE_LOCAL` y `WP_ENVIRONMENT_TYPE` viven
 * las dos en `wp-config.php`, y WordPress no comprueba la segunda —se limita a
 * creer lo que el archivo declara—. Quien puede escribir una constante puede
 * escribir las dos, así que exigir ambas es pedir la misma llave dos veces.
 *
 * Por eso se mira además la dirección del sitio, que no se puede fingir sin
 * consecuencias: apuntarla a `localhost` deja el sitio real inaccesible.
 */
final class LocalEnvironment {

	/**
	 * Nombres que sólo puede tener una máquina de desarrollo.
	 */
	private const HOSTS_LOCALES = array(
		'localhost',
		'127.0.0.1',
		'::1',
		'[::1]',
	);

	/**
	 * Dominios de primer nivel reservados por la IANA para pruebas.
	 *
	 * Ninguno se puede registrar ni resolver en internet, así que un sitio en
	 * producción no puede estar detrás de ellos.
	 */
	private const SUFIJOS_LOCALES = array(
		'.test',
		'.local',
		'.localhost',
		'.invalid',
		'.example',
	);

	/**
	 * Indica si se puede aplicar el escape de desarrollo.
	 *
	 * Exige las dos constantes de siempre y, además, que la dirección del sitio
	 * sea realmente local.
	 */
	public static function bypass_allowed(): bool {
		return defined( 'NEXO_MCP_ALLOW_INSECURE_LOCAL' )
			&& true === NEXO_MCP_ALLOW_INSECURE_LOCAL
			&& 'local' === wp_get_environment_type()
			&& self::host_is_local();
	}

	/**
	 * Comprueba que la dirección del sitio no pueda existir en internet.
	 *
	 * Se usa `home_url()`, la dirección configurada del sitio, y no la cabecera
	 * `Host` de la petición: esa la elige quien llama y se falsifica escribiendo
	 * una línea. La configurada, en cambio, es la que usa WordPress para todos
	 * sus enlaces, así que falsearla rompe el sitio que se pretendía colar.
	 */
	private static function host_is_local(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, self::HOSTS_LOCALES, true ) ) {
			return true;
		}

		foreach ( self::SUFIJOS_LOCALES as $sufijo ) {
			if ( substr( $host, -strlen( $sufijo ) ) === $sufijo ) {
				return true;
			}
		}

		return self::is_private_ip( $host );
	}

	/**
	 * Indica si el host es una IP de red privada o reservada.
	 *
	 * Cubre las redes internas habituales —10.x, 172.16-31.x, 192.168.x— donde
	 * viven los entornos de pruebas de una oficina.
	 *
	 * @param string $host Host normalizado.
	 */
	private static function is_private_ip( string $host ): bool {
		$limpio = trim( $host, '[]' );

		if ( false === filter_var( $limpio, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// Si deja de validar al excluir los rangos privados y reservados, es
		// que pertenece a uno de ellos.
		return false === filter_var(
			$limpio,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
