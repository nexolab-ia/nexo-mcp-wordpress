<?php
/**
 * Dónde se usa un ítem global de la biblioteca de Divi 5: las páginas (y demás contenidos) que lo referencian.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Un ítem global no se copia en las páginas: cada una guarda `<!-- wp:divi/global-layout {"globalModule":"<id>",…} /-->` y Divi
 * lee el ítem al RENDERIZAR (L2 de `odd/tasks/divi-theme-builder.md`), así que cambiarlo, borrarlo o restaurarlo cambia todas
 * esas páginas sin tocarlas. Esta clase las encuentra para avisar antes (y después) de hacerlo.
 *
 * Cuenta publicados, borradores, privados, programados, pendientes y COPIAS DE TRABAJO (que son borradores con la meta
 * `_nexo_mcp_draft_of`): no la papelera, ni el borrador automático, ni las revisiones. También los layouts del Theme Builder y
 * otros ítems de la biblioteca que lo referencian. La coincidencia es exacta por id (`12` no encuentra `123`) y reconoce la
 * referencia de Divi 5 y el atributo `global_module` de los shortcodes de Divi 4.
 */
final class DiviLibraryUsage {

	/**
	 * Filas por lote al recorrer la tabla de contenidos.
	 */
	private const BATCH = 100;

	/**
	 * Tope de contenidos que se listan en la respuesta (todos se cuentan).
	 */
	public const MAX_LISTED = 50;

	/**
	 * Tope de contenidos a los que se les renueva la caché tras cambiar un global (el resto se informa).
	 */
	public const MAX_FLUSH = 500;

	/**
	 * Tope de ids sin permiso de edición que se devuelven.
	 */
	public const MAX_BLOCKED = 20;

	/**
	 * Lote de candidatos: fn( int $after, int $item ): array<int, array<string, mixed>> (filas con `ID`, `post_type`,
	 * `post_status`, `post_title` y `post_content` cuyo contenido menciona una referencia global, de id mayor que `$after`,
	 * distintas de `$item`, en orden de id y hasta {@see self::BATCH}).
	 *
	 * @var callable
	 */
	private $fetch;

	/**
	 * Permiso de edición de un contenido: fn( int $id ): bool.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Permiso de lectura de un contenido (para mostrar su título): fn( int $id ): bool.
	 *
	 * @var callable
	 */
	private $can_read;

	/**
	 * Construye la consulta.
	 *
	 * @param callable|null $fetch    Lote de candidatos. Por omisión, una consulta a `$wpdb->posts`.
	 * @param callable|null $can_edit fn( int ): bool. Por omisión, `current_user_can( 'edit_post', $id )`.
	 * @param callable|null $can_read fn( int ): bool. Por omisión, `current_user_can( 'read_post', $id )`.
	 */
	public function __construct( ?callable $fetch = null, ?callable $can_edit = null, ?callable $can_read = null ) {
		$this->fetch    = $fetch ?? array( self::class, 'fetch_batch' );
		$this->can_edit = $can_edit ?? static fn( int $id ): bool => current_user_can( 'edit_post', $id );
		$this->can_read = $can_read ?? static fn( int $id ): bool => current_user_can( 'read_post', $id );
	}

	/**
	 * Contenidos que referencian el ítem.
	 *
	 * `count`, `pages` e `ids` son los vivos; `total` cuenta además papelera, revisiones y borradores automáticos, que Divi también
	 * despega y cuyo permiso de edición entra en `not_editable`. `unknown` es true si la base falló: el resto no es fiable.
	 *
	 * @param int $item_id Ítem global.
	 *
	 * @return array{count: int, total: int, unknown: bool, ids: array<int, int>, pages: array<int, array<string, mixed>>, truncated: bool, not_editable: array<int, int>, not_editable_count: int}
	 */
	public function of( int $item_id ): array {
		$pages     = array();
		$count     = 0;
		$blocked   = array();
		$blocked_n = 0;
		$total     = 0;
		$ids       = array();
		$unknown   = false;
		$after     = 0;

		do {
			$from = $after;

			try {
				$rows = ( $this->fetch )( $after, $item_id );
			} catch ( \Throwable $e ) {
				$rows = null;
			}

			// Un error de la base NO es «sin uso»: se marca desconocido (como Divi, que se detiene con `$wpdb->last_error`).
			if ( ! is_array( $rows ) ) {
				$unknown = true;
				break;
			}

			$rows = array_values( $rows );
			$full = count( $rows ) >= self::BATCH;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['ID'] ) ) {
					continue;
				}

				$id     = (int) $row['ID'];
				$after  = max( $after, $id );
				$status = (string) ( $row['post_status'] ?? '' );
				$type   = (string) ( $row['post_type'] ?? '' );

				if ( $id === $item_id || ! self::references( (string) ( $row['post_content'] ?? '' ), $item_id ) ) {
					continue;
				}

