<?php
/**
 * Ítems globales de la biblioteca (T10, S1/S5/S7): actualizar, borrar y restaurar sólo con `confirm_global: true`, con la lista de
 * páginas afectadas, el respaldo y, al borrar, las instancias despegadas por Divi (las páginas conservan su contenido).
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use NexoDev\NexoMCP\Builders\DiviLibraryBackup;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryGlobalToolTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const SAVE    = 'save-divi-library-item';
	private const DELETE  = 'delete-divi-library-item';
	private const RESTORE = 'restore-divi-library-item';

	private const COPY    = 101;
	private const OTHER   = 102;
	private const GONE    = 103;
	private const PRIVATE = 104;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
		require_once __DIR__ . '/DiviLibraryGlobalStubs.php';

		$GLOBALS['wpdb'] = new \WP_Library_Wpdb_Double();
		GlobalLayout::$mode = 'detach';
		add_filter( 'pre_trash_post', array( GlobalLayout::class, 'preserve_instances_before_delete' ), 10, 2 );

		$ref = static fn( $id ): string => '<!-- wp:divi/global-layout {"globalModule":' . ( is_int( $id ) ? $id : '"' . $id . '"' ) . ',"blockName":"divi/text"} /-->';
		$put = static function ( int $id, string $type, string $status, string $content ): void {
			\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Contenido ' . $id, 'post_content' => $content ) );
		};

		$put( self::PAGE, 'page', 'publish', 'Antes ' . $ref( '803' ) );
		$put( self::COPY, 'page', 'draft', 'Copia ' . $ref( '803' ) );
		$put( self::OTHER, 'post', 'publish', 'Otro ' . $ref( '8030' ) );
		$put( self::GONE, 'post', 'trash', 'Borrada ' . $ref( '803' ) );
		$put( self::PRIVATE, 'post', 'private', 'Privada ' . $ref( 803 ) );
		update_post_meta( self::COPY, '_nexo_mcp_draft_of', self::PAGE );
	}

	/**
	 * @param array<string, mixed> $extra Campos.
	 * @return mixed
	 */
	private function global_save( array $extra ) {
		return $this->run_tool( self::SAVE, array_merge( array( 'id' => self::GLOBAL ), $extra ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function contents(): array {
		return array_map( static fn( $post ): string => (string) $post->post_content, array_filter( \WP_Test_State::$posts, static fn( $post ): bool => 'et_pb_layout' !== $post->post_type ) );
	}

	public function test_updating_a_global_item_with_confirm_writes_keeps_the_backup_and_lists_the_affected_pages(): void {
		$pages = $this->contents();
		$old   = \WP_Test_State::$posts[ self::GLOBAL ]->post_content;

		$result = $this->global_save( array( 'title' => 'Global nuevo', 'nodes' => array( $this->text_node( 'Nuevo' ) ), 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Global nuevo', \WP_Test_State::$posts[ self::GLOBAL ]->post_title );
		$this->assertNotSame( $old, \WP_Test_State::$posts[ self::GLOBAL ]->post_content );
		$this->assertSame( $old, ( new DiviLibraryBackup() )->read( self::GLOBAL )['content'] );
		$this->assertSame( 'save', $result['backup']['operation'] );
		$this->assertSame( 3, $result['affected_pages']['count'] );
		$this->assertSame( array( self::PAGE, self::COPY, self::PRIVATE ), array_column( $result['affected_pages']['pages'], 'id' ), 'publicada, copia de trabajo y privada; no la papelera ni el id parecido' );
		$this->assertSame( self::PAGE, $result['affected_pages']['pages'][1]['working_copy_of'] );
		$this->assertFalse( $result['affected_pages']['truncated'] );
		$this->assertSame( $pages, $this->contents(), 'las páginas no se tocan: la sincronización se hace al renderizar' );
		$this->assertContains( self::PAGE, \WP_Test_State::$cleaned, 'se renueva la caché de las páginas afectadas' );
		$this->assertNotSame( array(), $result['warnings'] );
	}

	public function test_updating_a_global_item_without_confirm_or_with_a_non_boolean_changes_nothing(): void {
		$before = $this->snapshot();

		foreach ( array( array(), array( 'confirm_global' => false ) ) as $extra ) {
			$this->assertError( $this->global_save( array_merge( array( 'title' => 'Otro' ), $extra ) ), 'nexo_mcp_divi_library_global_blocked' );
		}

		foreach ( array( 'si', 1, null ) as $value ) {
			$this->assertError( $this->global_save( array( 'title' => 'Otro', 'confirm_global' => $value ) ), 'nexo_mcp_divi_library_invalid' );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_confirm_global_must_be_a_boolean_on_an_item_that_is_not_global_and_changes_nothing_else_there(): void {
		$before = $this->snapshot();

		$error = $this->assertError( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'x', 'confirm_global' => 'si' ) ), 'nexo_mcp_divi_library_invalid' );
		$this->assertSame( 'confirm_global', $error->get_error_data()['path'] );
		$this->assertSame( $before, $this->snapshot() );

		$result = $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Sección roja', 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'affected_pages', $result, 'un ítem no global no tiene páginas afectadas' );
	}

	public function test_the_save_schema_documents_confirm_global(): void {
		$tool = $this->tool( self::SAVE );

		$this->assertSame( 'boolean', $tool['input_schema']['properties']['confirm_global']['type'] );
		$this->assertStringContainsString( 'confirm_global', $tool['description'] );
		$this->assertStringContainsString( 'affected_pages', $tool['description'] );
	}

	public function test_deleting_a_global_item_with_confirm_detaches_the_instances_and_the_pages_keep_their_content(): void {
		$result = $this->run_tool( self::DELETE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::GLOBAL ]->post_status );
		$this->assertSame( 'delete', $result['backup']['operation'] );
		$this->assertSame( array( self::PAGE, self::COPY, self::PRIVATE ), array_column( $result['affected_pages']['pages'], 'id' ) );

		foreach ( array( self::PAGE, self::COPY, self::PRIVATE ) as $id ) {
			$this->assertStringContainsString( GlobalLayout::MARKER, \WP_Test_State::$posts[ $id ]->post_content, 'la página ' . $id . ' conserva el contenido del ítem' );
			$this->assertStringNotContainsString( 'globalModule', \WP_Test_State::$posts[ $id ]->post_content );
		}

		$this->assertStringContainsString( '"globalModule":"8030"', \WP_Test_State::$posts[ self::OTHER ]->post_content, 'lo que usa otro ítem no se toca' );
		$this->assertStringContainsString( GlobalLayout::MARKER, \WP_Test_State::$posts[ self::GONE ]->post_content, 'Divi despega también lo que está en la papelera' );
		$this->assertSame( 0, $result['still_referenced'] );
		$this->assertStringContainsString( 'restore-divi-library-item', implode( ' ', $result['warnings'] ) );
	}

	public function test_deleting_a_global_item_is_refused_when_a_page_cannot_be_edited_and_nothing_changes(): void {
		\WP_Test_State::$denied_edit_posts = array( self::COPY );
		$before                            = $this->snapshot();

		$error = $this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_pages_forbidden' );

		$this->assertSame( array( self::COPY ), $error->get_error_data()['pages'] );
		$this->assertSame( $before, $this->snapshot(), 'ni páginas, ni ítem, ni respaldo' );
	}

	public function test_deleting_a_used_global_item_is_refused_when_divi_would_not_detach_it(): void {
		\WP_Test_State::$hooks = array();
		$before                = $this->snapshot();

		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_detach_unavailable' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_an_unused_global_item_is_deleted_even_if_divi_would_not_detach_anything(): void {
		\WP_Test_State::$hooks = array();
		$this->put_item( 810, 'Global sin uso', 'module', 'global', 'publish', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );

		$result = $this->run_tool( self::DELETE, array( 'id' => 810, 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['affected_pages']['count'] );
		$this->assertSame( 'trash', \WP_Test_State::$posts[810]->post_status );
	}

	public function test_if_divi_aborts_the_trash_the_item_and_the_pages_stay_as_they_were(): void {
		GlobalLayout::$mode = 'abort';
		$pages              = $this->contents();

		$error = $this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_save_failed' );

		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::GLOBAL ]->post_status );
		$this->assertSame( $pages, $this->contents() );
		$this->assertStringContainsString( 'despeg', $error->get_error_message() );
	}

	public function test_if_instances_remain_after_the_trash_the_item_is_put_back(): void {
		GlobalLayout::$mode = 'noop';

		$error = $this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_detach_failed' );

		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::GLOBAL ]->post_status, 'el ítem vuelve' );
		$this->assertSame( array( self::PAGE, self::COPY, self::PRIVATE ), $error->get_error_data()['pages'] );
	}

	public function test_restoring_a_global_item_needs_confirm_and_lists_the_affected_pages(): void {
		$this->assertIsArray( $this->global_save( array( 'nodes' => array( $this->text_node( 'V2' ) ), 'confirm_global' => true ) ) );
		$v2 = \WP_Test_State::$posts[ self::GLOBAL ]->post_content;
		$v1 = ( new DiviLibraryBackup() )->read( self::GLOBAL )['content'];
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::RESTORE, array( 'id' => self::GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
		$this->assertSame( $before, $this->snapshot() );

		$result = $this->run_tool( self::RESTORE, array( 'id' => self::GLOBAL, 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( $v1, \WP_Test_State::$posts[ self::GLOBAL ]->post_content );
		$this->assertSame( $v2, ( new DiviLibraryBackup() )->read( self::GLOBAL )['content'], 'restaurar de nuevo alterna' );
		$this->assertSame( 3, $result['affected_pages']['count'] );
	}

	public function test_delete_and_restore_schemas_take_confirm_global(): void {
		foreach ( array( self::DELETE, self::RESTORE ) as $name ) {
			$tool = $this->tool( $name );

			$this->assertSame( array( 'id', 'confirm_global' ), array_keys( $tool['input_schema']['properties'] ) );
			$this->assertSame( array( 'id' ), $tool['input_schema']['required'] );
			$this->assertStringContainsString( 'confirm_global', $tool['description'] );
		}

		$this->assertStringContainsString( 'nexo_mcp_divi_library_pages_forbidden', $this->tool( self::DELETE )['description'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_library_detach_failed', $this->tool( self::DELETE )['description'] );
	}

	public function test_a_garbage_confirm_global_on_delete_and_restore_is_rejected_before_anything_changes(): void {
		$before = $this->snapshot();

		foreach ( array( self::DELETE, self::RESTORE ) as $name ) {
			foreach ( array( 'si', 1, null, array() ) as $value ) {
				$error = $this->assertError( $this->run_tool( $name, array( 'id' => self::SECTION, 'confirm_global' => $value ) ), 'nexo_mcp_divi_library_invalid' );

				$this->assertSame( 'confirm_global', $error->get_error_data()['path'] );
			}
		}

		$this->assertSame( $before, $this->snapshot() );
	}
}
