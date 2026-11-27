<?php
/**
 * insert-divi-library-item (T10, S1/S3/S5/S7): insertar un ítem de la biblioteca en una página de Divi 5 como copia o como
 * referencia `divi/global-layout`, siempre en la copia de trabajo de la página y con el permiso de constructores Y el de la
 * biblioteca.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryInsertToolTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const INSERT = 'insert-divi-library-item';

	private const GLOBAL_SECTION = 820;

	private const GLOBAL_ROW = 821;

	private const GLOBAL_LAYOUT = 822;

	private const GLOBAL_DRAFT = 823;

	private const LAYOUT = 824;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();

		$this->put_item( self::GLOBAL_SECTION, 'Sección global', 'section', 'global' );
		$this->put_item( self::GLOBAL_ROW, 'Fila global', 'row', 'global' );
		$this->put_item( self::GLOBAL_LAYOUT, 'Layout global', 'layout', 'global' );
		$this->put_item( self::GLOBAL_DRAFT, 'Global en borrador', 'module', 'global', 'draft', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );
		$this->put_item( self::LAYOUT, 'Layout', 'layout' );

		\WP_Test_State::$posts[ self::PAGE ] = new \WP_Post( array( 'ID' => self::PAGE, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
	}

	/**
	 * @param array<string, mixed> $extra Campos que cambian.
	 * @return array<string, mixed>
	 */
	private function input( int $item, array $extra = array() ): array {
		$path = $extra['path'] ?? '0.0';

		return array_merge(
			array(
				'id'          => $item,
				'post_id'     => self::PAGE,
				'path'        => $path,
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), (string) $path )['fingerprint'] ?? '' ),
				'position'    => 'after',
			),
			$extra
		);
	}

	/**
	 * @param array<string, mixed> $extra Campos.
	 * @return mixed
	 */
	private function insert( int $item, array $extra = array(), array $settings = array() ) {
		return $this->run_tool( self::INSERT, $this->input( $item, $extra ), $settings );
	}

	private function copy_content( array $result ): string {
		$this->assertArrayHasKey( 'draft_of', $result, 'va a la copia de trabajo' );
		$copy = get_post( (int) $result['id'] );
		$this->assertInstanceOf( \WP_Post::class, $copy );

		return $copy->post_content;
	}

	public function test_it_is_registered_as_a_write_tool_with_a_closed_schema_and_documents_its_rules(): void {
		$tool = $this->tool( self::INSERT );

		$this->assertContains( 'nexo-mcp/' . self::INSERT, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'post_id', 'path', 'fingerprint', 'position', 'mode', 'draft' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'id', 'post_id', 'path', 'fingerprint', 'position' ), $tool['input_schema']['required'] );
		$this->assertSame( array( 'copy', 'global' ), $tool['input_schema']['properties']['mode']['enum'] );

		foreach ( array( 'mcp:divi_library', 'mcp:builders', 'nexo_mcp_divi_library_denied', 'nexo_mcp_divi5_required', 'nexo_mcp_divi_library_mode_invalid', 'nexo_mcp_divi_library_foreign', 'nexo_mcp_divi_library_copy_invalid', 'copia de trabajo', 'divi/global-layout', 'nunca publica' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_permission_needs_the_builders_one_and_the_library_one(): void {
		$input = $this->input( self::SECTION );

		$this->assertTrue( $this->tool( self::INSERT )['permission_callback']( $input ) );
		$this->assertNotTrue( $this->tool( self::INSERT, array( self::SWITCH => false ) )['permission_callback']( $input ), 'sin biblioteca' );
		$this->assertNotTrue( $this->tool( self::INSERT, array( 'builders_tools_enabled' => false ) )['permission_callback']( $input ), 'sin constructores' );

		\WP_Test_State::$caps['edit_post'] = false;
		$this->assertNotTrue( $this->tool( self::INSERT )['permission_callback']( $input ), 'sin edit_post sobre la página' );
	}

	public function test_a_global_item_also_needs_edit_global_library_even_to_insert_a_copy(): void {
		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble de Divi, definido sólo en este proceso aparte.
		eval( 'function et_pb_is_allowed( $capability ) { return ! empty( $GLOBALS["nexo_test_et_allowed"][ $capability ] ); }' );
		$tool = $this->tool( self::INSERT );

		$this->assertTrue( $tool['permission_callback']( $this->input( self::SECTION ) ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input( self::GLOBAL, array( 'mode' => 'global' ) ) ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input( self::GLOBAL, array( 'mode' => 'copy' ) ) ) );

		$GLOBALS['nexo_test_et_allowed']['edit_global_library'] = true;
		$this->assertTrue( $tool['permission_callback']( $this->input( self::GLOBAL, array( 'mode' => 'global' ) ) ) );
	}

	public function test_without_the_library_permission_nothing_is_written_even_with_a_valid_input(): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( self::SECTION, array(), array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->insert( self::PAGE ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->insert( 99999 ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_copy_goes_to_the_working_copy_and_leaves_the_page_and_the_item_untouched(): void {
		$page = \WP_Test_State::$posts[ self::PAGE ]->post_content;
		$item = clone \WP_Test_State::$posts[ self::SECTION ];

		$result = $this->insert( self::SECTION );

		$this->assertIsArray( $result );
		$this->assertSame( self::PAGE, $result['draft_of'] );
		$this->assertSame( 'insert', $result['operation'] );
		$this->assertSame( 'copy', $result['mode'] );
		$this->assertSame( array( 'id' => self::SECTION, 'title' => 'Sección azul', 'type' => 'section', 'scope' => 'non_global' ), $result['item'] );
		$this->assertSame( $page, \WP_Test_State::$posts[ self::PAGE ]->post_content, 'el original no cambia' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::PAGE ]->post_status );
		$this->assertEquals( $item, \WP_Test_State::$posts[ self::SECTION ], 'el ítem no cambia' );
		$this->assertSame( (int) $result['id'], WorkingCopy::copy_of( \WP_Test_State::$posts[ self::PAGE ] ) );

		$tree = Divi5Tree::build( $this->copy_content( $result ) );
		$this->assertCount( 3, $tree[0]['children'] );
		$this->assertSame( 'Sección azul', Divi5Tree::locate( $tree, '0.1.0.0.0' )['text'] ); // @phpstan-ignore-line
		$this->assertSame( 'A', Divi5Tree::locate( $tree, '0.0.0.0.0' )['text'] ); // @phpstan-ignore-line
		$this->assertStringNotContainsString( 'global-layout', $this->copy_content( $result ) );
	}

	public function test_each_item_type_is_copied_where_its_block_fits(): void {
		$cases = array(
			'layout'  => array( self::LAYOUT, '0.0', 'after', '0.1.0.0.0', 'Layout' ),
			'section' => array( self::SECTION, '0.1', 'before', '0.1.0.0.0', 'Sección azul' ),
			'row'     => array( self::ROW, '0.0', 'inside', '0.0.1.0.0', 'Fila' ),
			'module'  => array( self::MODULE, '0.0.0.0', 'inside', '0.0.0.0.1', 'Texto' ),
		);

		foreach ( $cases as $name => $case ) {
			\WP_Test_State::$posts[ self::PAGE ]->post_content = Divi5SectionEditorTest::page();
			$result                                           = $this->insert( $case[0], array( 'path' => $case[1], 'position' => $case[2] ) );

			$this->assertIsArray( $result, $name );
			$this->assertSame( $case[4], Divi5Tree::locate( Divi5Tree::build( $this->copy_content( $result ) ), $case[3] )['text'], $name ); // @phpstan-ignore-line
			$this->assertSame( 'insert', $result['operation'] );

			// Cada ejecución parte de una copia nueva.
			$copy = WorkingCopy::copy_of( \WP_Test_State::$posts[ self::PAGE ] );
			unset( \WP_Test_State::$posts[ $copy ] );
			delete_post_meta( self::PAGE, WorkingCopy::META_DRAFT );
		}
	}

	public function test_copying_a_global_item_inserts_its_content_without_a_reference(): void {
		$result = $this->insert( self::GLOBAL, array( 'path' => '0.0.0.0', 'position' => 'inside' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'global', $result['item']['scope'], 'el ítem sigue siendo global' );
		$this->assertSame( 'Módulo global', Divi5Tree::locate( Divi5Tree::build( $this->copy_content( $result ) ), '0.0.0.0.1' )['text'] ); // @phpstan-ignore-line
		$this->assertStringNotContainsString( 'globalModule', $this->copy_content( $result ) );
	}

	public function test_global_mode_writes_the_exact_reference_divi_writes_into_the_working_copy(): void {
		$page   = \WP_Test_State::$posts[ self::PAGE ]->post_content;
		$result = $this->insert( self::GLOBAL, array( 'path' => '0.0.0.0', 'position' => 'inside', 'mode' => 'global' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'global', $result['mode'] );
		$this->assertSame( 'global', $result['item']['scope'] );
		$this->assertStringContainsString( '<!-- wp:divi/global-layout {"globalModule":"803","blockName":"divi/text"} /-->', $this->copy_content( $result ) );
		$this->assertSame( 'divi/global-layout', Divi5Tree::locate( Divi5Tree::build( $this->copy_content( $result ) ), '0.0.0.0.1' )['type'] ); // @phpstan-ignore-line
		$this->assertSame( $page, \WP_Test_State::$posts[ self::PAGE ]->post_content, 'el original no cambia' );
		$this->assertNotSame( array(), $result['warnings'] );
	}

	public function test_global_mode_for_a_global_section_and_a_global_row(): void {
		$section = $this->insert( self::GLOBAL_SECTION, array( 'mode' => 'global' ) );

		$this->assertIsArray( $section );
		$this->assertStringContainsString( '<!-- wp:divi/global-layout {"globalModule":"820","blockName":"divi/section"} /-->', $this->copy_content( $section ) );

		$row = $this->insert( self::GLOBAL_ROW, array( 'path' => '0.0', 'position' => 'inside', 'mode' => 'global' ) );

		$this->assertIsArray( $row );
		$this->assertStringContainsString( '<!-- wp:divi/global-layout {"globalModule":"821","blockName":"divi/row"} /-->', $this->copy_content( $row ) );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function not_referenceable(): array {
		return array(
			'ítem no global'        => array( self::SECTION ),
			'layout global'         => array( self::GLOBAL_LAYOUT ),
			'global en borrador'    => array( self::GLOBAL_DRAFT ),
		);
	}

	/**
	 * @dataProvider not_referenceable
	 */
	public function test_global_mode_is_refused_for_an_item_that_divi_would_not_render_as_a_reference_and_nothing_changes( int $item ): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( $item, array( 'mode' => 'global' ) ), 'nexo_mcp_divi_library_mode_invalid' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_reference_does_not_fit_where_its_block_does_not_fit_and_nothing_changes(): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( self::GLOBAL, array( 'path' => '0.0', 'position' => 'after', 'mode' => 'global' ) ), 'nexo_mcp_divi_nesting_invalid' );
		$this->assertError( $this->insert( self::GLOBAL_ROW, array( 'path' => '0.0.0.0', 'position' => 'inside', 'mode' => 'global' ) ), 'nexo_mcp_divi_nesting_invalid' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_copy_that_does_not_fit_there_is_rejected_and_nothing_changes(): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( self::MODULE, array( 'path' => '0.0', 'position' => 'after' ) ), 'nexo_mcp_divi_nesting_invalid' );
		$this->assertError( $this->insert( self::SECTION, array( 'path' => '0.0.0', 'position' => 'after' ) ), 'nexo_mcp_divi_nesting_invalid' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_an_item_with_content_the_connector_cannot_represent_is_not_copied(): void {
		$before = $this->snapshot();

		$error = $this->assertError( $this->insert( self::FOREIGN ), 'nexo_mcp_divi_library_foreign' );

		$this->assertSame( array( 'divi/fullwidth-menu' ), $error->get_error_data()['foreign'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_an_item_whose_attributes_the_validator_rejects_is_not_copied_and_the_error_says_why(): void {
		$content = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.19.1","html":"<script>x</script>"} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text {"builderVersion":"5.19.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
		$this->put_item( 830, 'Con atributos ajenos', 'section', 'non_global', 'publish', $content );
		$before = $this->snapshot();

		$error = $this->assertError( $this->insert( 830 ), 'nexo_mcp_divi_library_copy_invalid' );

		$this->assertSame( 'nexo_mcp_divi_attr_denied', $error->get_error_data()['reason'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_trashed_item_an_auto_draft_and_a_stale_fingerprint_change_nothing(): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( self::TRASHED ), 'nexo_mcp_divi_library_trashed' );
		$this->assertError( $this->insert( self::AUTO_DRAFT ), 'nexo_mcp_divi_library_not_found' );
		$this->assertError( $this->insert( self::SECTION, array( 'fingerprint' => 'viejo' ) ), 'nexo_mcp_divi_node_changed' );
		$this->assertError( $this->insert( self::SECTION, array( 'path' => '9.9', 'fingerprint' => 'x' ) ), 'nexo_mcp_builder_node_missing' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_target_must_be_a_divi_5_page_the_user_can_edit_not_a_library_item(): void {
		$before = $this->snapshot();

		$this->assertError( $this->insert( self::SECTION, array( 'post_id' => self::ROW ) ), 'nexo_mcp_divi_post_type_denied' );
		$this->assertError( $this->insert( self::SECTION, array( 'post_id' => 99999 ) ), 'nexo_mcp_builder_not_found' );

		\WP_Test_State::$denied_edit_posts = array( self::PAGE );
		$this->assertError( $this->insert( self::SECTION ), 'nexo_mcp_builder_forbidden' );
		$this->assertSame( $before, $this->snapshot() );

		\WP_Test_State::$denied_edit_posts                  = array();
		\WP_Test_State::$posts[ self::PAGE ]->post_content = 'Texto suelto sin Divi';
		$this->assertError( $this->insert( self::SECTION ), 'nexo_mcp_divi_not_divi5' );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function garbage(): array {
		return array(
			'campo ajeno'      => array( array( 'otro' => 1 ), 'otro' ),
			'sin post_id'      => array( array( 'post_id' => null ), 'post_id' ),
			'post_id texto'    => array( array( 'post_id' => 'x' ), 'post_id' ),
			'sin ruta'         => array( array( 'path' => '' ), 'path' ),
			'sin huella'       => array( array( 'fingerprint' => '' ), 'fingerprint' ),
			'posición ajena'   => array( array( 'position' => 'encima' ), 'position' ),
			'modo ajeno'       => array( array( 'mode' => 'link' ), 'mode' ),
			'draft no booleano' => array( array( 'draft' => 'si' ), 'draft' ),
		);
	}

	/**
	 * @dataProvider garbage
	 *
	 * @param array<string, mixed> $extra Campo basura.
	 */
	public function test_a_garbage_input_is_rejected_with_its_path_before_anything_changes( array $extra, string $path ): void {
		$before = $this->snapshot();
		$input  = array_merge( $this->input( self::SECTION ), $extra );

		if ( array_key_exists( 'post_id', $extra ) && null === $extra['post_id'] ) {
			unset( $input['post_id'] );
		}

		$error = $this->assertError( $this->run_tool( self::INSERT, $input ), 'nexo_mcp_divi_library_invalid' );

		$this->assertSame( $path, $error->get_error_data()['path'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_regular_section_tool_still_rejects_a_global_reference(): void {
		$before = $this->snapshot();
		$input  = array(
			'id'          => self::PAGE,
			'operation'   => 'insert',
			'path'        => '0.0.0.0',
			'fingerprint' => $this->input( self::SECTION, array( 'path' => '0.0.0.0' ) )['fingerprint'],
			'position'    => 'inside',
			'sections'    => array( array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => '803', 'blockName' => 'divi/text' ) ) ),
		);

		$this->assertError( $this->run_tool( 'edit-divi-section', $input ), 'nexo_mcp_divi_module_denied' );
		$this->assertSame( $before, $this->snapshot() );
	}
}
