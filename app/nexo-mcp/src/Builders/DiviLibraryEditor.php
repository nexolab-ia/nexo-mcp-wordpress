<?php
/**
 * Herramientas de la biblioteca de Divi 5: listar, leer, guardar (crear y actualizar), borrar y restaurar ítems.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\PostLock;

/**
 * Lógica de `list-divi-library`, `get-divi-library-item`, `save-divi-library-item`, `delete-divi-library-item` y
 * `restore-divi-library-item` (T9 de `odd/tasks/divi-theme-builder.md`; decisiones S5 y S7, D10 de L2).
 *
 * El orden es siempre: permiso de la biblioteca ({@see PermissionPolicy::can_write_divi_library()}, con el id del ítem si
 * lo hay y ANTES de mirar el resto de la entrada), Divi 5 activo, forma de la entrada ({@see DiviLibrarySchema}) y, para lo
 * que reescribe, el bloqueo del ítem, el respaldo y la escritura.
 *
 * Un ítem en la papelera (`trash`) o en borrador automático (`auto-draft`) no se lee ni se toca (aviso 4 de L13); sólo
 * `restore-divi-library-item` saca uno de la papelera. Los ítems GLOBALES se pueden leer y crear, pero no actualizar, borrar
 * ni restaurar: cambiarlos cambia todas las páginas que los usan y eso es T10 (`confirm_global`).
 */
final class DiviLibraryEditor {

	public const CODE_DENIED = 'nexo_mcp_divi_library_denied';

	/**
	 * Meta con la marca única que lleva el ítem mientras se crea, para encontrarlo si el creador lanza.
	 */
	public const MARKER_META = '_nexo_mcp_library_creating';

	/**
	 * Tope de ítems que se miran al listar (después se filtra y se corta al límite pedido).
	 */
	private const SCAN = 300;

	/**
	 * Esquema.
	 *
	 * @var DiviLibrarySchema
	 */
	private DiviLibrarySchema $schema;

	/**
	 * Respaldos.
	 *
	 * @var DiviLibraryBackup
	 */
	private DiviLibraryBackup $backup;

	/**
	 * Validador del árbol (el de Divi 5 de siempre, sin los módulos de layout del Theme Builder).
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Permiso de la biblioteca: fn( ?int ): bool.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Bloqueo de presets: fn( callable ): mixed.
	 *
	 * @var callable
	 */
	private $presets_lock;

	/**
	 * Versión de Divi para `builderVersion`: fn(): string.
	 *
	 * @var callable
	 */
	private $version;

	/**
	 * Alta del ítem: fn( string $title, string $content, array $meta, array $terms, array $categories ): int|\WP_Error|false.
	 *
	 * @var callable
	 */
	private $creator;

	/**
	 * Búsqueda de lo creado por la marca: fn( string $meta_key, string $marker ): array<int, int>.
	 *
	 * @var callable
	 */
	private $finder;

	/**
	 * Borrado de un ítem creado a medias: fn( int $id ): mixed (falsy si falla).
	 *
	 * @var callable
	 */
	private $deleter;

	/**
	 * Construye el editor.
	 *
	 * @param DiviLibrarySchema $schema       Esquema.
	 * @param DiviLibraryBackup $backup       Respaldos.
	 * @param DiviTreeValidator $validator    Validador del árbol.
	 * @param callable          $allowed      fn( ?int ): bool. `PermissionPolicy::can_write_divi_library()`.
	 * @param callable|null     $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null     $presets_lock fn( callable ): mixed. Por omisión, el bloqueo de {@see DiviDesignStore::LOCK_ID}.
	 * @param callable|null     $version      fn(): string. Por omisión, la versión de Divi instalada.
	 * @param callable|null     $creator      fn( string, string, array, array, array ): int|\WP_Error|false. Por omisión, `et_pb_create_layout()` si existe y si no `wp_insert_post()`.
	 * @param callable|null     $finder       fn( string, string ): array<int, int>. Por omisión, `get_posts()` por la marca.
	 * @param callable|null     $deleter      fn( int ): mixed. Por omisión, `wp_delete_post( $id, true )`.
	 */
	public function __construct(
		DiviLibrarySchema $schema,
		DiviLibraryBackup $backup,
		DiviTreeValidator $validator,
		callable $allowed,
		?callable $divi5_active = null,
		?callable $presets_lock = null,
		?callable $version = null,
		?callable $creator = null,
		?callable $finder = null,
		?callable $deleter = null
	) {
		$this->schema       = $schema;
		$this->backup       = $backup;
		$this->validator    = $validator;
		$this->allowed      = $allowed;
		$this->divi5_active = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->presets_lock = $presets_lock ?? static fn( callable $work ) => PostLock::run( DiviDesignStore::LOCK_ID, $work );
		$this->version      = $version ?? array( Divi5BlockBuilder::class, 'installed_version' );
		$this->creator      = $creator ?? array( self::class, 'create_with_divi' );
		$this->finder       = $finder ?? array( self::class, 'find_by_marker' );
		$this->deleter      = $deleter ?? static fn( int $id ) => wp_delete_post( $id, true );
	}

