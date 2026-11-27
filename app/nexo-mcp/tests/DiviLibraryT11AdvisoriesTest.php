<?php
/**
 * Avisos de las reviews de T9 y T10 que cierra T11 (S1, S3, S7): el título de `affected_pages` sin `read_post`, un solo escaneo de
 * uso por llamada y la categoría con el mismo slug que otra.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use NexoDev\NexoMCP\Builders\DiviLibrarySchema;
use NexoDev\NexoMCP\Builders\DiviLibraryUsage;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryT11AdvisoriesTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library();
		require_once __DIR__ . '/DiviLibraryGlobalStubs.php';

		$GLOBALS['wpdb']    = new \WP_Library_Wpdb_Double();
		GlobalLayout::$mode = 'detach';
		add_filter( 'pre_trash_post', array( GlobalLayout::class, 'preserve_instances_before_delete' ), 10, 2 );
	}

	private function refpage( int $id, string $status = 'publish', string $title = '' ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => 'page', 'post_status' => $status, 'post_title' => '' === $title ? 'R' . $id : $title, 'post_content' => 'x <!-- wp:divi/global-layout {"globalModule":"803","blockName":"divi/text"} /-->' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $status, string $title ): array {
		return array(
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => '<!-- wp:divi/global-layout {"globalModule":"5","blockName":"divi/text"} /-->',
		);
	}

	// R1 de review-d7a0210acf1a07a4.
	public function test_the_title_of_a_content_the_user_cannot_read_is_hidden_but_the_rest_of_the_entry_stays(): void {
		$rows  = array( $this->row( 10, 'private', 'Despido de Ana' ), $this->row( 11, 'publish', 'Inicio' ) );
		$fetch = static fn( int $after ): array => 0 === $after ? $rows : array();
		$pages = ( new DiviLibraryUsage( $fetch, static fn(): bool => true, static fn( int $id ): bool => 11 === $id ) )->of( 5 )['pages'];

		$this->assertSame( array( 10, 11 ), array_column( $pages, 'id' ), 'sigue contando y listando el contenido' );
		$this->assertSame( '', $pages[0]['title'] );
		$this->assertTrue( $pages[0]['title_hidden'] );
		$this->assertSame( 'private', $pages[0]['status'] );
		$this->assertSame( 'page', $pages[0]['post_type'] );
		$this->assertSame( 'Inicio', $pages[1]['title'] );
		$this->assertArrayNotHasKey( 'title_hidden', $pages[1] );
		$this->assertStringNotContainsString( 'Despido', (string) json_encode( $pages ) );
	}

	public function test_a_permission_check_that_throws_hides_the_title(): void {
		$fetch = fn( int $after ): array => 0 === $after ? array( $this->row( 10, 'publish', 'Secreto' ) ) : array();
		$pages = ( new DiviLibraryUsage( $fetch, static fn(): bool => true, static function (): bool {
			throw new \RuntimeException( 'x' );
		} ) )->of( 5 )['pages'];

		$this->assertSame( '', $pages[0]['title'] );
	}

	public function test_the_tools_never_return_the_title_of_a_content_without_read_post(): void {
		$this->refpage( 120, 'private', 'Secreto de Ana' );
		\WP_Test_State::$caps['read_post'] = false;

		$result = $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'V2', 'confirm_global' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 120 ), array_column( $result['affected_pages']['pages'], 'id' ) );
		$this->assertStringNotContainsString( 'Secreto', (string) json_encode( $result ) );

		$deleted = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );

		$this->assertIsArray( $deleted );
		$this->assertStringNotContainsString( 'Secreto', (string) json_encode( $deleted ) );

		\WP_Test_State::$caps['read_post'] = true;
		$this->refpage( 121, 'publish', 'Visible' );
		$restored = $this->run_tool( 'restore-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );

		$this->assertIsArray( $restored );
		$this->assertContains( 'Visible', array_column( $restored['affected_pages']['pages'], 'title' ), 'con read_post el título sí sale' );
	}

	// R4 de review-d7a0210acf1a07a4.
	public function test_each_call_scans_the_site_once_for_the_pre_check_and_the_response(): void {
		$this->refpage( 120 );
		$wpdb = $GLOBALS['wpdb'];

		$this->assertIsArray( $this->run_tool( 'save-divi-library-item', array( 'id' => self::GLOBAL, 'title' => 'V2', 'confirm_global' => true ) ) );
		$this->assertSame( 1, $wpdb->queries, 'guardar: un solo escaneo' );

		$wpdb->queries = 0;
		$this->assertIsArray( $this->run_tool( 'restore-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) ) );
		$this->assertSame( 1, $wpdb->queries, 'restaurar: un solo escaneo' );

		$wpdb->queries = 0;
		$result        = $this->run_tool( 'delete-divi-library-item', array( 'id' => self::GLOBAL, 'confirm_global' => true ) );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $wpdb->queries, 'borrar: uno antes (para el permiso y la respuesta) y otro después, para comprobar que Divi despegó todo' );
		$this->assertSame( 0, $result['still_referenced'] );
	}

	public function test_the_scan_only_brings_rows_that_mention_the_item_id(): void {
		( new DiviLibraryUsage() )->of( self::GLOBAL );

		$this->assertStringContainsString( "'%" . self::GLOBAL . "%'", $GLOBALS['wpdb']->last_sql, 'el id tiene que aparecer en el contenido: es condición necesaria de cualquier forma de la referencia' );
	}

	// Aviso de review-f0d277ef09919679.
	public function test_a_category_whose_slug_exists_under_another_name_is_reused_not_duplicated(): void {
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Hero Section' => 91 );
		$before = \WP_Library_Stub_State::registry();

		$this->assertSame( array(), ( new DiviLibrarySchema() )->ensure_categories( array( 'Hero-Section' ) ), 'ya existe (mismo slug): no crea ni cuenta como suya' );
		$this->assertSame( $before, \WP_Library_Stub_State::registry(), 'no queda un duplicado vacío' );
	}

	public function test_a_new_category_is_still_created_and_reported(): void {
		$created = ( new DiviLibrarySchema() )->ensure_categories( array( 'Totalmente nueva' ) );

		$this->assertCount( 1, $created );
		$this->assertArrayHasKey( 'Totalmente nueva', \WP_Library_Stub_State::registry()['layout_category'] );
	}

	public function test_saving_with_a_category_that_only_differs_by_punctuation_leaves_one_term(): void {
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Hero Section' => 91 );
		$result = $this->run_tool( 'save-divi-library-item', array( 'id' => self::ROW, 'categories' => array( 'Hero-Section' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'Hero Section', 'Héroes' ), array_keys( \WP_Library_Stub_State::registry()['layout_category'] ), 'el término que ya existía, sin uno nuevo (Héroes es el de otro ítem)' );
	}

	public function test_the_stub_creates_a_duplicate_like_wordpress_so_the_guard_is_what_prevents_it(): void {
		\WP_Library_Stub_State::$known['layout_category'] = array( 'Hero Section' => 91 );

		$this->assertIsArray( wp_insert_term( 'Hero-Section', 'layout_category' ), 'WordPress crea el término nuevo con otro slug' );
		$this->assertArrayHasKey( 'Hero-Section', \WP_Library_Stub_State::registry()['layout_category'] );
		$this->assertInstanceOf( \WP_Error::class, wp_insert_term( 'hero section', 'layout_category' ), 'el mismo nombre sí da term_exists' );
	}
}
