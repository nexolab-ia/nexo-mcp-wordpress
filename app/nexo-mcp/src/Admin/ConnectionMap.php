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
	 * Ajustes que encienden un módulo de herramientas, con su riesgo.
	 *
	 * `true` marca los de riesgo alto: los mismos que el panel confirma al
	 * encenderlos (`data-nexo-risk="on"` en dashboard.php).
	 *
	 * @var array<string, bool>
	 */
	private const MODULES = array(
		'wordpress_tools_enabled'          => false,
		'content_tools_enabled'            => false,
		'seo_tools_enabled'                => false,
		'appearance_tools_enabled'         => false,
		'builders_tools_enabled'           => false,
		'design_system_tools_enabled'      => true,
		'builders_dynamic_enabled'         => true,
		'builders_components_enabled'      => true,
		'divi_design_tools_enabled'        => true,
		'divi_presets_tools_enabled'       => true,
		'divi_dynamic_tools_enabled'       => true,
		'divi_conditions_tools_enabled'    => true,
		'divi_theme_builder_tools_enabled' => true,
		'divi_library_tools_enabled'       => true,
		'maintenance_tools_enabled'        => true,
		'extension_deletion_enabled'       => true,
		'publishing_enabled'               => true,
		'destructive_enabled'              => true,
	);

	/**
	 * Cuenta conexiones y módulos.
	 *
	 * @param array<string, mixed> $settings       Ajustes guardados.
	 * @param array<int, mixed>    $connections    Conexiones OAuth vigentes (una por cliente y usuario).
	 * @param bool                 $is_woocommerce Si WooCommerce está activo; sólo entonces su módulo existe.
	 * @return array{connections: int, modules_total: int, modules_active: int, risk_active: int}
	 */
	public static function summarize( array $settings, array $connections, bool $is_woocommerce ): array {
		$modules = self::MODULES;

		if ( $is_woocommerce ) {
			$modules['woocommerce_tools_enabled'] = false;
		}

		$active = 0;
		$risk   = 0;

		foreach ( $modules as $key => $is_risky ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}

			++$active;

			if ( $is_risky ) {
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
