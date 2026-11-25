<?php
/**
 * Herramienta list-divi-dynamic-sources: registro, permisos de lectura de constructores, filtro por página y errores.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class DiviDynamicToolsTest extends NexoTestCase {

	private const LIST = 'nexo-mcp/list-divi-dynamic-sources';

	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		\WP_Test_State::$posts[200] = new \WP_Post( array( 'ID' => 200, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Taza' ) );
	}

	private function catalog( bool $active = true ): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => $active,
			static function ( int $post_id ): array {
				$names = array( 'post_title', 'post_link_url', 'site_logo', 'post_meta_key', 'loop_user_email' );

				if ( 200 === $post_id ) {
					$names = array_merge( $names, array( 'product_price' ) );
				}

				$types = array( 'post_link_url' => 'url', 'site_logo' => 'image' );

				return array_combine( $names, array_map( static fn( string $n ): array => array( 'type' => $types[ $n ] ?? 'text' ), $names ) );
			},
			static fn(): array => array( array( 'name' => 'nexo_prueba_texto', 'label' => 'Texto', 'type' => 'text', 'group' => 'G' ) ),
			static fn( int $id ): string => 200 === $id ? 'product' : 'page',
			static fn(): array => array()
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array(), bool $active = true ): array {
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
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, $this->catalog( $active ) )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	public function test_the_tool_is_registered_read_only_with_an_optional_post_id_and_documents_its_errors(): void {
		$all = $this->abilities();

		$this->assertContains( self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertArrayHasKey( self::LIST, $all );

		$schema = $all[ self::LIST ]['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'post_id' ), array_keys( $schema['properties'] ) );
		$this->assertSame( 'integer', $schema['properties']['post_id']['type'] );
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertTrue( $all[ self::LIST ]['meta']['annotations']['readonly'] );

		foreach ( array( 'nexo_mcp_divi_dynamic_unavailable', 'category', 'settings', 'ACF', 'WooCommerce', 'enable_html', 'sin meta personalizado' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::LIST ]['description'], $needle );
		}
	}

	public function test_it_needs_only_the_builders_read_permission_not_the_dynamic_switch(): void {
		$this->assertTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'divi_dynamic_tools_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'builders_tools_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );

		\WP_Test_State::$caps['edit_posts'] = false;
		$this->assertNotTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
	}

	public function test_with_a_post_id_it_also_needs_edit_post_on_that_post(): void {
		$all = $this->abilities();

		$this->assertTrue( $all[ self::LIST ]['permission_callback']( array( 'post_id' => 100 ) ) );

		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $all[ self::LIST ]['permission_callback']( array( 'post_id' => 100 ) ) );
		$this->assertTrue( $all[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $all[ self::LIST ]['permission_callback']( array( 'post_id' => 200 ) ) );
	}

	public function test_without_a_post_it_lists_the_closed_catalog_with_the_field_targets(): void {
		$result = $this->abilities()[ self::LIST ]['execute_callback']( array() );

		$this->assertArrayNotHasKey( 'post_id', $result );
		$names = array_column( $result['sources'], 'name' );

		$this->assertContains( 'post_title', $names );
		$this->assertContains( 'product_price', $names );
		$this->assertContains( 'custom_meta_nexo_prueba_texto', $names );
		$this->assertNotContains( 'post_meta_key', $names );
		$this->assertNotContains( 'loop_user_email', $names );
		$this->assertContains( 'title.innerContent', $result['targets']['divi/heading']['text'] );
		$this->assertSame( array(), array_values( array_filter( $result['sources'], static fn( array $s ): bool => ! isset( $s['name'], $s['label'], $s['category'], $s['settings'] ) ) ) );
	}

	public function test_with_a_post_id_the_list_is_filtered_to_what_applies(): void {
		$all = $this->abilities();

		$page = $all[ self::LIST ]['execute_callback']( array( 'post_id' => 100 ) );
		$this->assertSame( 100, $page['post_id'] );
		$this->assertSame( 'page', $page['post_type'] );
		$this->assertSame( array( 'post_title', 'post_link_url', 'site_logo', 'custom_meta_nexo_prueba_texto' ), array_column( $page['sources'], 'name' ) );

		$product = $all[ self::LIST ]['execute_callback']( array( 'post_id' => 200 ) );
		$this->assertContains( 'product_price', array_column( $product['sources'], 'name' ) );
	}

	public function test_a_missing_post_and_a_post_id_of_zero_are_errors(): void {
		$all = $this->abilities();

		$this->assertSame( 'nexo_mcp_builder_not_found', $all[ self::LIST ]['execute_callback']( array( 'post_id' => 999 ) )->get_error_code() );
		$this->assertSame( 'nexo_mcp_builder_invalid_id', $all[ self::LIST ]['execute_callback']( array( 'post_id' => 0 ) )->get_error_code() );
	}

	public function test_without_divi_5_it_answers_unavailable(): void {
		$all = $this->abilities( array(), false );

		$this->assertSame( 'nexo_mcp_divi_dynamic_unavailable', $all[ self::LIST ]['execute_callback']( array() )->get_error_code() );
		$this->assertSame( 'nexo_mcp_divi_dynamic_unavailable', $all[ self::LIST ]['execute_callback']( array( 'post_id' => 100 ) )->get_error_code() );
	}

	public function test_a_resolver_failure_in_listing_comes_back_as_the_tools_error_with_nothing_listed(): void {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array( 'read_only' => false ) );
		$build                     = function ( DiviDynamicCatalog $catalog ) use ( $settings ): array {
			( new ExtendedAbilityRegistrar(
				$settings,
				new PermissionPolicy( $settings ),
				new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
				new ContentAdminGateway( $settings ),
				new SeoGateway(),
				new AppearanceGateway(),
				new MaintenanceGateway(),
				new ProductGateway( $settings ),
				new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, $catalog )
			) )->register_abilities();

			return \WP_Test_State::$abilities;
		};
		$boom                      = static function (): array {
			throw new \RuntimeException( 'falla' );
		};

		$divi_down = $build( new DiviDynamicCatalog( static fn(): bool => true, $boom, static fn(): array => array(), static fn(): string => 'page', static fn(): array => array() ) );
		$result    = $divi_down[ self::LIST ]['execute_callback']( array( 'post_id' => 100 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_dynamic_unavailable', $result->get_error_code() );

		$acf_down = $build( new DiviDynamicCatalog( static fn(): bool => true, static fn(): array => array(), $boom, static fn(): string => 'page', static fn(): array => array() ) );
		$result   = $acf_down[ self::LIST ]['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_dynamic_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'ACF', $result->get_error_message() );
	}

	public function test_loop_sources_are_listed_apart_and_never_mixed_into_sources(): void {
		$result = $this->abilities()[ self::LIST ]['execute_callback']( array() );

		$this->assertIsArray( $result );
		$this->assertContains( 'loop_post_title', array_column( $result['loop_sources'], 'name' ) );
		$this->assertContains( 'loop_product_sku', array_column( $result['loop_sources'], 'name' ) );
		$this->assertNotContains( 'loop_user_email', array_column( $result['loop_sources'], 'name' ) );

		foreach ( $result['sources'] as $source ) {
			$this->assertStringStartsNotWith( 'loop_', $source['name'] );
		}
	}

	public function test_the_other_divi_read_tools_keep_their_registration(): void {
		$all = $this->abilities();

		foreach ( array( 'nexo-mcp/get-divi-presets', 'nexo-mcp/edit-divi-module', 'nexo-mcp/create-divi-page', 'nexo-mcp/edit-divi-section', 'nexo-mcp/list-dynamic-tags' ) as $tool ) {
			$this->assertArrayHasKey( $tool, $all, $tool );
		}

		$this->assertArrayHasKey( 'nexo-mcp/set-divi-dynamic', $all );
	}
}
