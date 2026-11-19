<?php
/**
 * Operaciones de lectura y edición sobre constructores visuales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Punto único de entrada del módulo de constructores.
 *
 * Concentra las comprobaciones que deben ocurrir sí o sí antes de tocar el
 * contenido: que el bloqueo de edición del propietario se respete, que exista
 * un respaldo restaurable y que el texto entrante venga sanitizado.
 */
final class BuilderGateway {

	/**
	 * Longitud máxima admitida para el texto de un nodo.
	 */
	private const MAX_TEXT = 65535;

	/**
	 * Registro de adaptadores.
	 *
	 * @var BuilderRegistry
	 */
	private BuilderRegistry $registry;

	/**
	 * Respaldo previo a la escritura.
	 *
	 * @var BuilderBackup
	 */
	private BuilderBackup $backup;

	/**
	 * Configuración: la necesita la edición de secciones para decidir la copia de trabajo.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Escritor del sistema de diseño (bloqueo, respaldo, deshacer); se crea al usarlo.
	 *
	 * @var DesignSystemWriter|null
	 */
	private ?DesignSystemWriter $design_writer;

	/**
	 * Operaciones de clases globales; se crean al usarlas.
	 *
	 * @var DesignClassOperations|null
	 */
	private ?DesignClassOperations $design_classes;

	/**
	 * Operaciones de variables globales; se crean al usarlas.
	 *
	 * @var DesignVariableOperations|null
	 */
	private ?DesignVariableOperations $design_variables;

	/**
	 * Operaciones de colores y tipografía del Kit; se crean al usarlas.
	 *
	 * @var DesignKitOperations|null
	 */
	private ?DesignKitOperations $design_kit;

	/**
	 * Construye el gateway.
	 *
	 * Las cuatro últimas piezas del sistema de diseño se inyectan sólo en pruebas: por
	 * omisión se crean con los almacenes reales de Elementor, y comparten el escritor.
	 *
	 * @param BuilderRegistry|null          $registry         Registro de adaptadores.
	 * @param BuilderBackup|null            $backup           Gestor de respaldos.
	 * @param Settings|null                 $settings         Configuración operativa.
	 * @param DesignSystemWriter|null       $design_writer    Escritor del sistema de diseño.
	 * @param DesignClassOperations|null    $design_classes   Operaciones de clases globales.
	 * @param DesignVariableOperations|null $design_variables Operaciones de variables globales.
	 * @param DesignKitOperations|null      $design_kit       Operaciones del Kit.
	 */
	public function __construct(
		?BuilderRegistry $registry = null,
		?BuilderBackup $backup = null,
		?Settings $settings = null,
		?DesignSystemWriter $design_writer = null,
		?DesignClassOperations $design_classes = null,
		?DesignVariableOperations $design_variables = null,
		?DesignKitOperations $design_kit = null
	) {
		$this->registry         = $registry ?? new BuilderRegistry();
		$this->backup           = $backup ?? new BuilderBackup();
		$this->settings         = $settings ?? new Settings();
		$this->design_writer    = $design_writer;
		$this->design_classes   = $design_classes;
		$this->design_variables = $design_variables;
		$this->design_kit       = $design_kit;
	}

