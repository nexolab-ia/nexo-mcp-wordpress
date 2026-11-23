<?php
/**
 * Camino de escritura compartido de las herramientas de edición de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Destino, bloqueo, copia de trabajo, respaldo y guardado de `post_content` de Divi 5.
 *
 * Lo usan {@see Divi5ModuleEditor} y {@see Divi5SectionEditor} (y {@see Divi5PageCreator}
 * para el kses): una sola implementación de las invariantes. Todo corre dentro del
 * bloqueo del original ({@see WorkingCopy::with_lock()}), que pone quien llama.
 *
 * Orden de {@see self::target()}: Divi 5 activo, la página es Divi 5, destino (copia o
 * borrador), modo «sólo borradores», bloqueo de edición. Orden de {@see self::write()}:
 * bloqueo vigente, copia (si hay que crearla), respaldo (sin respaldo no se escribe),
 * guardado con los filtros kses quitados sólo durante esa escritura, metas
 * `_et_pb_use_builder`/`_et_pb_use_divi_5` y vaciado de la caché CSS. Si algo falla
 * después de crear una copia nueva, la copia se descarta.
 */
final class Divi5ContentWriter {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Adaptador de Divi 5.
	 *
	 * @var Divi5Adapter
	 */
	private Divi5Adapter $adapter;

	/**
	 * Respaldo previo a la escritura.
	 *
	 * @var BuilderBackup
	 */
	private BuilderBackup $backup;

	/**
	 * Copias de trabajo.
	 *
	 * @var WorkingCopy
	 */
	private WorkingCopy $copies;

	/**
	 * Comprobación del bloqueo de edición: fn( \WP_Post ): true|\WP_Error.
	 *
	 * @var callable
	 */
	private $lock_check;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Guardado de `post_content`: fn( int $id, string $content ): true|\WP_Error.
	 *
	 * @var callable
	 */
	private $saver;

