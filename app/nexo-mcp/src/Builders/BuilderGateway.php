<?php
/**
 * Operaciones de lectura y edición sobre constructores visuales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Authorization\PermissionPolicy;
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
	 * Catálogo de dynamic tags; se crea al usarlo.
	 *
	 * @var DynamicTagCatalog|null
	 */
	private ?DynamicTagCatalog $dynamic_tags;

	/**
	 * Componentes de Elementor 4; se crea al usarlo.
	 *
	 * @var ComponentStore|null
	 */
	private ?ComponentStore $components;

	/**
	 * Diseño global de Divi 5; se crea al usarlo.
	 *
	 * @var DiviDesignStore|null
	 */
	private ?DiviDesignStore $divi_design;

	/**
	 * Escrituras del diseño global de Divi 5; se crean al usarlas.
	 *
	 * @var DiviDesignOperations|null
	 */
	private ?DiviDesignOperations $divi_design_ops;

	/**
	 * Presets de módulos de Divi 5; se crea al usarlo.
	 *
	 * @var DiviPresetStore|null
	 */
	private ?DiviPresetStore $divi_presets;

	/**
	 * Escrituras de los presets de Divi 5; se crean al usarlas.
	 *
	 * @var DiviPresetOperations|null
	 */
	private ?DiviPresetOperations $divi_preset_ops;

	/**
	 * Catálogo de fuentes de contenido dinámico de Divi 5; se crea al usarlo.
	 *
	 * @var DiviDynamicCatalog|null
	 */
	private ?DiviDynamicCatalog $divi_dynamic;

	/**
	 * Esquema del bucle de consulta de Divi 5; se crea al usarlo.
	 *
	 * @var DiviLoopSchema|null
	 */
	private ?DiviLoopSchema $divi_loops;

	/**
	 * Esquema de las condiciones de visualización de Divi 5 (el de producción si no se inyecta otro).
	 *
	 * @var DiviConditionsSchema
	 */
	private DiviConditionsSchema $divi_conditions;

	/**
	 * Catálogo de las condiciones de visualización; se crea al usarlo.
	 *
	 * @var DiviConditionsCatalog|null
	 */
	private ?DiviConditionsCatalog $divi_conditions_catalog;

	/**
	 * Permiso del Theme Builder para tocar un layout: fn(): bool. Por omisión, `PermissionPolicy::can_write_divi_theme_builder()`.
	 *
	 * @var callable|null
	 */
	private $layout_allowed;

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
	 * @param DynamicTagCatalog|null        $dynamic_tags     Catálogo de dynamic tags (las pruebas inyectan los tags registrados).
	 * @param ComponentStore|null           $components       Almacén de componentes (las pruebas inyectan el de Elementor).
	 * @param DiviDesignStore|null          $divi_design      Almacén del diseño global de Divi (las pruebas inyectan uno sin Divi).
	 * @param DiviDesignOperations|null     $divi_design_ops  Escrituras del diseño global de Divi (las pruebas inyectan las suyas).
	 * @param DiviPresetStore|null          $divi_presets     Almacén de presets de Divi (las pruebas inyectan uno sin Divi).
	 * @param DiviPresetOperations|null     $divi_preset_ops  Escrituras de los presets de Divi (las pruebas inyectan las suyas).
	 * @param DiviDynamicCatalog|null       $divi_dynamic     Catálogo de contenido dinámico de Divi (las pruebas inyectan Divi, ACF y los tipos).
	 * @param DiviLoopSchema|null           $divi_loops       Esquema del bucle de consulta de Divi (las pruebas inyectan tipos, taxonomías y términos).
	 * @param DiviConditionsSchema|null     $divi_conditions  Esquema de las condiciones de visualización de Divi (las pruebas inyectan roles, tipos, términos y WooCommerce).
	 * @param DiviConditionsCatalog|null    $divi_conditions_catalog Catálogo de condiciones (las pruebas inyectan roles, tipos y WooCommerce).
	 * @param callable|null                 $layout_allowed  fn(): bool. Permiso del Theme Builder para leer o escribir un layout (las pruebas lo inyectan); por omisión, `PermissionPolicy::can_write_divi_theme_builder()` con estos ajustes.
	 */
	public function __construct(
		?BuilderRegistry $registry = null,
		?BuilderBackup $backup = null,
		?Settings $settings = null,
		?DesignSystemWriter $design_writer = null,
		?DesignClassOperations $design_classes = null,
		?DesignVariableOperations $design_variables = null,
		?DesignKitOperations $design_kit = null,
		?DynamicTagCatalog $dynamic_tags = null,
		?ComponentStore $components = null,
		?DiviDesignStore $divi_design = null,
		?DiviDesignOperations $divi_design_ops = null,
		?DiviPresetStore $divi_presets = null,
		?DiviPresetOperations $divi_preset_ops = null,
		?DiviDynamicCatalog $divi_dynamic = null,
		?DiviLoopSchema $divi_loops = null,
		?DiviConditionsSchema $divi_conditions = null,
		?DiviConditionsCatalog $divi_conditions_catalog = null,
		?callable $layout_allowed = null
	) {
		$this->registry                = $registry ?? new BuilderRegistry();
		$this->backup                  = $backup ?? new BuilderBackup();
		$this->settings                = $settings ?? new Settings();
		$this->design_writer           = $design_writer;
		$this->design_classes          = $design_classes;
		$this->design_variables        = $design_variables;
		$this->design_kit              = $design_kit;
		$this->dynamic_tags            = $dynamic_tags;
		$this->components              = $components;
		$this->divi_design             = $divi_design;
		$this->divi_design_ops         = $divi_design_ops;
		$this->divi_presets            = $divi_presets;
		$this->divi_preset_ops         = $divi_preset_ops;
		$this->divi_dynamic            = $divi_dynamic;
		$this->divi_loops              = $divi_loops;
		$this->divi_conditions         = $divi_conditions ?? new DiviConditionsSchema();
		$this->divi_conditions_catalog = $divi_conditions_catalog;
		$this->layout_allowed          = $layout_allowed;
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
	 * Lee el diseño global de Divi 5: paleta, variables, colores del Customizer y tipografía.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_divi_design_system() {
		return $this->divi_design()->read();
	}

	/**
	 * Deshace el último cambio del diseño global de Divi 5.
	 *
	 * El historial es uno solo para el diseño global y los presets de Divi; el almacén de presets se crea antes para que
	 * registre su área y el deshacer sepa restaurarla.
	 *
	 * @param bool          $force   Restaurar aunque el área haya cambiado después.
	 * @param callable|null $allowed fn( string $area ): bool. Permiso de escritura del área que se deshace.
	 * @param string|null   $area    Deshacer el último cambio de esta área aunque haya otros encima; null = el último de la pila.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo_divi_design_change( bool $force = false, ?callable $allowed = null, ?string $area = null ) {
		$this->divi_presets();

		return $this->divi_design()->undo( $force, $allowed, $area );
	}

	/**
	 * Área del último cambio del historial de Divi (`presets`, `colors`…), o null si no hay historial.
	 */
	public function divi_last_change_area(): ?string {
		$history = $this->divi_design()->history();
		$last    = end( $history );

		return false === $last ? null : $last['area'];
	}

	/**
	 * Lee los presets de módulos de Divi 5.
	 *
	 * @param array<string, mixed> $input Parámetros: `module` opcional.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_divi_presets( array $input ) {
		return $this->divi_presets()->read( isset( $input['module'] ) && is_string( $input['module'] ) ? $input['module'] : null );
	}

	/**
	 * Crea o actualiza un preset de módulo o de grupo de Divi 5.
	 *
	 * @param array<string, mixed> $input Parámetros: `kind`, `name`, `id?`, `label`, `attrs?`, `module?`, `group_id?`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_preset( array $input ) {
		return $this->divi_preset_ops()->set_preset( $input );
	}

	/**
	 * Fija o quita el preset predeterminado de un módulo o de un grupo de Divi 5.
	 *
	 * @param array<string, mixed> $input Parámetros: `kind`, `name`, `id` (o null).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_default_preset( array $input ) {
		return $this->divi_preset_ops()->set_default_preset( $input );
	}

	/**
	 * Borra un preset de Divi 5 (en uso, sólo con force).
	 *
	 * @param array<string, mixed> $input Parámetros: `kind`, `name`, `id`, `force?`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_divi_preset( array $input ) {
		return $this->divi_preset_ops()->delete_preset( $input );
	}

	/**
	 * Escrituras de los presets de Divi (comparten el almacén de lectura y deshacer).
	 */
	public function divi_preset_ops(): DiviPresetOperations {
		$this->divi_preset_ops ??= new DiviPresetOperations( $this->divi_presets() );

		return $this->divi_preset_ops;
	}

	/**
	 * Almacén de presets de Divi (comparte con el diseño global el historial, el bloqueo y el deshacer).
	 */
	public function divi_presets(): DiviPresetStore {
		$this->divi_presets ??= new DiviPresetStore( $this->divi_design() );

		return $this->divi_presets;
	}

	/**
	 * Bloqueo de presets para las escrituras de página que aplican un preset: el mismo del borrado de presets.
	 *
	 * @return callable fn( callable ): mixed.
	 */
	private function divi_presets_lock(): callable {
		return fn( callable $work ) => $this->divi_design()->locked( $work );
	}

	/**
	 * Validador de los árboles y parches de Divi 5; los presets que un nodo aplica se leen del almacén de presets (sólo si hay `preset`).
	 */
	private function divi_validator(): DiviTreeValidator {
		return new DiviTreeValidator(
			null,
			null,
			fn(): array => $this->divi_presets()->current(),
			fn( string $module ): array => $this->divi_presets()->module_groups( $module )
		);
	}

	/**
	 * Crea o actualiza un color global de Divi 5.
	 *
	 * @param array<string, mixed> $input `{id?, label, color}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_global_color( array $input ) {
		return $this->divi_design_ops()->set_color( $input );
	}

	/**
	 * Borra un color global de Divi 5 (en uso, sólo con force).
	 *
	 * @param array<string, mixed> $input `{id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_divi_global_color( array $input ) {
		return $this->divi_design_ops()->delete_color( $input );
	}

	/**
	 * Crea o actualiza una variable de diseño de Divi 5.
	 *
	 * @param array<string, mixed> $input `{type, id?, label, value}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_variable( array $input ) {
		return $this->divi_design_ops()->set_variable( $input );
	}

	/**
	 * Archiva o reactiva una variable de diseño de Divi 5.
	 *
	 * @param array<string, mixed> $input `{type, id, archived}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function archive_divi_variable( array $input ) {
		return $this->divi_design_ops()->archive_variable( $input );
	}

	/**
	 * Cambia la tipografía base y los colores del Customizer de Divi 5.
	 *
	 * @param array<string, mixed> $input Campos de tipografía.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_typography( array $input ) {
		return $this->divi_design_ops()->set_typography( $input );
	}

	/**
	 * Escrituras del diseño global de Divi (comparten el almacén de lectura y deshacer).
	 */
	public function divi_design_ops(): DiviDesignOperations {
		$this->divi_design_ops ??= new DiviDesignOperations( $this->divi_design() );

		return $this->divi_design_ops;
	}

	/**
	 * Almacén del diseño global de Divi (compartido por lectura, escrituras y deshacer).
	 */
	public function divi_design(): DiviDesignStore {
		$this->divi_design ??= new DiviDesignStore();

		return $this->divi_design;
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
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
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
	 * Inserta una instancia de un componente de Elementor 4 en una página, en la copia de trabajo.
	 *
	 * Mismo camino que {@see self::edit_elementor_section()} (insert). Sin overrides.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function insert_component( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$store = $this->components ?? new ComponentStore();
		$ready = $store->require_insertable();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$component = isset( $input['component_id'] ) && is_int( $input['component_id'] ) ? $input['component_id'] : 0;

		if ( ! $store->usable( $component ) ) {
			return new \WP_Error(
				'nexo_mcp_component_not_found',
				__( 'El componente no existe, está archivado o en la papelera.', 'nexo-mcp' )
			);
		}

		$editor = new ElementorSectionEditor(
			$this->settings,
			new ElementorAdapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$store->guard(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			static fn( int $id ): bool => $store->usable( $id )
		);

		return $editor->edit(
			$post,
			array(
				'operation'    => 'insert',
				'node_id'      => isset( $input['node_id'] ) && is_string( $input['node_id'] ) ? sanitize_text_field( $input['node_id'] ) : '',
				'position'     => isset( $input['position'] ) && is_string( $input['position'] ) ? $input['position'] : '',
				'component_id' => $component,
				'draft'        => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Lista los dynamic tags del catálogo cerrado que el sitio tiene registrados.
	 *
	 * Sin Elementor Pro no hay tags registrados: la lista sale vacía, no es un error.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_dynamic_tags(): array {
		return ( $this->dynamic_tags ?? new DynamicTagCatalog() )->listing();
	}

	/**
	 * Lista las fuentes de contenido dinámico de Divi 5 de la lista cerrada, con su esquema de ajustes.
	 *
	 * Con `post_id` (la página o su copia de trabajo; se usa el original) sólo lo que aplica a ella.
	 *
	 * @param array<string, mixed> $input Parámetros: `post_id?`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_divi_dynamic_sources( array $input ) {
		$catalog = $this->divi_dynamic ?? new DiviDynamicCatalog();
		$out     = array();
		$post_id = null;

		if ( array_key_exists( 'post_id', $input ) ) {
			$post = $this->resolve_page_or_copy( array( 'id' => $input['post_id'] ) );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post_id          = WorkingCopy::original_id_for( (int) $post->ID );
			$out['post_id']   = $post_id;
			$out['post_type'] = $post->post_type;
		}

		$sources = $catalog->listing( $post_id );

		if ( is_wp_error( $sources ) ) {
			return $sources;
		}

		$loop_sources = $catalog->loop_listing( $post_id );

		if ( is_wp_error( $loop_sources ) ) {
			return $loop_sources;
		}

		$out['sources']      = $sources;
		$out['loop_sources'] = $loop_sources;
		$out['targets']      = DiviDynamicCatalog::targets();

		return $out;
	}

	/**
	 * Catálogo de las condiciones de visualización de Divi 5 que el conector deja escribir, con las listas vivas del sitio.
	 *
	 * Con `post_id` (la página o su copia de trabajo) se comprueba que exista y se devuelve su id y tipo.
	 *
	 * @param array<string, mixed> $input Parámetros: `post_id?`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_divi_conditions( array $input ) {
		$out = array();

		if ( array_key_exists( 'post_id', $input ) ) {
			$post = $this->resolve_page_or_copy( array( 'id' => $input['post_id'] ) );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$out['post_id']   = WorkingCopy::original_id_for( (int) $post->ID );
			$out['post_type'] = $post->post_type;
		}

		return array_merge( $out, ( $this->divi_conditions_catalog ?? new DiviConditionsCatalog() )->listing() );
	}

	/**
	 * Lista los componentes de Elementor 4 (sin el árbol).
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function list_components( array $input ) {
		return ( $this->components ?? new ComponentStore() )->list( ! empty( $input['include_archived'] ) );
	}

	/**
	 * Crea un componente de Elementor 4 en borrador.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_component( array $input ) {
		return ( $this->components ?? new ComponentStore() )->create( $input );
	}

	/**
	 * Asigna un dynamic tag a una prop de un nodo atómico, en la copia de trabajo.
	 *
	 * Mismo permiso sobre el original y mismo camino de escritura que
	 * {@see self::edit_elementor_section()}. Ver {@see DynamicTagAssigner}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_dynamic_tag( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$assigner = new DynamicTagAssigner(
			$this->settings,
			new ElementorAdapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			new ElementorSchemaGuard(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			$this->dynamic_tags ?? new DynamicTagCatalog()
		);

		return $assigner->assign(
			$post,
			array(
				'node_id' => isset( $input['node_id'] ) && is_string( $input['node_id'] ) ? sanitize_text_field( $input['node_id'] ) : '',
				'prop'    => isset( $input['prop'] ) && is_string( $input['prop'] ) ? $input['prop'] : '',
				'tag'     => $input['tag'] ?? null,
				'draft'   => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Edita un módulo de una página de Divi 5, en la copia de trabajo.
	 *
	 * Mismo permiso sobre el original que {@see self::edit_elementor_section()}. Ver {@see Divi5ModuleEditor}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit_divi_module( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$editor = new Divi5ModuleEditor(
			$this->settings,
			new Divi5Adapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$this->divi_validator(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			null,
			$this->divi_presets_lock(),
			null,
			null,
			$this->layout_allowed_callable()
		);

		return $editor->edit(
			$post,
			array(
				'path'        => isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '',
				'fingerprint' => isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '',
				'content'     => $input['content'] ?? null,
				'design'      => $input['design'] ?? null,
				'preset'      => $input['preset'] ?? null,
				'draft'       => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Asigna (o quita) un token de contenido dinámico de Divi 5 en un campo de un módulo, en la copia de trabajo.
	 *
	 * Mismo camino que {@see self::edit_divi_module()} (ruta, huella, copia, bloqueo, respaldo) con un permiso más:
	 * `$allowed` (`PermissionPolicy::can_write_divi_dynamic()` sobre el original) se vuelve a comprobar aquí y el
	 * validador con contexto dinámico (`with_dynamic`) se obtiene SÓLO después; sin `$allowed` se deniega. Quitar el
	 * token (`source` null) deja el campo vacío y no necesita el contexto, pero pide el mismo permiso.
	 *
	 * @param array<string, mixed> $input   Parámetros de la herramienta: `id`, `path`, `fingerprint`, `field`, `source`, `draft`.
	 * @param callable|null        $allowed fn( int ): bool. Permiso de escribir dinámico sobre esa página.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_dynamic( array $input, ?callable $allowed = null ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( null === $allowed || ! $allowed( (int) $post->ID ) ) {
			return $this->forbidden();
		}

		if ( ! array_key_exists( 'source', $input ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_dynamic_invalid',
				__( 'source: es obligatorio: un objeto {name, settings?} o null para quitar el token.', 'nexo-mcp' ),
				array( 'path' => 'source' )
			);
		}

		$validator = $this->divi_validator();

		if ( null !== $input['source'] ) {
			$validator = $validator->with_dynamic( $this->divi_dynamic ?? new DiviDynamicCatalog(), WorkingCopy::original_id_for( (int) $post->ID ) );
		}

		$editor = new Divi5ModuleEditor(
			$this->settings,
			new Divi5Adapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$validator,
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			null,
			null,
			$this->divi_loops ?? new DiviLoopSchema(),
			null,
			$this->layout_allowed_callable()
		);

		return $editor->edit(
			$post,
			array(
				'path'        => isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '',
				'fingerprint' => isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '',
				'dynamic'     => array(
					'field'  => isset( $input['field'] ) && is_string( $input['field'] ) ? $input['field'] : '',
					'source' => $input['source'],
				),
				'draft'       => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Activa (o quita) el bucle de consulta de un módulo de Divi 5, en la copia de trabajo.
	 *
	 * Mismo camino que {@see self::set_divi_dynamic()} y mismo permiso (`$allowed` = `PermissionPolicy::can_write_divi_dynamic()`
	 * sobre el original, vuelto a comprobar aquí ANTES de tocar el esquema; sin `$allowed` se deniega). El bucle sólo se
	 * escribe con el esquema cerrado de {@see DiviLoopSchema} (S7); `loop` es obligatorio y `null` lo quita.
	 *
	 * @param array<string, mixed> $input   Parámetros de la herramienta: `id`, `path`, `fingerprint`, `loop`, `draft`.
	 * @param callable|null        $allowed fn( int ): bool. Permiso de escribir dinámico sobre esa página.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_loop( array $input, ?callable $allowed = null ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( null === $allowed || ! $allowed( (int) $post->ID ) ) {
			return $this->forbidden();
		}

		if ( ! array_key_exists( 'loop', $input ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_loop_invalid',
				__( 'loop: es obligatorio: un objeto {post_type, per_page, order_by, order, offset?, exclude_current?, terms?} o null para quitar el bucle.', 'nexo-mcp' ),
				array( 'path' => 'loop' )
			);
		}

		$editor = new Divi5ModuleEditor(
			$this->settings,
			new Divi5Adapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$this->divi_validator(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			null,
			null,
			$this->divi_loops ?? new DiviLoopSchema(),
			null,
			$this->layout_allowed_callable()
		);

		return $editor->edit(
			$post,
			array(
				'path'        => isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '',
				'fingerprint' => isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '',
				'loop'        => $input['loop'],
				'draft'       => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Pone (o quita) las condiciones de visualización de un módulo de Divi 5, en la copia de trabajo.
	 *
	 * Mismo camino que {@see self::set_divi_loop()} y su propio permiso: `$allowed` (`PermissionPolicy::can_write_divi_conditions()`
	 * sobre el original) se vuelve a comprobar aquí ANTES de tocar el esquema; sin `$allowed` se deniega. Sólo se escribe
	 * con el esquema cerrado de {@see DiviConditionsSchema} (S5, S6); `conditions` es obligatorio y la lista vacía las quita.
	 * Una lista que no es del conector exige `replace_foreign: true` (S7).
	 *
	 * @param array<string, mixed> $input   Parámetros de la herramienta: `id`, `path`, `fingerprint`, `match`, `conditions`, `replace_foreign`, `draft`.
	 * @param callable|null        $allowed fn( int ): bool. Permiso de escribir condiciones sobre esa página.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_conditions( array $input, ?callable $allowed = null ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( null === $allowed || ! $allowed( (int) $post->ID ) ) {
			return $this->forbidden();
		}

		if ( ! array_key_exists( 'conditions', $input ) ) {
			return new \WP_Error(
				DiviConditionsSchema::CODE,
				__( 'conditions: es obligatorio: una lista de condiciones {type, …} (vacía para quitar todas).', 'nexo-mcp' ),
				array( 'path' => 'conditions' )
			);
		}

		if ( ! array_key_exists( 'match', $input ) ) {
			return new \WP_Error(
				DiviConditionsSchema::CODE,
				__( 'match: es obligatorio (all o any), también al quitar con conditions vacío.', 'nexo-mcp' ),
				array( 'path' => 'match' )
			);
		}

		$set = array(
			'conditions' => $input['conditions'],
			'match'      => $input['match'],
		);

		$editor = new Divi5ModuleEditor(
			$this->settings,
			new Divi5Adapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$this->divi_validator(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			null,
			null,
			null,
			$this->divi_conditions,
			$this->layout_allowed_callable()
		);

		return $editor->edit(
			$post,
			array(
				'path'            => isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '',
				'fingerprint'     => isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '',
				'conditions'      => $set,
				'replace_foreign' => true === ( $input['replace_foreign'] ?? false ),
				'draft'           => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Crea una página de Divi 5 en borrador a partir de secciones con módulos reales.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_divi_page( array $input ) {
		return ( new Divi5PageCreator( $this->divi_validator(), null, null, null, null, null, $this->divi_presets_lock() ) )->create( $input );
	}

	/**
	 * Crea un layout del Theme Builder de Divi 5 (encabezado, cuerpo o pie), publicado y sin asignar.
	 *
	 * Exige el permiso del Theme Builder (el inyectado o `PermissionPolicy::can_write_divi_theme_builder()`) ANTES de mirar la
	 * entrada. Ver {@see Divi5LayoutCreator}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `type`, `title`, `sections`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_divi_layout( array $input ) {
		return ( new Divi5LayoutCreator( $this->divi_validator(), $this->layout_allowed_callable(), null, null, null, null, null, $this->divi_presets_lock() ) )->create( $input );
	}

	/**
	 * Lista las plantillas del Theme Builder en vivo de Divi 5 (sólo lee).
	 *
	 * Exige el permiso del Theme Builder (el inyectado o `PermissionPolicy::can_write_divi_theme_builder()`) ANTES de leer
	 * nada. Ver {@see DiviTemplateEditor}.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_divi_theme_builder() {
		return $this->template_editor()->list_templates();
	}

	/**
	 * Crea o reemplaza UNA plantilla del Theme Builder en vivo de Divi 5.
	 *
	 * Exige el permiso del Theme Builder ANTES de mirar la entrada. Ver {@see DiviTemplateEditor::set_template()}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_divi_template( array $input ) {
		return $this->template_editor()->set_template( $input );
	}

	/**
	 * Saca una plantilla del Theme Builder en vivo y la manda a la papelera, con respaldo.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `id`, `confirm_site_wide`, `replace_foreign`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_divi_template( array $input ) {
		return $this->template_editor()->delete_template( $input );
	}

	/**
	 * Devuelve una plantilla del Theme Builder en vivo al estado de su respaldo (también una borrada).
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `id`, `confirm_site_wide`, `replace_foreign`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_divi_template( array $input ) {
		return $this->template_editor()->restore_template( $input );
	}

	/**
	 * Lista los ítems de la biblioteca de Divi 5 (sólo lee).
	 *
	 * Exige el permiso de la biblioteca (`PermissionPolicy::can_write_divi_library()`) ANTES de mirar la entrada. Ver {@see DiviLibraryEditor}.
	 *
	 * @param array<string, mixed> $input Filtros: `type`, `scope`, `category`, `search`, `limit`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_divi_library( array $input ) {
		return $this->library_editor()->list_items( $input );
	}

	/**
	 * Lee un ítem de la biblioteca de Divi 5 con la lista de sus nodos.
	 *
	 * @param array<string, mixed> $input Parámetros: `id`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_divi_library_item( array $input ) {
		return $this->library_editor()->get_item( $input );
	}

	/**
	 * Crea (sin `id`) o actualiza (con `id`) un ítem de la biblioteca de Divi 5.
	 *
	 * Exige el permiso de la biblioteca (con el id del ítem si lo hay) ANTES de mirar la entrada. Ver {@see DiviLibraryEditor::save_item()}.
	 *
	 * @param array<string, mixed> $input Parámetros: `id?`, `title`, `type`, `scope`, `nodes`, `categories`, `replace_foreign`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_divi_library_item( array $input ) {
		return $this->library_editor()->save_item( $input );
	}

	/**
	 * Editor de la biblioteca de Divi 5 con los componentes de producción.
	 */
	private function library_editor(): DiviLibraryEditor {
		return new DiviLibraryEditor(
			new DiviLibrarySchema(),
			new DiviLibraryBackup(),
			$this->divi_validator(),
			fn( ?int $item_id ): bool => ( new PermissionPolicy( $this->settings ) )->can_write_divi_library( $item_id ),
			null,
			$this->divi_presets_lock()
		);
	}

	/**
	 * Editor de plantillas del Theme Builder con los componentes de producción.
	 */
	private function template_editor(): DiviTemplateEditor {
		return new DiviTemplateEditor( new DiviThemeBuilderSchema(), new DiviTemplateStore(), $this->layout_allowed_callable() );
	}

	/**
	 * Reemplaza, inserta o borra un nodo de una página de Divi 5, en la copia de trabajo.
	 *
	 * Mismo permiso sobre el original que {@see self::edit_elementor_section()}. Ver {@see Divi5SectionEditor}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit_divi_section( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$editor = new Divi5SectionEditor(
			$this->settings,
			new Divi5Adapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			$this->divi_validator(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			null,
			null,
			null,
			$this->divi_presets_lock(),
			$this->divi_conditions,
			$this->layout_allowed_callable()
		);

		return $editor->edit(
			$post,
			array(
				'operation'   => isset( $input['operation'] ) && is_string( $input['operation'] ) ? $input['operation'] : '',
				'path'        => isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '',
				'fingerprint' => isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '',
				'position'    => isset( $input['position'] ) && is_string( $input['position'] ) ? $input['position'] : '',
				'sections'    => $input['sections'] ?? null,
				'draft'       => $input['draft'] ?? false,
			)
		);
	}

	/**
	 * Migra una página de Elementor 3 a elementos atómicos, en la copia de trabajo.
	 *
	 * Mismo permiso y mismo camino de escritura que {@see self::edit_elementor_section()}.
	 * Ver {@see ElementorPageMigrator}.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function migrate_elementor_page( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$migrator = new ElementorPageMigrator(
			$this->settings,
			new ElementorAdapter(),
			$this->backup,
			new WorkingCopy( $this->settings ),
			new ElementorSchemaGuard(),
			fn( \WP_Post $locked ) => $this->locked_by_other( $locked ),
			$this->convert_v3_tree( ... )
		);

		return $migrator->migrate( $post, array( 'draft' => $input['draft'] ?? false ) );
	}

	/**
	 * Reemplaza el texto de un nodo del constructor.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_node_text( array $input ) {
		$post = $this->resolve_page_or_copy( $input );

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
		$post = $this->resolve_page_or_copy( $input );

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
		$post = $this->resolve_page_or_copy( $input );

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
		$post = $this->resolve_page_or_copy( $input );

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
		$post = $this->find_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Un layout del Theme Builder ya pasó el permiso del Theme Builder en `find_post`: no se le pide además el de una página.
		if ( ! DiviLayoutGuard::is_layout( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->forbidden();
		}

		return $post;
	}

	/**
	 * Resuelve una página o su copia de trabajo, con el permiso pedido sobre el original.
	 *
	 * Editar la copia es editar el original: quien puede editar el original puede
	 * trabajar con su copia aunque la haya creado otro usuario. Sólo vale para la
	 * copia vigente, con el enlace confirmado desde los dos lados
	 * ({@see WorkingCopy::copy_of()}); cualquier otro contenido, incluida una copia
	 * huérfana o un borrador que el original no reconoce, pide permiso sobre sí mismo.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_page_or_copy( array $input ) {
		$post = $this->find_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$original    = get_post( WorkingCopy::original_id_for( (int) $post->ID ) );
		$is_its_copy = $original instanceof \WP_Post && $original->ID !== $post->ID && WorkingCopy::copy_of( $original ) === (int) $post->ID;

		if ( ! DiviLayoutGuard::is_layout( $post ) && ! current_user_can( 'edit_post', $is_its_copy ? $original->ID : $post->ID ) ) {
			return $this->forbidden();
		}

		return $post;
	}

	/**
	 * Permiso del Theme Builder para tocar un layout: el inyectado o, por omisión, el de la política con estos ajustes.
	 *
	 * @return callable fn(): bool.
	 */
	private function layout_allowed_callable(): callable {
		return $this->layout_allowed ?? fn(): bool => ( new PermissionPolicy( $this->settings ) )->can_write_divi_theme_builder();
	}

	/**
	 * Convierte un árbol de Elementor 3 a elementos atómicos. Ver {@see ElementorV3Migrator}.
	 *
	 * @param array<int, array<string, mixed>> $tree Árbol v3.
	 *
	 * @return array<string, mixed>
	 */
	private function convert_v3_tree( array $tree ): array {
		return ( new ElementorV3Migrator( new ElementorV3StyleMapper( new ElementorV3StyleSource() ) ) )->migrate( $tree );
	}

	/**
	 * Error de permiso de las herramientas de constructor.
	 */
	private function forbidden(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_builder_forbidden',
			__( 'No tienes permiso sobre este contenido.', 'nexo-mcp' )
		);
	}

	/**
	 * Busca el contenido indicado, sin comprobar permisos.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function find_post( array $input ) {
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

		// Un layout del Theme Builder pide el permiso del Theme Builder; los demás tipos `et_*` se rechazan (T5).
		$guard = DiviLayoutGuard::check( $post, $this->layout_allowed_callable() );

		return is_wp_error( $guard ) ? $guard : $post;
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