				// Divi despega TODO lo que referencia el ítem, sin filtrar estado ni tipo (papelera, revisiones, borradores
				// automáticos): el permiso de edición se comprueba en todos, la lista sólo trae los vivos.
				++$total;
				$editable = $this->editable( $id );

				if ( ! $editable ) {
					++$blocked_n;

					if ( count( $blocked ) < self::MAX_BLOCKED ) {
						$blocked[] = $id;
					}
				}

				if ( in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) || in_array( $type, array( 'revision', 'nav_menu_item' ), true ) ) {
					continue;
				}

				++$count;

				if ( count( $ids ) < self::MAX_FLUSH ) {
					$ids[] = $id;
				}

				if ( count( $pages ) < self::MAX_LISTED ) {
					$pages[] = $this->entry( $id, $type, $status, (string) ( $row['post_title'] ?? '' ), $editable );
				}
			}
		} while ( $full && $after > $from );

		return array(
			'count'              => $count,
			'total'              => $total,
			'unknown'            => $unknown,
			'ids'                => $ids,
			'pages'              => $pages,
			'truncated'          => $count > count( $pages ),
			'not_editable'       => $blocked,
			'not_editable_count' => $blocked_n,
		);
	}

	/**
	 * Si el contenido tiene una referencia al ítem (`"globalModule":"12"`, también con el id como número, o el `global_module="12"`
	 * de Divi 4). El id es exacto: `12` no coincide con `123` ni con `912`.
	 *
	 * @param string $content `post_content`.
	 * @param int    $id      Ítem.
	 */
	public static function references( string $content, int $id ): bool {
		return 1 === preg_match( '/globalModule\\\\*"\s*:\s*\\\\*"?' . $id . '(?![0-9])/', $content )
			|| 1 === preg_match( '/\bglobal_module=\\\\*["\']?' . $id . '(?![0-9])/', $content );
	}

	/**
	 * Si Divi despega las instancias al mandar un ítem global a la papelera (su filtro `pre_trash_post`,
	 * `GlobalLayout::preserve_instances_before_delete`, que incrusta el contenido del ítem en cada página que lo usa).
	 */
	public static function divi_detaches_on_trash(): bool {
		return function_exists( 'has_filter' )
			&& false !== has_filter( 'pre_trash_post', array( 'ET\Builder\Packages\GlobalLayout\GlobalLayout', 'preserve_instances_before_delete' ) );
	}

	/**
	 * Un contenido de la respuesta.
	 *
	 * @param int    $id       Contenido.
	 * @param string $type     Tipo de contenido.
	 * @param string $status   Estado.
	 * @param string $title    Título.
	 * @param bool   $editable Si el usuario puede editarlo.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( int $id, string $type, string $status, string $title, bool $editable ): array {
		// Un privado, un borrador o un contenido de otro usuario no deja ver su título sin `read_post` (aviso R1 de T10).
		$readable = $this->readable( $id );
		$entry    = array(
			'id'        => $id,
			'title'     => $readable ? $title : '',
			'post_type' => $type,
			'status'    => $status,
		);

		if ( ! $readable ) {
			$entry['title_hidden'] = true;
		}

		$original = WorkingCopy::original_id_for( $id );

		if ( $original !== $id ) {
			$entry['working_copy_of'] = $original;
		}

		$entry['editable'] = $editable;

		return $entry;
	}

	/**
	 * Permiso de lectura sin lanzar: si no se puede saber, no se puede.
	 *
	 * @param int $id Contenido.
	 */
	private function readable( int $id ): bool {
		try {
			return (bool) ( $this->can_read )( $id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Permiso de edición sin lanzar: si no se puede saber, no se puede.
	 *
	 * @param int $id Contenido.
	 */
	private function editable( int $id ): bool {
		try {
			return (bool) ( $this->can_edit )( $id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Lote de candidatos de la tabla de contenidos: los que mencionan una referencia global Y el id del ítem (condición necesaria de
	 * cualquier forma de la referencia), así no se leen los contenidos que sólo usan OTROS globales. El filtro exacto sigue en
	 * {@see self::references()}.
	 *
	 * @param int $after Último id visto.
	 * @param int $item  Ítem (se excluye).
	 *
	 * @return array<int, array<string, mixed>>|null Null si la base falló.
	 */
	public static function fetch_batch( int $after, int $item ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Búsqueda puntual de uso, sin caché a propósito.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE ID > %d AND ID <> %d AND post_content LIKE %s AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas de Core.
				$after,
				$item,
				'%' . $wpdb->esc_like( (string) $item ) . '%',
				'%' . $wpdb->esc_like( 'globalModule' ) . '%',
				'%' . $wpdb->esc_like( 'global_module' ) . '%',
				self::BATCH
			),
			ARRAY_A
		);

		return is_array( $rows ) && '' === (string) ( $wpdb->last_error ?? '' ) ? $rows : null;
	}
}
