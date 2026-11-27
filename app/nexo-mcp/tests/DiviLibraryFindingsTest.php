<?php
/**
 * Hallazgos de la verificación de T9: la regla de «global» de Divi (con su alternativa de Divi 4), un restaurar que falla no
 * destruye el respaldo, categorías que WordPress reutiliza o escapa, términos que no se dejan de sobra y metas de Divi 5.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLibraryBackup;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryFindingsTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const D4_GLOBAL = 820;
	private const LEGACY    = 821;
	private const SAVE      = 'save-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	/**
	 * Alternativa de Divi para globales importados de Divi 4: módulo con `_et_pb_module_type` y `_et_pb_excluded_global_options`.
	 */
	private function put_d4_global(): void {
		$this->put_item( self::D4_GLOBAL, 'Global D4 importado', 'module', 'non_global', 'publish', null, array(), array( '_et_pb_module_type' => 'divi/text', '_et_pb_excluded_global_options' => '[]' ) );
		unset( \WP_Test_State::$terms[ self::D4_GLOBAL ]['scope'] );
	}

	public function test_a_divi_4_global_without_the_scope_term_is_not_updated(): void {
		$this->put_d4_global();
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::D4_GLOBAL, 'title' => 'X' ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_divi_4_global_is_not_trashed_nor_restored(): void {
		$this->put_d4_global();
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::D4_GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::D4_GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_get_and_list_report_a_divi_4_global_as_global(): void {
		$this->put_d4_global();

		$got  = $this->run_tool( 'get-divi-library-item', array( 'id' => self::D4_GLOBAL ) );
		$rows = array_column( $this->run_tool( 'list-divi-library', array( 'scope' => 'global' ) )['items'], 'scope', 'id' );

		$this->assertTrue( $got['global'] );
		$this->assertSame( 'global', $got['scope'] );
		$this->assertSame( 'global', $rows[ self::D4_GLOBAL ] );
		$this->assertNotContains( self::D4_GLOBAL, array_column( $this->run_tool( 'list-divi-library', array( 'scope' => 'non_global' ) )['items'], 'id' ) );
	}

	public function test_a_failed_restore_keeps_the_backup_it_was_restoring_from(): void {
		$old = \WP_Test_State::$posts[ self::SECTION ]->post_content;
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'nodes' => $this->section_nodes( 'Cambiado' ) ) ) );
		$backup = ( new DiviLibraryBackup() )->read( self::SECTION );
		\WP_Test_State::$fail_update_post = true;

		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $backup, ( new DiviLibraryBackup() )->read( self::SECTION ) );
		$this->assertSame( $old, ( new DiviLibraryBackup() )->read( self::SECTION )['content'] );
	}

	public function test_a_restore_that_loses_the_lock_keeps_the_backup_too(): void {
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro' ) ) );
		$backup = ( new DiviLibraryBackup() )->read( self::SECTION );
		$this->lose_lock_after_acquiring( self::SECTION );

		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_busy' );
		$this->assertSame( $backup, ( new DiviLibraryBackup() )->read( self::SECTION ) );
	}

	public function test_categories_that_wordpress_reuses_by_slug_or_escapes_do_not_roll_the_save_back(): void {
		// WordPress reutiliza «Header» para «header» y guarda «A & B» como «A &amp; B» (comprobado en el docker).
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Header' => 90 );

		$result = $this->run_tool( self::SAVE, array( 'title' => 'Con categorías', 'type' => 'section', 'nodes' => $this->section_nodes(), 'categories' => array( 'header', 'A & B' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'Header', 'A & B' ), $result['categories'], 'sin escapar' );

		$update = $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'categories' => array( 'header', 'A & B' ) ) );

		$this->assertIsArray( $update );
	}

	public function test_a_rolled_back_create_does_not_leave_the_new_categories(): void {
		\WP_Test_State::$mangle_insert = true;
		$before                        = \WP_Library_Stub_State::registry();

		$this->assertError( $this->run_tool( self::SAVE, array( 'title' => 'X', 'type' => 'section', 'nodes' => $this->section_nodes(), 'categories' => array( 'Nueva' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, \WP_Library_Stub_State::registry() );
	}

	public function test_a_failed_update_does_not_leave_the_new_categories(): void {
		$before = \WP_Library_Stub_State::registry();
		\WP_Library_Stub_State::$leak_terms_in = 'layout_category';

		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro', 'categories' => array( 'Nueva' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, \WP_Library_Stub_State::registry() );
		$this->assertSame( array( 'Héroes' ), array_keys( \WP_Test_State::$terms[ self::SECTION ]['layout_category'] ) );
	}

	public function test_a_category_that_already_existed_is_not_deleted_by_a_rollback(): void {
		\WP_Test_State::$mangle_insert = true;
		$before                        = \WP_Library_Stub_State::registry();

		$this->assertError( $this->run_tool( self::SAVE, array( 'title' => 'X', 'type' => 'section', 'nodes' => $this->section_nodes(), 'categories' => array( 'Héroes' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $before, \WP_Library_Stub_State::registry() );
		$this->assertArrayHasKey( 'Héroes', $before['layout_category'] ?? array() );
	}

	private function put_legacy(): void {
		$this->put_item( self::LEGACY, 'D4', 'section', 'non_global', 'publish', '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]' );
		delete_post_meta( self::LEGACY, '_et_pb_use_divi_5' );
	}

	public function test_replacing_a_divi_4_item_marks_it_as_divi_5(): void {
		$this->put_legacy();

		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::LEGACY, 'nodes' => $this->section_nodes(), 'replace_foreign' => true ) ) );
		$this->assertSame( 'on', get_post_meta( self::LEGACY, '_et_pb_use_divi_5', true ) );
	}

	public function test_restoring_a_divi_4_item_takes_the_divi_5_mark_away_again(): void {
		$this->put_legacy();
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::LEGACY, 'nodes' => $this->section_nodes(), 'replace_foreign' => true ) ) );

		$this->assertIsArray( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::LEGACY ) ) );
		$this->assertSame( '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]', \WP_Test_State::$posts[ self::LEGACY ]->post_content );
		$this->assertSame( '', get_post_meta( self::LEGACY, '_et_pb_use_divi_5', true ) );
	}

	public function test_a_failed_update_puts_the_module_type_back_even_when_there_was_none(): void {
		$this->put_item( 822, 'Módulo viejo', 'module' );
		\WP_Library_Stub_State::$lose_terms_in = 'layout_category';

		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => 822, 'nodes' => array( array( 'type' => 'divi/divider' ) ), 'categories' => array( 'Pie' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( '', get_post_meta( 822, '_et_pb_module_type', true ) );
	}

	public function test_a_failed_update_puts_back_the_old_module_type(): void {
		\WP_Library_Stub_State::$lose_terms_in = 'layout_category';

		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::MODULE, 'nodes' => array( array( 'type' => 'divi/divider' ) ), 'categories' => array( 'Pie' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( 'divi/text', get_post_meta( self::MODULE, '_et_pb_module_type', true ) );
	}
}
