<?php
/**
 * Funciones de revisiones de WordPress que el arranque de las pruebas no trae (restore-revision de layouts).
 *
 * Las firmas siguen a las de WordPress. Sólo se incluye en pruebas de proceso aparte.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_get_post_revision' ) ) {
	/**
	 * @param int $revision_id Revisión.
	 *
	 * @return \WP_Post|null
	 */
	function wp_get_post_revision( $revision_id ) {
		$post = \WP_Test_State::$posts[ (int) $revision_id ] ?? null;

		return $post instanceof \WP_Post && 'revision' === $post->post_type ? $post : null;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	/**
	 * Copia el contenido de la revisión en su padre y lo anota en la prueba.
	 *
	 * @param int $revision_id Revisión.
	 *
	 * @return int|false
	 */
	function wp_restore_post_revision( $revision_id ) {
		$revision = wp_get_post_revision( $revision_id );

		if ( ! $revision instanceof \WP_Post || ! isset( \WP_Test_State::$posts[ $revision->post_parent ] ) ) {
			return false;
		}

		\WP_Test_State::$posts[ $revision->post_parent ]->post_content = $revision->post_content;
		\NexoDev\NexoMCP\Tests\DiviLayoutContentAdminTest::$restored[ (int) $revision_id ] = $revision->post_content;

		return $revision->post_parent;
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	/**
	 * @param array<int, int> $ids Contenidos.
	 */
	function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ): void {}
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	/**
	 * Menús de la prueba (id de término => nombre), en `WP_Layout_Stub_State::$menus`.
	 *
	 * @return array<int, object>
	 */
	function wp_get_nav_menus( $args = array() ): array {
		return array_map(
			static fn( int $id ): object => (object) array( 'term_id' => $id, 'name' => WP_Layout_Stub_State::$menus[ $id ] ),
			array_keys( WP_Layout_Stub_State::$menus )
		);
	}
}

if ( ! class_exists( 'WP_Layout_Stub_State' ) ) {
	final class WP_Layout_Stub_State {
		/** @var array<int, string> */
		public static array $menus = array( 34 => 'Principal', 35 => 'Pie' );
		/** @var array<int, array<int, mixed>> Llamadas a `ET_Core_PageResource::remove_static_resources()`. */
		public static array $css_calls = array();
		/** @var array<int, mixed> Llamadas a `et_theme_builder_clear_wp_cache()`. */
		public static array $cache_calls = array();
	}
}
