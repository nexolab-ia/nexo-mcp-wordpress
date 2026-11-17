<?php
/**
 * Herramienta get-design-system: lo comprobable sin Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\ElementorDesignSystem;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class ElementorDesignSystemTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/get-design-system';

	/**
	 * @return array<string, mixed>
	 */
	private function ability( array $settings = array() ): array {
		$settings = $this->settings( $settings );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		) )->register_abilities();

		return \WP_Test_State::$abilities[ self::TOOL ];
	}

	public function test_without_elementor_it_answers_a_clear_error(): void {
		$result = ( new ElementorDesignSystem() )->read();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_missing', $result->get_error_code() );
		$this->assertStringContainsString( 'Elementor no está activo', $result->get_error_message() );
	}

	public function test_the_tool_is_registered_as_a_read_tool_without_input(): void {
		$ability = $this->ability();

		$this->assertTrue( $ability['meta']['annotations']['readonly'] );
		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertInstanceOf( \stdClass::class, $ability['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'required', $ability['input_schema'] );

		$result = $ability['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_missing', $result->get_error_code() );
	}

	public function test_permission_follows_the_builders_module_like_get_page_structure(): void {
		$structure = static fn( array $registry ) => $registry['nexo-mcp/get-page-structure']['permission_callback'];

		$ability = $this->ability();
		$this->assertTrue( $ability['permission_callback']( array() ) );

		// Módulo apagado: se deniega igual que get-page-structure.
		\WP_Test_State::$abilities = array();
		$off                       = $this->ability( array( 'builders_tools_enabled' => false ) );
		$this->assertNotTrue( $off['permission_callback']( array() ) );
		$this->assertNotTrue( $structure( \WP_Test_State::$abilities )( array( 'id' => 5 ) ) );

		// Sin la capacidad de constructores (alcance OAuth) también se deniega.
		\WP_Test_State::$abilities = array();
		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$no_scope = $this->ability();
		$this->assertNotTrue( $no_scope['permission_callback']( array() ) );
	}
}
