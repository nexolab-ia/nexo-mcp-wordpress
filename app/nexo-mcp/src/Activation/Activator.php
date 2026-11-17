<?php
/**
 * Activación del plugin.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Activation;

use NexoDev\NexoMCP\Admin\LicenseController;
use NexoDev\NexoMCP\Support\Requirements;
use NexoDev\NexoMCP\Installation\Installer;

/**
 * Instala opciones iniciales al activar el plugin.
 */
final class Activator {

	/**
	 * Activa el plugin en un sitio o en toda la red.
	 *
	 * @param bool $network_wide Indica si WordPress solicitó una activación de red.
	 */
	public static function activate( bool $network_wide = false ): void {
		$requirements = new Requirements();

		if ( ! $requirements->is_satisfied() ) {
			deactivate_plugins( plugin_basename( NEXO_MCP_FILE ) );

			wp_die(
				esc_html( implode( ' ', $requirements->get_errors() ) ),
				esc_html__( 'No se pudo activar Nexo MCP', 'nexo-mcp' ),
				array( 'back_link' => true )
			);
		}

		$installer = new Installer();

		// La revalidación es una sola para todo el sitio, no una por blog: el
		// estado de la licencia se guarda a nivel de red cuando la activación
		// es de red.
		LicenseController::schedule();

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$installer->install();
				restore_current_blog();
			}

			return;
		}

		$installer->install();
	}
}
