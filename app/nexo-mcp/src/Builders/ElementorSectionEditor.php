<?php
/**
 * Edición de secciones de una página de Elementor con elementos atómicos nuevos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Reemplaza, inserta o borra un nodo del documento, en la copia de trabajo.
 *
 * Orden: página de Elementor, plan (operación, nodo, posición), requisitos de
 * Elementor 4 y validación del subárbol nuevo (propia y de Elementor), armado con
 * ids que no chocan, empalme con prueba de que lo no tocado quedó igual, topes del
 * documento resultante, bloqueo y modo «sólo borradores». Sólo entonces se crea o
 * reutiliza la copia, se respalda y se guarda con {@see ElementorAdapter::save_tree()}
 * (nunca con `Document::save`, que reescribe y filtra el contenido v3 existente).
 * Un error antes de ese punto no escribe nada ni crea copia. Nunca publica.
 */
final class ElementorSectionEditor {

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
	 * Generador de ids para el armador; null usa el del editor.
	 *
	 * @var callable|null
	 */
	private $id_generator;

	/**
	 * Construye el editor.
	 *
	 * @param Settings             $settings     Configuración.
	 * @param ElementorAdapter     $adapter      Adaptador de Elementor.
	 * @param BuilderBackup        $backup       Respaldos.
	 * @param WorkingCopy          $copies       Copias de trabajo.
	 * @param ElementorSchemaGuard $guard        Guarda de esquema.
	 * @param callable             $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null        $id_generator fn(): string, para pruebas.
	 */
	public function __construct(
		Settings $settings,
		ElementorAdapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		ElementorSchemaGuard $guard,
		callable $lock_check,
		?callable $id_generator = null
	) {
		$this->settings     = $settings;
		$this->adapter      = $adapter;
		$this->backup       = $backup;
		$this->copies       = $copies;
		$this->guard        = $guard;
		$this->lock_check   = $lock_check;
		$this->id_generator = $id_generator;
	}

	/**
	 * Aplica la operación.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit( \WP_Post $post, array $input ) {
		// Todo el ciclo (leer el árbol, empalmar, crear la copia, guardar) corre bajo
		// el bloqueo del original: sin él, dos ediciones leen el mismo árbol y una
		// pisa a la otra, o ambas crean su copia. Si está ocupado no se escribe nada.
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				return $this->edit_locked( $fresh, $input );
			}
		);
	}

	/**
	 * Aplica la operación ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_locked( \WP_Post $post, array $input ) {
		if ( ! $this->adapter->handles( $post ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_not_elementor',
				__( 'Esta página no está hecha con Elementor.', 'nexo-mcp' )
			);
		}

		// Con copia de trabajo, el árbol sobre el que se trabaja es el de la copia
		// existente (puede tener nodos que el original no tiene). Sin copia, el del
		// original: la copia nace como clon suyo, y recién se crea al final.
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

		$operation = isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '';
		$plan      = ElementorSectionSplicer::plan(
			$tree,
			$operation,
			isset( $input['node_id'] ) && is_string( $input['node_id'] ) ? $input['node_id'] : '',
			isset( $input['position'] ) && is_string( $input['position'] ) ? $input['position'] : ''
		);

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$built = $this->build_new( $input, $tree, $plan );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$spliced = ElementorSectionSplicer::apply( $tree, $plan, $built );

		if ( is_wp_error( $spliced ) ) {
			return $spliced;
		}

		if ( 'delete' !== $operation ) {
			$limits = $this->check_limits( $spliced['tree'], ElementorTreeValidator::node_cap( ElementorTree::count_nodes( $tree ) ) );

			if ( is_wp_error( $limits ) ) {
				return $limits;
			}
		}

		return $this->write( $post, $staged, $existing, $operation, $spliced );
	}

	/**
	 * Valida y arma el subárbol nuevo; para `delete` comprueba que no venga.
	 *
	 * @param array<string, mixed> $input Parámetros.
	 * @param array<int, mixed>    $tree  Documento.
	 * @param array<string, mixed> $plan  Plan de la operación.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function build_new( array $input, array $tree, array $plan ) {
		if ( 'delete' === $plan['operation'] ) {
			if ( array_key_exists( 'elements', $input ) && null !== $input['elements'] ) {
				return new \WP_Error(
					'nexo_mcp_elementor_elements_forbidden',
					__( 'delete no recibe «elements»: sólo quita el nodo indicado.', 'nexo-mcp' )
				);
			}

			return array();
		}

		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$elements = $input['elements'] ?? null;
		// Un dato dinámico que el documento ya tiene (mismo id de nodo, mismo tipo, misma
		// prop, mismo valor) no se rechaza; uno nuevo o cambiado sí (se asigna con set-dynamic-tag).
		$checked = $this->guard->validator()->accepting_unchanged_dynamic( $tree )->validate(
			$elements,
			(string) $plan['parent_kind'],
			(int) $plan['base_depth'],
			(int) $plan['nodes_without'],
			ElementorTreeValidator::node_cap( ElementorTree::count_nodes( $tree ) )
		);

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		if ( ! is_array( $elements ) ) {
			return new \WP_Error( 'nexo_mcp_elementor_tree_invalid', 'elements: ' . __( 'debe ser una lista no vacía de elementos.', 'nexo-mcp' ) );
		}

		// Los datos dinámicos ya aprobados por el validador (idénticos a los guardados) no
		// pasan por los analizadores de Elementor, que los comparan con los tags que registra
		// el editor (y no todos los de texto, ver L5): se apartan y se devuelven tal cual
		// después del saneado. Son el valor guardado, nunca texto nuevo del cliente.
		$split = self::split_dynamic( $elements );

		$checked = $this->guard->validate( $split['elements'] );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// Lo que se guarda es lo saneado por Elementor, como en create-elementor-page
		// (que pasa por Document::save): nunca el texto crudo del cliente.
		$clean = $this->guard->sanitize( $split['elements'] );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$clean = self::restore_dynamic( $clean, $split['dynamic'] );

		$builder = new ElementorTreeBuilder( $this->id_generator );

		return $builder->reserve( ElementorTree::collect_ids( $tree ) )->build( $clean );
	}

	/**
	 * Aparta las props dinámicas de primer nivel de `settings` de una lista de nodos.
	 *
	 * @param array<int|string, mixed> $nodes Nodos del cliente (ya validados).
	 *
	 * @return array{elements: array<int|string, mixed>, dynamic: array<int|string, array{props: array<string, mixed>, children: array<int|string, mixed>}>}
	 */
	private static function split_dynamic( array $nodes ): array {
		$dynamic = array();

		foreach ( $nodes as $index => $node ) {
			$props = array();

			if ( is_array( $node ) && is_array( $node['settings'] ?? null ) ) {
				foreach ( $node['settings'] as $key => $value ) {
					if ( is_array( $value ) && 'dynamic' === ( $value['$$type'] ?? null ) ) {
						$props[ $key ] = $value;
						unset( $node['settings'][ $key ] );
					}
				}
			}

			$children = array();

			if ( is_array( $node ) && is_array( $node['elements'] ?? null ) ) {
				$inner            = self::split_dynamic( $node['elements'] );
				$node['elements'] = $inner['elements'];
				$children         = $inner['dynamic'];
			}

			if ( array() !== $props || array() !== $children ) {
				$dynamic[ $index ] = array(
					'props'    => $props,
					'children' => $children,
				);
			}

			$nodes[ $index ] = $node;
		}

		return array(
			'elements' => $nodes,
			'dynamic'  => $dynamic,
		);
	}