	/**
	 * Lista los ítems de la biblioteca (sólo lee).
	 *
	 * @param array<string, mixed> $input Filtros: `type`, `scope`, `category`, `search`, `limit`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_items( array $input ) {
		$gate = $this->gate( null );

		if ( null !== $gate ) {
			return $gate;
		}

		$filters = $this->schema->parse_filters( $input );

		if ( $filters instanceof \WP_Error ) {
			return $filters;
		}

		$args = array(
			'post_type'      => DiviLibrarySchema::POST_TYPE,
			'post_status'    => DiviLibrarySchema::LIVE_STATUSES,
			'posts_per_page' => self::SCAN,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}

		// Sólo ayuda a la consulta; abajo se vuelve a filtrar. Sin `non_global`: un ítem sin término de alcance cuenta como no global.
		foreach ( array(
			'layout_type'     => array( 'slug', $filters['type'] ),
			'scope'           => array( 'slug', 'global' === $filters['scope'] ? 'global' : '' ),
			'layout_category' => array( 'name', $filters['category'] ),
		) as $taxonomy => $match ) {
			if ( '' !== $match[1] ) {
				$args['tax_query'][] = array(
					'taxonomy' => $taxonomy,
					'field'    => $match[0],
					'terms'    => array( $match[1] ),
				);
			}
		}

		$posts   = function_exists( 'get_posts' ) ? get_posts( $args ) : array();
		$matches = array();

		foreach ( $posts as $post ) {
			if ( DiviLibrarySchema::POST_TYPE === $post->post_type ) {
				$summary = $this->schema->summary( $post );

				if ( $this->matches( $summary, $filters ) ) {
					$matches[] = $summary;
				}
			}
		}

		return array(
			'items'     => array_slice( $matches, 0, $filters['limit'] ),
			'count'     => min( count( $matches ), $filters['limit'] ),
			'truncated' => count( $matches ) > $filters['limit'] || count( $posts ) >= self::SCAN,
			'limits'    => array(
				'max_list'       => DiviLibrarySchema::MAX_LIST,
				'max_title'      => DiviLibrarySchema::MAX_TITLE,
				'max_categories' => DiviLibrarySchema::MAX_CATEGORIES,
			),
		);
	}

	/**
	 * Lee un ítem con la lista plana de sus nodos (ruta, tipo y huella), sin el contenido crudo.
	 *
	 * @param array<string, mixed> $input Parámetros: `id`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_item( array $input ) {
		$id   = $this->id_of( $input );
		$gate = $this->gate( $id );

		if ( null !== $gate ) {
			return $gate;
		}

		if ( null === $id ) {
			return $this->schema->error( 'id', __( 'debe ser el id entero de un ítem de la biblioteca.', 'nexo-mcp' ) );
		}

		$post = $this->load( $id );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		return array_merge(
			$this->schema->summary( $post ),
			array(
				'global'  => $this->schema->is_global( $id ),
				'foreign' => $this->schema->foreign_types( (string) $post->post_content ),
				'backup'  => $this->backup->describe( $id ),
				'nodes'   => self::nodes_of( (string) $post->post_content ),
			)
		);
	}

	/**
	 * Crea un ítem nuevo o actualiza uno que existe.
	 *
	 * Sin `id` es un alta (`title`, `type` y `nodes` obligatorios, y permiso `save_library`). Con `id`, el ítem tiene que ser de
	 * la biblioteca, estar vivo y no ser global; se cambia lo que se pase (`title`, `nodes`, `categories`) y no se cambian
	 * `type` ni `scope`.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_item( array $input ) {
		$id   = $this->id_of( $input );
		$gate = $this->gate( $id );

		if ( null !== $gate ) {
			return $gate;
		}

		if ( array_key_exists( 'id', $input ) && null === $id ) {
			return $this->schema->error( 'id', __( 'debe ser el id entero de un ítem de la biblioteca.', 'nexo-mcp' ) );
		}

		return null === $id ? $this->create( $input ) : $this->update( $id, $input );
	}

	/**
	 * Manda un ítem a la papelera, con respaldo.
	 *
	 * @param array<string, mixed> $input Parámetros: `id`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_item( array $input ) {
		return $this->guarded( $input, false );
	}

	/**
	 * Devuelve un ítem al estado de su respaldo (también uno borrado, que está en la papelera).
	 *
	 * @param array<string, mixed> $input Parámetros: `id`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_item( array $input ) {
		return $this->guarded( $input, true );
	}

	/**
	 * Camino común de borrar y restaurar: permiso, Divi 5, id, ítem usable y no global, y el bloqueo del ítem.
	 *
	 * @param array<string, mixed> $input   Parámetros: `id`.
	 * @param bool                 $restore Restaurar (true) o borrar (false).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function guarded( array $input, bool $restore ) {
		$id   = $this->id_of( $input );
		$gate = $this->gate( $id );

		if ( null !== $gate ) {
			return $gate;
		}

		if ( null === $id ) {
			return $this->schema->error( 'id', __( 'debe ser el id entero de un ítem de la biblioteca.', 'nexo-mcp' ) );
		}

		$checked = $this->checked( $id, $restore );

		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		return PostLock::run(
			$id,
			function () use ( $id, $restore ) {
				$post = $this->checked( $id, $restore );

				return $post instanceof \WP_Error ? $post : ( $restore ? $this->restore_locked( $post ) : $this->delete_locked( $post ) );
			}
		);
	}

	/**
	 * El ítem, si se puede borrar o restaurar: existe, no es global y (si se borra) la papelera lo guarda.
	 *
	 * @param int  $id      Ítem.
	 * @param bool $restore Restaurar (acepta uno en la papelera) o borrar.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function checked( int $id, bool $restore ) {
		$post = $this->load( $id, $restore );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$editable = $this->editable( $id );

		if ( $editable instanceof \WP_Error ) {
			return $editable;
		}

		if ( ! $restore && defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_trash_disabled',
				__( 'La papelera está desactivada en este sitio (EMPTY_TRASH_DAYS es 0): borrar el ítem lo eliminaría para siempre y no se hace. Bórralo desde la biblioteca de Divi.', 'nexo-mcp' ),
				array( 'status' => 409 )
			);
		}

		return $post;
	}

	/**
	 * Borra con el bloqueo en mano: respaldo (sin él no se borra), comprobación del bloqueo y papelera.
	 *
	 * @param \WP_Post $post Ítem.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function delete_locked( \WP_Post $post ) {
		$id       = (int) $post->ID;
		$snapshot = $this->backup->snapshot( $post, 'delete', $this->schema->read( $id ) );
		$guard    = $this->back_up( $id, $snapshot );

		if ( null !== $guard ) {
			return $guard;
		}

		$trashed = wp_trash_post( $id );
		$now     = get_post( $id );

		if ( ! $trashed || ! $now instanceof \WP_Post || 'trash' !== $now->post_status ) {
			return $this->restore_state( $id, $snapshot, __( 'WordPress no mandó el ítem a la papelera.', 'nexo-mcp' ) );
		}

		$read = $this->schema->read( $id );

		return array(
			'id'       => $id,
			'deleted'  => true,
			'status'   => 'trash',
			'title'    => (string) $post->post_title,
			'type'     => $read['type'],
			'scope'    => $read['scope'],
			'backup'   => $this->backup->describe( $id ),
			'warnings' => array( __( 'El ítem está en la papelera de WordPress (se vacía sola a los pocos días). Restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' ) ),
		);
	}

	/**
	 * Restaura con el bloqueo en mano: respaldo del estado de ahora, comprobación del bloqueo y escritura.
	 *
	 * @param \WP_Post $post Ítem.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_locked( \WP_Post $post ) {
		$id   = (int) $post->ID;
		$from = $this->backup->read( $id );

		if ( null === $from ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_no_backup',
				__( 'Este ítem no tiene un respaldo disponible: no hay nada que restaurar (el respaldo lo guardan guardar y borrar antes de cambiarlo).', 'nexo-mcp' ),
				array( 'status' => 404 )
			);
		}

		$now   = $this->backup->snapshot( $post, 'restore', $this->schema->read( $id ) );
		$guard = $this->back_up( $id, $now );

		if ( null !== $guard ) {
			return $guard;
		}

		if ( 'trash' === $post->post_status && function_exists( 'wp_untrash_post' ) ) {
			wp_untrash_post( $id );
		}

		if ( ! $this->put_back( $id, $from ) ) {
			return $this->restore_state( $id, $now, __( 'WordPress no dejó el ítem como estaba en el respaldo.', 'nexo-mcp' ) );
		}

		$answer = $this->answer(
			$id,
			false,
			array(),
			array(
				'title'      => $now['title'],
				'categories' => $now['categories'],
				'status'     => $now['status'],
			),
			$this->backup->describe( $id )
		);

		return $answer instanceof \WP_Error ? $answer : array_merge(
			$answer,
			array(
				'restored'      => true,
				'restored_from' => array(
					'operation'   => $from['operation'],
					'captured_at' => $from['captured_at'],
				),
			)
		);
	}

	/**
	 * Guarda el respaldo y comprueba el bloqueo, justo antes de la primera escritura.
	 *
	 * @param int                  $id       Ítem.
	 * @param array<string, mixed> $snapshot Estado que se respalda.
	 */
	private function back_up( int $id, array $snapshot ): ?\WP_Error {
		if ( ! $this->backup->save( $id, $snapshot ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_backup_failed',
				__( 'No se pudo guardar el respaldo del ítem, así que no se cambió nada.', 'nexo-mcp' ),
				array( 'status' => 500 )
			);
		}

		$locked = PostLock::verify( $id );

		return is_wp_error( $locked ) ? $locked : null;
	}

