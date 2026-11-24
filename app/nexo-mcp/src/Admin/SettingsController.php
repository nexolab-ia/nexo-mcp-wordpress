<?php
/**
 * Registro y validación de ajustes.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use NexoDev\NexoMCP\OAuth\DiscoveryController;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Registra los ajustes y aplica una allowlist estricta.
 */
final class SettingsController {

	public const GROUP = 'nexo_mcp_settings_group';

	/**
	 * Registra la opción mediante Settings API.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'object',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitiza la política operativa recibida.
	 *
	 * @param mixed $input Valor recibido desde Settings API.
	 * @return array<string, bool|int|string>
	 */
	public function sanitize( $input ): array {
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			add_settings_error(
				Settings::OPTION_NAME,
				'nexo_mcp_invalid_settings',
				__( 'No fue posible guardar la configuración.', 'nexo-mcp' ),
				'error'
			);

			return Settings::defaults();
		}

		$sanitized = Settings::defaults();

		$sanitized['server_enabled']              = ! empty( $input['server_enabled'] );
		$sanitized['wordpress_tools_enabled']     = ! empty( $input['wordpress_tools_enabled'] );
		$sanitized['woocommerce_tools_enabled']   = class_exists( 'WooCommerce' )
			&& ! empty( $input['woocommerce_tools_enabled'] );
		$sanitized['content_tools_enabled']       = ! empty( $input['content_tools_enabled'] );
		$sanitized['seo_tools_enabled']           = ! empty( $input['seo_tools_enabled'] );
		$sanitized['appearance_tools_enabled']    = ! empty( $input['appearance_tools_enabled'] );
		$sanitized['builders_tools_enabled']      = ! empty( $input['builders_tools_enabled'] );
		$sanitized['design_system_tools_enabled'] = ! empty( $input['design_system_tools_enabled'] );
		$sanitized['builders_dynamic_enabled']    = ! empty( $input['builders_dynamic_enabled'] );
		$sanitized['builders_components_enabled'] = ! empty( $input['builders_components_enabled'] );
		$sanitized['divi_design_tools_enabled']   = ! empty( $input['divi_design_tools_enabled'] );
		$sanitized['maintenance_tools_enabled']   = ! empty( $input['maintenance_tools_enabled'] );
		$sanitized['extension_deletion_enabled']  = ! empty( $input['extension_deletion_enabled'] );
		$sanitized['publishing_enabled']          = ! empty( $input['publishing_enabled'] );
		$sanitized['destructive_enabled']         = ! empty( $input['destructive_enabled'] );
		$sanitized['read_only']                   = ! empty( $input['read_only'] );
		$sanitized['drafts_only']                 = ! empty( $input['drafts_only'] );
		$sanitized['working_copy_default']        = ! empty( $input['working_copy_default'] );
		$sanitized['cache_purge_enabled']         = ! empty( $input['cache_purge_enabled'] );
		$sanitized['enforce_connection_scopes']   = ! empty( $input['enforce_connection_scopes'] );
		$sanitized['audit_retention_days']        = 90;

		$escrito = isset( $input['proxy_resource'] ) ? (string) $input['proxy_resource'] : '';
		$alias   = DiscoveryController::sanitize_resource_alias( wp_unslash( $escrito ) );

		// La casilla manda: desmarcarla borra la dirección aunque siga escrita en
		// el campo, para que apagar el túnel no dependa de acordarse de vaciarlo.
		$sanitized['proxy_resource'] = empty( $input['proxy_enabled'] ) ? '' : $alias;

		if ( '' !== trim( $escrito ) && '' === $alias && ! empty( $input['proxy_enabled'] ) ) {
			add_settings_error(
				Settings::OPTION_NAME,
				'nexo_mcp_invalid_proxy_resource',
				__( 'La dirección del túnel o proxy no es válida y no se guardó. Debe ser una dirección HTTPS completa, sin parámetros y sin usuario ni contraseña.', 'nexo-mcp' ),
				'error'
			);

			return $sanitized;
		}

		add_settings_error(
			Settings::OPTION_NAME,
			'nexo_mcp_settings_saved',
			__( 'Configuración guardada.', 'nexo-mcp' ),
			'updated'
		);

		return $sanitized;
	}
}