	/**
	 * Devuelve a su sitio las props dinámicas apartadas por {@see self::split_dynamic()}.
	 *
	 * @param array<int|string, mixed> $nodes   Nodos saneados (misma forma y orden que los apartados).
	 * @param array<int|string, mixed> $dynamic Props apartadas.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function restore_dynamic( array $nodes, array $dynamic ): array {
		foreach ( $dynamic as $index => $kept ) {
			if ( ! isset( $nodes[ $index ] ) || ! is_array( $nodes[ $index ] ) ) {
				continue;
			}

			$settings                    = is_array( $nodes[ $index ]['settings'] ?? null ) ? $nodes[ $index ]['settings'] : array();
			$nodes[ $index ]['settings'] = array_merge( $settings, $kept['props'] );

			if ( array() !== $kept['children'] && is_array( $nodes[ $index ]['elements'] ?? null ) ) {
				$nodes[ $index ]['elements'] = self::restore_dynamic( $nodes[ $index ]['elements'], $kept['children'] );
			}
		}

		return $nodes;
	}

	/**
	 * Topes del documento resultante (nodos y bytes).
	 *
	 * @param array<int, mixed> $tree      Documento resultante.
	 * @param int               $max_nodes Tope de elementos: `max( 200, nodos actuales )`.
	 *
	 * @return true|\WP_Error
	 */
	private function check_limits( array $tree, int $max_nodes ) {
		if ( ElementorTree::count_nodes( $tree ) > $max_nodes ) {
			return new \WP_Error(
				'nexo_mcp_elementor_too_many_nodes',
				sprintf(
					/* translators: %d: máximo de elementos. */
					__( 'La página resultante superaría el máximo de %d elementos; no se cambia nada.', 'nexo-mcp' ),
					$max_nodes
				)
			);
		}

		$encoded = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || strlen( $encoded ) > ElementorTreeValidator::MAX_BYTES ) {
			return new \WP_Error(
				'nexo_mcp_elementor_too_large',
				__( 'La página resultante pesaría más de 3 MB; no se cambia nada.', 'nexo-mcp' )
			);
		}

		return true;
	}

	/**
	 * Crea o reutiliza la copia si corresponde, respalda y guarda.
	 *
	 * @param \WP_Post             $post      Contenido indicado.
	 * @param bool                 $staged    Si el cambio va a la copia de trabajo.
	 * @param int                  $existing  Copia que ya existía, o 0.
	 * @param string               $operation Operación.
	 * @param array<string, mixed> $spliced   Resultado del empalme.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( \WP_Post $post, bool $staged, int $existing, string $operation, array $spliced ) {
		// Todo lo largo (validar, armar, empalmar) ya pasó: antes de la primera escritura,
		// el bloqueo pudo vencer y otro proceso tomarlo.
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
					__( 'No fue posible respaldar el contenido; la edición se canceló.', 'nexo-mcp' )
				)
			);
		}

		$saved = $this->adapter->save_tree( $target, $spliced['tree'] );

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
		$out['operation']   = $operation;

		if ( 'delete' !== $operation ) {
			$out['node_ids'] = $spliced['node_ids'];
		}

		if ( 'insert' !== $operation ) {
			$out['removed'] = $spliced['removed'];
		}

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
