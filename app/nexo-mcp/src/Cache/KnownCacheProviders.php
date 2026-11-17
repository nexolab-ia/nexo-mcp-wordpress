<?php
/**
 * Proveedores de caché reconocidos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Cache;

/**
 * Describe cómo purgar cada plugin de caché admitido.
 *
 * Se declara como datos y no como una clase por plugin: todas las integraciones
 * hacen lo mismo —comprobar que el plugin está y llamar a lo suyo—, y una clase
 * de veinte líneas por cada uno sólo repartiría el mismo `function_exists` en
 * seis archivos.
 *
 * Cada entrada comprueba su disponibilidad antes de llamar nada. Los plugins de
 * caché cambian de API entre versiones mayores con más frecuencia de la
 * deseable, así que una función ausente se trata como «este proveedor no está»,
 * nunca como un error fatal.
 */
final class KnownCacheProviders {

	/**
	 * Construye la lista de proveedores admitidos.
	 *
	 * @return array<int, CacheProvider>
	 */
	public static function all(): array {
		return array(
			// WP Rocket expone funciones estables desde hace años.
			new CallableCacheProvider(
				'wp-rocket',
				'WP Rocket',
				static fn(): bool => function_exists( 'rocket_clean_post' ) || function_exists( 'rocket_clean_domain' ),
				static function ( int $post_id ): bool {
					if ( ! function_exists( 'rocket_clean_post' ) ) {
						return false;
					}

					rocket_clean_post( $post_id );

					return true;
				},
				static function (): bool {
					if ( ! function_exists( 'rocket_clean_domain' ) ) {
						return false;
					}

					rocket_clean_domain();

					return true;
				}
			),

			// LiteSpeed trabaja por acciones, que es su vía documentada.
			new CallableCacheProvider(
				'litespeed-cache',
				'LiteSpeed Cache',
				static fn(): bool => defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' ),
				static function ( int $post_id ): bool {
					do_action( 'litespeed_purge_post', $post_id );

					return true;
				},
				static function (): bool {
					do_action( 'litespeed_purge_all' );

					return true;
				}
			),

			// W3 Total Cache publica helpers globales para ambos alcances.
			new CallableCacheProvider(
				'w3-total-cache',
				'W3 Total Cache',
				static fn(): bool => function_exists( 'w3tc_flush_post' ) || function_exists( 'w3tc_flush_all' ),
				static function ( int $post_id ): bool {
					if ( ! function_exists( 'w3tc_flush_post' ) ) {
						return false;
					}

					w3tc_flush_post( $post_id );

					return true;
				},
				static function (): bool {
					if ( ! function_exists( 'w3tc_flush_all' ) ) {
						return false;
					}

					w3tc_flush_all();

					return true;
				}
			),

			// Swift Performance cambió de espacio de nombres entre versiones, así
			// que se prueban las dos formas antes de darlo por ausente.
			new CallableCacheProvider(
				'swift-performance',
				'Swift Performance',
				static fn(): bool => class_exists( '\Swift_Performance_Cache' ),
				static function ( int $post_id ): bool {
					if ( ! class_exists( '\Swift_Performance_Cache' ) ) {
						return false;
					}

					$url = get_permalink( $post_id );

					if ( is_string( $url ) && method_exists( '\Swift_Performance_Cache', 'clear_cache_by_url' ) ) {
						\Swift_Performance_Cache::clear_cache_by_url( $url );

						return true;
					}

					if ( method_exists( '\Swift_Performance_Cache', 'clear_all_cache' ) ) {
						\Swift_Performance_Cache::clear_all_cache();

						return true;
					}

					return false;
				},
				static function (): bool {
					if ( ! class_exists( '\Swift_Performance_Cache' ) || ! method_exists( '\Swift_Performance_Cache', 'clear_all_cache' ) ) {
						return false;
					}

					\Swift_Performance_Cache::clear_all_cache();

					return true;
				}
			),

			// WP Super Cache distingue el cambio de una entrada del vaciado total.
			new CallableCacheProvider(
				'wp-super-cache',
				'WP Super Cache',
				static fn(): bool => function_exists( 'wp_cache_post_change' ) || function_exists( 'wp_cache_clear_cache' ),
				static function ( int $post_id ): bool {
					if ( ! function_exists( 'wp_cache_post_change' ) ) {
						return false;
					}

					wp_cache_post_change( $post_id );

					return true;
				},
				static function (): bool {
					if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
						return false;
					}

					wp_cache_clear_cache();

					return true;
				}
			),

			// WP Fastest Cache sólo expone métodos en su instancia global.
			new CallableCacheProvider(
				'wp-fastest-cache',
				'WP Fastest Cache',
				static fn(): bool => isset( $GLOBALS['wp_fastest_cache'] ) && is_object( $GLOBALS['wp_fastest_cache'] ),
				static function ( int $post_id ): bool {
					$instancia = $GLOBALS['wp_fastest_cache'] ?? null;

					if ( ! is_object( $instancia ) || ! method_exists( $instancia, 'singleDeleteCache' ) ) {
						return false;
					}

					$instancia->singleDeleteCache( false, $post_id );

					return true;
				},
				static function (): bool {
					$instancia = $GLOBALS['wp_fastest_cache'] ?? null;

					if ( ! is_object( $instancia ) || ! method_exists( $instancia, 'deleteCache' ) ) {
						return false;
					}

					$instancia->deleteCache( true );

					return true;
				}
			),
		);
	}
}
