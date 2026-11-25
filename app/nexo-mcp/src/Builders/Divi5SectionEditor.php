<?php
/**
 * Reconstrucción de secciones de Divi 5 por ruta y huella, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * `replace`, `insert` (before, after, inside) y `delete` de un nodo de una página de Divi 5.
 *
 * Mismo camino que {@see Divi5ModuleEditor} ({@see Divi5ContentWriter}): todo corre bajo el
 * bloqueo del original; destino, bloqueo de edición y modo «sólo borradores» antes de leer;
 * luego forma de la petición, nodo por `path`, `fingerprint` (si cambió, no se escribe) y el
 * subárbol nuevo validado con {@see DiviTreeValidator::validate_tree()} contra el tipo del
 * padre real (placeholder > section > row > column > módulos; icon-list > icon-list-item).
 * Sólo después se crea o reutiliza la copia, se respalda y se guarda. Nunca publica.
 *
 * Sólo cambia la lista de hijos del padre ({@see Divi5Tree::splice()}): los nodos fuera del
 * subárbol reemplazado conservan sus bytes. Borrar la última sección de la raíz se rechaza.
 *
 * Si algún nodo nuevo trae `preset`, la validación y el guardado corren bajo el bloqueo de presets
 * ({@see DiviDesignStore::LOCK_ID}, el del borrado), tomado después del de la página; ocupado → su error, sin escribir.
 * Sin `preset` no se toma.
 */
final class Divi5SectionEditor {

	public const OPERATIONS = array( 'replace', 'insert', 'delete' );
	public const POSITIONS  = array( 'before', 'after', 'inside' );

	/**
	 * Camino de escritura compartido.
	 *
	 * @var Divi5ContentWriter
	 */
	private Divi5ContentWriter $writer;

	/**
	 * Validador del subárbol.
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Versión de Divi para `builderVersion`: fn(): string.
	 *
	 * @var callable
	 */
	private $version;

	/**
	 * Bloqueo de presets: fn( callable ): mixed.
	 *
	 * @var callable
	 */
	private $presets_lock;

	/**
	 * Esquema que resume las condiciones que se pierden (S7).
	 *
	 * @var DiviConditionsSchema
	 */
	private DiviConditionsSchema $conditions;

	/**
	 * Construye el editor.
	 *
	 * @param Settings                  $settings     Configuración.
	 * @param Divi5Adapter              $adapter      Adaptador de Divi 5.
	 * @param BuilderBackup             $backup       Respaldos.
	 * @param WorkingCopy               $copies       Copias de trabajo.
	 * @param DiviTreeValidator         $validator    Validador del subárbol.
	 * @param callable                  $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null             $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null             $saver        fn( int, string ): true|\WP_Error. Por omisión, `wp_update_post()` (sólo pruebas).
	 * @param callable|null             $version      fn(): string. Por omisión, la versión de Divi instalada.
	 * @param callable|null             $presets_lock fn( callable ): mixed. Bloqueo de presets; por omisión, el de {@see DiviDesignStore::LOCK_ID}.
	 * @param DiviConditionsSchema|null $conditions Esquema que resume las condiciones del subárbol reemplazado o borrado.
	 * @param callable|null             $layout_allowed fn(): bool. Permiso del Theme Builder para escribir un layout (ver {@see DiviLayoutGuard}).
	 */
	public function __construct(
		Settings $settings,
		Divi5Adapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		DiviTreeValidator $validator,
		callable $lock_check,
		?callable $divi5_active = null,
		?callable $saver = null,
		?callable $version = null,
		?callable $presets_lock = null,
		?DiviConditionsSchema $conditions = null,
		?callable $layout_allowed = null
	) {
		$this->writer       = new Divi5ContentWriter( $settings, $adapter, $backup, $copies, $lock_check, $divi5_active, $saver, $layout_allowed );
		$this->validator    = $validator;
		$this->version      = $version ?? array( Divi5BlockBuilder::class, 'installed_version' );
		$this->conditions   = $conditions ?? new DiviConditionsSchema();
		$this->presets_lock = $presets_lock ?? static fn( callable $work ) => PostLock::run( DiviDesignStore::LOCK_ID, $work );
	}

