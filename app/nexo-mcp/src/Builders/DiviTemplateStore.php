<?php
/**
 * Acceso a las plantillas del Theme Builder de Divi 5: Theme Builder en vivo, mapeo, metas, respaldo y cachés.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\PostLock;

/**
 * Lo que `list-divi-theme-builder`, `set-divi-template` y `delete-divi-template` tocan de WordPress (L2 y L4 de
 * `odd/tasks/divi-theme-builder.md`).
 *
 * Divi guarda el Theme Builder como UN post `et_theme_builder`: `publish` es el que ve el sitio y `auto-draft` el
 * borrador del Visual Builder. Las plantillas (`et_template`, siempre `publish`) cuelgan de él con una fila de meta
 * `_et_template` por plantilla. Aquí se escribe SIEMPRE en el vivo, y de ese mapeo se toca sólo la fila de la plantilla
 * propia (`add_post_meta`/`delete_post_meta` de UNA fila, nunca «borrar todo y volver a poner» como el guardado del
 * Visual Builder). Las metas de la plantilla se escriben directamente con {@see DiviThemeBuilderSchema::stored()}, sin
 * pasar por `et_theme_builder_store_template()` (L4 (d): enlaza mal, deja la plantilla por defecto si falta una clave,
 * compara con cadenas y recorta en silencio los layouts).
 *
 * Todo lo que lee y reescribe corre bajo un único bloqueo ({@see self::locked()}): dos llamadas a la vez no mezclan el
 * mapeo. Antes de cada cambio se guarda un respaldo en la propia plantilla ({@see self::BACKUP_META}): sus metas, su
 * título y estado y si estaba en el mapeo (más la lista completa del mapeo, para saber qué había). Es una sola copia, la
 * del último estado bueno.
 */
final class DiviTemplateStore {

	/**
	 * Clave del bloqueo: un id de contenido que no existe en la práctica (una sola cerradura para todo el Theme Builder).
	 */
	public const LOCK_ID = PHP_INT_MAX;

	/**
	 * Meta de la plantilla con su respaldo.
	 */
	public const BACKUP_META = '_nexo_mcp_template_backup';

	public const TEMPLATE_TYPE = 'et_template';

	public const BUILDER_TYPE = 'et_theme_builder';

	/**
	 * Fila de meta del Theme Builder con el id de una plantilla.
	 */
	public const MAP_META = '_et_template';

	/**
	 * Marca de Divi: lo que no está en el mapeo en vivo, a la papelera a los 7 días.
	 */
	public const MARKED_META = '_et_theme_builder_marked_as_unused';

	public const MAX_TEMPLATES = 50;

	/**
	 * Metas con una fila por elemento.
	 */
	private const LIST_METAS = array( '_et_use_on', '_et_exclude_from' );

	/**
	 * Metas de Divi que el conector no toca al reescribir una plantilla.
	 */
	private const KEPT_METAS = array( '_et_onboarding_created' );

