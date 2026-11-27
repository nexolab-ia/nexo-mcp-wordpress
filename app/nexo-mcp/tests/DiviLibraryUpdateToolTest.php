<?php
/**
 * save-divi-library-item con id (T9): actualizar título, contenido y categorías con respaldo, lo que no se puede tocar
 * (global, papelera, tipo y alcance), módulos ajenos con replace_foreign, bloqueo y escrituras que fallan.
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
final class DiviLibraryUpdateToolTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const SAVE = 'save-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	/**
	 * @param array<string, mixed> $extra Campos.
	 * @return mixed
	 */
	private function update( int $id, array $extra ) {
		return $this->run_tool( self::SAVE, array_merge( array( 'id' => $id ), $extra ) );
	}

	public function test_a_title_only_update_keeps_the_content_and_the_terms_and_leaves_a_backup(): void {
		$content = \WP_Test_State::$posts[ self::SECTION ]->post_content;
		$terms   = \WP_Test_State::$terms[ self::SECTION ];

		$result = $this->update( self::SECTION, array( 'title' => 'Sección roja' ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['created'] );
		$this->assertSame( self::SECTION, $result['id'] );
		$this->assertSame( 'Sección roja', $result['title'] );
		$this->assertSame( 'Sección azul', $result['previous']['title'] );
		$this->assertSame( array( 'Héroes' ), $result['previous']['categories'] );
		$this->assertSame( 'save', $result['backup']['operation'] );
		$this->assertSame( 'Sección roja', \WP_Test_State::$posts[ self::SECTION ]->post_title );
		$this->assertSame( $content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
		$this->assertSame( $terms, \WP_Test_State::$terms[ self::SECTION ] );
		$this->assertSame( array(), \WP_Library_Stub_State::$create_calls, 'actualizar no crea' );

		$backup = ( new DiviLibraryBackup() )->read( self::SECTION );
		$this->assertSame( 'Sección azul', $backup['title'] );
		$this->assertSame( $content, $backup['content'] );
		$this->assertSame( 'publish', $backup['status'] );
	}

	public function test_new_nodes_replace_the_whole_content_and_the_old_one_stays_in_the_backup(): void {
		$old = \WP_Test_State::$posts[ self::SECTION ]->post_content;

		$result = $this->update( self::SECTION, array( 'nodes' => $this->section_nodes( 'Nuevo texto' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->blocks_for( $this->section_nodes( 'Nuevo texto' ) ), \WP_Test_State::$posts[ self::SECTION ]->post_content );
		$this->assertSame( 'Sección azul', \WP_Test_State::$posts[ self::SECTION ]->post_title );
		$this->assertSame( $old, ( new DiviLibraryBackup() )->read( self::SECTION )['content'] );
		$this->assertSame( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/text' ), array_column( $result['nodes'], 'type' ) );
		$this->assertSame( array( 'section' ), array_keys( \WP_Test_State::$terms[ self::SECTION ]['layout_type'] ) );
		$this->assertSame( 'section', get_post_meta( self::SECTION, '_et_pb_template_type', true ) );
	}

	public function test_categories_are_replaced_by_the_new_list_and_an_empty_list_clears_them(): void {
		$this->assertIsArray( $this->update( self::SECTION, array( 'categories' => array( 'Pie', 'Cabeceras' ) ) ) );
		$this->assertSame( array( 'Pie', 'Cabeceras' ), array_keys( \WP_Test_State::$terms[ self::SECTION ]['layout_category'] ) );

		$this->assertIsArray( $this->update( self::SECTION, array( 'categories' => array() ) ) );
		$this->assertSame( array(), \WP_Test_State::$terms[ self::SECTION ]['layout_category'] );
		$this->assertSame( array( 'Pie', 'Cabeceras' ), ( new DiviLibraryBackup() )->read( self::SECTION )['categories'] );
	}

	public function test_a_module_update_follows_the_new_module_type(): void {
		$this->assertIsArray( $this->update( self::MODULE, array( 'nodes' => array( array( 'type' => 'divi/divider' ) ) ) ) );
		$this->assertSame( 'divi/divider', get_post_meta( self::MODULE, '_et_pb_module_type', true ) );
		$this->assertSame( 'divi/text', ( new DiviLibraryBackup() )->read( self::MODULE )['module_type'] );
	}

	public function test_type_and_scope_cannot_change_and_nothing_changes(): void {
		$before = $this->snapshot();

		foreach ( array( 'type' => 'row', 'scope' => 'global' ) as $key => $value ) {
			$error = $this->assertError( $this->update( self::SECTION, array( 'title' => 'x', $key => $value ) ), 'nexo_mcp_divi_library_invalid' );

			$this->assertSame( $key, $error->get_error_data()['path'] );
		}

		$this->assertError( $this->update( self::SECTION, array() ), 'nexo_mcp_divi_library_invalid' );
		$this->assertError( $this->update( self::SECTION, array( 'nodes' => array( array( 'type' => 'divi/row' ) ) ) ), 'nexo_mcp_divi_nesting_invalid' );
		$this->assertError( $this->update( self::SECTION, array( 'nodes' => $this->section_nodes(), 'replace_foreign' => 'si' ) ), 'nexo_mcp_divi_library_invalid' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_global_item_is_not_updated(): void {
		$before = $this->snapshot();

		$this->assertError( $this->update( self::GLOBAL, array( 'title' => 'Otro' ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertError( $this->update( self::GLOBAL, array( 'nodes' => array( $this->text_node( 'x' ) ) ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_trashed_item_an_auto_draft_and_a_page_are_not_updated(): void {
		$before = $this->snapshot();

		$this->assertError( $this->update( self::TRASHED, array( 'title' => 'x' ) ), 'nexo_mcp_divi_library_trashed' );
		$this->assertError( $this->update( self::AUTO_DRAFT, array( 'title' => 'x' ) ), 'nexo_mcp_divi_library_not_found' );
		$this->assertError( $this->update( self::PAGE, array( 'title' => 'x' ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->update( 99999, array( 'title' => 'x' ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_garbage_id_is_rejected_before_anything_is_written(): void {
		$before = $this->snapshot();

		foreach ( array( 'x', 0, -3, 1.5, null ) as $id ) {
			$error = $this->assertError( $this->run_tool( self::SAVE, array( 'id' => $id, 'title' => 'x' ) ), 'nexo_mcp_divi_library_invalid' );

			$this->assertSame( 'id', $error->get_error_data()['path'] );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_replacing_content_the_connector_cannot_represent_needs_replace_foreign(): void {
		$before = $this->snapshot();

		$error = $this->assertError( $this->update( self::FOREIGN, array( 'nodes' => $this->section_nodes( 'Nuevo' ) ) ), 'nexo_mcp_divi_library_foreign' );

		$this->assertSame( array( 'divi/fullwidth-menu' ), $error->get_error_data()['foreign'] );
		$this->assertSame( $before, $this->snapshot(), 'ni siquiera el respaldo' );
	}

	public function test_replace_foreign_replaces_and_the_old_content_stays_in_the_backup(): void {
		$old = \WP_Test_State::$posts[ self::FOREIGN ]->post_content;

		$result = $this->update( self::FOREIGN, array( 'nodes' => $this->section_nodes( 'Nuevo' ), 'replace_foreign' => true ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( $old, ( new DiviLibraryBackup() )->read( self::FOREIGN )['content'] );
		$this->assertSame( $this->blocks_for( $this->section_nodes( 'Nuevo' ) ), \WP_Test_State::$posts[ self::FOREIGN ]->post_content );
	}

	public function test_a_title_update_on_an_item_with_foreign_content_does_not_need_replace_foreign(): void {
		$content = \WP_Test_State::$posts[ self::FOREIGN ]->post_content;

		$this->assertIsArray( $this->update( self::FOREIGN, array( 'title' => 'Con menú 2' ) ) );
		$this->assertSame( $content, \WP_Test_State::$posts[ self::FOREIGN ]->post_content );
	}

	public function test_without_a_backup_nothing_is_written(): void {
		\WP_Test_State::$json_fail_in = 'save';
		$before                       = $this->snapshot();

		$this->assertError( $this->update( self::SECTION, array( 'title' => 'Nuevo' ) ), 'nexo_mcp_divi_library_backup_failed' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_if_the_lock_is_lost_before_writing_nothing_changes(): void {
		$this->lose_lock_after_acquiring( self::SECTION );
		$title = \WP_Test_State::$posts[ self::SECTION ]->post_title;

		$this->assertError( $this->update( self::SECTION, array( 'title' => 'Nuevo' ) ), 'nexo_mcp_busy' );
		$this->assertSame( $title, \WP_Test_State::$posts[ self::SECTION ]->post_title );
	}

	public function test_if_the_write_fails_the_item_stays_as_it_was_and_the_error_says_so(): void {
		\WP_Test_State::$fail_update_post = true;
		$post                             = clone \WP_Test_State::$posts[ self::SECTION ];
		$terms                            = \WP_Test_State::$terms[ self::SECTION ];

		$error = $this->assertError( $this->update( self::SECTION, array( 'title' => 'Nuevo', 'nodes' => $this->section_nodes( 'x' ) ) ), 'nexo_mcp_divi_library_save_failed' );

		$this->assertEquals( $post, \WP_Test_State::$posts[ self::SECTION ] );
		$this->assertSame( $terms, \WP_Test_State::$terms[ self::SECTION ] );
		$this->assertStringContainsString( 'volvió a su estado anterior', $error->get_error_message() );
	}

	public function test_if_the_terms_fail_after_the_content_was_written_the_item_is_put_back(): void {
		\WP_Library_Stub_State::$fail_terms_in = 'layout_category';
		$post                                  = clone \WP_Test_State::$posts[ self::SECTION ];

		$this->assertError( $this->update( self::SECTION, array( 'title' => 'Nuevo', 'nodes' => $this->section_nodes( 'x' ), 'categories' => array( 'Pie' ) ) ), 'nexo_mcp_divi_library_save_failed' );

		$this->assertSame( $post->post_title, \WP_Test_State::$posts[ self::SECTION ]->post_title );
		$this->assertSame( $post->post_content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
	}

	public function test_the_other_builder_tools_still_reject_library_items(): void {
		$result = $this->run_tool( 'get-page-structure', array( 'id' => self::SECTION ) );

		$this->assertError( $result, 'nexo_mcp_divi_post_type_denied' );
	}
}
