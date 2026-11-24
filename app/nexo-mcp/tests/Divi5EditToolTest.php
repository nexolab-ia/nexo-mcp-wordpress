<?php
/**
 * Herramienta edit-divi-module: registro, esquema, permisos, auditoría y cableado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class Divi5EditToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/edit-divi-module';

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function tool( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false ), $settings ) );

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

	private function put_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5ModuleEditorTest::page() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'id'          => 100,
			'path'        => '0.0.0.0.0',
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5ModuleEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
			'content'     => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ),
		);
	}

	public function test_it_is_registered_as_a_write_tool_with_a_closed_schema(): void {
		$tool = $this->tool();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint' ), $tool['input_schema']['required'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'content', 'design', 'preset', 'draft' ), array_keys( $tool['input_schema']['properties'] ) );
		foreach ( array( 'nexo_mcp_divi_node_changed', 'nexo_mcp_divi5_required', 'nunca publica' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'] );
		}
	}

	public function test_permission_is_the_builders_one_on_the_original(): void {
		$this->put_page();

		$this->assertTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps['edit_post'] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_without_edit_permission_on_the_page_nothing_is_written(): void {
		$this->put_page();
		\WP_Test_State::$caps['edit_post'] = false;
		$before                            = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}

	public function test_an_unknown_id_is_not_found(): void {
		$result = $this->tool()['execute_callback']( array_merge( $this->input(), array( 'id' => 999 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_not_found', $result->get_error_code() );
	}

	public function test_without_divi5_it_asks_for_divi5_goes_through_the_audit_and_writes_nothing(): void {
		$this->put_page();
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'edit-divi-module', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}
}
