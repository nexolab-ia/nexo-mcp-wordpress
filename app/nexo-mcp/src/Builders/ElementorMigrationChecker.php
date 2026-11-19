<?php
/**
 * Validación del árbol que produce la migración v3 → atómico antes de guardarlo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Deja pasar sólo lo que `migrate-elementor-page` puede escribir.
 *
 * El validador del plugin rechaza todo elemento de Elementor 3 en un subárbol nuevo
 * (`create-elementor-page` y `edit-elementor-section` siguen igual). La migración
 * conserva widgets v3 sin equivalente, así que este paso, y sólo este, los admite con
 * una regla cerrada sobre el árbol original leído bajo el bloqueo:
 *
 * - Un nodo idéntico (todo menos sus hijos) a uno del original se acepta tal cual: es
 *   un v3 conservado o un atómico que ya estaba. No lo escribió la migración.
 * - Cualquier otro nodo tiene que ser atómico y pasar la validación de siempre
 *   ({@see ElementorTreeValidator}, con anidado según su padre real, y la validación y el
 *   saneado de {@see ElementorSchemaGuard}); lo que se guarda es lo saneado.
 * - Un nodo v3 nuevo o modificado, o un nodo nuevo con el id de uno del original, se
 *   rechaza. Los ids no se repiten.
 *
 * Los topes (nodos, bytes) valen para el documento resultante; la profundidad, para los
 * nodos nuevos.
 */
final class ElementorMigrationChecker {

	/**
	 * Guarda de esquema.
	 *
	 * @var ElementorSchemaGuard
	 */
	private ElementorSchemaGuard $guard;

	/**
	 * Validador de nodos nuevos, armado una vez por comprobación.
	 *
	 * @var ElementorTreeValidator|null
	 */
	private ?ElementorTreeValidator $validator = null;

	/**
	 * Nodos del árbol original por id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $original = array();

	/**
	 * Tope de nodos del documento resultante.
	 *
	 * @var int
	 */
	private int $max_nodes = 0;

	/**
	 * Construye el comprobador.
	 *
	 * @param ElementorSchemaGuard $guard Guarda de esquema.
	 */
	public function __construct( ElementorSchemaGuard $guard ) {
		$this->guard = $guard;
	}

