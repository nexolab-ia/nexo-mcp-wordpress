<?php
/**
 * Permiso de la biblioteca de Divi 5: interruptor, capacidad, scope OAuth y ayudante de permiso (con id de ítem opcional).
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

final class DiviLibraryPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:divi_library';
	private const SWITCH = 'divi_library_tools_enabled';
	private const ITEM   = 700;
	private const GLOBAL = 701;

	private const ALL_SCOPES = array( 'mcp:connect', 'mcp:write', self::SCOPE );

	protected function setUp(): void {
		parent::setUp();

		\WP_Test_State::$posts[ self::ITEM ]   = new \WP_Post( array( 'ID' => self::ITEM, 'post_type' => 'et_pb_layout', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[ self::GLOBAL ] = new \WP_Post( array( 'ID' => self::GLOBAL, 'post_type' => 'et_pb_layout', 'post_status' => 'publish' ) );
		\WP_Test_State::$terms[ self::GLOBAL ] = array( 'scope' => array( 'global' => 31 ) );
		\WP_Test_State::$posts[100]            = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
	}

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false, self::SWITCH => true ), $overrides ) ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_divi_library', Capabilities::DIVI_LIBRARY );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::DIVI_LIBRARY, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertSame( 'Guardar, cambiar y borrar elementos de la biblioteca de Divi 5 y reutilizarlos en las páginas (un elemento global cambia todas las páginas que lo usan)', ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DIVI_LIBRARY, 'write' ) );
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

		$this->assertSame( 'mcp:divi_theme_builder', array_key_last( array_slice( $switches, 0, -1, true ) ) );
		$this->assertSame( self::SCOPE, array_key_last( $switches ) );
		$this->assertSame( self::SWITCH, end( $switches ) );
		$this->assertSame( 'divi_theme_builder_tools_enabled', $switches['mcp:divi_theme_builder'] );
		$this->assertSame( 'divi_conditions_tools_enabled', $switches['mcp:divi_conditions'] );

		$this->assertSame( Capabilities::DIVI_THEME_BUILDER, $map['mcp:divi_theme_builder'] );
		$this->assertSame( self::SCOPE, array_keys( $map )[ array_search( 'mcp:divi_theme_builder', array_keys( $map ), true ) + 1 ], 'va justo tras el Theme Builder, antes de las variantes de consulta' );
		$this->assertArrayHasKey( self::SCOPE, $labels );

		$defaults = Settings::defaults();
		foreach ( array( 'divi_design_tools_enabled', 'divi_presets_tools_enabled', 'divi_dynamic_tools_enabled', 'divi_conditions_tools_enabled', 'divi_theme_builder_tools_enabled' ) as $key ) {
			$this->assertFalse( $defaults[ $key ], $key );
		}
	}

	public function test_sin_id_exige_interruptor_scope_capacidad_y_edit_posts(): void {
		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $this->policy()->can_write_divi_library(), 'todo en regla' );

		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_library(), 'interruptor apagado' );
		$this->assertFalse( $this->policy( array( 'read_only' => true ) )->can_write_divi_library(), 'sólo revisar' );

		foreach ( array( 'edit_posts', Capabilities::DIVI_LIBRARY, Capabilities::WRITE, Capabilities::CONNECT ) as $cap ) {
			\WP_Test_State::$caps[ $cap ] = false;
			$this->assertFalse( $this->policy()->can_write_divi_library(), 'sin ' . $cap );
			\WP_Test_State::$caps[ $cap ] = true;
		}

		$this->assertTrue( $this->policy()->can_write_divi_library(), 'restaurado' );
	}

	public function test_con_id_exige_un_item_de_la_biblioteca_que_el_usuario_pueda_editar(): void {
		$this->connect_with_scopes( self::ALL_SCOPES );
		$policy = $this->policy();

		$this->assertTrue( $policy->can_write_divi_library( self::ITEM ), 'ítem propio de la biblioteca' );
		$this->assertTrue( $policy->can_write_divi_library( self::GLOBAL ), 'ítem global, sin Divi cargado' );
		$this->assertFalse( $policy->can_write_divi_library( 100 ), 'una página no es un ítem de la biblioteca' );
		$this->assertFalse( $policy->can_write_divi_library( 999 ), 'un id que no existe' );
		$this->assertFalse( $policy->can_write_divi_library( 0 ), 'id cero' );

		\WP_Test_State::$denied_edit_posts = array( self::ITEM );
		$this->assertFalse( $policy->can_write_divi_library( self::ITEM ), 'sin edit_post sobre el ítem' );
		$this->assertTrue( $policy->can_write_divi_library( self::GLOBAL ), 'el permiso es por ítem' );
		\WP_Test_State::$denied_edit_posts = array();

		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_library( self::ITEM ), 'el id no salta el interruptor' );
		\WP_Test_State::$caps[ Capabilities::DIVI_LIBRARY ] = false;
		$this->assertFalse( $policy->can_write_divi_library( self::ITEM ), 'el id no salta la capacidad' );
	}

	public function test_ids_negativos_y_de_otros_tipos_de_divi_no_pasan(): void {
		$this->connect_with_scopes( self::ALL_SCOPES );
		\WP_Test_State::$posts[702] = new \WP_Post( array( 'ID' => 702, 'post_type' => 'et_body_layout', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[703] = new \WP_Post( array( 'ID' => 703, 'post_type' => 'et_template', 'post_status' => 'publish' ) );
		$policy                      = $this->policy();

		$this->assertFalse( $policy->can_write_divi_library( -1 ), 'id negativo' );
		$this->assertFalse( $policy->can_write_divi_library( -self::ITEM ), 'id negativo de un ítem real' );
		$this->assertFalse( $policy->can_write_divi_library( 702 ), 'un layout del TB no es de la biblioteca' );
		$this->assertFalse( $policy->can_write_divi_library( 703 ), 'una plantilla del TB no es de la biblioteca' );

		\WP_Test_State::$caps['edit_posts'] = false;
		$this->assertFalse( $policy->can_write_divi_library( self::ITEM ), 'el id no salta edit_posts' );
	}

	public function test_sin_el_scope_en_el_token_deniega(): void {
		$policy = $this->policy();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:divi_design', 'mcp:divi_presets', 'mcp:divi_dynamic', 'mcp:divi_conditions', 'mcp:divi_theme_builder' ) );
		$this->assertFalse( $policy->can_write_divi_library(), 'los otros scopes de Divi no sirven' );
		$this->assertFalse( $policy->can_write_divi_library( self::ITEM ) );

		$this->connect_with_scopes( array( 'mcp:connect', self::SCOPE ) );
		$this->assertFalse( $policy->can_write_divi_library(), 'sin mcp:write' );

		$this->connect_with_scopes( self::ALL_SCOPES );
		$this->assertTrue( $policy->can_write_divi_library() );
	}

	public function test_la_biblioteca_y_el_theme_builder_no_se_encienden_entre_si(): void {
		\WP_Test_State::$caps['edit_others_posts'] = true;
		$this->connect_with_scopes( array_merge( self::ALL_SCOPES, array( 'mcp:divi_theme_builder' ) ) );

		$tb_only = $this->policy( array( self::SWITCH => false, 'divi_theme_builder_tools_enabled' => true ) );
		$this->assertFalse( $tb_only->can_write_divi_library(), 'el Theme Builder no enciende la biblioteca' );
		$this->assertTrue( $tb_only->can_write_divi_theme_builder() );

		$library_only = $this->policy( array( 'divi_theme_builder_tools_enabled' => false ) );
		$this->assertTrue( $library_only->can_write_divi_library(), 'la biblioteca funciona sola' );
		$this->assertFalse( $library_only->can_write_divi_theme_builder(), 'la biblioteca no enciende el Theme Builder' );
	}

	public function test_sin_la_funcion_de_divi_el_ayudante_no_la_exige(): void {
		$this->connect_with_scopes( self::ALL_SCOPES );

		$this->assertFalse( function_exists( 'et_pb_is_allowed' ), 'la prueba corre sin Divi cargado' );
		$this->assertTrue( $this->policy()->can_write_divi_library() );
		$this->assertTrue( $this->policy()->can_write_divi_library( self::GLOBAL ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_con_la_funcion_de_divi_deciden_sus_permisos_de_biblioteca(): void {
		$GLOBALS['nexo_test_et_allowed'] = array();
		$GLOBALS['nexo_test_et_asked']   = array();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble de Divi, definido sólo en este proceso aparte.
		eval( 'function et_pb_is_allowed( $capability ) { $GLOBALS["nexo_test_et_asked"][] = $capability; return ! empty( $GLOBALS["nexo_test_et_allowed"][ $capability ] ); }' );
		$this->connect_with_scopes( self::ALL_SCOPES );

		$this->assertFalse( $this->policy()->can_write_divi_library(), 'Divi no da divi_library' );
		$this->assertContains( 'divi_library', $GLOBALS['nexo_test_et_asked'] );

		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true );
		$this->assertFalse( $this->policy()->can_write_divi_library(), 'crear pide además save_library' );
		$this->assertContains( 'save_library', $GLOBALS['nexo_test_et_asked'] );
		$this->assertTrue( $this->policy()->can_write_divi_library( self::ITEM ), 'cambiar un ítem no global no pide save_library' );
		$this->assertFalse( $this->policy()->can_write_divi_library( self::GLOBAL ), 'un ítem global pide edit_global_library' );
		$this->assertContains( 'edit_global_library', $GLOBALS['nexo_test_et_asked'] );

		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true, 'edit_global_library' => true );
		$this->assertTrue( $this->policy()->can_write_divi_library(), 'Divi da divi_library y save_library' );
		$this->assertTrue( $this->policy()->can_write_divi_library( self::GLOBAL ), 'Divi da edit_global_library' );

		$GLOBALS['nexo_test_et_allowed'] = array( 'save_library' => true, 'edit_global_library' => true );
		$this->assertFalse( $this->policy()->can_write_divi_library( self::ITEM ), 'sin divi_library no hay ítem que valga' );

		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true, 'edit_global_library' => true );
		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_write_divi_library(), 'Divi no salta el interruptor' );
		\WP_Test_State::$caps['edit_posts'] = false;
		$this->assertFalse( $this->policy()->can_write_divi_library( self::ITEM ), 'Divi no salta edit_posts' );
		\WP_Test_State::$caps['edit_posts'] = true;

		$GLOBALS['nexo_test_et_allowed']  = array( 'divi_library' => true, 'save_library' => true );
		\WP_Test_State::$terms_error = 'scope';
		$this->assertFalse( $this->policy()->can_write_divi_library( self::ITEM ), 'sin poder leer los términos se trata como global' );
		$GLOBALS['nexo_test_et_allowed']['edit_global_library'] = true;
		$this->assertTrue( $this->policy()->can_write_divi_library( self::ITEM ), 'con edit_global_library pasa' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_un_global_de_divi_4_sin_termino_pero_con_modulo_y_opciones_excluidas_pide_edit_global_library(): void {
		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true );
		$GLOBALS['nexo_test_et_asked']   = array();
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble de Divi, definido sólo en este proceso aparte.
		eval( 'function et_pb_is_allowed( $capability ) { $GLOBALS["nexo_test_et_asked"][] = $capability; return ! empty( $GLOBALS["nexo_test_et_allowed"][ $capability ] ); }' );
		$this->connect_with_scopes( self::ALL_SCOPES );

		\WP_Test_State::$posts[710] = new \WP_Post( array( 'ID' => 710, 'post_type' => 'et_pb_layout', 'post_status' => 'publish' ) );
		update_post_meta( 710, '_et_pb_module_type', 'divi/text' );
		update_post_meta( 710, '_et_pb_excluded_global_options', '[]' );

		$this->assertFalse( $this->policy()->can_write_divi_library( 710 ), 'Divi lo trata como global aunque no tenga el término' );
		$this->assertContains( 'edit_global_library', $GLOBALS['nexo_test_et_asked'] );

		$GLOBALS['nexo_test_et_allowed']['edit_global_library'] = true;
		$this->assertTrue( $this->policy()->can_write_divi_library( 710 ), 'con edit_global_library pasa' );

		// Sin una de las dos metas no es global (como Divi).
		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true );
		delete_post_meta( 710, '_et_pb_excluded_global_options' );
		$this->assertTrue( $this->policy()->can_write_divi_library( 710 ), 'sin opciones excluidas no es global' );
	}

	public function test_los_ajustes_guardan_el_interruptor_como_booleano_y_los_demas_no_lo_arrastran(): void {
		$controller = new SettingsController();

		foreach ( array( '1', 1, true, 'on' ) as $value ) {
			$this->assertTrue( $controller->sanitize( array( self::SWITCH => $value ) )[ self::SWITCH ], var_export( $value, true ) );
		}
		foreach ( array( '', '0', 0, false, null ) as $value ) {
			$this->assertFalse( $controller->sanitize( array( self::SWITCH => $value ) )[ self::SWITCH ], var_export( $value, true ) );
		}

		$missing = $controller->sanitize( array( 'divi_theme_builder_tools_enabled' => '1', 'divi_conditions_tools_enabled' => '1' ) );
		$this->assertFalse( $missing[ self::SWITCH ], 'sin la casilla queda apagado' );
		$this->assertTrue( $missing['divi_theme_builder_tools_enabled'] );
		$this->assertTrue( $missing['divi_conditions_tools_enabled'] );

		$only = $controller->sanitize( array( self::SWITCH => '1' ) );
		$this->assertFalse( $only['divi_theme_builder_tools_enabled'] );
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
			$this->assertStringContainsString( 'data-nexo-confirm-word="biblioteca"', $this->input_tag( $html ) );
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
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_LIBRARY ) );
		$this->assertTrue( $admin->has_cap( Capabilities::DIVI_THEME_BUILDER ), 'la de D8a sigue concedida' );
		$this->assertFalse( \WP_Test_State::$roles['nexo_mcp_operator']->has_cap( Capabilities::DIVI_LIBRARY ), 'el operador no la recibe' );
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

		\WP_Test_State::$roles['administrator']              = new \WP_Role_Double( 'Administrator', array( Capabilities::CONNECT => true, Capabilities::DIVI_THEME_BUILDER => true ) );
		\WP_Test_State::$options[ Settings::VERSION_OPTION ] = '1.11.0';

		try {
			$this->assertFalse( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_LIBRARY ), 'el sitio ya instalado no la tiene' );

			( new Installer() )->maybe_upgrade();

			$this->assertTrue( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_LIBRARY ), 'la migración la concede' );
		} finally {
			$GLOBALS['wpdb'] = $real_wpdb;
			unset( $GLOBALS['nexo_test_dbdelta'] );
		}
	}

	public function test_la_version_publicada_es_posterior_a_1_12_0_para_que_los_sitios_instalados_reciban_la_capacidad(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/nexo-mcp.php' );
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header ) );
		$this->assertSame( 1, preg_match( "/define\( 'NEXO_MCP_VERSION', '([^']+)' \)/", $plugin, $constant ) );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s+(\S+)$/m', $readme, $stable ) );

		$this->assertSame( $header[1], $constant[1], 'cabecera y constante coinciden' );
		$this->assertSame( $header[1], $stable[1], 'cabecera y Stable tag coinciden' );
		$this->assertTrue( version_compare( $header[1], '1.12.0', '>' ), 'maybe_upgrade sólo concede la capacidad si la versión cambia respecto a la instalada (1.12.0, que no la tiene)' );
		$this->assertStringContainsString( '= ' . $header[1] . ' =', $readme, 'el changelog documenta esta versión' );
		// El scope nació en 1.13.0: su entrada del changelog lo menciona (las versiones posteriores no tienen por qué).
		$this->assertStringContainsString( '= 1.13.0 =', $readme, 'el changelog documenta la versión que introdujo el scope' );
		$this->assertStringContainsString( 'mcp:divi_library', substr( $readme, (int) strpos( $readme, '= 1.13.0 =' ), 600 ), 'y menciona el scope nuevo' );
	}

	public function test_un_sitio_en_1_12_0_sin_la_capacidad_la_recibe_al_actualizar(): void {
		$real_wpdb = $GLOBALS['wpdb'];
		// Doble con lo que pide la creación de tablas (sin base de datos real).
		$GLOBALS['wpdb'] = new class() extends \WPDB_Test_Double {
			public function get_charset_collate(): string {
				return '';
			}
		};
		$GLOBALS['nexo_test_dbdelta'] = array();

		\WP_Test_State::$roles['administrator']              = new \WP_Role_Double( 'Administrator', array( Capabilities::CONNECT => true, Capabilities::DIVI_THEME_BUILDER => true ) );
		\WP_Test_State::$options[ Settings::VERSION_OPTION ] = '1.12.0';

		try {
			( new Installer() )->maybe_upgrade();

			$this->assertTrue( \WP_Test_State::$roles['administrator']->has_cap( Capabilities::DIVI_LIBRARY ) );
			$this->assertNotSame( '1.12.0', \WP_Test_State::$options[ Settings::VERSION_OPTION ], 'la versión instalada pasa a la nueva' );
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
