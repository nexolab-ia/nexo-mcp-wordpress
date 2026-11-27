<?php
/**
 * Hallazgos de la review nativa de T9: borrar pide también `delete_post`, un deshacer sólo borra las categorías que creó él
 * mismo, y un módulo no global recién creado no se confunde con uno global.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryReviewFindingsTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const SAVE = 'save-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	public function test_delete_is_denied_without_delete_post_even_with_edit_post_and_changes_nothing(): void {
		\WP_Test_State::$caps['delete_post'] = false;
		$before                              = $this->snapshot();

		$this->assertNotTrue( $this->tool( 'delete-divi-library-item' )['permission_callback']( array( 'id' => self::SECTION ) ) );
		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertTrue( $this->tool( self::SAVE )['permission_callback']( array( 'id' => self::SECTION ) ), 'editar sigue permitido' );
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Sigue editable' ) ) );
	}

	public function test_restoring_from_the_trash_needs_delete_post_like_untrash_but_restoring_a_live_item_does_not(): void {
		$this->assertIsArray( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::SECTION ) ) );
		\WP_Test_State::$caps['delete_post'] = false;
		$before                              = $this->snapshot();

		$this->assertError( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::SECTION ]->post_status );

		\WP_Test_State::$caps['delete_post'] = true;
		$this->assertIsArray( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ) );
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro' ) ) );

		\WP_Test_State::$caps['delete_post'] = false;
		$this->assertIsArray( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::SECTION ) ), 'un ítem vivo es una edición' );
	}

	public function test_a_rollback_never_deletes_an_existing_empty_category_even_if_it_cannot_read_the_terms(): void {
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Vacía' => 91 );
		\WP_Library_Stub_State::$fail_get_terms           = 1;
		\WP_Test_State::$mangle_insert                    = true;

		$this->assertError( $this->run_tool( self::SAVE, array( 'title' => 'X', 'type' => 'section', 'nodes' => $this->section_nodes(), 'categories' => array( 'Nueva' ) ) ), 'nexo_mcp_divi_library_save_failed' );
		$registry = \WP_Library_Stub_State::registry()['layout_category'];

		$this->assertArrayHasKey( 'Vacía', $registry );
		$this->assertArrayHasKey( 'Nueva', $registry, 'sin poder leer los términos no se borra ni la que creó esta llamada' );
		$this->assertSame( 0, \WP_Library_Stub_State::$fail_get_terms, 'el fallo de get_terms() se consumió: el camino seguro se recorrió de verdad' );
	}

	public function test_a_rollback_keeps_an_existing_empty_category_that_the_write_asked_for(): void {
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Vacía' => 91 );
		\WP_Test_State::$mangle_insert                    = true;

		$this->assertError( $this->run_tool( self::SAVE, array( 'title' => 'X', 'type' => 'section', 'nodes' => $this->section_nodes(), 'categories' => array( 'vacía', 'Nueva' ) ) ), 'nexo_mcp_divi_library_save_failed' );

		$registry = \WP_Library_Stub_State::registry()['layout_category'];
		$this->assertArrayHasKey( 'Vacía', $registry );
		$this->assertArrayNotHasKey( 'Nueva', $registry, 'la que creó esta llamada sí se quita' );
	}

	public function test_a_rollback_keeps_a_category_another_save_created_meanwhile(): void {
		\WP_Library_Stub_State::$leak_terms_in = 'layout_category';
		\WP_Library_Stub_State::$during_terms  = static function (): void {
			\WP_Library_Stub_State::$known['layout_category']['Concurrente'] = 777;
		};

		$this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'categories' => array( 'Nueva' ) ) ), 'nexo_mcp_divi_library_save_failed' );

		$registry = \WP_Library_Stub_State::registry()['layout_category'];
		$this->assertArrayHasKey( 'Concurrente', $registry );
		$this->assertArrayNotHasKey( 'Nueva', $registry );
		$this->assertArrayHasKey( 'Héroes', $registry );
	}

	public function test_a_non_global_module_is_created_reads_back_as_non_global_and_can_be_renamed(): void {
		$result = $this->run_tool( self::SAVE, array( 'title' => 'Módulo local', 'type' => 'module', 'scope' => 'non_global', 'nodes' => array( $this->text_node( 'Hola' ) ) ) );
		$id     = max( array_keys( \WP_Test_State::$posts ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'non_global', $result['scope'] );
		$this->assertSame( 'divi/text', get_post_meta( $id, '_et_pb_module_type', true ) );
		$this->assertSame( '', get_post_meta( $id, '_et_pb_excluded_global_options', true ), 'sin esta meta Divi no lo toma por global' );
		$this->assertFalse( $this->run_tool( 'get-divi-library-item', array( 'id' => $id ) )['global'] );
		$this->assertContains( $id, array_column( $this->run_tool( 'list-divi-library', array( 'scope' => 'non_global' ) )['items'], 'id' ) );

		$renamed = $this->run_tool( self::SAVE, array( 'id' => $id, 'title' => 'Módulo local 2' ) );

		$this->assertIsArray( $renamed );
		$this->assertSame( 'Módulo local 2', \WP_Test_State::$posts[ $id ]->post_title );
	}
}
