<?php
/**
 * Funciones de WordPress y de Divi que usan las plantillas del Theme Builder y que el arranque de las pruebas no trae:
 * `get_posts()` (sólo lo que busca el Theme Builder en vivo) y `et_theme_builder_get_theme_builder_post_id()`. Sólo se
 * incluye en pruebas de proceso aparte.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Template_Stub_State' ) ) {
	final class WP_Template_Stub_State {
		/** @var array<int, bool> Llamadas a `et_theme_builder_get_theme_builder_post_id()`: `$create` de cada una. */
		public static array $tb_calls = array();
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * @param array<string, mixed> $args Argumentos de WordPress (sólo tipo, estado, tope y `fields`).
	 *
	 * @return array<int, mixed>
	 */
	function get_posts( $args = array() ): array {
		$types  = (array) ( $args['post_type'] ?? 'post' );
		$status = (array) ( $args['post_status'] ?? 'publish' );
		$found  = array();

		foreach ( \WP_Test_State::$posts as $post ) {
			if ( isset( $args['meta_key'] ) && (string) get_post_meta( $post->ID, (string) $args['meta_key'], true ) !== (string) ( $args['meta_value'] ?? '' ) ) {
				continue;
			}

			if ( ( in_array( 'any', $types, true ) || in_array( $post->post_type, $types, true ) ) && ( in_array( 'any', $status, true ) || in_array( $post->post_status, $status, true ) ) ) {
				$found[] = $post;
			}
		}

		usort( $found, static fn( $a, $b ): int => $b->ID <=> $a->ID );
		$found = array_slice( $found, 0, (int) ( $args['posts_per_page'] ?? 5 ) );

		return 'ids' === ( $args['fields'] ?? '' ) ? array_map( static fn( $post ): int => $post->ID, $found ) : $found;
	}
}

if ( ! function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ) {
	/**
	 * Como el de Divi: el Theme Builder en vivo (`publish`) o el borrador (`auto-draft`); si no existe y `$create`, lo crea.
	 *
	 * @param bool $live   En vivo.
	 * @param bool $create Crear si falta.
	 */
	function et_theme_builder_get_theme_builder_post_id( $live, $create = true ): int {
		WP_Template_Stub_State::$tb_calls[] = (bool) $create;
		$status                             = $live ? 'publish' : 'auto-draft';
		$ids                                = get_posts(
			array(
				'post_type'      => 'et_theme_builder',
				'post_status'    => $status,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( array() !== $ids ) {
			return (int) $ids[0];
		}

		if ( ! $create ) {
			return 0;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'et_theme_builder',
				'post_status' => $status,
				'post_title'  => 'Theme Builder',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
