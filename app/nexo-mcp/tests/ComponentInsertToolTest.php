<?php
/**
 * Herramienta insert-component: registro, permisos, disponibilidad y rechazos sin escribir.
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
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ComponentInsertToolTest extends NexoTestCase {

	private const TOOL  = 'nexo-mcp/insert-component';
	private const SCOPE = 'mcp:builders:components';

	private bool $can_add = true;

	/**
	 * Filas que devuelve el repositorio de Elementor.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	private FakeSchemaGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->can_add = true;
		$this->guard   = new FakeSchemaGuard();
		$this->rows    = array(
			array( 'id' => 7, 'uid' => 'a', 'title' => 'Tarjeta', 'status' => 'draft', 'modified' => '', 'archived' => false ),
			array( 'id' => 8, 'uid' => 'b', 'title' => 'Vieja', 'status' => 'publish', 'modified' => '', 'archived' => true ),
			array( 'id' => 9, 'uid' => 'c', 'title' => 'Borrada', 'status' => 'trash', 'modified' => '', 'archived' => false ),
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian (el interruptor de componentes va encendido).
	 * @return array<string, mixed>
	 */
	private function tool( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'builders_components_enabled' => true ), $settings ) );
		$store                     = new ComponentStore(
			$this->guard,
			static fn(): bool => true,
			fn(): array => $this->rows,
			static fn(): ?string => null,
			static fn(): int => 1,
			fn(): bool => $this->can_add
		);

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, $store )
		) )->register_abilities();

		return \WP_Test_State::$abilities[ self::TOOL ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input( int $id = 100, int $component = 7 ): array {
		return array(
			'id'           => $id,
			'node_id'      => 'eh00001',
			'position'     => 'after',
			'component_id' => $component,
		);
	}

	private function put_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		update_post_meta( 100, '_elementor_edit_mode', 'builder' );
		update_post_meta( 100, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::atomic() ) );
	}

	private function put_copy_of_page(): void {
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Inicio' ) );
		update_post_meta( 101, WorkingCopy::META_DRAFT_OF, 100 );
		update_post_meta( 100, WorkingCopy::META_DRAFT, 101 );
		update_post_meta( 101, '_elementor_edit_mode', 'builder' );
		update_post_meta( 101, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::atomic() ) );
	}

	private function snapshot(): string {
		return serialize( array( array_keys( \WP_Test_State::$posts ), \WP_Test_State::$meta ) );
	}

	public function test_esta_en_la_allowlist_con_su_esquema_y_su_descripcion(): void {
		$tool = $this->tool();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'node_id', 'position', 'component_id' ), $tool['input_schema']['required'] );
		$this->assertSame( array( 'id', 'draft', 'node_id', 'position', 'component_id' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'before', 'after', 'inside' ), $tool['input_schema']['properties']['position']['enum'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );

		foreach ( array( 'copia de trabajo', 'Nunca publica', 'mcp:builders:components', 'nexo_mcp_component_not_found', 'nexo_mcp_components_unavailable', 'list-components', 'overrides' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_con_todo_encendido_el_permiso_se_concede(): void {
		$this->assertTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_sin_el_interruptor_de_componentes_se_deniega(): void {
		$this->assertNotTrue( $this->tool( array( 'builders_components_enabled' => false ) )['permission_callback']( $this->input() ) );
	}

	public function test_sin_la_capacidad_de_componentes_se_deniega(): void {
		\WP_Test_State::$caps[ Capabilities::BUILDERS_COMPONENTS ] = false;

		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_sin_el_modulo_o_la_capacidad_de_constructores_se_deniega(): void {
		$this->assertNotTrue( $this->tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_en_solo_revisar_o_sin_edit_post_se_deniega(): void {
		$this->assertNotTrue( $this->tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps['edit_post'] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_un_token_necesita_el_scope_de_componentes_y_el_de_constructores(): void {
		$tool = $this->tool();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sin el scope de componentes' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sólo el scope de componentes' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE ) );
		$this->assertTrue( $tool['permission_callback']( $this->input() ) );
	}

	public function test_con_el_id_de_la_copia_el_permiso_se_pide_sobre_el_original(): void {
		$this->put_page();
		$this->put_copy_of_page();
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$tool                              = $this->tool();

		$this->assertNotTrue( $tool['permission_callback']( $this->input( 101 ) ) );

		$before = $this->snapshot();
		$result = $tool['execute_callback']( $this->input( 101 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_inserta_en_la_copia_de_trabajo_y_devuelve_el_id_del_nodo(): void {
		$this->put_page();
		$original = (string) get_post_meta( 100, '_elementor_data', true );

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 100, $result['draft_of'] );
		$this->assertCount( 1, $result['node_ids'] );
		$this->assertSame( $original, (string) get_post_meta( 100, '_elementor_data', true ) );
		$this->assertStringContainsString( '"component_id":{"$$type":"number","value":7}', (string) get_post_meta( (int) $result['id'], '_elementor_data', true ) );
		$this->assertSame( 'insert-component', end( \WP_Test_State::$audit )['tool'] ?? null );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function unusable(): array {
		return array(
			'inexistente' => array( 99 ),
			'archivado'   => array( 8 ),
			'en papelera' => array( 9 ),
		);
	}

	/**
	 * @dataProvider unusable
	 */
	public function test_un_componente_inexistente_archivado_o_en_papelera_no_escribe_nada( int $component ): void {
		$this->put_page();
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input( 100, $component ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_component_not_found', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_sin_pro_con_licencia_no_esta_disponible_y_no_escribe(): void {
		$this->put_page();
		$this->can_add = false;
		$before        = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_components_unavailable', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_con_el_editor_v4_apagado_no_esta_disponible_y_no_escribe(): void {
		$this->put_page();
		$this->guard->v4 = false;
		$before          = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_components_unavailable', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_id_de_pagina_inexistente_responde_error_sin_escribir(): void {
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input( 999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, $this->snapshot() );
	}
}
