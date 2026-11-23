<?php
/**
 * Herramientas list-components y create-component: scope, permisos, validación y rechazos sin escribir.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\ComponentStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class ComponentToolsTest extends NexoTestCase {

	private const CREATE = 'nexo-mcp/create-component';
	private const LIST   = 'nexo-mcp/list-components';
	private const SCOPE  = 'mcp:builders:components';

	/**
	 * Llamadas a la creación de Elementor: lo que se habría escrito.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = array();

	/**
	 * Mensaje de los validadores de Elementor (null: pasan).
	 */
	private ?string $elementor_says = null;

	/**
	 * Mensaje de la excepción de Elementor al crear (null: crea).
	 */
	private ?string $elementor_throws = null;

	private bool $pro = true;

	/**
	 * Componentes que devuelve el repositorio de Elementor.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $existing = array();

	protected function setUp(): void {
		parent::setUp();
		$this->created          = array();
		$this->elementor_says   = null;
		$this->elementor_throws = null;
		$this->pro              = true;
		$this->existing         = array();
	}

	private function store(): ComponentStore {
		return new ComponentStore(
			new FakeSchemaGuard(),
			fn(): bool => $this->pro,
			fn(): array => $this->existing,
			fn( string $title, string $uid, array $elements ): ?string => $this->elementor_says,
			function ( string $title, array $elements, string $status, string $uid ): int {
				if ( null !== $this->elementor_throws ) {
					throw new \Exception( $this->elementor_throws );
				}
				$this->created[] = compact( 'title', 'elements', 'status', 'uid' );

				return 500 + count( $this->created );
			}
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian (el interruptor de componentes va encendido).
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array(), ?ComponentStore $store = null ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'builders_components_enabled' => true ), $settings ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, $store ?? $this->store() )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function create_tool( array $settings = array() ): array {
		return $this->abilities( $settings )[ self::CREATE ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input( string $title = 'Tarjeta' ): array {
		return array(
			'title'    => $title,
			'elements' => array(
				array(
					'type'     => 'e-flexbox',
					'elements' => array(
						array(
							'type'     => 'e-heading',
							'settings' => array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'Hola' ) ),
						),
					),
				),
			),
		);
	}

	private function assert_rejected( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $this->created, 'no se escribió nada' );

		return $result;
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_builders_components', Capabilities::BUILDERS_COMPONENTS );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::BUILDERS_COMPONENTS, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertNotSame( self::SCOPE, ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::BUILDERS_COMPONENTS, 'write' ) );
		$this->assertContains( self::SCOPE, ScopeMap::parse( 'mcp:connect ' . self::SCOPE ) );
	}

	public function test_el_interruptor_viene_apagado_y_el_perfil_anuncia_el_scope_sólo_con_el_encendido(): void {
		$this->assertFalse( Settings::defaults()['builders_components_enabled'] );

		update_option( Settings::OPTION_NAME, array( 'read_only' => false, 'builders_tools_enabled' => true ) );
		$this->assertFalse( ( new Settings() )->get( 'builders_components_enabled' ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', new Settings() ) );

		$on = $this->settings( array( 'builders_components_enabled' => true, 'read_only' => false ) );
		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $on ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'read', $on ) );

		$off = $this->settings( array( 'builders_components_enabled' => false, 'read_only' => false ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $off ) );
	}

	public function test_son_herramientas_en_la_allowlist_con_sus_esquemas(): void {
		$all = $this->abilities();

		$this->assertContains( self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertContains( self::CREATE, ExtendedAbilityRegistrar::ABILITIES );

		$create = $all[ self::CREATE ];
		$this->assertFalse( $create['meta']['annotations']['readonly'] );
		$this->assertFalse( $create['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'title', 'elements' ), $create['input_schema']['required'] );
		$this->assertFalse( $create['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'title', 'elements' ), array_keys( $create['input_schema']['properties'] ) );
		foreach ( array( 'borrador', 'mcp:builders:components', 'nexo_mcp_components_unavailable', 'nexo_mcp_component_invalid', 'Elementor Pro' ) as $needle ) {
			$this->assertStringContainsString( $needle, $create['description'], $needle );
		}

		$list = $all[ self::LIST ];
		$this->assertTrue( $list['meta']['annotations']['readonly'] );
		$this->assertFalse( $list['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'include_archived' ), array_keys( $list['input_schema']['properties'] ) );
		$this->assertSame( 'array', $list['output_schema']['properties']['result']['type'] );
	}

	public function test_crear_sin_el_interruptor_de_componentes_se_deniega(): void {
		$this->assertTrue( $this->create_tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->create_tool( array( 'builders_components_enabled' => false ) )['permission_callback']( $this->input() ) );
	}

	public function test_crear_sin_la_capacidad_de_componentes_se_deniega(): void {
		\WP_Test_State::$caps[ Capabilities::BUILDERS_COMPONENTS ] = false;

		$this->assertNotTrue( $this->create_tool()['permission_callback']( $this->input() ) );
	}

	public function test_crear_sin_el_modulo_ni_la_capacidad_de_constructores_o_edit_posts_se_deniega(): void {
		$this->assertNotTrue( $this->create_tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->create_tool()['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = true;
		\WP_Test_State::$caps['edit_posts']             = false;
		$this->assertNotTrue( $this->create_tool()['permission_callback']( $this->input() ) );
	}

	public function test_crear_en_solo_revisar_se_deniega(): void {
		$this->assertNotTrue( $this->create_tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );
	}

	public function test_un_token_necesita_el_scope_de_componentes_y_el_de_constructores(): void {
		$tool = $this->create_tool();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sin mcp:builders:components' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sólo con el scope nuevo' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE ) );
		$this->assertTrue( $tool['permission_callback']( $this->input() ) );
	}

	public function test_el_scope_de_componentes_no_salta_el_interruptor_apagado(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE ) );

		$this->assertNotTrue( $this->create_tool( array( 'builders_components_enabled' => false ) )['permission_callback']( $this->input() ) );
	}

	public function test_el_scope_de_componentes_no_da_permiso_a_las_otras_herramientas_de_constructores(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$all = $this->abilities();

		foreach ( array( 'create-elementor-page', 'edit-elementor-section', 'migrate-elementor-page', 'set-dynamic-tag', 'update-node-text' ) as $name ) {
			$this->assertNotTrue( $all[ 'nexo-mcp/' . $name ]['permission_callback']( array( 'id' => 100, 'operation' => 'delete', 'node_id' => 'x' ) ), $name );
		}
		$this->assertNotTrue( $all[ self::LIST ]['permission_callback']( array() ), 'ni siquiera listar' );
	}

	public function test_listar_exige_lectura_de_constructores_y_no_el_interruptor_de_componentes(): void {
		$this->assertTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'builders_components_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'read_only' => true ) )[ self::LIST ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'builders_tools_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read' ) );
		$this->assertTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
		$this->connect_with_scopes( array( 'mcp:connect' ) );
		$this->assertNotTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
	}

	public function test_listar_sin_modulo_de_componentes_devuelve_lista_vacia(): void {
		$this->assertSame( array( 'result' => array() ), $this->abilities( array(), new ComponentStore() )[ self::LIST ]['execute_callback']( array() ) );
		$this->assertSame( array(), $this->created );
	}

	public function test_listar_devuelve_los_no_archivados_sin_el_arbol_y_con_archivados_si_se_pide(): void {
		$this->existing = array(
			array( 'id' => 10, 'uid' => 'a-1', 'title' => 'Tarjeta', 'status' => 'publish', 'modified' => '2026-11-23 10:00:00', 'archived' => false, 'elements' => array( 'x' ) ),
			array( 'id' => 11, 'uid' => 'a-2', 'title' => 'Vieja', 'status' => 'draft', 'modified' => '2026-11-22 09:00:00', 'archived' => true ),
		);
		$tool = $this->abilities()[ self::LIST ];

		$this->assertSame(
			array( 'result' => array( array( 'id' => 10, 'uid' => 'a-1', 'title' => 'Tarjeta', 'status' => 'publish', 'modified' => '2026-11-23 10:00:00' ) ) ),
			$tool['execute_callback']( array() )
		);

		$this->assertSame(
			array(
				'result' => array(
					array( 'id' => 10, 'uid' => 'a-1', 'title' => 'Tarjeta', 'status' => 'publish', 'modified' => '2026-11-23 10:00:00', 'archived' => false ),
					array( 'id' => 11, 'uid' => 'a-2', 'title' => 'Vieja', 'status' => 'draft', 'modified' => '2026-11-22 09:00:00', 'archived' => true ),
				),
			),
			$tool['execute_callback']( array( 'include_archived' => true ) )
		);

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'list-components', $last['tool'] ?? null );
		$this->assertSame( 'read', $last['operation'] ?? null );
	}

	public function test_crear_guarda_un_borrador_con_uid_propio_y_titulo_saneado(): void {
		$result = $this->create_tool()['execute_callback']( $this->input( '  Tarjeta <b>azul</b> ' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->created );
		$call = $this->created[0];
		$this->assertSame( 'draft', $call['status'] );
		$this->assertSame( 'Tarjeta <b>azul</b>', $call['title'] );
		$this->assertMatchesRegularExpression( '/^nexo-[a-z0-9]{12}$/', $call['uid'] );
		$this->assertSame( 'e-heading', $call['elements'][0]['elements'][0]['widgetType'], 'árbol en el formato que guarda Elementor' );
		$this->assertSame( 'widget', $call['elements'][0]['elements'][0]['elType'] );
		$this->assertSame(
			array( 'id' => 501, 'uid' => $call['uid'], 'title' => 'Tarjeta <b>azul</b>', 'status' => 'draft' ),
			$result
		);

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'create-component', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}

	public function test_cada_creacion_genera_un_uid_distinto(): void {
		$tool = $this->create_tool();
		$tool['execute_callback']( $this->input( 'Uno' ) );
		$tool['execute_callback']( $this->input( 'Dos' ) );

		$this->assertCount( 2, $this->created );
		$this->assertNotSame( $this->created[0]['uid'], $this->created[1]['uid'] );
	}

	public function test_sin_pro_con_licencia_responde_no_disponible_y_no_escribe(): void {
		$this->pro = false;

		$result = $this->assert_rejected( $this->create_tool()['execute_callback']( $this->input() ), 'nexo_mcp_components_unavailable' );
		$this->assertStringContainsString( 'Elementor Pro', $result->get_error_message() );
	}

	public function test_con_el_editor_v4_apagado_responde_no_disponible_y_no_escribe(): void {
		$guard     = new FakeSchemaGuard();
		$guard->v4 = false;
		$store     = new ComponentStore( $guard, fn(): bool => true );

		$this->assert_rejected( $this->abilities( array(), $store )[ self::CREATE ]['execute_callback']( $this->input() ), 'nexo_mcp_components_unavailable' );
	}

	public function test_titulo_invalido_se_rechaza_sin_escribir(): void {
		$tool = $this->create_tool();

		foreach ( array( '', '   ', 'A', str_repeat( 'a', 201 ) ) as $title ) {
			$this->assert_rejected( $tool['execute_callback']( $this->input( $title ) ), 'nexo_mcp_component_invalid' );
		}

		$input = $this->input();
		unset( $input['title'] );
		$this->assert_rejected( $tool['execute_callback']( $input ), 'nexo_mcp_component_invalid' );

		$this->assertIsArray( $tool['execute_callback']( $this->input( 'Ab' ) ) );
		$this->assertIsArray( $tool['execute_callback']( $this->input( str_repeat( 'a', 200 ) ) ) );
	}

	public function test_rechaza_un_widget_v3_sin_escribir(): void {
		$input             = $this->input();
		$input['elements'] = array( array( 'type' => 'e-flexbox', 'elements' => array( array( 'type' => 'heading', 'settings' => array( 'title' => 'Hola' ) ) ) ) );

		$this->assert_rejected( $this->create_tool()['execute_callback']( $input ), 'nexo_mcp_elementor_type_denied' );
	}

	public function test_rechaza_un_componente_anidado_sin_escribir(): void {
		$input             = $this->input();
		$input['elements'] = array(
			array(
				'type'     => 'e-flexbox',
				'elements' => array(
					array(
						'type'     => 'e-component',
						'settings' => array( 'component_instance' => array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => 5 ) ) ) ),
					),
				),
			),
		);

		$this->assert_rejected( $this->create_tool()['execute_callback']( $input ), 'nexo_mcp_elementor_type_denied' );
	}

	public function test_rechaza_una_prop_dinamica_sin_escribir(): void {
		$input                                          = $this->input();
		$input['elements'][0]['elements'][0]['settings']['title'] = array( '$$type' => 'dynamic', 'value' => array( 'name' => 'post-title', 'group' => 'post', 'settings' => array() ) );

		$this->assert_rejected( $this->create_tool()['execute_callback']( $input ), 'nexo_mcp_elementor_prop_type_denied' );
	}

	public function test_un_rechazo_de_los_validadores_de_elementor_es_invalido_y_no_escribe(): void {
		$this->elementor_says = "El título del componente 'Tarjeta' está duplicado.";

		$result = $this->assert_rejected( $this->create_tool()['execute_callback']( $this->input() ), 'nexo_mcp_component_invalid' );
		$this->assertStringContainsString( "El título del componente 'Tarjeta' está duplicado.", $result->get_error_message() );
	}

	public function test_si_elementor_lanza_al_crear_responde_invalido_con_su_mensaje(): void {
		$this->elementor_throws = 'Settings validation failed. title: invalid_value';

		$result = $this->assert_rejected( $this->create_tool()['execute_callback']( $this->input() ), 'nexo_mcp_component_invalid' );
		$this->assertStringContainsString( 'Settings validation failed. title: invalid_value', $result->get_error_message() );
	}

	public function test_los_validadores_reciben_el_titulo_el_uid_y_el_arbol_que_se_guardara(): void {
		$seen  = array();
		$store = new ComponentStore(
			new FakeSchemaGuard(),
			fn(): bool => true,
			fn(): array => array(),
			function ( string $title, string $uid, array $elements ) use ( &$seen ): ?string {
				$seen = compact( 'title', 'uid', 'elements' );

				return null;
			},
			fn( string $title, array $elements, string $status, string $uid ): int => 7
		);

		$result = $this->abilities( array(), $store )[ self::CREATE ]['execute_callback']( $this->input() );

		$this->assertSame( 'Tarjeta', $seen['title'] );
		$this->assertSame( $result['uid'], $seen['uid'] );
		$this->assertSame( 'e-heading', $seen['elements'][0]['elements'][0]['widgetType'] );
	}

	public function test_el_arbol_se_sanea_antes_de_guardar(): void {
		$input = $this->input();
		$input['elements'][0]['elements'][0]['settings']['title']['value'] = 'Hola <script>x</script>';

		$this->create_tool()['execute_callback']( $input );

		$this->assertStringNotContainsString( '<script', (string) wp_json_encode( $this->created[0]['elements'] ) );
	}
}
