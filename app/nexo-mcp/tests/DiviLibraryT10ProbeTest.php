<?php
/**
 * Sondas del verificador independiente de T10 (S1, S3, S7), adoptadas como pruebas: forma de las referencias, permisos en la ruta de
 * ejecución, alcance de la búsqueda de uso, error de base de datos en la búsqueda y respaldo tras un despegue fallido.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviLibraryBackup;
use NexoDev\NexoMCP\Builders\DiviLibraryUsage;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryT10ProbeTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
		require_once __DIR__ . '/DiviLibraryGlobalStubs.php';
		$GLOBALS['wpdb']    = new \WP_Library_Wpdb_Double();
		GlobalLayout::$mode = 'detach';
		add_filter( 'pre_trash_post', array( GlobalLayout::class, 'preserve_instances_before_delete' ), 10, 2 );
		\WP_Test_State::$posts[ self::PAGE ] = new \WP_Post( array( 'ID' => self::PAGE, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
	}

	private function in( int $item, array $extra = array() ): array {
		$path = $extra['path'] ?? '0.0.0.0';
		return array_merge( array( 'id' => $item, 'post_id' => self::PAGE, 'path' => $path, 'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), (string) $path )['fingerprint'] ?? '' ), 'position' => 'inside' ), $extra );
	}

	private function refpage( int $id, string $status = 'publish', string $type = 'page' ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'R' . $id, 'post_content' => 'x <!-- wp:divi/global-layout {"globalModule":"803","blockName":"divi/text"} /-->' ) );
	}

	// P1 Q4: default lookup (DiviLibrarySchema::global_block) rejects forged ids.
	public function test_p1_default_validator_rejects_forged_global_refs(): void {
		$v   = ( new DiviTreeValidator( static fn() => false, static fn() => false ) )->with_global_refs();
		$ref = static fn( $id, $b = 'divi/text' ) => array( array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => (string) $id, 'blockName' => $b ) ) );
		$this->put_item( 830, 'G draft', 'module', 'global', 'draft', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );
		$this->put_item( 831, 'G private', 'module', 'global', 'private', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );
		foreach ( array( self::PAGE, self::MODULE, 830, 831, self::TRASHED, 99999 ) as $id ) {
			$r = $v->validate_tree( $ref( $id ), 'divi/column' );
			$this->assertInstanceOf( \WP_Error::class, $r, "id $id" );
			$this->assertSame( 'nexo_mcp_divi_global_ref_invalid', $r->get_error_code(), "id $id" );
		}
		$this->assertIsArray( $v->validate_tree( $ref( self::GLOBAL ), 'divi/column' ) );
		$this->assertInstanceOf( \WP_Error::class, $v->validate_tree( $ref( self::GLOBAL, 'divi/section' ), 'divi/column' ) );
	}

	// P2 Q4: copy of an item containing a nested ref to a page is not copied and nothing changes.
	public function test_p2_copy_of_item_with_nested_ref_is_refused(): void {
		$this->put_item( 832, 'Con ref', 'section', 'non_global', 'publish', '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/global-layout {"globalModule":"100","blockName":"divi/text"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$before = $this->snapshot();
		$r      = $this->run_tool( 'insert-divi-library-item', $this->in( 832, array( 'path' => '0', 'position' => 'after' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( 'nexo_mcp_divi_library_foreign', $r->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	// P3 Q2/Q3: execute path (no permission_callback) still denies a global without edit_global_library.
	public function test_p3_execute_denies_global_ops_without_edit_global_library(): void {
		$GLOBALS['nexo_test_et_allowed'] = array( 'divi_library' => true, 'save_library' => true );
		eval( 'function et_pb_is_allowed( $c ) { return ! empty( $GLOBALS["nexo_test_et_allowed"][ $c ] ); }' );
		$this->refpage( 120 );
		$before = $this->snapshot();
		$this->assertSame( 'nexo_mcp_divi_library_denied', $this->run_tool( 'insert-divi-library-item', $this->in( self::GLOBAL, array( 'mode' => 'global' ) ) )->get_error_code() );
		$this->assertSame( 'nexo_mcp_divi_library_denied', $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'X', 'confirm_global' => true ) )->get_error_code() );
		$this->assertSame( 'nexo_mcp_divi_library_denied', $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) )->get_error_code() );
		$this->assertSame( 'nexo_mcp_divi_library_denied', $this->run_tool( 'restore-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) )->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	// P4 Q3: execute path denies inserting into a page the user cannot edit (layer 2 at the gateway).
	public function test_p4_execute_denies_page_without_edit_post(): void {
		\WP_Test_State::$denied_edit_posts = array( self::PAGE );
		$before                            = $this->snapshot();
		$r                                 = $this->run_tool( 'insert-divi-library-item', $this->in( self::GLOBAL, array( 'mode' => 'global' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertSame( $before, $this->snapshot() );
	}

	// P6 Q5: scan includes private/future/pending/working copies and TB layouts, excludes revision, nav_menu_item, trash, auto-draft.
	public function test_p6_usage_scope(): void {
		$this->refpage( 130, 'private' );
		$this->refpage( 131, 'future' );
		$this->refpage( 132, 'pending' );
		$this->refpage( 133, 'inherit', 'revision' );
		$this->refpage( 134, 'publish', 'nav_menu_item' );
		$this->refpage( 135, 'trash' );
		$this->refpage( 136, 'auto-draft' );
		$this->refpage( 137, 'publish', 'et_footer_layout' );
		$u = ( new DiviLibraryUsage( null, static fn() => true ) )->of( self::GLOBAL );
		$this->assertSame( array( 130, 131, 132, 137 ), array_column( $u['pages'], 'id' ) );
	}

	// P7 Q5/Q6: a DB error in the scan must not count as "unused" and let a used global be trashed without the detach guard.
	public function test_p7_db_error_in_usage_scan_does_not_trash_a_used_global(): void {
		$this->refpage( 140 );
		\WP_Test_State::$hooks = array(); // Divi detach filter absent.
		$GLOBALS['wpdb']        = new class() extends \WPDB_Test_Double {
			public string $posts = 'wp_posts';
			public string $last_error = 'Lost connection';
			public function esc_like( string $t ): string { return addcslashes( $t, '_%\\' ); }
			public function get_results( $sql, $output = null ): array { return array(); }
		};
		$r = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );
		$this->assertInstanceOf( \WP_Error::class, $r );
		$this->assertNotSame( 'trash', \WP_Test_State::$posts[ self::GLOBAL ]->post_status );
	}

	// P8 Q6/Q7: a failed detach keeps the previous backup (undo of the last save is not lost).
	public function test_p8_detach_failed_keeps_previous_backup(): void {
		$this->refpage( 150 );
		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'nodes' => array( $this->text_node( 'V2' ) ), 'confirm_global' => true ) ) );
		$prev               = ( new DiviLibraryBackup() )->read( self::GLOBAL );
		GlobalLayout::$mode = 'noop';
		$r                  = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );
		$this->assertSame( 'nexo_mcp_divi_library_detach_failed', $r->get_error_code() );
		$now = ( new DiviLibraryBackup() )->read( self::GLOBAL );
		$this->assertSame( $prev, $now );
	}

	// P9 Q7: rejected global save leaves posts/metas/terms byte-identical (missing confirm, bad confirm, invalid nodes with confirm).
	public function test_p9_rejected_global_inputs_byte_identical(): void {
		$this->refpage( 160 );
		$before = $this->snapshot();
		foreach ( array(
			array( 'id' => self::GLOBAL, 'title' => 'X' ),
			array( 'id' => self::GLOBAL, 'title' => 'X', 'confirm_global' => 'true' ),
			array( 'id' => self::GLOBAL, 'confirm_global' => true, 'nodes' => array( array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => '803', 'blockName' => 'divi/text' ) ) ) ),
			array( 'id' => self::GLOBAL, 'confirm_global' => true, 'type' => 'section' ),
		) as $i => $input ) {
			$this->assertInstanceOf( \WP_Error::class, $this->run_tool( 'save-divi-library-item', $input ), "case $i" );
			$this->assertSame( $before, $this->snapshot(), "case $i" );
		}
		foreach ( array( 'delete-divi-library-item', 'restore-divi-library-item' ) as $tool ) {
			$this->assertInstanceOf( \WP_Error::class, $this->run_tool( $tool, array( 'id' => self::GLOBAL ) ) );
			$this->assertSame( $before, $this->snapshot(), $tool );
		}
		foreach ( array( array( 'mode' => 'global', 'path' => '0', 'position' => 'inside' ), array( 'mode' => 'Global' ), array( 'draft' => 'yes' ), array( 'extra' => 1 ) ) as $i => $extra ) {
			$this->assertInstanceOf( \WP_Error::class, $this->run_tool( 'insert-divi-library-item', $this->in( self::GLOBAL, $extra ) ), "insert $i" );
			$this->assertSame( $before, $this->snapshot(), "insert $i" );
		}
	}

	// P10 Q5: affected_pages scan is complete past the 50-row list cap; working copy flagged.
	public function test_p10_usage_counts_past_cap(): void {
		for ( $i = 0; $i < 120; $i++ ) {
			$this->refpage( 1000 + $i );
		}
		$r = $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'T2', 'confirm_global' => true ) );
		$this->assertIsArray( $r );
		$this->assertSame( 120, $r['affected_pages']['count'] );
		$this->assertCount( 50, $r['affected_pages']['pages'] );
		$this->assertTrue( $r['affected_pages']['truncated'] );
	}
}
