<?php
/**
 * Permiso del Theme Builder de Divi 5: interruptor, capacidad, scope OAuth y ayudante de permiso (sin id de página).
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

final class DiviThemeBuilderPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:divi_theme_builder';
	private const SWITCH = 'divi_theme_builder_tools_enabled';

	private const ALL_SCOPES = array( 'mcp:connect', 'mcp:write', self::SCOPE );

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false, self::SWITCH => true ), $overrides ) ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_divi_theme_builder', Capabilities::DIVI_THEME_BUILDER );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::DIVI_THEME_BUILDER, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertSame( 'Crear y cambiar las plantillas del Theme Builder de Divi 5 y sus layouts (encabezado, pie, cuerpo, archivo y 404): afecta a todo el sitio', ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DIVI_THEME_BUILDER, 'write' ) );
		$this->assertContains( self::SCOPE, ScopeMap::parse( 'mcp:connect ' . self::SCOPE ) );
	}

	public function test_el_interruptor_viene_apagado_y_el_perfil_anuncia_el_scope_sólo_con_el_encendido(): void {
		$this->assertFalse( Settings::defaults()[ self::SWITCH ] );

		update_option( Settings::OPTION_NAME, array( 'read_only' => false ) );
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

		$this->assertSame( 'mcp:divi_conditions', array_key_last( array_slice( $switches, 0, -1, true ) ) );
		$this->assertSame( self::SCOPE, array_key_last( $switches ) );
		$this->assertSame( self::SWITCH, end( $switches ) );
		$this->assertSame( 'divi_conditions_tools_enabled', $switches['mcp:divi_conditions'] );
		$this->assertSame( 'divi_dynamic_tools_enabled', $switches['mcp:divi_dynamic'] );
		$this->assertSame( 'divi_presets_tools_enabled', $switches['mcp:divi_presets'] );
		$this->assertSame( 'divi_design_tools_enabled', $switches['mcp:divi_design'] );

		$this->assertSame( Capabilities::DIVI_CONDITIONS, $map['mcp:divi_conditions'] );
		$this->assertSame( Capabilities::DIVI_DYNAMIC, $map['mcp:divi_dynamic'] );
		$this->assertSame( self::SCOPE, array_keys( $map )[ array_search( 'mcp:divi_conditions', array_keys( $map ), true ) + 1 ], 'va justo tras condiciones, antes de las variantes de consulta' );
		$this->assertArrayHasKey( self::SCOPE, $labels );

		$defaults = Settings::defaults();
		foreach ( array( 'builders_dynamic_enabled', 'divi_design_tools_enabled', 'divi_presets_tools_enabled', 'divi_dynamic_tools_enabled', 'divi_conditions_tools_enabled' ) as $key ) {
			$this->assertFalse( $defaults[ $key ], $key );
		}
		foreach ( array( 'mcp:divi_design', 'mcp:divi_presets', 'mcp:divi_dynamic', 'mcp:divi_conditions' ) as $scope ) {
			$this->assertNotSame( $scope, ScopeMap::label_for( $scope ) );
		}
	}

	public function test_el_ayudante_exige_interruptor_scope_capacidad_edit_theme_options_y_edit_others_posts(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $this->policy()->can_write_divi_theme_builder(), 'todo en regla' );

		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_theme_builder(), 'interruptor apagado' );
		$this->assertFalse( $this->policy( array( 'read_only' => true ) )->can_write_divi_theme_builder(), 'sólo revisar' );

		\WP_Test_State::$caps['edit_theme_options'] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'sin edit_theme_options' );
		\WP_Test_State::$caps['edit_theme_options'] = true;

		\WP_Test_State::$caps['edit_others_posts'] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'sin edit_others_posts' );
		\WP_Test_State::$caps['edit_others_posts'] = true;

		\WP_Test_State::$caps[ Capabilities::DIVI_THEME_BUILDER ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'sin la capacidad' );
		\WP_Test_State::$caps[ Capabilities::DIVI_THEME_BUILDER ] = true;

		\WP_Test_State::$caps[ Capabilities::WRITE ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'sin la capacidad de escritura' );
		\WP_Test_State::$caps[ Capabilities::WRITE ] = true;

		\WP_Test_State::$caps[ Capabilities::CONNECT ] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'sin la capacidad de conectar' );
		\WP_Test_State::$caps[ Capabilities::CONNECT ] = true;

		$this->assertTrue( $this->policy()->can_write_divi_theme_builder(), 'restaurado' );
	}

	public function test_el_ayudante_no_recibe_id_de_pagina(): void {
		$method = new \ReflectionMethod( PermissionPolicy::class, 'can_write_divi_theme_builder' );
		$this->assertSame( 0, $method->getNumberOfParameters() );
	}

	public function test_el_ayudante_sin_el_scope_en_el_token_deniega(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;
		$policy                                    = $this->policy();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:builders:dynamic', 'mcp:divi_design', 'mcp:divi_presets', 'mcp:divi_dynamic', 'mcp:divi_conditions' ) );
		$this->assertFalse( $policy->can_write_divi_theme_builder(), 'los otros scopes de Divi y de constructores no sirven' );

		$this->connect_with_scopes( array( 'mcp:connect', self::SCOPE ) );
		$this->assertFalse( $policy->can_write_divi_theme_builder(), 'sin mcp:write' );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $policy->can_write_divi_theme_builder() );
	}

	public function test_el_theme_builder_las_condiciones_y_lo_dinamico_no_se_encienden_entre_si(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;
		\WP_Test_State::$posts[100]                = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->connect_with_scopes( array_merge( self::ALL_SCOPES, array( 'mcp:builders', 'mcp:divi_dynamic', 'mcp:divi_conditions' ) ) );
		$others = array(
			self::SWITCH                    => false,
			'builders_tools_enabled'        => true,
			'builders_dynamic_enabled'      => true,
			'divi_design_tools_enabled'     => true,
			'divi_presets_tools_enabled'    => true,
			'divi_dynamic_tools_enabled'    => true,
			'divi_conditions_tools_enabled' => true,
		);
		$this->assertFalse( $this->policy( $others )->can_write_divi_theme_builder(), 'los otros interruptores no encienden el Theme Builder' );

		$only = $this->policy( array( 'builders_tools_enabled' => true, 'divi_dynamic_tools_enabled' => false, 'divi_conditions_tools_enabled' => false ) );
		$this->assertTrue( $only->can_write_divi_theme_builder(), 'el Theme Builder funciona solo' );
		$this->assertFalse( $only->can_write_divi_dynamic( 100 ), 'el Theme Builder no enciende lo dinámico' );
		$this->assertFalse( $only->can_write_divi_conditions( 100 ), 'el Theme Builder no enciende las condiciones' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:divi_dynamic', 'mcp:divi_conditions' ) );
		$conditions = $this->policy( array( self::SWITCH => false, 'builders_tools_enabled' => true, 'divi_dynamic_tools_enabled' => true, 'divi_conditions_tools_enabled' => true ) );
		$this->assertTrue( $conditions->can_write_divi_dynamic( 100 ), 'lo dinámico sigue funcionando' );
		$this->assertTrue( $conditions->can_write_divi_conditions( 100 ), 'las condiciones siguen funcionando' );
		$this->assertFalse( $conditions->can_write_divi_theme_builder(), 'ni lo dinámico ni las condiciones encienden el Theme Builder' );
	}

	public function test_sin_la_funcion_de_divi_el_ayudante_no_la_exige(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;
		$this->connect_with_scopes( self::ALL_SCOPES );

		$this->assertFalse( function_exists( 'et_pb_is_allowed' ), 'la prueba corre sin Divi cargado' );
		$this->assertTrue( $this->policy()->can_write_divi_theme_builder() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_con_la_funcion_de_divi_el_permiso_theme_builder_decide(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;
		$GLOBALS['nexo_test_et_allowed']           = array();
		$GLOBALS['nexo_test_et_asked']             = array();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble de Divi, definido sólo en este proceso aparte.
		eval( 'function et_pb_is_allowed( $capability ) { $GLOBALS["nexo_test_et_asked"][] = $capability; return ! empty( $GLOBALS["nexo_test_et_allowed"][ $capability ] ); }' );
		$this->connect_with_scopes( self::ALL_SCOPES );

		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'Divi no da theme_builder' );
		$this->assertContains( 'theme_builder', $GLOBALS['nexo_test_et_asked'] );

		$GLOBALS['nexo_test_et_allowed'] = array( 'theme_builder' => true );
		$this->assertTrue( $this->policy()->can_write_divi_theme_builder(), 'Divi da theme_builder' );

		$GLOBALS['nexo_test_et_allowed'] = array( 'theme_builder' => true );
		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_theme_builder(), 'Divi no salta el interruptor' );
		\WP_Test_State::$caps['edit_theme_options'] = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'Divi no salta edit_theme_options' );
		\WP_Test_State::$caps['edit_theme_options'] = true;
		\WP_Test_State::$caps['edit_others_posts']  = false;
		$this->assertFalse( $this->policy()->can_write_divi_theme_builder(), 'Divi no salta edit_others_posts' );
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
				'divi_design_tools_enabled'     => '1',
				'divi_presets_tools_enabled'    => '1',
				'divi_dynamic_tools_enabled'    => '1',
				'divi_conditions_tools_enabled' => '1',
			)
		);
		$this->assertFalse( $missing[ self::SWITCH ], 'sin la casilla queda apagado' );
		$this->assertTrue( $missing['divi_design_tools_enabled'] );
		$this->assertTrue( $missing['divi_presets_tools_enabled'] );
		$this->assertTrue( $missing['divi_dynamic_tools_enabled'] );
		$this->assertTrue( $missing['divi_conditions_tools_enabled'] );

		$only = $controller->sanitize( array( self::SWITCH => '1' ) );
		$this->assertFalse( $only['divi_design_tools_enabled'] );
		$this->assertFalse( $only['divi_presets_tools_enabled'] );
		$this->assertFalse( $only['divi_dynamic_tools_enabled'] );
		$this->assertFalse( $only['divi_conditions_tools_enabled'] );
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
			$this->assertStringContainsString( 'data-nexo-confirm-word="themebuilder"', $this->input_tag( $html ) );
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
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_THEME_BUILDER ) );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_CONDITIONS ), 'la de D7 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DYNAMIC ), 'la de D5 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_PRESETS ), 'la de D4 sigue concedida' );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_DESIGN ), 'la de D3 sigue concedida' );
		$this->assertFalse( \WP_Test_State::$roles['nexo_mcp_operator']->has_cap( Capabilities::DIVI_THEME_BUILDER ), 'el operador no la recibe' );
	}

	public function test_una_actualizacion_desde_una_version_anterior_da_la_capacidad_al_administrador_ya_instalado(): void {
		$real_wpdb = $GLOBALS['wpdb'];
		// Doble con lo que pide la creación de tablas (sin base de datos real).
		$GLOBALS['wpdb'] = new class() extends \WPDB_Test_Double {
			public function get_charset_collate(): string {
				return '';
			}
		};
		$GLOBALS['nexo_test_dbdelta'] = array();

		\WP_Test_State::$roles['administrator']              = new \WP_Role_Double( 'Administrator', array( Capabilities::CONNECT => true, Capabilities::DIVI_CONDITIONS => true ) );
		\WP_Test_State::$options[ Settings::VERSION_OPTION ] = '1.10.0';

		try {
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_THEME_BUILDER ), 'el sitio ya instalado no la tiene' );

			( new Installer() )->maybe_upgrade();

			$this->assertTrue( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_THEME_BUILDER ), 'la migración la concede' );
			$this->assertSame( NEXO_MCP_VERSION, \WP_Test_State::$options[ Settings::VERSION_OPTION ] );
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
