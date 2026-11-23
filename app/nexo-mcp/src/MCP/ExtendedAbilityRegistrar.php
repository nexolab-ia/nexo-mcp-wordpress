<?php
/**
 * Abilities modulares avanzadas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\DenialExplainer;
use NexoDev\NexoMCP\Authorization\IrreversibleActions;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\DesignKitOperations;
use NexoDev\NexoMCP\Builders\DesignVariableValue;
use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use NexoDev\NexoMCP\Fields\FieldGateway;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Registra herramientas opcionales manteniendo una allowlist auditable.
 */
final class ExtendedAbilityRegistrar {

	public const ABILITIES = array(
		'nexo-mcp/list-media',
		'nexo-mcp/update-media',
		'nexo-mcp/import-media',
		'nexo-mcp/upload-media',
		'nexo-mcp/list-terms',
		'nexo-mcp/create-term',
		'nexo-mcp/list-revisions',
		'nexo-mcp/restore-revision',
		'nexo-mcp/set-content-status',
		'nexo-mcp/get-working-copy-diff',
		'nexo-mcp/get-post-seo',
		'nexo-mcp/update-post-seo',
		'nexo-mcp/get-seo-settings',
		'nexo-mcp/update-seo-settings',
		'nexo-mcp/get-site-appearance',
		'nexo-mcp/update-site-identity',
		'nexo-mcp/update-custom-css',
		'nexo-mcp/list-menus',
		'nexo-mcp/upsert-menu-item',
		'nexo-mcp/list-fields',
		'nexo-mcp/update-field',
		'nexo-mcp/list-plugins',
		'nexo-mcp/list-themes',
		'nexo-mcp/prepare-plugin-action',
		'nexo-mcp/execute-plugin-action',
		'nexo-mcp/list-products',
		'nexo-mcp/get-product',
		'nexo-mcp/upsert-product',
		'nexo-mcp/delete-product',
		'nexo-mcp/get-page-structure',
		'nexo-mcp/get-design-system',
		'nexo-mcp/undo-design-system-change',
		'nexo-mcp/create-global-class',
		'nexo-mcp/update-global-class',
		'nexo-mcp/delete-global-class',
		'nexo-mcp/create-design-variable',
		'nexo-mcp/update-design-variable',
		'nexo-mcp/delete-design-variable',
		'nexo-mcp/update-kit-globals',
		'nexo-mcp/create-elementor-page',
		'nexo-mcp/edit-elementor-section',
		'nexo-mcp/migrate-elementor-page',
		'nexo-mcp/list-dynamic-tags',
		'nexo-mcp/set-dynamic-tag',
		'nexo-mcp/list-components',
		'nexo-mcp/create-component',
		'nexo-mcp/insert-component',
		'nexo-mcp/update-node-text',
		'nexo-mcp/update-node-attribute',
		'nexo-mcp/restore-builder-backup',
		'nexo-mcp/edit-structure',
	);

	/**
	 * Tope de IDs por llamada en set-content-status.
	 */
	private const LOTE_MAXIMO = 50;

	/**
	 * Configuración.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Política.
	 *
	 * @var PermissionPolicy
	 */
	private PermissionPolicy $policy;

	/**
	 * Ejecutor.
	 *
	 * @var ToolExecutor
	 */
	private ToolExecutor $executor;

	/**
	 * Contenido avanzado.
	 *
	 * @var ContentAdminGateway
	 */
	private ContentAdminGateway $content;

	/**
	 * Campos personalizados.
	 *
	 * @var FieldGateway
	 */
	private FieldGateway $fields;

	/**
	 * SEO.
	 *
	 * @var SeoGateway
	 */
	private SeoGateway $seo;

	/**
	 * Apariencia.
	 *
	 * @var AppearanceGateway
	 */
	private AppearanceGateway $appearance;

	/**
	 * Mantenimiento.
	 *
	 * @var MaintenanceGateway
	 */
	private MaintenanceGateway $maintenance;

	/**
	 * WooCommerce.
	 *
	 * @var ProductGateway
	 */
	private ProductGateway $products;

	/**
	 * Constructores visuales.
	 *
	 * @var BuilderGateway
	 */
	private BuilderGateway $builders;

	/**
	 * Traductor de denegaciones.
	 *
	 * @var DenialExplainer
	 */
	private DenialExplainer $explainer;

	/**
	 * Construye el registro.
	 *
	 * @param Settings            $settings    Configuración.
	 * @param PermissionPolicy    $policy      Política.
	 * @param ToolExecutor        $executor    Ejecutor auditado.
	 * @param ContentAdminGateway $content     Contenido avanzado.
	 * @param SeoGateway          $seo         SEO.
	 * @param AppearanceGateway   $appearance  Apariencia.
	 * @param MaintenanceGateway  $maintenance Mantenimiento.
	 * @param ProductGateway      $products    WooCommerce.
	 * @param BuilderGateway|null $builders    Constructores visuales.
	 * @param FieldGateway|null   $fields      Campos personalizados.
	 */
	public function __construct(
		Settings $settings,
		PermissionPolicy $policy,
		ToolExecutor $executor,
		ContentAdminGateway $content,
		SeoGateway $seo,
		AppearanceGateway $appearance,
		MaintenanceGateway $maintenance,
		ProductGateway $products,
		?BuilderGateway $builders = null,
		?FieldGateway $fields = null
	) {
		$this->settings    = $settings;
		$this->policy      = $policy;
		$this->executor    = $executor;
		$this->content     = $content;
		$this->seo         = $seo;
		$this->appearance  = $appearance;
		$this->maintenance = $maintenance;
		$this->products    = $products;
		$this->builders    = $builders ?? new BuilderGateway( null, null, $settings );
		$this->fields      = $fields ?? new FieldGateway();
		$this->explainer   = new DenialExplainer( $settings );
	}

	/**
	 * Registra hook de Abilities API.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registra todas las abilities opcionales.
	 */
	public function register_abilities(): void {
		$this->register_content_abilities();
		$this->register_seo_abilities();
		$this->register_appearance_abilities();
		$this->register_maintenance_abilities();
		$this->register_commerce_abilities();
		$this->register_builder_abilities();
	}

