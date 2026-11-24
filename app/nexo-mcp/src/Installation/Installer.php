<?php
/**
 * Instalación y upgrades de infraestructura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Installation;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Mantiene opciones, roles y tablas en la versión esperada.
 */
final class Installer {

	/**
	 * Ejecuta una instalación completa e idempotente.
	 */
	public function install(): void {
		( new Settings() )->install_defaults();
		$this->install_role();
		$this->install_tables();
		update_option( Settings::VERSION_OPTION, NEXO_MCP_VERSION, false );
	}

	/**
	 * Aplica upgrades cuando cambia la versión persistida.
	 */
	public function maybe_upgrade(): void {
		$installed_version = (string) get_option( Settings::VERSION_OPTION, '' );

		if ( NEXO_MCP_VERSION !== $installed_version ) {
			$this->install();
		}
	}

	/**
	 * Crea un rol sin permisos destructivos ni de publicación.
	 */
	private function install_role(): void {
		$capabilities = array(
			'read'                         => true,
			'edit_posts'                   => true,
			'edit_pages'                   => true,
			'upload_files'                 => true,
			'manage_categories'            => true,
			Capabilities::CONNECT          => true,
			Capabilities::READ             => true,
			Capabilities::WRITE            => true,
			Capabilities::CONTENT_ADVANCED => true,
			Capabilities::SEO              => true,
		);

		$name = __( 'Operador Nexo MCP', 'nexo-mcp' );
		$role = get_role( 'nexo_mcp_operator' );

		if ( null === $role ) {
			$role = add_role( 'nexo_mcp_operator', $name, $capabilities );
		} else {
			$this->rename_role( 'nexo_mcp_operator', $name );
		}

		if ( null !== $role ) {
			foreach ( $capabilities as $capability => $grant ) {
				$role->add_cap( $capability, $grant );
			}
		}

		$administrator = get_role( 'administrator' );

		if ( null !== $administrator ) {
			$administrator->add_cap( Capabilities::CONNECT );
			$administrator->add_cap( Capabilities::READ );
			$administrator->add_cap( Capabilities::WRITE );
			$administrator->add_cap( Capabilities::MANAGE_CREDENTIALS );
			$administrator->add_cap( Capabilities::CONTENT_ADVANCED );
			$administrator->add_cap( Capabilities::SEO );
			$administrator->add_cap( Capabilities::APPEARANCE );
			$administrator->add_cap( Capabilities::MAINTENANCE );
			$administrator->add_cap( Capabilities::COMMERCE );
			$administrator->add_cap( Capabilities::BUILDERS );
			$administrator->add_cap( Capabilities::BUILDER_ATTRS );
			$administrator->add_cap( Capabilities::BUILDERS_DYNAMIC );
			$administrator->add_cap( Capabilities::BUILDERS_COMPONENTS );
			$administrator->add_cap( Capabilities::PUBLISH );
			$administrator->add_cap( Capabilities::DESTRUCTIVE );
			$administrator->add_cap( Capabilities::DESIGN_SYSTEM );
			$administrator->add_cap( Capabilities::DIVI_DESIGN );
		}
	}

	/**
	 * Actualiza el nombre visible de un rol que ya existe.
	 *
	 * La función add_role() no toca un rol creado, así que los sitios que instalaron una
	 * versión anterior seguirían mostrando el nombre viejo. Solo cambia la
	 * etiqueta: el identificador y las capacidades quedan iguales.
	 *
	 * @param string $slug Identificador del rol.
	 * @param string $name Nombre visible nuevo.
	 */
	private function rename_role( string $slug, string $name ): void {
		$roles = wp_roles();

		if ( ! isset( $roles->roles[ $slug ] ) || $name === $roles->roles[ $slug ]['name'] ) {
			return;
		}

		$roles->roles[ $slug ]['name'] = $name;
		$roles->role_names[ $slug ]    = $name;

		if ( $roles->use_db ) {
			update_option( $roles->role_key, $roles->roles );
		}
	}

	/**
	 * Crea las tablas con dbDelta para soportar prefijos y multisite.
	 */
	private function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$audit_table     = $wpdb->prefix . 'nexo_mcp_audit_log';
		$rate_table      = $wpdb->prefix . 'nexo_mcp_rate_limits';
		$clients_table   = $wpdb->prefix . 'nexo_mcp_oauth_clients';
		$codes_table     = $wpdb->prefix . 'nexo_mcp_oauth_codes';
		$tokens_table    = $wpdb->prefix . 'nexo_mcp_oauth_tokens';

		$audit_sql = "CREATE TABLE {$audit_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			user_id bigint(20) unsigned NULL,
			credential_uuid varchar(36) NULL,
			tool varchar(128) NOT NULL,
			operation varchar(16) NOT NULL,
			status varchar(20) NOT NULL,
			parameter_summary text NULL,
			result_summary text NULL,
			ip_hash char(64) NULL,
			ip_address varchar(45) NULL,
			duration_ms decimal(12,3) NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY user_tool (user_id, tool)
		) {$charset_collate};";

		$rate_sql = "CREATE TABLE {$rate_table} (
			bucket_key char(64) NOT NULL,
			window_start datetime NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 1,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (bucket_key),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		// El client_id puede ser una URL cuando el cliente usa Client ID Metadata
		// Documents. Se limita a 191 caracteres para que quepa como clave primaria
		// bajo utf8mb4 sin depender de prefijos de índice extendidos.
		$clients_sql = "CREATE TABLE {$clients_table} (
			client_id varchar(191) NOT NULL,
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris text NOT NULL,
			metadata_url varchar(512) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (client_id)
		) {$charset_collate};";

		$codes_sql = "CREATE TABLE {$codes_table} (
			code_hash char(64) NOT NULL,
			client_id varchar(191) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			redirect_uri varchar(512) NOT NULL,
			scope varchar(512) NOT NULL DEFAULT '',
			resource varchar(512) NOT NULL DEFAULT '',
			code_challenge varchar(128) NOT NULL DEFAULT '',
			code_challenge_method varchar(10) NOT NULL DEFAULT 'S256',
			consumed tinyint(1) NOT NULL DEFAULT 0,
			consumed_at datetime NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (code_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$tokens_sql = "CREATE TABLE {$tokens_table} (
			token_hash char(64) NOT NULL,
			token_type varchar(16) NOT NULL,
			client_id varchar(191) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(512) NOT NULL DEFAULT '',
			resource varchar(512) NOT NULL DEFAULT '',
			code_hash char(64) NOT NULL DEFAULT '',
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			last_used_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY user_client (user_id, client_id),
			KEY code_hash (code_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $audit_sql );
		dbDelta( $rate_sql );
		dbDelta( $clients_sql );
		dbDelta( $codes_sql );
		dbDelta( $tokens_sql );
	}
}
