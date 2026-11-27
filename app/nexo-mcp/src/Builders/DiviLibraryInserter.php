<?php
/**
 * Inserta un ítem de la biblioteca de Divi 5 en una página: copia de su contenido o referencia global.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lógica de `insert-divi-library-item` (T10 de `odd/tasks/divi-theme-builder.md`; S1, S3, S5 y S7, D10 de L2).
 *
 * Orden: permiso de la biblioteca con el id del ítem ({@see PermissionPolicy::can_write_divi_library()}, ANTES de mirar la
 * entrada), Divi 5 activo, forma de la entrada, ítem usable, modo y contenido, página (el permiso de constructores sobre el
 * original lo resuelve quien llama) y, por último, la escritura, que es la de siempre de las páginas: `Divi5SectionEditor`
 * con operación `insert`, en la copia de trabajo, con respaldo y bloqueo de la página. Esta clase no escribe nada.
 *
 * - `copy`: el contenido del ítem se convierte a nodos y pasa por el validador de Divi 5 como cualquier subárbol. Un ítem con
 *   módulos que el conector no sabe representar (hechos en el Visual Builder) o con atributos que el catálogo no admite no
 *   se copia (nunca se pega contenido sin validar).
 * - `global`: se escribe `<!-- wp:divi/global-layout {"globalModule":"<id>","blockName":"<bloque>"} /-->`, la forma de Divi 5.19.1
 *   (L2/L4), sin `localAttrs`. Sólo para un ítem global publicado de tipo sección, fila o módulo.
 */
final class DiviLibraryInserter {

	public const MODES = array( 'copy', 'global' );

	private const KEYS = array( 'id', 'post_id', 'path', 'fingerprint', 'position', 'mode', 'draft' );

	/**
	 * Errores del validador que, al copiar, vienen del CONTENIDO del ítem y no de lo que pidió quien llama.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_ERRORS = array( 'nexo_mcp_divi_attr_denied', 'nexo_mcp_divi_value_invalid', 'nexo_mcp_divi_module_denied', 'nexo_mcp_divi_tree_invalid', 'nexo_mcp_divi_too_large', 'nexo_mcp_divi_too_many_nodes' );

	/**
	 * Esquema de la biblioteca.
	 *
	 * @var DiviLibrarySchema
	 */
	private DiviLibrarySchema $schema;

	/**
	 * Permiso de la biblioteca: fn( ?int ): bool.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * Si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Página (o su copia) con el permiso de constructores resuelto: fn( int ): \WP_Post|\WP_Error.
	 *
	 * @var callable
	 */
	private $resolve_page;

	/**
	 * Escritura de la página: fn( \WP_Post $page, array $edit, bool $global_refs ): array|\WP_Error (`Divi5SectionEditor::edit()`).
	 *
	 * @var callable
	 */
	private $edit;

	/**
	 * Construye la herramienta.
	 *
	 * @param DiviLibrarySchema $schema       Esquema de la biblioteca.
	 * @param callable          $allowed      fn( ?int ): bool.
	 * @param callable          $divi5_active fn(): bool.
	 * @param callable          $resolve_page fn( int ): \WP_Post|\WP_Error.
	 * @param callable          $edit         fn( \WP_Post, array, bool ): array|\WP_Error.
	 */
	public function __construct( DiviLibrarySchema $schema, callable $allowed, callable $divi5_active, callable $resolve_page, callable $edit ) {
		$this->schema       = $schema;
		$this->allowed      = $allowed;
		$this->divi5_active = $divi5_active;
		$this->resolve_page = $resolve_page;
		$this->edit         = $edit;
	}

