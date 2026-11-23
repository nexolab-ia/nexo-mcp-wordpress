<?php
/**
 * Herramientas create-divi-page y edit-divi-section: registro, esquema, permisos y cableado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class Divi5PageToolsTest extends NexoTestCase {

	private const CREATE = 'nexo-mcp/create-divi-page';
	private const EDIT   = 'nexo-mcp/edit-divi-section';

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( array $settings = array() ): array {
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

		return \WP_Test_State::$abilities;
	}

	private function put_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function edit_input(): array {
		return array(
			'id'          => 100,
			'operation'   => 'replace',
			'path'        => '0.0',
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), '0.0' )['fingerprint'] ?? '' ),
			'sections'    => Divi5SectionEditorTestFixtures::sections(),
		);
	}

	public function test_both_are_registered_as_write_tools_with_closed_schemas(): void {
		$tools = $this->tools();

		foreach ( array( self::CREATE, self::EDIT ) as $name ) {
			$this->assertContains( $name, ExtendedAbilityRegistrar::ABILITIES );
			$this->assertFalse( $tools[ $name ]['meta']['annotations']['readonly'] );
			$this->assertFalse( $tools[ $name ]['input_schema']['additionalProperties'] );
			$this->assertStringContainsString( 'nexo_mcp_divi5_required', $tools[ $name ]['description'] );
			$this->assertStringContainsString( 'nunca publica', strtolower( $tools[ $name ]['description'] ) );
		}

		$this->assertSame( array( 'title', 'sections' ), array_keys( $tools[ self::CREATE ]['input_schema']['properties'] ) );
		$this->assertSame( array( 'title', 'sections' ), $tools[ self::CREATE ]['input_schema']['required'] );
		$this->assertSame( array( 'id', 'operation', 'path', 'fingerprint', 'position', 'sections', 'draft' ), array_keys( $tools[ self::EDIT ]['input_schema']['properties'] ) );
		$this->assertSame( array( 'id', 'operation', 'path', 'fingerprint' ), $tools[ self::EDIT ]['input_schema']['required'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_node_changed', $tools[ self::EDIT ]['description'] );
	}

	public function test_create_permission_is_the_one_of_create_elementor_page(): void {
		$this->assertTrue( $this->tools()[ self::CREATE ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tools( array( 'builders_tools_enabled' => false ) )[ self::CREATE ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tools( array( 'read_only' => true ) )[ self::CREATE ]['permission_callback']( array() ) );

		\WP_Test_State::$caps['edit_pages'] = false;
		$this->assertNotTrue( $this->tools()[ self::CREATE ]['permission_callback']( array() ) );
	}

	public function test_edit_permission_is_the_builders_one_on_the_original(): void {
		$this->put_page();

		$this->assertTrue( $this->tools()[ self::EDIT ]['permission_callback']( $this->edit_input() ) );
		$this->assertNotTrue( $this->tools( array( 'builders_tools_enabled' => false ) )[ self::EDIT ]['permission_callback']( $this->edit_input() ) );

		\WP_Test_State::$caps['edit_post'] = false;
		$this->assertNotTrue( $this->tools()[ self::EDIT ]['permission_callback']( $this->edit_input() ) );
	}

	public function test_create_without_divi5_asks_for_it_goes_through_the_audit_and_writes_nothing(): void {
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->tools()[ self::CREATE ]['execute_callback']( array( 'title' => 'x', 'sections' => Divi5SectionEditorTestFixtures::sections() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
		$this->assertSame( 'create-divi-page', end( \WP_Test_State::$audit )['tool'] ?? null );
	}

	public function test_edit_without_edit_permission_on_the_page_nothing_is_written(): void {
		$this->put_page();
		\WP_Test_State::$caps['edit_post'] = false;
		$before                            = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->tools()[ self::EDIT ]['execute_callback']( $this->edit_input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}

	public function test_edit_without_divi5_asks_for_it_goes_through_the_audit_and_writes_nothing(): void {
		$this->put_page();
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->tools()[ self::EDIT ]['execute_callback']( $this->edit_input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
		$this->assertSame( 'edit-divi-section', end( \WP_Test_State::$audit )['tool'] ?? null );
	}
}
