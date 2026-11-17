<?php
/**
 * Empalme de un subárbol atómico en el documento decodificado de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Planifica y aplica `replace`, `insert` y `delete` sobre el árbol (clase pura).
 *
 * No toca la base de datos ni Elementor: trabaja sobre el documento ya decodificado
 * y no lo reescribe, sólo cambia una lista de hijos. Antes de devolver el resultado
 * demuestra que lo no tocado quedó idéntico y que los ids nuevos no chocan.
 */
final class ElementorSectionSplicer {

	public const OPERATIONS = array( 'replace', 'insert', 'delete' );
	public const POSITIONS  = array( 'before', 'after', 'inside' );

	/**
	 * Planifica la operación: dónde cuelga lo nuevo y qué se quita.
	 *
	 * @param array<int, mixed> $tree      Documento decodificado.
	 * @param string            $operation `replace`, `insert` o `delete`.
	 * @param string            $node_id   Nodo de referencia.
	 * @param string            $position  `before`, `after` o `inside` (sólo `insert`).
	 *
	 * @return array{operation: string, parent_kind: string, parent_path: array<int, int>, index: int, remove: bool, init_children: bool, base_depth: int, nodes_without: int, removed: array<int, string>}|\WP_Error
	 */
	public static function plan( array $tree, string $operation, string $node_id, string $position = '' ) {
		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_operation_invalid',
				__( 'La operación debe ser replace, insert o delete.', 'nexo-mcp' )
			);
		}

		if ( 'insert' === $operation && ! in_array( $position, self::POSITIONS, true ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_position_invalid',
				__( 'Con insert indica position: before, after o inside.', 'nexo-mcp' )
			);
		}

		if ( 'insert' !== $operation && '' !== $position ) {
			return new \WP_Error(
				'nexo_mcp_elementor_position_invalid',
				__( 'position sólo se usa con insert.', 'nexo-mcp' )
			);
		}

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento de referencia.', 'nexo-mcp' )
			);
		}

		$found = ElementorTree::locate( $tree, $node_id );

		if ( null === $found ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ElementorTree::count_id( $tree, $node_id ) > 1 ) {
			return new \WP_Error(
				'nexo_mcp_elementor_node_ambiguous',
				__( 'Ese id aparece más de una vez en el documento y no se sabe a cuál se refiere; no se cambia nada.', 'nexo-mcp' )
			);
		}

		$element = $found['element'];
		$total   = ElementorTree::count_nodes( $tree );
		$removed = ElementorTree::list_ids( array( $element ) );
		$depth   = count( $found['parent_path'] );

		if ( 'delete' === $operation && array() === $found['parent_path'] && 1 === count( $tree ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_last_root',
				__( 'Ese es el único elemento de la raíz: borrarlo dejaría la página vacía.', 'nexo-mcp' )
			);
		}

		if ( 'insert' === $operation && 'inside' === $position ) {
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			$kind     = ElementorNesting::kind_of( $element );

			return array(
				'operation'     => $operation,
				'parent_kind'   => $kind,
				// Un contenedor guardado sin la clave `elements` es un contenedor vacío.
				'init_children' => ! array_key_exists( 'elements', $element ) && in_array( $kind, array( ElementorNesting::CONTAINER, ElementorNesting::ATOMIC ), true ),
				'parent_path'   => array_merge( $found['parent_path'], array( $found['index'] ) ),
				'index'         => count( $children ),
				'remove'        => false,
				'base_depth'    => $depth + 1,
				'nodes_without' => $total,
				'removed'       => array(),
			);
		}

		$replaces = 'insert' !== $operation;

		return array(
			'operation'     => $operation,
			'parent_kind'   => ElementorNesting::kind_of_type( $found['parent'] ),
			'parent_path'   => $found['parent_path'],
			'index'         => $found['index'] + ( 'after' === $position ? 1 : 0 ),
			'remove'        => $replaces,
			'init_children' => false,
			'base_depth'    => $depth,
			'nodes_without' => $replaces ? $total - ElementorTree::count_nodes( array( $element ) ) : $total,
			'removed'       => $replaces ? $removed : array(),
		);
	}

	/**
	 * Aplica el plan con el subárbol ya armado y comprueba el resultado.
	 *
	 * @param array<int, mixed>                $tree  Documento decodificado.
	 * @param array<string, mixed>             $plan  Resultado de {@see self::plan()}.
	 * @param array<int, array<string, mixed>> $built Subárbol nuevo con ids del plugin (vacío al borrar).
	 *
	 * @return array{tree: array<int, mixed>, node_ids: array<int, string>, removed: array<int, string>}|\WP_Error
	 */
	public static function apply( array $tree, array $plan, array $built ) {
		$new_ids = ElementorTree::list_ids( $built );
		$before  = ElementorTree::collect_ids( $tree );

		if ( count( array_unique( $new_ids ) ) !== count( $new_ids ) || array() !== array_intersect_key( array_flip( $new_ids ), $before ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_id_collision',
				__( 'Un id nuevo coincide con uno que el documento ya tiene; no se cambia nada.', 'nexo-mcp' )
			);
		}

		$after    = $tree;
		$siblings = &ElementorTree::children_at( $after, $plan['parent_path'] );
		array_splice( $siblings, (int) $plan['index'], $plan['remove'] ? 1 : 0, $built );
		unset( $siblings );

		if ( array() === $after ) {
			return new \WP_Error(
				'nexo_mcp_elementor_last_root',
				__( 'La página se quedaría sin elementos en la raíz.', 'nexo-mcp' )
			);
		}

		// Prueba de que no se tocó nada más: sin lo quitado (antes) y sin lo nuevo
		// (después) los dos documentos deben ser el mismo, byte a byte una vez decodificados.
		$baseline = $tree;

		if ( ! empty( $plan['init_children'] ) ) {
			ElementorTree::children_at( $baseline, $plan['parent_path'] );
		}

		$gone = array_fill_keys( $plan['removed'], true );
		$made = array_fill_keys( $new_ids, true );

		$expected = wp_json_encode( ElementorTree::without_ids( $baseline, $gone ) );
		$actual   = wp_json_encode( ElementorTree::without_ids( $after, $made ) );

		if ( ! is_string( $expected ) || $expected !== $actual ) {
			return new \WP_Error(
				'nexo_mcp_elementor_structure_changed',
				__( 'El cambio habría alterado otros elementos de la página y se descartó; no se cambia nada.', 'nexo-mcp' )
			);
		}

		return array(
			'tree'     => $after,
			'node_ids' => $new_ids,
			'removed'  => $plan['removed'],
		);
	}
}
