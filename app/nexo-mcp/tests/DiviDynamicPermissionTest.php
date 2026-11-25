<?php
/**
 * Permiso del contenido dinámico de Divi 5: interruptor, capacidad, scope OAuth y ayudante de permiso por página.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Admin\SettingsController;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Installation\Installer;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DiviDynamicPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:divi_dynamic';
	private const SWITCH = 'divi_dynamic_tools_enabled';

	private const ALL_SCOPES = array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE );

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false, 'builders_tools_enabled' => true, self::SWITCH => true ), $overrides ) ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_divi_dynamic', Capabilities::DIVI_DYNAMIC );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::DIVI_DYNAMIC, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertSame( 'Poner contenido dinámico y bucles de consulta en páginas de Divi 5 (muestra datos del sitio al público)', ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DIVI_DYNAMIC, 'write' ) );
		$this->assertContains( self::SCOPE, ScopeMap::parse( 'mcp:connect ' . self::SCOPE ) );
	}

	public function test_el_interruptor_viene_apagado_y_el_perfil_anuncia_el_scope_sólo_con_el_encendido(): void {
		$this->assertFalse( Settings::defaults()[ self::SWITCH ] );

		update_option( Settings::OPTION_NAME, array( 'read_only' => false, 'builders_tools_enabled' => true ) );
		$this->assertFalse( ( new Settings() )->get( self::SWITCH ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', new Settings() ) );

		$on = $this->settings( array( self::SWITCH => true, 'read_only' => false ) );
		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $on ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'read', $on ) );

		$off = $this->settings( array( self::SWITCH => false, 'read_only' => false ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $off ) );
	}

	public function test_los_scopes_e_interruptores_existentes_no_cambian_y_el_nuevo_va_al_final(): void {
		$map      = ( new \ReflectionClassConstant( ScopeMap::class, 'MAP' ) )->getValue();
		$switches = ( new \ReflectionClassConstant( ScopeMap::class, 'MODULE_SWITCH' ) )->getValue();
		$labels   = ( new \ReflectionClassConstant( ScopeMap::class, 'LABELS' ) )->getValue();

		$this->assertSame( 'mcp:divi_presets', array_key_last( array_slice( $switches, 0, -1, true ) ) );
		$this->assertSame( self::SCOPE, array_key_last( $switches ) );
		$this->assertSame( self::SWITCH, end( $switches ) );
		$this->assertSame( 'divi_presets_tools_enabled', $switches['mcp:divi_presets'] );
		$this->assertSame( 'divi_design_tools_enabled', $switches['mcp:divi_design'] );
		$this->assertSame( 'builders_dynamic_enabled', $switches['mcp:builders:dynamic'] );

		$this->assertSame( Capabilities::DIVI_PRESETS, $map['mcp:divi_presets'] );
		$this->assertSame( Capabilities::DIVI_DESIGN, $map['mcp:divi_design'] );
		$this->assertSame( Capabilities::BUILDERS_DYNAMIC, $map['mcp:builders:dynamic'] );
		$this->assertSame( self::SCOPE, array_keys( $map )[ array_search( 'mcp:divi_presets', array_keys( $map ), true ) + 1 ], 'va justo tras presets, antes de las variantes de consulta' );
		$this->assertArrayHasKey( self::SCOPE, $labels );

		$defaults = Settings::defaults();
		foreach ( array( 'builders_dynamic_enabled', 'builders_components_enabled', 'divi_design_tools_enabled', 'divi_presets_tools_enabled' ) as $key ) {
			$this->assertFalse( $defaults[ $key ], $key );
		}
		foreach ( array( 'mcp:builders:dynamic', 'mcp:builders:components', 'mcp:divi_design', 'mcp:divi_presets' ) as $scope ) {
			$this->assertNotSame( $scope, ScopeMap::label_for( $scope ) );
		}
	}

	public function test_el_ayudante_exige_interruptor_scope_capacidad_constructores_y_edit_post(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $this->policy()->can_write_divi_dynamic( 100 ), 'todo en regla' );

		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_dynamic( 100 ), 'interruptor apagado' );
		$this->assertFalse( $this->policy( array( 'builders_tools_enabled' => false ) )->can_write_divi_dynamic( 100 ), 'constructores apagado' );
		$this->assertFalse( $this->policy( array( 'read_only' => true ) )->can_write_divi_dynamic( 100 ), 'sólo revisar' );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertFalse( $this->policy()->can_write_divi_dynamic( 100 ), 'sin edit_post sobre la página' );
		\WP_Test_State::$denied_edit_posts = array();

		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_dynamic( 100 ), 'sin la capacidad' );
		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = true;

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_dynamic( 100 ), 'sin la capacidad de constructores' );
	}

	public function test_el_ayudante_sin_el_scope_o_sin_constructores_en_el_token_deniega(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$policy                     = $this->policy();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:builders:dynamic', 'mcp:divi_design', 'mcp:divi_presets' ) );
		$this->assertFalse( $policy->can_write_divi_dynamic( 100 ), 'el scope de Elementor no sirve para Divi' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertFalse( $policy->can_write_divi_dynamic( 100 ), 'sólo el scope dinámico, sin builders' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertFalse( $policy->can_write_divi_dynamic( 100 ), 'sólo builders, sin el scope dinámico' );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $policy->can_write_divi_dynamic( 100 ) );
	}

	public function test_el_ayudante_pide_edit_post_sobre_el_original_aunque_llegue_el_id_de_la_copia(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\WP_Test_State::$meta[101]  = array( WorkingCopy::META_DRAFT_OF => 100 );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertSame( 100, WorkingCopy::original_id_for( 101 ), 'la copia apunta al original' );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertFalse( $this->policy()->can_write_divi_dynamic( 101 ), 'denegado sobre el original' );
		$this->assertFalse( $this->policy()->can_write_divi_dynamic( 100 ) );

		\WP_Test_State::$denied_edit_posts = array( 101 );
		$this->assertTrue( $this->policy()->can_write_divi_dynamic( 101 ), 'sólo cuenta el original' );
	}

	public function test_el_interruptor_de_elementor_y_los_de_divi_no_encienden_el_dinamico_ni_al_reves(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->connect_with_scopes( self::ALL_SCOPES );

		$others = array( self::SWITCH => false, 'builders_dynamic_enabled' => true, 'divi_design_tools_enabled' => true, 'divi_presets_tools_enabled' => true );
		$this->assertFalse( $this->policy( $others )->can_write_divi_dynamic( 100 ) );

		$only = $this->policy( array( 'builders_dynamic_enabled' => false ) );
		$this->assertFalse( $only->can_module_write( 'builders_dynamic_enabled', Capabilities::BUILDERS_DYNAMIC, 'edit_post', 100 ), 'el de Divi no enciende el de Elementor' );
		$this->assertFalse( $only->can_module_write( 'divi_presets_tools_enabled', Capabilities::DIVI_PRESETS, 'manage_options' ) );
		$this->assertFalse( $only->can_module_write( 'divi_design_tools_enabled', Capabilities::DIVI_DESIGN, 'manage_options' ) );
	}

	public function test_los_ajustes_guardan_el_interruptor_como_booleano_y_los_demas_no_lo_arrastran(): void {
		$controller = new SettingsController();

		foreach ( array( '1', 1, true, 'on' ) as $value ) {
			$saved = $controller->sanitize( array( self::SWITCH => $value ) );
			$this->assertTrue( $saved[ self::SWITCH ], var_export( $value, true ) );
		}

		foreach ( array( '', '0', 0, false, null ) as $value ) {
			$saved = $controller->sanitize( array( self::SWITCH => $value ) );
			$this->assertFalse( $saved[ self::SWITCH ], var_export( $value, true ) );
		}

		$missing = $controller->sanitize(
			array(
				'builders_dynamic_enabled'   => '1',
				'divi_design_tools_enabled'  => '1',
				'divi_presets_tools_enabled' => '1',
			)
		);
		$this->assertFalse( $missing[ self::SWITCH ], 'sin la casilla queda apagado' );
		$this->assertTrue( $missing['builders_dynamic_enabled'] );
		$this->assertTrue( $missing['divi_design_tools_enabled'] );
		$this->assertTrue( $missing['divi_presets_tools_enabled'] );

		$only = $controller->sanitize( array( self::SWITCH => '1' ) );
		$this->assertFalse( $only['builders_dynamic_enabled'] );
		$this->assertFalse( $only['divi_design_tools_enabled'] );
		$this->assertFalse( $only['divi_presets_tools_enabled'] );
	}

	public function test_los_ajustes_sin_permiso_de_administrador_no_encienden_el_interruptor(): void {
		\WP_Test_State::$caps['manage_options'] = false;

		$saved = ( new SettingsController() )->sanitize( array( self::SWITCH => '1' ) );

		$this->assertFalse( $saved[ self::SWITCH ] );
		$this->assertSame( Settings::defaults(), $saved );
	}

	public function test_el_panel_pinta_la_casilla_con_su_estado_y_la_confirmacion_de_opcion_sensible(): void {
		$off = $this->render_dashboard( false );
		$on  = $this->render_dashboard( true );

		foreach ( array( $off, $on ) as $html ) {
			$this->assertStringContainsString( 'name="nexo_mcp_settings[' . self::SWITCH . ']"', $html );
			$this->assertStringContainsString( 'data-nexo-risk="on"', $this->input_tag( $html ) );
			$this->assertStringContainsString( 'data-nexo-confirm-word="dinámico"', $this->input_tag( $html ) );
			$this->assertStringContainsString( 'data-nexo-confirm-title=', $this->input_tag( $html ) );
		}

		$this->assertStringNotContainsString( 'checked', $this->input_tag( $off ) );
		$this->assertStringContainsString( "checked='checked'", $this->input_tag( $on ) );
	}

	public function test_el_instalador_da_la_capacidad_al_administrador_y_no_al_operador(): void {
		\WP_Test_State::$roles['administrator'] = new \WP_Role_Double( 'Administrator' );

		$install_role = new \ReflectionMethod( Installer::class, 'install_role' );
		$install_role->invoke( new Installer() );

		$admin = \WP_Test_State::$roles['administrator'];
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DYNAMIC ) );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_PRESETS ), 'la de D4 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DESIGN ), 'la de D3 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::BUILDERS_DYNAMIC ), 'la de Elementor sigue concedida' );
		$this->assertFalse( \WP_Test_State::$roles['nexo_mcp_operator']->has_cap( Capabilities::DIVI_DYNAMIC ), 'el operador no la recibe' );
	}

	public function test_una_actualizacion_desde_1_9_1_da_la_capacidad_al_administrador_ya_instalado(): void {
		$real_wpdb = $GLOBALS['wpdb'];
		// Doble con lo que pide la creación de tablas (sin base de datos real).
		$GLOBALS['wpdb'] = new class() extends \WPDB_Test_Double {
			public function get_charset_collate(): string {
				return '';
			}
		};
		$GLOBALS['nexo_test_dbdelta'] = array();

		\WP_Test_State::$roles['administrator'] = new \WP_Role_Double( 'Administrator', array( Capabilities::CONNECT => true, Capabilities::DIVI_PRESETS => true ) );
		\WP_Test_State::$options[ Settings::VERSION_OPTION ] = '1.9.1';

		try {
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_DYNAMIC ), 'el sitio ya instalado no la tiene' );

			( new Installer() )->maybe_upgrade();

			$this->assertTrue( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_DYNAMIC ), 'la migración la concede' );
			$this->assertSame( NEXO_MCP_VERSION, \WP_Test_State::$options[ Settings::VERSION_OPTION ] );
			$this->assertNotEmpty( $GLOBALS['nexo_test_dbdelta'], 'la actualización pasó por la instalación completa' );

			// Con la versión al día no vuelve a correr (idempotente y barato).
			\WP_Test_State::$roles['administrator']->capabilities = array();
			( new Installer() )->maybe_upgrade();
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_DYNAMIC ) );
		} finally {
			$GLOBALS['wpdb'] = $real_wpdb;
			unset( $GLOBALS['nexo_test_dbdelta'] );
		}
	}

	private function input_tag( string $html ): string {
		$start = (int) strpos( $html, 'name="nexo_mcp_settings[' . self::SWITCH . ']"' );
		$from  = (int) strrpos( substr( $html, 0, $start ), '<input' );
		$to    = (int) strpos( $html, '>', $start );

		return substr( $html, $from, $to - $from + 1 );
	}

	private function render_dashboard( bool $enabled ): string {
		$settings          = array_merge( Settings::defaults(), array( self::SWITCH => $enabled ) );
		$endpoint          = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https          = true;
		$is_local_http     = false;
		$is_server_ready   = true;
		$is_woocommerce    = false;
		$adapter_version   = '1';
		$audit_entries     = array();
		$registration      = array();
		$oauth_connections = array();
		$oauth_site_url    = 'https://example.test';
		$report_notice     = null;
		$update_available  = false;
		$api_configured    = false;

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';

		return (string) ob_get_clean();
	}
}
