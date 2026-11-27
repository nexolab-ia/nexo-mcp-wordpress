<?php
/**
 * Conteos que alimentan la banda del mapa de conexión del panel.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

/**
 * Resume conexiones y módulos para los nodos «Tu IA» y «Módulos».
 */
final class ConnectionMap {

	/**
	 * Etiqueta de cada grupo del Resumen, en el orden en que se muestran.
	 *
	 * @return array<string, string>
	 */
	private static function group_labels(): array {
		return array(
			'site'      => __( 'Sitio', 'nexo-mcp' ),
			'elementor' => __( 'Elementor', 'nexo-mcp' ),
			'divi'      => __( 'Divi 5', 'nexo-mcp' ),
			'risk'      => __( 'Riesgo alto', 'nexo-mcp' ),
		);
	}

	/**
	 * Módulos de herramientas: la única lista de etiquetas, scopes y riesgo.
	 *
	 * La clave es el ajuste que enciende el módulo. `risk` marca los de riesgo
	 * alto: los mismos que el panel confirma al encenderlos
	 * (`data-nexo-risk="on"` en dashboard.php). `group` es la sección donde el
	 * Resumen los muestra: los de riesgo alto que no son de un constructor
	 * (publicar, borrar, mantener) van juntos, y los de un constructor siguen
	 * en el suyo con su etiqueta de riesgo.
	 *
	 * @param bool $is_woocommerce Si WooCommerce está activo; sólo entonces su módulo existe.
	 * @return array<string, array{group: string, label: string, scopes: list<string>, risk: bool}>
	 */
	private static function modules( bool $is_woocommerce ): array {
		$modules = array(
			'wordpress_tools_enabled'          => array(
				'group'  => 'site',
				'label'  => __( 'Herramientas WordPress', 'nexo-mcp' ),
				'scopes' => array( 'mcp:read', 'mcp:write' ),
				'risk'   => false,
			),
			'content_tools_enabled'            => array(
				'group'  => 'site',
				'label'  => __( 'Contenido avanzado', 'nexo-mcp' ),
				'scopes' => array( 'mcp:content' ),
				'risk'   => false,
			),
			'seo_tools_enabled'                => array(
				'group'  => 'site',
				'label'  => __( 'SEO técnico', 'nexo-mcp' ),
				'scopes' => array( 'mcp:seo' ),
				'risk'   => false,
			),
			'appearance_tools_enabled'         => array(
				'group'  => 'site',
				'label'  => __( 'Apariencia global', 'nexo-mcp' ),
				'scopes' => array( 'mcp:appearance' ),
				'risk'   => false,
			),
			'builders_tools_enabled'           => array(
				'group'  => 'elementor',
				'label'  => __( 'Constructores visuales', 'nexo-mcp' ),
				'scopes' => array( 'mcp:builders', 'mcp:builder_attrs' ),
				'risk'   => false,
			),
			'design_system_tools_enabled'      => array(
				'group'  => 'elementor',
				'label'  => __( 'Sistema de diseño de Elementor', 'nexo-mcp' ),
				'scopes' => array( 'mcp:design_system' ),
				'risk'   => true,
			),
			'builders_dynamic_enabled'         => array(
				'group'  => 'elementor',
				'label'  => __( 'Datos dinámicos de Elementor', 'nexo-mcp' ),
				'scopes' => array( 'mcp:builders:dynamic' ),
				'risk'   => true,
			),
			'builders_components_enabled'      => array(
				'group'  => 'elementor',
				'label'  => __( 'Componentes de Elementor', 'nexo-mcp' ),
				'scopes' => array( 'mcp:builders:components' ),
				'risk'   => true,
			),
			'divi_design_tools_enabled'        => array(
				'group'  => 'divi',
				'label'  => __( 'Diseño global de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_design' ),
				'risk'   => true,
			),
			'divi_presets_tools_enabled'       => array(
				'group'  => 'divi',
				'label'  => __( 'Presets de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_presets' ),
				'risk'   => true,
			),
			'divi_dynamic_tools_enabled'       => array(
				'group'  => 'divi',
				'label'  => __( 'Contenido dinámico de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_dynamic' ),
				'risk'   => true,
			),
			'divi_conditions_tools_enabled'    => array(
				'group'  => 'divi',
				'label'  => __( 'Condiciones de visualización de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_conditions' ),
				'risk'   => true,
			),
			'divi_theme_builder_tools_enabled' => array(
				'group'  => 'divi',
				'label'  => __( 'Theme Builder de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_theme_builder' ),
				'risk'   => true,
			),
			'divi_library_tools_enabled'       => array(
				'group'  => 'divi',
				'label'  => __( 'Biblioteca de Divi', 'nexo-mcp' ),
				'scopes' => array( 'mcp:divi_library' ),
				'risk'   => true,
			),
			'maintenance_tools_enabled'        => array(
				'group'  => 'risk',
				'label'  => __( 'Mantenimiento de plugins', 'nexo-mcp' ),
				'scopes' => array( 'mcp:maintenance' ),
				'risk'   => true,
			),
			'extension_deletion_enabled'       => array(
				'group'  => 'risk',
				'label'  => __( 'Borrar plugins y temas', 'nexo-mcp' ),
				'scopes' => array( 'mcp:maintenance' ),
				'risk'   => true,
			),
			'publishing_enabled'               => array(
				'group'  => 'risk',
				'label'  => __( 'Publicación remota', 'nexo-mcp' ),
				'scopes' => array( 'mcp:publish' ),
				'risk'   => true,
			),
			'destructive_enabled'              => array(
				'group'  => 'risk',
				'label'  => __( 'Acciones destructivas', 'nexo-mcp' ),
				'scopes' => array( 'mcp:destructive' ),
				'risk'   => true,
			),
		);

		if ( $is_woocommerce ) {
			$modules['woocommerce_tools_enabled'] = array(
				'group'  => 'site',
				'label'  => __( 'Herramientas WooCommerce', 'nexo-mcp' ),
				'scopes' => array( 'mcp:commerce' ),
				'risk'   => false,
			);
		}

		return $modules;
	}

