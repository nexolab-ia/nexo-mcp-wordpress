<?php
/**
 * set-divi-dynamic de punta a punta por las herramientas registradas (con Divi 5 activo) y las demás herramientas de
 * página de Divi 5, que siguen cerradas a los tokens.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
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

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviSetDynamicGatewayTest extends NexoTestCase {

	private const TOKEN = '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$';

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
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( bool $dynamic_on = true ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array( 'read_only' => false, 'divi_dynamic_tools_enabled' => $dynamic_on ) );
		$catalog                   = new DiviDynamicCatalog(
			static fn(): bool => true,
			static fn(): array => array( 'post_title' => array( 'type' => 'text' ), 'post_link_url' => array( 'type' => 'url' ), 'post_featured_image' => array( 'type' => 'image' ), 'loop_user_email' => array( 'type' => 'text' ), 'post_meta_key' => array( 'type' => 'any' ) ),
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
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, $catalog )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
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
	private function input( array $extra = array(), string $path = '0.0.0.0.0', ?string $content = null ): array {
		return array_merge(
			array(
				'id'          => 100,
				'path'        => $path,
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $content ?? Divi5DynamicEditorTest::page() ), $path )['fingerprint'] ?? '' ),
				'field'       => 'title.innerContent',
				'source'      => array( 'name' => 'post_title' ),
			),
			$extra
		);
	}

	public function test_it_assigns_a_token_to_the_working_copy_and_the_original_stays_untouched(): void {
		$tools = $this->tools();
		$input = $this->input();

		$this->assertTrue( $tools['nexo-mcp/set-divi-dynamic']['permission_callback']( $input ) );
		$result = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$this->assertSame( Divi5DynamicEditorTest::page(), $this->content( 100 ) );

		$block = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), '0.0.0.0.0' );
		$this->assertSame( '$variable({"type":"content","value":{"name":"post_title","settings":{"enable_html":"off"}}})$', $block['attrs']['title']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
		$this->assertSame( $result['token'], $block['attrs']['title']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'set-divi-dynamic', $last['tool'] ?? null );
	}

	public function test_a_source_outside_the_allowlist_and_a_wrong_category_are_rejected_without_writing(): void {
		$tools  = $this->tools();
		$before = $this->snapshot();

		foreach ( array( 'loop_user_email', 'post_meta_key', 'nope', 'post_link_url' ) as $name ) {
			$result = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $this->input( array( 'source' => array( 'name' => $name ) ) ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $result->get_error_code(), $name );
		}

		$result = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $this->input( array( 'source' => array( 'name' => 'post_featured_image' ) ) ) );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'nada escrito, ni copia' );
	}

	public function test_the_other_page_tools_stay_closed_to_tokens_with_the_dynamic_scope_on(): void {
		$tools  = $this->tools();
		$before = $this->snapshot();

		$edit = $tools['nexo-mcp/edit-divi-module']['execute_callback'](
			array(
				'id'          => 100,
				'path'        => '0.0.0.0.0',
				'fingerprint' => $this->input()['fingerprint'],
				'content'     => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => self::TOKEN ) ) ) ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $edit );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $edit->get_error_code() );

		$create = $tools['nexo-mcp/create-divi-page']['execute_callback'](
			array(
				'title'    => 'Nueva',
				'sections' => array(
					array(
						'type'     => 'divi/section',
						'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( array( 'type' => 'divi/heading', 'attrs' => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => self::TOKEN ) ) ) ) ) ) ) ) ) ),
					),
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $create );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $create->get_error_code() );

		$section = $tools['nexo-mcp/edit-divi-section']['execute_callback'](
			array(
				'id'          => 100,
				'operation'   => 'replace',
				'path'        => '0.0.0.0.0',
				'fingerprint' => $this->input()['fingerprint'],
				'sections'    => array( array( 'type' => 'divi/heading', 'attrs' => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => self::TOKEN ) ) ) ) ) ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $section );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $section->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'ninguna escribió' );
	}

	public function test_no_page_tool_can_leave_a_loop_token_without_its_loop(): void {
		// Los `loop_*` y los bucles sólo los escribe el conector dentro del mismo subárbol; las demás herramientas ni los escriben
		// (create-divi-page, edit-divi-section replace/insert, edit-divi-module) ni mueven nodos: no hay forma de dejar un token huérfano.
		$tools  = $this->tools();
		$before = $this->snapshot();
		$token  = '$variable({"type":"content","value":{"name":"loop_post_title","settings":{"enable_html":"off"}}})$';
		$title  = array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => $token ) ) ) );
		$heading = array( 'type' => 'divi/heading', 'attrs' => $title );
		$loop    = array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => array( 'enable' => 'on', 'loopId' => 'loop-nxabcd1234' ) ) ) ) ) );

		$results = array(
			'create-divi-page'   => $tools['nexo-mcp/create-divi-page']['execute_callback']( array( 'title' => 'N', 'sections' => array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $heading ) ) ) ) ) ) ) ) ),
			'replace + token'    => $tools['nexo-mcp/edit-divi-section']['execute_callback']( array( 'id' => 100, 'operation' => 'replace', 'path' => '0.0.0.0.0', 'fingerprint' => $this->input()['fingerprint'], 'sections' => array( $heading ) ) ),
			'insert + token'     => $tools['nexo-mcp/edit-divi-section']['execute_callback']( array( 'id' => 100, 'operation' => 'insert', 'position' => 'after', 'path' => '0.0.0.0.0', 'fingerprint' => $this->input()['fingerprint'], 'sections' => array( $heading ) ) ),
			'module + token'     => $tools['nexo-mcp/edit-divi-module']['execute_callback']( array( 'id' => 100, 'path' => '0.0.0.0.0', 'fingerprint' => $this->input()['fingerprint'], 'content' => $title ) ),
			'module + own loop'  => $tools['nexo-mcp/edit-divi-module']['execute_callback']( array( 'id' => 100, 'path' => '0.0.0.0', 'fingerprint' => $this->input()['fingerprint'], 'design' => $loop ) ),
		);

		foreach ( $results as $name => $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
		}

		foreach ( array( 'create-divi-page', 'replace + token', 'insert + token', 'module + token' ) as $name ) {
			$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $results[ $name ]->get_error_code(), $name );
		}

		$this->assertSame( $before, $this->snapshot(), 'ninguna escribió' );
	}

	public function test_existing_tokens_survive_an_edit_divi_module_of_the_same_module_as_in_1_9_1(): void {
		$tools = $this->tools();
		$set   = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $this->input( array( 'path' => '0.0.0.0.1', 'field' => 'button.innerContent.text' ), '0.0.0.0.1' ) );
		$this->assertIsArray( $set );

		$edit = $tools['nexo-mcp/edit-divi-module']['execute_callback'](
			array(
				'id'          => $set['id'],
				'path'        => '0.0.0.0.1',
				'fingerprint' => $set['fingerprint'],
				'content'     => array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'linkTarget' => 'on' ) ) ) ) ),
			)
		);
		$this->assertIsArray( $edit );

		$value = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $edit['id'] ) ), '0.0.0.0.1' )['attrs']['button']['innerContent']['desktop']['value']; // @phpstan-ignore-line
		$this->assertStringContainsString( '"name":"post_title"', $value['text'] );
		$this->assertStringContainsString( '"name":"post_link_url"', $value['linkUrl'] );
		$this->assertSame( 'on', $value['linkTarget'] );
	}

	public function test_a_null_source_clears_the_token_with_the_same_permission(): void {
		$tools = $this->tools();
		$set   = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $this->input() );
		$this->assertIsArray( $set );

		$removed = $tools['nexo-mcp/set-divi-dynamic']['execute_callback']( $this->input( array( 'id' => $set['id'], 'fingerprint' => $set['fingerprint'], 'source' => null ) ) );
		$this->assertIsArray( $removed );
		$this->assertTrue( $removed['removed'] );
		$this->assertSame( '', Divi5Tree::locate_block( parse_blocks( $this->content( (int) $removed['id'] ) ), '0.0.0.0.0' )['attrs']['title']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line

		$off = $this->tools( false );
		$this->assertNotTrue( $off['nexo-mcp/set-divi-dynamic']['permission_callback']( $this->input( array( 'source' => null ) ) ), 'quitar también exige el scope' );
	}
}
