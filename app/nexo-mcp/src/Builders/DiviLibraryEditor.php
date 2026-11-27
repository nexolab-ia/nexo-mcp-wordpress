<?php
/**
 * Herramientas de la biblioteca de Divi 5: listar, leer, guardar (crear y actualizar), borrar y restaurar ítems.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

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
	 * Construye el editor.
	 *
	 * @param DiviLibrarySchema $schema       Esquema.
	 * @param DiviLibraryBackup $backup       Respaldos.
	 * @param callable          $allowed      fn( ?int ): bool. `PermissionPolicy::can_write_divi_library()`.
	 * @param callable|null     $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 */
	public function __construct( DiviLibrarySchema $schema, DiviLibraryBackup $backup, callable $allowed, ?callable $divi5_active = null ) {
		$this->schema       = $schema;
		$this->backup       = $backup;
		$this->allowed      = $allowed;
		$this->divi5_active = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
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

		$nodes = array();

		foreach ( Divi5Tree::build( (string) $post->post_content ) as $node ) {
			$nodes = array_merge( $nodes, Divi5Tree::summarize( $node ) );
		}

		return array_merge(
			$this->schema->summary( $post ),
			array(
				'global'  => $this->schema->is_global( $id ),
				'foreign' => $this->schema->foreign_types( (string) $post->post_content ),
				'backup'  => $this->backup->describe( $id ),
				'nodes'   => $nodes,
			)
		);
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
