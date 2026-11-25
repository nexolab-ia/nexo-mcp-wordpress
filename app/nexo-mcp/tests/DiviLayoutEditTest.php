<?php
/**
 * Edición de layouts del Theme Builder con edit-divi-section y edit-divi-module (T5): módulos de layout
 * (`divi/post-content`, `divi/post-title`, `divi/menu`) sólo dentro de un layout, aviso `dropped_post_content` y
 * `warnings` al quitar el `divi/post-content` de un cuerpo, y borrado del CSS del Theme Builder al escribir el layout vivo.
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
use NexoDev\NexoMCP\Builders\Divi5Tree;
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
final class DiviLayoutEditTest extends NexoTestCase {

	private const SWITCH = 'divi_theme_builder_tools_enabled';

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		require_once __DIR__ . '/DiviLayoutWpStubs.php';
		require_once __DIR__ . '/DiviLayoutCssStubs.php';

		\WP_Layout_Stub_State::$css_calls   = array();
		\WP_Layout_Stub_State::$cache_calls = array();

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => self::page_with_menu() ) );
		\WP_Test_State::$posts[200] = new \WP_Post( array( 'ID' => 200, 'post_type' => 'et_body_layout', 'post_status' => 'publish', 'post_title' => 'Cuerpo', 'post_content' => self::body() ) );
		\WP_Test_State::$posts[201] = new \WP_Post( array( 'ID' => 201, 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Encabezado', 'post_content' => self::header() ) );
		\WP_Test_State::$posts[202] = new \WP_Post( array( 'ID' => 202, 'post_type' => 'et_body_layout', 'post_status' => 'publish', 'post_title' => 'Cuerpo con dos', 'post_content' => self::body( true ) ) );
	}

	/**
	 * Cuerpo: placeholder 0, sección 0.0, fila 0.0.0, columna 0.0.0.0, texto 0.0.0.0.0, divi/post-content 0.0.0.0.1
	 * (con `$twice`, otra sección 0.1 con otro divi/post-content en 0.1.0.0.0).
	 */
	private static function body( bool $twice = false ): string {
		$raw = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Antes</p>"}}}} /-->' . "\n"
			. '<!-- wp:divi/post-content /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. ( $twice ? '<!-- wp:divi/section {"builderVersion":"5.19.1"} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/post-content /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->' . "\n" : '' )
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	/**
	 * Encabezado: columna 0.0.0.0 con menú (0.0.0.0.0) y título (0.0.0.0.1).
	 */
	private static function header(): string {
		return self::wrap( '<!-- wp:divi/menu {"menu":{"advanced":{"menuId":{"desktop":{"value":"34"}}}}} /-->' . "\n" . '<!-- wp:divi/post-title /-->' );
	}

	private static function page_with_menu(): string {
		return self::wrap( '<!-- wp:divi/menu {"menu":{"advanced":{"menuId":{"desktop":{"value":"34"}}}}} /-->' );
	}

	private static function wrap( string $modules ): string {
		$raw = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. $modules . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, self::SWITCH => true ), $settings ) );

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

		$short = array();

		foreach ( \WP_Test_State::$abilities as $name => $ability ) {
			$short[ substr( $name, strlen( 'nexo-mcp/' ) ) ] = $ability;
		}

		return $short;
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	private function fingerprint( int $id, string $path ): string {
		return (string) ( Divi5Tree::locate( Divi5Tree::build( \WP_Test_State::$posts[ $id ]->post_content ), $path )['fingerprint'] ?? '' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sections_with( array $module ): array {
		return array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $module ) ) ) ) ) ) );
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function edit( int $id, string $operation, string $path, array $extra = array() ): array {
		return array_merge( array( 'id' => $id, 'operation' => $operation, 'path' => $path, 'fingerprint' => $this->fingerprint( $id, $path ) ), $extra );
	}

	public function test_a_layout_accepts_post_content_post_title_and_menu_and_a_page_rejects_them(): void {
		foreach ( array( 'divi/post-content', 'divi/post-title', 'divi/menu' ) as $type ) {
			$this->setUp();
			$tools = $this->tools();
			$input = $this->edit( 201, 'insert', '0.0.0.0', array( 'position' => 'inside', 'sections' => array( array( 'type' => $type ) ) ) );

			$result = $tools['edit-divi-section']['execute_callback']( $input );

			$this->assertIsArray( $result, $type );
			$this->assertSame( 201, $result['draft_of'], $type );
			$this->assertStringContainsString( 'wp:' . $type, \WP_Test_State::$posts[ $result['id'] ]->post_content, $type );
			$this->assertContains( $type, array_column( $result['nodes'], 'type' ), $type );

			$before = $this->snapshot();
			$page   = $this->edit( 100, 'insert', '0.0.0.0', array( 'position' => 'inside', 'sections' => array( array( 'type' => $type ) ) ) );
			$denied = $tools['edit-divi-section']['execute_callback']( $page );

			$this->assertInstanceOf( \WP_Error::class, $denied, $type );
			$this->assertSame( 'nexo_mcp_divi_module_denied', $denied->get_error_code(), $type );
			$this->assertSame( $before, $this->snapshot(), "$type: la página no cambia" );
		}
	}

	public function test_a_menu_in_a_layout_is_edited_with_edit_divi_module_and_the_menu_id_is_validated(): void {
		$tools = $this->tools();
		$patch = static fn( string $id ): array => array( 'menu' => array( 'advanced' => array( 'menuId' => array( 'desktop' => array( 'value' => $id ) ) ) ) );

		$bad    = $this->snapshot();
		$denied = $tools['edit-divi-module']['execute_callback']( $this->edit( 201, 'x', '0.0.0.0.0', array( 'content' => $patch( '999' ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'nexo_mcp_divi_value_invalid', $denied->get_error_code() );
		$this->assertSame( array( 'path' => 'content.menu.advanced.menuId.desktop.value' ), $denied->get_error_data( 'nexo_mcp_divi_value_invalid' ) );
		$this->assertSame( $bad, $this->snapshot() );

		$ok = $tools['edit-divi-module']['execute_callback']( $this->edit( 201, 'x', '0.0.0.0.0', array( 'content' => $patch( '35' ) ) ) );

		$this->assertIsArray( $ok );
		$this->assertSame( 201, $ok['draft_of'] );
		$this->assertStringContainsString( '"menuId":{"desktop":{"value":"35"}}', \WP_Test_State::$posts[ $ok['id'] ]->post_content );
		$this->assertStringContainsString( '"menuId":{"desktop":{"value":"34"}}', \WP_Test_State::$posts[201]->post_content, 'el layout vivo no cambia' );
	}

	public function test_a_menu_in_a_page_is_still_not_an_editable_module(): void {
		$before = $this->snapshot();
		$result = $this->tools()['edit-divi-module']['execute_callback'](
			$this->edit( 100, 'x', '0.0.0.0.0', array( 'content' => array( 'menu' => array( 'advanced' => array( 'menuId' => array( 'desktop' => array( 'value' => '35' ) ) ) ) ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_module_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_replacing_the_section_with_the_only_post_content_of_a_body_reports_it_and_warns(): void {
		$result = $this->tools()['edit-divi-section']['execute_callback'](
			$this->edit( 200, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nuevo' ) ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( array( 'path' => '0.0.0.0.1', 'type' => 'divi/post-content' ) ), $result['dropped_post_content'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'divi/post-content', $result['warnings'][0] );
		$this->assertStringContainsString( 'REEMPLAZA', $result['warnings'][0] );
		$this->assertArrayNotHasKey( 'dropped_conditions', $result );
		$this->assertStringNotContainsString( 'divi/post-content', \WP_Test_State::$posts[ $result['id'] ]->post_content, 'se escribe igual, en la copia' );
		$this->assertStringContainsString( 'divi/post-content', \WP_Test_State::$posts[200]->post_content );
	}

	public function test_deleting_the_module_itself_reports_its_path(): void {
		$result = $this->tools()['edit-divi-section']['execute_callback']( $this->edit( 200, 'delete', '0.0.0.0.1' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( array( 'path' => '0.0.0.0.1', 'type' => 'divi/post-content' ) ), $result['dropped_post_content'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	public function test_no_body_warning_when_another_post_content_remains_or_the_layout_is_not_a_body(): void {
		$two = $this->tools()['edit-divi-section']['execute_callback'](
			$this->edit( 202, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nuevo' ) ) )
		);

		$this->assertIsArray( $two );
		$this->assertCount( 1, $two['dropped_post_content'] );
		$this->assertArrayNotHasKey( 'warnings', $two, 'queda otro divi/post-content' );

		\WP_Test_State::$posts[201]->post_content = self::wrap( '<!-- wp:divi/post-content /-->' );
		$header = $this->tools()['edit-divi-section']['execute_callback'](
			$this->edit( 201, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nuevo' ) ) )
		);

		$this->assertIsArray( $header );
		$this->assertCount( 1, $header['dropped_post_content'] );
		$this->assertArrayNotHasKey( 'warnings', $header, 'un encabezado no es un cuerpo' );
	}

	public function test_preserve_replacing_without_post_content_adds_no_new_keys(): void {
		$result = $this->tools()['edit-divi-section']['execute_callback'](
			$this->edit( 201, 'replace', '0.0.0.0.0', array( 'sections' => array() ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result, 'sections vacío sigue siendo un error de validación' );

		$ok = $this->tools()['edit-divi-section']['execute_callback'](
			$this->edit( 201, 'insert', '0.0.0.0', array( 'position' => 'inside', 'sections' => array( array( 'type' => 'divi/text' ) ) ) )
		);

		$this->assertIsArray( $ok );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'operation', 'nodes' ), array_keys( $ok ) );

		$page = $this->tools()['edit-divi-section']['execute_callback']( $this->edit( 100, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nuevo' ) ) ) );

		$this->assertIsArray( $page );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'operation', 'nodes' ), array_keys( $page ) );
	}

	public function test_publishing_a_layout_copy_clears_the_whole_static_css_and_the_page_cache_plugins_but_a_page_does_not(): void {
		$tools = $this->tools( array( 'publishing_enabled' => true ) );
		$edit  = $tools['edit-divi-section']['execute_callback']( $this->edit( 201, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nuevo' ) ) ) );
		$this->assertIsArray( $edit );
		$copy = (int) $edit['id'];

		$this->assertNotContains( array( 'all', 'all' ), \WP_Layout_Stub_State::$css_calls, 'escribir la copia no toca el CSS de todo el sitio' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
		$this->assertContains( array( $copy, 'all' ), \WP_Layout_Stub_State::$css_calls, 'sí el de la propia copia (como siempre)' );

		\WP_Layout_Stub_State::$css_calls = array();
		$merged                           = $tools['set-content-status']['execute_callback']( array( 'id' => $copy, 'status' => 'publish' ) );

		$this->assertIsArray( $merged );
		$this->assertContains( array( 201, 'all' ), \WP_Layout_Stub_State::$css_calls );
		$this->assertContains( array( 'all', 'all' ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );

		// Una página: sólo lo suyo, como antes.
		\WP_Layout_Stub_State::$css_calls   = array();
		\WP_Layout_Stub_State::$cache_calls = array();
		$page                               = $tools['edit-divi-section']['execute_callback']( $this->edit( 100, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Pagina' ) ) ) );
		$this->assertIsArray( $page );
		$page_merge = $tools['set-content-status']['execute_callback']( array( 'id' => $page['id'], 'status' => 'publish' ) );

		$this->assertIsArray( $page_merge );
		$this->assertNotContains( array( 'all', 'all' ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
		$this->assertContains( array( 100, 'all' ), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_editing_a_live_layout_in_place_clears_the_whole_static_css(): void {
		$tools = $this->tools( array( 'working_copy_default' => false ) );

		$result = $tools['edit-divi-section']['execute_callback']( $this->edit( 201, 'replace', '0.0', array( 'sections' => Divi5SectionEditorTestFixtures::sections( 'Directo' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 201, $result['id'] );
		$this->assertContains( array( 'all', 'all' ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );
		$this->assertSame( 0, WorkingCopy::copy_of( \WP_Test_State::$posts[201] ) );
	}
}