	/**
	 * Metas que deja `wp_trash_post()`.
	 */
	private const TRASH_METAS = array( '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/**
	 * Busca el Theme Builder de un estado: fn( string $status ): int (0 si no hay).
	 *
	 * @var callable
	 */
	private $finder;

	/**
	 * Crea (o devuelve) el Theme Builder en vivo: fn(): int.
	 *
	 * @var callable
	 */
	private $creator;

	/**
	 * Bloqueo: fn( callable ): mixed.
	 *
	 * @var callable
	 */
	private $lock;

	/**
	 * Construye el almacén; cada función tiene su fuente de producción por omisión.
	 *
	 * @param callable|null $finder  fn( string $status ): int. Por omisión, {@see self::find_builder_post()}.
	 * @param callable|null $creator fn(): int. Por omisión, `et_theme_builder_get_theme_builder_post_id( true )` de Divi.
	 * @param callable|null $lock    fn( callable ): mixed. Por omisión, {@see PostLock} con {@see self::LOCK_ID}.
	 */
	public function __construct( ?callable $finder = null, ?callable $creator = null, ?callable $lock = null ) {
		$this->finder  = $finder ?? array( self::class, 'find_builder_post' );
		$this->creator = $creator ?? static fn(): int => function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ? absint( et_theme_builder_get_theme_builder_post_id( true ) ) : 0;
		$this->lock    = $lock ?? static fn( callable $work ) => PostLock::run( self::LOCK_ID, $work );
	}

	/**
	 * Theme Builder de un estado (fuente de producción): el más reciente que no sea de la biblioteca del Theme Builder.
	 *
	 * @param string $status `publish` (en vivo) o `auto-draft` (borrador del Visual Builder).
	 */
	public static function find_builder_post( string $status ): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::BUILDER_TYPE,
				'post_status'    => $status,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_et_library_theme_builder',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return isset( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Corre el trabajo con el bloqueo del Theme Builder, o devuelve `nexo_mcp_busy`.
	 *
	 * @param callable $work fn(): mixed.
	 *
	 * @return mixed|\WP_Error
	 */
	public function locked( callable $work ) {
		return ( $this->lock )( $work );
	}

	/**
	 * Justo antes de la primera escritura: si el bloqueo venció y es de otro, no se escribe.
	 *
	 * @return true|\WP_Error
	 */
	public function verify_lock() {
		return PostLock::verify( self::LOCK_ID );
	}

	/**
	 * Id del Theme Builder en vivo, o 0 si no hay (no lo crea).
	 */
	public function live_id(): int {
		return absint( ( $this->finder )( 'publish' ) );
	}

	/**
	 * Id del borrador del Visual Builder, o 0 si no hay.
	 */
	public function draft_id(): int {
		return absint( ( $this->finder )( 'auto-draft' ) );
	}

	/**
	 * Id del Theme Builder en vivo; si no existe lo crea como lo hace Divi. 0 si no se pudo.
	 */
	public function ensure_live_id(): int {
		$id = $this->live_id();

		return $id > 0 ? $id : absint( ( $this->creator )() );
	}

	/**
	 * Ids de las plantillas del mapeo de un Theme Builder, en su orden y sin repetir.
	 *
	 * @param int $tb Theme Builder.
	 *
	 * @return array<int, int>
	 */
	public function mapping( int $tb ): array {
		if ( $tb <= 0 ) {
			return array();
		}

		$rows = get_post_meta( $tb, self::MAP_META, false );
		$ids  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = is_scalar( $row ) && 1 === preg_match( '/^[1-9][0-9]{0,9}\z/', (string) $row ) ? (int) $row : 0;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Enlaza la plantilla al Theme Builder con UNA fila de meta (si ya está, no hace nada).
	 *
	 * @param int $tb Theme Builder.
	 * @param int $id Plantilla.
	 */
	public function map( int $tb, int $id ): bool {
		if ( $tb <= 0 || $id <= 0 ) {
			return false;
		}

		if ( in_array( $id, $this->mapping( $tb ), true ) ) {
			return true;
		}

		add_post_meta( $tb, self::MAP_META, (string) $id );

		return in_array( $id, $this->mapping( $tb ), true );
	}

	/**
	 * Quita del Theme Builder la fila de la plantilla (y sólo ésa).
	 *
	 * @param int $tb Theme Builder.
	 * @param int $id Plantilla.
	 */
	public function unmap( int $tb, int $id ): bool {
		if ( $tb <= 0 || $id <= 0 ) {
			return false;
		}

		delete_post_meta( $tb, self::MAP_META, (string) $id );

		return ! in_array( $id, $this->mapping( $tb ), true );
	}

	/**
	 * La plantilla (`et_template`) con ese id, o null si no existe o es de otro tipo.
	 *
	 * @param int $id Id.
	 */
	public function template( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && self::TEMPLATE_TYPE === $post->post_type ? $post : null;
	}

	/**
	 * Metas `_et_*` de una plantilla: las listas (`_et_use_on`, `_et_exclude_from`) como arreglo de textos y el resto como
	 * el primer valor, tal como espera {@see DiviThemeBuilderSchema::summary()}.
	 *
	 * @param int $id Plantilla.
	 *
	 * @return array<string, string|array<int, string>>
	 */
	public function metas( int $id ): array {
		$raw = get_post_meta( $id );
		$out = array();

		foreach ( is_array( $raw ) ? $raw : array() as $key => $values ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, '_et_' ) ) {
				continue;
			}

			$values = array_values( array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', is_array( $values ) ? $values : array() ) );

			$out[ $key ] = in_array( $key, self::LIST_METAS, true )
				? array_values( array_filter( $values, static fn( string $value ): bool => '' !== $value ) )
				: ( $values[0] ?? '' );
		}

		return $out;
	}

	/**
	 * Si Divi marcó el contenido (plantilla o layout) como sin uso: pasados 7 días lo manda a la papelera.
	 *
	 * @param int $id Plantilla o layout.
	 */
	public function is_marked_unused( int $id ): bool {
		return '' !== (string) get_post_meta( $id, self::MARKED_META, true );
	}

	/**
	 * Crea la plantilla `publish` y vacía; las metas las escribe {@see self::write()}.
	 *
	 * @param string $title Título limpio.
	 *
	 * @return int|\WP_Error
	 */
	public function insert( string $title ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::TEMPLATE_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $title ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $id;
	}

	/**
	 * Cambia el título de la plantilla.
	 *
	 * @param int    $id    Plantilla.
	 * @param string $title Título limpio.
	 *
	 * @return true|\WP_Error
	 */
	public function rename( int $id, string $title ) {
		$saved = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => wp_slash( $title ),
			),
			true
		);

		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * Reescribe las metas `_et_*` de la plantilla con EXACTAMENTE estas: las listas, una fila por elemento; el resto,
	 * texto. Las `_et_*` que no estén en `$stored` se quitan (salvo las que el conector no toca): una plantilla ajena
	 * reemplazada no conserva metas que el esquema no entiende.
	 *
	 * @param int                                      $id     Plantilla.
	 * @param array<string, string|array<int, string>> $stored Metas ({@see DiviThemeBuilderSchema::stored()} o un respaldo).
	 */
	public function write( int $id, array $stored ): void {
		foreach ( array_keys( $this->metas( $id ) ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) && ! in_array( $key, self::KEPT_METAS, true ) ) {
				delete_post_meta( $id, $key );
			}
		}

