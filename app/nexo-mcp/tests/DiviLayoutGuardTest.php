<?php
/**
 * Guarda de tipo de contenido de las herramientas de constructor (T5, seguridad): un layout del Theme Builder
 * (`et_header_layout`, `et_body_layout`, `et_footer_layout`, o una copia de trabajo suya) sólo se lee y se escribe con
 * `PermissionPolicy::can_write_divi_theme_builder()` (nunca con el `edit_post` de una página); los demás tipos `et_*`
 * (plantillas, Theme Builder, biblioteca, snippets) se rechazan siempre en las herramientas de página.
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
use NexoDev\NexoMCP\Builders\DiviLayoutGuard;
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
final class DiviLayoutGuardTest extends NexoTestCase {

	private const SWITCH = 'divi_theme_builder_tools_enabled';
	private const SCOPE  = 'mcp:divi_theme_builder';

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		$content = Divi5SectionEditorTest::page();

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $content ) );
		\WP_Test_State::$posts[200] = new \WP_Post( array( 'ID' => 200, 'post_type' => 'et_body_layout', 'post_status' => 'publish', 'post_title' => 'Cuerpo', 'post_content' => $content ) );
		\WP_Test_State::$posts[201] = new \WP_Post( array( 'ID' => 201, 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Encabezado', 'post_content' => $content ) );
		\WP_Test_State::$posts[202] = new \WP_Post( array( 'ID' => 202, 'post_type' => 'et_footer_layout', 'post_status' => 'publish', 'post_title' => 'Pie', 'post_content' => $content ) );
		// Copia de trabajo de un layout (como la deja WorkingCopy::obtain, sondeada en L4 (a)).
		\WP_Test_State::$posts[210] = new \WP_Post( array( 'ID' => 210, 'post_type' => 'et_body_layout', 'post_status' => 'draft', 'post_title' => 'Cuerpo', 'post_content' => $content ) );
		update_post_meta( 210, WorkingCopy::META_DRAFT_OF, 200 );
		update_post_meta( 200, WorkingCopy::META_DRAFT, 210 );
		// Un borrador de PÁGINA que dice ser copia de un layout pero el layout no apunta a él (apunta a 210): se trata como página.
		\WP_Test_State::$posts[211] = new \WP_Post( array( 'ID' => 211, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Falsa', 'post_content' => $content ) );
		update_post_meta( 211, WorkingCopy::META_DRAFT_OF, 200 );

		foreach ( array( 300 => 'et_template', 301 => 'et_theme_builder', 302 => 'et_pb_layout', 303 => 'et_code_snippet' ) as $id => $type ) {
			\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $type, 'post_content' => $content ) );
		}
	}

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

		$short = array();

		foreach ( \WP_Test_State::$abilities as $name => $ability ) {
			$short[ substr( $name, strlen( 'nexo-mcp/' ) ) ] = $ability;
		}

		return $short;
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	private function fingerprint( string $path ): string {
		return (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), $path )['fingerprint'] ?? '' );
	}

	/**
	 * Entrada mínima de cada herramienta de constructor que resuelve un contenido por id.
	 *
	 * @return array<string, mixed>
	 */
	private function input( string $tool, int $id ): array {
		$base = array( 'id' => $id );

		switch ( $tool ) {
			case 'edit-divi-section':
				return $base + array( 'operation' => 'replace', 'path' => '0.0', 'fingerprint' => $this->fingerprint( '0.0' ), 'sections' => Divi5SectionEditorTestFixtures::sections( 'Nueva' ) );
			case 'edit-divi-module':
				return $base + array( 'path' => '0.0.0.0.0', 'fingerprint' => $this->fingerprint( '0.0.0.0.0' ), 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ) );
			case 'set-divi-dynamic':
				return $base + array( 'path' => '0.0.0.0.0', 'fingerprint' => $this->fingerprint( '0.0.0.0.0' ), 'field' => 'content.innerContent', 'source' => null );
			case 'set-divi-loop':
				return $base + array( 'path' => '0.0.0.0.0', 'fingerprint' => $this->fingerprint( '0.0.0.0.0' ), 'loop' => null );
			case 'set-divi-conditions':
				return $base + array( 'path' => '0.0.0.0.0', 'fingerprint' => $this->fingerprint( '0.0.0.0.0' ), 'match' => 'all', 'conditions' => array() );
			case 'list-divi-dynamic-sources':
			case 'list-divi-conditions':
				return array( 'post_id' => $id );
			case 'update-node-text':
				return $base + array( 'node_id' => '0.0.0.0.0', 'text' => 'Nuevo' );
			case 'update-node-attribute':
				return $base + array( 'node_id' => '0.0.0.0.0', 'attribute' => 'link', 'value' => 'https://example.com/' );
			case 'edit-structure':
				return $base + array( 'node_id' => '0.0.0.0.0' );
			case 'set-dynamic-tag':
				return $base + array( 'node_id' => 'x', 'prop' => 'title', 'tag' => array( 'name' => 'post-title' ) );
			case 'insert-component':
				return $base + array( 'component_id' => 1 );
			case 'edit-elementor-section':
				return $base + array( 'operation' => 'delete', 'node_id' => 'x' );
			default:
				return $base;
		}
	}

	/**
	 * Todas las herramientas de constructor que resuelven un contenido por id (L4 (f) más las de D5-D7).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function builder_tools(): array {
		$names = array(
			'get-page-structure',
			'edit-divi-section',
			'edit-divi-module',
			'set-divi-dynamic',
			'set-divi-loop',
			'set-divi-conditions',
			'list-divi-dynamic-sources',
			'list-divi-conditions',
			'update-node-text',
			'update-node-attribute',
			'edit-structure',
			'restore-builder-backup',
			'set-dynamic-tag',
			'insert-component',
			'edit-elementor-section',
			'migrate-elementor-page',
		);

		return array_combine( $names, array_map( static fn( string $name ): array => array( $name ), $names ) );
	}

	/**
	 * Cada herramienta con su ajuste de «módulo apagado» propio, todos encendidos para que sólo mande el tipo.
	 *
	 * @return array<string, mixed>
	 */
	private function all_on(): array {
		return array(
			'builders_tools_enabled'        => true,
			'builders_dynamic_enabled'      => true,
			'builders_components_enabled'   => true,
			'divi_dynamic_tools_enabled'    => true,
			'divi_conditions_tools_enabled' => true,
		);
	}

	/**
	 * @dataProvider builder_tools
	 */
	public function test_a_layout_is_denied_with_only_edit_post_in_both_layers_and_nothing_is_written( string $tool ): void {
		// El editor tiene todo lo de constructores y edit_post sobre el layout, pero el Theme Builder está apagado.
		$settings = $this->all_on() + array( self::SWITCH => false );

		foreach ( array( 200, 201, 202, 210 ) as $id ) {
			$input  = $this->input( $tool, $id );
			$before = $this->snapshot();

			$this->assertNotTrue( $this->tools( $settings )[ $tool ]['permission_callback']( $input ), "$tool $id: permiso" );

			$result = $this->tools( $settings )[ $tool ]['execute_callback']( $input );

			$this->assertInstanceOf( \WP_Error::class, $result, "$tool $id" );
			$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code(), "$tool $id" );
			$this->assertSame( $before, $this->snapshot(), "$tool $id: nada cambia" );
		}
	}

	/**
	 * Una página-borrador que el layout reconoce como su copia (el layout apunta de vuelta) se cubre con el permiso del Theme Builder.
	 *
	 * @dataProvider builder_tools
	 */
	public function test_a_page_typed_copy_the_layout_points_back_to_is_denied_by_its_original( string $tool ): void {
		update_post_meta( 200, WorkingCopy::META_DRAFT, 211 );

		$input  = $this->input( $tool, 211 );
		$before = $this->snapshot();
		$tools  = $this->tools( $this->all_on() + array( self::SWITCH => false ) );

		$this->assertNotTrue( $tools[ $tool ]['permission_callback']( $input ), $tool );

		$result = $tools[ $tool ]['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_forged_copy_pointer_without_a_back_pointer_keeps_the_page_rules(): void {
		$this->assertSame( DiviLayoutGuard::KIND_NONE, DiviLayoutGuard::kind( \WP_Test_State::$posts[211] ), 'el layout apunta a 210, no a 211' );
		$this->assertSame( DiviLayoutGuard::KIND_LAYOUT, DiviLayoutGuard::kind( \WP_Test_State::$posts[210] ), 'la copia verdadera sigue siendo layout' );
		$this->assertSame( DiviLayoutGuard::KIND_LAYOUT, DiviLayoutGuard::kind( \WP_Test_State::$posts[200] ) );

		// El layout sin copia vigente: la página tampoco hereda nada.
		unset( \WP_Test_State::$meta[200][ WorkingCopy::META_DRAFT ] );
		$this->assertSame( DiviLayoutGuard::KIND_NONE, DiviLayoutGuard::kind( \WP_Test_State::$posts[211] ) );

		// Sigue mandando edit_post de la página: con el Theme Builder apagado se permite; sin edit_post, no.
		$tools = $this->tools( $this->all_on() + array( self::SWITCH => false ) );

		foreach ( array( 'get-page-structure', 'update-node-text', 'edit-divi-section' ) as $tool ) {
			$this->assertTrue( $tools[ $tool ]['permission_callback']( $this->input( $tool, 211 ) ), "$tool: edit_post basta" );
		}

		\WP_Test_State::$denied_edit_posts = array( 200, 211 );
		$tools                             = $this->tools( $this->all_on() + array( self::SWITCH => true ) );

		$this->assertNotTrue( $tools['get-page-structure']['permission_callback']( array( 'id' => 211 ) ), 'con el Theme Builder encendido tampoco abre una página falsa' );
	}

	/**
	 * Las herramientas con scope propio lo siguen exigiendo en un layout, además del permiso del Theme Builder.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, bool>}>
	 */
	public static function own_scopes(): array {
		return array(
			'update-node-attribute / scope BUILDER_ATTRS'  => array( 'update-node-attribute', 'nexo_mcp_builder_attrs', array() ),
			'set-dynamic-tag / capacidad'                  => array( 'set-dynamic-tag', 'nexo_mcp_builders_dynamic', array() ),
			'set-dynamic-tag / interruptor'                => array( 'set-dynamic-tag', '', array( 'builders_dynamic_enabled' => false ) ),
			'insert-component / capacidad'                 => array( 'insert-component', 'nexo_mcp_builders_components', array() ),
			'insert-component / interruptor'               => array( 'insert-component', '', array( 'builders_components_enabled' => false ) ),
		);
	}

	/**
	 * @dataProvider own_scopes
	 *
	 * @param array<string, bool> $off Ajustes que se apagan.
	 */
	public function test_a_layout_still_needs_the_tools_own_scope_on_top_of_the_theme_builder( string $tool, string $capability, array $off ): void {
		$settings = $this->all_on() + array( self::SWITCH => true );

		foreach ( array( 200, 210 ) as $id ) {
			$input = $this->input( $tool, $id );

			$this->assertTrue( $this->tools( $settings )[ $tool ]['permission_callback']( $input ), "$tool $id: control, todo en regla" );

			if ( '' !== $capability ) {
				\WP_Test_State::$caps[ $capability ] = false;
			}

			$this->assertNotTrue( $this->tools( array_merge( $settings, $off ) )[ $tool ]['permission_callback']( $input ), "$tool $id: sin su scope propio" );

			if ( '' !== $capability ) {
				\WP_Test_State::$caps[ $capability ] = true;
			}
		}

		// Y sin el Theme Builder, con el scope propio, tampoco.
		$this->assertNotTrue( $this->tools( $this->all_on() + array( self::SWITCH => false ) )[ $tool ]['permission_callback']( $this->input( $tool, 200 ) ), "$tool: sin Theme Builder" );
	}

	public function test_a_token_without_the_builder_attrs_scope_cannot_edit_a_layout_attribute(): void {
		$settings = $this->all_on() + array( self::SWITCH => true, 'enforce_connection_scopes' => true );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertNotTrue( $this->tools( $settings )['update-node-attribute']['permission_callback']( $this->input( 'update-node-attribute', 200 ) ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE, 'mcp:builder_attrs' ) );
		$this->assertTrue( $this->tools( $settings )['update-node-attribute']['permission_callback']( $this->input( 'update-node-attribute', 200 ) ) );
	}

	/**
	 * @dataProvider builder_tools
	 */
	public function test_the_other_et_types_are_always_rejected_even_with_the_theme_builder_permission( string $tool ): void {
		$settings = $this->all_on() + array( self::SWITCH => true );

		foreach ( array( 300, 301, 302, 303 ) as $id ) {
			$input  = $this->input( $tool, $id );
			$before = $this->snapshot();

			$result = $this->tools( $settings )[ $tool ]['execute_callback']( $input );

			$this->assertInstanceOf( \WP_Error::class, $result, "$tool $id" );
			$this->assertSame( 'nexo_mcp_divi_post_type_denied', $result->get_error_code(), "$tool $id" );
			$this->assertSame( $before, $this->snapshot(), "$tool $id: nada cambia" );
		}
	}

	public function test_each_missing_piece_of_the_theme_builder_permission_denies_reading_and_writing(): void {
		$on = $this->all_on() + array( self::SWITCH => true );

		$this->assertTrue( $this->tools( $on )['get-page-structure']['permission_callback']( array( 'id' => 200 ) ), 'control: todo en regla' );

		$cases = array(
			'interruptor apagado'         => static function (): array {
				return array( self::SWITCH => false );
			},
			'sin edit_theme_options'      => static function (): array {
				\WP_Test_State::$caps['edit_theme_options'] = false;

				return array();
			},
			'sin edit_others_posts'       => static function (): array {
				\WP_Test_State::$caps['edit_others_posts'] = false;

				return array();
			},
			'sin la capacidad del scope'  => static function (): array {
				\WP_Test_State::$caps['nexo_mcp_divi_theme_builder'] = false;

				return array();
			},
			'sólo revisar'                => static function (): array {
				return array( 'read_only' => true );
			},
		);

		foreach ( $cases as $label => $apply ) {
			$this->setUp();
			$before = $this->snapshot();
			$extra  = $apply();
			$tools  = $this->tools( array_merge( $on, $extra ) );

			foreach ( array( 'get-page-structure' => array( 'id' => 200 ), 'edit-divi-section' => $this->input( 'edit-divi-section', 200 ) ) as $tool => $input ) {
				$this->assertNotTrue( $tools[ $tool ]['permission_callback']( $input ), "$label $tool: permiso" );

				$result = $tools[ $tool ]['execute_callback']( $input );

				$this->assertInstanceOf( \WP_Error::class, $result, "$label $tool" );
				$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code(), "$label $tool" );
			}

			$this->assertSame( $before, $this->snapshot(), "$label: nada cambia" );
		}
	}

	public function test_a_token_without_the_theme_builder_scope_is_denied(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:builders' ) );
		$tools = $this->tools( $this->all_on() + array( self::SWITCH => true, 'enforce_connection_scopes' => true ) );

		$this->assertNotTrue( $tools['get-page-structure']['permission_callback']( array( 'id' => 200 ) ) );
		$this->assertNotTrue( $tools['edit-divi-section']['permission_callback']( $this->input( 'edit-divi-section', 200 ) ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$tools = $this->tools( $this->all_on() + array( self::SWITCH => true, 'enforce_connection_scopes' => true ) );

		$this->assertTrue( $tools['get-page-structure']['permission_callback']( array( 'id' => 200 ) ) );
		$this->assertTrue( $tools['edit-divi-section']['permission_callback']( $this->input( 'edit-divi-section', 200 ) ) );
	}

	public function test_with_the_theme_builder_permission_a_layout_is_read_without_edit_post_and_without_the_builders_module(): void {
		\WP_Test_State::$denied_edit_posts = array( 200, 201, 202 );
		$tools                             = $this->tools( array( self::SWITCH => true, 'builders_tools_enabled' => false ) );

		$this->assertTrue( $tools['get-page-structure']['permission_callback']( array( 'id' => 200 ) ) );

		$result = $tools['get-page-structure']['execute_callback']( array( 'id' => 200 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['id'] );
		$this->assertSame( 'divi5', $result['builder'] );
		$this->assertContains( '0.0.0.0.0', array_column( $result['nodes'], 'id' ) );
	}

	public function test_with_the_theme_builder_permission_a_layout_is_edited_through_its_working_copy(): void {
		$tools = $this->tools( array( self::SWITCH => true, 'builders_tools_enabled' => false ) );
		unset( \WP_Test_State::$meta[200][ WorkingCopy::META_DRAFT ], \WP_Test_State::$meta[210], \WP_Test_State::$posts[210], \WP_Test_State::$posts[211] );
		$original = \WP_Test_State::$posts[200]->post_content;
		$input    = $this->input( 'edit-divi-section', 200 );

		$this->assertTrue( $tools['edit-divi-section']['permission_callback']( $input ) );

		$result = $tools['edit-divi-section']['execute_callback']( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['draft_of'] );
		$copy = (int) $result['id'];
		$this->assertNotSame( 200, $copy );
		$this->assertSame( 'et_body_layout', \WP_Test_State::$posts[ $copy ]->post_type );
		$this->assertSame( 'draft', \WP_Test_State::$posts[ $copy ]->post_status );
		$this->assertSame( $original, \WP_Test_State::$posts[200]->post_content, 'el layout vivo no cambia' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[200]->post_status );
		$this->assertSame( $copy, WorkingCopy::copy_of( \WP_Test_State::$posts[200] ) );
		$this->assertStringContainsString( 'Nueva', \WP_Test_State::$posts[ $copy ]->post_content );
	}

	public function test_the_copy_of_a_layout_is_covered_by_the_same_permission_and_a_forged_copy_id_does_not_open_it(): void {
		$tools = $this->tools( array( self::SWITCH => true, 'builders_tools_enabled' => false ) );

		$this->assertTrue( $tools['get-page-structure']['permission_callback']( array( 'id' => 210 ) ) );

		$result = $tools['get-page-structure']['execute_callback']( array( 'id' => 210 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 210, $result['id'] );
	}

	public function test_a_non_layout_et_type_has_no_permission_shortcut_in_the_registrar(): void {
		// Con el Theme Builder encendido la herramienta sigue pidiendo lo de siempre para un tipo ajeno (y la ejecución lo rechaza).
		\WP_Test_State::$denied_edit_posts = array( 300 );

		$this->assertNotTrue( $this->tools( $this->all_on() + array( self::SWITCH => true ) )['get-page-structure']['permission_callback']( array( 'id' => 300 ) ) );
	}

	public function test_dynamic_loop_and_conditions_on_a_layout_need_the_theme_builder_and_their_own_scope(): void {
		foreach ( array( 'set-divi-dynamic' => 'divi_dynamic_tools_enabled', 'set-divi-loop' => 'divi_dynamic_tools_enabled', 'set-divi-conditions' => 'divi_conditions_tools_enabled' ) as $tool => $own ) {
			$input = $this->input( $tool, 200 );

			$this->assertTrue( $this->tools( $this->all_on() + array( self::SWITCH => true ) )[ $tool ]['permission_callback']( $input ), "$tool: todo" );
			$this->assertNotTrue( $this->tools( $this->all_on() + array( self::SWITCH => false ) )[ $tool ]['permission_callback']( $input ), "$tool: sin Theme Builder" );
			$this->assertNotTrue( $this->tools( array_merge( $this->all_on(), array( self::SWITCH => true, $own => false ) ) )[ $tool ]['permission_callback']( $input ), "$tool: sin su scope propio" );
		}
	}

	/**
	 * @dataProvider builder_tools
	 */
	public function test_preserve_pages_keep_the_edit_post_permission_and_their_old_errors( string $tool ): void {
		$settings = $this->all_on() + array( self::SWITCH => false );
		$input    = $this->input( $tool, 100 );

		// Con edit_post la página pasa la capa de permiso (como antes de T5).
		$this->assertTrue( $this->tools( $settings )[ $tool ]['permission_callback']( $input ), "$tool: edit_post basta en una página" );

		// Sin edit_post: el error de siempre y nada cambia.
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$before                            = $this->snapshot();
		$result                            = $this->tools( $settings )[ $tool ]['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result, $tool );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code(), $tool );
		$this->assertSame( $before, $this->snapshot() );

		// Un id que no existe: el de siempre.
		$missing = $this->tools( $settings )[ $tool ]['execute_callback']( $this->input( $tool, 9999 ) );

		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'nexo_mcp_builder_not_found', $missing->get_error_code(), $tool );
	}

	public function test_preserve_a_page_is_still_read_and_edited_with_only_edit_post(): void {
		$tools = $this->tools( array( 'builders_tools_enabled' => true, self::SWITCH => false ) );

		$read = $tools['get-page-structure']['execute_callback']( array( 'id' => 100 ) );

		$this->assertIsArray( $read );
		$this->assertSame( 'divi5', $read['builder'] );

		$edited = $tools['edit-divi-section']['execute_callback']( $this->input( 'edit-divi-section', 100 ) );

		$this->assertIsArray( $edited );
		$this->assertSame( 100, $edited['draft_of'] );
	}
}
