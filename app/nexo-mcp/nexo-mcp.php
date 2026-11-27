<?php
/**
 * Plugin Name:       Nexo MCP
 * Description:       Conecta WordPress y WooCommerce con clientes MCP (Claude, Cursor y otros) mediante herramientas explícitas, OAuth 2.1 y permisos seguros.
 * Version:           1.14.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            NexoDev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexo-mcp
 *
 * Nexo MCP es una obra derivada del plugin «MCP Prisma» de SICS, distribuido
 * bajo GPL-2.0-or-later. Se redistribuye modificado bajo la misma licencia.
 * Consulta NOTICE.md para el detalle de la derivación.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEXO_MCP_VERSION', '1.14.1' );
define( 'NEXO_MCP_FILE', __FILE__ );
define( 'NEXO_MCP_PATH', plugin_dir_path( __FILE__ ) );

$nexo_mcp_autoloaders = array(
	NEXO_MCP_PATH . 'vendor/autoload_packages.php',
	NEXO_MCP_PATH . 'vendor/autoload.php',
);

$nexo_mcp_autoloaded = false;

foreach ( $nexo_mcp_autoloaders as $nexo_mcp_autoloader ) {
	if ( file_exists( $nexo_mcp_autoloader ) ) {
		require_once $nexo_mcp_autoloader;
		$nexo_mcp_autoloaded = true;
		break;
	}
}

if ( ! $nexo_mcp_autoloaded ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Nexo MCP no pudo iniciar porque faltan las dependencias de Composer. Instala el plugin desde el ZIP de distribución o ejecuta composer install.',
					'nexo-mcp'
				)
			);
		}
	);

	return;
}

register_activation_hook(
	__FILE__,
	array( \NexoDev\NexoMCP\Activation\Activator::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( \NexoDev\NexoMCP\Activation\Deactivator::class, 'deactivate' )
);

add_action(
	'plugins_loaded',
	static function (): void {
		$requirements = new \NexoDev\NexoMCP\Support\Requirements();

		if ( ! $requirements->is_satisfied() ) {
			add_action(
				'admin_notices',
				static function () use ( $requirements ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					foreach ( $requirements->get_errors() as $error ) {
						printf(
							'<div class="notice notice-error"><p>%s</p></div>',
							esc_html( $error )
						);
					}
				}
			);

			return;
		}

		\NexoDev\NexoMCP\Plugin::instance()->boot();
	},
	20
);
