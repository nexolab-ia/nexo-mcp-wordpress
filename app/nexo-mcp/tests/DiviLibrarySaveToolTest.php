<?php
/**
 * save-divi-library-item, alta (T9): registro, permiso, términos y metas del Visual Builder, contenido validado por Divi 5,
 * y que una entrada rechazada o una escritura fallida no dejan nada a medias.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibrarySaveToolTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const SAVE = 'save-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	/**
	 * @param array<string, mixed> $extra Campos que se suman o pisan.
	 * @return array<string, mixed>
	 */
	private function section_input( array $extra = array() ): array {
		return array_merge( array( 'title' => 'Mi sección', 'type' => 'section', 'nodes' => $this->section_nodes( 'Hola' ) ), $extra );
	}

	private function new_id(): int {
		return max( array_keys( \WP_Test_State::$posts ) );
	}

	public function test_it_is_registered_as_a_destructive_write_tool_with_a_closed_schema(): void {
		$tool = $this->tool( self::SAVE );

		$this->assertContains( 'nexo-mcp/' . self::SAVE, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertTrue( $tool['meta']['annotations']['destructive'], 'actualizar reemplaza el contenido' );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'title', 'type', 'scope', 'nodes', 'categories', 'replace_foreign' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'layout', 'section', 'row', 'module' ), $tool['input_schema']['properties']['type']['enum'] );
		$this->assertSame( array( 'non_global', 'global' ), $tool['input_schema']['properties']['scope']['enum'] );
		$this->assertSame( 100, $tool['input_schema']['properties']['title']['maxLength'] );
		$this->assertSame( 10, $tool['input_schema']['properties']['categories']['maxItems'] );

		foreach ( array( 'mcp:divi_library', 'nexo_mcp_divi_library_denied', 'nexo_mcp_divi5_required', 'nexo_mcp_divi_library_invalid', 'nexo_mcp_divi_library_foreign', 'nexo_mcp_divi_library_global_blocked', 'nexo_mcp_divi_library_backup_failed', 'nexo_mcp_divi_library_save_failed', 'nexo_mcp_busy', 'nexo-mcp-restore-divi-library-item', 'replace_foreign', 'divi/placeholder', 'respaldo' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_without_the_library_permission_nothing_is_created_or_changed(): void {
		$before = $this->snapshot();

		$this->assertNotTrue( $this->tool( self::SAVE, array( self::SWITCH => false ) )['permission_callback']( $this->section_input() ) );
		$this->assertTrue( $this->tool( self::SAVE )['permission_callback']( $this->section_input() ) );
		$this->assertError( $this->run_tool( self::SAVE, $this->section_input(), array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'x' ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::PAGE, 'title' => 'x' ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Library_Stub_State::$create_calls );
	}

	public function test_a_section_is_created_with_the_terms_and_metas_of_the_visual_builder(): void {
		$result = $this->run_tool( self::SAVE, $this->section_input( array( 'categories' => array( 'Héroes', 'Pie' ) ) ) );
		$id     = $this->new_id();

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'section', $result['type'] );
		$this->assertSame( 'non_global', $result['scope'] );
		$this->assertSame( array( 'Héroes', 'Pie' ), $result['categories'] );
		$this->assertSame( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/text' ), array_column( $result['nodes'], 'type' ) );
		$this->assertSame( array(), $result['warnings'] );

		$post = \WP_Test_State::$posts[ $id ];
		$this->assertSame( 'et_pb_layout', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Mi sección', $post->post_title );
		$this->assertSame( $this->blocks_for( $this->section_nodes( 'Hola' ) ), $post->post_content );

		$this->assertSame( array( 'section' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_type'] ) );
		$this->assertSame( array( 'non_global' ), array_keys( \WP_Test_State::$terms[ $id ]['scope'] ) );
		$this->assertSame( array( 'regular' ), array_keys( \WP_Test_State::$terms[ $id ]['module_width'] ) );
		$this->assertSame( array( 'Héroes', 'Pie' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_category'] ) );
		$this->assertSame( 'page', get_post_meta( $id, '_et_pb_built_for_post_type', true ) );
		$this->assertSame( 'section', get_post_meta( $id, '_et_pb_template_type', true ) );
		$this->assertSame( 'on', get_post_meta( $id, '_et_pb_use_divi_5', true ) );
		$this->assertSame( '', get_post_meta( $id, '_et_pb_module_type', true ) );
		$this->assertSame( array( '_et_pb_built_for_post_type', '_et_pb_template_type', '_et_pb_use_divi_5' ), array_keys( get_post_meta( $id ) ), 'ni marca de creación ni respaldo' );
		$this->assertCount( 1, \WP_Library_Stub_State::$create_calls, 'por et_pb_create_layout' );
	}

	public function test_a_row_is_stored_under_the_placeholder_with_its_row_type(): void {
		$result = $this->run_tool( self::SAVE, array( 'title' => 'Fila nueva', 'type' => 'row', 'nodes' => $this->row_nodes( 'Fila' ) ) );
		$id     = $this->new_id();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'divi/placeholder', 'divi/row', 'divi/column', 'divi/text' ), array_column( $result['nodes'], 'type' ) );
		$this->assertSame( array( 'row' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_type'] ) );
		$this->assertSame( 'row', get_post_meta( $id, '_et_pb_template_type', true ) );
		$this->assertSame( $this->blocks_for( $this->row_nodes( 'Fila' ) ), \WP_Test_State::$posts[ $id ]->post_content );
	}

	public function test_a_global_module_gets_its_module_type_and_the_excluded_options_meta(): void {
		$result = $this->run_tool( self::SAVE, array( 'title' => 'Aviso', 'type' => 'module', 'scope' => 'global', 'nodes' => array( $this->text_node( 'Aviso' ) ) ) );
		$id     = $this->new_id();

		$this->assertIsArray( $result );
		$this->assertSame( 'global', $result['scope'] );
		$this->assertSame( array( 'global' ), array_keys( \WP_Test_State::$terms[ $id ]['scope'] ) );
		$this->assertSame( array( 'module' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_type'] ) );
		$this->assertSame( 'divi/text', get_post_meta( $id, '_et_pb_module_type', true ) );
		$this->assertSame( '[]', get_post_meta( $id, '_et_pb_excluded_global_options', true ) );
		$this->assertSame( 'module', get_post_meta( $id, '_et_pb_template_type', true ) );
		$this->assertTrue( $this->run_tool( 'get-divi-library-item', array( 'id' => $id ) )['global'] );
	}

	public function test_a_layout_may_carry_several_sections(): void {
		$nodes  = array_merge( $this->section_nodes( 'Uno' ), $this->section_nodes( 'Dos' ) );
		$result = $this->run_tool( self::SAVE, array( 'title' => 'Dos secciones', 'type' => 'layout', 'nodes' => $nodes ) );

		$this->assertIsArray( $result );
		$this->assertCount( 2, array_filter( $result['nodes'], static fn( array $n ): bool => 'divi/section' === $n['type'] ) );
		$this->assertSame( $this->blocks_for( $nodes ), \WP_Test_State::$posts[ $this->new_id() ]->post_content );
	}

	public function test_the_saved_item_is_listed_and_read_back_by_the_other_tools(): void {
		$id = $this->run_tool( self::SAVE, $this->section_input( array( 'title' => 'Nuevo azul', 'categories' => array( 'Héroes' ) ) ) )['id'];

		$this->assertContains( $id, array_column( $this->run_tool( 'list-divi-library', array( 'search' => 'Nuevo azul' ) )['items'], 'id' ) );
		$this->assertSame( 'Nuevo azul', $this->run_tool( 'get-divi-library-item', array( 'id' => $id ) )['title'] );
		$this->assertSame( Divi5Tree::build( \WP_Test_State::$posts[ $id ]->post_content )[0]['type'], 'divi/placeholder' );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function rejected_inputs(): array {
		return array(
			'sin título'          => array( array( 'title' => ' ' ), 'nexo_mcp_divi_library_invalid' ),
			'tipo desconocido'    => array( array( 'type' => 'page' ), 'nexo_mcp_divi_library_invalid' ),
			'campo ajeno'         => array( array( 'post_status' => 'draft' ), 'nexo_mcp_divi_library_invalid' ),
			'categoría con coma'  => array( array( 'categories' => array( 'a,b' ) ), 'nexo_mcp_divi_library_invalid' ),
			'sin nodos'           => array( array( 'nodes' => array() ), 'nexo_mcp_divi_tree_invalid' ),
			'dos secciones'       => array( array( 'nodes' => array_merge( array( array( 'type' => 'divi/section' ) ), array( array( 'type' => 'divi/section' ) ) ) ), 'nexo_mcp_divi_library_invalid' ),
			'fila donde va sección' => array( array( 'nodes' => array( array( 'type' => 'divi/row' ) ) ), 'nexo_mcp_divi_nesting_invalid' ),
			'módulo desconocido'  => array( array( 'nodes' => array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( array( 'type' => 'divi/nope' ) ) ) ) ) ) ) ) ), 'nexo_mcp_divi_module_denied' ),
		);
	}

	/**
	 * @dataProvider rejected_inputs
	 * @param array<string, mixed> $extra Campos que rompen la entrada.
	 */
	public function test_a_rejected_input_creates_nothing( array $extra, string $code ): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, $this->section_input( $extra ) ), $code );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Library_Stub_State::$create_calls );
		$this->assertSame( array(), \WP_Library_Stub_State::$term_calls );
	}

	public function test_a_module_item_needs_one_module_of_the_catalog_and_post_content_is_not_one(): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, array( 'title' => 'x', 'type' => 'module', 'nodes' => array( $this->text_node(), $this->text_node() ) ) ), 'nexo_mcp_divi_library_invalid' );
		$this->assertInstanceOf( \WP_Error::class, $this->run_tool( self::SAVE, array( 'title' => 'x', 'type' => 'module', 'nodes' => array( array( 'type' => 'divi/post-content' ) ) ) ) );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_content_is_written_without_kses_filters_so_blocks_survive(): void {
		\WP_Test_State::$kses_active = true;

		$this->assertIsArray( $this->run_tool( self::SAVE, $this->section_input() ) );
		$this->assertSame( array( false ), \WP_Test_State::$kses_during_insert, 'sin kses mientras se inserta' );
		$this->assertTrue( \WP_Test_State::$kses_active, 'y se restauran después' );
	}

	public function test_if_wordpress_cannot_insert_nothing_is_left_and_the_error_says_so(): void {
		\WP_Test_State::$fail_insert_post = true;
		$before                          = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, $this->section_input() ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_if_the_creator_throws_after_inserting_the_item_is_found_and_deleted(): void {
		\WP_Library_Stub_State::$throw_after_insert = true;
		$before                                      = $this->snapshot();

		$error = $this->assertError( $this->run_tool( self::SAVE, $this->section_input() ), 'nexo_mcp_divi_library_save_failed' );

		$this->assertStringContainsString( 'eliminó', $error->get_error_message() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_if_wordpress_stores_other_content_the_item_is_deleted(): void {
		\WP_Test_State::$mangle_insert = true;
		$before                        = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, $this->section_input() ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_if_the_terms_do_not_end_up_as_asked_the_item_is_deleted(): void {
		\WP_Library_Stub_State::$fail_terms_in = 'layout_type';
		$before                                 = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, $this->section_input() ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, $this->snapshot() );
	}
}
