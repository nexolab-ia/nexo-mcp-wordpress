<?php
/**
 * restore-divi-template (T6b): registro, permiso del Theme Builder, restauración desde `_nexo_mcp_template_backup` (también tras
 * borrar y tras crear), respaldo fresco antes de escribir (restaurar dos veces alterna), confirmaciones de sitio entero y de
 * lo ajeno, marca de «sin uso» de los layouts, vuelta atrás si algo falla, bloqueo y cachés.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviTemplateStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviRestoreTemplateToolTest extends NexoTestCase {

	use DiviTemplateFixtures;

	private const RESTORE = 'restore-divi-template';

	private const MARK = '_et_theme_builder_marked_as_unused';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_divi_templates();
		$this->put_live_tb();
	}

	/**
	 * @param array<string, mixed> $input    Entrada.
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return mixed
	 */
	private function restore( array $input, array $settings = array() ) {
		return $this->tool( self::RESTORE, $settings )['execute_callback']( $input );
	}

	/**
	 * @param array<string, mixed> $input Entrada.
	 * @return mixed
	 */
	private function call( string $name, array $input ) {
		return $this->tool( $name )['execute_callback']( $input );
	}

	private function assertError( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	/**
	 * Guarda el respaldo del estado actual de la plantilla, como lo haría una herramienta de escritura.
	 */
	private function back_up( int $id, string $operation = 'replace' ): void {
		$store = new DiviTemplateStore();

		$this->assertTrue( $store->save_backup( $id, $store->snapshot( $id, self::TB, $operation, true ) ) );
	}

	/**
	 * Saca la plantilla del mapeo y la manda a la papelera sin pasar por la herramienta (el respaldo ya lo tiene mapeada).
	 */
	private function trash_it( int $id ): void {
		$store = new DiviTemplateStore();

		$store->unmap( self::TB, $id );
		$store->trash( $id );
	}

	private function reset_calls(): void {
		\WP_Layout_Stub_State::$css_calls   = array();
		\WP_Layout_Stub_State::$cache_calls = array();
	}

	public function test_it_is_registered_as_a_destructive_write_tool_with_a_closed_schema_and_warns_about_the_whole_site(): void {
		$tool = $this->tool( self::RESTORE );

		$this->assertContains( 'nexo-mcp/' . self::RESTORE, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertTrue( $tool['meta']['annotations']['destructive'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'confirm_site_wide', 'replace_foreign' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'id' ), $tool['input_schema']['required'] );
		$this->assertSame( 'integer', $tool['input_schema']['properties']['id']['type'] );
		$this->assertSame( 'boolean', $tool['input_schema']['properties']['confirm_site_wide']['type'] );
		$this->assertSame( 'boolean', $tool['input_schema']['properties']['replace_foreign']['type'] );

		foreach ( array( 'mcp:divi_theme_builder', 'todo el sitio', 'UNA sola plantilla', 'papelera', 'confirm_site_wide', 'replace_foreign', 'respaldo', '_nexo_mcp_template_backup', 'alterna', 'edit_posts', 'nexo_mcp_divi_template_denied', 'nexo_mcp_divi5_required', 'nexo_mcp_divi_template_no_backup', 'nexo_mcp_divi_template_not_found', 'nexo_mcp_divi_template_site_wide', 'nexo_mcp_divi_template_foreign', 'nexo_mcp_divi_template_backup_failed', 'nexo_mcp_busy', 'restore-builder-backup' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_permission_is_the_theme_builder_one_and_without_it_nothing_changes(): void {
		$this->put_template( 950, 'Plantilla' );
		$this->back_up( 950 );
		$before = $this->snapshot();

		$this->assertTrue( $this->tool( self::RESTORE, array( 'builders_tools_enabled' => false ) )['permission_callback']( array( 'id' => 950 ) ) );
		$this->assertNotTrue( $this->tool( self::RESTORE, array( self::SWITCH => false ) )['permission_callback']( array( 'id' => 950 ) ) );
		$this->assertNotTrue( $this->tool( self::RESTORE, array( 'read_only' => true ) )['permission_callback']( array( 'id' => 950 ) ) );

		$this->assertError( $this->restore( array( 'id' => 950 ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_template_denied' );
		$this->assertError( $this->restore( array( 'basura' => 1 ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_template_denied' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_it_restores_a_replaced_template_keeps_a_fresh_backup_and_restoring_again_toggles_back(): void {
		$this->put_template( 949, 'Antes', array( '_et_use_on' => array( '404' ) ) );
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ), '_et_footer_layout_id' => (string) self::FOOTER ) );
		$this->put_template( 951, 'Después', array( '_et_use_on' => array( 'archive:date:all' ) ) );
		$old   = $this->template_state( 950 );
		$other = array( $this->template_state( 949 )['metas'], $this->template_state( 951 )['metas'] );

		$this->assertIsArray( $this->call( 'set-divi-template', $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) ) );
		$new = $this->template_state( 950 );
		$this->assertNotEquals( $old, $new );
		$this->reset_calls();

		$result = $this->restore( array( 'id' => 950 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 950, $result['id'] );
		$this->assertTrue( $result['restored'] );
		$this->assertSame( 'Vieja', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertTrue( $result['mapped'] );
		$this->assertTrue( $result['enabled'] );
		$this->assertFalse( $result['default'] );
		$this->assertFalse( $result['site_wide'] );
		$this->assertNull( $result['effect'] );
		$this->assertTrue( $result['ours'] );
		$this->assertSame( array( array( 'id' => 'search', 'supported' => true, 'priority' => 1 ) ), $result['use_on'] );
		$this->assertSame( array( 'layout_id' => self::FOOTER, 'enabled' => true ), $result['footer'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $result['body'] );
		$this->assertSame( 'Nueva', $result['previous']['title'] );
		$this->assertSame( array( 'layout_id' => self::BODY, 'enabled' => true ), $result['previous']['body'] );
		$this->assertSame( 'replace', $result['restored_from']['operation'] );
		$this->assertTrue( $result['restored_from']['existed'] );
		$this->assertSame( 'restore', $result['backup']['operation'] );
		$this->assertTrue( $result['backup']['existed'] );
		$this->assertSame( self::TB, $result['live_theme_builder'] );
		$this->assertSame( array( 'css' => true, 'page_cache' => true ), $result['cache'] );
		$this->assertIsArray( $result['warnings'] );

		$this->assertEquals( $old, $this->template_state( 950 ) );
		$this->assertSame( array( '949', '950', '951' ), $this->mapping(), 'ya estaba en el mapeo: ni se mueve ni se repite' );
		$this->assertEquals( $other, array( $this->template_state( 949 )['metas'], $this->template_state( 951 )['metas'] ), 'las otras plantillas no se tocan' );
		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );

		$store = new DiviTemplateStore();
		$this->assertSame( 'restore', $store->backup( 950 )['operation'], 'el respaldo es el del estado que se acaba de dejar' );
		$this->assertSame( 'Nueva', $store->backup( 950 )['title'] );

		$again = $this->restore( array( 'id' => 950 ) );

		$this->assertIsArray( $again );
		$this->assertSame( 'restore', $again['restored_from']['operation'] );
		$this->assertEquals( $new, $this->template_state( 950 ), 'restaurar dos veces vuelve al estado de antes' );

		$this->assertIsArray( $this->restore( array( 'id' => 950 ) ) );
		$this->assertEquals( $old, $this->template_state( 950 ) );
	}

	public function test_it_brings_back_a_deleted_template_untrashes_it_and_links_only_its_own_row(): void {
		$this->put_template( 950, 'Otra', array( '_et_use_on' => array( 'search' ) ) );
		$this->put_template( 951, 'Inicio', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY, '_et_header_layout_id' => (string) self::HEADER ) );
		$this->put_template( 952, 'Más', array( '_et_use_on' => array( '404' ) ) );
		$old   = $this->template_state( 951 );
		$other = array( $this->metas_of( 950 ), $this->metas_of( 952 ) );

		$this->assertIsArray( $this->call( 'delete-divi-template', array( 'id' => 951 ) ) );
		update_post_meta( 951, '_wp_trash_meta_status', 'publish' );
		update_post_meta( 951, '_wp_trash_meta_time', '1700000000' );
		$this->assertSame( 'trash', \WP_Test_State::$posts[951]->post_status );
		$this->assertSame( array( '950', '952' ), $this->mapping() );
		$this->reset_calls();

		$result = $this->restore( array( 'id' => 951 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertTrue( $result['mapped'] );
		$this->assertSame( 'delete', $result['restored_from']['operation'] );
		$this->assertSame( 'Inicio', $result['previous']['title'], 'el resumen previo es el de la plantilla en la papelera' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[951]->post_status );
		$this->assertSame( 'Inicio', \WP_Test_State::$posts[951]->post_title );
		$this->assertEquals( $old['metas'], $this->metas_of( 951 ) );
		$this->assertSame( array( '950', '952', '951' ), $this->mapping(), 'sólo se añade su fila, al final' );
		$this->assertArrayNotHasKey( '_wp_trash_meta_status', \WP_Test_State::$meta[951] ?? array() );
		$this->assertArrayNotHasKey( '_wp_trash_meta_time', \WP_Test_State::$meta[951] ?? array() );
		$this->assertEquals( $other, array( $this->metas_of( 950 ), $this->metas_of( 952 ) ) );
		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );

		$this->assertIsArray( $this->restore( array( 'id' => 951 ) ), 'otra vez: la deja como estaba antes de restaurar' );
		$this->assertSame( 'trash', \WP_Test_State::$posts[951]->post_status );
		$this->assertSame( array( '950', '952' ), $this->mapping() );
	}

	public function test_restoring_the_backup_of_a_creation_sends_the_template_to_the_trash_and_restoring_again_brings_it_back(): void {
		$this->put_template( 950, 'Otra' );
		$this->assertIsArray( $this->call( 'set-divi-template', $this->new_template() ) );
		$ids = array_values( array_filter( array_keys( \WP_Test_State::$posts ), static fn( int $id ): bool => 'et_template' === \WP_Test_State::$posts[ $id ]->post_type && 950 !== $id ) );
		$id  = $ids[0];
		$this->assertSame( array( '950', (string) $id ), $this->mapping() );
		$this->reset_calls();

		$result = $this->restore( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'trash', $result['status'] );
		$this->assertFalse( $result['mapped'] );
		$this->assertSame( 'create', $result['restored_from']['operation'] );
		$this->assertFalse( $result['restored_from']['existed'] );
		$this->assertStringContainsString( 'papelera', implode( ' ', $result['warnings'] ) );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ $id ]->post_status );
		$this->assertSame( array( '950' ), $this->mapping() );
		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );

		$back = $this->restore( array( 'id' => $id ) );

		$this->assertIsArray( $back );
		$this->assertSame( 'publish', $back['status'] );
		$this->assertTrue( $back['mapped'] );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ $id ]->post_status );
		$this->assertSame( array( '950', (string) $id ), $this->mapping() );
		$this->assertSame( array( 'homepage' ), $this->metas_of( $id )['_et_use_on'] );
	}

	public function test_assigning_the_layouts_again_clears_their_unused_mark_and_only_theirs(): void {
		$this->put_template( 950, 'Plantilla', array( '_et_body_layout_id' => (string) self::BODY, '_et_header_layout_id' => (string) self::BODY_BARE, '_et_footer_layout_id' => '100' ) );
		$this->back_up( 950 );
		( new DiviTemplateStore() )->write( 950, $this->template_metas( array( '_et_body_layout_id' => '0', '_et_header_layout_id' => '0', '_et_footer_layout_id' => '0' ) ) );

		foreach ( array( self::BODY, self::BODY_BARE, self::FOOTER, 100 ) as $marked ) {
			update_post_meta( $marked, self::MARK, '1700000000' );
		}

		$this->assertIsArray( $this->restore( array( 'id' => 950 ) ) );

		$this->assertSame( '', (string) get_post_meta( self::BODY, self::MARK, true ), 'el cuerpo vuelve a estar en uso' );
		$this->assertSame( '1700000000', get_post_meta( self::BODY_BARE, self::MARK, true ), 'un cuerpo en la ranura del encabezado no cuenta' );
		$this->assertSame( '1700000000', get_post_meta( 100, self::MARK, true ), 'una página en una ranura no se toca' );
		$this->assertSame( '1700000000', get_post_meta( self::FOOTER, self::MARK, true ), 'un layout que no vuelve a la plantilla sigue marcado' );
	}

	public function test_a_site_wide_state_needs_confirm_site_wide_whether_it_is_the_backup_or_the_current_one(): void {
		$store = new DiviTemplateStore();

		// El respaldo es de sitio entero y el estado actual no.
		$this->put_template( 950, 'Sitio', array( '_et_default' => '1' ) );
		$this->back_up( 950 );
		$store->write( 950, $this->template_metas() );
		$store->rename( 950, 'Ahora no' );

		// El respaldo no es de sitio entero y el estado actual sí.
		$this->put_template( 951, 'Normal', array( '_et_use_on' => array( 'homepage' ) ) );
		$this->back_up( 951 );
		$store->write( 951, $this->template_metas( array( '_et_use_on' => array( 'archive:all' ) ) ) );

		foreach ( array( 950, 951 ) as $id ) {
			$before = $this->snapshot();

			$error = $this->assertError( $this->restore( array( 'id' => $id ) ), 'nexo_mcp_divi_template_site_wide' );

			$this->assertSame( array( 'path' => 'confirm_site_wide' ), $error->get_error_data() );
			$this->assertSame( $before, $this->snapshot() );
		}

		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );

		$wide = $this->restore( array( 'id' => 950, 'confirm_site_wide' => true ) );

		$this->assertIsArray( $wide );
		$this->assertTrue( $wide['site_wide'] );
		$this->assertTrue( $wide['default'] );
		$this->assertIsString( $wide['effect'] );
		$this->assertSame( '1', $this->metas_of( 950 )['_et_default'] );

		$this->assertIsArray( $this->restore( array( 'id' => 951, 'confirm_site_wide' => true ) ) );
		$this->assertSame( array( 'homepage' ), $this->metas_of( 951 )['_et_use_on'] );
	}

	public function test_replacing_a_live_foreign_template_needs_replace_foreign_but_a_trashed_one_does_not(): void {
		$store = new DiviTemplateStore();

		$this->put_template( 950, 'Mía', array( '_et_use_on' => array( 'search' ) ) );
		$this->back_up( 950 );
		$store->write( 950, $this->template_metas( array( '_et_use_on' => array( 'archive:user:role:editor' ), '_et_library_item_id' => '9' ) ) );
		$foreign = $this->template_state( 950 );
		$before  = $this->snapshot();

		$error = $this->assertError( $this->restore( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_foreign' );

		$this->assertSame( 950, $error->get_error_data()['id'] );
		$this->assertFalse( $error->get_error_data()['summary']['ours'] );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );

		$ok = $this->restore( array( 'id' => 950, 'replace_foreign' => true ) );

		$this->assertIsArray( $ok );
		$this->assertFalse( $ok['previous']['ours'] );
		$this->assertSame( array( 'search' ), $this->metas_of( 950 )['_et_use_on'] );
		$this->assertArrayNotHasKey( '_et_library_item_id', $this->metas_of( 950 ) );
		$this->assertSame( 'restore', $store->backup( 950 )['operation'] );
		$this->assertEquals( $foreign['metas'], $store->backup( 950 )['metas'], 'lo ajeno queda en el respaldo' );

		$this->put_template( 951, 'Ajena borrada', array( '_et_use_on' => array( 'search' ) ) );
		$this->back_up( 951, 'delete' );
		$this->trash_it( 951 );
		$store->write( 951, $this->template_metas( array( '_et_use_on' => array( 'archive:user:role:editor' ) ) ) );

		$this->assertIsArray( $this->restore( array( 'id' => 951 ) ), 'no está en el mapeo en vivo: no se pierde nada' );
	}

	public function test_without_a_backup_nothing_is_written_not_even_a_backup(): void {
		$this->put_template( 950, 'Sin respaldo' );
		$before = $this->snapshot();

		$error = $this->assertError( $this->restore( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_no_backup' );

		$this->assertSame( array( 'id' => 950 ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertArrayNotHasKey( DiviTemplateStore::BACKUP_META, \WP_Test_State::$meta[950] ?? array() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );

		update_post_meta( 950, DiviTemplateStore::BACKUP_META, 'no es json' );
		$before = $this->snapshot();

		$this->assertError( $this->restore( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_no_backup' );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function missing_targets(): array {
		return array(
			'no existe'           => array( 4040 ),
			'es una página'       => array( 100 ),
			'es un layout'        => array( self::BODY ),
			'es el Theme Builder' => array( self::TB ),
		);
	}

	/**
	 * @dataProvider missing_targets
	 */
	public function test_an_id_that_is_not_a_template_is_not_found_and_nothing_changes( int $id ): void {
		$this->put_template( 950, 'Otra' );
		$this->back_up( 950 );
		update_post_meta( $id, DiviTemplateStore::BACKUP_META, '{"operation":"replace","captured_at":"2026-01-01 00:00:00","existed":true}' );
		$before = $this->snapshot();

		$error = $this->assertError( $this->restore( array( 'id' => $id ) ), 'nexo_mcp_divi_template_not_found' );

		$this->assertSame( array( 'id' => $id ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'sin id'                        => array( array(), 'id' ),
			'id como texto'                 => array( array( 'id' => '950' ), 'id' ),
			'id cero'                       => array( array( 'id' => 0 ), 'id' ),
			'confirm_site_wide no booleano' => array( array( 'id' => 950, 'confirm_site_wide' => 'true' ), 'confirm_site_wide' ),
			'replace_foreign no booleano'   => array( array( 'id' => 950, 'replace_foreign' => 1 ), 'replace_foreign' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @param array<string, mixed> $input Entrada inválida.
	 */
	public function test_invalid_input_changes_nothing_and_names_the_path( array $input, string $path ): void {
		$this->put_template( 950, 'Plantilla' );
		$this->back_up( 950 );
		$before = $this->snapshot();

		$error = $this->assertError( $this->restore( $input ), 'nexo_mcp_divi_template_invalid' );

		$this->assertSame( array( 'path' => $path ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_when_the_fresh_backup_cannot_be_saved_nothing_is_written(): void {
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$this->back_up( 950 );
		( new DiviTemplateStore() )->write( 950, $this->template_metas( array( '_et_use_on' => array( 'homepage' ) ) ) );
		$before = $this->snapshot();

		\WP_Test_State::$json_fail_in = 'save_backup';
		$result                       = $this->restore( array( 'id' => 950 ) );
		\WP_Test_State::$json_fail_in = '';

		$this->assertError( $result, 'nexo_mcp_divi_template_backup_failed' );
		$this->assertSame( $before, $this->snapshot(), 'sin respaldo fresco no se escribe: ni metas ni respaldo ni mapeo' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_failure_while_writing_puts_everything_back_including_the_original_backup(): void {
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$this->assertIsArray( $this->call( 'set-divi-template', $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) ) );
		$this->reset_calls();
		$before = $this->snapshot();

		\WP_Test_State::$fail_add_meta['_et_use_on'] = true;
		$error                                       = $this->assertError( $this->restore( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_save_failed' );

		$this->assertStringContainsString( 'volvió a su estado anterior', $error->get_error_message() );
		$this->assertSame( $before, $this->snapshot(), 'título, metas, mapeo y el respaldo de antes quedan como estaban' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_failed_update_changes_nothing(): void {
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$this->assertIsArray( $this->call( 'set-divi-template', $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) ) );
		$this->reset_calls();
		$before = $this->snapshot();

		\WP_Test_State::$fail_update_post = true;
		$result                           = $this->restore( array( 'id' => 950 ) );
		\WP_Test_State::$fail_update_post = false;

		$this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_restore_cannot_exceed_the_template_limit_or_add_a_second_default(): void {
		$this->put_template( 950, 'Por defecto', array( '_et_default' => '1' ) );
		$this->put_template( 951, 'Borrada por defecto', array( '_et_default' => '1' ) );
		$this->back_up( 951, 'delete' );
		$this->trash_it( 951 );
		$before = $this->snapshot();

		$error = $this->assertError( $this->restore( array( 'id' => 951, 'confirm_site_wide' => true ) ), 'nexo_mcp_divi_template_default_exists' );

		$this->assertSame( array( 'id' => 950 ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		for ( $id = 960; $id < 960 + DiviTemplateStore::MAX_TEMPLATES - 1; $id++ ) {
			$this->put_template( $id, 'T' . $id );
		}

		$this->put_template( 955, 'Borrada', array() );
		$this->back_up( 955, 'delete' );
		$this->trash_it( 955 );
		$before = $this->snapshot();

		$this->assertError( $this->restore( array( 'id' => 955 ) ), 'nexo_mcp_divi_template_too_many' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_answer_warns_about_layouts_that_are_not_published(): void {
		$this->put_template( 950, 'Plantilla', array( '_et_use_on' => array( 'homepage' ), '_et_header_layout_id' => (string) self::HEADER_DRAFT ) );
		$this->back_up( 950 );
		( new DiviTemplateStore() )->write( 950, $this->template_metas() );

		$result = $this->restore( array( 'id' => 950 ) );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( (string) self::HEADER_DRAFT, implode( ' ', $result['warnings'] ) );
		$this->assertStringContainsString( 'draft', implode( ' ', $result['warnings'] ) );
	}

	public function test_a_tampered_backup_cannot_write_anything_but_et_metas_and_a_known_status(): void {
		$this->put_template( 950, 'Plantilla', array( '_et_use_on' => array( 'homepage' ) ) );
		update_post_meta(
			950,
			DiviTemplateStore::BACKUP_META,
			(string) wp_json_encode(
				array(
					'operation'   => 'replace',
					'captured_at' => '2026-01-01 00:00:00',
					'existed'     => true,
					'title'       => 'Vuelta',
					'status'      => 'inventado',
					'metas'       => array(
						'_et_enabled'       => '0',
						'_et_use_on'        => array( 'search' ),
						'_wp_page_template' => 'x',
						'evil'              => 'x',
						'_et_nested'        => array( 'a' => array( 'b' ) ),
					),
					'mapped'      => true,
				)
			)
		);

		$this->assertIsArray( $this->restore( array( 'id' => 950 ) ) );

		$this->assertSame( 'publish', \WP_Test_State::$posts[950]->post_status );
		$this->assertSame( 'Vuelta', \WP_Test_State::$posts[950]->post_title );
		$this->assertSame( array( 'search' ), $this->metas_of( 950 )['_et_use_on'] );
		$this->assertArrayNotHasKey( 'evil', \WP_Test_State::$meta[950] ?? array() );
		$this->assertArrayNotHasKey( '_wp_page_template', \WP_Test_State::$meta[950] ?? array() );
		$this->assertArrayNotHasKey( '_et_nested', $this->metas_of( 950 ) );
	}

	public function test_a_busy_lock_changes_nothing(): void {
		$this->put_template( 950, 'Plantilla' );
		$this->back_up( 950 );
		$before = $this->snapshot();

		\WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] = 'otro|' . ( time() + 100 );
		PostLock::$wait                                                         = 0.0;
		$result = $this->restore( array( 'id' => 950 ) );

		$this->assertError( $result, 'nexo_mcp_busy' );
		unset( \WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_lock_lost_before_writing_changes_nothing(): void {
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$this->back_up( 950 );
		( new DiviTemplateStore() )->write( 950, $this->template_metas( array( '_et_use_on' => array( 'homepage' ) ) ) );
		$before = $this->template_state( 950 );

		$this->lose_lock_after_acquiring( DiviTemplateStore::LOCK_ID );

		$this->assertError( $this->restore( array( 'id' => 950 ) ), 'nexo_mcp_busy' );
		$this->assertSame( $before, $this->template_state( 950 ) );
		$this->assertSame( 'replace', ( new DiviTemplateStore() )->backup( 950 )['operation'], 'el respaldo tampoco se pisó' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_the_css_warning_is_part_of_the_answer_when_divi_would_not_clear_it(): void {
		$this->put_template( 950, 'Plantilla' );
		$this->back_up( 950 );
		\WP_Test_State::$caps['edit_posts'] = false;

		$result = $this->restore( array( 'id' => 950 ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cache']['css'] );
		$this->assertStringContainsString( 'edit_posts', implode( ' ', $result['warnings'] ) );
	}
}