	/**
	 * Módulos agrupados para el Resumen, sin su estado.
	 *
	 * @param bool $is_woocommerce Si WooCommerce está activo.
	 * @return array<string, list<array{key: string, label: string, scopes: list<string>, risk: bool}>>
	 */
	public static function module_groups( bool $is_woocommerce ): array {
		$labels = self::group_labels();
		$groups = array();

		foreach ( $labels as $label ) {
			$groups[ $label ] = array();
		}

		foreach ( self::modules( $is_woocommerce ) as $key => $module ) {
			$groups[ $labels[ $module['group'] ] ][] = array(
				'key'    => $key,
				'label'  => $module['label'],
				'scopes' => $module['scopes'],
				'risk'   => $module['risk'],
			);
		}

		return $groups;
	}

	/**
	 * Si un alcance pertenece a un módulo de riesgo alto.
	 *
	 * Usa la misma lista de módulos que el Resumen, así que no hay otra que
	 * mantener al sumar un módulo.
	 *
	 * @param string $scope Alcance OAuth, por ejemplo `mcp:destructive`.
	 */
	public static function is_risky_scope( string $scope ): bool {
		foreach ( self::modules( true ) as $module ) {
			if ( $module['risk'] && in_array( $scope, $module['scopes'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cuenta conexiones y módulos.
	 *
	 * @param array<string, mixed> $settings       Ajustes guardados.
	 * @param array<int, mixed>    $connections    Conexiones OAuth vigentes (una por cliente y usuario).
	 * @param bool                 $is_woocommerce Si WooCommerce está activo; sólo entonces su módulo existe.
	 * @return array{connections: int, modules_total: int, modules_active: int, risk_active: int}
	 */
	public static function summarize( array $settings, array $connections, bool $is_woocommerce ): array {
		$modules = self::modules( $is_woocommerce );
		$active  = 0;
		$risk    = 0;

		foreach ( $modules as $key => $module ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}

			++$active;

			if ( $module['risk'] ) {
				++$risk;
			}
		}

		return array(
			'connections'    => count( $connections ),
			'modules_total'  => count( $modules ),
			'modules_active' => $active,
			'risk_active'    => $risk,
		);
	}
}
