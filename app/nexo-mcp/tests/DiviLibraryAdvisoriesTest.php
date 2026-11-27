<?php
/**
 * Avisos del verificador de T10 (S1, S3, S7): error de la base en la búsqueda de uso, respaldo tras un borrado fallido, caché de
 * todas las páginas afectadas, lo que Divi despega (papelera y revisiones incluidas) y el borrado de un ítem no global.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use NexoDev\NexoMCP\Builders\DiviLibraryBackup;
use NexoDev\NexoMCP\Builders\DiviLibraryUsage;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryAdvisoriesTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
		require_once __DIR__ . '/DiviLibraryGlobalStubs.php';

		$GLOBALS['wpdb']    = new \WP_Library_Wpdb_Double();
		GlobalLayout::$mode = 'detach';
		add_filter( 'pre_trash_post', array( GlobalLayout::class, 'preserve_instances_before_delete' ), 10, 2 );
	}

	private function refpage( int $id, string $status = 'publish', string $type = 'page', string $ref = '803' ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'R' . $id, 'post_content' => 'x <!-- wp:divi/global-layout {"globalModule":"' . $ref . '","blockName":"divi/text"} /-->' ) );
	}

	private function broken_db(): void {
		$GLOBALS['wpdb']             = new \WP_Library_Wpdb_Double();
		$GLOBALS['wpdb']->last_error = 'Lost connection';
	}

	// A1.
	public function test_a_database_error_in_the_scan_is_unknown_not_unused(): void {
		$this->broken_db();
		$usage = ( new DiviLibraryUsage() )->of( self::GLOBAL );

		$this->assertTrue( $usage['unknown'] );
		$this->assertSame( 0, $usage['count'] );
	}

	public function test_a_clean_scan_is_not_unknown(): void {
		$this->refpage( 120 );

		$this->assertFalse( ( new DiviLibraryUsage() )->of( self::GLOBAL )['unknown'] );
	}

	public function test_with_an_unknown_usage_a_global_is_neither_deleted_updated_nor_restored_and_nothing_changes(): void {
		$this->refpage( 120 );
		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'V2', 'confirm_global' => true ) ), 'prepara un respaldo' );
		$this->broken_db();
		$before = $this->snapshot();

		foreach ( array(
			array( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'V3', 'confirm_global' => true ) ),
			array( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ),
			array( 'restore-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ),
		) as $call ) {
			$this->assertError( $this->run_tool( $call[0], $call[1] ), 'nexo_mcp_divi_library_usage_unknown' );
			$this->assertSame( $before, $this->snapshot(), $call[0] );
		}
	}

	public function test_a_non_global_item_does_not_need_the_scan(): void {
		$this->broken_db();

		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => self::SECTION, 'title' => 'Otro' ) ) );
	}

	public function test_a_scan_that_breaks_after_the_trash_puts_the_item_back(): void {
		$this->refpage( 120 );
		add_filter(
			'pre_trash_post',
			static function ( $override ) {
				$GLOBALS['wpdb']->last_error = 'Lost connection';

				return $override;
			},
			20,
			2
		);

		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_detach_failed' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::GLOBAL ]->post_status );
	}

	// A2.
	public function test_an_aborted_trash_keeps_the_previous_backup_or_leaves_none(): void {
		$this->refpage( 120 );
		GlobalLayout::$mode = 'abort';

		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertNull( ( new DiviLibraryBackup() )->read( self::GLOBAL ), 'sin respaldo previo no queda el del borrado' );

		GlobalLayout::$mode = 'detach';
		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'V2', 'confirm_global' => true ) ) );
		$previous           = ( new DiviLibraryBackup() )->read( self::GLOBAL );
		GlobalLayout::$mode = 'abort';

		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( $previous, ( new DiviLibraryBackup() )->read( self::GLOBAL ) );
	}

	// A3.
	public function test_the_descriptions_tell_when_the_write_is_live_and_that_a_failed_delete_may_have_detached_pages(): void {
		$insert = $this->tool( 'insert-divi-library-item' )['description'];
		$delete = $this->tool( 'delete-divi-library-item' )['description'];

		$this->assertStringContainsString( 'working_copy_default', $insert );
		$this->assertStringContainsString( 'EN VIVO', $insert );
		$this->assertStringContainsString( 'puede haber despegado', $delete );
		$this->assertStringContainsString( 'no cambia nada', $delete );
		$this->assertStringContainsString( 'ítem no global', $delete );
	}

	// A4a.
	public function test_every_affected_page_gets_its_cache_cleared_not_only_the_listed_ones(): void {
		for ( $i = 0; $i < 120; $i++ ) {
			$this->refpage( 1000 + $i );
		}

		$result = $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'T2', 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertCount( DiviLibraryUsage::MAX_LISTED, $result['affected_pages']['pages'] );
		$this->assertSame( array(), array_diff( range( 1000, 1119 ), \WP_Test_State::$cleaned ) );
		$this->assertSame( 0, $result['cache_not_cleared'] );
	}

	public function test_beyond_the_bound_the_pages_whose_cache_was_not_cleared_are_reported(): void {
		for ( $i = 0; $i < DiviLibraryUsage::MAX_FLUSH + 20; $i++ ) {
			$this->refpage( 2000 + $i );
		}

		$result = $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'T2', 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 20, $result['cache_not_cleared'] );
		$this->assertStringContainsString( '20', implode( ' ', $result['warnings'] ) );
	}

	// A4b.
	public function test_a_trashed_page_or_a_revision_the_user_cannot_edit_blocks_the_delete_because_divi_would_abort_halfway(): void {
		$this->refpage( 120 );
		$this->refpage( 121, 'trash' );
		$this->refpage( 122, 'inherit', 'revision' );
		\WP_Test_State::$denied_edit_posts = array( 121, 122 );
		$before                            = $this->snapshot();

		$error = $this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_pages_forbidden' );

		$this->assertSame( array( 121, 122 ), $error->get_error_data()['pages'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_what_divi_detaches_in_the_trash_and_revisions_is_not_listed_as_an_affected_page(): void {
		$this->refpage( 120 );
		$this->refpage( 121, 'trash' );
		$this->refpage( 122, 'inherit', 'revision' );

		$result = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 120 ), array_column( $result['affected_pages']['pages'], 'id' ) );
		$this->assertSame( 0, $result['still_referenced'] );
		$this->assertStringContainsString( GlobalLayout::MARKER, \WP_Test_State::$posts[121]->post_content );
	}

	public function test_a_reference_left_in_the_trash_after_the_delete_puts_the_item_back(): void {
		$this->refpage( 121, 'trash' );
		GlobalLayout::$mode = 'noop';

		$this->assertError( $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ), 'nexo_mcp_divi_library_detach_failed' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::GLOBAL ]->post_status );
	}

	// A4c.
	public function test_deleting_a_non_global_item_does_not_scan_for_references(): void {
		$this->refpage( 120, 'publish', 'page', (string) self::SECTION );

		$result = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::SECTION ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'affected_pages', $result );
		$this->assertSame( 0, $GLOBALS['wpdb']->queries );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::SECTION ]->post_status );
	}
}
