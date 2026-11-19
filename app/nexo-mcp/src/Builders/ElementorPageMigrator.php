<?php
/**
 * Migración de una página de Elementor 3 a elementos atómicos, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Migra la página entera con el mismo camino de escritura que {@see ElementorSectionEditor}.
 *
 * Orden: página de Elementor, destino (copia de trabajo), modo «sólo borradores»,
 * bloqueo de edición, lectura del árbol (el de la copia si ya existe, si no el del
 * original), requisitos de Elementor 4, migración, «nada que migrar», comprobación del
 * árbol migrado ({@see ElementorMigrationChecker}, que sólo admite v3 idénticos al
 * original) y topes. Sólo entonces se crea o reutiliza la copia, se respalda y se guarda
 * con {@see ElementorAdapter::save_tree()}. Un error antes de ese punto no escribe nada
 * ni crea copia. Nunca publica.
 */
final class ElementorPageMigrator {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Adaptador de Elementor.
	 *
	 * @var ElementorAdapter
	 */
	private ElementorAdapter $adapter;

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
	 * Guarda de esquema de Elementor.
	 *
	 * @var ElementorSchemaGuard
	 */
	private ElementorSchemaGuard $guard;

	/**
	 * Comprobación del bloqueo de edición: fn( \WP_Post ): true|\WP_Error.
	 *
	 * @var callable
	 */
	private $lock_check;

	/**
	 * Migración: fn( array $tree ): array{tree, migrated, kept_v3, unmapped}.
	 *
	 * @var callable
	 */
	private $migrate;

	/**
	 * Construye el migrador de páginas.
	 *
	 * @param Settings             $settings   Configuración.
	 * @param ElementorAdapter     $adapter    Adaptador de Elementor.
	 * @param BuilderBackup        $backup     Respaldos.
	 * @param WorkingCopy          $copies     Copias de trabajo.
	 * @param ElementorSchemaGuard $guard      Guarda de esquema.
	 * @param callable             $lock_check fn( \WP_Post ): true|\WP_Error.
	 * @param callable             $migrate    fn( array $tree ): array; la migración propiamente dicha.
	 */
	public function __construct(
		Settings $settings,
		ElementorAdapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		ElementorSchemaGuard $guard,
		callable $lock_check,
		callable $migrate
	) {
		$this->settings   = $settings;
		$this->adapter    = $adapter;
		$this->backup     = $backup;
		$this->copies     = $copies;
		$this->guard      = $guard;
		$this->lock_check = $lock_check;
		$this->migrate    = $migrate;
	}

	/**
	 * Migra la página.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros (`draft`).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function migrate( \WP_Post $post, array $input ) {
		// Todo el ciclo corre bajo el bloqueo del original, como en edit-elementor-section.
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				return $this->migrate_locked( $fresh, $input );
			}
		);
	}

	/**
	 * Migra ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function migrate_locked( \WP_Post $post, array $input ) {
		if ( ! $this->adapter->handles( $post ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_not_elementor',
				__( 'Esta página no está hecha con Elementor.', 'nexo-mcp' )
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

		$tree = $this->adapter->tree( $source );

		if ( null === $tree || array() === $tree ) {
			return new \WP_Error(
				'nexo_mcp_builder_unreadable',
				__( 'El documento de Elementor no pudo leerse.', 'nexo-mcp' )
			);
		}

		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = ( $this->migrate )( $tree );

		if ( 0 === (int) $result['migrated'] ) {
			return new \WP_Error(
				'nexo_mcp_nothing_to_migrate',
				__( 'La página no tiene elementos de Elementor 3 que se puedan convertir; no se cambió nada.', 'nexo-mcp' )
			);
		}

		$checked = ( new ElementorMigrationChecker( $this->guard ) )->check(
			$result['tree'],
			$tree,
			ElementorTreeValidator::node_cap( ElementorTree::count_nodes( $tree ) )
		);

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		return $this->write( $post, $staged, $existing, $checked, $result );
	}

	/**
	 * Crea o reutiliza la copia si corresponde, respalda y guarda.
	 *
	 * @param \WP_Post             $post     Contenido indicado.
	 * @param bool                 $staged   Si el cambio va a la copia de trabajo.
	 * @param int                  $existing Copia que ya existía, o 0.
	 * @param array<int, mixed>    $tree     Árbol migrado y comprobado.
	 * @param array<string, mixed> $result   Resultado de la migración.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( \WP_Post $post, bool $staged, int $existing, array $tree, array $result ) {
		// Antes de la primera escritura el bloqueo pudo vencer y otro proceso tomarlo.
		$held = WorkingCopy::ensure_lock( (int) $post->ID );

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		$target  = $post;
		$created = false;

		if ( $staged ) {
			$target = $this->copies->obtain( $post );

			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$created = 0 === $existing;
		}

		// Sin respaldo no se escribe: las revisiones de WordPress no cubren `post_meta`.
		if ( ! $this->backup->capture( $target, $this->adapter ) ) {
			return $this->abort(
				$target,
				$created,
				new \WP_Error(
					'nexo_mcp_builder_backup_failed',
					__( 'No fue posible respaldar el contenido; la migración se canceló.', 'nexo-mcp' )
				)
			);
		}

		$saved = $this->adapter->save_tree( $target, $tree );

		if ( is_wp_error( $saved ) ) {
			return $this->abort( $target, $created, $saved );
		}

		$this->adapter->invalidate_post( $target );

		$out      = array( 'id' => (int) $target->ID );
		$original = WorkingCopy::original_of( $target );

		if ( $original > 0 ) {
			$out['draft_of'] = $original;
		}

		$out['preview_url'] = (string) get_preview_post_link( $target );
		$out['migrated']    = (int) $result['migrated'];
		$out['kept_v3']     = $result['kept_v3'];
		$out['unmapped']    = $result['unmapped'];

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
