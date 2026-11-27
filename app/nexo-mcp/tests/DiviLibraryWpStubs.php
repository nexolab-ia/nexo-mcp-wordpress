<?php
/**
 * Funciones de WordPress que usa la biblioteca de Divi 5 y que el arranque de las pruebas no trae: `wp_set_object_terms()`
 * (términos por nombre, que en el doble son también el slug). Sólo se incluye en pruebas de proceso aparte.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Library_Stub_State' ) ) {
	final class WP_Library_Stub_State {
		/** @var array<int, array<string, mixed>> Llamadas a `et_pb_create_layout()`. */
		public static array $create_calls = array();
		/** @var array<int, array{int, string, array<int, string>}> Llamadas a `wp_set_object_terms()`: id, taxonomía, nombres. */
		public static array $term_calls = array();
		/** @var int Próximo id de término. */
		public static int $next_term = 500;
		/** @var bool `et_pb_create_layout()` lanza justo después de insertar el post. */
		public static bool $throw_after_insert = false;
		/** @var string Taxonomía cuyo `wp_set_object_terms()` falla (WP_Error). */
		public static string $fail_terms_in = '';
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	/**
	 * @param int                         $object_id Contenido.
	 * @param string|int|array<int,mixed> $terms     Nombres.
	 * @param string                      $taxonomy  Taxonomía.
	 * @param bool                        $append    Si se suman a los que hay.
	 *
	 * @return array<int, int>|\WP_Error
	 */
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		WP_Library_Stub_State::$term_calls[] = array( (int) $object_id, (string) $taxonomy, array_map( 'strval', (array) $terms ) );

		if ( WP_Library_Stub_State::$fail_terms_in === $taxonomy ) {
			return new WP_Error( 'invalid_taxonomy', 'Taxonomía inválida.' );
		}

		// Como WordPress: un nombre que ya tenía el ítem conserva su término.
		$had     = WP_Test_State::$terms[ (int) $object_id ][ $taxonomy ] ?? array();
		$current = $append ? $had : array();

		foreach ( (array) $terms as $name ) {
			$current[ (string) $name ] = $had[ (string) $name ] ?? WP_Library_Stub_State::$next_term++;
		}

		WP_Test_State::$terms[ (int) $object_id ][ $taxonomy ] = $current;

		return array_values( $current );
	}
}
