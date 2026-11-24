<?php
/**
 * Herramienta set-divi-dynamic: registro, esquema, permiso doble sobre el original y orden de las comprobaciones.
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
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DiviSetDynamicToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/set-divi-dynamic';

	/**
	 * Veces que se consultó el registro de Divi (el catálogo sólo se usa tras el permiso).
	 *
	 * @var int
	 */
	private int $registry_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5DynamicEditorTest::page() ) );
	}

	private function catalog(): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => true,
			function (): array {
				++$this->registry_calls;

				return array( 'post_title' => array( 'type' => 'text' ) );
			},
			static fn(): array => array(),
			static fn(): string => 'page',
			static fn(): array => array()
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function tool( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_dynamic_tools_enabled' => true ), $settings ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, $this->catalog() )
		) )->register_abilities();

		return \WP_Test_State::$abilities[ self::TOOL ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'id'          => 100,
			'path'        => '0.0.0.0.0',
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5DynamicEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
			'field'       => 'title.innerContent',
			'source'      => array( 'name' => 'post_title' ),
		);
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	public function test_it_is_registered_as_a_closed_write_tool_and_documents_the_contract(): void {
		$tool = $this->tool();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'field', 'source' ), $tool['input_schema']['required'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'field', 'source', 'draft' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'object', 'null' ), $tool['input_schema']['properties']['source']['type'] );
		$this->assertSame( array( 'name' ), $tool['input_schema']['properties']['source']['required'] );
		$this->assertFalse( $tool['input_schema']['properties']['source']['additionalProperties'] );

		foreach ( array( 'nexo-mcp-list-divi-dynamic-sources', 'null', 'cadena vacía', 'nexo_mcp_divi_dynamic_denied', 'nexo_mcp_divi_dynamic_invalid', 'nexo_mcp_divi_dynamic_unavailable', 'nexo_mcp_divi_dynamic_not_set', 'nexo_mcp_divi_node_changed', 'nunca publica', 'mcp:divi_dynamic', 'edit-divi-module' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'] );
		}
	}

	public function test_the_other_page_tools_say_that_tokens_only_go_through_this_tool(): void {
		$this->tool();

		foreach ( array( 'edit-divi-module', 'create-divi-page', 'edit-divi-section', 'list-divi-dynamic-sources' ) as $name ) {
			$this->assertStringContainsString( 'set-divi-dynamic', \WP_Test_State::$abilities[ 'nexo-mcp/' . $name ]['description'], $name );
		}
	}

	public function test_permission_needs_builders_and_the_dynamic_switch_and_scope_and_edit_post_on_the_original(): void {
		$this->assertTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'divi_dynamic_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'builders_dynamic_enabled' => true, 'divi_dynamic_tools_enabled' => false ) )['permission_callback']( $this->input() ), 'el de Elementor no enciende el de Divi' );

		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = true;

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
	}

	public function test_a_copy_id_is_checked_against_its_original(): void {
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\WP_Test_State::$meta[101]  = array( WorkingCopy::META_DRAFT_OF => 100 );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $this->tool()['permission_callback']( array_merge( $this->input(), array( 'id' => 101 ) ) ) );

		\WP_Test_State::$denied_edit_posts = array( 101 );
		$this->assertTrue( $this->tool()['permission_callback']( array_merge( $this->input(), array( 'id' => 101 ) ) ) );
	}

	public function test_execute_rechecks_the_dynamic_permission_before_the_catalog_is_ever_consulted(): void {
		$before = $this->snapshot();
		$tool   = $this->tool( array( 'divi_dynamic_tools_enabled' => false ) );

		$result = $tool['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->registry_calls, 'sin permiso el catálogo dinámico no se consulta' );
		$this->assertSame( $before, $this->snapshot() );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$result                            = $this->tool()['execute_callback']( $this->input() );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->registry_calls );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_an_unknown_id_is_not_found_and_a_missing_source_is_invalid(): void {
		$result = $this->tool()['execute_callback']( array_merge( $this->input(), array( 'id' => 999 ) ) );
		$this->assertSame( 'nexo_mcp_builder_not_found', $result->get_error_code() );

		$input = $this->input();
		unset( $input['source'] );
		$before = $this->snapshot();
		$result = $this->tool()['execute_callback']( $input );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_dynamic_invalid', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_without_divi5_it_asks_for_divi5_goes_through_the_audit_and_writes_nothing(): void {
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'set-divi-dynamic', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}
}
