<?php
/**
 * delete-divi-library-item y restore-divi-library-item (T9): papelera con respaldo, restauración de un cambio y de un
 * borrado, lo que no se toca (global, papelera desactivada, sin respaldo) y escrituras que fallan.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLibraryBackup;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryDeleteRestoreToolTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const DELETE  = 'delete-divi-library-item';
	private const RESTORE = 'restore-divi-library-item';
	private const SAVE    = 'save-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	public function test_both_tools_are_registered_as_destructive_write_tools_with_a_closed_schema(): void {
		foreach ( array( self::DELETE, self::RESTORE ) as $name ) {
			$tool = $this->tool( $name );

			$this->assertContains( 'nexo-mcp/' . $name, ExtendedAbilityRegistrar::ABILITIES );
			$this->assertFalse( $tool['meta']['annotations']['readonly'] );
			$this->assertTrue( $tool['meta']['annotations']['destructive'], $name );
			$this->assertFalse( $tool['input_schema']['additionalProperties'] );
			$this->assertSame( array( 'id' ), array_keys( $tool['input_schema']['properties'] ) );
			$this->assertSame( array( 'id' ), $tool['input_schema']['required'] );

			foreach ( array( 'mcp:divi_library', 'nexo_mcp_divi_library_denied', 'nexo_mcp_divi5_required', 'nexo_mcp_divi_library_global_blocked', 'nexo_mcp_divi_library_backup_failed', 'nexo_mcp_divi_library_save_failed', 'nexo_mcp_busy', '_nexo_mcp_library_backup', 'papelera' ) as $needle ) {
				$this->assertStringContainsString( $needle, $tool['description'], $name . ' ' . $needle );
			}
		}

		$this->assertStringContainsString( 'nexo_mcp_divi_library_trash_disabled', $this->tool( self::DELETE )['description'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_library_no_backup', $this->tool( self::RESTORE )['description'] );
	}

	public function test_without_the_library_permission_nothing_is_deleted_or_restored(): void {
		$before = $this->snapshot();

		foreach ( array( self::DELETE, self::RESTORE ) as $name ) {
			$this->assertNotTrue( $this->tool( $name, array( self::SWITCH => false ) )['permission_callback']( array( 'id' => self::SECTION ) ) );
			$this->assertError( $this->run_tool( $name, array( 'id' => self::SECTION ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
			$this->assertError( $this->run_tool( $name, array( 'id' => self::PAGE ) ), 'nexo_mcp_divi_library_denied' );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_delete_sends_the_item_to_the_trash_with_a_backup_and_keeps_its_terms_and_metas(): void {
		$post  = clone \WP_Test_State::$posts[ self::SECTION ];
		$terms = \WP_Test_State::$terms[ self::SECTION ];

		$result = $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::SECTION, $result['id'] );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'trash', $result['status'] );
		$this->assertSame( 'Sección azul', $result['title'] );
		$this->assertSame( 'delete', $result['backup']['operation'] );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::SECTION ]->post_status );
		$this->assertSame( $post->post_content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
		$this->assertSame( $terms, \WP_Test_State::$terms[ self::SECTION ] );
		$this->assertSame( 'section', get_post_meta( self::SECTION, '_et_pb_template_type', true ) );

		$backup = ( new DiviLibraryBackup() )->read( self::SECTION );
		$this->assertSame( 'publish', $backup['status'] );
		$this->assertSame( $post->post_content, $backup['content'] );
		$this->assertNotContains( self::SECTION, array_column( $this->run_tool( 'list-divi-library' )['items'], 'id' ) );
		$this->assertError( $this->run_tool( 'get-divi-library-item', array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_trashed' );
	}

	public function test_delete_leaves_the_other_items_alone(): void {
		$others = array( self::ROW => clone \WP_Test_State::$posts[ self::ROW ], self::MODULE => clone \WP_Test_State::$posts[ self::MODULE ] );

		$this->assertIsArray( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ) );

		foreach ( $others as $id => $post ) {
			$this->assertEquals( $post, \WP_Test_State::$posts[ $id ] );
		}
	}

	public function test_delete_and_restore_reject_what_they_cannot_touch_and_change_nothing(): void {
		$before = $this->snapshot();

		foreach ( array( self::DELETE, self::RESTORE ) as $name ) {
			$this->assertError( $this->run_tool( $name, array( 'id' => self::GLOBAL ) ), 'nexo_mcp_divi_library_global_blocked' );
			$this->assertError( $this->run_tool( $name, array( 'id' => self::AUTO_DRAFT ) ), 'nexo_mcp_divi_library_not_found' );

			foreach ( array( array(), array( 'id' => 'x' ), array( 'id' => 0 ) ) as $input ) {
				$this->assertSame( 'id', $this->assertError( $this->run_tool( $name, $input ), 'nexo_mcp_divi_library_invalid' )->get_error_data()['path'] );
			}
		}

		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::TRASHED ) ), 'nexo_mcp_divi_library_trashed' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_delete_is_refused_when_the_trash_is_disabled_because_it_would_be_permanent(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_trash_disabled' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_delete_without_a_backup_or_without_the_lock_changes_nothing(): void {
		\WP_Test_State::$json_fail_in = 'save';
		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_backup_failed' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::SECTION ]->post_status );

		\WP_Test_State::$json_fail_in = '';
		$this->lose_lock_after_acquiring( self::SECTION );
		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ), 'nexo_mcp_busy' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::SECTION ]->post_status );
	}

	public function test_if_wordpress_cannot_trash_the_item_it_stays_published_and_the_error_says_so(): void {
		\WP_Test_State::$fail_trash = true;

		$this->assertError( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::SECTION ]->post_status );
	}

	public function test_restore_undoes_an_update_and_the_next_restore_alternates(): void {
		$old = clone \WP_Test_State::$posts[ self::SECTION ];
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro', 'nodes' => $this->section_nodes( 'Cambiado' ), 'categories' => array( 'Pie' ) ) ) );
		$changed = clone \WP_Test_State::$posts[ self::SECTION ];

		$result = $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['restored'] );
		$this->assertSame( 'save', $result['restored_from']['operation'] );
		$this->assertSame( 'restore', $result['backup']['operation'] );
		$this->assertSame( 'Otro', $result['previous']['title'] );
		$this->assertSame( $old->post_title, \WP_Test_State::$posts[ self::SECTION ]->post_title );
		$this->assertSame( $old->post_content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
		$this->assertSame( array( 'Héroes' ), array_keys( \WP_Test_State::$terms[ self::SECTION ]['layout_category'] ) );

		$this->assertIsArray( $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) ) );
		$this->assertSame( $changed->post_title, \WP_Test_State::$posts[ self::SECTION ]->post_title );
		$this->assertSame( $changed->post_content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
	}

	public function test_restore_brings_a_deleted_item_back_from_the_trash(): void {
		$post  = clone \WP_Test_State::$posts[ self::SECTION ];
		$terms = \WP_Test_State::$terms[ self::SECTION ];
		$this->assertIsArray( $this->run_tool( self::DELETE, array( 'id' => self::SECTION ) ) );

		$result = $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'delete', $result['restored_from']['operation'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::SECTION ]->post_status );
		$this->assertSame( $post->post_content, \WP_Test_State::$posts[ self::SECTION ]->post_content );
		$this->assertSame( $terms, \WP_Test_State::$terms[ self::SECTION ] );
		$this->assertContains( self::SECTION, array_column( $this->run_tool( 'list-divi-library' )['items'], 'id' ) );
	}

	public function test_restore_without_a_backup_says_so_and_changes_nothing(): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_no_backup' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_restore_needs_a_new_backup_and_the_lock_before_writing(): void {
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro' ) ) );
		$before = $this->snapshot();

		\WP_Test_State::$json_fail_in = 'save';
		$this->assertError( $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_backup_failed' );
		$this->assertSame( $before, $this->snapshot() );

		\WP_Test_State::$json_fail_in = '';
		$this->lose_lock_after_acquiring( self::SECTION );
		$this->assertError( $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) ), 'nexo_mcp_busy' );
		$this->assertSame( 'Otro', \WP_Test_State::$posts[ self::SECTION ]->post_title );
	}

	public function test_if_restoring_fails_the_item_keeps_what_it_had(): void {
		$this->assertIsArray( $this->run_tool( self::SAVE, array( 'id' => self::SECTION, 'title' => 'Otro' ) ) );
		\WP_Test_State::$fail_update_post = true;

		$this->assertError( $this->run_tool( self::RESTORE, array( 'id' => self::SECTION ) ), 'nexo_mcp_divi_library_save_failed' );
		$this->assertSame( 'Otro', \WP_Test_State::$posts[ self::SECTION ]->post_title );
	}
}
