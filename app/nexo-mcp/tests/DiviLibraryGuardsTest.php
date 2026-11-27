<?php
/**
 * Guardas de T9 que la verificación comprobó con sondas: páginas con una meta de respaldo, ids que no son enteros, contenido
 * de Divi 4, ítems globales con respaldo, formas incorrectas por tipo y un restaurar fallido desde la papelera.
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
final class DiviLibraryGuardsTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	public function test_the_tools_refuse_a_page_that_carries_a_library_backup_meta(): void {
		update_post_meta( self::PAGE, DiviLibraryBackup::META_KEY, wp_json_encode( array( 'operation' => 'save', 'captured_at' => 'x', 'title' => 'T', 'status' => 'publish', 'content' => 'BAD', 'categories' => array(), 'module_type' => '' ) ) );
		$before = $this->snapshot();
		foreach ( array( 'restore-divi-library-item', 'delete-divi-library-item', 'get-divi-library-item' ) as $tool ) {
			$this->assertInstanceOf( \WP_Error::class, $this->run_tool( $tool, array( 'id' => self::PAGE ) ), $tool );
		}
		$this->assertInstanceOf( \WP_Error::class, $this->run_tool( 'save-divi-library-item', array( 'id' => self::PAGE, 'title' => 'X' ) ) );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_non_integer_ids_write_nothing(): void {
		$before = $this->snapshot();
		foreach ( array( '800', 800.0, 0, -800, true ) as $id ) {
			$r = $this->run_tool( 'save-divi-library-item', array( 'id' => $id, 'title' => 'X', 'type' => 'section', 'nodes' => $this->section_nodes() ) );
			$this->assertInstanceOf( \WP_Error::class, $r, var_export( $id, true ) );
			$this->assertSame( array(), \WP_Library_Stub_State::$create_calls, 'no create for id ' . var_export( $id, true ) );
		}
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_legacy_divi_4_content_needs_replace_foreign(): void {
		$this->put_item( 821, 'D4', 'section', 'non_global', 'publish', '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]' );
		$before = $this->snapshot();
		$this->assertError( $this->run_tool( 'save-divi-library-item', array( 'id' => 821, 'nodes' => $this->section_nodes() ) ), 'nexo_mcp_divi_library_foreign' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => 821, 'title' => 'D4 renamed' ) ) );
		$this->assertSame( '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]', \WP_Test_State::$posts[821]->post_content );
	}

	public function test_a_global_item_cannot_be_restored_trashed_or_updated_even_with_a_backup(): void {
		update_post_meta( self::GLOBAL, DiviLibraryBackup::META_KEY, wp_json_encode( array( 'operation' => 'save', 'captured_at' => 'x', 'title' => 'T', 'status' => 'publish', 'content' => 'BAD', 'categories' => array(), 'module_type' => '' ) ) );
		$before = $this->snapshot();
		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertError( $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'categories' => array() ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_wrong_shape_for_the_type_creates_nothing(): void {
		$before = $this->snapshot();
		$cases = array(
			array( 'module', $this->section_nodes() ),
			array( 'row', $this->section_nodes() ),
			array( 'section', $this->row_nodes() ),
			array( 'module', array( $this->text_node(), $this->text_node() ) ),
			array( 'section', array_merge( $this->section_nodes(), $this->section_nodes() ) ),
			array( 'module', array( array( 'type' => 'divi/post-content' ) ) ),
			array( 'layout', array() ),
		);
		foreach ( $cases as $i => $c ) {
			$this->assertInstanceOf( \WP_Error::class, $this->run_tool( 'save-divi-library-item', array( 'title' => 'X', 'type' => $c[0], 'nodes' => $c[1] ) ), 'case ' . $i );
		}
		$this->assertSame( array(), \WP_Library_Stub_State::$create_calls );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_failed_restore_of_a_trashed_item_leaves_it_in_the_trash(): void {
		$this->assertIsArray( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::SECTION ) ) );
		\WP_Test_State::$fail_update_post = true;

		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::SECTION ]->post_status );
	}
}
