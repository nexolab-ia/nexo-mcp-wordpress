<?php
/**
 * Ayudantes puros sobre el documento decodificado de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Localizar, listar y recortar nodos del árbol `_elementor_data` ya decodificado.
 *
 * Los usan el duplicado de elementos ({@see ElementorAdapter}) y la edición de
 * secciones ({@see ElementorSectionSplicer}); ninguno toca la base de datos.
 */
final class ElementorTree {

	/**
	 * Localiza un elemento y el lugar que ocupa.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 * @param string            $id       Identificador buscado.
	 * @param array<int, int>   $path     Posiciones hasta el padre.
	 * @param string            $owner    Tipo del padre, o `root`.
	 *
	 * @return array{element: array<string, mixed>, parent: string, parent_path: array<int, int>, index: int}|null
	 */
	public static function locate( array $elements, string $id, array $path = array(), string $owner = 'root' ): ?array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array(
					'element'     => $element,
					'parent'      => $owner,
					'parent_path' => $path,
					'index'       => (int) $index,
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::locate( $element['elements'], $id, array_merge( $path, array( (int) $index ) ), (string) ( $element['elType'] ?? '' ) );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Devuelve por referencia la lista de hijos en una posición del árbol.
	 *
	 * Si el padre no tiene lista de hijos todavía, queda una vacía.
	 *
	 * @param array<int, mixed> $tree Documento completo.
	 * @param array<int, int>   $path Posiciones hasta el padre; vacío, la raíz.
	 *
	 * @return array<int, mixed>
	 */
	public static function &children_at( array &$tree, array $path ): array {
		$level = &$tree;

		foreach ( $path as $index ) {
			$level = &$level[ $index ]['elements'];

			if ( ! is_array( $level ) ) {
				$level = array();
			}
		}

		return $level;
	}

	/**
	 * Reúne los identificadores usados en el documento.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 *
	 * @return array<string, true>
	 */
	public static function collect_ids( array $elements ): array {
		$ids = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$ids[ (string) ( $element['id'] ?? '' ) ] = true;

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids += self::collect_ids( $element['elements'] );
			}
		}

		return $ids;
	}

	/**
	 * Lista los identificadores en orden de documento, con repeticiones.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 *
	 * @return array<int, string>
	 */
	public static function list_ids( array $elements ): array {
		$ids = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$ids[] = (string) ( $element['id'] ?? '' );

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, self::list_ids( $element['elements'] ) );
			}
		}

		return $ids;
	}

	/**
	 * Cuenta los elementos del árbol.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 */
	public static function count_nodes( array $elements ): int {
		$total = 0;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			++$total;

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$total += self::count_nodes( $element['elements'] );
			}
		}

		return $total;
	}

	/**
	 * Cuántas veces aparece un identificador.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 * @param string            $id       Identificador buscado.
	 */
	public static function count_id( array $elements, string $id ): int {
		return count( array_keys( self::list_ids( $elements ), $id, true ) );
	}

	/**
	 * Copia del árbol sin los nodos cuyo id está en el conjunto (con sus subárboles).
	 *
	 * @param array<int, mixed>   $elements Elementos del nivel actual.
	 * @param array<string, true> $skip     Conjunto de ids a omitir.
	 *
	 * @return array<int, mixed>
	 */
	public static function without_ids( array $elements, array $skip ): array {
		$out = array();

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $skip[ (string) ( $element['id'] ?? '' ) ] ) ) {
				continue;
			}

			if ( is_array( $element ) && isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::without_ids( $element['elements'], $skip );
			}

			$out[] = $element;
		}

		return $out;
	}
}
