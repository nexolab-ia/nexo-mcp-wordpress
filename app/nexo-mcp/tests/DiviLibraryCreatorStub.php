<?php
/**
 * `et_pb_create_layout()` de Divi con la misma forma que la real (shortcode-core.php:738): inserta el post, añade las metas,
 * crea las categorías por nombre y pone los términos; devuelve el id sólo si hay términos. Se incluye aparte para poder
 * probar también el camino sin Divi.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

require_once __DIR__ . '/DiviLibraryWpStubs.php';

if ( ! function_exists( 'et_pb_create_layout' ) ) {
	/**
	 * @param string               $name         Título.
	 * @param string               $content      Contenido (con barras, como espera `wp_insert_post()`).
	 * @param array<string,string> $meta         Metas.
	 * @param array<string,mixed>  $tax_input    Términos por taxonomía.
	 * @param string               $new_category Nombres de categorías separados por coma.
	 *
	 * @return int|null
	 */
	function et_pb_create_layout( $name, $content, $meta = array(), $tax_input = array(), $new_category = '', $new_tag = '', $post_status = 'publish', $post_date = null ) {
		WP_Library_Stub_State::$create_calls[] = array( $name, $content, $meta, $tax_input, $new_category, $post_status );

		$id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $name ),
				'post_content' => $content,
				'post_status'  => $post_status,
				'post_type'    => 'et_pb_layout',
			)
		);

		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, sanitize_text_field( $value ) );
		}

		foreach ( array_filter( array_map( 'trim', explode( ',', $new_category ) ) ) as $category ) {
			$tax_input['layout_category'][] = $category;
		}

		if ( ! empty( $tax_input ) ) {
			foreach ( $tax_input as $taxonomy => $terms ) {
				wp_set_object_terms( $id, $terms, $taxonomy );
			}

			return $id;
		}

		return null;
	}
}
