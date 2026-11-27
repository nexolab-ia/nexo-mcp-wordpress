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
use NexoDev\NexoMCP\Builders\DiviLayoutGuard;
use NexoDev\NexoMCP\Builders\DesignKitOperations;
use NexoDev\NexoMCP\Builders\DesignVariableValue;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;
use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\Builders\DiviPresetOperations;
use NexoDev\NexoMCP\Builders\DiviThemeBuilderSchema;
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
		'nexo-mcp/edit-divi-module',
		'nexo-mcp/set-divi-dynamic',
		'nexo-mcp/set-divi-loop',
		'nexo-mcp/set-divi-conditions',
		'nexo-mcp/create-divi-page',
		'nexo-mcp/create-divi-layout',
		'nexo-mcp/list-divi-theme-builder',
		'nexo-mcp/set-divi-template',
		'nexo-mcp/delete-divi-template',
		'nexo-mcp/restore-divi-template',
		'nexo-mcp/list-divi-library',
		'nexo-mcp/get-divi-library-item',
		'nexo-mcp/save-divi-library-item',
		'nexo-mcp/delete-divi-library-item',
		'nexo-mcp/restore-divi-library-item',
		'nexo-mcp/insert-divi-library-item',
		'nexo-mcp/edit-divi-section',
		'nexo-mcp/get-divi-design-system',
		'nexo-mcp/undo-divi-design-change',
		'nexo-mcp/set-divi-global-color',
		'nexo-mcp/delete-divi-global-color',
		'nexo-mcp/set-divi-variable',
		'nexo-mcp/archive-divi-variable',
		'nexo-mcp/set-divi-typography',
		'nexo-mcp/get-divi-presets',
		'nexo-mcp/set-divi-preset',
		'nexo-mcp/set-divi-default-preset',
		'nexo-mcp/delete-divi-preset',
		'nexo-mcp/list-divi-dynamic-sources',
		'nexo-mcp/list-divi-conditions',
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
			'Detecta el constructor visual y lista los textos, enlaces e imágenes editables de una página o entrada, sin el HTML. Con el id de un layout del Theme Builder (encabezado, cuerpo o pie, o su copia de trabajo) pide el permiso del Theme Builder (scope mcp:divi_theme_builder) y no el de una página: nexo_mcp_divi_layout_denied sin él; los demás tipos internos de Divi se rechazan (nexo_mcp_divi_post_type_denied). Es la lectura liviana previa a nexo-mcp-update-node-text. Los nodos que no se pueden editar traen editable: false y el motivo. En Divi 5, un módulo con condiciones de visualización trae attributes.conditions: un resumen {ours, match, total, conditions: [{type, operator, enabled, supported, summary}]} (ours: true si las puso nexo-mcp-set-divi-conditions) sin valores crudos; las hechas en el Visual Builder se conservan.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->builders->get_structure( $input ),
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_read(
					'builders_tools_enabled',
					Capabilities::BUILDERS,
					'edit_post',
					(int) ( $input['id'] ?? 0 )
				)
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
			'get-divi-design-system',
			'Obtener el diseño global de Divi 5',
			'Lee el diseño global de Divi 5: la paleta de colores globales (id gcid-…, etiqueta, color y estado), las variables de diseño por tipo (numbers, strings, images, links, fonts y gradients —estos últimos sólo de lectura—, con id gvid-… y estado), los 5 colores del Customizer (accent, secondary_accent, header, font, link), las fuentes y pesos de títulos y cuerpo, el tamaño y la altura de línea del cuerpo (null = Divi usa su valor por defecto) y cuántos cambios hay para deshacer. Sólo lee. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
			'read',
			$this->empty_schema(),
			fn() => $this->builders->get_divi_design_system(),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'undo-divi-design-change',
			'Deshacer el último cambio del diseño global o de los presets de Divi',
			'Restaura el valor anterior al último cambio hecho al diseño global de Divi 5 por este conector (colores globales, variables de un tipo, colores y tipografía del Customizer, o el catálogo completo de presets de módulos —área presets—) con las mismas funciones de Divi, vacía el CSS generado y saca ese cambio del historial (se guardan los últimos 20, se deshace de a uno, el más reciente primero). Una variable creada después del cambio no se borra: queda archivada, como hace Divi. Si esa área cambió después del cambio del conector (por ejemplo desde el panel de Divi) responde nexo_mcp_divi_design_changed_since y no restaura nada: force: true la restaura igual y pisa ese cambio. Devuelve {area, tool, changed_at, remaining}. Sin cambios que deshacer responde nexo_mcp_divi_design_nothing_to_undo; sin Divi 5 activo, nexo_mcp_divi5_required; si no se puede leer o restaurar el área, nexo_mcp_divi_design_undo_failed (el respaldo se conserva); si el valor se restauró pero no se pudo sacar del historial, nexo_mcp_divi_design_history_failed. Diseño global y presets comparten una sola pila: cada área exige su propio scope (mcp:divi_design para colores, variables y tipografía; mcp:divi_presets para presets), y si el cambio de arriba es de un área para la que el token no tiene permiso responde nexo_mcp_divi_design_area_forbidden y no restaura nada. Con area (colors, variables —cualquier tipo—, theme_options o presets) deshace el último cambio de ESA área aunque haya cambios de otras encima (las áreas tocan opciones distintas), así quien sólo tiene mcp:divi_design no queda bloqueado por un cambio de presets y al revés; un area desconocida responde nexo_mcp_divi_design_area_invalid y sin cambios de esa área, nexo_mcp_divi_design_nothing_to_undo; sin area se deshace el de arriba de la pila, como siempre. Requiere el scope mcp:divi_design o mcp:divi_presets según el área, que el administrador enciende aparte (y mcp:destructive con force).',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true restaura aunque el área haya cambiado después del cambio del conector (pisa ese cambio).',
					),
					'area'  => array(
						'type'        => 'string',
						'enum'        => array( 'colors', 'variables', 'theme_options', 'presets' ),
						'description' => 'Deshacer el último cambio de esta área aunque haya otros encima. Sin ella se deshace el de arriba de la pila.',
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->undo_divi_design_change(
				true === ( $input['force'] ?? false ),
				fn( string $area ): bool => $this->divi_area_write( $area ),
				isset( $input['area'] ) && is_string( $input['area'] ) ? $input['area'] : null
			),
			fn( array $input = array() ) => $this->divi_area_write( isset( $input['area'] ) && is_string( $input['area'] ) ? $input['area'] : $this->builders->divi_last_change_area() ) && ( empty( $input['force'] ) || $this->policy->can_destroy( 'manage_options' ) )
		);
		$this->ability(
			'set-divi-global-color',
			'Crear o cambiar un color global de Divi 5',
			'Crea (sin id) o cambia (con id) un color de la paleta global de Divi 5. CAMBIA TODO EL SITIO EN VIVO: las páginas que usan el color cambian al instante. Antes llamar a nexo-mcp-get-divi-design-system para reutilizar lo que existe. Se deshace con nexo-mcp-undo-divi-design-change. Sin id se crea uno nuevo (gcid- y 10 caracteres a-z0-9); se usa en los módulos con $variable({"type":"color","value":{"name":"gcid-…","settings":{}}})$. color: #hex de 3, 4, 6 u 8 dígitos, rgb()/rgba() o hsl()/hsla(); label: texto plano de 1 a 100 caracteres. Los 5 colores del Customizer (gcid-primary-color, gcid-secondary-color, gcid-heading-color, gcid-body-color, gcid-link-color) no se editan aquí sino con nexo-mcp-set-divi-typography (nexo_mcp_divi_design_customizer_color). Id inexistente: nexo_mcp_divi_design_not_found. Todo error de validación es nexo_mcp_divi_design_invalid con «ruta: motivo» y data.path, y no escribe nada. Devuelve {id, label, color, created, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_design que el administrador enciende aparte.',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'string',
						'description' => 'Id del color a cambiar (gcid-…). Sin id se crea uno nuevo.',
					),
					'label' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 100,
						'description' => 'Nombre del color, texto plano de 1 a 100 caracteres.',
					),
					'color' => array(
						'type'        => 'string',
						'description' => 'Color: #hex (3, 4, 6 u 8 dígitos), rgb()/rgba() o hsl()/hsla().',
					),
				),
				'required'             => array( 'label', 'color' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->set_divi_global_color( $input ),
			fn() => $this->divi_design_write()
		);
		$this->ability(
			'delete-divi-global-color',
			'Borrar un color global de Divi 5',
			'Borra un color de la paleta global de Divi 5. CAMBIA TODO EL SITIO EN VIVO: los módulos que lo usan quedan sin ese color (var(--gcid-…) sin definir). Antes llamar a nexo-mcp-get-divi-design-system. Busca el uso (gcid-… en el contenido de las entradas de Divi 5 no borradas): si está en uso y no se manda force responde nexo_mcp_divi_color_in_use con data.post_ids y no borra nada; con force: true se borra igual y además exige el permiso destructivo. Se deshace con nexo-mcp-undo-divi-design-change (restaura la paleta, con el mismo id). Los colores del Customizer no se borran (nexo_mcp_divi_design_customizer_color). Id inexistente: nexo_mcp_divi_design_not_found; id mal formado: nexo_mcp_divi_design_invalid. Devuelve {deleted, posts_affected, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_design que el administrador enciende aparte (y mcp:destructive con force).',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Id del color global (gcid-…).',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true borra aunque esté en uso (sin force, en uso se rechaza con la lista de entradas); exige el permiso destructivo.',
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->delete_divi_global_color( $input ),
			fn( array $input = array() ) => $this->divi_design_write() && ( empty( $input['force'] ) || $this->policy->can_destroy( 'manage_options' ) )
		);
		$this->ability(
			'set-divi-variable',
			'Crear o cambiar una variable de diseño de Divi 5',
			'Crea (sin id) o cambia (con id) una variable de diseño de Divi 5 de un tipo. CAMBIA TODO EL SITIO EN VIVO: lo que usa la variable cambia al instante. Antes llamar a nexo-mcp-get-divi-design-system. Se deshace con nexo-mcp-undo-divi-design-change. type: numbers (número con unidad px, em, rem, %, vw o vh, o sin unidad), strings (texto plano de hasta 200 caracteres), images (id de un adjunto, se guarda su URL, o URL http/https), links (http, https, mailto, tel o ruta relativa) o fonts (nombre de familia con letras, números, espacios, comillas simples, guion y guion bajo, hasta 100); los gradientes no se escriben (nexo_mcp_divi_design_type_invalid). label: texto plano de 1 a 100 caracteres. Sin id se crea (gvid- y 10 caracteres a-z0-9), con id se cambia (nexo_mcp_divi_design_not_found si no existe en ese tipo). Se usa en los módulos con $variable({"type":"content","value":{"name":"gvid-…","settings":{}}})$. Todo error de validación es nexo_mcp_divi_design_invalid con «ruta: motivo» y data.path, y no escribe nada. Devuelve {type, id, label, value, created, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_design que el administrador enciende aparte (y que el usuario pueda gestionar las variables de Divi).',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'type'  => array(
						'type'        => 'string',
						'enum'        => DiviDesignStore::WRITE_TYPES,
						'description' => 'Tipo de variable.',
					),
					'id'    => array(
						'type'        => 'string',
						'description' => 'Id de la variable a cambiar (gvid-…). Sin id se crea una nueva.',
					),
					'label' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 100,
						'description' => 'Nombre de la variable, texto plano de 1 a 100 caracteres.',
					),
					'value' => array(
						'type'        => array( 'string', 'number' ),
						'description' => 'Valor según el tipo: numbers (12px, 1.5rem, 50%, 16), strings (texto), images (id de adjunto o URL), links (URL o ruta), fonts (familia).',
					),
				),
				'required'             => array( 'type', 'label', 'value' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->set_divi_variable( $input ),
			fn() => $this->divi_design_write()
		);
		$this->ability(
			'archive-divi-variable',
			'Archivar o reactivar una variable de diseño de Divi 5',
			'Archiva (archived: true) o reactiva (archived: false) una variable de diseño de Divi 5; no se borra, como hace Divi. CAMBIA TODO EL SITIO EN VIVO. Antes llamar a nexo-mcp-get-divi-design-system. Se deshace con nexo-mcp-undo-divi-design-change. Id inexistente en ese tipo: nexo_mcp_divi_design_not_found; datos inválidos: nexo_mcp_divi_design_invalid (con «ruta: motivo»); si ya estaba en ese estado no escribe nada y responde changed: false. Devuelve {type, id, status, changed, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_design que el administrador enciende aparte.',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'type'     => array(
						'type'        => 'string',
						'enum'        => DiviDesignStore::WRITE_TYPES,
						'description' => 'Tipo de variable.',
					),
					'id'       => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Id de la variable (gvid-…).',
					),
					'archived' => array(
						'type'        => 'boolean',
						'description' => 'true archiva, false reactiva.',
					),
				),
				'required'             => array( 'type', 'id', 'archived' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->archive_divi_variable( $input ),
			fn() => $this->divi_design_write()
		);
		$this->ability(
			'set-divi-typography',
			'Cambiar la tipografía base y los colores del Customizer de Divi 5',
			'Cambia a la vez las fuentes y pesos de títulos y cuerpo, el tamaño y la altura de línea del cuerpo y los 5 colores del Customizer de Divi 5 (colors: accent, secondary_accent, header, font, link). CAMBIA TODO EL SITIO EN VIVO. Antes llamar a nexo-mcp-get-divi-design-system. Todo o nada: si un campo es inválido no se escribe ninguno; si Divi falla a mitad se revierte lo ya escrito. Se deshace con nexo-mcp-undo-divi-design-change. heading_font y body_font: nombre de familia con letras, números, espacios, comillas simples, guion y guion bajo (hasta 100); pesos: 100 a 900, normal o bold; body_font_size: 8 a 72 px (16 o 16px); body_line_height: 1 a 3 (1.7); colores: #hex de 3, 4, 6 u 8 dígitos, rgb()/rgba() o hsl()/hsla(). Sin ningún campo responde nexo_mcp_divi_design_nothing_to_change; todo error de validación es nexo_mcp_divi_design_invalid con «ruta: motivo» y data.path. Devuelve {changed, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_design que el administrador enciende aparte.',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'heading_font'        => array(
						'type'        => 'string',
						'description' => 'Familia de los títulos.',
					),
					'body_font'           => array(
						'type'        => 'string',
						'description' => 'Familia del cuerpo.',
					),
					'heading_font_weight' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Peso de los títulos: 100 a 900, normal o bold.',
					),
					'body_font_weight'    => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Peso del cuerpo: 100 a 900, normal o bold.',
					),
					'body_font_size'      => array(
						'type'        => array( 'string', 'number' ),
						'description' => 'Tamaño del cuerpo en px, de 8 a 72 (16 o 16px).',
					),
					'body_line_height'    => array(
						'type'        => array( 'string', 'number' ),
						'description' => 'Altura de línea del cuerpo, de 1 a 3 (1.7).',
					),
					'colors'              => array(
						'type'                 => 'object',
						'description'          => 'Colores del Customizer de Divi.',
						'properties'           => array(
							'accent'           => array( 'type' => 'string' ),
							'secondary_accent' => array( 'type' => 'string' ),
							'header'           => array( 'type' => 'string' ),
							'font'             => array( 'type' => 'string' ),
							'link'             => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->set_divi_typography( $input ),
			fn() => $this->divi_design_write()
		);
		$this->ability(
			'get-divi-presets',
			'Obtener los presets de módulos de Divi 5',
			'Lee los presets de Divi 5: los de módulo (uno por módulo, con su id, nombre, fechas y cuál es el predeterminado del módulo) y los de grupo de opciones (fondo, borde, fuente, espaciado…, con su grupo, el módulo para el que se hicieron y el predeterminado de cada grupo), más los grupos de opciones disponibles para cada módulo del catálogo (available_groups: id de grupo y nombre). Un preset pesa en todos los módulos que lo usan y el predeterminado en todos los que no tienen otro: lo que diga un attr propio del módulo gana siempre. Con module (por ejemplo divi/text; sólo módulos del catálogo de create-divi-page, si no responde nexo_mcp_divi_preset_module_unknown) devuelve además los attrs de cada preset de ese módulo y los de grupo hechos para él; sin module devuelve todo sin attrs. Los módulos fuera del catálogo se listan con in_catalog: false y no se gestionan. Se deshace el último cambio con nexo-mcp-undo-divi-design-change (área presets). Devuelve {modules, groups, available_groups, history_count}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Leer sólo requiere el permiso de lectura de constructores; cambiar presets requiere el scope mcp:divi_presets que el administrador enciende aparte.',
			'read',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'module' => array(
						'type'        => 'string',
						'description' => 'Nombre de bloque de un módulo del catálogo (divi/text, divi/heading, divi/button…). Sin él se devuelven todos los presets sin attrs.',
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->get_divi_presets( $input ),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'set-divi-preset',
			'Crear o cambiar un preset de módulo o de grupo de Divi 5',
			'Crea (sin id) o cambia (con id) un preset de Divi 5: de un módulo del catálogo (kind: module, name: divi/text, divi/heading…) o de un grupo de opciones de uno de esos módulos (kind: group, name: el grupo, por ejemplo divi/background, de available_groups de nexo-mcp-get-divi-presets; al crear también module y group_id, p. ej. module.decoration.background). CAMBIA TODO EL SITIO EN VIVO: todos los módulos que usan el preset (y los que no tienen otro si es el predeterminado) cambian al instante. Antes llamar a nexo-mcp-get-divi-presets para reutilizar lo que existe. Se deshace con nexo-mcp-undo-divi-design-change (área presets). Sin id se crea con un UUID v4 (attrs obligatorios, Divi descarta los presets sin atributos); con id se renombra (label) y, si llegan attrs, se REEMPLAZAN los attrs del preset (mandar todos); el resto del preset (renderAttrs, styleAttrs, groupPresets, priority, order, fechas de creación…) se conserva. Crear no cambia el predeterminado (nexo-mcp-set-divi-default-preset). label: texto plano de 1 a 100 caracteres, sin etiquetas ni shortcodes. attrs: sólo el diseño de ESCRITORIO de los módulos del catálogo de nexo-mcp-create-divi-page, con la forma de los attrs de bloque de Divi (p. ej. {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ff0000"}}}}}}) y las mismas reglas de valor que esa herramienta (colores #hex, rgb()/hsl() o variable global, tamaños con unidad…); en un preset de grupo sólo las rutas de ese grupo. Se rechazan manejadores de eventos, attributes personalizados, javascript:, shortcodes, contenido, tablet, teléfono, hover y cualquier ruta fuera del catálogo, aunque el usuario tenga unfiltered_html: error nexo_mcp_divi_preset_invalid con «ruta: motivo» y data.path (sin escribir nada). Módulo fuera del catálogo: nexo_mcp_divi_preset_module_unknown; id inexistente: nexo_mcp_divi_preset_not_found. Devuelve {kind, name, id, label, created, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_presets que el administrador enciende aparte.',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'kind'     => array(
						'type'        => 'string',
						'enum'        => array( 'module', 'group' ),
						'description' => 'module: preset de un módulo; group: preset de un grupo de opciones de un módulo.',
					),
					'name'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Con kind module, el módulo del catálogo (divi/text); con kind group, el nombre del grupo (divi/background).',
					),
					'id'       => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Id del preset que se cambia. Sin id se crea uno nuevo.',
					),
					'label'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 100,
						'description' => 'Nombre del preset (texto plano, 1 a 100 caracteres).',
					),
					'attrs'    => array(
						'type'                 => 'object',
						'description'          => 'Attrs de diseño de escritorio del preset, con la forma de los attrs de bloque de Divi. Obligatorio al crear; con id reemplaza los attrs.',
						'additionalProperties' => true,
					),
					'module'   => array(
						'type'        => 'string',
						'description' => 'Sólo al crear un preset de grupo: el módulo del catálogo para el que se hace (divi/text).',
					),
					'group_id' => array(
						'type'        => 'string',
						'description' => 'Sólo al crear un preset de grupo: el id del grupo de ese módulo (module.decoration.background), de available_groups.',
					),
				),
				'required'             => array( 'kind', 'name', 'label' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->set_divi_preset( $input ),
			fn() => $this->divi_presets_write()
		);
		$this->ability(
			'set-divi-default-preset',
			'Fijar o quitar el preset predeterminado de un módulo o grupo de Divi 5',
			'Fija (id) o quita (id: null) el preset predeterminado de un módulo del catálogo (kind: module) o de un grupo de opciones (kind: group): el que se aplica a todos los módulos que no tienen otro preset. CAMBIA TODO EL SITIO EN VIVO. Antes llamar a nexo-mcp-get-divi-presets. Se deshace con nexo-mcp-undo-divi-design-change (área presets). El id debe ser el de un preset existente de esa colección (nexo_mcp_divi_preset_not_found) y un módulo fuera del catálogo se rechaza (nexo_mcp_divi_preset_module_unknown); datos inválidos: nexo_mcp_divi_preset_invalid con «ruta: motivo» y data.path. Si ya estaba así no escribe nada y responde changed: false. Devuelve {kind, name, id, changed, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_presets que el administrador enciende aparte.',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'kind' => array(
						'type'        => 'string',
						'enum'        => array( 'module', 'group' ),
						'description' => 'module o group.',
					),
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'El módulo (divi/text) o el grupo (divi/background).',
					),
					'id'   => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Id del preset predeterminado, o null para dejar sin predeterminado.',
					),
				),
				'required'             => array( 'kind', 'name', 'id' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->set_divi_default_preset( $input ),
			fn() => $this->divi_presets_write()
		);
		$this->ability(
			'delete-divi-preset',
			'Borrar un preset de módulo o de grupo de Divi 5',
			'Borra un preset de Divi 5 (kind: module o group). CAMBIA TODO EL SITIO EN VIVO: los módulos que lo usan vuelven al estilo del predeterminado. Antes llamar a nexo-mcp-get-divi-presets. Busca el uso (el id exacto en modulePreset o groupPreset de las entradas de Divi 5 no borradas; un preset de grupo enlazado desde un preset de módulo también cuenta): si está en uso y no se manda force responde nexo_mcp_divi_preset_in_use con data.post_ids (hasta ' . DiviPresetOperations::MAX_POST_IDS . '; si hay más, data.post_ids_truncated: true y data.post_total) y data.preset_ids, y no borra nada. Un id con --, <, >, &, comillas, barra invertida o caracteres no ASCII (WordPress los escapa en el contenido, así que el uso no se puede comprobar) responde nexo_mcp_divi_preset_usage_unknown sin force; con force: true se borra igual (usage_unverified: true) y además exige el permiso destructivo. Al borrar con force, si era un preset de grupo enlazado desde presets de módulo, quita el enlace de sus groupPresets en el mismo cambio (links_removed). Si era el predeterminado, también lo desmarca (default_cleared). Se deshace con nexo-mcp-undo-divi-design-change (restaura el catálogo, con el mismo id). Id inexistente: nexo_mcp_divi_preset_not_found; módulo fuera del catálogo: nexo_mcp_divi_preset_module_unknown; datos inválidos: nexo_mcp_divi_preset_invalid. Devuelve {deleted, kind, name, default_cleared, posts_affected (y posts_affected_truncated/posts_affected_total si hay más de ' . DiviPresetOperations::MAX_POST_IDS . '), links_removed?, usage_unverified?, area, undo_available}. Sin Divi 5 activo responde nexo_mcp_divi5_required. Requiere el scope mcp:divi_presets que el administrador enciende aparte (y mcp:destructive con force).',
			'write',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'kind'  => array(
						'type'        => 'string',
						'enum'        => array( 'module', 'group' ),
						'description' => 'module o group.',
					),
					'name'  => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'El módulo (divi/text) o el grupo (divi/background).',
					),
					'id'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Id del preset que se borra.',
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true borra aunque esté en uso (sin force, en uso se rechaza con la lista de entradas); exige el permiso destructivo.',
					),
				),
				'required'             => array( 'kind', 'name', 'id' ),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->delete_divi_preset( $input ),
			fn( array $input = array() ) => $this->divi_presets_write() && ( empty( $input['force'] ) || $this->policy->can_destroy( 'manage_options' ) )
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
			'list-divi-dynamic-sources',
			'Listar fuentes de contenido dinámico de Divi 5',
			$this->divi_dynamic_list_description(),
			'read',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Página (o su copia de trabajo) para la que se quiere la lista: sólo lo que Divi registra para ella (productos de WooCommerce, taxonomías, campos de ACF de sus grupos). Sin él, toda la lista cerrada.',
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->list_divi_dynamic_sources( $input ),
			fn( array $input ) => isset( $input['post_id'] )
				? $this->layout_gate( (int) $input['post_id'], fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_post', WorkingCopy::original_id_for( (int) $input['post_id'] ) ) )
				: $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_posts' )
		);
		$this->ability(
			'list-divi-conditions',
			'Listar las condiciones de visualización de Divi 5',
			$this->divi_conditions_list_description(),
			'read',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Página (o su copia de trabajo) para comprobar que existe y devolver su id y tipo. Sin él, sólo el catálogo.',
					),
				),
				'additionalProperties' => false,
			),
			fn( array $input ) => $this->builders->list_divi_conditions( $input ),
			fn( array $input ) => isset( $input['post_id'] )
				? $this->layout_gate( (int) $input['post_id'], fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_post', WorkingCopy::original_id_for( (int) $input['post_id'] ) ) )
				: $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_posts' )
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
			'edit-divi-module',
			'Editar un módulo de Divi 5',
			$this->divi_module_description(),
			'write',
			$this->divi_module_schema(),
			fn( array $input ) => $this->builders->edit_divi_module( $input ),
			fn( array $input ) => $this->elementor_page_permission( $input )
		);
		$this->ability(
			'set-divi-dynamic',
			'Poner o quitar contenido dinámico en un módulo de Divi 5',
			$this->divi_dynamic_set_description(),
			'write',
			$this->divi_dynamic_set_schema(),
			fn( array $input ) => $this->builders->set_divi_dynamic(
				$input,
				fn( int $post_id ): bool => $this->policy->can_write_divi_dynamic( $post_id )
			),
			fn( array $input ) => $this->policy->can_write_divi_dynamic( (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'set-divi-loop',
			'Activar o quitar el bucle de consulta de un módulo de Divi 5',
			$this->divi_loop_set_description(),
			'write',
			$this->divi_loop_set_schema(),
			fn( array $input ) => $this->builders->set_divi_loop(
				$input,
				fn( int $post_id ): bool => $this->policy->can_write_divi_dynamic( $post_id )
			),
			fn( array $input ) => $this->policy->can_write_divi_dynamic( (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'set-divi-conditions',
			'Poner o quitar las condiciones de visualización de un módulo de Divi 5',
			$this->divi_conditions_set_description(),
			'write',
			$this->divi_conditions_set_schema(),
			fn( array $input ) => $this->builders->set_divi_conditions(
				$input,
				fn( int $post_id ): bool => $this->policy->can_write_divi_conditions( $post_id )
			),
			fn( array $input ) => $this->policy->can_write_divi_conditions( (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'create-divi-page',
			'Crear página de Divi 5 (borrador)',
			$this->divi_page_description(),
			'write',
			$this->divi_page_schema(),
			fn( array $input ) => $this->builders->create_divi_page( $input ),
			// Como create-elementor-page: contenido nuevo, sin post al que atar la capacidad. Nunca publica.
			fn() => $this->module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'create-divi-layout',
			'Crear un layout del Theme Builder de Divi 5 (publicado, sin asignar)',
			$this->divi_layout_description(),
			'write',
			$this->divi_layout_schema(),
			fn( array $input ) => $this->builders->create_divi_layout( $input ),
			// Sin id de página: un layout se muestra en todo el sitio. Permiso de sitio del Theme Builder (no el de constructores).
			fn() => $this->policy->can_write_divi_theme_builder()
		);
		$this->ability(
			'list-divi-theme-builder',
			'Listar las plantillas del Theme Builder de Divi 5',
			$this->divi_template_list_description(),
			'read',
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			fn() => $this->builders->list_divi_theme_builder(),
			// Sin id de página: una plantilla decide lo que ve todo el sitio. Lee con el permiso del Theme Builder (como los layouts).
			fn() => $this->policy->can_write_divi_theme_builder()
		);
		$this->ability(
			'set-divi-template',
			'Crear o reemplazar una plantilla del Theme Builder de Divi 5',
			$this->divi_template_set_description(),
			'write',
			$this->divi_template_set_schema(),
			fn( array $input ) => $this->builders->set_divi_template( $input ),
			fn() => $this->policy->can_write_divi_theme_builder()
		);
		$this->ability(
			'delete-divi-template',
			'Sacar una plantilla del Theme Builder de Divi 5 y mandarla a la papelera',
			$this->divi_template_delete_description(),
			'write',
			$this->divi_template_delete_schema(),
			fn( array $input ) => $this->builders->delete_divi_template( $input ),
			fn() => $this->policy->can_write_divi_theme_builder()
		);
		$this->ability(
			'restore-divi-template',
			'Restaurar una plantilla del Theme Builder de Divi 5 desde su respaldo',
			$this->divi_template_restore_description(),
			'write',
			$this->divi_template_restore_schema(),
			fn( array $input ) => $this->builders->restore_divi_template( $input ),
			fn() => $this->policy->can_write_divi_theme_builder()
		);
		$this->ability(
			'list-divi-library',
			'Listar los ítems de la biblioteca de Divi 5',
			DiviLibraryToolSpec::list_description(),
			'read',
			DiviLibraryToolSpec::list_schema(),
			fn( array $input ) => $this->builders->list_divi_library( $input ),
			// Sin id de ítem: permiso de la biblioteca (también para leer; más estricto que la ruta de Divi).
			fn() => $this->policy->can_write_divi_library()
		);
		$this->ability(
			'get-divi-library-item',
			'Leer un ítem de la biblioteca de Divi 5',
			DiviLibraryToolSpec::get_description(),
			'read',
			DiviLibraryToolSpec::get_schema(),
			fn( array $input ) => $this->builders->get_divi_library_item( $input ),
			fn( array $input ) => $this->policy->can_write_divi_library( $this->library_item_id( $input ) )
		);
		$this->ability(
			'save-divi-library-item',
			'Crear o actualizar un ítem de la biblioteca de Divi 5',
			DiviLibraryToolSpec::save_description(),
			'write',
			DiviLibraryToolSpec::save_schema(),
			fn( array $input ) => $this->builders->save_divi_library_item( $input ),
			// Sin id es un alta (save_library); con id, el permiso del ítem (tipo, edit_post y, si es global, edit_global_library).
			fn( array $input ) => $this->policy->can_write_divi_library( $this->library_item_id( $input ) )
		);
		$this->ability(
			'delete-divi-library-item',
			'Mandar un ítem de la biblioteca de Divi 5 a la papelera',
			DiviLibraryToolSpec::delete_description(),
			'write',
			DiviLibraryToolSpec::id_confirm_schema( 'Ítem de la biblioteca que se manda a la papelera.' ),
			fn( array $input ) => $this->builders->delete_divi_library_item( $input ),
			// Mandar a la papelera es delete_post, además de poder editar el ítem.
			fn( array $input ) => $this->policy->can_write_divi_library( $this->library_item_id( $input ) )
				&& ( null === $this->library_item_id( $input ) || current_user_can( 'delete_post', $this->library_item_id( $input ) ) )
		);
		$this->ability(
			'restore-divi-library-item',
			'Restaurar un ítem de la biblioteca de Divi 5 desde su respaldo',
			DiviLibraryToolSpec::restore_description(),
			'write',
			DiviLibraryToolSpec::id_confirm_schema( 'Ítem de la biblioteca con un respaldo (también uno borrado, en la papelera).' ),
			fn( array $input ) => $this->builders->restore_divi_library_item( $input ),
			fn( array $input ) => $this->policy->can_write_divi_library( $this->library_item_id( $input ) )
		);
		$this->ability(
			'insert-divi-library-item',
			'Insertar un ítem de la biblioteca de Divi 5 en una página',
			DiviLibraryToolSpec::insert_description(),
			'write',
			DiviLibraryToolSpec::insert_schema(),
			fn( array $input ) => $this->builders->insert_divi_library_item( $input ),
			// Las dos capas: la biblioteca (con el id del ítem: edit_global_library si es global) y constructores sobre la página.
			fn( array $input ) => $this->policy->can_write_divi_library( $this->library_item_id( $input ) )
				&& $this->layout_gate(
					(int) ( $input['post_id'] ?? 0 ),
					fn() => $this->module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_post', WorkingCopy::original_id_for( (int) ( $input['post_id'] ?? 0 ) ) )
				)
		);
		$this->ability(
			'edit-divi-section',
			'Reconstruir secciones de Divi 5',
			$this->divi_section_description(),
			'write',
			$this->divi_section_schema(),
			fn( array $input ) => $this->builders->edit_divi_section( $input ),
			fn( array $input ) => $this->elementor_page_permission( $input )
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
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_tools_enabled',
					Capabilities::BUILDERS,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				)
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
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_tools_enabled',
					Capabilities::BUILDER_ATTRS,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				),
				fn() => $this->module_write( 'builders_tools_enabled', Capabilities::BUILDER_ATTRS, 'edit_theme_options' )
			)
		);
		$this->ability(
			'restore-builder-backup',
			'Restaurar respaldo del constructor',
			'Devuelve la página al estado previo a la última edición del conector. Es una edición más y nunca escribe en vivo una página publicada: con el id de la copia de trabajo, o con el de una página publicada que ya tiene copia, se deshace la última edición de la copia (se restaura el respaldo propio de la copia; restaurar dos veces deja el mismo estado). Sólo si la página publicada no tiene copia se usa el respaldo del original (el estado previo a su última edición en vivo o a su última publicación) y se restaura en una copia nueva; sin respaldo del original no se crea la copia. Revisa con nexo-mcp-get-working-copy-diff y publica con nexo-mcp-set-content-status. Un borrador se restaura en su sitio.',
			'write',
			$this->builder_restore_schema(),
			fn( array $input ) => $this->builders->restore_backup( $input ),
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_tools_enabled',
					Capabilities::BUILDERS,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				)
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
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_tools_enabled',
					Capabilities::BUILDERS,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				)
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
			fn( array $input ) => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_posts' )
				// Restaurar la revisión de un layout reescribe el layout vivo (todo el sitio): además, el permiso del Theme Builder.
				&& ( ! $this->is_layout_revision( (int) ( $input['id'] ?? 0 ) ) || $this->policy->can_write_divi_theme_builder() )
		);
		$this->ability(
			'set-content-status',
			'Cambiar estado de contenido',
			'Publica, cambia el estado o envía a la papelera cuando el módulo de publicación está habilitado. Usa id, o ids para un lote de hasta 50. La copia de trabajo de un layout del Theme Builder (encabezado, cuerpo o pie) también se publica (se fusiona en el layout vivo, que ve todo el sitio) o se descarta (trash) por aquí, pero pide además el permiso del Theme Builder (scope mcp:divi_theme_builder): nexo_mcp_divi_layout_denied sin él. El layout vivo en sí no cambia de estado por esta herramienta.',
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
			'Muestra qué cambió una copia de trabajo respecto a su original: título, extracto, un diff del contenido por líneas, metadatos y categorías o etiquetas, y si el original cambió desde que se creó la copia (stale). Úsala antes de pedirle a la persona que publique o descarte una copia, para explicarle exactamente qué va a cambiar. En meta, kept_on_publish lista claves que faltan en la copia pero que el original conserva al publicar (publicar nunca borra meta). Acepta el id del original o el de la copia (el que devuelve update-page o update-post). Para un layout del Theme Builder pide el permiso del Theme Builder (nexo_mcp_divi_layout_denied sin él); los demás tipos internos de Divi se rechazan.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->content->get_working_copy_diff( (int) $input['id'] ),
			fn( array $input ) => $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_post', WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) ) )
			)
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
		return $this->layout_gate(
			(int) ( $input['id'] ?? 0 ),
			fn() => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
			)
		);
	}

	/**
	 * Indica si la revisión es de un layout del Theme Builder.
	 *
	 * @param int $revision_id Revisión.
	 */
	private function is_layout_revision( int $revision_id ): bool {
		$revision = function_exists( 'wp_get_post_revision' ) ? wp_get_post_revision( $revision_id ) : null;

		return $revision instanceof \WP_Post && DiviLayoutGuard::KIND_LAYOUT === DiviLayoutGuard::kind_of_id( (int) $revision->post_parent );
	}

	/**
	 * Capa de permiso del tipo de contenido (T5): un layout del Theme Builder pide el permiso del Theme Builder y NO el de una página.
	 *
	 * Para cualquier otro contenido rige `$normal`. Los demás tipos `et_*` no tienen atajo: el permiso de siempre y, al
	 * ejecutar, el gateway los rechaza con `nexo_mcp_divi_post_type_denied` ({@see DiviLayoutGuard}).
	 *
	 * @param int           $post_id Contenido indicado (original o copia de trabajo).
	 * @param callable      $normal  fn(): bool. Permiso de una página.
	 * @param callable|null $extra   fn(): bool. Scope propio de la herramienta que también rige en un layout (sin `edit_post`).
	 */
	private function layout_gate( int $post_id, callable $normal, ?callable $extra = null ): bool {
		if ( DiviLayoutGuard::KIND_LAYOUT !== DiviLayoutGuard::kind_of_id( $post_id ) ) {
			return (bool) $normal();
		}

		return $this->policy->can_write_divi_theme_builder() && ( null === $extra || (bool) $extra() );
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
			&& $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_dynamic_enabled',
					Capabilities::BUILDERS_DYNAMIC,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				),
				fn() => $this->module_write( 'builders_dynamic_enabled', Capabilities::BUILDERS_DYNAMIC, 'edit_theme_options' )
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
			&& $this->layout_gate(
				(int) ( $input['id'] ?? 0 ),
				fn() => $this->module_write(
					'builders_components_enabled',
					Capabilities::BUILDERS_COMPONENTS,
					'edit_post',
					WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) )
				),
				fn() => $this->module_write( 'builders_components_enabled', Capabilities::BUILDERS_COMPONENTS, 'edit_theme_options' )
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
	 * Descripción de list-divi-dynamic-sources.
	 */
	private function divi_dynamic_list_description(): string {
		return implode(
			"\n",
			array(
				'Lista las fuentes de contenido dinámico de Divi 5 que el conector deja usar en una página (lista cerrada, sin meta personalizado, sin usuarios y sin enlaces a otros posts): de la entrada (título, extracto, fecha, autor, categorías y etiquetas, enlace, URL, imagen destacada), del sitio (título, lema, URL, logo), la fecha actual, WooCommerce básico (precio, SKU y descripción corta; el título de un producto es post_title) y los campos de texto, área de texto y número de ACF (custom_meta_<nombre del campo>, sólo de grupos de campos registrados, nunca claves de meta crudas ni las que empiezan por _). Sólo lee. Requiere Divi 5 activo: si no, nexo_mcp_divi_dynamic_unavailable.',
				'',
				'SALIDA: {post_id?, post_type?, sources, loop_sources, targets}. loop_sources: las fuentes loop_* por elemento de un bucle del plugin (nexo-mcp-set-divi-loop; con la misma forma que sources, source divi-loop, requires loop | loop_taxonomy | loop_product): sólo valen dentro de un módulo con ese bucle y las de producto en un bucle de product. sources: lista de {name, label, category (text | url | image), source (divi | acf), requires (\'\' | taxonomy | product), settings, forced}. settings es el esquema de los ajustes admitidos de la opción ({type: text | number | onoff | enum | taxonomy, options?, min?, max?, pattern?}); before y after son texto plano sin etiquetas, shortcodes, $ ni \\; forced indica lo que el conector fija siempre (enable_html: off en las de texto). targets: por módulo, las rutas de contenido donde cabe cada categoría (text en títulos, textos, botones y alt; url en enlaces; image en src). Con post_id sólo lo que aplica a esa página (los de WooCommerce sólo en un producto, categorías y etiquetas sólo si el tipo tiene taxonomías, los campos de ACF de los grupos que aplican a ella).',
				'',
				'Esta herramienta sólo lista: asignar un token a un módulo se hace con nexo-mcp-set-divi-dynamic (scope mcp:divi_dynamic) y activar un bucle de consulta con nexo-mcp-set-divi-loop (mismo scope); create-divi-page, edit-divi-module y edit-divi-section siguen rechazando todo $variable(…)$ con nexo_mcp_divi_dynamic_denied. Errores: nexo_mcp_builder_not_found, nexo_mcp_builder_invalid_id (post_id).',
			)
		);
	}

	/**
	 * Descripción de set-divi-dynamic.
	 */
	private function divi_dynamic_set_description(): string {
		return implode(
			"\n",
			array(
				'Pone (o quita) UN token de contenido dinámico de Divi 5 en UN campo de UN módulo de contenido, en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. El token muestra datos del sitio al PÚBLICO al renderizar (título, extracto, fecha, autor, categorías, enlace, imagen destacada, datos del sitio, fecha actual, WooCommerce básico y campos de texto de ACF), por eso es la única herramienta que los escribe y tiene su propio scope: requiere mcp:divi_dynamic (que el administrador enciende aparte, apagado por defecto), además del permiso de constructores y edit_post sobre la página. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'DESTINO: igual que nexo-mcp-edit-divi-module: si la página está publicada el cambio va a su copia de trabajo (draft_of, preview_url); si es un borrador, se edita el borrador. Si el sitio apagó la copia de trabajo por defecto, pasa draft: true para no escribir en vivo. Puedes pasar el id del original o el de su copia. Se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
				'',
				'ENTRADA: id, path y fingerprint del módulo (como en nexo-mcp-edit-divi-module), field y source. field es uno de los destinos de ese tipo de módulo que publica nexo-mcp-list-divi-dynamic-sources en targets (por ejemplo title.innerContent en divi/heading, button.innerContent.text o button.innerContent.linkUrl en divi/button, image.innerContent.src o .alt en divi/image). source es {name, settings?}: name de la lista cerrada de nexo-mcp-list-divi-dynamic-sources (sources, con su category) y settings con los ajustes que admite esa opción. La categoría de la fuente debe ser la del campo: text en textos, títulos y alt; url en enlaces; image en src (si no, nexo_mcp_divi_dynamic_denied). Un campo lleva UN solo token: sin texto alrededor (el texto antes y después se pone con los ajustes before y after de la fuente). Lo que se guarda es el token canónico que arma el plugin (enable_html siempre off), nunca la cadena del cliente.',
				'',
				'QUITAR: source: null deja el campo VACÍO (cadena vacía) si hoy tiene un token; después se pone el valor normal con nexo-mcp-edit-divi-module. Si el campo no tenía contenido dinámico responde nexo_mcp_divi_dynamic_not_set y no cambia nada (nunca borra un texto normal). Quitar también exige mcp:divi_dynamic. Las fuentes loop_* (loop_post_title…) sólo se aceptan dentro de un módulo con un bucle del plugin (nexo-mcp-set-divi-loop), propio o de un ancestro; si el campo mezcla el token con otro texto, nexo_mcp_divi_dynamic_mixed y no se cambia nada.',
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, path, fingerprint nuevo del módulo (úsalo en la siguiente edición), field y token (el canónico guardado) o removed: true.',
				'',
				'Los demás tokens de la página no se tocan. nexo-mcp-edit-divi-module, nexo-mcp-create-divi-page y nexo-mcp-edit-divi-section siguen rechazando todo $variable(…)$ nuevo (nexo_mcp_divi_dynamic_denied) y conservan los que ya existen.',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_builder_forbidden (sin permiso), nexo_mcp_divi_node_changed (la huella no coincide), nexo_mcp_builder_node_missing, nexo_mcp_divi_not_divi5, nexo_mcp_divi5_required, nexo_mcp_divi_module_denied (módulo fuera del catálogo de contenido), nexo_mcp_divi_dynamic_denied (fuente fuera de la lista, de otra categoría que el campo, que no aplica a esta página o token mal formado), nexo_mcp_divi_dynamic_invalid (field que no es un destino del módulo, source mal formado o ajustes inválidos, con «ruta: motivo»), nexo_mcp_divi_dynamic_not_set (quitar donde no hay token), nexo_mcp_divi_dynamic_mixed (quitar de un campo con más que un token), nexo_mcp_divi_dynamic_unavailable (Divi 5 o ACF no respondieron al consultar las fuentes).',
			)
		);
	}

	/**
	 * Esquema de entrada de set-divi-dynamic.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_dynamic_set_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 o su copia de trabajo.',
				),
				'path'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del módulo (por ejemplo 0.0.0.0.1; ver get-page-structure).',
				),
				'fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del módulo tal como la devolvió la lectura.',
				),
				'field'       => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'description' => 'Campo del módulo (de targets en nexo-mcp-list-divi-dynamic-sources), por ejemplo title.innerContent o button.innerContent.linkUrl.',
				),
				'source'      => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'name'     => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 120,
							'description' => 'Fuente de la lista cerrada de nexo-mcp-list-divi-dynamic-sources (post_title, site_title, post_link_url…).',
						),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => 'Ajustes de la fuente según su esquema (before, after, date_format…). enable_html no se admite distinto de off.',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
					'description'          => 'La fuente que se pone en el campo, o null para quitar el token (deja el campo vacío).',
				),
				'draft'       => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'path', 'fingerprint', 'field', 'source' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de set-divi-loop.
	 */
	private function divi_loop_set_description(): string {
		return implode(
			"\n",
			array(
				'Activa, cambia o quita (loop: null) el bucle de consulta de UN módulo de Divi 5, en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. El bucle repite el módulo (con todos sus hijos) una vez por contenido encontrado y muestra datos del sitio al PÚBLICO, por eso comparte el scope de set-divi-dynamic: requiere mcp:divi_dynamic (que el administrador enciende aparte, apagado por defecto), además del permiso de constructores y edit_post sobre la página. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'DESTINO: igual que nexo-mcp-set-divi-dynamic (draft_of, preview_url; draft: true si el sitio apagó la copia por defecto; se respalda antes de guardar; puedes pasar el id del original o el de su copia).',
				'',
				'ENTRADA: id, path y fingerprint del módulo (nexo-mcp-get-page-structure) y loop. Módulos que lo admiten: divi/section, divi/row, divi/column, divi/text, divi/heading, divi/button, divi/image, divi/divider, divi/blurb, divi/icon-list y divi/icon-list-item (no divi/icon, divi/video ni el placeholder: nexo_mcp_divi_module_denied). loop es {post_type, per_page, order_by, order, offset?, exclude_current?, terms?}: post_type, UN solo tipo de contenido público (post, page, product…; no attachment, no any, no varios); per_page de 1 a 24; order_by date | title | id; order asc | desc; offset de 0 a 1000; exclude_current true para no listar la página actual; terms {taxonomía: [ids de término]} de taxonomías públicas de ese tipo (categorías, etiquetas…), términos que existan. NO hay metaQuery, búsqueda, usuarios, términos como lista, repetidores, menús ni nada más: cualquier otra clave da nexo_mcp_divi_loop_invalid (con data.path). Lo que se guarda en module.advanced.loop.desktop.value es sólo lo que arma el plugin (enable, queryType post_types, subTypes, orderBy, order, postPerPage, postOffset, excludeCurrentPost, ignoreStickysPost, loopId, y los términos), nunca la cadena del cliente.',
				'',
				'VISIBILIDAD: el bucle muestra SÓLO contenido publicado y sin contraseña (los privados, borradores, programados y protegidos no salen), forzado por el plugin al renderizar; el bucle del plugin se reconoce por su loopId (loop-nx…). La paginación por URL (?<loopId>=N) de Divi sigue funcionando sobre el mismo tope por página.',
				'',
				'SIN ANIDAR: si algún ancestro o descendiente del módulo ya tiene un bucle activo, nexo_mcp_divi_loop_nested y no se escribe nada. Sobre un módulo que ya tiene bucle, loop lo reemplaza entero (conserva su loopId si ya era del plugin).',
				'',
				'CAMPOS POR ELEMENTO: dentro del módulo con bucle (o de sus hijos) se ponen fuentes loop_* con nexo-mcp-set-divi-dynamic (la lista y sus ajustes, en loop_sources de nexo-mcp-list-divi-dynamic-sources): loop_post_title, loop_post_excerpt, loop_post_date, loop_post_author, loop_post_terms, loop_post_link (URL), loop_post_featured_image y, si el tipo es product, loop_product_title, loop_product_price_current, loop_product_sku y loop_product_short_description. Sin bucle del plugin válido en el módulo o en un ancestro, esas fuentes dan nexo_mcp_divi_dynamic_denied. Nunca hay usuarios, términos ni meta personalizado.',
				'',
				'QUITAR: loop: null borra module.advanced.loop (y deja el módulo como uno normal). Si el módulo no tiene bucle, nexo_mcp_divi_loop_not_set. Mientras queden fuentes loop_* dentro del módulo (o, al cambiar el tipo, fuentes que el tipo nuevo no admite, como las de producto en un bucle de entradas) responde nexo_mcp_divi_loop_in_use y no escribe: quítalas antes con nexo-mcp-set-divi-dynamic (source: null).',
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, path, fingerprint nuevo del módulo y loop (los parámetros normalizados y loop_id) o loop: null y removed: true.',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_builder_forbidden (sin permiso), nexo_mcp_divi_node_changed (la huella no coincide), nexo_mcp_builder_node_missing, nexo_mcp_divi_not_divi5, nexo_mcp_divi5_required, nexo_mcp_divi_module_denied, nexo_mcp_divi_loop_invalid (loop mal formado o fuera de los límites, con «ruta: motivo»), nexo_mcp_divi_loop_nested, nexo_mcp_divi_loop_in_use, nexo_mcp_divi_loop_not_set.',
			)
		);
	}

	/**
	 * Esquema de entrada de set-divi-loop.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_loop_set_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 o su copia de trabajo.',
				),
				'path'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del módulo (por ejemplo 0.0.0.0; ver get-page-structure).',
				),
				'fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del módulo tal como la devolvió la lectura.',
				),
				'loop'        => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'post_type'       => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 20,
							'description' => 'UN tipo de contenido público (post, page, product…). No attachment ni any.',
						),
						'per_page'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 24,
							'description' => 'Contenidos por página del bucle (1-24).',
						),
						'order_by'        => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'title', 'id' ),
							'description' => 'Orden: fecha, título o id.',
						),
						'order'           => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => 'Ascendente o descendente.',
						),
						'offset'          => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 1000,
							'description' => 'Contenidos a saltar al principio (0 por omisión).',
						),
						'exclude_current' => array(
							'type'        => 'boolean',
							'description' => 'No listar la página actual.',
						),
						'terms'           => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'     => 'array',
								'items'    => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'minItems' => 1,
								'maxItems' => 20,
							),
							'description'          => 'Sólo contenido con alguno de estos términos: {taxonomía: [ids]} de taxonomías públicas del tipo (category, post_tag, product_cat…).',
						),
					),
					'required'             => array( 'post_type', 'per_page', 'order_by', 'order' ),
					'additionalProperties' => false,
					'description'          => 'El bucle que se pone en el módulo, o null para quitarlo.',
				),
				'draft'       => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'path', 'fingerprint', 'loop' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de list-divi-conditions.
	 */
	private function divi_conditions_list_description(): string {
		return implode(
			"\n",
			array(
				'Lista las condiciones de visualización de Divi 5 que el conector deja poner en un módulo con nexo-mcp-set-divi-conditions (catálogo cerrado: sesión, rol, fecha y hora, tipo de contenido, categorías y etiquetas, contenidos concretos, navegador, sistema operativo, parámetro de URL y WooCommerce) y los valores que admite cada una. Sólo lee: no hace falta el scope mcp:divi_conditions (sólo permiso de constructores).',
				'',
				'SALIDA: {post_id?, post_type?, match, max_conditions, modules, types, roles, post_types, woocommerce}. match: all (se cumplen todas) | any (basta una). types: lista de {type, label, description, rules (reglas válidas del tipo), settings ({clave: {type, description, values?}}; values son las listas cerradas de navegadores, sistemas y días), requires (woocommerce o vacío), available (false si requiere WooCommerce y no está activo)}. roles: {slug, label} de los roles del sitio; post_types: {name, label} de los tipos de contenido públicos; woocommerce: si está activo. modules: los módulos que admiten condiciones (divi/placeholder no). Los ids de categorías, etiquetas, contenidos y productos se buscan con las herramientas de lectura normales (nexo-mcp-list-terms…).',
				'',
				'NO ES CONTROL DE ACCESO: las condiciones sólo cambian lo que se muestra; el contenido oculto sigue en la página (lo ven quienes la editan, la API con permisos de edición, las revisiones y copias) y el texto de un módulo oculto sigue siendo buscable en el buscador del sitio. Navegador, sistema, parámetro de URL y cookies los controla el visitante. Nunca las presentes como «privado» ni «restringido».',
				'',
				'Errores: nexo_mcp_builder_not_found, nexo_mcp_builder_invalid_id (post_id).',
			)
		);
	}

	/**
	 * Descripción de set-divi-conditions.
	 */
	private function divi_conditions_set_description(): string {
		return implode(
			"\n",
			array(
				'Pone, reemplaza o quita (conditions: []) las condiciones de visualización de UN módulo de Divi 5, en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Cambia lo que ve cada visitante, por eso tiene su propio scope: requiere mcp:divi_conditions (que el administrador enciende aparte, apagado por defecto), además del permiso de constructores y edit_post sobre la página. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'NO ES CONTROL DE ACCESO: es presentación. Un módulo oculto no sale en el HTML, pero su contenido sigue en la página: lo ven quienes la editan y la API con permisos, y su texto es buscable en el buscador del sitio. Navegador, sistema operativo y parámetro de URL los controla el visitante. Con una caché de página completa (plugin de caché o CDN) el resultado del primer visitante se sirve a todos. Fallan abiertas: si Divi no entiende una condición, MUESTRA el módulo. Nunca lo describas como «privado» ni «restringido»; ocultar un contenedor oculta todo lo que lleva dentro. Si necesitas confidencialidad real, no pongas el contenido en la página.',
				'',
				'DESTINO: igual que nexo-mcp-edit-divi-module (draft_of, preview_url; draft: true si el sitio apagó la copia por defecto; se respalda antes de guardar; puedes pasar el id del original o el de su copia).',
				'',
				'ENTRADA: id, path y fingerprint del módulo (nexo-mcp-get-page-structure), match y conditions. Módulos que las admiten: divi/section, divi/row, divi/column, divi/text, divi/heading, divi/button, divi/image, divi/divider, divi/blurb, divi/icon, divi/video, divi/icon-list y divi/icon-list-item (no divi/placeholder: nexo_mcp_divi_module_denied). match: all (se cumplen todas) o any (basta una); el mismo operador vale para todas (no hay listas mixtas). conditions: de 0 a 5 objetos {type, rule, …ajustes}, el conjunto ENTERO (reemplaza al que había; conserva el id de las que no cambian). Los tipos, reglas, ajustes y valores permitidos (roles, tipos de contenido, navegadores…) están en nexo-mcp-list-divi-conditions: sesión (logged_in), rol (user_role), fecha y hora (date_time: before, after, on_date, on_days; sin repeticiones), tipo de contenido (post_type), categorías y etiquetas (categories, tags), contenidos concretos (posts), navegador (browser), sistema operativo (operating_system), parámetro de URL (url_parameter) y, con WooCommerce activo, cart_contents, product_stock y product_purchase. Con match any no se admiten dos condiciones del mismo tipo (Divi descartaría la segunda), salvo fecha/hora y parámetro de URL. NO hay customField, cookie, número de visitas, páginas visitadas, autor, archivos ni dispositivo (eso es otra opción de Divi): cualquier otra cosa da nexo_mcp_divi_conditions_invalid con data.path. Lo que se guarda en module.decoration.conditions.desktop.value es sólo lo que arma el plugin (nunca la cadena del cliente). match es obligatorio siempre, también con conditions: [] (si falta: nexo_mcp_divi_conditions_invalid con data.path match).',
				'',
				'LAS HECHAS EN EL VISUAL BUILDER: si el módulo ya tiene condiciones que no son de este conector, reemplazarlas o quitarlas las pierde: responde nexo_mcp_divi_conditions_foreign con un resumen en data.summary y no escribe nada; repite con replace_foreign: true para confirmar (la salida trae replaced con el resumen de lo que había). Las propias se reemplazan sin confirmar. edit-divi-module (contenido, diseño y presets) las conserva tal cual; edit-divi-section replace o delete las pierde y lo avisa con dropped_conditions; ninguna de las tres las escribe (las rechazan como atributo: nexo_mcp_divi_attr_denied). nexo-mcp-get-page-structure las resume en attributes.conditions de cada módulo.',
				'',
				'QUITAR: conditions: [] borra module.decoration.conditions.desktop.value (y deja el módulo sin condiciones); la salida trae removed: true.',
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, path, fingerprint nuevo del módulo, match, conditions (las guardadas, con su id), replaced (si había una lista ajena) y removed: true al quitar.',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_builder_forbidden (sin permiso), nexo_mcp_divi_node_changed (la huella no coincide), nexo_mcp_builder_node_missing, nexo_mcp_divi_not_divi5, nexo_mcp_divi5_required, nexo_mcp_divi_module_denied, nexo_mcp_divi_conditions_invalid (conjunto mal formado, con «ruta: motivo»), nexo_mcp_divi_conditions_foreign (lista ajena sin replace_foreign).',
			)
		);
	}

	/**
	 * Esquema de entrada de set-divi-conditions.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_conditions_set_schema(): array {
		$def      = DiviConditionsSchema::definition();
		$strings  = static fn( string $description, int $max ): array => array(
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => $max,
			'items'       => array( 'type' => 'string' ),
			'description' => $description,
		);
		$integers = static fn( string $description ): array => array(
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => DiviConditionsSchema::MAX_ITEMS,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => $description,
		);
		$time     = static fn( string $description ): array => array(
			'type'        => 'string',
			'pattern'     => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
			'description' => $description,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 o su copia de trabajo.',
				),
				'path'            => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del módulo (por ejemplo 0.0.0.0; ver get-page-structure).',
				),
				'fingerprint'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del módulo tal como la devolvió la lectura.',
				),
				'match'           => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'any' ),
					'description' => 'Obligatorio. all: se cumplen todas las condiciones; any: basta una. Con conditions vacío (quitar) no cambia nada, pero se pide igual.',
				),
				'conditions'      => array(
					'type'        => 'array',
					'minItems'    => 0,
					'maxItems'    => DiviConditionsSchema::MAX_CONDITIONS,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'       => array(
								'type'        => 'string',
								'enum'        => array_keys( DiviConditionsSchema::TYPES ),
								'description' => 'Tipo de condición (ver nexo-mcp-list-divi-conditions).',
							),
							'rule'       => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 30,
								'description' => 'Regla del tipo (logged_in | logged_out, is | is_not, before | after | on_date | on_days, equals…).',
							),
							'roles'      => $strings( 'user_role: slugs de rol que existan (1 a ' . DiviConditionsSchema::MAX_ROLES . ').', DiviConditionsSchema::MAX_ROLES ),
							'post_types' => $strings( 'post_type: tipos de contenido públicos (1 a ' . DiviConditionsSchema::MAX_POST_TYPES . ').', DiviConditionsSchema::MAX_POST_TYPES ),
							'terms'      => $integers( 'categories o tags: ids de término que existan (1 a 20).' ),
							'post_type'  => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 20,
								'description' => 'posts: UN tipo de contenido público (no attachment).',
							),
							'posts'      => $integers( 'posts: ids de contenido publicado de ese tipo (1 a 20).' ),
							'browsers'   => $strings( 'browser: ' . implode( ' | ', $def['browsers'] ) . '.', count( $def['browsers'] ) ),
							'systems'    => $strings( 'operating_system: ' . implode( ' | ', $def['systems'] ) . '.', count( $def['systems'] ) ),
							'weekdays'   => $strings( 'date_time con on_days: ' . implode( ' | ', $def['weekdays'] ) . '.', count( $def['weekdays'] ) ),
							'products'   => $integers( 'WooCommerce: ids de productos que existan (1 a 20); sólo con las reglas que nombran productos.' ),
							'name'       => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 40,
								'description' => 'url_parameter: nombre del parámetro [A-Za-z0-9_-].',
							),
							'value'      => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => DiviConditionsSchema::MAX_TEXT,
								'description' => 'url_parameter con equals, not_equals, contains o not_contains.',
							),
							'date'       => array(
								'type'        => 'string',
								'pattern'     => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
								'description' => 'date_time con before, after u on_date: fecha real Y-m-d.',
							),
							'time'       => $time( 'date_time con before o after: hora HH:MM.' ),
							'all_day'    => array(
								'type'        => 'boolean',
								'description' => 'date_time con on_date u on_days: todo el día (por omisión); con false, from_time y until_time.',
							),
							'from_time'  => $time( 'date_time con all_day false: hora HH:MM.' ),
							'until_time' => $time( 'date_time con all_day false: hora HH:MM, posterior a from_time.' ),
						),
						'required'             => array( 'type' ),
						'additionalProperties' => false,
					),
					'description' => 'El conjunto ENTERO de condiciones del módulo (reemplaza al que había); [] las quita.',
				),
				'replace_foreign' => array(
					'type'        => 'boolean',
					'description' => 'true confirma reemplazar o quitar condiciones que no son de este conector (por ejemplo hechas en el Visual Builder).',
				),
				'draft'           => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'path', 'fingerprint', 'match', 'conditions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de edit-divi-module.
	 */
	private function divi_module_description(): string {
		return implode(
			"\n",
			array(
				'Cambia el contenido y/o el diseño de ESCRITORIO de un módulo de contenido de una página de Divi 5 (texto, título, botón, imagen, divisor, blurb, ícono, video, lista). Escribe en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'DESTINO: si la página está publicada el cambio va a su copia de trabajo (draft_of, preview_url); si es un borrador, se edita el borrador. Si el sitio apagó la copia de trabajo por defecto, pasa draft: true para no escribir en vivo. Puedes pasar el id del original o el de su copia. Se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
				'',
				'ENTRADA: id, path y fingerprint del módulo (se leen con nexo-mcp-get-page-structure: el id del nodo es el path), y content y/o design: objetos con la forma de los atributos del bloque donde cada valor va en <ruta>.desktop.value, p. ej. content {"content":{"innerContent":{"desktop":{"value":"<p>Hola</p>"}}}} o design {"module":{"decoration":{"spacing":{"desktop":{"value":{"margin":{"top":"20px"}}}}}}}. Sólo se cambian las rutas indicadas: tablet, teléfono, hover y todo lo no indicado se conserva. No admite código, HTML crudo, shortcodes, tokens $variable(…)$ nuevos (para eso nexo-mcp-set-divi-dynamic; el bucle de consulta es nexo-mcp-set-divi-loop; las condiciones de visualización, nexo-mcp-set-divi-conditions) ni URLs javascript:.',
				'',
				'PRESET (opcional, sola o junto a content/design): preset {module?: id | null, groups?: {<id de grupo>: id | null}} aplica presets de Divi 5 al módulo (se escribe modulePreset y groupPreset en el bloque, con el nombre de grupo que da Divi; null quita la referencia; un módulo lleva un preset de módulo y uno por grupo). Los ids salen de nexo-mcp-get-divi-presets (con module se ven los presets de ese módulo y available_groups da los ids de grupo válidos); el id debe existir para ese módulo o grupo: si no, nexo_mcp_divi_preset_unknown con data.path y no se escribe nada. Es una escritura de página (copia de trabajo, huella): no pide el scope de presets. Los attrs propios del módulo ganan siempre al preset. modulePreset/groupPreset crudos dentro de content o design siguen rechazados.',
				'',
				'EN UN LAYOUT además se editan divi/post-title y divi/menu (menu.advanced.menuId: el id, como texto, de un menú que existe; uno inexistente se rechaza) y divi/post-content (sin contenido que editar).',
				$this->divi_layout_note(),
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, path y fingerprint nuevo del módulo (úsalo en la siguiente edición).',
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_divi_node_changed (la huella no coincide: el módulo cambió desde la lectura), nexo_mcp_builder_node_missing, nexo_mcp_divi_not_divi5, nexo_mcp_divi5_required, nexo_mcp_divi_module_denied (estructura o módulo fuera del catálogo), nexo_mcp_divi_attr_denied, nexo_mcp_divi_value_invalid, nexo_mcp_divi_shortcode_denied, nexo_mcp_divi_dynamic_denied, nexo_mcp_divi_tree_invalid, nexo_mcp_divi_preset_unknown (preset).',
			)
		);
	}

	/**
	 * Esquema de entrada de edit-divi-module.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_module_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 o su copia de trabajo.',
				),
				'path'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del módulo (por ejemplo 0.0.0.0.1; ver get-page-structure).',
				),
				'fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del módulo tal como la devolvió la lectura.',
				),
				'content'     => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Atributos de contenido, con cada valor en <ruta>.desktop.value.',
				),
				'design'      => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Atributos de diseño de escritorio (color, espaciado, tipografía), con cada valor en <ruta>.desktop.value.',
				),
				'preset'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'module' => array(
							'type'        => array( 'string', 'null' ),
							'minLength'   => 1,
							'description' => 'Id del preset de módulo (de nexo-mcp-get-divi-presets) o null para quitarlo.',
						),
						'groups' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => array( 'string', 'null' ) ),
							'description'          => 'Presets de grupo de opciones: {<id de grupo, p. ej. module.decoration.background>: id del preset o null para quitarlo}.',
						),
					),
					'additionalProperties' => false,
					'description'          => 'Aplica o quita presets en el módulo (escribe modulePreset/groupPreset). Se puede pasar sola o con content/design.',
				),
				'draft'       => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'path', 'fingerprint' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción del árbol de módulos de Divi 5 que comparten create-divi-page y edit-divi-section.
	 */
	private function divi_tree_help(): string {
		return 'ÁRBOL: lista de nodos {type, attrs?, children?}. type es un módulo de la lista cerrada: divi/section, divi/row, divi/column y los de contenido divi/text, divi/heading, divi/button, divi/image, divi/divider, divi/blurb, divi/icon, divi/video, divi/icon-list (con hijos divi/icon-list-item). Anidado: placeholder > section > row > column > módulos de contenido. attrs lleva los atributos de bloque de Divi tal como se guardan, con cada valor en <ruta>.desktop.value (p. ej. {"content":{"innerContent":{"desktop":{"value":"<p>Hola</p>"}}}}); no lleva builderVersion ni ids (los pone el plugin). Sólo contenido y diseño de escritorio: tablet, teléfono y hover se rechazan. No admite código, HTML crudo, shortcodes, tokens $variable(…)$ nuevos (para eso nexo-mcp-set-divi-dynamic) ni URLs javascript:. Un nodo puede llevar además preset {module?: id | null, groups?: {<id de grupo>: id | null}} para aplicarle presets de Divi 5 (ids de nexo-mcp-get-divi-presets; se escriben como modulePreset/groupPreset; modulePreset/groupPreset dentro de attrs se rechazan; un id que no existe para ese módulo o grupo da nexo_mcp_divi_preset_unknown con data.path y no se escribe nada). Las condiciones de visualización (module.decoration.conditions) no se aceptan aquí (nexo_mcp_divi_attr_denied): se ponen sólo con nexo-mcp-set-divi-conditions. Máximo 200 módulos y 3 MB en la página resultante.';
	}

	/**
	 * Descripción de create-divi-page.
	 */
	private function divi_page_description(): string {
		return implode(
			"\n",
			array(
				'Crea una página de Divi 5 con módulos reales (secciones, filas, columnas y módulos de contenido) como BORRADOR. Nunca publica. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'ENTRADA: title y sections: la lista de secciones (sólo divi/section en la raíz; el divi/placeholder que envuelve el contenido lo pone el plugin). Cada módulo lleva builderVersion de la versión de Divi instalada.',
				$this->divi_tree_help(),
				'',
				'SALIDA: id, status (draft), preview_url y nodes: lista plana de {path, type, fingerprint} con la ruta de cada nodo (úsala con nexo-mcp-edit-divi-section y nexo-mcp-edit-divi-module).',
				'',
				'ERRORES (no crean nada; si falla el guardado el borrador a medias se elimina): nexo_mcp_divi5_required, nexo_mcp_divi_title_required, nexo_mcp_divi_tree_invalid, nexo_mcp_divi_module_denied, nexo_mcp_divi_nesting_invalid, nexo_mcp_divi_attr_denied, nexo_mcp_divi_value_invalid, nexo_mcp_divi_shortcode_denied, nexo_mcp_divi_dynamic_denied, nexo_mcp_divi_too_many_nodes, nexo_mcp_divi_too_large, nexo_mcp_divi_save_failed.',
			)
		);
	}

	/**
	 * Esquema de entrada de create-divi-page.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_page_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'sections' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 200,
					'description' => 'Secciones: lista de nodos {type: "divi/section", attrs?, children?}. Ver la descripción de la herramienta.',
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'required'             => array( 'title', 'sections' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Nota de las herramientas de edición sobre los layouts del Theme Builder.
	 */
	private function divi_layout_note(): string {
		return 'LAYOUTS DEL THEME BUILDER: id puede ser el de un layout de encabezado, cuerpo o pie (o su copia de trabajo); entonces se pide el permiso del Theme Builder (scope mcp:divi_theme_builder con su interruptor y un administrador con el permiso theme_builder de Divi) y NO el de una página: sin él, nexo_mcp_divi_layout_denied. Los demás tipos internos de Divi (plantillas, biblioteca) se rechazan siempre (nexo_mcp_divi_post_type_denied). El cambio va a la copia de trabajo del layout (el layout vivo, que ve todo el sitio, no cambia): revísala con nexo-mcp-get-working-copy-diff y publícala o descártala con nexo-mcp-set-content-status (también con el permiso del Theme Builder).';
	}

	/**
	 * Id de ítem de la biblioteca de la entrada: un entero positivo, o null (alta o entrada sin id válido).
	 *
	 * @param array<string, mixed> $input Entrada.
	 */
	private function library_item_id( array $input ): ?int {
		return isset( $input['id'] ) && is_int( $input['id'] ) && $input['id'] > 0 ? $input['id'] : null;
	}

	/**
	 * Descripción de list-divi-theme-builder.
	 */
	private function divi_template_list_description(): string {
		return implode(
			"\n",
			array(
				'Lista las plantillas del Theme Builder EN VIVO de Divi 5 (el que ve el sitio) con todo lo necesario para decidir un cambio; sólo lee y no crea nada. Requiere el scope mcp:divi_theme_builder (interruptor encendido por el administrador) con un administrador que tenga el permiso theme_builder de Divi, también para leer: sin él, nexo_mcp_divi_template_denied. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'POR QUÉ IMPORTA: una plantilla decide lo que ve todo el sitio (TODO, no sólo una página) y Divi elige UNA sola plantilla por petición, no las fusiona: gana la de use_on con mayor prioridad (las deshabilitadas y las que cumplen alguna exclude_from se descartan) y, si ninguna aplica, la plantilla por defecto habilitada. Una plantilla más específica (por ejemplo una por id de página) hace desaparecer el encabezado y el pie de la por defecto en esa página.',
				'',
				'SALIDA: live {id, exists} (el Theme Builder en vivo; si no existe, set-divi-template lo crea), draft {exists, id?, note?} (un borrador auto-draft del Visual Builder: se ignora; WordPress lo barre a los 7 días y un Visual Builder abierto pisa el mapeo en vivo al guardar), truncated (true si el mapeo tiene más de 50 plantillas y sólo se leyeron las primeras 50), templates (en el orden del mapeo: id, status, ours, title, enabled, default, site_wide, use_on y exclude_from como listas de {id, supported, priority} —los ids que el catálogo no conoce se conservan con supported: false y los textos libres no se muestran—, y header, body y footer con {layout_id, enabled, status del layout —publish, draft…, missing o wrong_type—, y en body has_post_content}), default_template (el id de la plantilla por defecto habilitada), contexts (cada contexto en uso con su priority, el winner —el id de la plantilla que gana si la petición sólo cumple ese contexto— y los templates que lo usan), shadows (qué plantilla tapa a cuál: {template, context, priority, covered_by, by_context, by_priority}), catalog (los contextos válidos con su prioridad, si son de sitio entero y si necesitan WooCommerce; WooCommerce sólo si está activo), limits y warnings.',
				'',
				'ours: true si el conector puede representar la plantilla por completo y reescribirla sin perder nada; false si es ajena o rara (hecha en el Visual Builder con metas que el conector no entiende): reemplazarla exige replace_foreign: true en set-divi-template. warnings: [{code, message, …}] con body_replaces_content (un cuerpo sin divi/post-content REEMPLAZA el contenido de las páginas a las que se asigna), layout_not_published (un layout que no es publish también lo ve el público), layout_trashed (un layout en la papelera: Divi no lo muestra), mapping_truncated (el mapeo tiene más de 50 plantillas y sólo se leen las primeras 50), layout_invalid (no existe o es de otro tipo), scheduled_for_trash (Divi marcó la plantilla o el layout como sin uso y a los 7 días los manda a la papelera), template_trashed, orphan_mapping y multiple_defaults.',
			)
		);
	}

	/**
	 * Descripción de set-divi-template.
	 */
	private function divi_template_set_description(): string {
		return implode(
			"\n",
			array(
				'Crea o REEMPLAZA UNA plantilla del Theme Builder EN VIVO de Divi 5 (la que ve el sitio): qué encabezado, cuerpo y pie se muestran y dónde. Requiere el scope mcp:divi_theme_builder (interruptor encendido por el administrador) con un administrador que tenga el permiso theme_builder de Divi: nexo_mcp_divi_template_denied sin él, antes de mirar la entrada. Requiere Divi 5 activo (nexo_mcp_divi5_required). Antes lee con nexo-mcp-list-divi-theme-builder; los layouts salen de nexo-mcp-create-divi-layout (publish).',
				'',
				'EFECTO EN TODO EL SITIO: una plantilla decide lo que ve todo el sitio y Divi elige UNA sola plantilla por petición, no las fusiona: gana la de use_on con mayor prioridad (las deshabilitadas y las que cumplen alguna exclude_from se descartan) y, si ninguna aplica, la plantilla por defecto habilitada. Una plantilla más específica (por ejemplo una por id de página) hace desaparecer el encabezado y el pie de la plantilla por defecto en esa página. Un encabezado o pie reemplaza al del tema (se pierden su menú, buscador y carrito). Un cuerpo sin divi/post-content REEMPLAZA el contenido de la página (el carrito de WooCommerce, el pago, un formulario dejan de verse).',
				'',
				'NUEVAS DESHABILITADAS: una plantilla nueva se crea SIEMPRE con enabled: false, también si pides enabled: true, y no cambia nada en el sitio; la respuesta trae needs_enable y el aviso. Para activarla haz una segunda llamada con su id y enabled: true. Reemplazar es reemplazar: lo que no pases vuelve a su valor por defecto (listas vacías, ranuras sin layout propio, default false), así que enabled es obligatorio y conviene releer la plantilla antes.',
				'',
				'ENTRADA: id (opcional: si se pasa reemplaza esa plantilla del Theme Builder en vivo; sin id crea una; un id que no es una plantilla en vivo da nexo_mcp_divi_template_not_found y nunca crea otra), title (1 a 100 caracteres, sin < >), enabled, default (la plantilla del sitio entero: sólo UNA, nexo_mcp_divi_template_default_exists si ya hay otra), use_on y exclude_from (de 0 a 20 contextos cada una, sin repetir ni estar en las dos), header, body y footer: {layout_id, enabled}; layout_id es el id entero de un layout PUBLISH del tipo de esa ranura (uno en borrador, privado o de otro tipo se rechaza: Divi lo recortaría a 0 en silencio) y null no pone layout propio en esa área; enabled: false en una ranura oculta esa área. Contextos (catálogo cerrado, ver nexo-mcp-list-divi-theme-builder): homepage, 404, search, singular:post_type:<tipo>:all, singular:post_type:<tipo>:id:<id>, singular:taxonomy:<tax>:term:id:<id>, archive:all, archive:post_type:<tipo>, archive:taxonomy:<tax>:all, archive:taxonomy:<tax>:term:id:<id>, archive:user:all, archive:user:id:<id>, archive:date:all y woocommerce:shop|cart|checkout|my_account sólo con WooCommerce activo; los ids deben existir y los contenidos estar publish; no se admiten roles de autor ni children:id. Cualquier otra cosa da nexo_mcp_divi_template_invalid con data.path.',
				'',
				'CONFIRMACIONES: confirm_site_wide: true es obligatorio si la plantilla es por defecto o su use_on incluye un …:all, archive:all o 404 (nexo_mcp_divi_template_site_wide), y también para cambiar o quitarle eso a una plantilla que ya lo tiene; la respuesta explica el efecto y qué plantillas más específicas la tapan (shadowed_by) o tapa (shadows). Un cuerpo sin divi/post-content asignado al inicio, a un singular, a WooCommerce o a la plantilla por defecto se rechaza (nexo_mcp_divi_template_body_replaces_content) salvo allow_without_post_content: true. replace_foreign: true es obligatorio para reemplazar una plantilla que no es del conector (hecha en el Visual Builder con ajustes que no representa): nexo_mcp_divi_template_foreign con el resumen en data.summary; las metas de Divi que el esquema no entiende se quitan, quedan en el respaldo y la salida trae replaced.',
				'',
				'RESPALDO Y CACHÉ: antes de cada cambio se guarda un respaldo de la plantilla (metas, título, estado y su fila del Theme Builder; una sola copia, la del último cambio) y sólo se toca la fila de ESTA plantilla del mapeo, nunca se reescribe todo. DESHACER: nexo-mcp-restore-divi-template con el id de la plantilla la devuelve a ese respaldo (nexo-mcp-restore-builder-backup NO acepta plantillas); la salida trae además previous (el resumen de lo que había: con esos valores también se puede volver a llamar a set-divi-template con el id). El respaldo completo queda en la meta _nexo_mcp_template_backup de la propia plantilla. Después se borra el CSS estático de todo el sitio (ET_Core_PageResource::remove_static_resources(\'all\', \'all\'), primer render lento) y las cachés de página (et_theme_builder_clear_wp_cache): Divi no borra el CSS, sin avisar, si el usuario de la conexión no tiene edit_posts (la respuesta lo avisa). Una plantilla o layout que no está en el Theme Builder en vivo lo manda Divi a la papelera a los 7 días.',
				'',
				'SALIDA: id, created, title, enabled, default, site_wide, effect (texto del efecto si es de sitio entero), ours, use_on, exclude_from, header, body, footer, shadowed_by, shadows, live_theme_builder, previous (el resumen de lo que había antes, null al crear), backup {operation, captured_at, existed}, cache {css, page_cache}, warnings, needs_enable (sólo al crear) y replaced (sólo al reemplazar una ajena).',
				'',
				'ERRORES (no escriben nada): nexo_mcp_divi_template_denied, nexo_mcp_divi5_required, nexo_mcp_divi_template_invalid (con data.path), nexo_mcp_divi_template_site_wide, nexo_mcp_divi_template_body_replaces_content, nexo_mcp_divi_template_foreign, nexo_mcp_divi_template_not_found, nexo_mcp_divi_template_default_exists, nexo_mcp_divi_template_too_many (50 plantillas), nexo_mcp_divi_template_backup_failed, nexo_mcp_divi_template_unavailable, nexo_mcp_busy (otra llamada sobre el Theme Builder en curso: reintenta) y nexo_mcp_divi_template_save_failed (si falla tras escribir, la plantilla vuelve a su estado anterior).',
			)
		);
	}

	/**
	 * Esquema de entrada de set-divi-template.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_template_set_schema(): array {
		$contexts = static fn( string $description ): array => array(
			'type'        => 'array',
			'maxItems'    => DiviThemeBuilderSchema::MAX_CONTEXTS,
			'items'       => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'description' => $description,
		);
		$slot     = static fn( string $description ): array => array(
			'type'                 => 'object',
			'properties'           => array(
				'layout_id' => array(
					'type'        => array( 'integer', 'null' ),
					'minimum'     => 1,
					'description' => 'Id del layout PUBLISH del tipo de esta ranura, o null para no poner layout propio en esa área.',
				),
				'enabled'   => array(
					'type'        => 'boolean',
					'description' => 'false oculta esa área (por omisión true).',
				),
			),
			'additionalProperties' => false,
			'description'          => $description,
		);
		$flag     = static fn( string $description ): array => array(
			'type'        => 'boolean',
			'description' => $description,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Plantilla del Theme Builder en vivo que se reemplaza. Sin id se crea una nueva (siempre deshabilitada).',
				),
				'title'                      => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => DiviThemeBuilderSchema::MAX_TITLE,
					'description' => 'Nombre de la plantilla.',
				),
				'enabled'                    => $flag( 'Si la plantilla está habilitada. Una plantilla NUEVA se crea con false aunque pidas true; se activa con una segunda llamada con su id.' ),
				'default'                    => $flag( 'Plantilla por defecto: se aplica a TODO el sitio donde no gane otra. Sólo una; exige confirm_site_wide.' ),
				'use_on'                     => $contexts( 'Contextos donde se aplica (catálogo de nexo-mcp-list-divi-theme-builder). Una plantilla gana por la prioridad de su contexto.' ),
				'exclude_from'               => $contexts( 'Contextos donde NO se aplica aunque use_on lo cubra.' ),
				'header'                     => $slot( 'Encabezado: {layout_id, enabled}.' ),
				'body'                       => $slot( 'Cuerpo: {layout_id, enabled}. Sin divi/post-content REEMPLAZA el contenido de la página.' ),
				'footer'                     => $slot( 'Pie: {layout_id, enabled}.' ),
				'confirm_site_wide'          => $flag( 'Confirma que la plantilla cambia todo el sitio (por defecto, …:all, archive:all o 404).' ),
				'replace_foreign'            => $flag( 'Confirma reemplazar una plantilla que no es del conector (se guarda un respaldo).' ),
				'allow_without_post_content' => $flag( 'Permite un cuerpo sin divi/post-content en un contexto que muestra contenido (REEMPLAZA ese contenido).' ),
			),
			'required'             => array( 'title', 'enabled' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de delete-divi-template.
	 */
	private function divi_template_delete_description(): string {
		return implode(
			"\n",
			array(
				'Saca UNA plantilla del Theme Builder EN VIVO de Divi 5 y la manda a la papelera (como Divi; no hay pantalla de WordPress para recuperarla: se restaura con nexo-mcp-restore-divi-template). Requiere el scope mcp:divi_theme_builder (interruptor encendido por el administrador) con un administrador que tenga el permiso theme_builder de Divi: nexo_mcp_divi_template_denied sin él. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'EFECTO EN TODO EL SITIO: quitar una plantilla cambia lo que ve todo el sitio donde ganaba (Divi elige UNA sola plantilla por petición: la siguiente en prioridad, o la por defecto, pasa a mandar). Si es la plantilla por defecto o su use_on incluye un …:all, archive:all o 404 hay que pasar confirm_site_wide: true (nexo_mcp_divi_template_site_wide). Una plantilla que no es del conector (hecha en el Visual Builder) exige replace_foreign: true (nexo_mcp_divi_template_foreign con el resumen en data.summary). Un id que no es una plantilla del Theme Builder en vivo da nexo_mcp_divi_template_not_found.',
				'',
				'ENTRADA: id (de nexo-mcp-list-divi-theme-builder), confirm_site_wide y replace_foreign (opcionales).',
				'',
				'RESPALDO Y CACHÉ: antes se guarda un respaldo (metas, título, estado y fila del Theme Builder) y sólo se quita la fila de ESTA plantilla del mapeo. DESHACER: nexo-mcp-restore-divi-template con el id de la plantilla borrada la saca de la papelera y la devuelve tal cual estaba, con su fila del Theme Builder (nexo-mcp-restore-builder-backup NO acepta plantillas); la salida trae además previous (el resumen de lo que había). El respaldo completo (metas, título, estado y fila del Theme Builder) queda en la meta _nexo_mcp_template_backup de la plantilla en la papelera. Después se borra el CSS estático de todo el sitio y las cachés de página, como el guardado en vivo de Divi (sin edit_posts Divi no borra el CSS, sin avisar: la respuesta lo avisa). Los layouts de la plantilla NO se borran, pero Divi los marca como sin uso y a los 7 días los manda a la papelera.',
				'',
				'SALIDA: id, deleted, status (trash), title, was_default, site_wide, layouts (los ids de sus layouts), live_theme_builder, previous (el resumen de lo que había), backup, cache {css, page_cache} y warnings.',
				'',
				'ERRORES (no escriben nada): nexo_mcp_divi_template_denied, nexo_mcp_divi5_required, nexo_mcp_divi_template_invalid (con data.path), nexo_mcp_divi_template_not_found, nexo_mcp_divi_template_site_wide, nexo_mcp_divi_template_foreign, nexo_mcp_divi_template_backup_failed, nexo_mcp_busy (otra llamada sobre el Theme Builder en curso: reintenta) y nexo_mcp_divi_template_save_failed (si la papelera falla, la plantilla vuelve al Theme Builder).',
			)
		);
	}

	/**
	 * Esquema de entrada de delete-divi-template.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_template_delete_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Plantilla del Theme Builder en vivo.',
				),
				'confirm_site_wide' => array(
					'type'        => 'boolean',
					'description' => 'Confirma quitar una plantilla por defecto o de sitio entero (…:all, archive:all, 404).',
				),
				'replace_foreign'   => array(
					'type'        => 'boolean',
					'description' => 'Confirma borrar una plantilla que no es del conector (se guarda un respaldo).',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de restore-divi-template.
	 */
	private function divi_template_restore_description(): string {
		return implode(
			"\n",
			array(
				'Devuelve UNA plantilla del Theme Builder EN VIVO de Divi 5 al estado de su respaldo (la meta _nexo_mcp_template_backup de la propia plantilla): sus metas, título, estado y su fila del Theme Builder. Sirve para deshacer set-divi-template y también tras delete-divi-template (la plantilla borrada está en la papelera, sin pantalla en WordPress: se saca de la papelera y vuelve a su fila). Requiere el scope mcp:divi_theme_builder (interruptor encendido por el administrador) con un administrador que tenga el permiso theme_builder de Divi: nexo_mcp_divi_template_denied sin él, antes de mirar la entrada. Requiere Divi 5 activo (nexo_mcp_divi5_required). nexo-mcp-restore-builder-backup NO acepta plantillas.',
				'',
				'EFECTO EN TODO EL SITIO: restaurar cambia lo que ve todo el sitio donde esa plantilla gane (Divi elige UNA sola plantilla por petición). Si la plantilla de ahora o la respaldada es la por defecto o su use_on incluye un …:all, archive:all o 404 hay que pasar confirm_site_wide: true (nexo_mcp_divi_template_site_wide). Si la plantilla de ahora está en el Theme Builder en vivo y no es del conector (hecha en el Visual Builder) hay que pasar replace_foreign: true (nexo_mcp_divi_template_foreign con el resumen en data.summary): lo que tiene ahora se pierde, aunque queda en el respaldo nuevo. Restaurar una plantilla borrada no puede pasar de 50 plantillas (nexo_mcp_divi_template_too_many) ni duplicar la por defecto (nexo_mcp_divi_template_default_exists). El respaldo de una CREACIÓN la manda a la papelera y la saca del Theme Builder en vivo.',
				'',
				'ENTRADA: id (de nexo-mcp-list-divi-theme-builder o de la salida de delete-divi-template), confirm_site_wide y replace_foreign (opcionales).',
				'',
				'RESPALDO Y CACHÉ: antes de escribir se guarda un respaldo del estado de ahora (sin él no se escribe: nexo_mcp_divi_template_backup_failed), así que restaurar dos veces alterna entre los dos estados. Sólo se toca la fila de ESTA plantilla del mapeo. Los layouts que vuelven a una ranura pierden la marca de «sin uso» de Divi (si no, a los 7 días los manda a la papelera). Después se borra el CSS estático de todo el sitio (primer render lento) y las cachés de página, como el guardado en vivo de Divi (sin edit_posts Divi no borra el CSS, sin avisar: la respuesta lo avisa). Si algo falla a medias la plantilla vuelve a su estado anterior.',
				'',
				'SALIDA: id, restored, title, status (publish, o trash si el respaldo era de una creación), mapped (si queda en el Theme Builder en vivo), enabled, default, site_wide, effect (texto del efecto si es de sitio entero), ours, use_on, exclude_from, header, body, footer, previous (el resumen de lo que había antes de restaurar), restored_from {operation, captured_at, existed} (el respaldo usado), backup {operation, captured_at, existed} (el respaldo nuevo del estado anterior), live_theme_builder, cache {css, page_cache} y warnings (por ejemplo un layout que ya no está publicado).',
				'',
				'ERRORES (no escriben nada): nexo_mcp_divi_template_denied, nexo_mcp_divi5_required, nexo_mcp_divi_template_invalid (con data.path), nexo_mcp_divi_template_not_found (no es una plantilla), nexo_mcp_divi_template_no_backup (no hay respaldo), nexo_mcp_divi_template_unavailable (no hay Theme Builder en vivo), nexo_mcp_divi_template_site_wide, nexo_mcp_divi_template_foreign, nexo_mcp_divi_template_too_many, nexo_mcp_divi_template_default_exists, nexo_mcp_divi_template_backup_failed, nexo_mcp_busy (otra llamada sobre el Theme Builder en curso: reintenta) y nexo_mcp_divi_template_save_failed (la plantilla vuelve a su estado anterior).',
			)
		);
	}

	/**
	 * Esquema de entrada de restore-divi-template.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_template_restore_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Plantilla del Theme Builder con un respaldo (también una borrada, en la papelera).',
				),
				'confirm_site_wide' => array(
					'type'        => 'boolean',
					'description' => 'Confirma restaurar una plantilla por defecto o de sitio entero (…:all, archive:all, 404), la de ahora o la respaldada.',
				),
				'replace_foreign'   => array(
					'type'        => 'boolean',
					'description' => 'Confirma reemplazar la plantilla de ahora si no es del conector (se guarda un respaldo).',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de create-divi-layout.
	 */
	private function divi_layout_description(): string {
		return implode(
			"\n",
			array(
				'Crea un layout del Theme Builder de Divi 5 (encabezado, cuerpo o pie) con módulos reales, PUBLICADO y SIN ASIGNAR a ninguna plantilla (inerte hasta enlazarlo). Requiere Divi 5 activo (nexo_mcp_divi5_required) y el scope mcp:divi_theme_builder (interruptor encendido por el administrador) con un administrador que tenga el permiso theme_builder de Divi: un layout se muestra en todo el sitio, así que NO basta el permiso de constructores ni el edit_post de una página (nexo_mcp_divi_layout_denied, se comprueba antes que la entrada).',
				'',
				'EFECTO EN TODO EL SITIO: crear no cambia el sitio, pero asignar el layout a una plantilla sí (afecta a todas las páginas que esa plantilla cubra). Un layout se crea en publish a propósito: uno en borrador, privado o pendiente lo ve igualmente el público. Un layout sin asignar lo marca Divi como sin uso y lo manda a la papelera a los 7 días: asígnalo antes. Un encabezado o pie reemplaza al del tema (se pierden su menú, buscador y carrito). Un cuerpo sin divi/post-content REEMPLAZA el contenido de las páginas a las que se asigne (se pierden el texto de la página y, en WooCommerce, el carrito y el pago): se crea igual, pero la respuesta lo avisa.',
				'',
				'ENTRADA: type (header | body | footer), title y sections: la lista de secciones (sólo divi/section en la raíz; el divi/placeholder que envuelve el contenido lo pone el plugin).',
				$this->divi_tree_help(),
				'EN UN LAYOUT además se admiten, siempre como hijos de una columna: divi/post-content (el contenido de la página o entrada que muestra la plantilla; sin atributos; imprescindible en un cuerpo), divi/post-title (título de la entrada: interruptores on/off en title.advanced.showTitle, meta.advanced.showMeta|showAuthor|showDate|showCategories|showCommentsCount, image.advanced.enabled y meta.advanced.dateFormat, un formato de fecha de PHP de hasta 30 caracteres) y divi/menu (menu.advanced.menuId: el id del menú, como TEXTO, de un menú que existe en el sitio —uno inexistente se rechaza porque Divi lo cambiaría en silencio por el primero—, logo.innerContent {src, alt, linkUrl, linkTarget}, cartIcon.advanced.show y searchIcon.advanced.show en on/off). Una página no los admite.',
				'',
				'SALIDA: id, type, post_type (et_header_layout, et_body_layout o et_footer_layout), status (publish), assigned (false), nodes (lista plana de {path, type, fingerprint} con la ruta de cada nodo; úsala con nexo-mcp-edit-divi-section y nexo-mcp-edit-divi-module, que aceptan el id del layout con este mismo permiso) y warnings (el de sin asignar y, en un cuerpo sin divi/post-content, el de que reemplazaría el contenido). Al crear se borra el CSS estático de todo el sitio (ET_Core_PageResource::remove_static_resources(\'all\', \'all\')): Divi no hace nada, sin avisar, si el usuario de la conexión no tiene edit_posts; los plugins de caché de página se vacían con et_theme_builder_clear_wp_cache.',
				'',
				'ERRORES (no crean nada; si falla el guardado el layout a medias se elimina): nexo_mcp_divi_layout_denied, nexo_mcp_divi_layout_type_invalid, nexo_mcp_divi5_required, nexo_mcp_divi_title_required, nexo_mcp_divi_tree_invalid, nexo_mcp_divi_module_denied, nexo_mcp_divi_nesting_invalid, nexo_mcp_divi_attr_denied, nexo_mcp_divi_value_invalid, nexo_mcp_divi_shortcode_denied, nexo_mcp_divi_dynamic_denied, nexo_mcp_divi_too_many_nodes, nexo_mcp_divi_too_large, nexo_mcp_divi_save_failed.',
			)
		);
	}

	/**
	 * Esquema de entrada de create-divi-layout.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_layout_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => array( 'header', 'body', 'footer' ),
					'description' => 'Qué layout es: encabezado, cuerpo o pie.',
				),
				'title'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'sections' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 200,
					'description' => 'Secciones: lista de nodos {type: "divi/section", attrs?, children?}. Ver la descripción de la herramienta (en un layout, además divi/post-content, divi/post-title y divi/menu dentro de una columna).',
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'required'             => array( 'type', 'title', 'sections' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de edit-divi-section.
	 */
	private function divi_section_description(): string {
		return implode(
			"\n",
			array(
				'Reemplaza, inserta o borra un nodo (sección, fila, columna o módulo) de una página de Divi 5 por su ruta. Escribe en la copia de trabajo de la página (se crea o se reutiliza): el original publicado no cambia hasta que revises y publiques la copia. Nunca publica. Requiere Divi 5 activo (nexo_mcp_divi5_required).',
				'',
				'DESTINO: si la página está publicada el cambio va a su copia de trabajo (draft_of, preview_url); si es un borrador, se edita el borrador. Si el sitio apagó la copia de trabajo por defecto, pasa draft: true para no escribir en vivo. Puedes pasar el id del original o el de su copia. Se respalda antes de guardar (nexo-mcp-restore-builder-backup).',
				'',
				'ENTRADA: id, operation (replace | insert | delete), path y fingerprint del nodo de referencia (se leen con nexo-mcp-get-page-structure o con la salida de nexo-mcp-create-divi-page), position (sólo con insert: before, after o inside; inside agrega al final de los hijos del nodo) y sections: el subárbol nuevo (lista de nodos; no se pasa con delete). Los nodos nuevos se validan contra el tipo real del padre: en una sección sólo caben filas, en una fila columnas, en una columna módulos de contenido, en un divi/icon-list sólo divi/icon-list-item. Los nodos fuera del subárbol reemplazado quedan byte a byte igual. Borrar el único elemento de la raíz (la última sección) se rechaza.',
				$this->divi_tree_help(),
				'',
				'SALIDA: id (el post editado: la copia si la hubo), draft_of (si es copia), preview_url, operation, nodes (lista plana de {path, type, fingerprint} de los nodos nuevos, ya con sus rutas finales; vacía con delete) y removed (la ruta borrada, sólo con delete) y dropped_conditions (sólo con replace o delete, si el subárbol reemplazado o borrado tenía módulos con condiciones de visualización: lista de {path, type, summary}, con el path del módulo antes del cambio y summary el resumen {ours, match, total, conditions: [{type, operator, enabled, supported, summary}]}, sin valores crudos; la escritura se hace igual: replace no puede conservarlas porque los árboles no las admiten, así que vuelve a ponerlas con nexo-mcp-set-divi-conditions). Las rutas de los hermanos posteriores cambian: vuelve a leer antes de otra edición.',
				'',
				'EN UN LAYOUT (ver abajo) replace y delete avisan de lo que se pierde también con dropped_post_content (lista de {path, type: divi/post-content} del subárbol reemplazado o borrado, con el path antes del cambio) y, si un CUERPO se queda sin ningún divi/post-content, con warnings (un cuerpo sin él REEMPLAZA el contenido de las páginas a las que se asigne); la escritura se hace igual. En un layout además se admiten divi/post-content, divi/post-title y divi/menu como hijos de una columna (ver nexo-mcp-create-divi-layout).',
				$this->divi_layout_note(),
				'',
				'ERRORES (no escriben nada ni crean copia): nexo_mcp_divi_layout_denied, nexo_mcp_divi_post_type_denied, nexo_mcp_divi_node_changed (la huella no coincide: el nodo cambió desde la lectura), nexo_mcp_builder_node_missing, nexo_mcp_divi_not_divi5, nexo_mcp_divi5_required, nexo_mcp_divi_operation_invalid, nexo_mcp_divi_position_invalid, nexo_mcp_divi_sections_not_allowed, nexo_mcp_divi_last_section, nexo_mcp_divi_nesting_invalid y los de validación de nexo-mcp-create-divi-page.',
			)
		);
	}

	/**
	 * Esquema de entrada de edit-divi-section.
	 *
	 * @return array<string, mixed>
	 */
	private function divi_section_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 o su copia de trabajo.',
				),
				'operation'   => array(
					'type'        => 'string',
					'enum'        => array( 'replace', 'insert', 'delete' ),
					'description' => 'replace cambia el nodo por sections; insert agrega sections junto al nodo; delete lo borra.',
				),
				'path'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del nodo de referencia (por ejemplo 0.1; ver get-page-structure).',
				),
				'fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del nodo tal como la devolvió la lectura.',
				),
				'position'    => array(
					'type'        => 'string',
					'enum'        => array( 'before', 'after', 'inside' ),
					'description' => 'Sólo con insert: antes, después o dentro (al final) del nodo.',
				),
				'sections'    => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 200,
					'description' => 'Subárbol nuevo: lista de nodos {type, attrs?, children?}. No se pasa con delete.',
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'draft'       => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'operation', 'path', 'fingerprint' ),
			'additionalProperties' => false,
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
		// La copia de un layout del Theme Builder cambia lo que ve todo el sitio al publicarla: el permiso del Theme Builder
		// (no el `edit_post` de una página), el módulo de publicación y poder publicar.
		if ( DiviLayoutGuard::is_layout( $copy ) ) {
			return $this->policy->can_write_divi_theme_builder()
				&& $this->module_write( 'publishing_enabled', Capabilities::PUBLISH, 'read' )
				&& ( 'publish' !== $status || current_user_can( 'publish_posts' ) );
		}

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
				'execute_callback'    => function ( $input = null ) use ( $name, $operation, $callback, $wraps_result ) {
					// La Abilities API entrega null cuando el cliente no manda entrada: es lo mismo que `{}`.
					$input = is_array( $input ) ? $input : array();

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
				'permission_callback' => function ( $input = array() ) use ( $name, $permission, $operation ) {
					$input   = is_array( $input ) ? $input : array();
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
						'destructive' => in_array( $name, array( 'delete-product', 'execute-plugin-action', 'restore-revision', 'undo-design-system-change', 'delete-global-class', 'delete-design-variable', 'update-kit-globals', 'delete-divi-global-color', 'delete-divi-preset', 'save-divi-library-item', 'delete-divi-library-item', 'restore-divi-library-item', 'set-divi-template', 'delete-divi-template', 'restore-divi-template' ), true ),
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
	 * Permiso de escritura del diseño global de Divi (cambia todo el sitio en vivo).
	 */
	private function divi_design_write(): bool {
		return $this->module_write( 'divi_design_tools_enabled', Capabilities::DIVI_DESIGN, 'manage_options' );
	}

	/**
	 * Permiso de escritura de los presets de módulos de Divi (cambian todo el sitio en vivo).
	 */
	private function divi_presets_write(): bool {
		return $this->module_write( 'divi_presets_tools_enabled', Capabilities::DIVI_PRESETS, 'manage_options' );
	}

	/**
	 * Permiso de escritura del área del historial de Divi: `presets` el de presets y el resto el del diseño global.
	 *
	 * @param string|null $area Área del último cambio; null (sin historial) cuenta como diseño global.
	 */
	private function divi_area_write( ?string $area ): bool {
		return 'presets' === $area ? $this->divi_presets_write() : $this->divi_design_write();
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
