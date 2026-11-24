<?php
/**
 * Herramientas get-divi-design-system y undo-divi-design-change: registro, permisos y respuestas.
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
use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class DiviDesignToolsTest extends NexoTestCase {

	private const GET  = 'nexo-mcp/get-divi-design-system';
	private const UNDO = 'nexo-mcp/undo-divi-design-change';

	private bool $active = true;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	private int $flushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->active  = true;
		$this->flushes = 0;
		$this->colors  = array( 'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ) );
	}

	private function store(): DiviDesignStore {
		return new DiviDesignStore(
			fn(): bool => $this->active,
			fn(): array => $this->colors,
			function ( array $all ): void {
				$this->colors = $all;
			},
			fn(): array => array(),
			fn( array $all ) => null,
			fn(): array => array( 'accent_color' => '#2ea3f2' ),
			function ( string $key, $value ): void {},
			function (): void {
				++$this->flushes;
			},
			function ( string $tool, string $status, $params, $result ): void {},
			fn( callable $work ) => $work()
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_design_tools_enabled' => true ), $settings ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, $this->store() )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	public function test_son_herramientas_de_la_allowlist_con_esquema_vacio(): void {
		$all = $this->abilities();

		$this->assertContains( self::GET, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertContains( self::UNDO, ExtendedAbilityRegistrar::ABILITIES );

		foreach ( array( self::GET, self::UNDO ) as $name ) {
			$this->assertFalse( $all[ $name ]['input_schema']['additionalProperties'], $name );
		}

		$this->assertEquals( new \stdClass(), $all[ self::GET ]['input_schema']['properties'] );
		$this->assertSame( array( 'force', 'area' ), array_keys( $all[ self::UNDO ]['input_schema']['properties'] ) );

		$this->assertTrue( $all[ self::GET ]['meta']['annotations']['readonly'] );
		$this->assertFalse( $all[ self::UNDO ]['meta']['annotations']['readonly'] );
		$this->assertStringContainsString( 'nexo_mcp_divi5_required', $all[ self::GET ]['description'] );
		foreach ( array( 'nexo_mcp_divi_design_nothing_to_undo', 'nexo_mcp_divi5_required', 'mcp:divi_design' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::UNDO ]['description'], $needle );
		}
	}

	public function test_leer_usa_el_permiso_de_lectura_de_constructores(): void {
		$this->assertTrue( $this->abilities()[ self::GET ]['permission_callback']( array() ) );
		// Leer no pide el interruptor de Divi.
		$this->assertTrue( $this->abilities( array( 'divi_design_tools_enabled' => false ) )[ self::GET ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'builders_tools_enabled' => false ) )[ self::GET ]['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->abilities()[ self::GET ]['permission_callback']( array() ) );
	}

	public function test_deshacer_pide_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		$this->assertTrue( $this->abilities()[ self::UNDO ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'divi_design_tools_enabled' => false ) )[ self::UNDO ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'read_only' => true ) )[ self::UNDO ]['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::DIVI_DESIGN ] = false;
		$this->assertNotTrue( $this->abilities()[ self::UNDO ]['permission_callback']( array() ) );
	}

	public function test_un_token_sin_el_scope_o_con_solo_el_scope_nuevo_no_deshace_ni_lee(): void {
		$all = $this->abilities();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:builders', 'mcp:design_system' ) );
		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:divi_design' ) );
		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:divi_design' ) );
		$this->assertNotTrue( $all[ self::GET ]['permission_callback']( array() ), 'el scope nuevo no concede lectura de constructores' );
		$this->assertNotTrue( $all['nexo-mcp/undo-design-system-change']['permission_callback']( array() ) );
	}

	public function test_leer_devuelve_el_sistema_de_diseno(): void {
		$result = $this->abilities()[ self::GET ]['execute_callback']( array() );

		$this->assertSame( 'gcid-aaaaaaaaaa', $result['colors'][0]['id'] );
		$this->assertSame( '#2ea3f2', $result['customizer_colors']['accent'] );
		$this->assertArrayHasKey( 'typography', $result );
		$this->assertArrayHasKey( 'variables', $result );
	}

	public function test_sin_divi_5_ambas_responden_el_error_y_no_escriben(): void {
		$this->active = false;
		$all          = $this->abilities();
		$colors       = $this->colors;

		foreach ( array( self::GET, self::UNDO ) as $name ) {
			$result = $all[ $name ]['execute_callback']( array() );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code(), $name );
		}

		$this->assertSame( $colors, $this->colors );
		$this->assertSame( 0, $this->flushes );
		$this->assertSame( array(), get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}

	public function test_deshacer_sin_historial_no_escribe(): void {
		$result = $this->abilities()[ self::UNDO ]['execute_callback']( array() );
		$colors = $this->colors;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_undo', $result->get_error_code() );
		$this->assertSame( $colors, $this->colors );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_deshacer_restaura_el_ultimo_cambio(): void {
		$before = $this->colors;
		$new    = $before;
		$new['gcid-aaaaaaaaaa']['color'] = '#ff0000';
		$this->assertIsArray( $this->store()->write_colors( $new, 'set-divi-global-color' ) );

		$result = $this->abilities()[ self::UNDO ]['execute_callback']( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'colors', $result['area'] );
		$this->assertSame( $before, $this->colors );
	}
}