	/**
	 * Reconstruye el nodo.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `operation`, `path`, `fingerprint`, `position`, `sections`, `draft`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit( \WP_Post $post, array $input ) {
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				if ( ! DiviTreeValidator::tree_has_preset( $input['sections'] ?? null ) ) {
					return $this->edit_locked( $fresh, $input );
				}

				return ( $this->presets_lock )( fn() => $this->edit_locked( $fresh, $input ) );
			}
		);
	}

	/**
	 * Edita ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_locked( \WP_Post $post, array $input ) {
		$target = $this->writer->target( $post, $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$prepared = $this->prepare( $target['source'], $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$written = $this->writer->write( $post, $target, $prepared['content'] );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$fresh = get_post( $written->ID );
		$tree  = Divi5Tree::build( $fresh instanceof \WP_Post ? (string) $fresh->post_content : $prepared['content'] );
		$nodes = array();

		for ( $i = 0; $i < $prepared['added']; $i++ ) {
			$node = Divi5Tree::locate( $tree, ( '' === $prepared['parent'] ? '' : $prepared['parent'] . '.' ) . ( $prepared['index'] + $i ) );

			if ( null !== $node ) {
				$nodes = array_merge( $nodes, Divi5Tree::summarize( $node ) );
			}
		}

		$out = array_merge( Divi5ContentWriter::describe( $written ), array( 'operation' => $prepared['operation'] ) );

		if ( 'delete' === $prepared['operation'] ) {
			$out['removed'] = $prepared['path'];
		}

		$out['nodes'] = $nodes;

		if ( array() !== $prepared['dropped'] ) {
			$out['dropped_conditions'] = $prepared['dropped'];
		}

		if ( array() !== $prepared['dropped_post_content'] ) {
			$out['dropped_post_content'] = $prepared['dropped_post_content'];
		}

		if ( array() !== $prepared['warnings'] ) {
			$out['warnings'] = $prepared['warnings'];
		}

		return $out;
	}

	/**
	 * Comprueba la petición, localiza el nodo, valida el subárbol y arma el contenido nuevo.
	 *
	 * @param \WP_Post             $source Contenido sobre el que se trabaja.
	 * @param array<string, mixed> $input  Parámetros.
	 *
	 * @return array{content: string, operation: string, path: string, parent: string, index: int, added: int, dropped: array<int, array<string, mixed>>, dropped_post_content: array<int, array<string, string>>, warnings: array<int, string>}|\WP_Error
	 */
	private function prepare( \WP_Post $source, array $input ) {
		$operation   = isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '';
		$position    = isset( $input['position'] ) && is_string( $input['position'] ) ? $input['position'] : '';
		$path        = isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '';
		$fingerprint = isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '';
		$sections    = $input['sections'] ?? null;

		$shape = $this->check_shape( $operation, $position, $sections );

		if ( is_wp_error( $shape ) ) {
			return $shape;
		}

		$content = (string) $source->post_content;
		$blocks  = parse_blocks( $content );
		$tree    = Divi5Tree::build( $content );
		$node    = Divi5Tree::locate( $tree, $path );

		$located = Divi5Tree::locate_block( $blocks, $path );

		if ( null === $node || null === $located ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El nodo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ! Divi5Tree::fingerprint_matches( $tree, $path, $fingerprint ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_node_changed',
				__( 'El nodo cambió desde que lo leíste (la huella no coincide). Vuelve a leer la página con nexo-mcp-get-page-structure; no se escribió nada.', 'nexo-mcp' )
			);
		}

		// El placeholder raíz envuelve todo el documento: sólo se le insertan secciones dentro.
		if ( 'divi/placeholder' === ( $node['type'] ?? null ) && ! ( 'insert' === $operation && 'inside' === $position ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_operation_invalid',
				__( 'El contenedor raíz de Divi (divi/placeholder) no se reemplaza, borra ni recibe hermanos: trabaja sobre sus secciones o inserta con position inside. No se escribió nada.', 'nexo-mcp' )
			);
		}

		$segments = explode( '.', $path );
		$last     = (int) array_pop( $segments );
		$parent   = implode( '.', $segments );
		$replaces = 'insert' !== $operation;
		$index    = $last;
		$siblings = $tree;
		$kind     = DiviModuleCatalog::ROOT;

		if ( 'insert' === $operation && 'inside' === $position ) {
			$parent   = $path;
			$kind     = (string) $node['type'];
			$siblings = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$index    = count( $siblings );
		} else {
			$index = $last + ( 'after' === $position ? 1 : 0 );

			if ( '' !== $parent ) {
				$parent_node = Divi5Tree::locate( $tree, $parent );
				$kind        = null === $parent_node ? DiviModuleCatalog::ROOT : (string) $parent_node['type'];
				$siblings    = null !== $parent_node && isset( $parent_node['children'] ) && is_array( $parent_node['children'] ) ? $parent_node['children'] : array();
			}
		}

		if ( 'delete' === $operation && count( $siblings ) <= 1 && in_array( $kind, array( DiviModuleCatalog::ROOT, 'divi/placeholder' ), true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_last_section',
				__( 'Ese es el único elemento de la raíz de la página: borrarlo la dejaría vacía. Reemplázalo en su lugar; no se escribió nada.', 'nexo-mcp' )
			);
		}

		$new = array();

		if ( 'delete' !== $operation ) {
			$base = Divi5Tree::count_blocks( $blocks ) - ( $replaces ? Divi5Tree::count_blocks( array( $located ) ) : 0 );
			// Un layout del Theme Builder admite además `divi/post-content`, `divi/post-title` y `divi/menu`; una página no.
			$validator = DiviLayoutGuard::is_layout( $source ) ? $this->validator->with_layout() : $this->validator;
			$clean     = $validator->validate_tree( $sections, $kind, 0, $base );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$new = Divi5BlockBuilder::build( $clean, (string) ( $this->version )() );
		}

		$spliced = Divi5Tree::splice( $blocks, $parent, $index, $replaces ? 1 : 0, $new );

		if ( null === $spliced ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El nodo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		$content = serialize_blocks( $spliced );
		$lost    = $replaces ? $this->dropped_post_content( $located, $path ) : array();

		return array(
			'content'              => $content,
			'operation'            => $operation,
			'path'                 => $path,
			'parent'               => $parent,
			'index'                => $index,
			'added'                => count( $new ),
			'dropped'              => $replaces ? $this->dropped( $located, $path ) : array(),
			'dropped_post_content' => $lost,
			'warnings'             => $this->post_content_warnings( $source, $lost, $content ),
		);
	}

	/**
	 * `divi/post-content` del nodo y de sus descendientes, que replace/delete pierden: `{path, type}` en orden de documento.
	 *
	 * El módulo que pone el contenido de la página dentro de un cuerpo del Theme Builder: sin él el cuerpo REEMPLAZA el
	 * contenido de las páginas a las que se asigne (S6). Se avisa en vez de callar.
	 *
	 * @param array<string, mixed> $block Bloque que se reemplaza o borra.
	 * @param string               $path  Su ruta.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function dropped_post_content( array $block, string $path ): array {
		$out = array();
		$all = array_merge(
			array(
				array(
					'path'  => $path,
					'block' => $block,
				),
			),
			Divi5Tree::descendants( $block, $path )
		);

		foreach ( $all as $item ) {
			if ( 'divi/post-content' === ( $item['block']['blockName'] ?? null ) ) {
				$out[] = array(
					'path' => $item['path'],
					'type' => 'divi/post-content',
				);
			}
		}

		return $out;
	}

	/**
	 * Avisos de la edición: un cuerpo del Theme Builder que se queda sin `divi/post-content`.
	 *
	 * @param \WP_Post                          $source  Contenido sobre el que se trabaja.
	 * @param array<int, array<string, string>> $lost    Lo que se pierde ({@see self::dropped_post_content()}).
	 * @param string                            $content Contenido nuevo.
	 *
	 * @return array<int, string>
	 */
	private function post_content_warnings( \WP_Post $source, array $lost, string $content ): array {
		if ( array() === $lost || 'body' !== DiviLayoutGuard::slot_of( $source ) || DiviLayoutGuard::has_post_content( $content ) ) {
			return array();
		}

		return array(
			__( 'Este cuerpo del Theme Builder se quedó sin divi/post-content: asignado a páginas, entradas o la tienda REEMPLAZA su contenido (se pierden el texto de la página y, en WooCommerce, el carrito y el pago). Vuelve a insertar un divi/post-content en una columna antes de asignarlo.', 'nexo-mcp' ),
		);
	}

	/**
	 * Condiciones de visualización del nodo y de sus descendientes, que replace/delete pierden (S7): `{path, type, summary}` en orden de documento.
	 *
	 * Replace no puede conservarlas: el árbol nuevo no las admite (S9), así que todas se pierden; se avisa en vez de callar.
	 *
	 * @param array<string, mixed> $block Bloque que se reemplaza o borra.
	 * @param string               $path  Su ruta.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dropped( array $block, string $path ): array {
		$schema = $this->conditions;
		$out    = array();
		$all    = array_merge(
			array(
				array(
					'path'  => $path,
					'block' => $block,
				),
			),
			Divi5Tree::descendants( $block, $path )
		);

		foreach ( $all as $item ) {
			$stored = Divi5Tree::conditions_of( $item['block'] );

			if ( null !== $stored ) {
				$out[] = array(
					'path'    => $item['path'],
					'type'    => (string) ( $item['block']['blockName'] ?? '' ),
					'summary' => $schema->summary( $stored ),
				);
			}
		}

		return $out;
	}

	/**
	 * Comprueba operación, posición y presencia de `sections`.
	 *
	 * @param string $operation Operación.
	 * @param string $position  Posición.
	 * @param mixed  $sections  Subárbol nuevo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_shape( string $operation, string $position, $sections ) {
		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_operation_invalid',
				__( 'La operación debe ser replace, insert o delete.', 'nexo-mcp' )
			);
		}

		if ( 'insert' === $operation && ! in_array( $position, self::POSITIONS, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_position_invalid',
				__( 'Con insert indica position: before, after o inside.', 'nexo-mcp' )
			);
		}

		if ( 'insert' !== $operation && '' !== $position ) {
			return new \WP_Error(
				'nexo_mcp_divi_position_invalid',
				__( 'position sólo se usa con insert.', 'nexo-mcp' )
			);
		}

		if ( 'delete' === $operation && null !== $sections ) {
			return new \WP_Error(
				'nexo_mcp_divi_sections_not_allowed',
				__( 'delete no lleva sections.', 'nexo-mcp' )
			);
		}

		return true;
	}
}
