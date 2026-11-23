<?php
/**
 * Asignación de un dynamic tag a una prop de un elemento atómico, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Escribe `{"$$type":"dynamic","value":{name, group, settings}}` en una prop de un nodo.
 *
 * Mismo camino que {@see ElementorSectionEditor}: todo corre bajo el bloqueo del
 * original; destino (copia o borrador), bloqueo de edición y modo «sólo borradores»
 * antes de leer; luego se valida (Editor V4, nodo, destino y catálogo) y sólo entonces
 * se crea o reutiliza la copia, se respalda y se guarda con
 * {@see ElementorAdapter::save_tree()}. Un error antes de ese punto no escribe nada ni
 * crea copia. Lo que se guarda es el valor normalizado que devuelve
 * {@see DynamicTagCatalog::validate()}, nunca el del cliente. Nunca publica.
 */
final class DynamicTagAssigner {

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
	 * Catálogo cerrado de tags.
	 *
	 * @var DynamicTagCatalog
	 */
	private DynamicTagCatalog $catalog;

	/**
	 * Construye el asignador.
	 *
	 * @param Settings             $settings   Configuración.
	 * @param ElementorAdapter     $adapter    Adaptador de Elementor.
	 * @param BuilderBackup        $backup     Respaldos.
	 * @param WorkingCopy          $copies     Copias de trabajo.
	 * @param ElementorSchemaGuard $guard      Guarda de esquema.
	 * @param callable             $lock_check fn( \WP_Post ): true|\WP_Error.
	 * @param DynamicTagCatalog    $catalog    Catálogo de tags.
	 */
	public function __construct(
		Settings $settings,
		ElementorAdapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		ElementorSchemaGuard $guard,
		callable $lock_check,
		DynamicTagCatalog $catalog
	) {
		$this->settings   = $settings;
		$this->adapter    = $adapter;
		$this->backup     = $backup;
		$this->copies     = $copies;
		$this->guard      = $guard;
		$this->lock_check = $lock_check;
		$this->catalog    = $catalog;
	}

	/**
	 * Asigna el tag.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `node_id`, `prop`, `tag`, `draft`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function assign( \WP_Post $post, array $input ) {
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				return $this->assign_locked( $fresh, $input );
			}
		);
	}

	/**
	 * Asigna ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function assign_locked( \WP_Post $post, array $input ) {
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

		$prepared = $this->prepare( $tree, $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return $this->write( $post, $staged, $existing, $prepared );
	}

	/**
	 * Valida la petición y arma el documento con la prop dinámica.
	 *
	 * @param array<int, mixed>    $tree  Documento sobre el que se trabaja.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array{tree: array<int, mixed>, node_id: string, prop: string, tag: array<string, mixed>}|\WP_Error
	 */
	private function prepare( array $tree, array $input ) {
		$node_id = isset( $input['node_id'] ) && is_string( $input['node_id'] ) ? $input['node_id'] : '';
		$prop    = isset( $input['prop'] ) && is_string( $input['prop'] ) ? $input['prop'] : '';
		$tag     = $input['tag'] ?? null;

		if ( '' === $node_id ) {
			return new \WP_Error( 'nexo_mcp_builder_node_missing', __( 'Debes indicar el elemento.', 'nexo-mcp' ) );
		}

		if ( '' === $prop ) {
			return new \WP_Error( 'nexo_mcp_dynamic_value_invalid', __( 'Debes indicar la propiedad («prop»).', 'nexo-mcp' ) );
		}

		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$value = $this->tag_value( $tag );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$found = ElementorTree::locate( $tree, $node_id );

		if ( null === $found ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ElementorTree::count_id( $tree, $node_id ) > 1 ) {
			return new \WP_Error(
				'nexo_mcp_elementor_node_ambiguous',
				__( 'Ese id aparece más de una vez en el documento y no se sabe a cuál se refiere; no se cambia nada.', 'nexo-mcp' )
			);
		}

		$element = $found['element'];
		$type    = 'widget' === ( $element['elType'] ?? null ) ? (string) ( $element['widgetType'] ?? '' ) : (string) ( $element['elType'] ?? '' );
		$checked = $this->catalog->validate( $type, $prop, $value );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$siblings = &ElementorTree::children_at( $tree, $found['parent_path'] );

		$siblings[ $found['index'] ]['settings'] = array_merge(
			is_array( $siblings[ $found['index'] ]['settings'] ?? null ) ? $siblings[ $found['index'] ]['settings'] : array(),
			array( $prop => $checked )
		);

		return array(
			'tree'    => $tree,
			'node_id' => $node_id,
			'prop'    => $prop,
			'tag'     => $checked['value'],
		);
	}

	/**
	 * Arma la prop dinámica a partir de `{name, settings?}`; el grupo lo pone el catálogo.
	 *
	 * @param mixed $tag Valor recibido.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function tag_value( $tag ) {
		$keys = is_array( $tag ) ? array_map( 'strval', array_keys( $tag ) ) : array();

		if ( ! is_array( $tag ) || array_is_list( $tag ) || ! is_string( $tag['name'] ?? null ) || array() !== array_diff( $keys, array( 'name', 'settings' ) ) ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_value_invalid',
				__( '«tag» debe ser un objeto {name, settings?} (el grupo lo pone el plugin).', 'nexo-mcp' )
			);
		}

		return array(
			'$$type' => 'dynamic',
			'value'  => array(
				'name'     => $tag['name'],
				'group'    => DynamicTagCatalog::definitions()[ $tag['name'] ]['group'] ?? '',
				'settings' => $tag['settings'] ?? array(),
			),
		);
	}

	/**
	 * Crea o reutiliza la copia si corresponde, respalda y guarda.
	 *
	 * @param \WP_Post             $post     Contenido indicado.
	 * @param bool                 $staged   Si el cambio va a la copia de trabajo.
	 * @param int                  $existing Copia que ya existía, o 0.
	 * @param array<string, mixed> $prepared Documento y datos de la respuesta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( \WP_Post $post, bool $staged, int $existing, array $prepared ) {
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
					__( 'No fue posible respaldar el contenido; el cambio se canceló.', 'nexo-mcp' )
				)
			);
		}

		$saved = $this->adapter->save_tree( $target, $prepared['tree'] );

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
		$out['node_id']     = $prepared['node_id'];
		$out['prop']        = $prepared['prop'];
		$out['tag']         = $prepared['tag'];

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
