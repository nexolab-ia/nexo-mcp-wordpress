<?php
/**
 * Permiso de las condiciones de visualización de Divi 5: interruptor, capacidad, scope OAuth y ayudante de permiso por página.
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

final class DiviConditionsPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:divi_conditions';
	private const SWITCH = 'divi_conditions_tools_enabled';

	private const ALL_SCOPES = array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE );

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false, 'builders_tools_enabled' => true, self::SWITCH => true ), $overrides ) ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_divi_conditions', Capabilities::DIVI_CONDITIONS );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::DIVI_CONDITIONS, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertSame( 'Poner condiciones de visualización en los módulos de Divi 5 (decide qué contenido ve cada visitante)', ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DIVI_CONDITIONS, 'write' ) );
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

		$this->assertSame( 'mcp:divi_dynamic', array_key_last( array_slice( $switches, 0, -1, true ) ) );
		$this->assertSame( self::SCOPE, array_key_last( $switches ) );
		$this->assertSame( self::SWITCH, end( $switches ) );
		$this->assertSame( 'divi_dynamic_tools_enabled', $switches['mcp:divi_dynamic'] );
		$this->assertSame( 'divi_presets_tools_enabled', $switches['mcp:divi_presets'] );
		$this->assertSame( 'divi_design_tools_enabled', $switches['mcp:divi_design'] );
		$this->assertSame( 'builders_dynamic_enabled', $switches['mcp:builders:dynamic'] );

		$this->assertSame( Capabilities::DIVI_DYNAMIC, $map['mcp:divi_dynamic'] );
		$this->assertSame( Capabilities::DIVI_PRESETS, $map['mcp:divi_presets'] );
		$this->assertSame( Capabilities::DIVI_DESIGN, $map['mcp:divi_design'] );
		$this->assertSame( Capabilities::BUILDERS_DYNAMIC, $map['mcp:builders:dynamic'] );
		$this->assertSame( self::SCOPE, array_keys( $map )[ array_search( 'mcp:divi_dynamic', array_keys( $map ), true ) + 1 ], 'va justo tras el dinámico, antes de las variantes de consulta' );
		$this->assertArrayHasKey( self::SCOPE, $labels );

		$defaults = Settings::defaults();
		foreach ( array( 'builders_dynamic_enabled', 'builders_components_enabled', 'divi_design_tools_enabled', 'divi_presets_tools_enabled', 'divi_dynamic_tools_enabled' ) as $key ) {
			$this->assertFalse( $defaults[ $key ], $key );
		}
		foreach ( array( 'mcp:builders:dynamic', 'mcp:builders:components', 'mcp:divi_design', 'mcp:divi_presets', 'mcp:divi_dynamic' ) as $scope ) {
			$this->assertNotSame( $scope, ScopeMap::label_for( $scope ) );
		}
	}

	public function test_el_ayudante_exige_interruptor_scope_capacidad_constructores_y_edit_post(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $this->policy()->can_write_divi_conditions( 100 ), 'todo en regla' );

		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_conditions( 100 ), 'interruptor apagado' );
		$this->assertFalse( $this->policy( array( 'builders_tools_enabled' => false ) )->can_write_divi_conditions( 100 ), 'constructores apagado' );
		$this->assertFalse( $this->policy( array( 'read_only' => true ) )->can_write_divi_conditions( 100 ), 'sólo revisar' );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertFalse( $this->policy()->can_write_divi_conditions( 100 ), 'sin edit_post sobre la página' );
		\WP_Test_State::$denied_edit_posts = array();

		\WP_Test_State::$caps[ Capabilities::DIVI_CONDITIONS ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_conditions( 100 ), 'sin la capacidad' );
		\WP_Test_State::$caps[ Capabilities::DIVI_CONDITIONS ] = true;

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_conditions( 100 ), 'sin la capacidad de constructores' );
	}

	public function test_el_ayudante_sin_el_scope_o_sin_constructores_en_el_token_deniega(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$policy                     = $this->policy();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:builders:dynamic', 'mcp:divi_design', 'mcp:divi_presets', 'mcp:divi_dynamic' ) );
		$this->assertFalse( $policy->can_write_divi_conditions( 100 ), 'el scope de Elementor y los otros de Divi no sirven' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertFalse( $policy->can_write_divi_conditions( 100 ), 'sólo el scope de condiciones, sin builders' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertFalse( $policy->can_write_divi_conditions( 100 ), 'sólo builders, sin el scope de condiciones' );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $policy->can_write_divi_conditions( 100 ) );
	}

	public function test_el_ayudante_pide_edit_post_sobre_el_original_aunque_llegue_el_id_de_la_copia(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\WP_Test_State::$meta[101]  = array( WorkingCopy::META_DRAFT_OF => 100 );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertSame( 100, WorkingCopy::original_id_for( 101 ), 'la copia apunta al original' );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertFalse( $this->policy()->can_write_divi_conditions( 101 ), 'denegado sobre el original' );
		$this->assertFalse( $this->policy()->can_write_divi_conditions( 100 ) );

		\WP_Test_State::$denied_edit_posts = array( 101 );
		$this->assertTrue( $this->policy()->can_write_divi_conditions( 101 ), 'sólo cuenta el original' );
	}

	public function test_el_interruptor_de_elementor_y_los_de_divi_no_encienden_el_dinamico_ni_al_reves(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->connect_with_scopes( self::ALL_SCOPES );

		$others = array( self::SWITCH => false, 'builders_dynamic_enabled' => true, 'divi_design_tools_enabled' => true, 'divi_presets_tools_enabled' => true, 'divi_dynamic_tools_enabled' => true );
		$this->assertFalse( $this->policy( $others )->can_write_divi_conditions( 100 ) );

		$only = $this->policy( array( 'builders_dynamic_enabled' => false ) );
		$this->assertFalse( $only->can_module_write( 'divi_dynamic_tools_enabled', Capabilities::DIVI_DYNAMIC, 'edit_post', 100 ), 'el de condiciones no enciende el dinámico' );
		$this->connect_with_scopes( array_merge( self::ALL_SCOPES, array( 'mcp:divi_dynamic' ) ) );
		$dynamic_only = $this->policy( array( self::SWITCH => false, 'divi_dynamic_tools_enabled' => true ) );
		$this->assertTrue( $dynamic_only->can_write_divi_dynamic( 100 ), 'el dinámico sigue funcionando solo' );
		$this->assertFalse( $dynamic_only->can_write_divi_conditions( 100 ), 'el dinámico no enciende las condiciones' );
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
				'divi_dynamic_tools_enabled' => '1',
			)
		);
		$this->assertFalse( $missing[ self::SWITCH ], 'sin la casilla queda apagado' );
		$this->assertTrue( $missing['builders_dynamic_enabled'] );
		$this->assertTrue( $missing['divi_design_tools_enabled'] );
		$this->assertTrue( $missing['divi_presets_tools_enabled'] );
		$this->assertTrue( $missing['divi_dynamic_tools_enabled'] );

		$only = $controller->sanitize( array( self::SWITCH => '1' ) );
		$this->assertFalse( $only['builders_dynamic_enabled'] );
		$this->assertFalse( $only['divi_design_tools_enabled'] );
		$this->assertFalse( $only['divi_presets_tools_enabled'] );
		$this->assertFalse( $only['divi_dynamic_tools_enabled'] );
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
			$this->assertStringContainsString( 'data-nexo-confirm-word="condiciones"', $this->input_tag( $html ) );
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
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_CONDITIONS ) );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DYNAMIC ), 'la de D5 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_PRESETS ), 'la de D4 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DESIGN ), 'la de D3 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::BUILDERS_DYNAMIC ), 'la de Elementor sigue concedida' );
		$this->assertFalse( \WP_Test_State::$roles['nexo_mcp_operator']->has_cap( Capabilities::DIVI_CONDITIONS ), 'el operador no la recibe' );
	}

	public function test_una_actualizacion_desde_1_10_0_da_la_capacidad_al_administrador_ya_instalado(): void {
		$real_wpdb = $GLOBALS['wpdb'];
		// Doble con lo que pide la creación de tablas (sin base de datos real).
		$GLOBALS['wpdb'] = new class() extends \WPDB_Test_Double {
			public function get_charset_collate(): string {
				return '';
			}
		};
		$GLOBALS['nexo_test_dbdelta'] = array();

		\WP_Test_State::$roles['administrator'] = new \WP_Role_Double( 'Administrator', array( Capabilities::CONNECT => true, Capabilities::DIVI_PRESETS => true, Capabilities::DIVI_DYNAMIC => true ) );
		\WP_Test_State::$options[ Settings::VERSION_OPTION ] = '1.10.0';

		try {
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_CONDITIONS ), 'el sitio ya instalado no la tiene' );

			( new Installer() )->maybe_upgrade();

			$this->assertTrue( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_CONDITIONS ), 'la migración la concede' );
			$this->assertSame( NEXO_MCP_VERSION, \WP_Test_State::$options[ Settings::VERSION_OPTION ] );
			$this->assertNotEmpty( $GLOBALS['nexo_test_dbdelta'], 'la actualización pasó por la instalación completa' );

			// Con la versión al día no vuelve a correr (idempotente y barato).
			\WP_Test_State::$roles['administrator']->capabilities = array();
			( new Installer() )->maybe_upgrade();
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_CONDITIONS ) );
		} finally {
			$GLOBALS['wpdb'] = $real_wpdb;
			unset( $GLOBALS['nexo_test_dbdelta'] );
		}
	}

	public function test_the_released_version_is_newer_than_1_10_0_so_installed_sites_reach_the_grant(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/nexo-mcp.php' );
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header ) );
		$this->assertSame( 1, preg_match( "/define\( 'NEXO_MCP_VERSION', '([^']+)' \)/", $plugin, $constant ) );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s+(\S+)$/m', $readme, $stable ) );

		$this->assertSame( $header[1], $constant[1], 'cabecera y constante coinciden' );
		$this->assertSame( $header[1], $stable[1], 'cabecera y Stable tag coinciden' );
		$this->assertTrue( version_compare( $header[1], '1.10.0', '>' ), 'maybe_upgrade sólo concede la capacidad si la versión cambia respecto a la instalada (1.10.0)' );
		$this->assertStringContainsString( '= ' . $header[1] . ' =', $readme, 'el changelog documenta esta versión' );
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
