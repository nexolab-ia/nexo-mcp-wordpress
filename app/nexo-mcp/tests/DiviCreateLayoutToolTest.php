<?php
/**
 * create-divi-layout (T5): registro, esquema, permiso del Theme Builder en dos capas, validación del árbol de layout,
 * creación `publish` y sin asignar, avisos, borrado del CSS del Theme Builder y deshacer lo creado si el guardado falla.
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
use NexoDev\NexoMCP\Builders\Divi5LayoutCreator;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
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
final class DiviCreateLayoutToolTest extends NexoTestCase {

	private const TOOL   = 'nexo-mcp/create-divi-layout';
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

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function tool( array $settings = array() ): array {
		return $this->tools( $settings )[ self::TOOL ];
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( string $text = 'Hola', array $module = array() ): array {
		$module = array() === $module ? array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . $text . '</p>' ) ) ) ) ) : $module;

		return array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $module ) ) ) ) ) ) );
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array() ): array {
		return array_merge( array( 'type' => 'body', 'title' => 'Cuerpo del sitio', 'sections' => $this->sections( 'Hola', array( 'type' => 'divi/post-content' ) ) ), $extra );
	}

	public function test_it_is_registered_as_a_write_tool_with_a_closed_schema_and_warns_about_the_whole_site(): void {
		$tool = $this->tool();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'type', 'title', 'sections' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'type', 'title', 'sections' ), $tool['input_schema']['required'] );
		$this->assertSame( array( 'header', 'body', 'footer' ), $tool['input_schema']['properties']['type']['enum'] );
		$this->assertSame( 200, $tool['input_schema']['properties']['title']['maxLength'] );

		foreach ( array( 'nexo_mcp_divi_layout_denied', 'nexo_mcp_divi_layout_type_invalid', 'divi/post-content', 'divi/post-title', 'divi/menu', 'publish', 'sin asignar', 'todo el sitio', '7 días', 'edit_posts', 'mcp:divi_theme_builder', 'nexo_mcp_divi5_required' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_permission_is_the_theme_builder_one_without_the_builders_module(): void {
		$this->assertTrue( $this->tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( array() ) );

		$this->assertNotTrue( $this->tool( array( self::SWITCH => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tool( array( 'read_only' => true ) )['permission_callback']( array() ) );

		foreach ( array( 'edit_theme_options', 'edit_others_posts', 'nexo_mcp_divi_theme_builder' ) as $cap ) {
			$this->setUp();
			\WP_Test_State::$caps[ $cap ] = false;
			$this->assertNotTrue( $this->tool()['permission_callback']( array() ), $cap );
		}

		$this->setUp();
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertNotTrue( $this->tool( array( 'enforce_connection_scopes' => true ) )['permission_callback']( array() ), 'sin el scope del Theme Builder en el token' );
	}

	public function test_without_the_permission_nothing_is_created_and_the_error_is_the_layout_one(): void {
		$before = $this->snapshot();

		$result = $this->tool( array( self::SWITCH => false ) )['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_it_creates_a_published_unassigned_body_with_marked_metas_and_returns_paths_and_fingerprints(): void {
		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $result );
		$id = (int) $result['id'];
		$this->assertSame( array( 'id', 'type', 'post_type', 'status', 'assigned', 'nodes', 'warnings' ), array_keys( $result ) );
		$this->assertSame( 'body', $result['type'] );
		$this->assertSame( 'et_body_layout', $result['post_type'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertFalse( $result['assigned'] );

		$post = \WP_Test_State::$posts[ $id ];
		$this->assertSame( 'et_body_layout', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Cuerpo del sitio', $post->post_title );
		$this->assertSame( 'on', get_post_meta( $id, '_et_pb_use_builder', true ) );
		$this->assertSame( 'on', get_post_meta( $id, '_et_pb_use_divi_5', true ) );
		$this->assertStringStartsWith( '<!-- wp:divi/placeholder -->', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:divi/post-content {"builderVersion":"5.19.1"} /-->', $post->post_content );
		$this->assertSame( array( false ), \WP_Test_State::$kses_during_insert, 'sin kses sólo durante el insert' );

		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.0' ), array_column( $result['nodes'], 'path' ), 'placeholder raíz, sección, fila, columna y módulo' );
		$this->assertSame( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/post-content' ), array_column( $result['nodes'], 'type' ) );

		$tree = Divi5Tree::build( $post->post_content );

		foreach ( $result['nodes'] as $node ) {
			$this->assertNotSame( '', $node['fingerprint'] );
			$this->assertSame( Divi5Tree::locate( $tree, $node['path'] )['fingerprint'] ?? null, $node['fingerprint'], $node['path'] );
		}
	}

	public function test_the_unassigned_warning_is_always_there_and_a_body_without_post_content_is_created_but_warned(): void {
		$with = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $with );
		$this->assertCount( 1, $with['warnings'], 'sólo el de sin asignar' );
		$this->assertStringContainsString( '7 días', $with['warnings'][0] );

		$without = $this->tool()['execute_callback']( $this->input( array( 'sections' => $this->sections() ) ) );

		$this->assertIsArray( $without );
		$this->assertSame( 'publish', $without['status'] );
		$this->assertCount( 2, $without['warnings'] );
		$this->assertStringContainsString( 'divi/post-content', $without['warnings'][1] );
		$this->assertStringContainsString( 'REEMPLAZA', $without['warnings'][1] );

		$header = $this->tool()['execute_callback']( $this->input( array( 'type' => 'header', 'title' => 'Encabezado', 'sections' => $this->sections() ) ) );

		$this->assertIsArray( $header );
		$this->assertSame( 'et_header_layout', $header['post_type'] );
		$this->assertCount( 1, $header['warnings'], 'un encabezado no necesita divi/post-content' );

		$footer = $this->tool()['execute_callback']( $this->input( array( 'type' => 'footer', 'title' => 'Pie', 'sections' => $this->sections() ) ) );

		$this->assertIsArray( $footer );
		$this->assertSame( 'et_footer_layout', $footer['post_type'] );
	}

	public function test_a_header_takes_a_menu_and_a_post_title_and_checks_the_menu_id(): void {
		$sections = $this->sections( 'x', array( 'type' => 'divi/menu', 'attrs' => array( 'menu' => array( 'advanced' => array( 'menuId' => array( 'desktop' => array( 'value' => '34' ) ) ) ) ) ) );

		$ok = $this->tool()['execute_callback']( $this->input( array( 'type' => 'header', 'sections' => $sections ) ) );
		$this->assertIsArray( $ok );
		$this->assertStringContainsString( '"menuId":{"desktop":{"value":"34"}}', \WP_Test_State::$posts[ (int) $ok['id'] ]->post_content );

		$before                                                                                             = $this->snapshot();
		$sections[0]['children'][0]['children'][0]['children'][0]['attrs']['menu']['advanced']['menuId']['desktop']['value'] = '999';
		$bad                                                                                                = $this->tool()['execute_callback']( $this->input( array( 'type' => 'header', 'sections' => $sections ) ) );

		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_divi_value_invalid', $bad->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		$title = $this->tool()['execute_callback']( $this->input( array( 'type' => 'header', 'sections' => $this->sections( 'x', array( 'type' => 'divi/post-title' ) ) ) ) );
		$this->assertIsArray( $title );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		$module = static fn( string $type ): array => array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( array( 'type' => $type ) ) ) ) ) ) ) );

		return array(
			'tipo desconocido'    => array( array( 'type' => 'archive' ), 'nexo_mcp_divi_layout_type_invalid' ),
			'sin tipo'            => array( array( 'type' => '' ), 'nexo_mcp_divi_layout_type_invalid' ),
			'tipo no es texto'    => array( array( 'type' => array( 'body' ) ), 'nexo_mcp_divi_layout_type_invalid' ),
			'sin título'          => array( array( 'title' => '' ), 'nexo_mcp_divi_title_required' ),
			'título en blanco'    => array( array( 'title' => '   ' ), 'nexo_mcp_divi_title_required' ),
			'sin secciones'       => array( array( 'sections' => array() ), 'nexo_mcp_divi_tree_invalid' ),
			'módulo de código'    => array( array( 'sections' => $module( 'divi/code' ) ), 'nexo_mcp_divi_module_denied' ),
			'ancho completo'      => array( array( 'sections' => $module( 'divi/fullwidth-menu' ) ), 'nexo_mcp_divi_module_denied' ),
			'no es una sección'   => array( array( 'sections' => array( array( 'type' => 'divi/post-content' ) ) ), 'nexo_mcp_divi_nesting_invalid' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @param array<string, mixed> $extra Parámetros inválidos.
	 */
	public function test_invalid_input_creates_nothing_with_the_exact_error( array $extra, string $code ): void {
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input( $extra ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( 'create-divi-layout', end( \WP_Test_State::$audit )['tool'] ?? null );
	}

	public function test_the_permission_is_checked_before_the_input(): void {
		$result = $this->tool( array( self::SWITCH => false ) )['execute_callback']( $this->input( array( 'type' => 'archive' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code() );
	}

	public function test_without_divi5_it_asks_for_it_and_creates_nothing(): void {
		$creator = new Divi5LayoutCreator( new DiviTreeValidator(), static fn(): bool => true, static fn(): bool => false );
		$before  = $this->snapshot();

		$result = $creator->create( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_creating_an_unassigned_layout_clears_only_its_own_css_and_not_the_whole_site(): void {
		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $result );
		$this->assertContains( array( (int) $result['id'], 'all' ), \WP_Layout_Stub_State::$css_calls );
		$this->assertNotContains( array( 'all', 'all' ), \WP_Layout_Stub_State::$css_calls, 'un layout sin asignar no se ve en ninguna página' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_it_inserts_through_the_divi_function_when_it_exists_and_returns_its_id(): void {
		if ( ! function_exists( 'et_theme_builder_insert_layout' ) ) {
			// Doble de la función de Divi (TB/theme-builder.php:1677): inserta y devuelve el id o un WP_Error; anota lo que recibió.
			eval( 'function et_theme_builder_insert_layout( $options ) { $GLOBALS["nexo_tb_insert"][] = $options; return wp_insert_post( array_merge( array( "post_status" => "publish", "post_title" => "Theme Builder Layout" ), $options ), true ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$GLOBALS['nexo_tb_insert'] = array();

		$result = $this->tool()['execute_callback']( $this->input( array( 'type' => 'footer' ) ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $GLOBALS['nexo_tb_insert'] );
		$this->assertSame( 'et_footer_layout', $GLOBALS['nexo_tb_insert'][0]['post_type'] );
		$this->assertSame( 'publish', $GLOBALS['nexo_tb_insert'][0]['post_status'] );
		$this->assertSame( \WP_Test_State::$posts[ (int) $result['id'] ]->post_content, $GLOBALS['nexo_tb_insert'][0]['post_content'] );
		$this->assertNotEmpty( \WP_Test_State::$slashed, 'el contenido pasa por wp_slash antes de insertar' );
	}

	public function test_a_failed_insert_returns_its_error_and_creates_nothing(): void {
		\WP_Test_State::$fail_insert_post = true;
		$before                           = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'db_insert_error', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_if_wordpress_stores_other_content_the_layout_is_deleted_and_the_error_says_so(): void {
		\WP_Test_State::$mangle_insert = true;
		$before                        = $this->snapshot();

		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'se eliminó', $result->get_error_message() );
		$this->assertSame( $before, $this->snapshot(), 'no queda ningún layout publicado a medias' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_if_the_rollback_cannot_delete_the_error_names_the_published_layout(): void {
		\WP_Test_State::$mangle_insert = true;
		$creator                       = new Divi5LayoutCreator( new DiviTreeValidator(), static fn(): bool => true, null, null, null, null, static fn( int $id ) => false );

		$result = $creator->create( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$ids = array_keys( \WP_Test_State::$posts );
		$this->assertCount( 1, $ids );
		$this->assertStringContainsString( (string) $ids[0], $result->get_error_message(), 'dice qué layout quedó' );
		$this->assertStringContainsString( 'publicado', $result->get_error_message() );
	}

	public function test_if_the_inserter_throws_after_creating_the_post_it_is_found_and_deleted(): void {
		$seen    = array();
		$creator = new Divi5LayoutCreator(
			new DiviTreeValidator(),
			static fn(): bool => true,
			null,
			null,
			static function ( array $data ) use ( &$seen ) {
				$seen = $data;
				$id   = wp_insert_post( $data, true );
				foreach ( (array) ( $data['meta_input'] ?? array() ) as $key => $value ) {
					update_post_meta( (int) $id, $key, $value );
				}

				throw new \RuntimeException( 'falló después de crear' );
			},
			null,
			null,
			null,
			static function ( string $key, string $value ): array {
				$ids = array();
				foreach ( \WP_Test_State::$meta as $post_id => $metas ) {
					if ( ( $metas[ $key ] ?? null ) === $value ) {
						$ids[] = (int) $post_id;
					}
				}

				return $ids;
			}
		);
		$before  = serialize( \WP_Test_State::$posts );

		$result = $creator->create( $this->input() );

		$this->assertNotEmpty( $seen['meta_input'] ?? array(), 'el layout lleva una marca única antes de insertar' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'se eliminó', $result->get_error_message() );
		$this->assertSame( $before, serialize( \WP_Test_State::$posts ), 'no queda ningún layout publicado a medias' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_if_the_inserter_throws_and_the_post_cannot_be_deleted_the_error_names_it(): void {
		$creator = new Divi5LayoutCreator(
			new DiviTreeValidator(),
			static fn(): bool => true,
			null,
			null,
			static function ( array $data ) {
				$id = wp_insert_post( $data, true );
				foreach ( (array) ( $data['meta_input'] ?? array() ) as $key => $value ) {
					update_post_meta( (int) $id, $key, $value );
				}

				throw new \RuntimeException( 'falló después de crear' );
			},
			null,
			static fn( int $id ) => false,
			null,
			static fn( string $key, string $value ): array => array_keys( \WP_Test_State::$posts )
		);

		$result = $creator->create( $this->input() );
		$id     = array_keys( \WP_Test_State::$posts )[0];

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertStringContainsString( (string) $id, $result->get_error_message(), 'dice qué layout quedó' );
		$this->assertStringContainsString( 'publicado', $result->get_error_message() );
	}

	public function test_if_the_inserter_throws_and_nothing_is_found_the_error_warns_a_layout_may_remain(): void {
		$creator = new Divi5LayoutCreator(
			new DiviTreeValidator(),
			static fn(): bool => true,
			null,
			null,
			static function ( array $data ) {
				throw new \RuntimeException( 'falló' );
			},
			null,
			null,
			null,
			static fn( string $key, string $value ): array => array()
		);

		$result = $creator->create( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'puede haber quedado', $result->get_error_message() );
	}

	public function test_a_successful_create_leaves_no_marker_meta_behind(): void {
		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( '_nexo_mcp_layout_creating', \WP_Test_State::$meta[ (int) $result['id'] ] ?? array() );
	}

	public function test_preserve_create_divi_page_still_makes_a_draft_page_and_rejects_the_layout_modules(): void {
		$tools = $this->tools();
		$page  = $tools['nexo-mcp/create-divi-page']['execute_callback']( array( 'title' => 'Nueva', 'sections' => $this->sections() ) );

		$this->assertIsArray( $page );
		$this->assertSame( 'draft', $page['status'] );
		$this->assertSame( 'page', \WP_Test_State::$posts[ (int) $page['id'] ]->post_type );

		$before = $this->snapshot();
		$denied = $tools['nexo-mcp/create-divi-page']['execute_callback']( array( 'title' => 'Otra', 'sections' => $this->sections( 'x', array( 'type' => 'divi/post-content' ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'nexo_mcp_divi_module_denied', $denied->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}
}
