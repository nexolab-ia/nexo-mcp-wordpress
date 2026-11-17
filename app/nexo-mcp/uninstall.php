<?php
/**
 * Desinstalación deliberadamente conservadora.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'NEXO_MCP_REMOVE_DATA' ) || true !== NEXO_MCP_REMOVE_DATA ) {
	return;
}

$nexo_mcp_remove_options = static function (): void {
	delete_option( 'nexo_mcp_settings' );
	delete_option( 'nexo_mcp_version' );
	delete_option( 'nexo_mcp_registration' );
	delete_option( 'nexo_mcp_update' );
	delete_option( 'nexo_mcp_seo_settings' );
	delete_option( 'nexo_mcp_maintenance_lock' );
};

if ( is_multisite() ) {
	$nexo_mcp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $nexo_mcp_site_ids as $nexo_mcp_site_id ) {
		switch_to_blog( (int) $nexo_mcp_site_id );
		$nexo_mcp_remove_options();
		restore_current_blog();
	}
} else {
	$nexo_mcp_remove_options();
}
