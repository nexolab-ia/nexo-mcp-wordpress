<?php
/**
 * delete-divi-template (T6): registro, permiso del Theme Builder, sale del mapeo en vivo y va a la papelera con respaldo,
 * confirmación para lo que cambia todo el sitio y para lo ajeno, cachés y restauración.
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
final class DiviDeleteTemplateToolTest extends NexoTestCase {

	use DiviTemplateFixtures;

	private const DELETE = 'delete-divi-template';

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
	private function delete( array $input, array $settings = array() ) {
		return $this->tool( self::DELETE, $settings )['execute_callback']( $input );
	}

	private function assertError( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	public function test_it_is_registered_as_a_destructive_write_tool_with_a_closed_schema_and_warns_about_the_whole_site(): void {
		$tool = $this->tool( self::DELETE );

		$this->assertContains( 'nexo-mcp/' . self::DELETE, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertTrue( $tool['meta']['annotations']['destructive'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'confirm_site_wide', 'replace_foreign' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'id' ), $tool['input_schema']['required'] );

		foreach ( array( 'mcp:divi_theme_builder', 'todo el sitio', 'UNA sola plantilla', 'papelera', 'confirm_site_wide', 'replace_foreign', 'respaldo', 'previous', '_nexo_mcp_template_backup', 'layouts', '7 días', 'edit_posts', 'nexo_mcp_divi_template_denied', 'nexo_mcp_divi_template_not_found', 'nexo_mcp_divi_template_site_wide', 'nexo_mcp_divi_template_foreign', 'nexo_mcp_busy' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_description_points_to_restore_divi_template_and_not_to_wordpress_for_recovering(): void {
		$description = $this->tool( self::DELETE )['description'];

		$this->assertStringContainsString( 'nexo-mcp-restore-divi-template', $description );
		$this->assertStringNotContainsString( 'se recupera desde WordPress', $description );
		$this->assertStringContainsString( 'nexo-mcp-restore-builder-backup NO acepta plantillas', $description );
		$this->assertStringNotContainsString( 'set-divi-template sin id; nace deshabilitada', $description );
	}

	public function test_the_permission_is_the_theme_builder_one_and_without_it_nothing_changes(): void {
		$this->put_template( 950, 'Plantilla' );
		$before = $this->snapshot();

		$this->assertTrue( $this->tool( self::DELETE, array( 'builders_tools_enabled' => false ) )['permission_callback']( array( 'id' => 950 ) ) );
		$this->assertNotTrue( $this->tool( self::DELETE, array( self::SWITCH => false ) )['permission_callback']( array( 'id' => 950 ) ) );

		$this->assertError( $this->delete( array( 'id' => 950 ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_template_denied' );
		$this->assertError( $this->delete( array( 'basura' => 1 ), array( self::SWITCH => false ) ), 'nexo_mcp_divi_template_denied' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_it_takes_one_template_out_of_the_mapping_trashes_it_and_keeps_a_backup_and_the_layouts(): void {
		$this->put_template( 950, 'Otra', array( '_et_use_on' => array( 'search' ) ) );
		$this->put_template( 951, 'Inicio', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY, '_et_header_layout_id' => (string) self::HEADER ) );
		$this->put_template( 952, 'Más', array( '_et_use_on' => array( '404' ) ) );
		$other = array( $this->metas_of( 950 ), $this->metas_of( 952 ) );
		$old   = $this->metas_of( 951 );

		$result = $this->delete( array( 'id' => 951 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 951, $result['id'] );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'trash', $result['status'] );
		$this->assertSame( 'Inicio', $result['title'] );
		$this->assertFalse( $result['was_default'] );
		$this->assertFalse( $result['site_wide'] );
		$this->assertSame( array( self::HEADER, self::BODY ), $result['layouts'] );
		$this->assertSame( self::TB, $result['live_theme_builder'] );
		$this->assertSame( 'delete', $result['backup']['operation'] );
		$this->assertTrue( $result['backup']['existed'] );
		$this->assertSame( array( 'css' => true, 'page_cache' => true ), $result['cache'] );
		$this->assertStringContainsString( '7 días', implode( ' ', $result['warnings'] ) );

		$this->assertSame( array( '950', '952' ), $this->mapping(), 'sólo se quitó su fila' );
		$this->assertSame( 'trash', \WP_Test_State::$posts[951]->post_status );
		$this->assertSame( $old, $this->metas_of( 951 ), 'sus metas quedan para poder restaurarla' );
		$this->assertSame( $other, array( $this->metas_of( 950 ), $this->metas_of( 952 ) ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::BODY ]->post_status, 'los layouts no se tocan' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::HEADER ]->post_status );

		$backup = ( new DiviTemplateStore() )->backup( 951 );
		$this->assertSame( 'delete', $backup['operation'] );
		$this->assertSame( $old, $backup['metas'] );
		$this->assertTrue( $backup['mapped'] );
		$this->assertSame( array( 950, 951, 952 ), $backup['mapping'] );

		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_deleted_template_comes_back_from_its_backup_and_the_answer_says_what_there_was(): void {
		$this->put_template( 950, 'Otra' );
		$this->put_template( 951, 'Inicio', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY ) );
		$before = $this->template_state( 951 );

		$result = $this->delete( array( 'id' => 951 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['previous']['ours'] );
		$this->assertSame( 'Inicio', $result['previous']['title'] );
		$this->assertSame( array( 'layout_id' => self::BODY, 'enabled' => true ), $result['previous']['body'] );
		$this->assertNotSame( $before, $this->template_state( 951 ) );

		$this->assertTrue( ( new DiviTemplateStore() )->restore( 951, self::TB ) );

		$this->assertSame( array( '950', '951' ), $this->mapping() );
		$this->assertSame( $before['metas'], $this->metas_of( 951 ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[951]->post_status );
		$this->assertArrayNotHasKey( '_wp_trash_meta_status', \WP_Test_State::$meta[951] ?? array() );
	}

	public function test_a_default_or_site_wide_template_needs_confirm_site_wide(): void {
		$this->put_template( 950, 'Sitio', array( '_et_default' => '1' ) );
		$this->put_template( 951, 'Todas', array( '_et_use_on' => array( 'singular:post_type:page:all' ) ) );
		$this->put_template( 952, 'Errores', array( '_et_use_on' => array( '404' ) ) );
		$before = $this->snapshot();

		foreach ( array( 950, 951, 952 ) as $id ) {
			$error = $this->assertError( $this->delete( array( 'id' => $id ) ), 'nexo_mcp_divi_template_site_wide' );
			$this->assertSame( array( 'path' => 'confirm_site_wide' ), $error->get_error_data() );
			$this->assertSame( $before, $this->snapshot() );
		}

		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );

		$ok = $this->delete( array( 'id' => 950, 'confirm_site_wide' => true ) );

		$this->assertIsArray( $ok );
		$this->assertTrue( $ok['was_default'] );
		$this->assertTrue( $ok['site_wide'] );
		$this->assertSame( array( '951', '952' ), $this->mapping() );
	}

	public function test_a_foreign_template_needs_replace_foreign_and_is_summarised(): void {
		$this->put_template( 950, 'Del VB', array( '_et_use_on' => array( 'archive:user:role:editor' ), '_et_library_item_id' => '9' ) );
		$before = $this->snapshot();

		$error = $this->assertError( $this->delete( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_foreign' );

		$this->assertSame( 950, $error->get_error_data()['id'] );
		$this->assertFalse( $error->get_error_data()['summary']['ours'] );
		$this->assertSame( $before, $this->snapshot() );

		$ok = $this->delete( array( 'id' => 950, 'replace_foreign' => true ) );

		$this->assertIsArray( $ok );
		$this->assertSame( array(), $this->mapping() );
		$this->assertSame( 'trash', \WP_Test_State::$posts[950]->post_status );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'sin id'                       => array( array(), 'id' ),
			'id como texto'                => array( array( 'id' => '950' ), 'id' ),
			'id cero'                      => array( array( 'id' => 0 ), 'id' ),
			'confirm_site_wide no booleano' => array( array( 'id' => 950, 'confirm_site_wide' => 'true' ), 'confirm_site_wide' ),
			'replace_foreign no booleano'  => array( array( 'id' => 950, 'replace_foreign' => 1 ), 'replace_foreign' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @param array<string, mixed> $input Entrada inválida.
	 */
	public function test_invalid_input_changes_nothing_and_names_the_path( array $input, string $path ): void {
		$this->put_template( 950, 'Plantilla' );
		$before = $this->snapshot();

		$error = $this->assertError( $this->delete( $input ), 'nexo_mcp_divi_template_invalid' );

		$this->assertSame( array( 'path' => $path ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function missing_targets(): array {
		return array(
			'no existe'               => array( 4040 ),
			'es una página'           => array( 100 ),
			'es un layout'            => array( self::BODY ),
			'es el Theme Builder'     => array( self::TB ),
			'no está en el en vivo'   => array( 960 ),
			'ya está en la papelera'  => array( 961 ),
		);
	}

	/**
	 * @dataProvider missing_targets
	 */
	public function test_an_id_that_is_not_a_live_template_is_not_found_and_nothing_is_trashed( int $id ): void {
		$this->put_template( 950, 'Otra' );
		$this->put_template( 960, 'Suelta', array(), false );
		$this->put_template( 961, 'Papelera', array(), true, 'trash' );
		$before = $this->snapshot();

		$error = $this->assertError( $this->delete( array( 'id' => $id ) ), 'nexo_mcp_divi_template_not_found' );

		$this->assertSame( array( 'id' => $id ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_without_a_live_builder_there_is_nothing_to_delete(): void {
		unset( \WP_Test_State::$posts[ self::TB ] );
		$this->put_template( 950, 'Plantilla', array(), false );
		$before = $this->snapshot();

		$this->assertError( $this->delete( array( 'id' => 950 ) ), 'nexo_mcp_divi_template_not_found' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_a_failed_trash_puts_the_template_back_in_the_mapping(): void {
		$this->put_template( 950, 'Otra' );
		$this->put_template( 951, 'Inicio', array( '_et_use_on' => array( 'homepage' ) ) );
		$before = $this->template_state( 951 );

		\WP_Test_State::$fail_trash = true;
		$result                    = $this->delete( array( 'id' => 951 ) );

		$this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		\WP_Test_State::$fail_trash = false;
		$this->assertSame( $before, $this->template_state( 951 ) );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_busy_lock_changes_nothing(): void {
		$this->put_template( 950, 'Plantilla' );
		$before = $this->snapshot();

		\WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] = 'otro|' . ( time() + 100 );
		PostLock::$wait                                                         = 0.0;
		$result = $this->delete( array( 'id' => 950 ) );

		$this->assertError( $result, 'nexo_mcp_busy' );
		unset( \WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_lock_lost_before_writing_changes_nothing(): void {
		$this->put_template( 950, 'Plantilla' );
		$before = $this->template_state( 950 );

		$this->lose_lock_after_acquiring( DiviTemplateStore::LOCK_ID );

		$this->assertError( $this->delete( array( 'id' => 950 ) ), 'nexo_mcp_busy' );
		$this->assertSame( $before, $this->template_state( 950 ) );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_the_css_warning_is_part_of_the_answer_when_divi_would_not_clear_it(): void {
		$this->put_template( 950, 'Plantilla' );
		\WP_Test_State::$caps['edit_posts'] = false;

		$result = $this->delete( array( 'id' => 950 ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cache']['css'] );
		$this->assertStringContainsString( 'edit_posts', implode( ' ', $result['warnings'] ) );
	}

	public function test_without_a_backup_nothing_is_deleted(): void {
		$this->put_template( 950, 'Otra' );
		$this->put_template( 951, 'Inicio', array( '_et_use_on' => array( 'homepage' ) ) );
		$before = $this->snapshot();

		\WP_Test_State::$json_fail_in = 'save_backup';
		$result                       = $this->delete( array( 'id' => 951 ) );
		\WP_Test_State::$json_fail_in = '';

		$this->assertError( $result, 'nexo_mcp_divi_template_backup_failed' );
		$this->assertSame( $before, $this->snapshot(), 'sigue publicada, en el mapeo y sin respaldo nuevo' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[951]->post_status );
		$this->assertSame( array( '950', '951' ), $this->mapping() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}
}
