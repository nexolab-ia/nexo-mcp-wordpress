<?php
/**
 * Funciones de WordPress que usa la biblioteca de Divi 5 y que el arranque de las pruebas no trae: `wp_set_object_terms()`,
 * `get_terms()` y `wp_delete_term()`, con los términos como en WordPress (se reutilizan por slug y se guardan con `&amp;`).
 * Sólo se incluye en pruebas de proceso aparte.
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
		/** @var array<string, array<string, int>> Términos que existen aunque ningún ítem los tenga: taxonomía => nombre guardado => id. */
		public static array $known = array();
		/** @var string Taxonomía cuyo `wp_set_object_terms()` falla (WP_Error). */
		public static string $fail_terms_in = '';
		/** @var int Cuántas de las próximas llamadas a `get_terms()` fallan (WP_Error). */
		public static int $fail_get_terms = 0;
		/** @var callable|null Se llama dentro de `wp_set_object_terms()`, como otra petición que crea un término a la vez. */
		public static $during_terms = null;
		/** @var string Taxonomía cuyo `wp_set_object_terms()` crea los términos y después falla (WP_Error). */
		public static string $leak_terms_in = '';
		/** @var string Taxonomía cuyo `wp_set_object_terms()` dice que va bien pero no guarda nada. */
		public static string $lose_terms_in = '';

		/**
		 * Todos los términos que existen por taxonomía (los del registro y los de los ítems): nombre => id.
		 *
		 * @return array<string, array<string, int>>
		 */
		public static function registry(): array {
			$all = self::$known;

			foreach ( WP_Test_State::$terms as $taxonomies ) {
				foreach ( $taxonomies as $taxonomy => $terms ) {
					foreach ( $terms as $name => $id ) {
						$all[ $taxonomy ][ (string) $name ] = (int) $id;
					}
				}
			}

			ksort( $all );

			return $all;
		}

		/**
		 * Slug como el de WordPress, para reconocer el mismo nombre.
		 *
		 * @param string $name Nombre.
		 */
		public static function slug( string $name ): string {
			return sanitize_title( html_entity_decode( $name, ENT_QUOTES ) );
		}
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

		if ( null !== WP_Library_Stub_State::$during_terms ) {
			( WP_Library_Stub_State::$during_terms )();
		}

		// Un término que un ítem tenía existe aunque el ítem deje de tenerlo.
		WP_Library_Stub_State::$known = WP_Library_Stub_State::registry();
		$known                        = WP_Library_Stub_State::$known[ $taxonomy ] ?? array();
		$current = $append ? ( WP_Test_State::$terms[ (int) $object_id ][ $taxonomy ] ?? array() ) : array();

		foreach ( (array) $terms as $name ) {
			$slug   = WP_Library_Stub_State::slug( (string) $name );
			$stored = null;

			// Como WordPress: un término con ese slug se reutiliza con SU nombre; uno nuevo se guarda con `&` escapado.
			foreach ( $known as $existing => $id ) {
				if ( WP_Library_Stub_State::slug( (string) $existing ) === $slug ) {
					$stored = array( (string) $existing, $id );
					break;
				}
			}

			if ( null === $stored ) {
				$escaped = str_replace( '&', '&amp;', html_entity_decode( (string) $name, ENT_QUOTES ) );
				$stored  = array( $escaped, WP_Library_Stub_State::$next_term++ );
			}

			$current[ $stored[0] ]                               = $stored[1];
			WP_Library_Stub_State::$known[ $taxonomy ][ $stored[0] ] = $stored[1];
		}

		if ( WP_Library_Stub_State::$lose_terms_in === $taxonomy ) {
			return array_values( $current );
		}

		WP_Test_State::$terms[ (int) $object_id ][ $taxonomy ] = $current;

		return WP_Library_Stub_State::$leak_terms_in === $taxonomy ? new WP_Error( 'db_error', 'Falló después de crear los términos.' ) : array_values( $current );
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Sólo `taxonomy` y `fields => ids`.
	 *
	 * @param array<string, mixed> $args Argumentos.
	 *
	 * @return array<int, int>|\WP_Error
	 */
	function get_terms( $args = array() ) {
		if ( WP_Library_Stub_State::$fail_get_terms > 0 ) {
			--WP_Library_Stub_State::$fail_get_terms;

			return new WP_Error( 'db_error', 'No se pudo leer.' );
		}

		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		$all      = WP_Library_Stub_State::registry()[ $taxonomy ] ?? array();

		if ( ! empty( $args['hide_empty'] ) ) {
			$used = array();

			foreach ( WP_Test_State::$terms as $taxonomies ) {
				$used = array_merge( $used, array_values( $taxonomies[ $taxonomy ] ?? array() ) );
			}

			$all = array_filter( $all, static fn( int $id ): bool => in_array( $id, $used, true ) );
		}

		return array_values( $all );
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	/**
	 * Como WordPress: un nombre o slug que ya existe da `term_exists` (con el id en los datos).
	 *
	 * @param string $term     Nombre.
	 * @param string $taxonomy Taxonomía.
	 *
	 * @return array{term_id: int}|\WP_Error
	 */
	function wp_insert_term( $term, $taxonomy ) {
		WP_Library_Stub_State::registry();
		$slug = WP_Library_Stub_State::slug( (string) $term );

		foreach ( WP_Library_Stub_State::registry()[ $taxonomy ] ?? array() as $existing => $id ) {
			if ( WP_Library_Stub_State::slug( (string) $existing ) === $slug ) {
				return new WP_Error( 'term_exists', 'El término ya existe.', $id );
			}
		}

		$id                                                          = WP_Library_Stub_State::$next_term++;
		WP_Library_Stub_State::$known[ $taxonomy ][ str_replace( '&', '&amp;', html_entity_decode( (string) $term, ENT_QUOTES ) ) ] = $id;

		return array( 'term_id' => $id );
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	/**
	 * @param int    $term     Término.
	 * @param string $taxonomy Taxonomía.
	 */
	function wp_delete_term( $term, $taxonomy ): bool {
		foreach ( WP_Library_Stub_State::$known[ $taxonomy ] ?? array() as $name => $id ) {
			if ( $id === (int) $term ) {
				unset( WP_Library_Stub_State::$known[ $taxonomy ][ $name ] );
			}
		}

		foreach ( WP_Test_State::$terms as $object => $taxonomies ) {
			foreach ( $taxonomies[ $taxonomy ] ?? array() as $name => $id ) {
				if ( $id === (int) $term ) {
					unset( WP_Test_State::$terms[ $object ][ $taxonomy ][ $name ] );
				}
			}
		}

		return true;
	}
}