	/**
	 * Describe la estructura editable de un contenido.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_structure( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			// Sin este aviso, una página de Avada o Bricks se anunciaba como
			// «contenido corriente» y el cliente intentaba editarla con las
			// herramientas normales: en el mejor caso no pasaba nada, en el peor
			// se sobrescribía el layout. Decir qué constructor es evita las dos.
			$ajeno = ( new ForeignBuilderDetector() )->detect( $post );

			if ( null !== $ajeno ) {
				return array(
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'builder' => $ajeno,
					'notice'  => sprintf(
						/* translators: %s: nombre del constructor detectado. */
						__( 'Esta página está hecha con %s, que el conector todavía no sabe editar. No intentes escribir su contenido: edítala desde su propio constructor.', 'nexo-mcp' ),
						$ajeno
					),
					'nodes'   => array(),
				);
			}

			return array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'builder' => null,
				'notice'  => __( 'Este contenido no usa un constructor reconocido; se edita con las herramientas de contenido habituales.', 'nexo-mcp' ),
				'nodes'   => array(),
			);
		}

		$read  = $adapter->read_nodes( $post );
		$nodes = array_map(
			static fn( BuilderNode $node ): array => $node->to_array(),
			$read
		);

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'builder'  => $adapter->slug(),
			'label'    => $adapter->label(),
			'editable' => count(
				array_filter( $read, static fn( BuilderNode $node ): bool => $node->editable )
			),
			'backup'   => $this->backup->describe( $post->ID ),
			'nodes'    => $nodes,
		);
	}

	/**
	 * Lee el sistema de diseño de Elementor: clases, variables, colores y tipografía.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_design_system() {
		return ( new ElementorDesignSystem() )->read();
	}

	/**
	 * Deshace el último cambio del sistema de diseño de Elementor.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo_design_system_change() {
		return $this->design_writer()->undo();
	}

	/**
	 * Crea una clase global de Elementor.
	 *
	 * @param array<string, mixed> $input `{label, variants}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_global_class( array $input ) {
		return $this->design_classes()->create( $input );
	}

	/**
	 * Modifica una clase global de Elementor.
	 *
	 * @param array<string, mixed> $input `{id, label?, variants?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_global_class( array $input ) {
		return $this->design_classes()->update( $input );
	}

	/**
	 * Borra una clase global de Elementor.
	 *
	 * @param array<string, mixed> $input `{id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_global_class( array $input ) {
		return $this->design_classes()->delete( $this->without_confirmation( $input ) );
	}

	/**
	 * Crea una variable global de Elementor.
	 *
	 * @param array<string, mixed> $input `{type, label, value}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_design_variable( array $input ) {
		return $this->design_variables()->create( $input );
	}

	/**
	 * Modifica una variable global de Elementor.
	 *
	 * @param array<string, mixed> $input `{id, label?, value?, type?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_design_variable( array $input ) {
		return $this->design_variables()->update( $input );
	}

	/**
	 * Borra una variable global de Elementor.
	 *
	 * @param array<string, mixed> $input `{id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_design_variable( array $input ) {
		return $this->design_variables()->delete( $this->without_confirmation( $input ) );
	}

	/**
	 * Modifica los colores y la tipografía globales del Kit activo.
	 *
	 * @param array<string, mixed> $input `{colors?, typography?, delete?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_kit_globals( array $input ) {
		return $this->design_kit()->update( $this->without_confirmation( $input ) );
	}

	/**
	 * Quita la palabra de confirmación: ya la comprobó `IrreversibleActions` y no es un parámetro de la operación.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>
	 */
	private function without_confirmation( array $input ): array {
		unset( $input['confirmation'] );

		return $input;
	}

	/**
	 * Escritor compartido por todas las escrituras del sistema de diseño.
	 */
	private function design_writer(): DesignSystemWriter {
		$this->design_writer ??= new DesignSystemWriter();

		return $this->design_writer;
	}

	/**
	 * Operaciones de clases globales.
	 */
	private function design_classes(): DesignClassOperations {
		$this->design_classes ??= new DesignClassOperations( $this->design_writer() );

		return $this->design_classes;
	}

	/**
	 * Operaciones de variables globales.
	 */
	private function design_variables(): DesignVariableOperations {
		$this->design_variables ??= new DesignVariableOperations( $this->design_writer() );

		return $this->design_variables;
	}

	/**
	 * Operaciones del Kit.
	 */
	private function design_kit(): DesignKitOperations {
		$this->design_kit ??= new DesignKitOperations( $this->design_writer() );

		return $this->design_kit;
	}

	/**
	 * Crea una página de Elementor 4 en borrador a partir de un árbol atómico.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_elementor_page( array $input ) {
		return ( new ElementorPageCreator() )->create( $input );
	}

	/**
	 * Reemplaza, inserta o borra un nodo de una página de Elementor.
	 *
	 * Lo nuevo es siempre atómico y el cambio va a la copia de trabajo de las
	 * páginas publicadas. Ver {@see ElementorSectionEditor}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit_elementor_section( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Editar la copia es editar el original: el permiso se pide sobre el original.
		if ( ! current_user_can( 'edit_post', WorkingCopy::original_id_for( (int) $post->ID ) ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_forbidden',
				__( 'No tienes permiso sobre este contenido.', 'nexo-mcp' )
			);
		}

		$editor = new ElementorSectionEditor(
			$this->settings,
			new ElementorAdapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			new ElementorSchemaGuard(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked )
		);

		return $editor->edit(
			$post,
			array(
				'operation' => isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '',
				'node_id'   => isset( $input['node_id'] ) && is_string( $input['node_id'] ) ? sanitize_text_field( $input['node_id'] ) : '',
				'position'  => isset( $input['position'] ) && is_string( $input['position'] ) ? $input['position'] : '',
				'elements'  => $input['elements'] ?? null,
				'draft'     => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Reemplaza el texto de un nodo del constructor.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_node_text( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->locked( $post, fn( \WP_Post $fresh ) => $this->update_node_text_locked( $fresh, $input ) );
	}

	/**
	 * Versión de update_node_text con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido ya resuelto.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_node_text_locked( \WP_Post $post, array $input ) {
		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_unknown',
				__( 'Este contenido no usa un constructor reconocido.', 'nexo-mcp' )
			);
		}

		$plan = $this->plan_target( $post, $input );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$node_id = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a editar.', 'nexo-mcp' )
			);
		}

		$text = (string) ( $input['text'] ?? '' );

		if ( strlen( $text ) > self::MAX_TEXT ) {
			return new \WP_Error(
				'nexo_mcp_builder_text_too_long',
				__( 'El texto excede la longitud admitida.', 'nexo-mcp' )
			);
		}

		// El nodo se valida antes de tocar el respaldo. De lo contrario una
		// operación condenada a fallar —un elemento inexistente, o uno vedado por
		// admitir código— sobrescribiría el último estado bueno con el actual y
		// dejaría al propietario sin punto de retorno.
		$target = $this->find_node( $adapter, $plan['source'], $node_id );

		if ( null === $target ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ! $target->editable ) {
			return new \WP_Error(
				'nexo_mcp_builder_type_forbidden',
				'' !== $target->reason
					? $target->reason
					: __( 'Ese elemento no admite edición.', 'nexo-mcp' )
			);
		}

		// El marcado se restringe al mismo conjunto que el resto del conector,
		// que ya excluye script, iframe y atributos de evento.
		$text = wp_kses_post( $text );

		// Todo lo que podía fallar ya pasó: recién ahora se crea la copia.
		$dest = $this->destination( $post, $plan );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		$target_post = $dest['post'];

		// Sin respaldo no se escribe: las revisiones de WordPress no cubren
		// `post_meta` y una escritura fallida dejaría la página irrecuperable.
		if ( ! $this->backup->capture( $target_post, $adapter ) ) {
			return $this->abort( $dest, $this->backup_failed() );
		}

		$result = $adapter->update_node_text( $target_post, $node_id, $text );

		if ( is_wp_error( $result ) ) {
			return $this->abort( $dest, $result );
		}

		$adapter->flush( $target_post );

		return $this->with_draft_of(
			array(
				'id'      => $target_post->ID,
				'builder' => $adapter->slug(),
				'node_id' => $node_id,
				'updated' => true,
				'backup'  => $this->backup->describe( $target_post->ID ),
			),
			$target_post
		);
	}

	/**
	 * Reemplaza un atributo de un nodo del constructor.
	 *
	 * Sigue el mismo orden que la edición de texto y por el mismo motivo: se
	 * comprueba todo lo que puede fallar —el nodo, el atributo, el valor— antes
	 * de tocar el respaldo. Al revés, una operación condenada a fallar
	 * sobrescribiría el último estado bueno y dejaría al propietario sin retorno.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_node_attribute( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->locked( $post, fn( \WP_Post $fresh ) => $this->update_node_attribute_locked( $fresh, $input ) );
	}

	/**
	 * Versión de update_node_attribute con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido ya resuelto.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_node_attribute_locked( \WP_Post $post, array $input ) {
		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_unknown',
				__( 'Este contenido no usa un constructor reconocido.', 'nexo-mcp' )
			);
		}

		$plan = $this->plan_target( $post, $input );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$node_id = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a editar.', 'nexo-mcp' )
			);
		}

		$attribute = isset( $input['attribute'] ) ? sanitize_key( (string) $input['attribute'] ) : '';

		$target = $this->find_node( $adapter, $plan['source'], $node_id );

		if ( null === $target ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		// Aquí no se mira `editable`: ese campo dice si el TEXTO se puede tocar, y
		// una imagen no tiene texto pero sí atributos. Lo que decide es la
		// allowlist de atributos, que además excluye los tipos que admiten código.
		if ( ! EditableAttributes::allows( $adapter->slug(), $target->type, $attribute ) ) {
			$admitidos = EditableAttributes::for_type( $adapter->slug(), $target->type );

			return new \WP_Error(
				'nexo_mcp_builder_attr_denied',
				array() === $admitidos
					? sprintf(
						/* translators: %s: tipo del elemento. */
						__( 'El elemento «%s» no admite editar atributos, sólo su texto.', 'nexo-mcp' ),
						$target->type
					)
					: sprintf(
						/* translators: 1: tipo del elemento, 2: lista de atributos admitidos. */
						__( 'El elemento «%1$s» no admite ese atributo. Admite: %2$s.', 'nexo-mcp' ),
						$target->type,
						implode( ', ', $admitidos )
					)
			);
		}

		$value = $this->validate_attribute( $attribute, $input );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		// Todo lo que podía fallar ya pasó: recién ahora se crea la copia.
		$dest = $this->destination( $post, $plan );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		$target_post = $dest['post'];

		// Sin respaldo no se escribe: las revisiones de WordPress no cubren
		// `post_meta` y una escritura fallida dejaría la página irrecuperable.
		if ( ! $this->backup->capture( $target_post, $adapter ) ) {
			return $this->abort( $dest, $this->backup_failed() );
		}

		$result = $adapter->update_node_attribute( $target_post, $node_id, $attribute, $value );

		if ( is_wp_error( $result ) ) {
			return $this->abort( $dest, $result );
		}

		$adapter->flush( $target_post );

		return $this->with_draft_of(
			array(
				'id'        => $target_post->ID,
				'builder'   => $adapter->slug(),
				'node_id'   => $node_id,
				'attribute' => $attribute,
				'updated'   => true,
				'backup'    => $this->backup->describe( $target_post->ID ),
			),
			$target_post
		);
	}

	/**
	 * Duplica un elemento de la página junto al original o a otro elemento.
	 *
	 * Mismo orden que las ediciones: todo lo que puede fallar se comprueba al
	 * preparar la copia, y recién entonces se respalda y se guarda.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit_structure( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->locked( $post, fn( \WP_Post $fresh ) => $this->edit_structure_locked( $fresh, $input ) );
	}

	/**
	 * Versión de edit_structure con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido ya resuelto.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_structure_locked( \WP_Post $post, array $input ) {
		$adapter = $this->registry->resolve( $post );

		if ( ! $adapter instanceof StructureAdapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_unsupported',
				__( 'Por ahora sólo se pueden duplicar elementos en páginas de Elementor.', 'nexo-mcp' )
			);
		}

		$plan = $this->plan_target( $post, $input );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$node_id  = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';
		$target   = isset( $input['target'] ) ? sanitize_text_field( (string) $input['target'] ) : '';
		$position = 'before' === ( $input['position'] ?? 'after' ) ? 'before' : 'after';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a duplicar.', 'nexo-mcp' )
			);
		}

		$prepared = $adapter->prepare_duplicate( $plan['source'], $node_id, $target, $position );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		// Todo lo que podía fallar ya pasó: recién ahora se crea la copia.
		$dest = $this->destination( $post, $plan );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		$target_post = $dest['post'];

		if ( ! $this->backup->capture( $target_post, $adapter ) ) {
			return $this->abort( $dest, $this->backup_failed() );
		}

		$saved = $adapter->save_tree( $target_post, $prepared['tree'] );

		if ( is_wp_error( $saved ) ) {
			return $this->abort( $dest, $saved );
		}

		$adapter->flush( $target_post );

		return $this->with_draft_of(
			array(
				'id'      => $target_post->ID,
				'created' => array_map(
					static fn( BuilderNode $node ): array => $node->to_array(),
					$prepared['created']
				),
				'backup'  => $this->backup->describe( $target_post->ID ),
			),
			$target_post
		);
	}

	/**
	 * Convierte el valor recibido en algo seguro de escribir.
	 *
	 * @param string               $attribute Atributo solicitado.
	 * @param array<string, mixed> $input     Parámetros de la herramienta.
	 *
	 * @return mixed|\WP_Error
	 */
	private function validate_attribute( string $attribute, array $input ) {
		switch ( $attribute ) {
			case EditableAttributes::LINK:
				return AttributeValue::link( (string) ( $input['value'] ?? '' ) );

			case EditableAttributes::IMAGE:
				// Se espera el identificador de la biblioteca, nunca una URL: una
				// dirección arbitraria cargaría un recurso de un tercero en la
				// página del propietario.
				return AttributeValue::image( (int) ( $input['value'] ?? 0 ) );

			case EditableAttributes::ALT:
				return AttributeValue::alt( (string) ( $input['value'] ?? '' ) );

			default:
				return new \WP_Error(
					'nexo_mcp_builder_attr_unknown',
					__( 'Atributo no reconocido. Se admiten: link, image y alt.', 'nexo-mcp' )
				);
		}
	}

	/**
	 * Restaura el último estado respaldado.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_backup( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->locked( $post, fn( \WP_Post $fresh ) => $this->restore_backup_locked( $fresh, $input ) );
	}

	/**
	 * Versión de restore_backup con el bloqueo del original en mano.
	 *
	 * Restaurar es una edición más, y «el último estado respaldado» de una página con
	 * copia de trabajo es el de la copia:
	 *
	 * - Con el id de la copia, de un borrador, o con el de una página publicada que ya
	 *   tiene copia, se restaura el respaldo propio de la copia (o del borrador), en su
	 *   sitio: deshace la última edición de la copia. No se captura antes de escribir:
	 *   si se capturara, un reintento restauraría lo que acaba de restaurar y la
	 *   edición anterior se perdería. Restaurar dos veces deja el mismo estado.
	 * - Con el id de una página publicada que no tiene copia se usa el respaldo del
	 *   original (el estado previo a su última edición en vivo o a su última
	 *   publicación) y se restaura en una copia nueva, nunca en vivo. La copia recién
	 *   creada es un clon del original y no tiene ediciones que perder, así que tampoco
	 *   se captura. Sin respaldo del original no se crea la copia.
	 *
	 * @param \WP_Post             $post  Contenido ya resuelto.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_backup_locked( \WP_Post $post, array $input ) {
		$plan = $this->plan_target( $post, $input );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$adapter = $this->registry->resolve( $post );
		$from    = 0;
		$dest    = array(
			'post'    => $plan['source'],
			'created' => false,
		);

		if ( $plan['staged'] && 0 === $plan['existing'] ) {
			// Sin copia: el respaldo es el del original y va a una copia nueva.
			if ( null === $adapter || ! $this->backup->exists( (int) $post->ID ) ) {
				return new \WP_Error(
					'nexo_mcp_builder_no_backup',
					__( 'Este contenido no tiene un respaldo disponible.', 'nexo-mcp' )
				);
			}

			$from = (int) $post->ID;
			$dest = $this->destination( $post, $plan );

			if ( is_wp_error( $dest ) ) {
				return $dest;
			}
		} else {
			// En su sitio (copia, borrador o publicada con copia): el respaldo propio del destino.
			$held = WorkingCopy::ensure_lock( (int) $post->ID );

			if ( is_wp_error( $held ) ) {
				return $held;
			}
		}

		$target_post = $dest['post'];
		$restored    = $this->backup->restore( $target_post, $from );

		if ( is_wp_error( $restored ) ) {
			return $this->abort( $dest, $restored );
		}

		if ( null !== $adapter ) {
			$adapter->flush( $target_post );
		}

		return $this->with_draft_of(
			array(
				'id'       => $target_post->ID,
				'restored' => true,
			),
			$target_post
		);
	}

	/**
	 * Decide el destino de una edición y hace las comprobaciones previas.
	 *
	 * No crea nada. `source` es el contenido sobre el que se valida: la copia
	 * ya existente si la hay (puede tener nodos que el original no tiene) o el
	 * propio contenido (la copia nace como clon suyo).
	 *
	 * @param \WP_Post             $post  Contenido indicado, releído dentro del bloqueo.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array{source: \WP_Post, staged: bool, existing: int}|\WP_Error
	 */
	private function plan_target( \WP_Post $post, array $input ) {
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

		foreach ( array_unique( array( (int) $post->ID, (int) $source->ID ) ) as $id ) {
			$candidate = get_post( $id );
			$locked    = $candidate instanceof \WP_Post ? $this->locked_by_other( $candidate ) : true;

			if ( is_wp_error( $locked ) ) {
				return $locked;
			}
		}

		return array(
			'source'   => $source,
			'staged'   => $staged,
			'existing' => $existing,
		);
	}

	/**
	 * Entrega el contenido que se va a escribir: la copia (creada o reutilizada) o el propio.
	 *
	 * Se llama sólo cuando la entrada ya se validó, para que un rechazo no cree copia.
	 *
	 * @param \WP_Post                                             $post Contenido indicado.
	 * @param array{source: \WP_Post, staged: bool, existing: int} $plan Destino decidido.
	 *
	 * @return array{post: \WP_Post, created: bool}|\WP_Error
	 */
	private function destination( \WP_Post $post, array $plan ) {
		// Primera escritura: el bloqueo pudo vencer mientras se validaba.
		$held = WorkingCopy::ensure_lock( (int) $post->ID );

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		if ( ! $plan['staged'] ) {
			return array(
				'post'    => $post,
				'created' => false,
			);
		}

		$copy = ( new WorkingCopy( $this->settings ) )->obtain( $post );

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		return array(
			'post'    => $copy,
			'created' => 0 === $plan['existing'],
		);
	}

	/**
	 * Devuelve el error y manda a la papelera la copia que esta llamada acaba de crear.
	 *
	 * @param array{post: \WP_Post, created: bool} $dest  Destino.
	 * @param \WP_Error                            $error Error a devolver.
	 */
	private function abort( array $dest, \WP_Error $error ): \WP_Error {
		if ( $dest['created'] ) {
			$discarded = ( new WorkingCopy( $this->settings ) )->discard( $dest['post'] );

			if ( is_wp_error( $discarded ) ) {
				return WorkingCopy::orphan_error( $dest['post'], $error );
			}
		}

		return $error;
	}

	/**
	 * Error de respaldo fallido.
	 */
	private function backup_failed(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_builder_backup_failed',
			__( 'No fue posible respaldar el contenido; la edición se canceló.', 'nexo-mcp' )
		);
	}

	/**
	 * Agrega `draft_of` a la respuesta si el contenido editado es una copia de trabajo.
	 *
	 * @param array<string, mixed> $out  Respuesta.
	 * @param \WP_Post             $post Contenido editado.
	 *
	 * @return array<string, mixed>
	 */
	private function with_draft_of( array $out, \WP_Post $post ): array {
		$original = WorkingCopy::original_of( $post );

		if ( $original > 0 ) {
			$out['draft_of'] = $original;
		}

		return $out;
	}

	/**
	 * Localiza un nodo dentro del contenido.
	 *
	 * @param BuilderAdapter $adapter Constructor que gobierna el contenido.
	 * @param \WP_Post       $post    Contenido consultado.
	 * @param string         $node_id Identificador buscado.
	 */
	private function find_node( BuilderAdapter $adapter, \WP_Post $post, string $node_id ): ?BuilderNode {
		foreach ( $adapter->read_nodes( $post ) as $node ) {
			if ( $node->id === $node_id ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Corre una escritura bajo el bloqueo del original del contenido.
	 *
	 * Las ediciones por el ID de la copia y por el del original compiten por el
	 * mismo bloqueo. Si está ocupado no se escribe nada ({@see WorkingCopy::with_lock()}).
	 *
	 * @param \WP_Post $post Contenido indicado.
	 * @param callable $work  Escritura: fn( \WP_Post $fresco ): array|\WP_Error.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function locked( \WP_Post $post, callable $work ) {
		return WorkingCopy::with_lock(
			(int) $post->ID,
			static function () use ( $post, $work ) {
				// El árbol del constructor vive en `post_meta` y el objeto recibido se
				// leyó antes de esperar: dentro del bloqueo se relee todo.
				$fresh = get_post( $post->ID );

				return $fresh instanceof \WP_Post
					? $work( $fresh )
					: new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
			}
		);
	}

	/**
	 * Obtiene el contenido indicado comprobando que sea editable.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_post( array $input ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( 0 === $post_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_invalid_id',
				__( 'Debes indicar el contenido.', 'nexo-mcp' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'nexo_mcp_builder_not_found',
				__( 'El contenido no existe.', 'nexo-mcp' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_forbidden',
				__( 'No tienes permiso sobre este contenido.', 'nexo-mcp' )
			);
		}

		return $post;
	}

	/**
	 * Rechaza la escritura si otro usuario tiene el contenido abierto.
	 *
	 * El constructor guarda el árbol completo al cerrar, de modo que escribir
	 * mientras alguien edita significa que uno de los dos cambios se pierde.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 *
	 * @return true|\WP_Error
	 */
	private function locked_by_other( \WP_Post $post ) {
		$lock = (string) get_post_meta( $post->ID, '_edit_lock', true );

		if ( '' === $lock ) {
			return true;
		}

		$parts = explode( ':', $lock );
		$time  = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$owner = isset( $parts[1] ) ? (int) $parts[1] : 0;

		/** Ventana de vigencia del bloqueo de edición de WordPress. */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro del núcleo de WordPress.

		if ( 0 < $owner && get_current_user_id() !== $owner && $window > ( time() - $time ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_locked',
				__( 'Otro usuario tiene este contenido abierto en el editor.', 'nexo-mcp' )
			);
		}

		return true;
	}
}