	/**
	 * Construye el escritor.
	 *
	 * @param Settings      $settings     Configuración.
	 * @param Divi5Adapter  $adapter      Adaptador de Divi 5.
	 * @param BuilderBackup $backup       Respaldos.
	 * @param WorkingCopy   $copies       Copias de trabajo.
	 * @param callable      $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null $saver        fn( int, string ): true|\WP_Error. Por omisión, `wp_update_post()` (sólo pruebas).
	 */
	public function __construct(
		Settings $settings,
		Divi5Adapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		callable $lock_check,
		?callable $divi5_active = null,
		?callable $saver = null
	) {
		$this->settings     = $settings;
		$this->adapter      = $adapter;
		$this->backup       = $backup;
		$this->copies       = $copies;
		$this->lock_check   = $lock_check;
		$this->divi5_active = $divi5_active ?? static fn(): bool => self::divi5_installed();
		$this->saver        = $saver ?? static function ( int $id, string $content ) {
			$saved = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $content ),
				),
				true
			);

			return is_wp_error( $saved ) ? $saved : true;
		};
	}

	/**
	 * Indica si Divi 5 está instalado y activo.
	 */
	public static function divi5_installed(): bool {
		return defined( 'ET_BUILDER_5_DIR' );
	}

	/**
	 * Error de Divi 5 no activo.
	 */
	public static function required_error(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi5_required',
			__( 'Divi 5 no está activo en este sitio. Instala y activa el tema Divi 5 para crear o editar páginas con módulos.', 'nexo-mcp' )
		);
	}

	/**
	 * Ejecuta el trabajo con los filtros kses quitados y los restaura siempre.
	 *
	 * Sólo para contenido que ya pasó el validador: el kses del núcleo corrompe los
	 * comentarios de bloque cuando el usuario no tiene `unfiltered_html`. Los filtros
	 * se restauran en un `finally` (también si el trabajo lanza) y sólo si estaban activos.
	 *
	 * @param callable $work fn(): mixed.
	 *
	 * @return mixed
	 */
	public static function without_kses( callable $work ) {
		$active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		kses_remove_filters();

		try {
			return $work();
		} finally {
			if ( $active ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Decide el destino y hace las comprobaciones previas; no escribe ni crea nada.
	 *
	 * @param \WP_Post             $post  Contenido indicado (ya releído dentro del bloqueo).
	 * @param array<string, mixed> $input Parámetros (`draft`).
	 *
	 * @return array{staged: bool, existing: int, source: \WP_Post}|\WP_Error
	 */
	public function target( \WP_Post $post, array $input ) {
		if ( ! ( $this->divi5_active )() ) {
			return self::required_error();
		}

		if ( ! $this->adapter->handles( $post ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_not_divi5',
				__( 'Esta página no está hecha con Divi 5 (no tiene bloques «divi/»).', 'nexo-mcp' )
			);
		}

		$staged   = WorkingCopy::should_stage( $post, $input, $this->settings );
		$existing = $staged ? WorkingCopy::copy_of( $post ) : 0;
		$source   = $existing > 0 ? get_post( $existing ) : $post;

		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'nexo_mcp_draft_missing', __( 'No se pudo leer la copia de trabajo.', 'nexo-mcp' ) );
		}

		if ( (bool) $this->settings->get( 'drafts_only' ) && 'draft' !== $post->post_status && ! $staged ) {
			return new \WP_Error(
				'nexo_mcp_draft_required',
				__( 'El modo sólo borradores impide editar contenido publicado.', 'nexo-mcp' )
			);
		}

		foreach ( array_unique( array( $post->ID, $source->ID ) ) as $locked_id ) {
			$locked_post = get_post( $locked_id );
			$locked      = $locked_post instanceof \WP_Post ? ( $this->lock_check )( $locked_post ) : true;

			if ( is_wp_error( $locked ) ) {
				return $locked;
			}
		}

		return array(
			'staged'   => $staged,
			'existing' => $existing,
			'source'   => $source,
		);
	}

	/**
	 * Crea o reutiliza la copia si corresponde, respalda y guarda.
	 *
	 * @param \WP_Post                                             $post    Contenido indicado.
	 * @param array{staged: bool, existing: int, source: \WP_Post} $target  Resultado de {@see self::target()}.
	 * @param string                                               $content Contenido serializado nuevo.
	 *
	 * @return \WP_Post|\WP_Error El contenido escrito (la copia o el propio).
	 */
	public function write( \WP_Post $post, array $target, string $content ) {
		// Antes de la primera escritura el bloqueo pudo vencer y otro proceso tomarlo.
		$held = WorkingCopy::ensure_lock( (int) $post->ID );

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		$dest    = $post;
		$created = false;

		if ( $target['staged'] ) {
			$dest = $this->copies->obtain( $post );

			if ( is_wp_error( $dest ) ) {
				return $dest;
			}

			$created = 0 === $target['existing'];
		}

		// Sin respaldo no se escribe.
		if ( ! $this->backup->capture( $dest, $this->adapter ) ) {
			return $this->abort(
				$dest,
				$created,
				new \WP_Error(
					'nexo_mcp_builder_backup_failed',
					__( 'No fue posible respaldar el contenido; el cambio se canceló.', 'nexo-mcp' )
				)
			);
		}

		$saver = $this->saver;
		$saved = self::without_kses( static fn() => $saver( (int) $dest->ID, $content ) );

		if ( is_wp_error( $saved ) ) {
			return $this->abort( $dest, $created, $saved );
		}

		self::mark( $dest );
		$this->adapter->flush( $dest );

		return $dest;
	}

	/**
	 * Marca el contenido como página de Divi 5.
	 *
	 * @param \WP_Post $post Contenido escrito.
	 */
	public static function mark( \WP_Post $post ): void {
		update_post_meta( $post->ID, '_et_pb_use_builder', 'on' );
		update_post_meta( $post->ID, '_et_pb_use_divi_5', 'on' );
	}

	/**
	 * Campos comunes de la respuesta: `id`, `draft_of` (si es copia) y `preview_url`.
	 *
	 * @param \WP_Post $target Contenido escrito.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe( \WP_Post $target ): array {
		$out      = array( 'id' => (int) $target->ID );
		$original = WorkingCopy::original_of( $target );

		if ( $original > 0 ) {
			$out['draft_of'] = $original;
		}

		$out['preview_url'] = (string) get_preview_post_link( $target );

		return $out;
	}

	/**
	 * Devuelve el error y manda a la papelera la copia que esta llamada acaba de crear.
	 *
	 * @param \WP_Post  $target  Destino.
	 * @param bool      $created Si la copia la creó esta llamada.
	 * @param \WP_Error $error   Error a devolver.
	 */
	private function abort( \WP_Post $target, bool $created, \WP_Error $error ): \WP_Error {
		if ( $created ) {
			$discarded = $this->copies->discard( $target );

			if ( is_wp_error( $discarded ) ) {
				return WorkingCopy::orphan_error( $target, $error );
			}
		}

		return $error;
	}
}