	/**
	 * Inserta el ítem.
	 *
	 * @param array<string, mixed> $input Parámetros: `id` (ítem), `post_id` (página), `path`, `fingerprint`, `position`, `mode`, `draft`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function insert( array $input ) {
		$item_id = isset( $input['id'] ) && is_int( $input['id'] ) && $input['id'] > 0 ? $input['id'] : null;

		if ( ! ( $this->allowed )( $item_id ) ) {
			return new \WP_Error(
				DiviLibraryEditor::CODE_DENIED,
				__( 'No tienes permiso para usar la biblioteca de Divi 5 con este conector. Hace falta el scope mcp:divi_library (con su interruptor encendido por el administrador) y los permisos de biblioteca de Divi.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		if ( ! ( $this->divi5_active )() ) {
			return Divi5ContentWriter::required_error();
		}

		$parsed = $this->parse( $input );

		if ( $parsed instanceof \WP_Error ) {
			return $parsed;
		}

		$item = $this->load( $parsed['id'] );

		if ( $item instanceof \WP_Error ) {
			return $item;
		}

		$nodes = 'global' === $parsed['mode'] ? $this->reference( $item ) : $this->copy( $item );

		if ( $nodes instanceof \WP_Error ) {
			return $nodes;
		}

		$page = ( $this->resolve_page )( $parsed['post_id'] );

		if ( $page instanceof \WP_Error ) {
			return $page;
		}

		$result = ( $this->edit )(
			$page,
			array(
				'operation'   => 'insert',
				'path'        => $parsed['path'],
				'fingerprint' => $parsed['fingerprint'],
				'position'    => $parsed['position'],
				'sections'    => $nodes,
				'draft'       => $parsed['draft'],
			),
			'global' === $parsed['mode']
		);

		if ( $result instanceof \WP_Error ) {
			return $this->explain( $result, $parsed['mode'] );
		}

		$read = $this->schema->read( $item->ID );

		return array_merge(
			$result,
			array(
				'mode'     => $parsed['mode'],
				'item'     => array(
					'id'    => (int) $item->ID,
					'title' => (string) $item->post_title,
					'type'  => $read['type'],
					'scope' => $read['scope'],
				),
				'warnings' => array_merge( (array) ( $result['warnings'] ?? array() ), $this->warnings( $parsed['mode'], $read['scope'] ) ),
			)
		);
	}

	/**
	 * Valida la forma de la entrada.
	 *
	 * @param array<string, mixed> $input Entrada.
	 *
	 * @return array{id: int, post_id: int, path: string, fingerprint: string, position: string, mode: string, draft: bool}|\WP_Error
	 */
	private function parse( array $input ) {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				return $this->schema->error( (string) $key, __( 'no es un campo conocido.', 'nexo-mcp' ) );
			}
		}

		foreach ( array( 'id', 'post_id' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_int( $input[ $key ] ) || $input[ $key ] <= 0 ) {
				return $this->schema->error( $key, __( 'debe ser un id entero positivo.', 'nexo-mcp' ) );
			}
		}

		foreach ( array(
			'path'        => 64,
			'fingerprint' => 128,
		) as $key => $max ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || '' === $input[ $key ] || strlen( $input[ $key ] ) > $max ) {
				return $this->schema->error( $key, __( 'es obligatorio (texto tal como lo devolvió nexo-mcp-get-page-structure).', 'nexo-mcp' ) );
			}
		}

		if ( ! isset( $input['position'] ) || ! is_string( $input['position'] ) || ! in_array( $input['position'], Divi5SectionEditor::POSITIONS, true ) ) {
			return $this->schema->error( 'position', __( 'debe ser before, after o inside.', 'nexo-mcp' ) );
		}

		$mode = $input['mode'] ?? 'copy';

		if ( ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
			return $this->schema->error( 'mode', __( 'debe ser copy o global.', 'nexo-mcp' ) );
		}

		$draft = $input['draft'] ?? false;

		if ( ! is_bool( $draft ) ) {
			return $this->schema->error( 'draft', __( 'debe ser true o false.', 'nexo-mcp' ) );
		}

		return array(
			'id'          => $input['id'],
			'post_id'     => $input['post_id'],
			'path'        => $input['path'],
			'fingerprint' => $input['fingerprint'],
			'position'    => $input['position'],
			'mode'        => $mode,
			'draft'       => $draft,
		);
	}

	/**
	 * El ítem, si es de la biblioteca y está vivo.
	 *
	 * @param int $id Ítem.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function load( int $id ) {
		$post = get_post( $id );

		if ( $post instanceof \WP_Post && DiviLibrarySchema::POST_TYPE === $post->post_type && 'trash' === $post->post_status ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_trashed',
				__( 'El ítem está en la papelera: no se inserta. Restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' ),
				array( 'status' => 410 )
			);
		}

		if ( ! $post instanceof \WP_Post || DiviLibrarySchema::POST_TYPE !== $post->post_type || ! in_array( $post->post_status, DiviLibrarySchema::LIVE_STATUSES, true ) || '' === $this->schema->read( $id )['type'] ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_not_found',
				sprintf(
					/* translators: %d: id. */
					__( 'El id %d no es un ítem de la biblioteca de Divi 5 disponible.', 'nexo-mcp' ),
					$id
				),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * El nodo de referencia a un ítem global.
	 *
	 * @param \WP_Post $item Ítem.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function reference( \WP_Post $item ) {
		$block = $this->schema->global_block( (int) $item->ID );

		if ( null === $block ) {
			$read = $this->schema->read( (int) $item->ID );

			if ( 'global' !== $read['scope'] ) {
				$why = __( 'el ítem no es global: no hay nada que sincronizar. Usa mode copy.', 'nexo-mcp' );
			} elseif ( 'publish' !== $item->post_status ) {
				$why = __( 'el ítem global no está publicado y Divi no renderiza un global que no lo esté: publícalo antes (o usa mode copy).', 'nexo-mcp' );
			} elseif ( 'layout' === $read['type'] ) {
				$why = __( 'un layout global no se puede referenciar desde aquí (sólo secciones, filas y módulos): usa mode copy.', 'nexo-mcp' );
			} else {
				$why = __( 'el ítem no es una sección, una fila ni un módulo del catálogo.', 'nexo-mcp' );
			}

			return new \WP_Error(
				'nexo_mcp_divi_library_mode_invalid',
				/* translators: %s: motivo. */
				sprintf( __( 'No se puede insertar como referencia global: %s', 'nexo-mcp' ), $why ),
				array(
					'status' => 409,
					'path'   => 'mode',
				)
			);
		}

		return array(
			array(
				'type'  => DiviModuleCatalog::GLOBAL_REF,
				'attrs' => array(
					'globalModule' => (string) $item->ID,
					'blockName'    => $block,
				),
			),
		);
	}

	/**
	 * Los nodos de la copia: los hijos del `divi/placeholder` del ítem, sin `builderVersion` (se vuelve a poner al escribir).
	 *
	 * @param \WP_Post $item Ítem.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function copy( \WP_Post $item ) {
		$content = (string) $item->post_content;
		$foreign = $this->schema->foreign_types( $content );

		if ( array() !== $foreign ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_foreign',
				sprintf(
					/* translators: %s: tipos de bloque. */
					__( 'El ítem tiene contenido que el conector no sabe representar (%s): no se copia entero. Si es global, insértalo con mode global; si no, usa el Visual Builder.', 'nexo-mcp' ),
					implode( ', ', $foreign )
				),
				array(
					'status'  => 409,
					'foreign' => $foreign,
				)
			);
		}

		$blocks = parse_blocks( $content );

		foreach ( $blocks as $block ) {
			if ( 'divi/placeholder' === $block['blockName'] ) {
				$blocks = $block['innerBlocks'];
				break;
			}
		}

		$nodes = self::nodes_of( $blocks );

		if ( array() === $nodes ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_copy_invalid',
				__( 'El ítem no tiene contenido que copiar.', 'nexo-mcp' ),
				array(
					'status' => 422,
					'reason' => 'empty',
				)
			);
		}

		return $nodes;
	}

	/**
	 * Bloques a nodos `{type, attrs?, children?}`; sin `builderVersion` y sin los bloques de texto suelto.
	 *
	 * @param array<int, mixed> $blocks Bloques de `parse_blocks()`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function nodes_of( array $blocks ): array {
		$nodes = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
				continue;
			}

			$node  = array( 'type' => $block['blockName'] );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			unset( $attrs['builderVersion'] );

			if ( array() !== $attrs ) {
				$node['attrs'] = $attrs;
			}

			$children = self::nodes_of( (array) ( $block['innerBlocks'] ?? array() ) );

			if ( array() !== $children ) {
				$node['children'] = $children;
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Un error del validador sobre el contenido copiado dice que es el CONTENIDO DEL ÍTEM el que no pasa, no la petición.
	 *
	 * @param \WP_Error $error Error de la escritura.
	 * @param string    $mode  Modo.
	 */
	private function explain( \WP_Error $error, string $mode ): \WP_Error {
		if ( 'copy' !== $mode || ! in_array( $error->get_error_code(), self::CONTENT_ERRORS, true ) ) {
			return $error;
		}

		$data = $error->get_error_data();

		return new \WP_Error(
			'nexo_mcp_divi_library_copy_invalid',
			sprintf(
				/* translators: %s: motivo del validador. */
				__( 'El contenido del ítem no pasa el validador de Divi 5 del conector y no se copia (%s). Si es global, insértalo con mode global; si no, edítalo en el Visual Builder.', 'nexo-mcp' ),
				$error->get_error_message()
			),
			array(
				'status' => 422,
				'reason' => $error->get_error_code(),
				'path'   => is_array( $data ) ? ( $data['path'] ?? '' ) : '',
			)
		);
	}

	/**
	 * Avisos de la respuesta.
	 *
	 * @param string $mode  Modo.
	 * @param string $scope Alcance del ítem.
	 *
	 * @return array<int, string>
	 */
	private function warnings( string $mode, string $scope ): array {
		if ( 'global' === $mode ) {
			return array( __( 'La página usa el ítem global POR REFERENCIA: cada cambio del ítem cambia también esta página sin tocarla (nexo-mcp-save-divi-library-item con confirm_global lista las páginas afectadas) y, si el ítem se borra, Divi incrusta su contenido en la página. Un ítem global que deje de estar publicado deja de verse.', 'nexo-mcp' ) );
		}

		return 'global' === $scope ? array( __( 'Se insertó una COPIA del ítem global: no se sincroniza con él. Para usarlo por referencia, mode global.', 'nexo-mcp' ) ) : array();
	}
}