	/**
	 * Alta de un ítem.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta (sin `id`).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create( array $input ) {
		$parsed = $this->schema->parse_save( $input );

		if ( $parsed instanceof \WP_Error ) {
			return $parsed;
		}

		$work = fn() => $this->create_unlocked( $parsed );

		return DiviTreeValidator::tree_has_preset( $parsed['nodes'] ) ? ( $this->presets_lock )( $work ) : $work();
	}

	/**
	 * Valida el árbol y crea el ítem (con el bloqueo de presets en mano si hace falta).
	 *
	 * @param array<string, mixed> $parsed Entrada de {@see DiviLibrarySchema::parse_save()}.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_unlocked( array $parsed ) {
		$type     = (string) $parsed['type'];
		$prepared = $this->prepare( $type, $parsed['nodes'] );

		if ( $prepared instanceof \WP_Error ) {
			return $prepared;
		}

		$scope      = (string) $parsed['scope'];
		$categories = (array) ( $parsed['categories'] ?? array() );
		$stored     = $this->schema->stored( $type, $scope, $prepared['module_type'] );
		$marker     = bin2hex( random_bytes( 16 ) );
		$meta       = array_merge( $stored['meta'], array( self::MARKER_META => $marker ) );
		$creator    = $this->creator;
		$title      = (string) $parsed['title'];

		try {
			$id = Divi5ContentWriter::without_kses( static fn() => $creator( $title, $prepared['content'], $meta, $stored['terms'], $categories ) );
		} catch ( \Throwable $e ) {
			return $this->rollback_found( $marker );
		}

		if ( $id instanceof \WP_Error ) {
			return $this->failed( $id->get_error_message() );
		}

		if ( ! is_int( $id ) || $id <= 0 ) {
			return $this->failed( __( 'WordPress no creó el ítem.', 'nexo-mcp' ) );
		}

		try {
			$post = get_post( $id );
			$read = $this->schema->read( $id );

			if ( ! $post instanceof \WP_Post
				|| $prepared['content'] !== $post->post_content
				|| 'publish' !== $post->post_status
				|| DiviLibrarySchema::POST_TYPE !== $post->post_type
				|| $type !== $read['type']
				|| $scope !== $read['scope']
				|| ! $this->same_names( $categories, $read['categories'] )
				|| ! $this->has_metas( $id, $stored['meta'] ) ) {
				return $this->rollback( $id, __( 'WordPress guardó un contenido, unos términos o unas metas distintos de los pedidos.', 'nexo-mcp' ) );
			}

			delete_post_meta( $id, self::MARKER_META );
		} catch ( \Throwable $e ) {
			return $this->rollback( $id, __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		$warnings = array();

		if ( 'global' === $scope ) {
			$warnings[] = __( 'Es un ítem global: cuando se use en páginas, cambiarlo cambia todas. El conector todavía no actualiza, borra ni restaura ítems globales: hazlo en el Visual Builder.', 'nexo-mcp' );
		}

		return $this->answer( $id, true, $warnings, null, null );
	}

	/**
	 * Actualiza un ítem: título, contenido y/o categorías.
	 *
	 * @param int                  $id    Ítem.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update( int $id, array $input ) {
		$post = $this->load( $id );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$current = $this->editable( $id );

		if ( $current instanceof \WP_Error ) {
			return $current;
		}

		$parsed = $this->schema->parse_save( $input, $current );

		if ( $parsed instanceof \WP_Error ) {
			return $parsed;
		}

		$has_nodes = array_key_exists( 'nodes', $input );
		$work      = fn() => $this->update_unlocked( $id, $parsed, $has_nodes );

		return $has_nodes && DiviTreeValidator::tree_has_preset( $parsed['nodes'] ) ? ( $this->presets_lock )( $work ) : $work();
	}

	/**
	 * Valida y, con el bloqueo del ítem, respalda y escribe.
	 *
	 * @param int                  $id        Ítem.
	 * @param array<string, mixed> $parsed    Entrada de {@see DiviLibrarySchema::parse_save()}.
	 * @param bool                 $has_nodes Si la entrada trae `nodes`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_unlocked( int $id, array $parsed, bool $has_nodes ) {
		$prepared = null;

		if ( $has_nodes ) {
			$prepared = $this->prepare( (string) $parsed['type'], $parsed['nodes'] );

			if ( $prepared instanceof \WP_Error ) {
				return $prepared;
			}
		}

		return PostLock::run( $id, fn() => $this->update_locked( $id, $parsed, $prepared ) );
	}

	/**
	 * Escribe con el bloqueo en mano: respaldo (sin él no se escribe), comprobación del bloqueo y cambio.
	 *
	 * @param int                                                   $id       Ítem.
	 * @param array<string, mixed>                                  $parsed   Entrada validada.
	 * @param array{content: string, module_type: string|null}|null $prepared Contenido nuevo, si lo hay.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_locked( int $id, array $parsed, ?array $prepared ) {
		$post = $this->load( $id );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$current = $this->editable( $id );

		if ( $current instanceof \WP_Error ) {
			return $current;
		}

		$foreign = $prepared ? $this->schema->foreign_types( (string) $post->post_content ) : array();

		if ( array() !== $foreign && ! $parsed['replace_foreign'] ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_foreign',
				sprintf(
					/* translators: %s: tipos de bloque. */
					__( 'El ítem tiene contenido que el conector no sabe representar (%s): reemplazarlo lo pierde. Pasa replace_foreign: true para reemplazarlo (queda en el respaldo) o cámbialo en el Visual Builder.', 'nexo-mcp' ),
					implode( ', ', $foreign )
				),
				array(
					'status'  => 409,
					'foreign' => $foreign,
				)
			);
		}

		$read     = $this->schema->read( $id );
		$snapshot = $this->backup->snapshot( $post, 'save', $read );

		$guard = $this->back_up( $id, $snapshot );

		if ( null !== $guard ) {
			return $guard;
		}

		$failure = $this->apply( $id, $parsed, $prepared );

		if ( null !== $failure ) {
			return $this->restore_state( $id, $snapshot, $failure );
		}

		$warnings = array();

		if ( array() !== $foreign ) {
			$warnings[] = __( 'Se reemplazó contenido que el conector no sabe representar; sigue en el respaldo.', 'nexo-mcp' );
		}

		$previous = array(
			'title'      => $snapshot['title'],
			'categories' => $snapshot['categories'],
		);

		return $this->answer( $id, false, $warnings, $previous, $this->backup->describe( $id ) );
	}

	/**
	 * Aplica el cambio y comprueba lo guardado. Devuelve el motivo si falla, o null.
	 *
	 * @param int                                                   $id       Ítem.
	 * @param array<string, mixed>                                  $parsed   Entrada validada.
	 * @param array{content: string, module_type: string|null}|null $prepared Contenido nuevo, si lo hay.
	 */
	private function apply( int $id, array $parsed, ?array $prepared ): ?string {
		$data = array( 'ID' => $id );

		if ( null !== $parsed['title'] ) {
			$data['post_title'] = wp_slash( (string) $parsed['title'] );
		}

		if ( null !== $prepared ) {
			$data['post_content'] = wp_slash( $prepared['content'] );
		}

		try {
			if ( count( $data ) > 1 ) {
				$saved = Divi5ContentWriter::without_kses( static fn() => wp_update_post( $data, true ) );

				if ( is_wp_error( $saved ) || ! $saved ) {
					return is_wp_error( $saved ) ? $saved->get_error_message() : __( 'WordPress no guardó el ítem.', 'nexo-mcp' );
				}
			}

			if ( null !== $parsed['categories'] ) {
				$terms = wp_set_object_terms( $id, $parsed['categories'], 'layout_category' );

				if ( is_wp_error( $terms ) ) {
					return $terms->get_error_message();
				}
			}

			if ( null !== $prepared && null !== $prepared['module_type'] ) {
				update_post_meta( $id, '_et_pb_module_type', $prepared['module_type'] );
			}

			$post = get_post( $id );
			$read = $this->schema->read( $id );

			if ( ! $post instanceof \WP_Post
				|| ( null !== $parsed['title'] && $parsed['title'] !== $post->post_title )
				|| ( null !== $prepared && $prepared['content'] !== $post->post_content )
				|| ( null !== $parsed['categories'] && ! $this->same_names( $parsed['categories'], $read['categories'] ) ) ) {
				return __( 'WordPress guardó un contenido o unos términos distintos de los pedidos.', 'nexo-mcp' );
			}
		} catch ( \Throwable $e ) {
			return __( 'error interno al guardar.', 'nexo-mcp' );
		}

		return null;
	}

	/**
	 * Devuelve el ítem a su respaldo tras una escritura fallida y devuelve el error.
	 *
	 * @param int                  $id       Ítem.
	 * @param array<string, mixed> $snapshot Estado de antes.
	 * @param string               $reason   Motivo.
	 */
	private function restore_state( int $id, array $snapshot, string $reason ): \WP_Error {
		$restored = $this->put_back( $id, $snapshot );

		return new \WP_Error(
			'nexo_mcp_divi_library_save_failed',
			sprintf(
				/* translators: 1: motivo, 2: resultado. */
				__( 'No se pudo guardar el ítem de la biblioteca: %1$s %2$s', 'nexo-mcp' ),
				$reason,
				$restored ? __( 'El ítem volvió a su estado anterior.', 'nexo-mcp' ) : __( 'No se pudo volver al estado anterior automáticamente: restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' )
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Pone un estado guardado (título, contenido, estado, categorías, módulo) y comprueba que quedó.
	 *
	 * @param int                  $id       Ítem.
	 * @param array<string, mixed> $snapshot Estado de {@see DiviLibraryBackup::snapshot()}.
	 */
	private function put_back( int $id, array $snapshot ): bool {
		try {
			Divi5ContentWriter::without_kses(
				static fn() => wp_update_post(
					array(
						'ID'           => $id,
						'post_title'   => wp_slash( (string) $snapshot['title'] ),
						'post_content' => wp_slash( (string) $snapshot['content'] ),
						'post_status'  => (string) $snapshot['status'],
					),
					true
				)
			);
			wp_set_object_terms( $id, (array) $snapshot['categories'], 'layout_category' );

			if ( '' !== (string) $snapshot['module_type'] ) {
				update_post_meta( $id, '_et_pb_module_type', (string) $snapshot['module_type'] );
			}

			$post = get_post( $id );

			return $post instanceof \WP_Post
				&& $post->post_title === $snapshot['title']
				&& $post->post_content === $snapshot['content']
				&& $post->post_status === $snapshot['status']
				&& $this->same_names( (array) $snapshot['categories'], $this->schema->read( $id )['categories'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Tipo y alcance de un ítem que se puede cambiar: reconocido (tiene tipo) y no global.
	 *
	 * @param int $id Ítem.
	 *
	 * @return array{type: string, scope: string}|\WP_Error
	 */
	private function editable( int $id ) {
		if ( $this->schema->is_global( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_global_blocked',
				__( 'Este ítem es global: cambiarlo, borrarlo o restaurarlo cambia todas las páginas que lo usan y el conector todavía no lo hace. Edítalo en el Visual Builder de Divi.', 'nexo-mcp' ),
				array( 'status' => 409 )
			);
		}

		$read = $this->schema->read( $id );

		if ( '' === $read['type'] ) {
			return $this->not_found( $id );
		}

		return array(
			'type'  => $read['type'],
			'scope' => $read['scope'],
		);
	}

	/**
	 * Valida el árbol con el validador de Divi 5, comprueba su forma y lo serializa bajo un `divi/placeholder`.
	 *
	 * @param string $type  Tipo del ítem.
	 * @param mixed  $nodes Nodos recibidos.
	 *
	 * @return array{content: string, module_type: string|null}|\WP_Error
	 */
	private function prepare( string $type, $nodes ) {
		$clean = $this->validator->validate_tree( $nodes, DiviLibrarySchema::root_parent( $type ) );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$shape = $this->schema->check_shape( $type, $clean );

		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}

		$module = 'module' === $type ? DiviLibrarySchema::module_type( $clean ) : null;

		if ( 'module' === $type && null === $module ) {
			return $this->schema->error( 'nodes', __( 'el módulo no está en el catálogo.', 'nexo-mcp' ) );
		}

		$blocks = Divi5BlockBuilder::build(
			array(
				array(
					'type'     => 'divi/placeholder',
					'children' => $clean,
				),
			),
			(string) ( $this->version )()
		);

		return array(
			'content'     => serialize_blocks( $blocks ),
			'module_type' => $module,
		);
	}

	/**
	 * Respuesta de guardar.
	 *
	 * @param int                        $id       Ítem.
	 * @param bool                       $created  Si se creó.
	 * @param array<int, string>         $warnings Avisos.
	 * @param array<string, mixed>|null  $previous Lo que había antes (null al crear).
	 * @param array<string, string>|null $backup  Respaldo (null al crear).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function answer( int $id, bool $created, array $warnings, ?array $previous, ?array $backup ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->failed( __( 'el ítem ya no existe.', 'nexo-mcp' ) );
		}

		return array_merge(
			$this->schema->summary( $post ),
			array(
				'created'  => $created,
				'nodes'    => self::nodes_of( (string) $post->post_content ),
				'previous' => $previous,
				'backup'   => $backup,
				'warnings' => $warnings,
			)
		);
	}

	/**
	 * El creador lanzó: si llegó a crear el ítem (se encuentra por su marca), se borra.
	 *
	 * @param string $marker Marca única de esta llamada.
	 */
	private function rollback_found( string $marker ): \WP_Error {
		$reason = __( 'error interno al guardar.', 'nexo-mcp' );

		try {
			$ids = ( $this->finder )( self::MARKER_META, $marker );
		} catch ( \Throwable $e ) {
			$ids = null;
		}

		if ( null === $ids || array() === $ids ) {
			return $this->failed( $reason . ' ' . __( 'Si WordPress llegó a crear el ítem, puede haber quedado uno publicado: búscalo en la biblioteca de Divi y bórralo si no lo reconoces.', 'nexo-mcp' ) );
		}

		$stuck = array();

		foreach ( $ids as $id ) {
			if ( ! $this->delete_created( (int) $id ) ) {
				$stuck[] = (int) $id;
			}
		}

		return array() === $stuck
			? $this->failed( $reason, true )
			: $this->failed( $reason . ' ' . sprintf( /* translators: %s: ids. */ __( 'El ítem %s quedó creado y no se pudo borrar: revísalo o bórralo desde la biblioteca de Divi.', 'nexo-mcp' ), implode( ', ', $stuck ) ) );
	}

	/**
	 * Borra el ítem creado y devuelve el error.
	 *
	 * @param int    $id     Ítem creado por esta llamada.
	 * @param string $reason Motivo.
	 */
	private function rollback( int $id, string $reason ): \WP_Error {
		return $this->delete_created( $id )
			? $this->failed( $reason, true )
			: $this->failed( $reason . ' ' . sprintf( /* translators: %d: id. */ __( 'El ítem %d quedó creado y no se pudo borrar: revísalo o bórralo desde la biblioteca de Divi.', 'nexo-mcp' ), $id ) );
	}

	/**
	 * Borra un ítem creado por esta llamada; false si sigue existiendo.
	 *
	 * @param int $id Ítem.
	 */
	private function delete_created( int $id ): bool {
		return (bool) ( $this->deleter )( $id ) || ! get_post( $id ) instanceof \WP_Post;
	}

	/**
	 * Error de guardado.
	 *
	 * @param string $reason  Motivo.
	 * @param bool   $deleted Si había un ítem a medias y se eliminó.
	 */
	private function failed( string $reason, bool $deleted = false ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_library_save_failed',
			sprintf(
				/* translators: %s: motivo. */
				__( 'No se pudo guardar el ítem de la biblioteca: %s', 'nexo-mcp' ),
				$reason
			) . ( $deleted ? ' ' . __( 'El ítem creado se eliminó.', 'nexo-mcp' ) : '' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Lista plana `{path, type, fingerprint}` de todos los nodos de un contenido.
	 *
	 * @param string $content `post_content`.
	 *
	 * @return array<int, array{path: string, type: string, fingerprint: string}>
	 */
	private static function nodes_of( string $content ): array {
		$nodes = array();

		foreach ( Divi5Tree::build( $content ) as $node ) {
			$nodes = array_merge( $nodes, Divi5Tree::summarize( $node ) );
		}

		return $nodes;
	}

	/**
	 * Si el ítem tiene todas esas metas con esos valores.
	 *
	 * @param int                  $id    Ítem.
	 * @param array<string,string> $metas Metas esperadas.
	 */
	private function has_metas( int $id, array $metas ): bool {
		foreach ( $metas as $key => $value ) {
			if ( (string) get_post_meta( $id, $key, true ) !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mismos nombres sin importar el orden.
	 *
	 * @param array<int, string> $a Nombres.
	 * @param array<int, string> $b Nombres.
	 */
	private function same_names( array $a, array $b ): bool {
		sort( $a );
		sort( $b );

		return $a === $b;
	}

	/**
	 * Alta por defecto: `et_pb_create_layout()` de Divi si existe (como el Visual Builder) y si no `wp_insert_post()`.
	 *
	 * @param string                           $title      Título.
	 * @param string                           $content    Contenido.
	 * @param array<string, string>            $meta       Metas.
	 * @param array<string, array<int,string>> $terms      Términos por taxonomía.
	 * @param array<int, string>               $categories Categorías por nombre.
	 *
	 * @return int|\WP_Error|false
	 */
	public static function create_with_divi( string $title, string $content, array $meta, array $terms, array $categories ) {
		if ( function_exists( 'et_pb_create_layout' ) ) {
			$id = et_pb_create_layout( wp_slash( $title ), wp_slash( $content ), $meta, $terms, implode( ',', $categories ), '', 'publish' );

			return is_numeric( $id ) ? (int) $id : false;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => DiviLibrarySchema::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( $content ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		foreach ( array_merge( $terms, array( 'layout_category' => $categories ) ) as $taxonomy => $names ) {
			if ( array() !== $names ) {
				wp_set_object_terms( $id, $names, $taxonomy );
			}
		}

		return (int) $id;
	}

	/**
	 * Busca por la marca los ítems que dejó un alta que lanzó.
	 *
	 * @param string $key    Meta con la marca.
	 * @param string $marker Marca.
	 *
	 * @return array<int, int>
	 */
	public static function find_by_marker( string $key, string $marker ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => DiviLibrarySchema::POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $marker, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Permiso (con el id del ítem si lo hay) y Divi 5, en ese orden.
	 *
	 * @param int|null $id Ítem, o null (alta, lista o entrada sin id válido).
	 */
	private function gate( ?int $id ): ?\WP_Error {
		if ( ! ( $this->allowed )( $id ) ) {
			return new \WP_Error(
				self::CODE_DENIED,
				__( 'No tienes permiso para usar la biblioteca de Divi 5 con este conector. Hace falta el scope mcp:divi_library (con su interruptor encendido por el administrador) y los permisos de biblioteca de Divi.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		return ( $this->divi5_active )() ? null : Divi5ContentWriter::required_error();
	}

	/**
	 * Id del ítem de la entrada: un entero positivo, o null.
	 *
	 * @param array<string, mixed> $input Entrada.
	 */
	private function id_of( array $input ): ?int {
		return isset( $input['id'] ) && is_int( $input['id'] ) && $input['id'] > 0 ? $input['id'] : null;
	}

	/**
	 * Carga un ítem vivo: existe, es de la biblioteca y no está en la papelera ni en borrador automático.
	 *
	 * @param int  $id            Ítem.
	 * @param bool $allow_trashed Si un ítem en la papelera también vale (sólo restaurar).
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function load( int $id, bool $allow_trashed = false ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || DiviLibrarySchema::POST_TYPE !== $post->post_type ) {
			return $this->not_found( $id );
		}

		if ( 'trash' === $post->post_status && ! $allow_trashed ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_trashed',
				__( 'El ítem está en la papelera: no se lee ni se cambia. Restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' ),
				array( 'status' => 410 )
			);
		}

		if ( 'trash' !== $post->post_status && ! in_array( $post->post_status, DiviLibrarySchema::LIVE_STATUSES, true ) ) {
			return $this->not_found( $id );
		}

		return $post;
	}

	/**
	 * Error de ítem que no existe o no es usable.
	 *
	 * @param int $id Ítem.
	 */
	private function not_found( int $id ): \WP_Error {
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

	/**
	 * Si un resumen cumple los filtros del listado.
	 *
	 * @param array<string, mixed> $summary Resumen de {@see DiviLibrarySchema::summary()}.
	 * @param array<string, mixed> $filters Filtros de {@see DiviLibrarySchema::parse_filters()}.
	 */
	private function matches( array $summary, array $filters ): bool {
		if ( '' !== $filters['type'] && $summary['type'] !== $filters['type'] ) {
			return false;
		}

		if ( '' !== $filters['scope'] && $summary['scope'] !== $filters['scope'] ) {
			return false;
		}

		if ( '' !== $filters['search'] && false === stripos( (string) $summary['title'], (string) $filters['search'] ) ) {
			return false;
		}

		if ( '' === $filters['category'] ) {
			return true;
		}

		foreach ( (array) $summary['categories'] as $name ) {
			if ( 0 === strcasecmp( (string) $name, (string) $filters['category'] ) ) {
				return true;
			}
		}

		return false;
	}
}
