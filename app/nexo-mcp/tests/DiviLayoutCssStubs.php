<?php
/**
 * Dobles de `ET_Core_PageResource` y `et_theme_builder_clear_wp_cache()` (los de Divi) que anotan sus llamadas en
 * `WP_Layout_Stub_State`. Sólo se incluye en pruebas de proceso aparte, después de DiviLayoutWpStubs.php.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! class_exists( 'ET_Core_PageResource' ) ) {
	final class ET_Core_PageResource {
		/**
		 * @param mixed $post_id Id de contenido o `all`.
		 * @param mixed $owner   Propietario o `all`.
		 */
		public static function remove_static_resources( $post_id, $owner ): void {
			WP_Layout_Stub_State::$css_calls[] = array( $post_id, $owner );
		}
	}
}

if ( ! function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
	/**
	 * @param mixed $post_ids Contenidos o `all`.
	 */
	function et_theme_builder_clear_wp_cache( $post_ids = 'all' ): void {
		WP_Layout_Stub_State::$cache_calls[] = $post_ids;
	}
}
