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
	 * Construye la consulta.
	 *
	 * @param callable|null $fetch    Lote de candidatos. Por omisión, una consulta a `$wpdb->posts`.
	 * @param callable|null $can_edit fn( int ): bool. Por omisión, `current_user_can( 'edit_post', $id )`.
	 */
	public function __construct( ?callable $fetch = null, ?callable $can_edit = null ) {
		$this->fetch    = $fetch ?? array( self::class, 'fetch_batch' );
		$this->can_edit = $can_edit ?? static fn( int $id ): bool => current_user_can( 'edit_post', $id );
	}

	/**
	 * Contenidos que referencian el ítem.
	 *
	 * @param int $item_id Ítem global.
	 *
	 * @return array{count: int, pages: array<int, array<string, mixed>>, truncated: bool, not_editable: array<int, int>, not_editable_count: int}
	 */
	public function of( int $item_id ): array {
		$pages     = array();
		$count     = 0;
		$blocked   = array();
		$blocked_n = 0;
		$after     = 0;

		do {
			$from = $after;

			try {
				$rows = ( $this->fetch )( $after, $item_id );
			} catch ( \Throwable $e ) {
				$rows = array();
			}

			$rows = is_array( $rows ) ? array_values( $rows ) : array();
			$full = count( $rows ) >= self::BATCH;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['ID'] ) ) {
					continue;
				}

				$id     = (int) $row['ID'];
				$after  = max( $after, $id );
				$status = (string) ( $row['post_status'] ?? '' );
				$type   = (string) ( $row['post_type'] ?? '' );

				if ( $id === $item_id || in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true ) || in_array( $type, array( 'revision', 'nav_menu_item' ), true ) || ! self::references( (string) ( $row['post_content'] ?? '' ), $item_id ) ) {
					continue;
				}

				++$count;
				$editable = $this->editable( $id );

				if ( ! $editable ) {
					++$blocked_n;

					if ( count( $blocked ) < self::MAX_BLOCKED ) {
						$blocked[] = $id;
					}
				}

				if ( count( $pages ) < self::MAX_LISTED ) {
					$pages[] = $this->entry( $id, $type, $status, (string) ( $row['post_title'] ?? '' ), $editable );
				}
			}
		} while ( $full && $after > $from );

		return array(
			'count'              => $count,
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
		$entry    = array(
			'id'        => $id,
			'title'     => $title,
			'post_type' => $type,
			'status'    => $status,
		);
		$original = WorkingCopy::original_id_for( $id );

		if ( $original !== $id ) {
			$entry['working_copy_of'] = $original;
		}

		$entry['editable'] = $editable;

		return $entry;
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
	 * Lote de candidatos de la tabla de contenidos.
	 *
	 * @param int $after Último id visto.
	 * @param int $item  Ítem (se excluye).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch_batch( int $after, int $item ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Búsqueda puntual de uso, sin caché a propósito.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE ID > %d AND ID <> %d AND post_type NOT IN ('revision', 'nav_menu_item') AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas de Core.
				$after,
				$item,
				'%' . $wpdb->esc_like( 'globalModule' ) . '%',
				'%' . $wpdb->esc_like( 'global_module' ) . '%',
				self::BATCH
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
