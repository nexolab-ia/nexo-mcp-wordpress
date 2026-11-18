<?php
/**
 * Reglas de permisos: sólo lectura, módulos y alcances por conexión.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\DenialExplainer;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;

final class PermissionPolicyTest extends NexoTestCase {

	// ------------------------------------------------------------ Sólo lectura

	public function test_sitio_nuevo_empieza_en_solo_lectura(): void {
		$policy = new PermissionPolicy( $this->settings() );

		$this->assertTrue( $policy->is_read_only() );
		$this->assertTrue( $policy->can_read( 'read' ) );
		$this->assertFalse( $policy->can_write( 'edit_posts' ) );
	}

	public function test_solo_lectura_bloquea_toda_escritura_aunque_el_usuario_sea_admin(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => true, 'destructive_enabled' => true ) ) );

		$this->assertFalse( $policy->can_write( 'edit_posts' ) );
		$this->assertFalse( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
		$this->assertFalse( $policy->can_destroy( 'delete_post' ) );
		// Leer sigue permitido.
		$this->assertTrue( $policy->can_module_read( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
	}

	public function test_apagar_solo_lectura_permite_escribir(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );

		$this->assertTrue( $policy->can_write( 'edit_posts' ) );
	}

	public function test_la_denegacion_en_solo_lectura_explica_la_causa(): void {
		$error = ( new DenialExplainer( $this->settings( array( 'read_only' => true ) ) ) )->explain( 'write' );

		$this->assertSame( DenialExplainer::CODE, $error->get_error_code() );
		$this->assertStringContainsString( 'Solo revisar', $error->get_error_message() );
	}

	public function test_conector_apagado_niega_todo(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'server_enabled' => false, 'read_only' => false ) ) );

		$this->assertFalse( $policy->can_connect() );
		$this->assertFalse( $policy->can_read( 'read' ) );
		$this->assertFalse( $policy->can_write( 'edit_posts' ) );
	}

	// -------------------------------------------------------- Módulos on / off

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function modules(): array {
		return array(
			'contenido'      => array( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED ),
			'seo'            => array( 'seo_tools_enabled', Capabilities::SEO ),
			'apariencia'     => array( 'appearance_tools_enabled', Capabilities::APPEARANCE ),
			'mantenimiento'  => array( 'maintenance_tools_enabled', Capabilities::MAINTENANCE ),
			'woocommerce'    => array( 'woocommerce_tools_enabled', Capabilities::COMMERCE ),
			'constructores'  => array( 'builders_tools_enabled', Capabilities::BUILDERS ),
			'diseño global'  => array( 'design_system_tools_enabled', Capabilities::DESIGN_SYSTEM ),
		);
	}

	/**
	 * @dataProvider modules
	 */
	public function test_modulo_desactivado_niega_lectura_y_escritura( string $switch, string $cap ): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false, $switch => false ) ) );

		$this->assertFalse( $policy->can_module_read( $switch, $cap, 'read' ) );
		$this->assertFalse( $policy->can_module_write( $switch, $cap, 'edit_posts' ) );
	}

	/**
	 * @dataProvider modules
	 */
	public function test_modulo_activado_permite_con_capacidad( string $switch, string $cap ): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false, $switch => true ) ) );

		$this->assertTrue( $policy->can_module_read( $switch, $cap, 'read' ) );
		$this->assertTrue( $policy->can_module_write( $switch, $cap, 'edit_posts' ) );
	}

	public function test_modulo_activado_no_basta_sin_capacidad_de_wordpress(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		unset( \WP_Test_State::$caps['edit_theme_options'] );

		$this->assertFalse( $policy->can_module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' ) );
	}

	public function test_acciones_destructivas_apagadas_por_defecto(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );

		$this->assertFalse( $policy->can_destroy( 'delete_post' ) );
	}

	public function test_acciones_destructivas_encendidas_permiten_borrar(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false, 'destructive_enabled' => true ) ) );

		$this->assertTrue( $policy->can_destroy( 'delete_post' ) );
	}

	// -------------------------------------------------- Alcances por conexión

	public function test_conexion_de_solo_lectura_no_puede_escribir_aunque_el_sitio_lo_permita(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read' ) );

		$this->assertTrue( $policy->can_read( 'read' ) );
		$this->assertFalse( $policy->can_write( 'edit_posts' ) );
	}

	public function test_conexion_con_mcp_write_puede_escribir(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write' ) );

		$this->assertTrue( $policy->can_write( 'edit_posts' ) );
	}

	public function test_escritura_de_modulo_exige_el_scope_del_modulo(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write' ) );

		$this->assertFalse( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:seo' ) );

		$this->assertTrue( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
	}

	public function test_variante_de_consulta_permite_leer_pero_no_escribir(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:seo:read' ) );

		$this->assertTrue( $policy->can_module_read( 'seo_tools_enabled', Capabilities::SEO, 'read' ) );
		$this->assertFalse( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
	}

	public function test_scope_concedido_no_supera_un_modulo_apagado(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false, 'seo_tools_enabled' => false ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:seo' ) );

		$this->assertFalse( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
	}

	public function test_borrar_exige_scope_destructivo_en_la_conexion(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false, 'destructive_enabled' => true ) ) );
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write' ) );

		$this->assertFalse( $policy->can_destroy( 'delete_post' ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:destructive' ) );

		$this->assertTrue( $policy->can_destroy( 'delete_post' ) );
	}

	public function test_token_sin_mcp_connect_no_conecta(): void {
		$policy = new PermissionPolicy( $this->settings() );
		$this->connect_with_scopes( array( 'mcp:read' ) );

		$this->assertFalse( $policy->can_connect() );
	}

	public function test_sin_aplicar_alcances_rige_solo_el_panel(): void {
		$policy = new PermissionPolicy(
			$this->settings(
				array(
					'read_only'                 => false,
					'enforce_connection_scopes' => false,
				)
			)
		);
		$this->connect_with_scopes( array( 'mcp:connect' ) );

		$this->assertTrue( $policy->can_write( 'edit_posts' ) );
		$this->assertTrue( $policy->can_module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_posts' ) );
	}

	public function test_application_password_no_lleva_scopes_y_rige_el_panel(): void {
		$policy = new PermissionPolicy( $this->settings( array( 'read_only' => false ) ) );
		// Sin token Bearer: autenticación por Application Password.

		$this->assertTrue( $policy->can_write( 'edit_posts' ) );
	}

	public function test_la_denegacion_por_alcance_lo_explica(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read' ) );

		$error = ( new DenialExplainer( $this->settings( array( 'read_only' => false ) ) ) )->explain( 'write' );

		$this->assertStringContainsString( 'mcp:write', $error->get_error_message() );
	}
}
