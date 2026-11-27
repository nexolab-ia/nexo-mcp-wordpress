<?php
/**
 * DiviTemplateStore (T6): Theme Builder en vivo y borrador, mapeo de UNA fila, metas de la plantilla, respaldo y restauración,
 * borrado de cachés como el guardado en vivo de Divi y bloqueo.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` y las clases de Divi (no se pueden quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviTemplateStore;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviTemplateStoreTest extends NexoTestCase implements DiviTemplateIds {

	use DiviTemplateFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_divi_templates();
		$this->put_live_tb();
	}

	private function store(): DiviTemplateStore {
		return new DiviTemplateStore();
	}

	public function test_the_live_builder_is_the_publish_one_and_the_draft_the_auto_draft_one_and_nothing_is_created(): void {
		$this->put_live_tb( 905, 'auto-draft' );
		$this->put_live_tb( 906, 'trash' );
		$before = $this->snapshot();

		$this->assertSame( self::TB, $this->store()->live_id() );
		$this->assertSame( 905, $this->store()->draft_id() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_without_a_live_builder_the_id_is_zero_and_ensure_creates_it_once_with_divis_own_function(): void {
		unset( \WP_Test_State::$posts[ self::TB ] );

		$this->assertSame( 0, $this->store()->live_id() );
		$this->assertSame( 0, $this->store()->draft_id() );

		$created = $this->store()->ensure_live_id();

		$this->assertGreaterThan( 0, $created );
		$this->assertSame( 'et_theme_builder', \WP_Test_State::$posts[ $created ]->post_type );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ $created ]->post_status );
		$this->assertSame( array( true ), \WP_Template_Stub_State::$tb_calls );
		$this->assertSame( $created, $this->store()->ensure_live_id(), 'la segunda vez no crea otro' );
		$this->assertSame( array( true ), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_mapping_lists_ids_in_order_without_repeats_or_junk(): void {
		foreach ( array( '5', '3', '5', '', 'abc', '0', '-4', '7' ) as $row ) {
			add_post_meta( self::TB, '_et_template', $row );
		}

		$this->assertSame( array( 5, 3, 7 ), $this->store()->mapping( self::TB ) );
		$this->assertSame( array(), $this->store()->mapping( 0 ) );
		$this->assertSame( array(), $this->store()->mapping( 12345 ) );
	}

	public function test_map_adds_exactly_one_row_and_unmap_removes_only_that_row(): void {
		$store = $this->store();
		add_post_meta( self::TB, '_et_template', '5' );
		add_post_meta( self::TB, '_et_template', '6' );

		$this->assertTrue( $store->map( self::TB, 7 ) );
		$this->assertTrue( $store->map( self::TB, 7 ), 'ya estaba' );
		$this->assertSame( array( '5', '6', '7' ), $this->mapping() );

		$this->assertTrue( $store->unmap( self::TB, 6 ) );
		$this->assertSame( array( '5', '7' ), $this->mapping() );
		$this->assertTrue( $store->unmap( self::TB, 99 ), 'una fila que no está no cambia nada' );
		$this->assertSame( array( '5', '7' ), $this->mapping() );

		$this->assertFalse( $store->map( 0, 7 ) );
		$this->assertFalse( $store->map( self::TB, 0 ) );
	}

	public function test_metas_returns_only_et_metas_with_lists_for_the_conditions(): void {
		$this->put_template( 950, 'Plantilla', array( '_et_use_on' => array( 'homepage', '404' ), '_et_foo' => 'x' ) );
		update_post_meta( 950, '_other', 'no' );
		update_post_meta( 950, '_nexo_mcp_template_backup', '{}' );

		$metas = $this->store()->metas( 950 );

		$this->assertSame( array( 'homepage', '404' ), $metas['_et_use_on'] );
		$this->assertArrayNotHasKey( '_et_exclude_from', $metas, 'una lista sin filas no tiene clave' );
		$this->assertSame( '1', $metas['_et_enabled'] );
		$this->assertSame( 'x', $metas['_et_foo'] );
		$this->assertArrayNotHasKey( '_other', $metas );
		$this->assertArrayNotHasKey( '_nexo_mcp_template_backup', $metas );
	}

	public function test_template_only_returns_et_template_posts(): void {
		$this->put_template( 950, 'Plantilla' );

		$this->assertSame( 950, $this->store()->template( 950 )->ID );
		$this->assertNull( $this->store()->template( 100 ) );
		$this->assertNull( $this->store()->template( self::BODY ) );
		$this->assertNull( $this->store()->template( 0 ) );
		$this->assertNull( $this->store()->template( 4040 ) );
	}

	public function test_write_replaces_the_et_metas_with_exactly_the_given_ones(): void {
		$this->put_template( 950, 'Plantilla', array( '_et_use_on' => array( 'homepage', '404' ), '_et_library_item_id' => '9', '_et_onboarding_created' => '1' ) );

		$this->store()->write( 950, array( '_et_enabled' => '0', '_et_use_on' => array( 'search' ), '_et_default' => '0' ) );

		$this->assertEquals(
			array( '_et_enabled' => '0', '_et_use_on' => array( 'search' ), '_et_default' => '0', '_et_onboarding_created' => '1' ),
			$this->store()->metas( 950 ),
			'las demás _et_ se quitan, salvo las que el conector no toca'
		);
	}

	public function test_insert_creates_a_published_template_and_a_failure_returns_the_error(): void {
		$id = $this->store()->insert( 'Nueva' );

		$this->assertIsInt( $id );
		$this->assertSame( 'et_template', \WP_Test_State::$posts[ $id ]->post_type );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ $id ]->post_status );
		$this->assertSame( 'Nueva', \WP_Test_State::$posts[ $id ]->post_title );

		\WP_Test_State::$fail_insert_post = true;
		$failed                           = $this->store()->insert( 'Otra' );

		$this->assertInstanceOf( \WP_Error::class, $failed );
		$this->assertSame( 'db_insert_error', $failed->get_error_code() );
	}

	public function test_a_backup_restores_metas_title_status_and_the_mapping_row_of_that_template_only(): void {
		$store = $this->store();
		$this->put_template( 950, 'Original', array( '_et_use_on' => array( 'homepage', '404' ), '_et_exclude_from' => array( 'search' ), '_et_library_item_id' => '9' ) );
		$this->put_template( 951, 'Otra' );

		$snapshot = $store->snapshot( 950, self::TB, 'replace', true );
		$this->assertTrue( $store->save_backup( 950, $snapshot ) );

		$this->assertSame( array( '950', '951' ), array_map( 'strval', $snapshot['mapping'] ) );
		$this->assertTrue( $snapshot['mapped'] );

		// Cambios posteriores: metas, título, estado, mapeo propio y una plantilla ajena que se añade.
		$store->write( 950, array( '_et_enabled' => '0', '_et_use_on' => array( 'archive:all' ), '_et_default' => '1' ) );
		$store->rename( 950, 'Cambiada' );
		$store->unmap( self::TB, 950 );
		$store->trash( 950 );
		$this->put_template( 952, 'Nueva de otro' );

		$this->assertTrue( $store->restore( 950, self::TB ) );

		$this->assertSame( 'Original', \WP_Test_State::$posts[950]->post_title );
		$this->assertSame( 'publish', \WP_Test_State::$posts[950]->post_status );
		$this->assertSame( $snapshot['metas'], $store->metas( 950 ) );
		$this->assertSame( array( '951', '952', '950' ), $this->mapping(), 'sólo vuelve la fila de 950; las demás no se tocan' );

		$again = $store->metas( 950 );
		$this->assertTrue( $store->restore( 950, self::TB ), 'restaurar dos veces deja el mismo estado' );
		$this->assertSame( $again, $store->metas( 950 ) );
		$this->assertSame( array( '951', '952', '950' ), $this->mapping() );
	}

	public function test_a_backup_of_a_template_that_was_not_mapped_unmaps_it_on_restore(): void {
		$store = $this->store();
		$this->put_template( 950, 'Suelta', array(), false );

		$this->assertTrue( $store->save_backup( 950, $store->snapshot( 950, self::TB, 'replace', true ) ) );
		$store->map( self::TB, 950 );
		$this->assertTrue( $store->restore( 950, self::TB ) );

		$this->assertSame( array(), $this->mapping() );
	}

	public function test_restoring_a_creation_trashes_the_template_and_unmaps_it(): void {
		$store = $this->store();
		$id    = $store->insert( 'Creada' );
		$store->map( self::TB, $id );
		$this->assertTrue( $store->save_backup( $id, $store->snapshot( $id, self::TB, 'create', false ) ) );
		$store->write( $id, array( '_et_enabled' => '1' ) );

		$this->assertTrue( $store->restore( $id, self::TB ) );

		$this->assertSame( 'trash', \WP_Test_State::$posts[ $id ]->post_status );
		$this->assertSame( array(), $this->mapping() );
	}

	public function test_restore_without_a_backup_or_for_something_that_is_not_a_template_fails_without_changes(): void {
		$this->put_template( 950, 'Sin respaldo' );
		$before = $this->snapshot();

		$none = $this->store()->restore( 950, self::TB );
		$this->assertInstanceOf( \WP_Error::class, $none );
		$this->assertSame( 'nexo_mcp_divi_template_no_backup', $none->get_error_code() );
		$this->assertSame( array( 'id' => 950 ), $none->get_error_data() );

		$page = $this->store()->restore( 100, self::TB );
		$this->assertInstanceOf( \WP_Error::class, $page );
		$this->assertSame( 'nexo_mcp_divi_template_no_backup', $page->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_there_is_only_one_backup_the_last_one_and_a_broken_one_is_not_a_backup(): void {
		$store = $this->store();
		$this->put_template( 950, 'T' );

		$this->assertNull( $store->backup( 950 ) );
		$store->save_backup( 950, $store->snapshot( 950, self::TB, 'replace', true ) );
		$store->save_backup( 950, $store->snapshot( 950, self::TB, 'delete', true ) );

		$this->assertSame( 'delete', $store->backup( 950 )['operation'] );

		update_post_meta( 950, DiviTemplateStore::BACKUP_META, 'no es json' );
		$this->assertNull( $store->backup( 950 ) );
		update_post_meta( 950, DiviTemplateStore::BACKUP_META, '{"x":1}' );
		$this->assertNull( $store->backup( 950 ) );
	}

	public function test_clear_caches_uses_the_same_two_calls_as_divis_live_save(): void {
		$result = $this->store()->clear_caches();

		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );
		$this->assertSame( array( 'css' => true, 'page_cache' => true, 'warnings' => array() ), $result );
	}

	public function test_clear_caches_warns_when_divi_would_silently_do_nothing_for_lack_of_edit_posts(): void {
		\WP_Test_State::$caps['edit_posts'] = false;

		$result = $this->store()->clear_caches();

		$this->assertFalse( $result['css'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'edit_posts', $result['warnings'][0] );
	}

	public function test_the_lock_runs_the_work_and_answers_busy_when_someone_else_holds_it(): void {
		$this->assertSame( 'hecho', $this->store()->locked( static fn(): string => 'hecho' ) );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID, \WP_Test_State::$options, 'se libera' );

		\WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] = 'otro|' . ( time() + 100 );
		PostLock::$wait                                                         = 0.0;
		$ran                                                                    = false;

		$busy = $this->store()->locked(
			static function () use ( &$ran ): string {
				$ran = true;

				return 'no';
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 'nexo_mcp_busy', $busy->get_error_code() );
		$this->assertFalse( $ran );
	}

	public function test_verify_lock_fails_when_the_lock_was_lost_before_writing(): void {
		$this->lose_lock_after_acquiring( DiviTemplateStore::LOCK_ID );

		$result = $this->store()->locked( fn() => $this->store()->verify_lock() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
	}

	public function test_restoring_a_creation_whose_row_cannot_be_removed_fails_and_does_not_trash(): void {
		$store = $this->store();
		$id    = $store->insert( 'Creada' );
		$store->map( self::TB, $id );
		$this->assertTrue( $store->save_backup( $id, $store->snapshot( $id, self::TB, 'create', false ) ) );
		$before = $this->snapshot();

		\WP_Test_State::$fail_delete_meta['_et_template'] = true;
		$result                                          = $store->restore( $id, self::TB );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_template_save_failed', $result->get_error_code() );
		$this->assertSame( array( 'id' => $id ), $result->get_error_data() );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ $id ]->post_status, 'no se manda a la papelera una plantilla que sigue en el mapeo' );
		$this->assertSame( array( (string) $id ), $this->mapping() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_discarding_the_live_builder_only_deletes_an_empty_one(): void {
		$store = $this->store();
		$this->put_template( 950, 'Plantilla' );

		$this->assertFalse( $store->discard_live_builder( self::TB ), 'tiene filas: no se borra' );
		$this->assertArrayHasKey( self::TB, \WP_Test_State::$posts );
		$this->assertFalse( $store->discard_live_builder( 950 ), 'no es un Theme Builder' );
		$this->assertArrayHasKey( 950, \WP_Test_State::$posts );

		$store->unmap( self::TB, 950 );

		$this->assertTrue( $store->discard_live_builder( self::TB ) );
		$this->assertArrayNotHasKey( self::TB, \WP_Test_State::$posts );
		$this->assertFalse( $store->discard_live_builder( 0 ) );
	}

	public function test_discarding_the_live_builder_never_deletes_one_from_the_theme_builder_library(): void {
		$store = $this->store();
		update_post_meta( self::TB, '_et_library_theme_builder', '1' );

		$this->assertSame( array(), $store->mapping( self::TB ), 'está vacío' );
		$this->assertFalse( $store->discard_live_builder( self::TB ) );
		$this->assertArrayHasKey( self::TB, \WP_Test_State::$posts );
		$this->assertSame( '1', \WP_Test_State::$meta[ self::TB ]['_et_library_theme_builder'] );

		delete_post_meta( self::TB, '_et_library_theme_builder' );

		$this->assertTrue( $store->discard_live_builder( self::TB ), 'sin la marca, vacío y propio sí' );
	}
}