	/**
	 * Constructores visuales.
	 *
	 * La lectura sólo exige el módulo activo; la escritura suma el respaldo
	 * obligatorio que aplica el gateway antes de tocar nada.
	 */
	private function register_builder_abilities(): void {
		$this->ability(
			'list-fields',
			'Listar campos personalizados',
			'Lista los campos personalizados de un contenido y cuáles admiten escritura.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->fields->list_fields( (int) $input['id'] ),
			fn( array $input ) => $this->module_read(
				'content_tools_enabled',
				Capabilities::CONTENT_ADVANCED,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'update-field',
			'Actualizar campo personalizado',
			'Escribe un campo personalizado, validado según su tipo.',
			'write',
			$this->field_schema(),
			fn( array $input ) => $this->fields->update_field(
				(int) $input['id'],
				(string) $input['name'],
				$input['value'] ?? ''
			),
			fn( array $input ) => $this->module_write(
				'content_tools_enabled',
				Capabilities::CONTENT_ADVANCED,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'get-page-structure',
			'Obtener estructura de la página',
			'Detecta el constructor visual y lista los textos, enlaces e imágenes editables de una página o entrada, sin el HTML. Es la lectura liviana previa a nexo-mcp-update-node-text. Los nodos que no se pueden editar traen editable: false y el motivo.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->builders->get_structure( $input ),
			fn( array $input ) => $this->module_read(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'get-design-system',
			'Obtener sistema de diseño de Elementor',
			'Lista el sistema de diseño de Elementor: si los elementos atómicos (v4) están activos, y las clases globales, variables, colores y tipografía del Kit activo. Úsalo antes de armar o leer páginas atómicas para reutilizar lo que ya existe. Si la lectura de las clases o de las variables falla, la respuesta trae warnings ([{source, message}]) y conserva lo ya leído: una lista vacía sin warnings significa que no hay nada.',
			'read',
			$this->empty_schema(),
			fn() => $this->builders->get_design_system(),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'undo-design-system-change',
			'Deshacer el último cambio del sistema de diseño de Elementor',
			'Restaura el estado previo al último cambio hecho al sistema de diseño de Elementor por este conector (clases globales, variables, o colores y tipografía del Kit), con los mismos ids, y borra ese respaldo. Restaura la colección completa que el cambio tocó (todas las clases, todas las variables, o las listas de colores y tipografía del Kit) tal como estaba antes del último cambio: lo que se haya cambiado después en esa colección desde el editor de Elementor también se pierde. Sólo hay un nivel: el último cambio; deshacer dos veces seguidas no cambia más. Sin cambio que deshacer responde nexo_mcp_design_no_backup y no cambia nada. Si el cambio había quitado una clase de las páginas que la usaban, deshacer la devuelve a la lista de clases pero no vuelve a ponerla en esas páginas (la respuesta lo avisa en warnings). Si el Kit activo cambió desde el último cambio responde nexo_mcp_design_kit_changed y no restaura nada. Pide confirmación explícita de la persona: repetir la llamada con "confirmation": "UNDO". Requiere el permiso del sistema de diseño y, para clases y variables, el Editor V4 activo.',
			'write',
			$this->confirmation_schema( 'undo-design-system-change' ),
			fn() => $this->builders->undo_design_system_change(),
			fn() => $this->module_write( 'design_system_tools_enabled', Capabilities::DESIGN_SYSTEM, 'manage_options' )
		);
		$this->ability(
			'create-global-class',
			'Crear clase global de Elementor 4',
			'Crea una clase global de Elementor 4 (estilos reutilizables con variantes por breakpoint y estado). CAMBIA TODO EL SITIO EN VIVO: la clase queda disponible en el editor al instante. Antes llamar a nexo-mcp-get-design-system para ver las clases y variables que ya existen y no duplicarlas. Se puede deshacer con nexo-mcp-undo-design-system-change (un solo nivel: el último cambio). Reglas: label con formato /^[a-z][a-z-_0-9]*$/i, de hasta 50 caracteres y único sin distinguir mayúsculas (si ya existe se rechaza con nexo_mcp_design_label_taken: no se renombra solo); máximo 1000 clases (nexo_mcp_design_limit_reached). ' . $this->class_variants_rules() . ' Devuelve {id, label}: el id (g-…) es el que se lista en settings.classes de los nodos. Todo error de validación es nexo_mcp_design_invalid con «ruta: motivo» y data.path, y no escribe nada. Requiere el permiso del sistema de diseño y el Editor V4 activo (nexo_mcp_elementor_v4_required).',
			'write',
			$this->class_create_schema(),
			fn( array $input ) => $this->builders->create_global_class( $input ),
			fn() => $this->design_write()
		);
		$this->ability(
			'update-global-class',
			'Modificar clase global de Elementor 4',
			'Cambia el label y/o las variantes de una clase global existente, con el mismo id. CAMBIA TODO EL SITIO EN VIVO: las páginas que usan la clase cambian de estilo. Antes llamar a nexo-mcp-get-design-system. Se puede deshacer con nexo-mcp-undo-design-system-change. variants REEMPLAZA todas las variantes de la clase (mandar la lista completa); sin variants se conservan las actuales. ' . $this->class_variants_rules() . ' El label nuevo sigue las reglas de create-global-class y no puede repetir el de otra clase (nexo_mcp_design_label_taken). Id inexistente: nexo_mcp_design_not_found. Sin label ni variants responde nexo_mcp_design_invalid. Devuelve {id, label}. Requiere el permiso del sistema de diseño y el Editor V4 activo.',
			'write',
			$this->class_update_schema(),
			fn( array $input ) => $this->builders->update_global_class( $input ),
			fn() => $this->design_write()
		);
		$this->ability(
			'delete-global-class',
			'Eliminar clase global de Elementor 4',
			'Elimina una clase global. CAMBIA TODO EL SITIO EN VIVO: las páginas que la usan pierden ese estilo. Antes llamar a nexo-mcp-get-design-system. Si la clase está en uso se rechaza con nexo_mcp_design_in_use y data.used_in [{id, title}] sin borrar nada; sólo con force: true se borra igual y Elementor la quita de esas páginas. Deshacer con nexo-mcp-undo-design-system-change restaura la clase (mismo id) pero NO vuelve a ponerla en las páginas de las que se quitó (la respuesta lo avisa). Pide confirmación explícita de la persona: repetir la llamada con "confirmation": "DELETE". Devuelve {deleted, pages_affected: [{id, title}]}. Requiere el permiso del sistema de diseño, el permiso destructivo y el Editor V4 activo.',
			'write',
			$this->confirmed_delete_schema( 'delete-global-class', 'Id de la clase global (g-…).' ),
			fn( array $input ) => $this->builders->delete_global_class( $input ),
			fn() => $this->design_destroy()
		);
		$this->ability(
			'create-design-variable',
			'Crear variable global de Elementor 4',
			'Crea una variable global de Elementor 4. CAMBIA TODO EL SITIO EN VIVO. Antes llamar a nexo-mcp-get-design-system para ver las variables existentes. Se puede deshacer con nexo-mcp-undo-design-system-change (un solo nivel). type: global-color-variable (hex #rgb/#rrggbb/#rrggbbaa o rgb()/rgba()/hsl()/hsla() con números), global-font-variable (nombres de familia separados por comas), global-size-variable (número + unidad en minúsculas, sin espacios: 16px, 1.5rem, 50%) o global-custom-size-variable (texto CSS como calc() o clamp(), con las mismas restricciones de valores de estilo que las clases: sin ; { } < > \ @ , url(), var() ni !important, comillas y paréntesis cerrados). label de letras sin tildes, números, guiones y guiones bajos (se usa como nombre de la propiedad CSS --label; sin espacios ni otros símbolos, no empieza con --), de hasta 50 caracteres y único sin distinguir mayúsculas entre las variables no borradas (nexo_mcp_design_label_taken); máximo 1000 variables activas (nexo_mcp_design_limit_reached). value no vacío, de hasta 512 caracteres y validado según el tipo. Devuelve {id, type, label, value}. Todo error de validación es nexo_mcp_design_invalid con «ruta: motivo» y data.path, y no escribe nada. Requiere el permiso del sistema de diseño y el Editor V4 activo.',
			'write',
			$this->variable_create_schema(),
			fn( array $input ) => $this->builders->create_design_variable( $input ),
			fn() => $this->design_write()
		);
		$this->ability(
			'update-design-variable',
			'Modificar variable global de Elementor 4',
			'Cambia el label, el valor y/o el tipo de una variable global existente, con el mismo id. CAMBIA TODO EL SITIO EN VIVO: lo que usa la variable cambia de aspecto. Antes llamar a nexo-mcp-get-design-system. Se puede deshacer con nexo-mcp-undo-design-system-change. El tipo sólo se puede cambiar entre global-size-variable y global-custom-size-variable; el valor se valida contra el tipo resultante y el label sigue las reglas de create-design-variable. Id inexistente o ya borrado: nexo_mcp_design_not_found. Sin label, value ni type responde nexo_mcp_design_invalid. Devuelve {id, type, label, value}. Requiere el permiso del sistema de diseño y el Editor V4 activo.',
			'write',
			$this->variable_update_schema(),
			fn( array $input ) => $this->builders->update_design_variable( $input ),
			fn() => $this->design_write()
		);
		$this->ability(
			'delete-design-variable',
			'Eliminar variable global de Elementor 4',
			'Elimina una variable global (borrado lógico de Elementor). CAMBIA TODO EL SITIO EN VIVO: lo que usa la variable pierde ese valor. Antes llamar a nexo-mcp-get-design-system. Si la variable está en uso se rechaza con nexo_mcp_design_in_use y data.used_in [{id, title}] (de los contenidos de Elementor y el Kit donde aparece) sin borrar nada; sólo con force: true se borra igual. Deshacer con nexo-mcp-undo-design-system-change restaura la variable con el mismo id. Pide confirmación explícita de la persona: repetir la llamada con "confirmation": "DELETE". Devuelve {deleted, pages_affected: [{id, title}]}. Requiere el permiso del sistema de diseño, el permiso destructivo y el Editor V4 activo.',
			'write',
			$this->confirmed_delete_schema( 'delete-design-variable', 'Id de la variable global (e-gv-…).' ),
			fn( array $input ) => $this->builders->delete_design_variable( $input ),
			fn() => $this->design_destroy()
		);
		$this->ability(
			'update-kit-globals',
			'Modificar colores y tipografía globales del Kit',
			'Modifica, crea y borra los colores y las tipografías globales del Kit activo de Elementor (system y custom). CAMBIA TODO EL SITIO EN VIVO. Antes llamar a nexo-mcp-get-design-system para ver los ids actuales. Se puede deshacer con nexo-mcp-undo-design-system-change (un solo nivel: restaura las cuatro listas del Kit). Cada item de colors ({id?, title?, color?}) y de typography ({id?, title?, typography_*}) con id modifica ese item (system o custom); sin id crea uno custom con id nuevo (colors pide title y color; typography pide title). color en hex #rgb, #rrggbb o #rrggbbaa. typography sólo admite las claves typography_* del control de tipografía de Elementor (familia, tamaño, peso, altura de línea, espaciado… con sus variantes por dispositivo); un valor null quita esa clave. delete es una lista de ids de items custom a borrar: uno system se rechaza con nexo_mcp_design_system_item, y borrar pide confirmación explícita de la persona (repetir con "confirmation": "DELETE") y el permiso destructivo. Sin nada que cambiar responde nexo_mcp_design_nothing_to_change; un id inexistente, nexo_mcp_design_not_found; todo error de validación es nexo_mcp_design_invalid con «ruta: motivo» y data.path, y no escribe nada. Devuelve {colors, typography} con la forma de nexo-mcp-get-design-system. Requiere el permiso del sistema de diseño (no necesita el Editor V4).',
			'write',
			$this->kit_globals_schema(),
			fn( array $input ) => $this->builders->update_kit_globals( $input ),
			fn( array $input ) => $this->design_write()
				&& ( null === IrreversibleActions::word_for( 'update-kit-globals', $input ) || $this->design_destroy() )
		);
		$this->ability(
			'create-elementor-page',
			'Crear página de Elementor 4 (borrador)',
			$this->elementor_page_description(),
			'write',
			$this->elementor_page_schema(),
			fn( array $input ) => $this->builders->create_elementor_page( $input ),
			// Crea contenido nuevo, así que no hay un post al que atar la capacidad:
			// se pide `edit_pages` como en la creación de páginas. Nunca publica.
			fn() => $this->module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'edit-elementor-section',
			'Reconstruir sección de Elementor 4',
			$this->elementor_section_description(),
			'write',
			$this->elementor_section_schema(),
			fn( array $input ) => $this->builders->edit_elementor_section( $input ),
			fn( array $input ) => $this->elementor_page_permission( $input )
		);
		$this->ability(
			'migrate-elementor-page',
			'Migrar página de Elementor 3 a elementos atómicos',
			$this->elementor_migrate_description(),
			'write',
			$this->elementor_migrate_schema(),
			fn( array $input ) => $this->builders->migrate_elementor_page( $input ),
			fn( array $input ) => $this->elementor_page_permission( $input )
		);
		$this->ability(
			'list-dynamic-tags',
			'Listar datos dinámicos de Elementor 4',
			$this->dynamic_list_description(),
			'read',
			$this->empty_schema(),
			fn() => $this->builders->list_dynamic_tags(),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_posts' )
		);
		$this->ability(
			'set-dynamic-tag',
			'Asignar dato dinámico a un elemento de Elementor 4',
			$this->dynamic_set_description(),
			'write',
			$this->dynamic_set_schema(),
			fn( array $input ) => $this->builders->set_dynamic_tag( $input ),
			fn( array $input ) => $this->dynamic_tag_permission( $input )
		);
		$this->ability(
			'list-components',
			'Listar componentes de Elementor 4',
			'Lista los componentes de Elementor 4 (bloques reutilizables guardados como datos) no archivados: id, uid, title, status y modified, sin el árbol. Con include_archived también los archivados (campo archived). Sin el módulo de componentes la lista sale vacía.',
			'read',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'include_archived' => array(
						'type'        => 'boolean',
						'description' => 'Incluir también los componentes archivados (por omisión no).',
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->list_components( $input ),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_posts' )
		);
		$this->ability(
			'create-component',
			'Crear componente de Elementor 4 (borrador)',
			$this->component_create_description(),
			'write',
			$this->component_create_schema(),
			fn( array $input ) => $this->builders->create_component( $input ),
			fn() => $this->component_create_permission()
		);
		$this->ability(
			'insert-component',
			'Insertar un componente de Elementor 4 en una página',
			$this->component_insert_description(),
			'write',
			$this->component_insert_schema(),
			fn( array $input ) => $this->builders->insert_component( $input ),
			fn( array $input ) => $this->component_insert_permission( $input )
		);
		$this->ability(
			'update-node-text',
			'Editar texto del constructor',
			'Reemplaza el texto de un elemento sin alterar la estructura de la página.' .
				' En una página publicada el cambio va a su copia de trabajo (se crea o se reutiliza; la respuesta trae id de la copia y draft_of): revísalo con nexo-mcp-get-working-copy-diff y publícalo con nexo-mcp-set-content-status. Un borrador se edita directamente.',
			'write',
			$this->builder_text_schema(),
			fn( array $input ) => $this->builders->update_node_text( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			)
		);
		$this->ability(
			'update-node-attribute',
			'Editar enlace o imagen del constructor',
			'Cambia el enlace, la imagen o el texto alternativo de un elemento. La imagen se indica por su identificador en la biblioteca de medios, no por URL.' .
				' En una página publicada el cambio va a su copia de trabajo (se crea o se reutiliza; la respuesta trae id de la copia y draft_of): revísalo con nexo-mcp-get-working-copy-diff y publícalo con nexo-mcp-set-content-status. Un borrador se edita directamente.',
			'write',
			$this->builder_attribute_schema(),
			fn( array $input ) => $this->builders->update_node_attribute( $input ),
			// Capacidad propia y no BUILDERS: cambiar a dónde apunta un enlace no
			// es lo mismo que corregir una palabra, así que se concede aparte.
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDER_ATTRS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			)
		);
		$this->ability(
			'restore-builder-backup',
			'Restaurar respaldo del constructor',
			'Devuelve la página al estado previo a la última edición del conector. Es una edición más y nunca escribe en vivo una página publicada: con el id de la copia de trabajo, o con el de una página publicada que ya tiene copia, se deshace la última edición de la copia (se restaura el respaldo propio de la copia; restaurar dos veces deja el mismo estado). Sólo si la página publicada no tiene copia se usa el respaldo del original (el estado previo a su última edición en vivo o a su última publicación) y se restaura en una copia nueva; sin respaldo del original no se crea la copia. Revisa con nexo-mcp-get-working-copy-diff y publica con nexo-mcp-set-content-status. Un borrador se restaura en su sitio.',
			'write',
			$this->builder_restore_schema(),
			fn( array $input ) => $this->builders->restore_backup( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			)
		);
		$this->ability(
			'edit-structure',
			'Duplicar elemento del constructor',
			'Duplica un elemento de Elementor (widget, columna, sección o contenedor) junto al original o antes/después de target, y devuelve los ids de la copia para editarla con nexo-mcp-update-node-text.' .
				' En una página publicada el cambio va a su copia de trabajo (se crea o se reutiliza; la respuesta trae id de la copia y draft_of): revísalo con nexo-mcp-get-working-copy-diff y publícalo con nexo-mcp-set-content-status. Un borrador se edita directamente.',
			'write',
			$this->builder_structure_schema(),
			fn( array $input ) => $this->builders->edit_structure( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			)
		);
	}

	/**
	 * Descripción de create-elementor-page: lo que un cliente de IA necesita para armar un árbol válido.
	 */
	private function elementor_page_description(): string {
		return implode(
			"\n",
			array(
				'Crea una página de Elementor 4 (elementos atómicos) como borrador y nunca la publica: se revisa y se publica después. Requiere el Editor V4 activo (get-design-system devuelve atomic_enabled). Antes de armar el árbol llama a nexo-mcp-get-design-system para reutilizar clases globales (se referencian por su id) y a nexo-mcp-list-media para ids de imágenes.',
				'',
				'ÁRBOL (elements): lista de nodos {type, settings?, styles?, elements?}. Los ids de elemento los genera el plugin (los que mandes se ignoran). Máximo 200 elementos, 10 niveles y 3 MB. Cualquier error indica la ruta, p. ej. elements[0].elements[1].settings.title, y no crea nada.',
				'TIPOS (lista cerrada): contenedores e-flexbox y e-div-block (los únicos con hijos en «elements»); widgets e-heading, e-paragraph, e-button, e-image, e-divider. En la raíz sólo van contenedores: un widget suelto en la raíz se rechaza (va dentro de un contenedor). Se rechazan e-svg, los widgets de Elementor 3 (heading, text-editor, html…) y cualquier otro tipo.',
				'',
				'PROPIEDADES (settings): cada una es {"$$type": tipo, "value": valor}. Props principales por tipo:',
				'- e-flexbox / e-div-block: classes, tag (div, header, section, article, aside, footer, main, nav), link.',
				'- e-heading: title (escaped-html), tag (h1-h6, span), link, classes.',
				'- e-paragraph: paragraph (escaped-html), tag (p, span), link, classes.',
				'- e-button: text (escaped-html), link, tag (button, a, span o div), classes.',
				'- e-image: image, link, classes.',
				'- e-divider: classes.',
				'Todas admiten además _cssid (string). No se admiten attributes ni propiedades desconocidas.',
				'FORMAS: texto {"$$type":"escaped-html","value":"Hola"} (admite b, strong, em, a, br…); cadena {"$$type":"string","value":"h2"}; booleano {"$$type":"boolean","value":true}; clases {"$$type":"classes","value":["g-ab12","mi-estilo"]}.',
				'TEXTO ENRIQUECIDO: los enlaces dentro de un escaped-html (<a href>) siguen los protocolos normales de kses de WordPress, no la regla estricta de «link»; para enlaces de importancia usa la propiedad link.',
				'ENLACE (link): {"$$type":"link","value":{"destination":{"$$type":"url","value":"https://ejemplo.com"},"isTargetBlank":{"$$type":"boolean","value":true}}}. Sólo https://, mailto: o tel: (o una ruta que empiece por «/»); nunca javascript: ni data:.',
				'IMAGEN (e-image): sólo por id de la biblioteca de medios, nunca por URL: {"$$type":"image","value":{"src":{"$$type":"image-src","value":{"id":{"$$type":"image-attachment-id","value":123},"url":null}},"size":{"$$type":"string","value":"full"}}}.',
				'',
				'ESTILOS: «styles» es un mapa id → estilo local del nodo: {"hero":{"id":"hero","type":"class","label":"hero","variants":[{"meta":{"breakpoint":"desktop","state":null},"props":{"gap":{"$$type":"size","value":{"size":16,"unit":"px"}},"color":{"$$type":"color","value":"#112233"}}}]}}. Un estilo sólo admite id, type, label y variants; cada variante sólo meta (breakpoint, state) y props. Los valores de texto de los props (color, content, aspect-ratio…) no pueden llevar ; { } < > \\ @, saltos de línea, /*, url(), image-set(), var() ni !important (los colores como #112233 o rgba(0,0,0,.5) sí; tampoco comillas ni paréntesis sin cerrar). meta.breakpoint es uno de desktop, widescreen, laptop, tablet_extra, tablet, mobile_extra o mobile; meta.state es null (base), hover, active, focus, focus-visible, checked, e--selected, e--disabled, e--playing o e--paused. Sólo se admiten propiedades de estilo del esquema de Elementor (color, font-size, font-family, gap, padding, flex-direction…) y las claves conocidas dentro de cada una; una desconocida se rechaza con su ruta. font-family lleva UNA sola familia, p. ej. {"$$type":"font-family","value":"Open Sans"} (Elementor la envuelve entre comillas). Los ids y nombres de clase cumplen /^[a-z][a-z-_0-9]*$/i. Un estilo local se aplica listándolo en las classes del MISMO nodo; una clase global existente se puede listar en cualquier nodo. No hay CSS personalizado ni HTML.',
				'',
				'EJEMPLO: [{"type":"e-flexbox","settings":{"classes":{"$$type":"classes","value":["hero"]}},"styles":{"hero":{"id":"hero","type":"class","label":"hero","variants":[{"meta":{"breakpoint":"desktop","state":null},"props":{"gap":{"$$type":"size","value":{"size":16,"unit":"px"}}}}]}},"elements":[{"type":"e-heading","settings":{"title":{"$$type":"escaped-html","value":"Bienvenidos"},"tag":{"$$type":"string","value":"h1"}}},{"type":"e-button","settings":{"text":{"$$type":"escaped-html","value":"Contacto"},"link":{"$$type":"link","value":{"destination":{"$$type":"url","value":"https://ejemplo.com/contacto"}}}}}]}]',
				'',
				'SALIDA: id, status («draft»), preview_url, edit_url (editor de Elementor) y nodes (elementos guardados). Para revisar: nexo-mcp-get-page-structure.',
			)
		);
	}

	/**
	 * Descripción de edit-elementor-section.
	 */
	private function elementor_section_description(): string {
		return implode(
			"\n",
			array(
				'Reconstruye una parte de una página de Elementor con elementos atómicos de la versión 4: reemplaza un nodo (típicamente una sección) por un árbol atómico nuevo, inserta uno antes/después/dentro de un nodo, o quita un nodo. Sirve en cualquier página de Elementor, también v3: se puede pasar de a una sección. Lo nuevo es siempre atómico. Nunca publica.',
				'',
				'DESTINO: si la página está publicada (programada o privada) el cambio va a su copia de trabajo (se crea o se reutiliza), no al original; la salida trae draft_of y preview_url. Revisa con nexo-mcp-get-working-copy-diff y la vista previa, y publica con nexo-mcp-set-content-status (publish fusiona la copia). Si es un borrador, se edita el borrador. Puedes pasar el id del original o el de su copia.',
				'',
				'ENTRADA: id (página o su copia), operation (replace | insert | delete), node_id (nodo de referencia: usa nexo-mcp-get-page-structure para ver los ids), position (sólo insert: before | after | inside; inside agrega al final de un contenedor), elements (árbol atómico nuevo; obligatorio en replace e insert, prohibido en delete), draft (opcional).',
				'',
				'ÁRBOL NUEVO: igual que en nexo-mcp-create-elementor-page (mismos tipos, props, estilos, límites y mismas validaciones; los ids de elemento los genera el plugin y nunca chocan con los de la página). Antes de armarlo, llama a nexo-mcp-get-design-system. Los topes de 200 elementos, 10 niveles y 3 MB valen para la página resultante completa. Una página v3 que ya pasa de 200 elementos admite replace e insert mientras el documento resultante no supere max(200, elementos actuales).',
				'',
				'REGLAS DE ANIDADO (se rechaza con el motivo y no se escribe nada): en la raíz sólo contenedores atómicos (e-flexbox, e-div-block), nunca un widget suelto; dentro de una columna v3 sólo widgets atómicos (un contenedor atómico ahí Elementor lo descarta en silencio); dentro de una sección v3 nada nuevo (sólo admite columnas), así que para cambiarla usa replace sobre la sección; dentro de un contenedor v3 o atómico, cualquier tipo permitido; dentro de un widget, nada. insert con before/after usa las reglas del padre del nodo de referencia; inside, las del propio nodo; replace, las del padre del nodo reemplazado. delete no puede dejar la página sin elementos en la raíz.',
				'',
				'EJEMPLOS: reemplazar la sección «abc1234» por un contenedor atómico: {"id":12,"operation":"replace","node_id":"abc1234","elements":[{"type":"e-flexbox","elements":[{"type":"e-heading","settings":{"title":{"$$type":"escaped-html","value":"Hola"}}}]}]}. Insertar un heading en una columna v3: {"id":12,"operation":"insert","node_id":"col5678","position":"inside","elements":[{"type":"e-heading","settings":{"title":{"$$type":"escaped-html","value":"Hola"}}}]}. Quitar un nodo: {"id":12,"operation":"delete","node_id":"abc1234"}.',
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, operation, node_ids (ids creados, en insert y replace) y removed (ids quitados, en replace y delete). Requiere el Editor V4 activo para replace e insert. Los nodos que no se tocan quedan idénticos; se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
			)
		);
	}

	/**
	 * Descripción de migrate-elementor-page.
	 */
	private function elementor_migrate_description(): string {
		return implode(
			"\n",
			array(
				'Migra una página de Elementor 3 (v3) a elementos atómicos de la versión 4, la página entera en una sola operación. Escribe en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Si el sitio apagó la copia de trabajo por defecto, pasa draft: true para no migrar la página publicada en vivo (sin draft se escribe en vivo con respaldo, como edit-elementor-section).',
				'',
				'SE MIGRA: section → e-flexbox en fila (html_tag → tag); column → e-div-block con su ancho en %; container flex → e-flexbox (dirección, justificación, alineación, wrap y gap); heading → e-heading (h1-h6 y span; con div o p queda v3); text-editor → un e-paragraph por párrafo cuando el HTML sólo tiene párrafos, enlaces y spans; button → e-button; image → e-image; divider → e-divider (la línea pasa a estilo). Los ids de los nodos convertidos son nuevos; los nodos que ya eran atómicos quedan igual.',
				'',
				'ESTILOS: cada nodo convertido recibe un estilo local con variantes desktop, tablet y mobile: tipografía (familia, tamaño, peso, alto de línea, espaciado, transformación) y alineación, color de texto, fondo de color clásico, margen y relleno, borde (ancho, color, radio). Los colores globales del Kit pasan a la variable de color con el mismo nombre si existe, si no al color literal del Kit; las tipografías globales, a los valores literales del Kit.',
				'',
				'SE CONSERVA v3 (Elementor 4 lo sigue mostrando, idéntico y con el mismo id): cualquier otro widget (spacer, icon-box, testimonial, compuestos…), un text-editor con formato (negrita, listas, saltos de línea…), un container grid (sus hijos sí se migran), un heading con div o p, un botón con icono, una imagen sin id o con pie de foto, un divisor con texto o icono y lo que lleve un enlace que el validador no admite. La respuesta los lista en kept_v3.',
				'',
				'ENTRADA: id (página o su copia de trabajo), draft (opcional: si el sitio no usa copia de trabajo por defecto, pide migrar en la copia de una página publicada). Revisa antes con nexo-mcp-get-page-structure; después con nexo-mcp-get-working-copy-diff y la vista previa, y publica con nexo-mcp-set-content-status. Se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
				'',
				'SALIDA: id (la copia), draft_of (el original), preview_url, migrated (cantidad de nodos v3 convertidos), kept_v3 [{id, type, reason}] (nodos que quedaron v3 y por qué) y unmapped [{id, keys}] (ajustes v3 que no se trasladaron al nodo nuevo).',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_elementor_v4_required (el Editor V4 / elementos atómicos está apagado), nexo_mcp_nothing_to_migrate (la página no tiene elementos v3 que convertir), nexo_mcp_elementor_v3_not_identical (un nodo v3 del resultado no es idéntico al original), nexo_mcp_elementor_migration_id_clash (ids repetidos en la página o un nodo cambiado que reusa un id original), y los de validación de nexo-mcp-create-elementor-page con el id del nodo. Tope de elementos: max(200, elementos actuales).',
			)
		);
	}

	/**
	 * Esquema de entrada de migrate-elementor-page.
	 *
	 * @return array<string, mixed>
	 */
	private function elementor_migrate_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Elementor o su copia de trabajo.',
				),
				'draft' => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide migrar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de entrada de edit-elementor-section.
	 *
	 * @return array<string, mixed>
	 */
	private function elementor_section_schema(): array {
		$elements = $this->elementor_page_schema()['properties']['elements'];

		// A diferencia de la creación, al borrar no se envía y el vacío lo decide el validador.
		unset( $elements['minItems'] );
		$elements['description'] = 'Árbol atómico nuevo: lista de nodos {type, settings, styles, elements}. Obligatorio en replace e insert; prohibido en delete.';

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Elementor o su copia de trabajo.',
				),
				'operation' => array(
					'type' => 'string',
					'enum' => array( 'replace', 'insert', 'delete' ),
				),
				'node_id'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Id del nodo de referencia (ver get-page-structure).',
				),
				'position'  => array(
					'type'        => 'string',
					'enum'        => array( 'before', 'after', 'inside' ),
					'description' => 'Sólo con insert.',
				),
				'elements'  => $elements,
				'draft'     => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'operation', 'node_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de entrada de create-elementor-page.
	 *
	 * @return array<string, mixed>
	 */
	private function elementor_page_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'elements' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 200,
					'description' => 'Árbol atómico: lista de nodos {type, settings, styles, elements}. Ver la descripción de la herramienta.',
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							// Sin `enum`: la lista cerrada la aplica el validador del plugin, que
							// responde con la ruta del nodo y los tipos admitidos; un rechazo del
							// esquema diría sólo «no es uno de…» sin decir dónde.
							'type'     => array(
								'type'        => 'string',
								'description' => 'e-flexbox, e-div-block, e-heading, e-paragraph, e-button, e-image o e-divider.',
							),
							'settings' => array(
								'type'                 => 'object',
								'description'          => 'Propiedades {"$$type": tipo, "value": valor}.',
								'additionalProperties' => true,
							),
							'styles'   => array(
								'type'                 => 'object',
								'description'          => 'Estilos locales: id → {id, type: "class", label, variants}.',
								'additionalProperties' => true,
							),
							'elements' => array(
								'type'        => 'array',
								'description' => 'Hijos; sólo en e-flexbox y e-div-block.',
							),
						),
						'required'             => array( 'type' ),
						'additionalProperties' => true,
					),
				),
				'slug'     => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'required'             => array( 'title', 'elements' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de restore-builder-backup: el id y, opcional, `draft`.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_restore_schema(): array {
		$schema = $this->id_schema();

		$schema['properties']['draft'] = array(
			'type'        => 'boolean',
			'description' => 'Si el sitio no usa copia de trabajo por defecto, pide restaurar en la copia de una página publicada.',
		);

		return $schema;
	}

	/**
	 * Esquema de duplicación de elementos en un constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_structure_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id'  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'target'   => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'position' => array(
					'type' => 'string',
					'enum' => array( 'before', 'after' ),
				),
				'draft'    => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'node_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de edición de texto en un constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_text_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'text'    => array(
					'type'      => 'string',
					'maxLength' => 65535,
				),
				'draft'   => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'node_id', 'text' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de edición de atributos en un constructor.
	 *
	 * `value` acepta texto o número porque el tipo depende del atributo: una
	 * dirección para el enlace, un identificador de la biblioteca para la
	 * imagen. Declararlo así evita una herramienta por atributo.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_attribute_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'attribute' => array(
					'type'        => 'string',
					'description' => 'Atributo a cambiar.',
					'enum'        => array( 'link', 'image', 'alt' ),
				),
				'value'     => array(
					'type'        => array( 'string', 'integer' ),
					'description' => 'Para link, la dirección (https, mailto, tel o ruta que empiece por «/»). Para image, el identificador del adjunto. Para alt, el texto.',
					'maxLength'   => 2000,
				),
				'draft'     => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'node_id', 'attribute', 'value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Contenido avanzado.
	 */
	private function register_content_abilities(): void {
		$this->ability(
			'list-media',
			'Listar medios',
			'Lista adjuntos y sus metadatos editoriales.',
			'read',
			$this->pagination_schema(),
			fn( array $input ) => $this->content->list_media( $input ),
			fn() => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'update-media',
			'Actualizar medio',
			'Actualiza título, texto alternativo, leyenda y descripción.',
			'write',
			$this->media_update_schema(),
			fn( array $input ) => $this->content->update_media( $input ),
			fn( array $input ) => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'import-media',
			'Importar medio',
			'Importa una imagen o PDF desde una URL HTTPS.',
			'write',
			$this->media_import_schema(),
			fn( array $input ) => $this->content->import_media( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'upload-media',
			'Subir medio',
			'Sube una imagen o PDF en base64 (hasta 2 MB). Sin content_base64 devuelve una URL de un solo uso para subirlo con curl, sin pasar el archivo por el contexto.',
			'write',
			$this->media_upload_schema(),
			fn( array $input ) => $this->content->upload_media( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'list-terms',
			'Listar términos',
			'Lista categorías y etiquetas aprobadas.',
			'read',
			$this->term_list_schema(),
			fn( array $input ) => $this->content->list_terms( $input ),
			fn() => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'read' )
		);
		$this->ability(
			'create-term',
			'Crear término',
			'Crea una categoría o etiqueta aprobada.',
			'write',
			$this->term_create_schema(),
			fn( array $input ) => $this->content->create_term( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'manage_categories' )
		);
		$this->ability(
			'list-revisions',
			'Listar revisiones',
			'Lista hasta 25 revisiones de una página o artículo.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->content->list_revisions( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'read_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'restore-revision',
			'Restaurar revisión',
			'Restaura una revisión respetando el modo sólo borradores. Es irreversible para el contenido actual: exige "confirmation": "RESTORE" tras la aprobación de la persona.',
			'write',
			$this->confirmed_id_schema( 'restore-revision' ),
			fn( array $input ) => $this->content->restore_revision( (int) $input['id'] ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_posts' )
		);
		$this->ability(
			'set-content-status',
			'Cambiar estado de contenido',
			'Publica, cambia el estado o envía a la papelera cuando el módulo de publicación está habilitado. Usa id, o ids para un lote de hasta 50.',
			'write',
			$this->status_schema(),
			fn( array $input ) => isset( $input['ids'] )
				? $this->set_status_batch( $input )
				: $this->content->set_status( (int) $input['id'], (string) $input['status'] ),
			fn( array $input ) => $this->status_permission( $input )
		);
		$this->ability(
			'get-working-copy-diff',
			'Comparar copia de trabajo',
			'Muestra qué cambió una copia de trabajo respecto a su original: título, extracto, un diff del contenido por líneas, metadatos y categorías o etiquetas, y si el original cambió desde que se creó la copia (stale). Úsala antes de pedirle a la persona que publique o descarte una copia, para explicarle exactamente qué va a cambiar. En meta, kept_on_publish lista claves que faltan en la copia pero que el original conserva al publicar (publicar nunca borra meta). Acepta el id del original o el de la copia (el que devuelve update-page o update-post).',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->content->get_working_copy_diff( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_post', WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) ) )
		);
	}

	/**
	 * Permiso de las herramientas que escriben una página de Elementor o su copia.
	 *
	 * Escriben en la copia de trabajo del original: el permiso se pide sobre el
	 * original aunque la llamada traiga el id de la copia. Nunca publican.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 */
	private function elementor_page_permission( array $input ): bool {
		return $this->module_write(
			'builders_tools_enabled',
			Capabilities::BUILDERS,
			'edit_post',
			WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
		);
	}

	/**
	 * Permiso de set-dynamic-tag: el de las herramientas de página de Elementor y, además, el scope propio.
	 *
	 * Asignar un dato dinámico exige el módulo de constructores y `edit_post` sobre el
	 * original (como edit-elementor-section) Y la capacidad `nexo_mcp_builders_dynamic`
	 * con su interruptor `builders_dynamic_enabled`, que el dueño enciende a propósito.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 */
	private function dynamic_tag_permission( array $input ): bool {
		return $this->elementor_page_permission( $input )
			&& $this->module_write(
				'builders_dynamic_enabled',
				Capabilities::BUILDERS_DYNAMIC,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			);
	}

	/**
	 * Permiso de create-component: el de constructores y, además, el scope propio.
	 *
	 * Crear un componente cambia el sitio, no sólo una página: exige el módulo de
	 * constructores Y la capacidad `nexo_mcp_builders_components` con su interruptor
	 * `builders_components_enabled`, que el dueño enciende a propósito.
	 */
	private function component_create_permission(): bool {
		return $this->module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_posts' )
			&& $this->module_write( 'builders_components_enabled', Capabilities::BUILDERS_COMPONENTS, 'edit_posts' );
	}

	/**
	 * Permiso de insert-component: el de las herramientas de página de Elementor y, además, el scope propio.
	 *
	 * Como set-dynamic-tag: módulo de constructores y `edit_post` sobre el original Y la
	 * capacidad `nexo_mcp_builders_components` con su interruptor `builders_components_enabled`.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 */
	private function component_insert_permission( array $input ): bool {
		return $this->elementor_page_permission( $input )
			&& $this->module_write(
				'builders_components_enabled',
				Capabilities::BUILDERS_COMPONENTS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			);
	}

	/**
	 * Descripción de insert-component.
	 */
	private function component_insert_description(): string {
		return implode(
			"\n",
			array(
				'Inserta en una página de Elementor 4 una instancia de un componente (widget e-component que apunta al componente): si editás el componente, cambia en todas las páginas que lo usan. Escribe en la copia de trabajo de la página (se crea o se reutiliza) con respaldo: el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Sin overrides: la instancia usa el componente tal cual. Requiere Elementor Pro con licencia activa y el Editor V4 (si no, nexo_mcp_components_unavailable) y, además del permiso de constructores, el scope mcp:builders:components que el administrador enciende aparte.',
				'',
				'ENTRADA: id (página o su copia), node_id (nodo de referencia, ver nexo-mcp-get-page-structure), position (before, after o inside; inside sólo en un contenedor; en la raíz de la página no cabe un componente), component_id (id de nexo-mcp-list-components) y draft (opcional).',
				'SALIDA: id (la copia), draft_of, preview_url, operation y node_ids con el id del nodo nuevo.',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_component_not_found (no existe, está archivado o en la papelera: Elementor lo renderizaría vacío sin avisar), nexo_mcp_components_unavailable, nexo_mcp_builder_node_missing y los errores de nexo-mcp-edit-elementor-section.',
			)
		);
	}

	/**
	 * Esquema de entrada de insert-component.
	 *
	 * @return array<string, mixed>
	 */
	private function component_insert_schema(): array {
		$section = $this->elementor_section_schema()['properties'];

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => $section['id'],
				'draft'        => $section['draft'],
				'node_id'      => $section['node_id'],
				'position'     => array(
					'type'        => 'string',
					'enum'        => array( 'before', 'after', 'inside' ),
					'description' => 'Dónde va la instancia respecto de node_id.',
				),
				'component_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Id del componente (ver list-components): existente, no archivado ni en la papelera.',
				),
			),
			'required'             => array( 'id', 'node_id', 'position', 'component_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de create-component.
	 */
	private function component_create_description(): string {
		return implode(
			"\n",
			array(
				'Crea un componente de Elementor 4 (bloque reutilizable guardado como datos) a partir de un árbol atómico. Nace como borrador y nunca se publica: se publica desde el editor de Elementor. Un componente es parte del sitio, no de una página. Requiere Elementor Pro con licencia activa y el Editor V4 (si no, nexo_mcp_components_unavailable y no se escribe nada) y, además del permiso de constructores, el scope mcp:builders:components que el administrador enciende aparte.',
				'',
				'ENTRADA: title (2 a 200 caracteres, único entre los componentes no archivados) y elements: el mismo árbol atómico y la misma lista cerrada que nexo-mcp-create-elementor-page (leé su descripción para tipos, props y estilos). No se admiten componentes anidados (e-component) ni datos dinámicos en esta entrega. Máximo 100 componentes en el sitio.',
				'SALIDA: id, uid (generado por el plugin), title y status («draft»). Listalos con nexo-mcp-list-components.',
				'ERRORES (no escriben nada): nexo_mcp_components_unavailable, nexo_mcp_component_invalid (título inválido o duplicado, límite de 100, ciclo, elementos no atómicos o rechazo de Elementor al guardar; trae el mensaje) y los errores de árbol de nexo-mcp-create-elementor-page (con su ruta).',
			)
		);
	}

	/**
	 * Esquema de entrada de create-component.
	 *
	 * @return array<string, mixed>
	 */
	private function component_create_schema(): array {
		$page = $this->elementor_page_schema();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array(
					'type'        => 'string',
					'minLength'   => 2,
					'maxLength'   => 200,
					'description' => 'Título del componente (único entre los no archivados).',
				),
				'elements' => $page['properties']['elements'],
			),
			'required'             => array( 'title', 'elements' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de list-dynamic-tags.
	 */
	private function dynamic_list_description(): string {
		return implode(
			"\n",
			array(
				'Lista los datos dinámicos (dynamic tags de Elementor) que se pueden asignar con nexo-mcp-set-dynamic-tag y que este sitio tiene registrados. Es una lista cerrada de tags sin datos sensibles (título, lema, URL y logo del sitio; título, extracto, fecha, URL, imagen destacada y términos de la entrada; título y URL de archivo; nombre del autor; fecha actual): shortcode, request-arg, user-info, campos personalizados, ACF y el resto no se pueden asignar.',
				'',
				'SALIDA: lista de {name, group, label, categories, settings, targets}. settings es el esquema de los ajustes admitidos del tag ({type: text | number | switch | enum | taxonomy, options?, min?, max?}); targets, las props donde sirve (widget.prop, por categoría). Los tags son de Elementor Pro: sin Pro (ningún tag registrado) devuelve una lista vacía, no un error.',
			)
		);
	}

	/**
	 * Descripción de set-dynamic-tag.
	 */
	private function dynamic_set_description(): string {
		return implode(
			"\n",
			array(
				'Asigna un dato dinámico (dynamic tag de Elementor Pro) a una prop de un elemento atómico de la versión 4: el valor se calcula en cada render para todo visitante. Escribe en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Requiere el Editor V4 activo (nexo_mcp_elementor_v4_required) y, además del permiso de constructores, el scope mcp:builders:dynamic que el administrador enciende aparte.',
				'',
				'DESTINO: si la página está publicada el cambio va a su copia de trabajo (draft_of, preview_url); si es un borrador, se edita el borrador. Si el sitio apagó la copia de trabajo por defecto, pasa draft: true para no escribir en vivo. Puedes pasar el id del original o el de su copia. Se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
				'',
				'ENTRADA: id, node_id (el elemento, ver nexo-mcp-get-page-structure), prop y tag {name, settings?}, draft (opcional). Destinos: e-heading.title, e-paragraph.paragraph, e-button.text (tags de categoría text) y e-image.image (categoría image). El grupo del tag lo pone el plugin. Usa nexo-mcp-list-dynamic-tags para ver los tags registrados y sus ajustes. Los ajustes se validan y se guardan como texto plano (lo que se guarda es el valor normalizado, devuelto en tag).',
				'',
				'EJEMPLO: {"id":12,"node_id":"abc1234","prop":"title","tag":{"name":"post-title","settings":{"before":"Hola, "}}}.',
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, node_id, prop y tag {name, group, settings}.',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_builder_node_missing (el elemento no existe), nexo_mcp_dynamic_target_unsupported (ese tipo de elemento o prop no admite datos dinámicos), nexo_mcp_dynamic_value_invalid, nexo_mcp_dynamic_tag_unknown, nexo_mcp_dynamic_group_mismatch, nexo_mcp_dynamic_category_mismatch (el tag no es de la categoría de la prop), nexo_mcp_dynamic_tag_unavailable (el tag no está registrado: falta Elementor Pro), nexo_mcp_dynamic_setting_unknown y nexo_mcp_dynamic_setting_invalid.',
			)
		);
	}

	/**
	 * Esquema de entrada de set-dynamic-tag.
	 *
	 * @return array<string, mixed>
	 */
	private function dynamic_set_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Elementor o su copia de trabajo.',
				),
				'node_id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Id del elemento (ver get-page-structure).',
				),
				'prop'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Prop del elemento: title (e-heading), paragraph (e-paragraph), text (e-button) o image (e-image).',
				),
				'tag'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'     => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 64,
							'description' => 'Nombre del tag (ver list-dynamic-tags).',
						),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => 'Ajustes del tag (before, after, fallback…, según list-dynamic-tags).',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'draft'   => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide asignar en la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'node_id', 'prop', 'tag' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Permiso de set-content-status, con un id o con un lote.
	 *
	 * Con `ids` aquí sólo se exigen los interruptores y las capacidades del
	 * conector: la capacidad de WordPress se comprueba después, elemento por
	 * elemento, igual que en una llamada individual. Así un contenido ajeno
	 * cae en `failed` sin frenar al resto.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function status_permission( array $input ) {
		if ( isset( $input['id'] ) === isset( $input['ids'] ) ) {
			return new \WP_Error(
				'nexo_mcp_status_target',
				__( 'Indica id o ids, uno de los dos.', 'nexo-mcp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $input['ids'] ) ) {
			return $this->can_set_status( (int) $input['id'], (string) ( $input['status'] ?? '' ) );
		}

		$total = is_array( $input['ids'] ) ? count( $input['ids'] ) : 0;

		// El esquema ya lo limita; se repite para no depender de que lo valide
		// quien llame a la herramienta.
		if ( $total < 1 || $total > self::LOTE_MAXIMO ) {
			return new \WP_Error(
				'nexo_mcp_status_batch_size',
				sprintf(
					/* translators: %d: máximo de IDs por llamada. */
					__( 'ids admite de 1 a %d elementos.', 'nexo-mcp' ),
					self::LOTE_MAXIMO
				),
				array( 'status' => 400 )
			);
		}

		return $this->module_write( 'publishing_enabled', Capabilities::PUBLISH, 'read' );
	}

	/**
	 * Permiso de cambiar el estado de un contenido concreto.
	 *
	 * Es el de la llamada individual y el que se aplica a cada elemento de un
	 * lote: el mismo punto para que las dos vías no puedan divergir.
	 *
	 * @param int    $post_id Contenido.
	 * @param string $status  Estado pedido.
	 */
	private function can_set_status( int $post_id, string $status ): bool {
		$post     = get_post( $post_id );
		$original = $post instanceof \WP_Post ? WorkingCopy::original_of( $post ) : 0;

		if ( $original > 0 ) {
			return $this->can_set_copy_status( $post, $original, $status );
		}

		return $this->module_write(
			'publishing_enabled',
			Capabilities::PUBLISH,
			'trash' === $status ? 'delete_post' : 'publish_posts',
			$post_id
		);
	}

	/**
	 * Permiso sobre una copia de trabajo (ADR-031).
	 *
	 * Rige lo que se hace con el original, no con la copia: descartarla pide
	 * editarlo, sin «Acciones destructivas» porque la copia no es contenido
	 * real, y publicarla pide además la capacidad de publicar el tipo. Si el
	 * original ya no existe se mira la copia, para que la fusión se rechace
	 * con su causa y una copia huérfana se pueda descartar.
	 *
	 * @param \WP_Post $copy     Copia de trabajo.
	 * @param int      $original ID del original.
	 * @param string   $status   Estado pedido.
	 */
	private function can_set_copy_status( \WP_Post $copy, int $original, string $status ): bool {
		$target = get_post( $original ) instanceof \WP_Post ? $original : (int) $copy->ID;

		if ( ! $this->module_write( 'publishing_enabled', Capabilities::PUBLISH, 'edit_post', $target ) ) {
			return false;
		}

		return 'publish' !== $status || current_user_can( 'page' === $copy->post_type ? 'publish_pages' : 'publish_posts' );
	}

	/**
	 * Cambia el estado de un lote, elemento por elemento.
	 *
	 * Una falla no detiene al resto y la respuesta sólo trae los IDs: devolver
	 * cada contenido entero gastaría en tokens lo que el lote ahorra en
	 * llamadas (ADR-030).
	 *
	 * @param array<string, mixed> $input Parámetros validados.
	 * @return array<string, mixed>
	 */
	private function set_status_batch( array $input ): array {
		$status = (string) $input['status'];
		$ids    = array_values( array_unique( array_map( 'intval', (array) $input['ids'] ) ) );
		$ok     = array();
		$failed = array();

		// Una consulta para todo el lote en vez de una por elemento.
		_prime_post_caches( $ids, false, false );

		foreach ( $ids as $id ) {
			try {
				$result = $this->can_set_status( $id, $status )
					? $this->content->set_status( $id, $status )
					: new \WP_Error( DenialExplainer::CODE, __( 'Tu usuario no puede cambiar este contenido.', 'nexo-mcp' ) );
			} catch ( \Throwable $exception ) {
				$result = new \WP_Error( 'nexo_mcp_tool_failed', __( 'La herramienta no pudo completar la solicitud.', 'nexo-mcp' ) );
			}

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'    => $id,
					'error' => $result->get_error_message(),
				);
				continue;
			}

			$ok[] = $id;
		}

		return array(
			'status' => $status,
			'ok'     => $ok,
			'failed' => $failed,
		);
	}

	/**
	 * SEO.
	 */
	private function register_seo_abilities(): void {
		$this->ability(
			'get-post-seo',
			'Obtener SEO',
			'Obtiene metadatos SEO propios de una entrada.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->seo->get_post_seo( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'seo_tools_enabled', Capabilities::SEO, 'read_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'update-post-seo',
			'Actualizar SEO',
			'Actualiza title, description, canonical, Open Graph, noindex y Schema.',
			'write',
			$this->seo_schema(),
			fn( array $input ) => $this->seo->update_post_seo( $input ),
			fn( array $input ) => $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'get-seo-settings',
			'Obtener ajustes SEO',
			'Obtiene estado del sitemap y reglas adicionales de robots.',
			'read',
			$this->empty_schema(),
			fn() => $this->seo->get_settings(),
			fn() => $this->module_read( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
		);
		$this->ability(
			'update-seo-settings',
			'Actualizar ajustes SEO',
			'Controla sitemap nativo y reglas Disallow validadas.',
			'write',
			$this->seo_settings_schema(),
			fn( array $input ) => $this->seo->update_settings( $input ),
			fn() => $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
		);
	}

	/**
	 * Apariencia.
	 */
	private function register_appearance_abilities(): void {
		$this->ability(
			'get-site-appearance',
			'Obtener apariencia global',
			'Obtiene identidad, tema activo y CSS adicional.',
			'read',
			$this->empty_schema(),
			fn() => $this->appearance->get_site_appearance(),
			fn() => $this->module_read( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' )
		);
		$this->ability(
			'update-site-identity',
			'Actualizar identidad del sitio',
			'Actualiza nombre, descripción, zona horaria y visibilidad en buscadores.',
			'write',
			$this->identity_schema(),
			fn( array $input ) => $this->appearance->update_site_identity( $input ),
			fn( array $input ) => $this->identity_permission( $input )
		);
		$this->ability(
			'update-custom-css',
			'Actualizar CSS global',
			'Actualiza CSS adicional sin editar archivos del tema.',
			'write',
			$this->css_schema(),
			fn( array $input ) => $this->appearance->update_custom_css( (string) $input['css'] ),
			fn() => $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_css' )
		);
		$this->ability(
			'list-menus',
			'Listar menús',
			'Lista menús clásicos y sus elementos.',
			'read',
			$this->empty_schema(),
			fn() => $this->appearance->list_menus(),
			fn() => $this->module_read( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' )
		);
		$this->ability(
			'upsert-menu-item',
			'Crear, actualizar o quitar elemento de menú',
			'Gestiona un elemento de menú clásico. delete:true con item_id lo quita (permiso destructivo).',
			'write',
			$this->menu_item_schema(),
			fn( array $input ) => $this->appearance->upsert_menu_item( $input ),
			fn( array $input ) => $this->menu_item_permission( $input )
		);
	}

	/**
	 * Permiso de upsert-menu-item.
	 *
	 * Quitar un elemento no pasa por la papelera, así que pide además «Acciones
	 * destructivas», y si falta lo dice con nombre para que la IA no reintente.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function menu_item_permission( array $input ) {
		if ( ! $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' ) ) {
			return false;
		}

		if ( empty( $input['delete'] ) ) {
			return true;
		}

		if ( ! (bool) $this->settings->get( 'destructive_enabled' ) ) {
			return new \WP_Error(
				DenialExplainer::CODE,
				__( 'Quitar elementos del menú requiere activar «Acciones destructivas» en el panel Nexo MCP.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		return $this->policy->can_destroy( 'delete_post', (int) ( $input['item_id'] ?? 0 ) );
	}

	/**
	 * Permiso de update-site-identity, campo por campo.
	 *
	 * Nombre, descripción y zona horaria son del módulo de apariencia; la
	 * visibilidad en buscadores es SEO, y ocultar el sitio pide además
	 * «Acciones destructivas»: sacarlo de Google cuesta semanas recuperarlo.
	 * Basta un campo sin permiso para negar la llamada entera, porque aplicar
	 * la mitad dejaría el sitio en un estado que nadie pidió.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function identity_permission( array $input ) {
		$visibilidad = array_key_exists( 'search_visible', $input );
		$apariencia  = array_intersect_key( $input, array_flip( array( 'name', 'description', 'timezone' ) ) );

		// Sin campos se exige el permiso que la herramienta tuvo siempre.
		if ( ( array() !== $apariencia || ! $visibilidad )
			&& ! $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'manage_options' )
		) {
			return false;
		}

		if ( ! $visibilidad ) {
			return true;
		}

		$ocultar = ! rest_sanitize_boolean( $input['search_visible'] );

		if ( $ocultar && ! (bool) $this->settings->get( 'destructive_enabled' ) ) {
			return new \WP_Error(
				DenialExplainer::CODE,
				__( 'Ocultar el sitio de los buscadores requiere activar «Acciones destructivas» en el panel Nexo MCP.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		return $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
			&& ( ! $ocultar || $this->policy->can_destroy( 'manage_options' ) );
	}

	/**
	 * Mantenimiento.
	 */
	private function register_maintenance_abilities(): void {
		$this->ability(
			'list-plugins',
			'Listar plugins',
			'Lista plugins, estado y actualizaciones disponibles.',
			'read',
			$this->empty_schema(),
			fn() => $this->maintenance->list_plugins(),
			fn() => $this->module_read( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'update_plugins' )
		);
		$this->ability(
			'list-themes',
			'Listar temas',
			'Lista temas instalados, cuál está activo y sus actualizaciones.',
			'read',
			$this->empty_schema(),
			fn() => $this->maintenance->list_themes(),
			fn() => $this->module_read( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'switch_themes' )
		);
		$this->ability(
			'prepare-plugin-action',
			'Preparar acción de plugin o tema',
			'Valida una actualización, activación, desactivación o instalación de plugin o tema y genera aprobación temporal. Si el panel lo permite, también borra plugins o temas desactivados.',
			'write',
			$this->plugin_action_schema(),
			fn( array $input ) => $this->maintenance->prepare_action( $input ),
			fn( array $input ) => $this->module_write(
				'maintenance_tools_enabled',
				Capabilities::MAINTENANCE,
				$this->maintenance_capability( (string) ( $input['action'] ?? '' ), (string) ( $input['target'] ?? 'plugin' ) )
			)
		);
		$this->ability(
			'execute-plugin-action',
			'Ejecutar acción de plugin',
			'Consume una aprobación temporal y ejecuta una sola operación.',
			'write',
			$this->approval_schema(),
			fn( array $input ) => $this->maintenance->execute_action( (string) $input['approval_token'] ),
			fn() => $this->module_write( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'update_plugins' )
		);
	}

	/**
	 * WooCommerce.
	 */
	private function register_commerce_abilities(): void {
		$this->ability(
			'list-products',
			'Listar productos',
			'Lista productos WooCommerce mediante CRUD oficial.',
			'read',
			$this->product_list_schema(),
			fn( array $input ) => $this->products->list_products( $input ),
			fn() => $this->module_read( 'woocommerce_tools_enabled', Capabilities::COMMERCE, 'edit_products' )
		);
		$this->ability(
			'get-product',
			'Obtener producto',
			'Obtiene un producto simple o variable con sus variaciones.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->products->get_product( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'woocommerce_tools_enabled', Capabilities::COMMERCE, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'upsert-product',
			'Crear o actualizar producto',
			'Gestiona productos simples, variables, atributos, precios, stock e imágenes.',
			'write',
			$this->product_schema(),
			fn( array $input ) => $this->products->upsert_product( $input ),
			fn( array $input ) => $this->module_write(
				'woocommerce_tools_enabled',
				Capabilities::COMMERCE,
				! empty( $input['id'] ) ? 'edit_post' : 'edit_products',
				...( ! empty( $input['id'] ) ? array( (int) $input['id'] ) : array() )
			)
		);
		$this->ability(
			'delete-product',
			'Eliminar producto',
			'Envía un producto a papelera o lo elimina definitivamente con permiso destructivo.',
			'write',
			$this->product_delete_schema(),
			fn( array $input ) => $this->products->delete_product( (int) $input['id'], ! empty( $input['force'] ) ),
			fn( array $input ) => (bool) $this->settings->get( 'woocommerce_tools_enabled' )
				&& $this->policy->can_destroy( 'delete_post', (int) ( $input['id'] ?? 0 ) )
				&& current_user_can( Capabilities::COMMERCE )
		);
	}

	/**
	 * Registra una ability con ejecución auditada.
	 *
	 * @param string               $name        Nombre corto.
	 * @param string               $label       Etiqueta.
	 * @param string               $description Descripción.
	 * @param string               $operation   read o write.
	 * @param array<string, mixed> $input_schema Esquema.
	 * @param callable             $callback    Negocio.
	 * @param callable             $permission  Autorización.
	 */
	private function ability(
		string $name,
		string $label,
		string $description,
		string $operation,
		array $input_schema,
		callable $callback,
		callable $permission
	): void {
		$array_outputs = array( 'list-media', 'list-terms', 'list-revisions', 'list-menus', 'list-plugins', 'list-themes', 'list-dynamic-tags', 'list-components' );

		// MCP exige que `outputSchema` describa un objeto. El adaptador envuelve
		// por su cuenta los esquemas que no lo son, pero esa transformación no
		// ocurre en todos los entornos y un cliente estricto descarta entonces el
		// catálogo completo. El envoltorio se declara aquí para que el contrato
		// sea el mismo en cualquier servidor.
		$wraps_result  = in_array( $name, $array_outputs, true );
		$output_schema = $wraps_result
			? array(
				'type'                 => 'object',
				'properties'           => array(
					'result' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'required'             => array( 'result' ),
				'additionalProperties' => false,
			)
			: array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);

		wp_register_ability(
			'nexo-mcp/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => AbilityRegistrar::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => function ( array $input ) use ( $name, $operation, $callback, $wraps_result ) {
					// Acciones irreversibles: sin la palabra exacta no se ejecuta.
					$confirmed = IrreversibleActions::check( $name, $input );

					if ( is_wp_error( $confirmed ) ) {
						return $this->executor->denied( $name, $operation, $input, $confirmed );
					}

					$output = 'read' === $operation
						? $this->executor->read( $name, $input, fn() => $callback( $input ) )
						: $this->executor->write( $name, $input, fn() => $callback( $input ) );

					// El envoltorio acompaña al esquema declarado más arriba: si el
					// contrato promete un objeto con `result`, la salida debe traerlo.
					if ( $wraps_result && ! is_wp_error( $output ) ) {
						return array( 'result' => $output );
					}

					return $output;
				},
				// Todas las abilities extendidas pasan por acá, así que basta este
				// punto para que una denegación diga su causa en vez del
				// «Permission denied» que el adaptador emite ante un `false`.
				// El parámetro es opcional porque la Abilities API sólo lo entrega
				// cuando la ability declara un esquema de entrada, y este envoltorio
				// cubre a todas por igual.
				'permission_callback' => function ( array $input = array() ) use ( $name, $permission, $operation ) {
					$allowed = $permission( $input );

					if ( true === $allowed ) {
						return true;
					}

					$reason = is_wp_error( $allowed ) ? $allowed : $this->explainer->explain( $operation );

					return $this->executor->denied( $name, $operation, $input, $reason );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => 'read' === $operation,
						'destructive' => in_array( $name, array( 'delete-product', 'execute-plugin-action', 'restore-revision', 'undo-design-system-change', 'delete-global-class', 'delete-design-variable', 'update-kit-globals' ), true ),
						'idempotent'  => in_array( $name, array( 'get-post-seo', 'get-seo-settings', 'get-site-appearance', 'get-product' ), true ),
					),
				),
			)
		);
	}

	/**
	 * Permiso de lectura modular.
	 *
	 * @param string $setting  Interruptor.
	 * @param string $nexo_cap Capacidad Nexo.
	 * @param string $wp_cap   Capacidad WordPress.
	 * @param mixed  ...$args  Argumentos de meta capability.
	 */
	private function module_read( string $setting, string $nexo_cap, string $wp_cap, ...$args ): bool {
		return $this->policy->can_module_read( $setting, $nexo_cap, $wp_cap, ...$args );
	}

	/**
	 * Permiso de escritura modular.
	 *
	 * @param string $setting  Interruptor.
	 * @param string $nexo_cap Capacidad Nexo.
	 * @param string $wp_cap   Capacidad WordPress.
	 * @param mixed  ...$args  Argumentos de meta capability.
	 */
	private function module_write( string $setting, string $nexo_cap, string $wp_cap, ...$args ): bool {
		return $this->policy->can_module_write( $setting, $nexo_cap, $wp_cap, ...$args );
	}

	/**
	 * Permiso de escritura del sistema de diseño (cambia todo el sitio en vivo).
	 */
	private function design_write(): bool {
		return $this->module_write( 'design_system_tools_enabled', Capabilities::DESIGN_SYSTEM, 'manage_options' );
	}

	/**
	 * Permiso de borrar en el sistema de diseño: el de escritura más el destructivo.
	 */
	private function design_destroy(): bool {
		return $this->design_write() && $this->policy->can_destroy( 'manage_options' );
	}

	/**
	 * Reglas de las variantes de una clase global, compartidas por crear y modificar.
	 */
	private function class_variants_rules(): string {
		return 'variants es una lista (puede ir vacía) de {meta: {breakpoint, state}, props, custom_css: null} con las mismas validaciones que los estilos locales de nexo-mcp-create-elementor-page: meta.breakpoint es uno de ' . implode( ', ', ElementorTreeValidator::BREAKPOINTS ) . '; meta.state es null (base), ' . implode( ', ', array_filter( ElementorTreeValidator::STATES ) ) . '; props sólo admite propiedades de estilo del esquema de Elementor con sus claves conocidas ({"gap":{"$$type":"size","value":{"size":16,"unit":"px"}},"color":{"$$type":"color","value":"#112233"}}); los valores de texto no pueden llevar ; { } < > \ @, saltos de línea, /*, url(), image-set(), var() ni !important, ni comillas o paréntesis sin cerrar (las variables globales van como $$type global-color-variable, etc.). No hay CSS personalizado (custom_css debe ser null).';
	}

	/**
	 * Esquema del campo de confirmación de una acción irreversible, obligatorio o no.
	 *
	 * @param string $tool Nombre corto de la herramienta.
	 *
	 * @return array<string, mixed>
	 */
	private function confirmation_schema( string $tool ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'confirmation' => IrreversibleActions::schema_for( $tool ),
			),
			'required'             => array( 'confirmation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de borrar una clase o variable global: id, force y confirmación.
	 *
	 * @param string $tool        Nombre corto de la herramienta.
	 * @param string $id_describe Descripción del id.
	 *
	 * @return array<string, mixed>
	 */
	private function confirmed_delete_schema( string $tool, string $id_describe ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => $id_describe,
				),
				'force'        => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'true borra aunque esté en uso (sin force, en uso se rechaza con la lista de dónde).',
				),
				'confirmation' => IrreversibleActions::schema_for( $tool ),
			),
			'required'             => array( 'id', 'confirmation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de la lista de variantes de una clase global.
	 *
	 * @return array<string, mixed>
	 */
	private function class_variants_schema(): array {
		return array(
			'type'        => 'array',
			'description' => 'Variantes de estilo: [{meta:{breakpoint,state}, props:{…}, custom_css:null}].',
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'meta'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'breakpoint' => array(
								'type' => 'string',
								'enum' => ElementorTreeValidator::BREAKPOINTS,
							),
							'state'      => array(
								'type' => array( 'string', 'null' ),
								'enum' => ElementorTreeValidator::STATES,
							),
						),
						'additionalProperties' => false,
					),
					'props'      => array(
						'type'                 => 'object',
						'description'          => 'Propiedades de estilo de Elementor: {propiedad: {"$$type": …, "value": …}}.',
						'additionalProperties' => true,
					),
					'custom_css' => array(
						'type' => 'null',
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Esquema de crear una clase global.
	 *
	 * @return array<string, mixed>
	 */
	private function class_create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'    => array(
					'type'        => 'string',
					'maxLength'   => 50,
					'description' => 'Nombre de la clase: empieza con letra; letras, números, guiones y guiones bajos.',
				),
				'variants' => $this->class_variants_schema(),
			),
			'required'             => array( 'label', 'variants' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de modificar una clase global.
	 *
	 * @return array<string, mixed>
	 */
	private function class_update_schema(): array {
		$schema = $this->class_create_schema();

		$schema['properties'] = array_merge(
			array(
				'id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Id de la clase global (g-…).',
				),
			),
			$schema['properties']
		);
		$schema['required']   = array( 'id' );

		return $schema;
	}

	/**
	 * Propiedades comunes de las variables globales.
	 *
	 * @return array<string, mixed>
	 */
	private function variable_properties(): array {
		return array(
			'type'  => array(
				'type'        => 'string',
				'enum'        => DesignVariableValue::types(),
				'description' => 'Tipo de variable. Al modificar sólo se puede cambiar entre global-size-variable y global-custom-size-variable.',
			),
			'label' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 50,
				'description' => 'Etiqueta: letras sin tildes, números, guiones y guiones bajos (nombre de la propiedad CSS --etiqueta); no empieza con --.',
			),
			'value' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 512,
				'description' => 'Valor según el tipo: #112233, Open Sans, 16px, clamp(1rem, 2vw, 3rem).',
			),
		);
	}

	/**
	 * Esquema de crear una variable global.
	 *
	 * @return array<string, mixed>
	 */
	private function variable_create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => $this->variable_properties(),
			'required'             => array( 'type', 'label', 'value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de modificar una variable global.
	 *
	 * @return array<string, mixed>
	 */
	private function variable_update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'id' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Id de la variable global (e-gv-…).',
					),
				),
				$this->variable_properties()
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de los colores y la tipografía del Kit.
	 *
	 * @return array<string, mixed>
	 */
	private function kit_globals_schema(): array {
		$typography = array(
			'id'    => array(
				'type'        => 'string',
				'description' => 'Id del item existente (system o custom); sin id se crea uno custom.',
			),
			'title' => array(
				'type'      => 'string',
				'maxLength' => 100,
			),
		);

		foreach ( DesignKitOperations::typography_keys() as $key => $spec ) {
			if ( 'select' === $spec['type'] ) {
				$typography[ $key ] = array(
					'type' => array( 'string', 'integer', 'null' ),
					'enum' => array_merge( $spec['options'] ?? array(), array( null ) ),
				);
			} elseif ( 'size' === $spec['type'] ) {
				$typography[ $key ] = array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'unit' => array(
							'type' => 'string',
							'enum' => $spec['units'] ?? array(),
						),
						'size' => array(
							'type' => array( 'number', 'string' ),
						),
					),
					'required'             => array( 'unit', 'size' ),
					'additionalProperties' => false,
				);
			} else {
				$typography[ $key ] = array(
					'type' => array( 'string', 'null' ),
				);
			}
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'colors'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'    => array(
								'type'        => 'string',
								'description' => 'Id del color existente (system o custom); sin id se crea uno custom.',
							),
							'title' => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'color' => array(
								'type'        => 'string',
								'description' => 'Hex: #rgb, #rrggbb o #rrggbbaa.',
							),
						),
						'additionalProperties' => false,
					),
				),
				'typography'   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => $typography,
						'additionalProperties' => false,
					),
				),
				'delete'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Ids de colores o tipografías custom a borrar (los system no se borran). Pide confirmation.',
				),
				'confirmation' => array(
					'type'        => 'string',
					'enum'        => array( 'DELETE' ),
					'description' => 'Obligatoria si delete trae ids: escribe DELETE sólo después de que la persona lo apruebe.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquemas compartidos.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_schema(): array {
		return array(
			'type'                 => 'object',
			// Un arreglo PHP vacío se serializa como `[]`, y JSON Schema exige un
			// objeto en `properties`. Los clientes MCP que validan el catálogo
			// rechazan la lista completa por ese detalle, así que se fuerza `{}`
			// en lugar de confiar en que otra capa lo normalice.
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de identificador con confirmación de acción irreversible.
	 *
	 * @param string $tool Nombre corto de la herramienta.
	 * @return array<string, mixed>
	 */
	private function confirmed_id_schema( string $tool ): array {
		$schema = $this->id_schema();

		$schema['properties']['confirmation'] = IrreversibleActions::schema_for( $tool );
		$schema['required'][]                 = 'confirmation';

		return $schema;
	}

	/**
	 * Devuelve el esquema de identificador.
	 *
	 * @return array<string, mixed>
	 */
	private function id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de paginación.
	 *
	 * @return array<string, mixed>
	 */
	private function pagination_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de actualización de medios.
	 *
	 * @return array<string, mixed>
	 */
	private function media_update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'         => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'caption'     => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 20000,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de importación de medios.
	 *
	 * @return array<string, mixed>
	 */
	private function media_import_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'   => array(
					'type'      => 'string',
					'format'    => 'uri',
					'maxLength' => 2000,
				),
				'title' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'   => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de carga directa de medios.
	 *
	 * El tope de `content_base64` son 2 MB de archivo ya codificados, que en
	 * base64 ocupan un tercio más. Cortarlo en el esquema evita que un cliente
	 * mande megabytes de texto para que se rechacen al final. Es opcional: sin
	 * él la herramienta entrega un enlace de subida (ADR-030).
	 *
	 * @return array<string, mixed>
	 */
	private function media_upload_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_base64' => array(
					'type'        => 'string',
					'description' => 'Contenido del archivo codificado en base64.',
					'maxLength'   => 2796204,
				),
				'filename'       => array(
					'type'        => 'string',
					'description' => 'Nombre propuesto. La extensión final la decide el contenido real.',
					'maxLength'   => 200,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'            => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de listado de términos.
	 *
	 * @return array<string, mixed>
	 */
	private function term_list_schema(): array {
		$schema                           = $this->pagination_schema();
		$schema['properties']['taxonomy'] = array(
			'type' => 'string',
			'enum' => array( 'category', 'post_tag', 'product_cat', 'product_tag' ),
		);
		$schema['required']               = array( 'taxonomy' );
		return $schema;
	}

	/**
	 * Devuelve el esquema de creación de términos.
	 *
	 * @return array<string, mixed>
	 */
	private function term_create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag', 'product_cat', 'product_tag' ),
				),
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'slug'        => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9-]+$',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de estados.
	 *
	 * @return array<string, mixed>
	 */
	private function field_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'  => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'value' => array(
					'type' => array( 'string', 'number', 'boolean' ),
				),
			),
			'required'             => array( 'id', 'name', 'value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de estados.
	 *
	 * @return array<string, mixed>
	 */
	private function status_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				// Sin `oneOf` para exigir uno de los dos: los clientes que lo
				// rechazan en la raíz descartarían el catálogo. Lo exige el permiso.
				'ids'    => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::LOTE_MAXIMO,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'status' => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'private', 'publish', 'trash' ),
				),
			),
			'required'             => array( 'status' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema SEO por contenido.
	 *
	 * @return array<string, mixed>
	 */
	private function seo_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'canonical'      => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'noindex'        => array( 'type' => 'boolean' ),
				'og_title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'og_description' => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'og_image'       => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'schema'         => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema SEO global.
	 *
	 * @return array<string, mixed>
	 */
	private function seo_settings_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sitemaps_enabled' => array( 'type' => 'boolean' ),
				'disallow_paths'   => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de identidad.
	 *
	 * @return array<string, mixed>
	 */
	private function identity_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'           => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'timezone'       => array(
					'type'        => 'string',
					'description' => 'Zona IANA, p. ej. America/Santiago.',
					'maxLength'   => 64,
				),
				'search_visible' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de CSS.
	 *
	 * @return array<string, mixed>
	 */
	private function css_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'css' => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
			),
			'required'             => array( 'css' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de elementos de menú.
	 *
	 * @return array<string, mixed>
	 */
	private function menu_item_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'item_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'url'       => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'object_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'parent'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'position'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'delete'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'menu_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de acciones de plugins.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_action_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action' => array(
					'type' => 'string',
					'enum' => array( 'update', 'activate', 'deactivate', 'install', 'delete' ),
				),
				'target' => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'plugin' => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'theme'  => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'slug'   => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9-]+$',
					'maxLength' => 200,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Traduce acción y objetivo a la capacidad que WordPress exige.
	 *
	 * Un tema y un plugin no comparten permisos: quien puede actualizar plugins
	 * no necesariamente puede cambiar el aspecto del sitio.
	 *
	 * @param string $action Acción solicitada.
	 * @param string $target plugin o theme.
	 */
	private function maintenance_capability( string $action, string $target ): string {
		if ( 'theme' === $target ) {
			if ( 'install' === $action ) {
				return 'install_themes';
			}

			if ( 'delete' === $action ) {
				return 'delete_themes';
			}

			return 'activate' === $action ? 'switch_themes' : 'update_themes';
		}

		if ( 'install' === $action ) {
			return 'install_plugins';
		}

		if ( 'delete' === $action ) {
			return 'delete_plugins';
		}

		return in_array( $action, array( 'activate', 'deactivate' ), true ) ? 'activate_plugins' : 'update_plugins';
	}

	/**
	 * Devuelve el esquema de aprobación.
	 *
	 * @return array<string, mixed>
	 */
	private function approval_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'approval_token' => array(
					'type'      => 'string',
					'minLength' => 20,
					'maxLength' => 100,
				),
			),
			'required'             => array( 'approval_token' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de listado de productos.
	 *
	 * @return array<string, mixed>
	 */
	private function product_list_schema(): array {
		$schema                         = $this->pagination_schema();
		$schema['properties']['status'] = array(
			'type'    => 'string',
			'enum'    => array( 'any', 'draft', 'pending', 'private', 'publish' ),
			'default' => 'any',
		);
		$schema['properties']['type']   = array(
			'type' => 'string',
			'enum' => array( 'simple', 'variable' ),
		);
		$schema['properties']['sku']    = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		return $schema;
	}

	/**
	 * Devuelve el esquema de producto.
	 *
	 * @return array<string, mixed>
	 */
	private function product_schema(): array {
		$price = array(
			'type'    => 'string',
			'pattern' => '^[0-9]+(?:\\.[0-9]{1,6})?$',
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'type'              => array(
					'type'    => 'string',
					'enum'    => array( 'simple', 'variable' ),
					'default' => 'simple',
				),
				'name'              => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 300,
				),
				'slug'              => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'       => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'short_description' => array(
					'type'      => 'string',
					'maxLength' => 10000,
				),
				'sku'               => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'regular_price'     => $price,
				'sale_price'        => $price,
				'stock_status'      => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'manage_stock'      => array( 'type' => 'boolean' ),
				'stock_quantity'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => 'Producto sin envío. Actívalo en servicios y productos digitales o WooCommerce pedirá dirección de entrega.',
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => 'El comprador recibe un archivo. Marca también el producto como virtual si no se indica lo contrario.',
				),
				'status'            => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'pending', 'private', 'publish' ),
					'default' => 'draft',
				),
				'categories'        => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'images'            => array(
					'type'     => 'array',
					'maxItems' => 21,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'attributes'        => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => $this->product_attribute_schema(),
				),
				'variations'        => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => $this->variation_schema( $price ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de atributo.
	 *
	 * @return array<string, mixed>
	 */
	private function product_attribute_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'options'   => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
				'visible'   => array( 'type' => 'boolean' ),
				'variation' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'name', 'options' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de variación.
	 *
	 * @param array<string, mixed> $price Esquema de precio.
	 * @return array<string, mixed>
	 */
	private function variation_schema( array $price ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sku'            => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'regular_price'  => $price,
				'sale_price'     => $price,
				'stock_status'   => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'manage_stock'   => array( 'type' => 'boolean' ),
				'stock_quantity' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'attributes'     => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de eliminación.
	 *
	 * @return array<string, mixed>
	 */
	private function product_delete_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'force'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'confirmation' => IrreversibleActions::schema_for( 'delete-product' ),
			),
			'required'             => array( 'id', 'confirmation' ),
			'additionalProperties' => false,
		);
	}
}
