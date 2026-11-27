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
 * `restore-divi-library-item` saca uno de la papelera. Los ítems GLOBALES se pueden leer y crear; actualizarlos, borrarlos o
 * restaurarlos cambia todas las páginas que los usan sin tocarlas (Divi lee el ítem al renderizar), así que exigen
 * `confirm_global: true` (T10, D10 de L2), devuelven las páginas afectadas (`affected_pages`) y dejan el respaldo de siempre.
 * Borrar un global despega antes sus instancias: lo hace Divi con su filtro `pre_trash_post`
 * (`GlobalLayout::preserve_instances_before_delete`, que incrusta el ítem en cada página); el conector sólo lo permite si Divi
 * lo va a hacer, si el usuario puede editar todas las páginas y lo comprueba después (decisión del punto 3 de T10).
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
	 * Dónde se usa un ítem global.
	 *
	 * @var DiviLibraryUsage
	 */
	private DiviLibraryUsage $usage;

	/**
	 * Si Divi despega las instancias al mandar un global a la papelera: fn(): bool.
	 *
	 * @var callable
	 */
	private $detaches;

	/**
	 * Construye el editor.
	 *
	 * @param DiviLibrarySchema     $schema       Esquema.
	 * @param DiviLibraryBackup     $backup       Respaldos.
	 * @param DiviTreeValidator     $validator    Validador del árbol.
	 * @param callable              $allowed      fn( ?int ): bool. `PermissionPolicy::can_write_divi_library()`.
	 * @param callable|null         $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null         $presets_lock fn( callable ): mixed. Por omisión, el bloqueo de {@see DiviDesignStore::LOCK_ID}.
	 * @param callable|null         $version      fn(): string. Por omisión, la versión de Divi instalada.
	 * @param callable|null         $creator      fn( string, string, array, array, array ): int|\WP_Error|false. Por omisión, `et_pb_create_layout()` si existe y si no `wp_insert_post()`.
	 * @param callable|null         $finder       fn( string, string ): array<int, int>. Por omisión, `get_posts()` por la marca.
	 * @param callable|null         $deleter      fn( int ): mixed. Por omisión, `wp_delete_post( $id, true )`.
	 * @param DiviLibraryUsage|null $usage    Búsqueda de las páginas que usan un global. Por omisión, la de producción.
	 * @param callable|null         $detaches     fn(): bool. Si Divi despega las instancias al borrar un global. Por omisión, {@see DiviLibraryUsage::divi_detaches_on_trash()}.
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
		?callable $deleter = null,
		?DiviLibraryUsage $usage = null,
		?callable $detaches = null
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
		$this->usage        = $usage ?? new DiviLibraryUsage();
		$this->detaches     = $detaches ?? array( DiviLibraryUsage::class, 'divi_detaches_on_trash' );
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

		$confirm = $this->confirm_of( $input );

		if ( $confirm instanceof \WP_Error ) {
			return $confirm;
		}

		$checked = $this->checked( $id, $restore, $confirm );

		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		return PostLock::run(
			$id,
			function () use ( $id, $restore, $confirm ) {
				$post = $this->checked( $id, $restore, $confirm );

				return $post instanceof \WP_Error ? $post : ( $restore ? $this->restore_locked( $post ) : $this->delete_locked( $post ) );
			}
		);
	}

	/**
	 * El ítem, si se puede borrar o restaurar: existe, no es global (o se confirmó) y (si se borra) la papelera lo guarda.
	 *
	 * @param int  $id      Ítem.
	 * @param bool $restore Restaurar (acepta uno en la papelera) o borrar.
	 * @param bool $confirm `confirm_global` de la entrada.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function checked( int $id, bool $restore, bool $confirm ) {
		$post = $this->load( $id, $restore );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$editable = $this->editable( $id, $confirm );

		if ( $editable instanceof \WP_Error ) {
			return $editable;
		}

		// Mandar a la papelera y sacar de ella son `delete_post` y `untrash_post` (que WordPress mapea a `delete_post`), no `edit_post`.
		if ( ( ! $restore || 'trash' === $post->post_status ) && ! current_user_can( 'delete_post', $id ) ) {
			return new \WP_Error(
				self::CODE_DENIED,
				__( 'No tienes permiso para borrar este ítem de la biblioteca de Divi 5 (hace falta delete_post sobre él).', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
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
		$id     = (int) $post->ID;
		$global = $this->schema->is_global( $id );
		$usage  = $global ? $this->usage->of( $id ) : null;

		if ( null !== $usage && $usage['unknown'] ) {
			return $this->usage_unknown();
		}

		// Un ítem NO global no se busca: las páginas llevan una copia, no una referencia, y Divi tampoco despega nada al borrarlo
		// (`GlobalLayout::preserve_instances_before_delete` sólo actúa con un global). Aviso A4c de T10.
		$used = null !== $usage && $usage['total'] > 0;

		if ( null !== $usage && $usage['not_editable_count'] > 0 ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_pages_forbidden',
				sprintf(
					/* translators: %s: ids de contenidos. */
					__( 'No puedes editar todos los contenidos que usan este ítem global (%s): Divi no despega las instancias de un contenido que el usuario no puede editar y el borrado las dejaría sin su contenido. No se cambió nada.', 'nexo-mcp' ),
					implode( ', ', $usage['not_editable'] )
				),
				array(
					'status' => 403,
					'pages'  => $usage['not_editable'],
					'count'  => $usage['not_editable_count'],
				)
			);
		}

		if ( $used && ! ( $this->detaches )() ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_detach_unavailable',
				__( 'Este ítem global lo usan páginas y Divi no despegaría sus instancias al borrarlo (no está su filtro pre_trash_post): borrarlo las dejaría sin su contenido. No se cambió nada; bórralo en el Visual Builder.', 'nexo-mcp' ),
				array( 'status' => 409 )
			);
		}

		$previous = $this->backup->read( $id );
		$snapshot = $this->backup->snapshot( $post, 'delete', $this->schema->read( $id ) );
		$guard    = $this->back_up( $id, $snapshot );

		if ( null !== $guard ) {
			return $guard;
		}

		$trashed = wp_trash_post( $id );
		$now     = get_post( $id );

		if ( ! $trashed || ! $now instanceof \WP_Post || 'trash' !== $now->post_status ) {
			$error = $this->restore_state(
				$id,
				$snapshot,
				$used ? __( 'Divi no despegó las instancias del ítem en las páginas que lo usan y abortó el borrado (puede haber despegado ya algunas: conservan su contenido).', 'nexo-mcp' ) : __( 'WordPress no mandó el ítem a la papelera.', 'nexo-mcp' )
			);

			// El respaldo que había (el deshacer del último cambio) vuelve: el del borrado ya no tiene sentido.
			$this->reinstate_backup( $id, $previous );

			return $error;
		}

		$left = $used ? $this->usage->of( $id ) : null;

		if ( null !== $left && ( $left['unknown'] || $left['total'] > 0 ) ) {
			// Divi dijo que sí y quedan referencias (o no se puede saber) a un ítem en la papelera (que no se renderiza): se devuelve el ítem.
			$put_back = $this->put_back( $id, $snapshot );
			$this->reinstate_backup( $id, $previous );

			return new \WP_Error(
				'nexo_mcp_divi_library_detach_failed',
				sprintf(
					/* translators: 1: contenidos, 2: resultado. */
					__( 'Tras mandar el ítem a la papelera quedan contenidos que todavía lo referencian (%1$s): perderían su contenido. %2$s', 'nexo-mcp' ),
					$left['unknown'] ? __( 'no se pudo comprobar', 'nexo-mcp' ) : implode( ', ', array_column( $left['pages'], 'id' ) ),
					$put_back ? __( 'El ítem volvió a su estado anterior; las páginas que Divi ya despegó conservan su contenido.', 'nexo-mcp' ) : __( 'No se pudo devolver el ítem automáticamente: restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' )
				),
				array(
					'status' => 500,
					'pages'  => array_column( $left['pages'], 'id' ),
				)
			);
		}

		$read     = $this->schema->read( $id );
		$warnings = array( __( 'El ítem está en la papelera de WordPress (se vacía sola a los pocos días). Restáuralo con nexo-mcp-restore-divi-library-item.', 'nexo-mcp' ) );
		$out      = array(
			'id'      => $id,
			'deleted' => true,
			'status'  => 'trash',
			'title'   => (string) $post->post_title,
			'type'    => $read['type'],
			'scope'   => $read['scope'],
			'backup'  => $this->backup->describe( $id ),
		);

		if ( null !== $usage ) {
			$out['affected_pages']   = self::shown( $usage );
			$out['still_referenced'] = null === $left ? 0 : $left['total'];

			if ( $used ) {
				$warnings[] = __( 'Divi despegó el ítem de las páginas de affected_pages: cada una conserva su contenido como bloques propios. Restaurar el ítem NO las vuelve a enlazar.', 'nexo-mcp' );
			}
		}

		$out['warnings'] = $warnings;

		return $out;
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

		// Un solo escaneo de uso por llamada (con el bloqueo tomado): sirve para la comprobación y para la respuesta.
		$usage = $this->schema->is_global( $id ) ? $this->usage->of( $id ) : null;

		if ( null !== $usage && $usage['unknown'] ) {
			return $this->usage_unknown();
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
			$error = $this->restore_state( $id, $now, __( 'WordPress no dejó el ítem como estaba en el respaldo.', 'nexo-mcp' ) );

			// El respaldo del que se restauraba sigue siendo el bueno: se devuelve (el de ahora es el mismo estado que ya tiene).
			$this->backup->save( $id, $from );

			return $error;
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

		return $answer instanceof \WP_Error ? $answer : $this->with_usage(
			array_merge(
				$answer,
				array(
					'restored'      => true,
					'restored_from' => array(
						'operation'   => $from['operation'],
						'captured_at' => $from['captured_at'],
					),
				)
			),
			$id,
			$usage
		);
	}

	/**
	 * Guarda el respaldo y comprueba el bloqueo, justo antes de la primera escritura.
	 *
	 * @param int                  $id       Ítem.
	 * @param array<string, mixed> $snapshot Estado que se respalda.
	 */
	private function back_up( int $id, array $snapshot ): ?\WP_Error {
		// Primero el bloqueo: un bloqueo perdido no debe dejar escrito un respaldo nuevo encima del que había.
		$locked = PostLock::verify( $id );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		if ( ! $this->backup->save( $id, $snapshot ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_backup_failed',
				__( 'No se pudo guardar el respaldo del ítem, así que no se cambió nada.', 'nexo-mcp' ),
				array( 'status' => 500 )
			);
		}

		return null;
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
		$created = array();
		$result  = $this->create_prepared( $parsed, $created );

		// Un alta que se deshace no deja las categorías que creó ella misma (y sólo esas).
		if ( $result instanceof \WP_Error ) {
			$this->schema->drop_categories( $created );
		}

		return $result;
	}

	/**
	 * Valida el árbol y crea el ítem; si algo falla después de crearlo, lo borra.
	 *
	 * @param array<string, mixed> $parsed  Entrada de {@see DiviLibrarySchema::parse_save()}.
	 * @param array<int, int>      $created Se llena con los ids de las categorías que crea esta llamada.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_prepared( array $parsed, array &$created ) {
		$type     = (string) $parsed['type'];
		$prepared = $this->prepare( $type, $parsed['nodes'] );

		if ( $prepared instanceof \WP_Error ) {
			return $prepared;
		}

		$scope      = (string) $parsed['scope'];
		$categories = (array) ( $parsed['categories'] ?? array() );
		$created    = $this->schema->ensure_categories( $categories );
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
				|| ! DiviLibrarySchema::same_categories( $categories, $read['categories'] )
				|| ! $this->has_metas( $id, $stored['meta'] ) ) {
				return $this->rollback( $id, __( 'WordPress guardó un contenido, unos términos o unas metas distintos de los pedidos.', 'nexo-mcp' ) );
			}

			delete_post_meta( $id, self::MARKER_META );
		} catch ( \Throwable $e ) {
			return $this->rollback( $id, __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		$warnings = array();

		if ( 'global' === $scope ) {
			$warnings[] = __( 'Es un ítem global: cuando se use en páginas (nexo-mcp-insert-divi-library-item con mode global), cambiarlo cambia todas. Actualizarlo, borrarlo o restaurarlo pide confirm_global: true y devuelve las páginas afectadas.', 'nexo-mcp' );
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

		$confirm = $this->confirm_of( $input );

		if ( $confirm instanceof \WP_Error ) {
			return $confirm;
		}

		$current = $this->editable( $id, $confirm );

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

		$current = $this->editable( $id, (bool) $parsed['confirm_global'] );

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

		// Un solo escaneo de uso por llamada (con el bloqueo tomado): sirve para la comprobación y para la respuesta.
		$usage = $this->schema->is_global( $id ) ? $this->usage->of( $id ) : null;

		if ( null !== $usage && $usage['unknown'] ) {
			return $this->usage_unknown();
		}

		$read     = $this->schema->read( $id );
		$previous = $this->backup->read( $id );
		$snapshot = $this->backup->snapshot( $post, 'save', $read );

		$guard = $this->back_up( $id, $snapshot );

		if ( null !== $guard ) {
			return $guard;
		}

		$created = null === $parsed['categories'] ? array() : $this->schema->ensure_categories( $parsed['categories'] );
		$failure = $this->apply( $id, $parsed, $prepared );

		if ( null !== $failure ) {
			$error = $this->restore_state( $id, $snapshot, $failure );
			$this->reinstate_backup( $id, $previous );

			// Las categorías que creó esta llamada (sólo ésas) no se quedan de sobra.
			$this->schema->drop_categories( $created );

			return $error;
		}

		$warnings = array();

		if ( array() !== $foreign ) {
			$warnings[] = __( 'Se reemplazó contenido que el conector no sabe representar; sigue en el respaldo.', 'nexo-mcp' );
		}

		$previous = array(
			'title'      => $snapshot['title'],
			'categories' => $snapshot['categories'],
		);

		$answer = $this->answer( $id, false, $warnings, $previous, $this->backup->describe( $id ) );

		return $answer instanceof \WP_Error ? $answer : $this->with_usage( $answer, $id, $usage );
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

			if ( null !== $prepared ) {
				// Un ítem de Divi 4 que se reescribe con bloques pasa a ser de Divi 5.
				update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
			}

			if ( null !== $prepared && null !== $prepared['module_type'] ) {
				update_post_meta( $id, '_et_pb_module_type', $prepared['module_type'] );
			}

			$post = get_post( $id );
			$read = $this->schema->read( $id );

			if ( ! $post instanceof \WP_Post
				|| ( null !== $parsed['title'] && $parsed['title'] !== $post->post_title )
				|| ( null !== $prepared && $prepared['content'] !== $post->post_content )
				|| ( null !== $parsed['categories'] && ! DiviLibrarySchema::same_categories( $parsed['categories'], $read['categories'] ) ) ) {
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

			// Un respaldo sin módulo o sin la marca de Divi 5 también las quita: lo que el cambio fallido pudo haber puesto.
			$this->put_meta( $id, '_et_pb_module_type', (string) $snapshot['module_type'] );

			if ( isset( $snapshot['use_divi_5'] ) && is_string( $snapshot['use_divi_5'] ) ) {
				$this->put_meta( $id, '_et_pb_use_divi_5', $snapshot['use_divi_5'] );
			}

			$post = get_post( $id );

			return $post instanceof \WP_Post
				&& $post->post_title === $snapshot['title']
				&& $post->post_content === $snapshot['content']
				&& $post->post_status === $snapshot['status']
				&& DiviLibrarySchema::same_categories( (array) $snapshot['categories'], $this->schema->read( $id )['categories'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Pone una meta, o la quita si el valor es vacío.
	 *
	 * @param int    $id    Ítem.
	 * @param string $key   Meta.
	 * @param string $value Valor.
	 */
	private function put_meta( int $id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );

			return;
		}

		update_post_meta( $id, $key, $value );
	}

	/**
	 * Tipo y alcance de un ítem que se puede cambiar: reconocido (tiene tipo) y, si es global, con `confirm_global: true`.
	 *
	 * @param int  $id      Ítem.
	 * @param bool $confirm `confirm_global` de la entrada.
	 *
	 * @return array{type: string, scope: string}|\WP_Error
	 */
	private function editable( int $id, bool $confirm ) {
		if ( ! $confirm && $this->schema->is_global( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_library_global_blocked',
				__( 'Este ítem es global: cambiarlo, borrarlo o restaurarlo cambia todas las páginas que lo usan sin tocarlas (Divi lo lee al renderizar). Si es lo que quieres, repite la llamada con confirm_global: true: la respuesta lista las páginas afectadas y queda el respaldo del ítem.', 'nexo-mcp' ),
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
	 * Devuelve el respaldo que había antes de una operación que falló (o lo quita si no había), para no perder el deshacer anterior.
	 *
	 * @param int                       $id       Ítem.
	 * @param array<string, mixed>|null $previous Respaldo de antes (de {@see DiviLibraryBackup::read()}).
	 */
	private function reinstate_backup( int $id, ?array $previous ): void {
		if ( null === $previous ) {
			$this->backup->forget( $id );

			return;
		}

		$this->backup->save( $id, $previous );
	}

	/**
	 * Error de una búsqueda de uso que falló: se trata como desconocido, nunca como «sin uso».
	 */
	private function usage_unknown(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_library_usage_unknown',
			__( 'No se pudo comprobar qué páginas usan este ítem global (la base de datos falló al buscarlas), así que no se cambia, borra ni restaura nada. Reintenta.', 'nexo-mcp' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * `confirm_global` de la entrada: false si falta; si está tiene que ser true o false.
	 *
	 * @param array<string, mixed> $input Entrada.
	 *
	 * @return bool|\WP_Error
	 */
	private function confirm_of( array $input ) {
		if ( ! array_key_exists( 'confirm_global', $input ) ) {
			return false;
		}

		return is_bool( $input['confirm_global'] ) ? $input['confirm_global'] : $this->schema->error( 'confirm_global', __( 'debe ser true o false.', 'nexo-mcp' ) );
	}

	/**
	 * Suma a la respuesta las páginas afectadas si el ítem es global, y renueva su caché (Divi las sincroniza al renderizar, pero
	 * el CSS estático y los plugins de caché guardan la versión anterior).
	 *
	 * @param array<string, mixed>      $answer Respuesta.
	 * @param int                       $id     Ítem.
	 * @param array<string, mixed>|null $usage  Escaneo de uso que ya hizo la llamada (se reutiliza); si es null se hace uno.
	 *
	 * @return array<string, mixed>
	 */
	private function with_usage( array $answer, int $id, ?array $usage = null ): array {
		if ( ! $this->schema->is_global( $id ) ) {
			return $answer;
		}

		$usage ??= $this->usage->of( $id );

		// A todas las afectadas (hasta el tope), en lotes: la lista de la respuesta sólo trae las primeras.
		$flushed = 0;

		foreach ( array_chunk( $usage['ids'], 50 ) as $batch ) {
			foreach ( $batch as $page_id ) {
				try {
					$post = get_post( $page_id );

					if ( $post instanceof \WP_Post ) {
						( new Divi5Adapter() )->flush( $post );
						++$flushed;
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		$answer['cache_not_cleared'] = max( 0, $usage['count'] - $flushed );

		$answer['affected_pages'] = self::shown( $usage );
		$extra                    = array();

		if ( $usage['unknown'] ) {
			$extra[] = __( 'No se pudo comprobar qué páginas usan el ítem (la base falló): affected_pages puede estar incompleta y no se renovó la caché de ninguna.', 'nexo-mcp' );
		} elseif ( $answer['cache_not_cleared'] > 0 ) {
			$extra[] = sprintf( /* translators: %d: páginas. */ __( 'No se renovó la caché de %d páginas afectadas (pasan del tope): se renovará sola o vacía la caché del sitio.', 'nexo-mcp' ), $answer['cache_not_cleared'] );
		}

		$answer['warnings'] = array_merge(
			(array) ( $answer['warnings'] ?? array() ),
			$extra,
			array( __( 'Es un ítem global: el cambio ya lo ven todas las páginas de affected_pages (Divi lo lee al renderizar) sin que se hayan tocado. Si algo sale mal, nexo-mcp-restore-divi-library-item con confirm_global: true lo deshace.', 'nexo-mcp' ) )
		);

		return $answer;
	}

	/**
	 * Las páginas afectadas tal como se devuelven.
	 *
	 * @param array<string, mixed> $usage Resultado de {@see DiviLibraryUsage::of()}.
	 *
	 * @return array<string, mixed>
	 */
	private static function shown( array $usage ): array {
		return array(
			'count'     => $usage['count'],
			'pages'     => $usage['pages'],
			'truncated' => $usage['truncated'],
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