		foreach ( $stored as $key => $value ) {
			if ( in_array( $key, self::LIST_METAS, true ) || is_array( $value ) ) {
				delete_post_meta( $id, $key );

				foreach ( (array) $value as $row ) {
					add_post_meta( $id, $key, (string) $row );
				}

				continue;
			}

			update_post_meta( $id, $key, (string) $value );
		}
	}

	/**
	 * Elimina del todo una plantilla que esta misma llamada acaba de crear (para deshacer una creación a medias).
	 *
	 * @param int $id Plantilla recién creada.
	 */
	public function discard( int $id ): void {
		wp_delete_post( $id, true );
	}

	/**
	 * La plantilla a la papelera (como Divi: recuperable desde WordPress).
	 *
	 * @param int $id Plantilla.
	 */
	public function trash( int $id ): bool {
		return false !== wp_trash_post( $id ) && 'trash' === get_post_status( $id );
	}

	/**
	 * Estado actual de la plantilla, para respaldarlo.
	 *
	 * @param int    $id        Plantilla.
	 * @param int    $tb        Theme Builder en vivo.
	 * @param string $operation `create`, `replace` o `delete`.
	 * @param bool   $existed   Si la plantilla ya existía (false al crearla).
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot( int $id, int $tb, string $operation, bool $existed ): array {
		$post = get_post( $id );

		return array(
			'version'     => 1,
			'operation'   => $operation,
			'captured_at' => gmdate( 'Y-m-d H:i:s' ),
			'tb_id'       => $tb,
			'template_id' => $id,
			'existed'     => $existed,
			'title'       => $post instanceof \WP_Post ? (string) $post->post_title : '',
			'status'      => $post instanceof \WP_Post ? (string) $post->post_status : '',
			'metas'       => $existed ? $this->metas( $id ) : array(),
			'mapped'      => in_array( $id, $this->mapping( $tb ), true ),
			'mapping'     => $this->mapping( $tb ),
		);
	}

	/**
	 * Guarda el respaldo en la plantilla (una sola copia).
	 *
	 * @param int                  $id       Plantilla.
	 * @param array<string, mixed> $snapshot Resultado de {@see self::snapshot()}.
	 */
	public function save_backup( int $id, array $snapshot ): bool {
		$encoded = wp_json_encode( $snapshot );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		update_post_meta( $id, self::BACKUP_META, wp_slash( $encoded ) );

		return (string) get_post_meta( $id, self::BACKUP_META, true ) === $encoded;
	}

	/**
	 * El respaldo guardado, o null.
	 *
	 * @param int $id Plantilla.
	 *
	 * @return array<string, mixed>|null
	 */
	public function backup( int $id ): ?array {
		$raw     = (string) get_post_meta( $id, self::BACKUP_META, true );
		$decoded = '' === $raw ? null : json_decode( $raw, true );

		return is_array( $decoded ) && isset( $decoded['operation'], $decoded['captured_at'], $decoded['existed'] ) ? $decoded : null;
	}

	/**
	 * Devuelve la plantilla al estado respaldado: sus metas, su título, su estado y si estaba en el mapeo en vivo. Un
	 * respaldo de una creación (`existed` false) la manda a la papelera y la saca del mapeo. Sólo toca la fila de ESTA
	 * plantilla en el mapeo. No captura nada antes: restaurar dos veces deja el mismo estado.
	 *
	 * @param int $id Plantilla.
	 * @param int $tb Theme Builder en vivo.
	 *
	 * @return true|\WP_Error
	 */
	public function restore( int $id, int $tb ) {
		$snapshot = $this->backup( $id );
		$post     = $this->template( $id );

		if ( null === $snapshot ) {
			return new \WP_Error(
				'nexo_mcp_divi_template_no_backup',
				__( 'Esta plantilla no tiene un respaldo disponible.', 'nexo-mcp' ),
				array( 'id' => $id )
			);
		}

		if ( null === $post ) {
			return new \WP_Error(
				'nexo_mcp_divi_template_not_found',
				__( 'No se encontró la plantilla.', 'nexo-mcp' ),
				array( 'id' => $id )
			);
		}

		if ( true !== $snapshot['existed'] ) {
			$this->unmap( $tb, $id );

			return $this->trash( $id ) || 'trash' === $post->post_status ? true : new \WP_Error( 'nexo_mcp_divi_template_save_failed', __( 'WordPress no pudo enviar la plantilla a la papelera.', 'nexo-mcp' ), array( 'id' => $id ) );
		}

		$status = isset( $snapshot['status'] ) && is_string( $snapshot['status'] ) && '' !== $snapshot['status'] ? $snapshot['status'] : 'publish';
		$saved  = wp_update_post(
			array(
				'ID'          => $id,
				'post_title'  => wp_slash( (string) ( $snapshot['title'] ?? '' ) ),
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( 'trash' !== $status ) {
			foreach ( self::TRASH_METAS as $key ) {
				delete_post_meta( $id, $key );
			}
		}

		$this->write( $id, is_array( $snapshot['metas'] ?? null ) ? $snapshot['metas'] : array() );

		if ( true === ( $snapshot['mapped'] ?? false ) ) {
			$this->map( $tb, $id );
		} else {
			$this->unmap( $tb, $id );
		}

		return true;
	}

	/**
	 * Borra el CSS estático y las cachés de página como el guardado en vivo del Theme Builder de Divi
	 * (`et_theme_builder_clear_wp_cache( 'all' )` y `ET_Core_PageResource::remove_static_resources( 'all', 'all' )`): el
	 * HTML se evalúa en cada petición, pero el CSS lleva el id de cada layout y sólo se renueva con la marca global.
	 *
	 * @return array{css: bool, page_cache: bool, warnings: array<int, string>}
	 */
	public function clear_caches(): array {
		$out = array(
			'css'        => false,
			'page_cache' => false,
			'warnings'   => array(),
		);

		if ( function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
			et_theme_builder_clear_wp_cache( 'all' );
			$out['page_cache'] = true;
		}

		if ( class_exists( '\ET_Core_PageResource' ) ) {
			\ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			$out['css'] = true;

			// Divi no hace nada, sin avisar, si el usuario no tiene `edit_posts` y no es cron (L4 (c)).
			if ( ! current_user_can( 'edit_posts' ) ) {
				$out['css']        = false;
				$out['warnings'][] = __( 'El usuario de la conexión no tiene edit_posts: Divi no borra el CSS estático sin avisar. El HTML ya es el nuevo, pero el CSS de los layouts puede tardar en renovarse: vacíalo desde Divi.', 'nexo-mcp' );
			}
		} else {
			$out['warnings'][] = __( 'No se encontró ET_Core_PageResource: no se pudo borrar el CSS estático.', 'nexo-mcp' );
		}

		return $out;
	}
}
