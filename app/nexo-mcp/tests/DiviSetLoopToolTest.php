<?php
/**
 * Herramienta set-divi-loop de punta a punta (con Divi 5 activo): registro, esquema, permiso del scope dinámico sobre el
 * original, orden de las comprobaciones y los tokens `loop_*` por set-divi-dynamic.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
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
use NexoDev\NexoMCP\Builders\DiviLoopSchema;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviSetLoopToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/set-divi-loop';

	/**
	 * Veces que se consultó el registro de tipos de contenido (el esquema sólo se usa tras el permiso).
	 *
	 * @var int
	 */
	private int $type_calls = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5DynamicEditorTest::page() ) );
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_dynamic_tools_enabled' => true ), $settings ) );
		$schema                    = new DiviLoopSchema(
			function ( string $type ): ?array {
				++$this->type_calls;

				return array( 'post' => array( 'public' => true, 'label' => 'Entradas' ), 'attachment' => array( 'public' => true, 'label' => 'Medios' ) )[ $type ] ?? null;
			},
			static fn(): array => array(),
			static fn(): ?string => null,
			static fn(): string => 'loop-nxtool0001'
		);
		$catalog                   = new DiviDynamicCatalog(
			static fn(): bool => true,
			static fn(): array => array( 'post_title' => array( 'type' => 'text' ), 'loop_post_title' => array( 'type' => 'text' ) ),
			static fn(): array => array(),
			static fn(): string => 'page',
			static fn(): array => array()
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
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, $catalog, $schema )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function tool( array $settings = array() ): array {
		return $this->tools( $settings )[ self::TOOL ];
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array(), string $path = '0.0.0.0', ?string $content = null ): array {
		return array_merge(
			array(
				'id'          => 100,
				'path'        => $path,
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $content ?? Divi5DynamicEditorTest::page() ), $path )['fingerprint'] ?? '' ),
				'loop'        => array( 'post_type' => 'post', 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc' ),
			),
			$extra
		);
	}

	public function test_it_is_registered_as_a_closed_write_tool_and_documents_the_contract(): void {
		$tool = $this->tool();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'loop' ), $tool['input_schema']['required'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'loop', 'draft' ), array_keys( $tool['input_schema']['properties'] ) );

		$loop = $tool['input_schema']['properties']['loop'];
		$this->assertSame( array( 'object', 'null' ), $loop['type'] );
		$this->assertFalse( $loop['additionalProperties'] );
		$this->assertSame( array( 'post_type', 'per_page', 'order_by', 'order' ), $loop['required'] );
		$this->assertSame( array( 'post_type', 'per_page', 'order_by', 'order', 'offset', 'exclude_current', 'terms' ), array_keys( $loop['properties'] ) );
		$this->assertSame( 1, $loop['properties']['per_page']['minimum'] );
		$this->assertSame( 24, $loop['properties']['per_page']['maximum'] );
		$this->assertSame( array( 'date', 'title', 'id' ), $loop['properties']['order_by']['enum'] );
		$this->assertSame( array( 'asc', 'desc' ), $loop['properties']['order']['enum'] );

		foreach ( array( 'nexo_mcp_divi_loop_invalid', 'nexo_mcp_divi_loop_nested', 'nexo_mcp_divi_loop_in_use', 'nexo_mcp_divi_loop_not_set', 'nexo_mcp_divi_module_denied', 'nexo_mcp_divi_node_changed', 'nunca publica', 'mcp:divi_dynamic', 'publicado', 'contraseña', '24', 'null', 'loop_post_title', 'set-divi-dynamic', 'metaQuery', 'anid' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_other_tools_point_at_it(): void {
		$tools = $this->tools();

		$this->assertStringContainsString( 'set-divi-loop', $tools['nexo-mcp/set-divi-dynamic']['description'] );
		$this->assertStringContainsString( 'set-divi-loop', $tools['nexo-mcp/list-divi-dynamic-sources']['description'] );
		$this->assertStringContainsString( 'set-divi-loop', $tools['nexo-mcp/edit-divi-module']['description'] );
	}

	public function test_permission_is_the_dynamic_scope_on_the_original(): void {
		$this->assertTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'divi_dynamic_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( array( 'builders_dynamic_enabled' => true, 'divi_dynamic_tools_enabled' => false ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
		\WP_Test_State::$caps[ Capabilities::DIVI_DYNAMIC ] = true;

		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\WP_Test_State::$meta[101]  = array( WorkingCopy::META_DRAFT_OF => 100 );
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input( array( 'id' => 101 ) ) ), 'la copia se comprueba contra su original' );
	}

	public function test_execute_rechecks_the_permission_before_the_loop_schema_is_ever_consulted(): void {
		$before = $this->snapshot();

		$result = $this->tool( array( 'divi_dynamic_tools_enabled' => false ) )['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->type_calls );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_missing_loop_key_is_invalid_and_an_unknown_id_is_not_found(): void {
		$input = $this->input();
		unset( $input['loop'] );
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $input );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_loop_invalid', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'loop' ), $result->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertSame( 'nexo_mcp_builder_not_found', $this->tool()['execute_callback']( $this->input( array( 'id' => 999 ) ) )->get_error_code() );
	}

	public function test_it_enables_the_loop_in_the_working_copy_and_the_original_stays_untouched(): void {
		$tool   = $this->tool();
		$result = $tool['execute_callback']( $this->input() );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$this->assertSame( Divi5DynamicEditorTest::page(), $this->content( 100 ) );
		$block = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), '0.0.0.0' );
		$this->assertSame( 'loop-nxtool0001', $block['attrs']['module']['advanced']['loop']['desktop']['value']['loopId'] ); // @phpstan-ignore-line
		$this->assertSame( 'post_types', $block['attrs']['module']['advanced']['loop']['desktop']['value']['queryType'] ); // @phpstan-ignore-line
		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'set-divi-loop', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}

	public function test_the_s7_rejections_come_back_as_tool_errors_and_write_nothing(): void {
		$before = $this->snapshot();
		$tool   = $this->tool();

		foreach ( array( 'any', 'attachment' ) as $type ) {
			$result = $tool['execute_callback']( $this->input( array( 'loop' => array( 'post_type' => $type, 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc' ) ) ) );
			$this->assertSame( 'nexo_mcp_divi_loop_invalid', $result->get_error_code(), $type );
		}

		$result = $tool['execute_callback']( $this->input( array( 'loop' => array( 'post_type' => 'post', 'per_page' => 25, 'order_by' => 'date', 'order' => 'desc' ) ) ) );
		$this->assertSame( 'nexo_mcp_divi_loop_invalid', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_loop_then_a_loop_token_then_the_rest_through_the_registered_tools(): void {
		$tools = $this->tools();

		$loop = $tools[ self::TOOL ]['execute_callback']( $this->input() );
		$this->assertIsArray( $loop );
		$copy = (int) $loop['id'];

		$heading = '0.0.0.0.0';
		$token   = $tools['nexo-mcp/set-divi-dynamic']['execute_callback'](
			array(
				'id'          => $copy,
				'path'        => $heading,
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $this->content( $copy ) ), $heading )['fingerprint'] ?? '' ),
				'field'       => 'title.innerContent',
				'source'      => array( 'name' => 'loop_post_title' ),
			)
		);

		$this->assertIsArray( $token );
		$this->assertSame( '$variable({"type":"content","value":{"name":"loop_post_title","settings":{"enable_html":"off"}}})$', $token['token'] );

		// Con el token puesto, el bucle ya no se puede quitar.
		$column = '0.0.0.0';
		$remove = $tools[ self::TOOL ]['execute_callback']( array( 'id' => $copy, 'path' => $column, 'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $this->content( $copy ) ), $column )['fingerprint'] ?? '' ), 'loop' => null ) );
		$this->assertInstanceOf( \WP_Error::class, $remove );
		$this->assertSame( 'nexo_mcp_divi_loop_in_use', $remove->get_error_code() );
	}

	public function test_the_loop_token_is_denied_without_a_loop_through_the_registered_tool(): void {
		$result = $this->tools()['nexo-mcp/set-divi-dynamic']['execute_callback'](
			array(
				'id'          => 100,
				'path'        => '0.0.0.0.0',
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5DynamicEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
				'field'       => 'title.innerContent',
				'source'      => array( 'name' => 'loop_post_title' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $result->get_error_code() );
	}
}
