<?php
/**
 * list-divi-theme-builder (T6): registro, permiso del Theme Builder, lectura sin efectos, plantillas con contextos tipados,
 * ranuras, quién gana y qué tapa a cuál, catálogo y avisos.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviListThemeBuilderToolTest extends NexoTestCase {

	use DiviTemplateFixtures;

	private const LIST = 'list-divi-theme-builder';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_divi_templates();
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return mixed
	 */
	private function run_list( array $settings = array() ) {
		return $this->tool( self::LIST, $settings )['execute_callback']( array() );
	}

	/**
	 * @param array<int, array<string, mixed>> $templates Lista de la salida.
	 * @return array<string, mixed>
	 */
	private function by_id( array $templates, int $id ): array {
		foreach ( $templates as $template ) {
			if ( $template['id'] === $id ) {
				return $template;
			}
		}

		$this->fail( "No está la plantilla $id" );
	}

	/**
	 * @param array<int, array<string, mixed>> $warnings Avisos.
	 * @return array<int, array<string, mixed>>
	 */
	private function warnings_of( array $warnings, string $code ): array {
		return array_values( array_filter( $warnings, static fn( array $warning ): bool => $code === $warning['code'] ) );
	}

	public function test_it_is_registered_as_a_read_tool_with_an_empty_closed_schema_and_warns_about_the_whole_site(): void {
		$tool = $this->tool( self::LIST );

		$this->assertContains( 'nexo-mcp/' . self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertTrue( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array(), $tool['input_schema']['properties'] );

		foreach ( array( 'mcp:divi_theme_builder', 'todo el sitio', 'UNA sola plantilla', 'gana', 'default', 'use_on', 'exclude_from', 'divi/post-content', 'publish', '7 días', 'auto-draft', 'ours', 'winner', 'shadows', 'catalog', 'nexo_mcp_divi_template_denied', 'nexo_mcp_divi5_required' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_permission_is_the_theme_builder_one_also_for_reading(): void {
		$this->assertTrue( $this->tool( self::LIST, array( 'builders_tools_enabled' => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tool( self::LIST, array( self::SWITCH => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tool( self::LIST, array( 'read_only' => true ) )['permission_callback']( array() ) );

		foreach ( array( 'edit_theme_options', 'edit_others_posts', 'nexo_mcp_divi_theme_builder' ) as $cap ) {
			$this->setUp();
			\WP_Test_State::$caps[ $cap ] = false;
			$this->assertNotTrue( $this->tool( self::LIST )['permission_callback']( array() ), $cap );
		}
	}

	public function test_without_the_permission_nothing_is_read_and_the_error_is_the_template_one(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Plantilla' );
		$before = $this->snapshot();

		$result = $this->run_list( array( self::SWITCH => false ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_template_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_without_a_live_builder_it_lists_nothing_and_creates_nothing(): void {
		$before = $this->snapshot();

		$result = $this->run_list();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id' => null, 'exists' => false ), $result['live'] );
		$this->assertSame( array(), $result['templates'] );
		$this->assertNull( $result['default_template'] );
		$this->assertFalse( $result['draft']['exists'] );
		$this->assertNotSame( array(), $result['catalog'] );
		$this->assertSame( $before, $this->snapshot(), 'una lectura no crea el Theme Builder' );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_it_notes_the_visual_builder_draft_and_leaves_it_alone(): void {
		$this->put_live_tb();
		$this->put_live_tb( 901, 'auto-draft' );
		$before = $this->snapshot();

		$result = $this->run_list();

		$this->assertSame( array( 'id' => self::TB, 'exists' => true ), $result['live'] );
		$this->assertTrue( $result['draft']['exists'] );
		$this->assertSame( 901, $result['draft']['id'] );
		$this->assertStringContainsString( 'auto-draft', $result['draft']['note'] );
		$this->assertStringContainsString( '7 días', $result['draft']['note'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_it_lists_templates_with_typed_contexts_slots_and_who_wins_or_shadows(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Sitio', array( '_et_default' => '1', '_et_header_layout_id' => (string) self::HEADER, '_et_footer_layout_id' => (string) self::FOOTER ) );
		$this->put_template( 951, 'Páginas', array( '_et_use_on' => array( 'singular:post_type:page:all' ), '_et_exclude_from' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY ) );
		$this->put_template( 952, 'Inicio', array( '_et_use_on' => array( 'homepage' ), '_et_header_layout_enabled' => '0' ) );
		$this->put_template( 953, 'Del Visual Builder', array( '_et_use_on' => array( 'archive:user:role:editor', 'archive:all' ), '_et_library_item_id' => '9' ) );
		$before = $this->snapshot();

		$result = $this->run_list();

		$this->assertIsArray( $result );
		$this->assertSame( array( 950, 951, 952, 953 ), array_column( $result['templates'], 'id' ), 'en el orden del mapeo' );
		$this->assertSame( 950, $result['default_template'] );

		$site = $this->by_id( $result['templates'], 950 );
		$this->assertTrue( $site['ours'] );
		$this->assertSame( 'publish', $site['status'] );
		$this->assertSame( 'Sitio', $site['title'] );
		$this->assertTrue( $site['enabled'] );
		$this->assertTrue( $site['default'] );
		$this->assertTrue( $site['site_wide'] );
		$this->assertSame( array(), $site['use_on'] );
		$this->assertSame( self::HEADER, $site['header']['layout_id'] );
		$this->assertTrue( $site['header']['enabled'] );
		$this->assertSame( 'publish', $site['header']['status'] );
		$this->assertSame( self::FOOTER, $site['footer']['layout_id'] );
		$this->assertNull( $site['body']['layout_id'] );

		$pages = $this->by_id( $result['templates'], 951 );
		$this->assertSame( array( array( 'id' => 'singular:post_type:page:all', 'supported' => true, 'priority' => 70 ) ), $pages['use_on'] );
		$this->assertSame( array( array( 'id' => 'homepage', 'supported' => true, 'priority' => 110 ) ), $pages['exclude_from'] );
		$this->assertSame( self::BODY, $pages['body']['layout_id'] );
		$this->assertTrue( $pages['body']['has_post_content'] );

		$home = $this->by_id( $result['templates'], 952 );
		$this->assertFalse( $home['header']['enabled'], 'un encabezado oculto' );
		$this->assertNull( $home['header']['layout_id'] );

		$foreign = $this->by_id( $result['templates'], 953 );
		$this->assertFalse( $foreign['ours'] );
		$this->assertSame( array( 'id' => 'archive:user:role:editor', 'supported' => false, 'priority' => null ), $foreign['use_on'][0], 'los ids desconocidos se conservan y se marcan' );
		$this->assertSame( array( 'id' => 'archive:all', 'supported' => true, 'priority' => 30 ), $foreign['use_on'][1] );
		$this->assertTrue( $foreign['site_wide'] );

		$contexts = array_column( $result['contexts'], null, 'context' );
		$this->assertSame( 951, $contexts['singular:post_type:page:all']['winner'] );
		$this->assertSame( 'use_on', $contexts['singular:post_type:page:all']['via'] );
		$this->assertSame( 70, $contexts['singular:post_type:page:all']['priority'] );
		$this->assertSame( array( 951 ), $contexts['singular:post_type:page:all']['templates'] );
		$this->assertSame( 952, $contexts['homepage']['winner'] );
		$this->assertSame( 953, $contexts['archive:all']['winner'] );

		$this->assertNotSame( array(), array_filter( $result['shadows'], static fn( array $row ): bool => 950 === $row['template'] && 951 === $row['covered_by'] ), 'la de páginas tapa a la por defecto' );

		$this->assertContains( 'homepage', array_column( $result['catalog'], 'pattern' ) );
		$this->assertSame( array( 'max_templates' => 50, 'max_contexts' => 20, 'max_title' => 100 ), $result['limits'] );
		$this->assertSame( $before, $this->snapshot(), 'leer no cambia nada' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_disabled_template_neither_wins_nor_shadows(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Páginas', array( '_et_use_on' => array( 'singular:post_type:page:all' ) ) );
		$this->put_template( 951, 'Apagada', array( '_et_enabled' => '0', '_et_use_on' => array( 'singular:post_type:page:all', 'homepage' ) ) );

		$result   = $this->run_list();
		$contexts = array_column( $result['contexts'], null, 'context' );

		$this->assertFalse( $this->by_id( $result['templates'], 951 )['enabled'] );
		$this->assertSame( 950, $contexts['singular:post_type:page:all']['winner'] );
		$this->assertNull( $contexts['homepage']['winner'] );
		$this->assertSame( array(), $result['shadows'] );
	}

	public function test_it_warns_about_a_body_without_post_content_on_content_contexts_only(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Páginas', array( '_et_use_on' => array( 'singular:post_type:page:all' ), '_et_body_layout_id' => (string) self::BODY_BARE ) );
		$this->put_template( 951, 'Archivos', array( '_et_use_on' => array( 'archive:all' ), '_et_body_layout_id' => (string) self::BODY_BARE ) );
		$this->put_template( 952, 'Con contenido', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY ) );
		$this->put_template( 953, 'Apagada', array( '_et_enabled' => '0', '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY_BARE ) );
		$this->put_template( 954, 'Cuerpo oculto', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY_BARE, '_et_body_layout_enabled' => '0' ) );
		$this->put_template( 955, 'Por defecto', array( '_et_default' => '1', '_et_body_layout_id' => (string) self::BODY_BARE ) );
		$this->put_template( 956, 'Ajena', array( '_et_use_on' => array( 'woocommerce:cart' ), '_et_body_layout_id' => (string) self::BODY_BARE, '_et_library_item_id' => '9' ) );

		$result   = $this->run_list();
		$warnings = $this->warnings_of( $result['warnings'], 'body_replaces_content' );

		$this->assertSame( array( 950, 955, 956 ), array_column( $warnings, 'template_id' ) );
		$this->assertSame( array( self::BODY_BARE, self::BODY_BARE, self::BODY_BARE ), array_column( $warnings, 'layout_id' ) );
		$this->assertStringContainsString( 'divi/post-content', $warnings[0]['message'] );
		$this->assertStringContainsString( 'REEMPLAZA', $warnings[0]['message'] );
		$this->assertFalse( $this->by_id( $result['templates'], 950 )['body']['has_post_content'] );
	}

	public function test_it_warns_about_layouts_that_are_not_published_or_not_of_the_slot_type_or_missing(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Borrador', array( '_et_use_on' => array( 'homepage' ), '_et_header_layout_id' => (string) self::HEADER_DRAFT ) );
		$this->put_template( 951, 'Tipo cruzado', array( '_et_use_on' => array( 'search' ), '_et_header_layout_id' => (string) self::BODY ) );
		$this->put_template( 952, 'Perdido', array( '_et_use_on' => array( 'archive:date:all' ), '_et_footer_layout_id' => '4040' ) );

		$result = $this->run_list();

		$not_published = $this->warnings_of( $result['warnings'], 'layout_not_published' );
		$this->assertCount( 1, $not_published );
		$this->assertSame( 950, $not_published[0]['template_id'] );
		$this->assertSame( self::HEADER_DRAFT, $not_published[0]['layout_id'] );
		$this->assertSame( 'header', $not_published[0]['slot'] );
		$this->assertStringContainsString( 'draft', $not_published[0]['message'] );
		$this->assertStringContainsString( 'público', $not_published[0]['message'] );
		$this->assertSame( 'draft', $this->by_id( $result['templates'], 950 )['header']['status'] );

		$invalid = $this->warnings_of( $result['warnings'], 'layout_invalid' );
		$this->assertSame( array( 951, 952 ), array_column( $invalid, 'template_id' ) );
		$this->assertSame( 'missing', $this->by_id( $result['templates'], 952 )['footer']['status'] );
	}

	public function test_it_warns_about_what_divi_will_send_to_the_trash_and_about_rows_and_defaults_that_do_not_add_up(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Marcada', array( '_et_use_on' => array( 'homepage' ), '_et_body_layout_id' => (string) self::BODY ) );
		update_post_meta( 950, '_et_theme_builder_marked_as_unused', '1700000000' );
		update_post_meta( self::BODY, '_et_theme_builder_marked_as_unused', '1700000000' );
		$this->put_template( 951, 'Por defecto A', array( '_et_default' => '1' ) );
		$this->put_template( 952, 'Por defecto B', array( '_et_default' => '1' ) );
		add_post_meta( self::TB, '_et_template', '4040' );
		add_post_meta( self::TB, '_et_template', '100' );
		$this->put_template( 953, 'En la papelera', array(), true, 'trash' );

		$result = $this->run_list();

		$scheduled = $this->warnings_of( $result['warnings'], 'scheduled_for_trash' );
		$this->assertCount( 2, $scheduled );
		$this->assertSame( 950, $scheduled[0]['template_id'] );
		$this->assertArrayNotHasKey( 'layout_id', $scheduled[0] );
		$this->assertSame( self::BODY, $scheduled[1]['layout_id'] );
		$this->assertStringContainsString( '7 días', $scheduled[0]['message'] );

		$defaults = $this->warnings_of( $result['warnings'], 'multiple_defaults' );
		$this->assertCount( 1, $defaults );
		$this->assertSame( array( 951, 952 ), $defaults[0]['template_ids'] );
		$this->assertSame( 951, $result['default_template'], 'la de menor id, como Divi' );

		$orphans = $this->warnings_of( $result['warnings'], 'orphan_mapping' );
		$this->assertSame( array( 4040, 100 ), array_column( $orphans, 'template_id' ), 'una fila que no apunta a una plantilla' );
		$this->assertSame( array( 950, 951, 952, 953 ), array_column( $result['templates'], 'id' ) );
		$this->assertSame( 'trash', $this->by_id( $result['templates'], 953 )['status'] );
		$this->assertCount( 1, $this->warnings_of( $result['warnings'], 'template_trashed' ) );
	}

	public function test_a_template_with_junk_metas_is_listed_as_foreign_without_throwing(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Basura', array( '_et_use_on' => array( 'x', 'singular:post_type:page:id:abc' ), '_et_enabled' => 'quizá', '_et_body_layout_id' => 'no' ) );

		$result = $this->run_list();

		$template = $this->by_id( $result['templates'], 950 );
		$this->assertFalse( $template['ours'] );
		$this->assertNull( $template['enabled'] );
		$this->assertNull( $template['body']['layout_id'] );
		$this->assertSame( array( false, false ), array_column( $template['use_on'], 'supported' ) );
	}

	public function test_it_does_not_leak_the_backup_or_unsafe_text(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Plantilla<script>', array( '_et_use_on' => array( 'a b<script>' ) ) );
		update_post_meta( 950, '_nexo_mcp_template_backup', '{"secreto":"x"}' );

		$json = (string) wp_json_encode( $this->run_list() );

		$this->assertStringNotContainsString( 'secreto', $json );
		$this->assertStringNotContainsString( 'a b<script>', $json );
	}
}
