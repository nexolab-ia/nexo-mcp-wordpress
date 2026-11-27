<?php
/**
 * list-divi-library y get-divi-library-item (T9): registro, permiso, filtros, ítems en la papelera o en borrador automático,
 * módulos ajenos y que ninguna lectura cambia nada.
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
final class DiviLibraryReadToolsTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	private const LIST = 'list-divi-library';
	private const GET  = 'get-divi-library-item';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
	}

	public function test_both_tools_are_registered_as_read_tools_with_closed_schemas(): void {
		$list = $this->tool( self::LIST );
		$get  = $this->tool( self::GET );

		$this->assertContains( 'nexo-mcp/' . self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertContains( 'nexo-mcp/' . self::GET, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertTrue( $list['meta']['annotations']['readonly'] );
		$this->assertTrue( $get['meta']['annotations']['readonly'] );
		$this->assertFalse( $list['meta']['annotations']['destructive'] );
		$this->assertFalse( $list['input_schema']['additionalProperties'] );
		$this->assertFalse( $get['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'type', 'scope', 'category', 'search', 'limit' ), array_keys( $list['input_schema']['properties'] ) );
		$this->assertSame( array( 'layout', 'section', 'row', 'module' ), $list['input_schema']['properties']['type']['enum'] );
		$this->assertSame( array( 'id' ), array_keys( $get['input_schema']['properties'] ) );
		$this->assertSame( array( 'id' ), $get['input_schema']['required'] );

		foreach ( array( 'mcp:divi_library', 'nexo_mcp_divi_library_denied', 'nexo_mcp_divi5_required', 'papelera' ) as $needle ) {
			$this->assertStringContainsString( $needle, $list['description'], $needle );
			$this->assertStringContainsString( $needle, $get['description'], $needle );
		}

		$this->assertStringContainsString( 'nexo_mcp_divi_library_trashed', $get['description'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_library_not_found', $get['description'] );
	}

	public function test_without_the_library_scope_switch_neither_tool_answers_and_nothing_changes(): void {
		$before = $this->snapshot();

		foreach ( array( self::LIST => array(), self::GET => array( 'id' => self::SECTION ) ) as $name => $input ) {
			$this->assertNotTrue( $this->tool( $name, array( self::SWITCH => false ) )['permission_callback']( $input ) );
			$this->assertError( $this->run_tool( $name, $input, array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
		}

		$this->assertTrue( $this->tool( self::LIST, array( 'builders_tools_enabled' => false ) )['permission_callback']( array() ) );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_list_shows_live_items_and_leaves_out_the_trash_auto_drafts_and_other_post_types(): void {
		\WP_Test_State::$posts[ 810 ] = new \WP_Post( array( 'ID' => 810, 'post_type' => 'et_template', 'post_status' => 'publish', 'post_title' => 'Plantilla' ) );
		$this->put_item( 811, 'Borrador', 'layout', 'non_global', 'draft' );
		$before = $this->snapshot();

		$result = $this->run_tool( self::LIST );

		$this->assertIsArray( $result );
		$this->assertSame( array( 811, self::FOREIGN, self::GLOBAL, self::MODULE, self::ROW, self::SECTION ), array_column( $result['items'], 'id' ) );
		$this->assertSame( 6, $result['count'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( array( 'max_list' => 100, 'max_title' => 100, 'max_categories' => 10 ), $result['limits'] );

		$section = array_values( array_filter( $result['items'], static fn( array $i ): bool => self::SECTION === $i['id'] ) )[0];
		$this->assertSame(
			array( 'id' => self::SECTION, 'title' => 'Sección azul', 'status' => 'publish', 'type' => 'section', 'scope' => 'non_global', 'categories' => array( 'Héroes' ), 'module_type' => '', 'modified' => '2026-01-01 00:00:00' ),
			$section
		);
		$this->assertSame( 'global', array_values( array_filter( $result['items'], static fn( array $i ): bool => self::GLOBAL === $i['id'] ) )[0]['scope'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_each_filter_narrows_the_list(): void {
		$this->assertSame( array( self::ROW ), array_column( $this->run_tool( self::LIST, array( 'type' => 'row' ) )['items'], 'id' ) );
		$this->assertSame( array( self::GLOBAL ), array_column( $this->run_tool( self::LIST, array( 'scope' => 'global' ) )['items'], 'id' ) );
		$this->assertSame( array( self::SECTION ), array_column( $this->run_tool( self::LIST, array( 'category' => 'héroes' ) )['items'], 'id' ) );
		$this->assertSame( array( self::SECTION ), array_column( $this->run_tool( self::LIST, array( 'search' => 'AZUL' ) )['items'], 'id' ) );
		$this->assertSame( array(), $this->run_tool( self::LIST, array( 'search' => 'no existe' ) )['items'] );
	}

	public function test_the_limit_truncates_and_says_so(): void {
		$result = $this->run_tool( self::LIST, array( 'limit' => 2 ) );

		$this->assertCount( 2, $result['items'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 2, $result['count'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function bad_filters(): array {
		return array(
			'tipo'        => array( array( 'type' => 'page' ), 'type' ),
			'ámbito'      => array( array( 'scope' => 'x' ), 'scope' ),
			'categoría'   => array( array( 'category' => array( 'a' ) ), 'category' ),
			'límite'      => array( array( 'limit' => 500 ), 'limit' ),
			'desconocido' => array( array( 'status' => 'trash' ), 'status' ),
		);
	}

	/**
	 * @dataProvider bad_filters
	 * @param array<string, mixed> $input Filtros inválidos.
	 */
	public function test_bad_filters_are_rejected_with_their_path_and_change_nothing( array $input, string $path ): void {
		$before = $this->snapshot();
		$error  = $this->assertError( $this->run_tool( self::LIST, $input ), 'nexo_mcp_divi_library_invalid' );

		$this->assertSame( $path, $error->get_error_data()['path'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_list_survives_items_whose_terms_cannot_be_read(): void {
		\WP_Test_State::$terms_error = 'layout_type';

		$result = $this->run_tool( self::LIST );

		$this->assertIsArray( $result );
		$this->assertSame( array( '' ), array_values( array_unique( array_column( $result['items'], 'type' ) ) ) );
	}

	public function test_get_returns_the_item_with_its_nodes_and_without_changing_anything(): void {
		$before = $this->snapshot();

		$result = $this->run_tool( self::GET, array( 'id' => self::SECTION ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::SECTION, $result['id'] );
		$this->assertSame( 'Sección azul', $result['title'] );
		$this->assertSame( 'section', $result['type'] );
		$this->assertSame( 'non_global', $result['scope'] );
		$this->assertSame( array( 'Héroes' ), $result['categories'] );
		$this->assertFalse( $result['global'] );
		$this->assertSame( array(), $result['foreign'] );
		$this->assertNull( $result['backup'] );
		$this->assertSame( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column', 'divi/text' ), array_column( $result['nodes'], 'type' ) );
		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.0' ), array_column( $result['nodes'], 'path' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['nodes'][4]['fingerprint'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_get_names_the_modules_outside_the_catalog_and_the_module_type(): void {
		$this->assertSame( array( 'divi/fullwidth-menu' ), $this->run_tool( self::GET, array( 'id' => self::FOREIGN ) )['foreign'] );
		$this->assertSame( 'divi/text', $this->run_tool( self::GET, array( 'id' => self::MODULE ) )['module_type'] );
	}

	public function test_get_reads_a_global_item_and_flags_it(): void {
		$result = $this->run_tool( self::GET, array( 'id' => self::GLOBAL ) );

		$this->assertTrue( $result['global'] );
		$this->assertSame( 'global', $result['scope'] );
	}

	public function test_get_rejects_a_trashed_item_and_an_auto_draft(): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::GET, array( 'id' => self::TRASHED ) ), 'nexo_mcp_divi_library_trashed' );
		$this->assertError( $this->run_tool( self::GET, array( 'id' => self::AUTO_DRAFT ) ), 'nexo_mcp_divi_library_not_found' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_get_denies_ids_that_are_not_library_items_without_revealing_them(): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( self::GET, array( 'id' => self::PAGE ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertError( $this->run_tool( self::GET, array( 'id' => 99999 ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_get_needs_an_integer_id(): void {
		foreach ( array( array(), array( 'id' => 'x' ), array( 'id' => 0 ), array( 'id' => 1.5 ) ) as $input ) {
			$error = $this->assertError( $this->run_tool( self::GET, $input ), 'nexo_mcp_divi_library_invalid' );

			$this->assertSame( 'id', $error->get_error_data()['path'] );
		}
	}
}