	/**
	 * Comprueba el árbol migrado y devuelve el que se guarda (con lo nuevo ya saneado).
	 *
	 * @param array<int, array<string, mixed>> $migrated  Árbol migrado, en formato guardado.
	 * @param array<int, array<string, mixed>> $original  Árbol original leído bajo el bloqueo.
	 * @param int                              $max_nodes Tope de nodos: `max( 200, nodos actuales )`.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function check( array $migrated, array $original, int $max_nodes ) {
		$this->validator = $this->guard->validator();
		$this->max_nodes = $max_nodes;
		$this->original  = array();

		$this->index( $original );

		if ( ElementorTree::count_nodes( $migrated ) > $max_nodes ) {
			return new \WP_Error(
				'nexo_mcp_elementor_too_many_nodes',
				sprintf(
					/* translators: %d: máximo de elementos. */
					__( 'La página migrada superaría el máximo de %d elementos; no se cambia nada.', 'nexo-mcp' ),
					$max_nodes
				)
			);
		}

		$ids = ElementorTree::list_ids( $migrated );

		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_migration_id_clash',
				__( 'La página (o la migración) tiene ids de elemento repetidos; no se cambia nada.', 'nexo-mcp' )
			);
		}

		$checked = $this->walk( $migrated, ElementorNesting::ROOT, 1 );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$encoded = wp_json_encode( $checked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || strlen( $encoded ) > ElementorTreeValidator::MAX_BYTES ) {
			return new \WP_Error(
				'nexo_mcp_elementor_too_large',
				__( 'La página migrada pesaría más de 3 MB; no se cambia nada.', 'nexo-mcp' )
			);
		}

		return $checked;
	}

	/**
	 * Indexa los nodos del original por id.
	 *
	 * @param array<int|string, mixed> $nodes Nodos.
	 */
	private function index( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = (string) ( $node['id'] ?? '' );

			if ( '' !== $id && ! isset( $this->original[ $id ] ) ) {
				$this->original[ $id ] = $node;
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->index( $node['elements'] );
			}
		}
	}

	/**
	 * Recorre una lista de hermanos.
	 *
	 * @param array<int|string, mixed> $nodes       Nodos.
	 * @param string                   $parent_kind Clase del padre (ElementorNesting).
	 * @param int                      $depth       Nivel de los nodos (1 = raíz).
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function walk( array $nodes, string $parent_kind, int $depth ) {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				return new \WP_Error(
					'nexo_mcp_elementor_tree_invalid',
					__( 'La migración produjo un elemento que no es un objeto; no se cambia nada.', 'nexo-mcp' )
				);
			}

			$checked = $this->walk_node( $node, $parent_kind, $depth );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$out[] = $checked;
		}

		return $out;
	}

	/**
	 * Comprueba un nodo y sus hijos.
	 *
	 * @param array<string, mixed> $node        Nodo migrado.
	 * @param string               $parent_kind Clase del padre.
	 * @param int                  $depth       Nivel del nodo.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_node( array $node, string $parent_kind, int $depth ) {
		$el_type  = (string) ( $node['elType'] ?? '' );
		$children = $node['elements'] ?? array();
		$children = is_array( $children ) ? $children : array();
		$kind     = ElementorNesting::kind_of_type( $el_type );

		if ( $this->is_unchanged( $node ) ) {
			$stored = $node;
		} else {
			$stored = $this->check_new( $node, $parent_kind, $depth );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
		}

		if ( array_key_exists( 'elements', $node ) ) {
			$walked = $this->walk( $children, $kind, $depth + 1 );

			if ( is_wp_error( $walked ) ) {
				return $walked;
			}

			$stored['elements'] = $walked;
		}

		return $stored;
	}

	/**
	 * Si el nodo es idéntico (menos sus hijos) a uno del original con el mismo id.
	 *
	 * @param array<string, mixed> $node Nodo migrado.
	 */
	private function is_unchanged( array $node ): bool {
		$id = (string) ( $node['id'] ?? '' );

		if ( '' === $id || ! isset( $this->original[ $id ] ) ) {
			return false;
		}

		$own  = $node;
		$from = $this->original[ $id ];

		unset( $own['elements'], $from['elements'] );

		return $own === $from;
	}

	/**
	 * Valida un nodo que no está en el original: tiene que ser atómico y válido.
	 *
	 * @param array<string, mixed> $node        Nodo migrado (sin comprobar).
	 * @param string               $parent_kind Clase del padre.
	 * @param int                  $depth       Nivel del nodo.
	 *
	 * @return array<string, mixed>|\WP_Error Nodo a guardar, sin hijos.
	 */
	private function check_new( array $node, string $parent_kind, int $depth ) {
		$id      = (string) ( $node['id'] ?? '' );
		$el_type = (string) ( $node['elType'] ?? '' );
		$type    = 'widget' === $el_type ? (string) ( $node['widgetType'] ?? '' ) : $el_type;

		if ( '' === $id ) {
			return new \WP_Error(
				'nexo_mcp_elementor_tree_invalid',
				__( 'La página (o la migración) tiene un elemento sin id; no se cambia nada.', 'nexo-mcp' )
			);
		}

		if ( ! in_array( $type, ElementorTreeValidator::allowed_types(), true ) && ! str_starts_with( $type, 'e-' ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v3_not_identical',
				sprintf(
					/* translators: 1: id del elemento, 2: tipo. */
					__( 'El elemento «%1$s» (%2$s) es de Elementor 3 y no es idéntico a ninguno de la página original: sólo se conservan los nodos v3 sin cambios; no se cambia nada.', 'nexo-mcp' ),
					$id,
					'' === $type ? $el_type : $type
				)
			);
		}

		if ( isset( $this->original[ $id ] ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_migration_id_clash',
				sprintf(
					/* translators: %s: id del elemento. */
					__( 'El elemento «%s» cambió respecto de la página original pero conserva su id; no se cambia nada.', 'nexo-mcp' ),
					$id
				)
			);
		}

		$children = $node['elements'] ?? array();

		if ( ! in_array( $type, ElementorTreeValidator::CONTAINERS, true ) && is_array( $children ) && array() !== $children ) {
			return $this->prefixed(
				$id,
				new \WP_Error(
					'nexo_mcp_elementor_children_denied',
					sprintf(
						/* translators: 1: tipo del elemento, 2: contenedores admitidos. */
						__( '«%1$s» no admite elementos hijos; sólo los contenedores (%2$s).', 'nexo-mcp' ),
						$type,
						implode( ', ', ElementorTreeValidator::CONTAINERS )
					)
				)
			);
		}

		$client = array(
			'type'     => $type,
			'settings' => $node['settings'] ?? array(),
			'styles'   => $node['styles'] ?? array(),
		);

		$valid = $this->validator()->validate( array( $client ), $parent_kind, $depth - 1, 0, $this->max_nodes );

		if ( is_wp_error( $valid ) ) {
			return $this->prefixed( $id, $valid );
		}

		$valid = $this->guard->validate( array( $client ) );

		if ( is_wp_error( $valid ) ) {
			return $this->prefixed( $id, $valid );
		}

		$clean = $this->guard->sanitize( array( $client ) );

		if ( is_wp_error( $clean ) ) {
			return $this->prefixed( $id, $clean );
		}

		// Only the keys Elementor stores for an atomic node; anything else the input carried is dropped.
		$stored = array(
			'id'     => $id,
			'elType' => $el_type,
		);

		if ( 'widget' === $el_type ) {
			$stored['widgetType'] = $type;
		}

		$stored['settings'] = $clean[0]['settings'] ?? array();
		$stored['styles']   = $clean[0]['styles'] ?? array();
		$stored['elements'] = array();

		return $stored;
	}

	/**
	 * Validador de la comprobación en curso.
	 */
	private function validator(): ElementorTreeValidator {
		return $this->validator ?? $this->guard->validator();
	}

	/**
	 * El mismo error con el id del nodo delante del mensaje.
	 *
	 * @param string    $id    Id del nodo.
	 * @param \WP_Error $error Error original.
	 */
	private function prefixed( string $id, \WP_Error $error ): \WP_Error {
		return new \WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: id del elemento, 2: motivo. */
				__( 'Elemento «%1$s»: %2$s', 'nexo-mcp' ),
				$id,
				$error->get_error_message()
			),
			$error->get_error_data()
		);
	}
}
