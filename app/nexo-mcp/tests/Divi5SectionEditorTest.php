<?php
/**
 * edit-divi-section: replace/insert/delete por ruta y huella, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5SectionEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg = $this->settings( array( 'read_only' => false ) );
		$this->put_page( self::ORIGINAL, 'publish' );
	}

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	/**
	 * Dos secciones. Rutas: placeholder 0; A 0.0 (fila 0.0.0, columna 0.0.0.0, texto 0.0.0.0.0);
	 * B 0.1 (0.1.0, 0.1.0.0, texto 0.1.0.0.0, lista 0.1.0.0.1 con ítem 0.1.0.0.1.0).
	 */
	public static function page(): string {
		$raw = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>A</p>"},"tablet":{"value":"<p>A tablet</p>"}}}} /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>B</p>"}}}} /-->' . "\n"
			. '<!-- wp:divi/icon-list -->' . "\n"
			. '<!-- wp:divi/icon-list-item {"content":{"innerContent":{"desktop":{"value":"Uno"}}}} /-->' . "\n"
			. '<!-- /wp:divi/icon-list -->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	private static function one_section_page(): string {
		$raw = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Solo</p>"}}}} /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	private function put_page( int $id, string $status, ?string $content = null ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => 'Inicio',
				'post_content' => $content ?? self::page(),
			)
		);
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function node( string $content, string $path ): array {
		$node = Divi5Tree::locate( Divi5Tree::build( $content ), $path );
		$this->assertIsArray( $node );

		return $node;
	}

	/**
	 * Sección nueva con un texto.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function section( string $text = 'Nuevo' ): array {
		return array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array(
									array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . $text . '</p>' ) ) ) ) ),
								),
							),
						),
					),
				),
			),
		);
	}

	private function editor( ?callable $lock = null, ?callable $active = null ): Divi5SectionEditor {
		return new Divi5SectionEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name ),
			$lock ?? static fn() => true,
			$active ?? static fn(): bool => true,
			null,
			static fn(): string => '5.19.1'
		);
	}

	/**
	 * @param array<string, mixed> $input Parámetros que cambian.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_edit( array $input = array(), int $id = self::ORIGINAL, ?callable $lock = null, ?callable $active = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$path = isset( $input['path'] ) ? (string) $input['path'] : '0.0';

		return $this->editor( $lock, $active )->edit(
			$post,
			array_filter(
				array_merge(
					array(
						'operation'   => 'replace',
						'path'        => $path,
						'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path )['fingerprint'] ?? 'x' ),
						'sections'    => self::section(),
					),
					$input
				),
				static fn( $value ): bool => null !== $value
			)
		);
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result Resultado.
	 */
	private function assertRejected( string $code, $result, string $before ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'no escribe nada' );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result Resultado.
	 * @return array<string, mixed>
	 */
	private function ok( $result ): array {
		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * @return array<int, string> Textos de los módulos `divi/text` en orden de documento.
	 */
	private function texts( string $content ): array {
		$out  = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				if ( isset( $node['text'] ) && 'divi/text' === $node['type'] ) {
					$out[] = $node['text'];
				}
				$walk( $node['children'] ?? array() );
			}
		};
		$walk( Divi5Tree::build( $content ) );

		return $out;
	}

	public function test_replace_goes_to_the_working_copy_and_leaves_everything_else_byte_identical(): void {
		$result = $this->ok( $this->run_edit() );

		$copy = (int) $result['id'];
		$this->assertNotSame( self::ORIGINAL, $copy );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( 'draft', get_post( $copy )->post_status ); // @phpstan-ignore-line
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ), 'el original no cambia' );
		$this->assertSame( array( 'Nuevo', 'B' ), $this->texts( $this->content( $copy ) ) );

		$old = parse_blocks( self::page() );
		$new = parse_blocks( $this->content( $copy ) );
		$this->assertSame( serialize_block( Divi5Tree::locate_block( $old, '0.1' ) ), serialize_block( Divi5Tree::locate_block( $new, '0.1' ) ), 'la sección B queda igual' );
		$this->assertSame( 'replace', $result['operation'] );
		$this->assertSame( 'on', get_post_meta( $copy, '_et_pb_use_builder', true ) );
		$this->assertSame( 'on', get_post_meta( $copy, '_et_pb_use_divi_5', true ) );
		$this->assertTrue( ( new BuilderBackup() )->exists( $copy ) );
		$this->assertContains( $copy, \WP_Test_State::$cleaned );
	}

	public function test_replace_returns_the_new_paths_and_fingerprints_as_re_read(): void {
		$result  = $this->ok( $this->run_edit() );
		$content = $this->content( (int) $result['id'] );

		$paths = array_column( $result['nodes'], 'path' );
		$this->assertSame( array( '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.0' ), $paths );
		$this->assertSame( array( 'divi/section', 'divi/row', 'divi/column', 'divi/text' ), array_column( $result['nodes'], 'type' ) );

		foreach ( $result['nodes'] as $row ) {
			$this->assertSame( $this->node( $content, $row['path'] )['fingerprint'], $row['fingerprint'] );
		}
	}

	public function test_new_blocks_carry_the_installed_builder_version(): void {
		$result = $this->ok( $this->run_edit() );
		$block  = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), '0.0.0.0.0' );

		$this->assertSame( '5.19.1', $block['attrs']['builderVersion'] ); // @phpstan-ignore-line
	}

	public function test_replace_with_several_sections_and_a_second_edit_with_the_returned_fingerprint(): void {
		$two    = array_merge( self::section( 'Uno' ), self::section( 'Dos' ) );
		$result = $this->ok( $this->run_edit( array( 'sections' => $two ) ) );
		$copy   = (int) $result['id'];

		$this->assertSame( array( 'Uno', 'Dos', 'B' ), $this->texts( $this->content( $copy ) ) );

		$second = $this->ok( $this->run_edit( array( 'path' => '0.1', 'fingerprint' => $result['nodes'][4]['fingerprint'] ?? $this->node( $this->content( $copy ), '0.1' )['fingerprint'], 'sections' => self::section( 'Tres' ) ), $copy ) );
		$this->assertSame( $copy, $second['id'] );
		$this->assertSame( array( 'Uno', 'Tres', 'B' ), $this->texts( $this->content( $copy ) ) );
	}

	public function test_a_draft_is_edited_in_place(): void {
		$this->put_page( 101, 'draft' );

		$result = $this->ok( $this->run_edit( array(), 101 ) );

		$this->assertSame( 101, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( array( 'Nuevo', 'B' ), $this->texts( $this->content( 101 ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>, 4: string}>
	 */
	public static function inserts(): array {
		return array(
			'after A'         => array( '0.0', 'after', 'Nuevo', array( 'A', 'Nuevo', 'B' ), '0.1' ),
			'before A'        => array( '0.0', 'before', 'Nuevo', array( 'Nuevo', 'A', 'B' ), '0.0' ),
			'after B'         => array( '0.1', 'after', 'Nuevo', array( 'A', 'B', 'Nuevo' ), '0.2' ),
			'before B'        => array( '0.1', 'before', 'Nuevo', array( 'A', 'Nuevo', 'B' ), '0.1' ),
			'inside holder'   => array( '0', 'inside', 'Nuevo', array( 'A', 'B', 'Nuevo' ), '0.2' ),
		);
	}

	/**
	 * @dataProvider inserts
	 * @param array<int, string> $expected Textos esperados en orden.
	 */
	public function test_insert_places_the_sections_by_position_and_keeps_the_rest_identical( string $path, string $position, string $text, array $expected, string $new_path ): void {
		$result = $this->ok( $this->run_edit( array( 'operation' => 'insert', 'path' => $path, 'position' => $position, 'sections' => self::section( $text ) ) ) );
		$copy   = (int) $result['id'];
		$new    = $this->content( $copy );

		$this->assertSame( $expected, $this->texts( $new ) );
		$this->assertSame( $new_path, $result['nodes'][0]['path'] );
		$this->assertSame( 'divi/section', $this->node( $new, $new_path )['type'] );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );

		// Las dos secciones de antes siguen byte a byte (se localizan por su texto).
		$old = parse_blocks( self::page() );
		$all = parse_blocks( $new );
		foreach ( array( '0.0', '0.1' ) as $old_path ) {
			$needle = serialize_block( Divi5Tree::locate_block( $old, $old_path ) );
			$this->assertStringContainsString( $needle, serialize_blocks( $all ), $old_path );
		}
	}

	public function test_insert_inside_a_column_adds_a_module_at_the_end(): void {
		$module = array( array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Extra</p>' ) ) ) ) ) );

		$result = $this->ok( $this->run_edit( array( 'operation' => 'insert', 'path' => '0.0.0.0', 'position' => 'inside', 'sections' => $module ) ) );

		$this->assertSame( array( 'A', 'Extra', 'B' ), $this->texts( $this->content( (int) $result['id'] ) ) );
		$this->assertSame( '0.0.0.0.1', $result['nodes'][0]['path'] );
	}

	public function test_insert_an_icon_list_item_inside_an_icon_list(): void {
		$item   = array( array( 'type' => 'divi/icon-list-item', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Dos' ) ) ) ) ) );
		$result = $this->ok( $this->run_edit( array( 'operation' => 'insert', 'path' => '0.1.0.0.1', 'position' => 'inside', 'sections' => $item ) ) );

		$this->assertSame( 'divi/icon-list-item', $this->node( $this->content( (int) $result['id'] ), '0.1.0.0.1.1' )['type'] );
	}

	public function test_delete_removes_the_node_and_keeps_the_rest_identical(): void {
		$result = $this->ok( $this->run_edit( array( 'operation' => 'delete', 'sections' => null ) ) );
		$copy   = (int) $result['id'];

		$this->assertSame( array( 'B' ), $this->texts( $this->content( $copy ) ) );
		$this->assertSame( array(), $result['nodes'] );
		$this->assertSame( '0.0', $result['removed'] );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
		$old = serialize_block( Divi5Tree::locate_block( parse_blocks( self::page() ), '0.1' ) );
		$this->assertSame( $old, serialize_block( Divi5Tree::locate_block( parse_blocks( $this->content( $copy ) ), '0.0' ) ), 'B ahora es 0.0 y no cambió' );
	}

	public function test_delete_inside_a_section_is_allowed_and_a_node_below_a_replace_stays_untouched(): void {
		$result = $this->ok( $this->run_edit( array( 'operation' => 'delete', 'path' => '0.1.0.0.1', 'sections' => null ) ) );

		$this->assertNull( Divi5Tree::locate( Divi5Tree::build( $this->content( (int) $result['id'] ) ), '0.1.0.0.1' ) );
		$this->assertSame( array( 'A', 'B' ), $this->texts( $this->content( (int) $result['id'] ) ) );
	}

	public function test_deleting_the_last_root_section_is_refused_without_writing(): void {
		$this->put_page( 101, 'draft', self::one_section_page() );
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_last_section', $this->run_edit( array( 'operation' => 'delete', 'sections' => null ), 101 ), $before );
	}

	public function test_the_root_placeholder_cannot_be_replaced_deleted_or_given_siblings(): void {
		$before = $this->snapshot();

		foreach ( array(
			array( 'operation' => 'replace', 'path' => '0' ),
			array( 'operation' => 'delete', 'path' => '0', 'sections' => null ),
			array( 'operation' => 'insert', 'path' => '0', 'position' => 'before' ),
			array( 'operation' => 'insert', 'path' => '0', 'position' => 'after' ),
		) as $input ) {
			$this->assertRejected( 'nexo_mcp_divi_operation_invalid', $this->run_edit( $input ), $before );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'bad operation'            => array( 'nexo_mcp_divi_operation_invalid', array( 'operation' => 'move' ) ),
			'insert without position'  => array( 'nexo_mcp_divi_position_invalid', array( 'operation' => 'insert' ) ),
			'insert bad position'      => array( 'nexo_mcp_divi_position_invalid', array( 'operation' => 'insert', 'position' => 'next' ) ),
			'replace with position'    => array( 'nexo_mcp_divi_position_invalid', array( 'position' => 'after' ) ),
			'delete with sections'     => array( 'nexo_mcp_divi_sections_not_allowed', array( 'operation' => 'delete' ) ),
			'replace without sections' => array( 'nexo_mcp_divi_tree_invalid', array( 'sections' => null ) ),
			'empty sections'           => array( 'nexo_mcp_divi_tree_invalid', array( 'sections' => array() ) ),
			'module denied'            => array( 'nexo_mcp_divi_module_denied', array( 'sections' => array( array( 'type' => 'divi/code' ) ) ) ),
			'row where section goes'   => array( 'nexo_mcp_divi_nesting_invalid', array( 'sections' => array( array( 'type' => 'divi/row' ) ) ) ),
			'section in a column'      => array( 'nexo_mcp_divi_nesting_invalid', array( 'path' => '0.0.0.0.0', 'sections' => array( array( 'type' => 'divi/section' ) ) ) ),
			'text in a section'        => array( 'nexo_mcp_divi_nesting_invalid', array( 'path' => '0.0.0', 'sections' => array( array( 'type' => 'divi/text' ) ) ) ),
			'module in the holder'     => array( 'nexo_mcp_divi_nesting_invalid', array( 'operation' => 'insert', 'path' => '0', 'position' => 'inside', 'sections' => array( array( 'type' => 'divi/text' ) ) ) ),
			'item in a column'         => array( 'nexo_mcp_divi_nesting_invalid', array( 'operation' => 'insert', 'path' => '0.0.0.0', 'position' => 'inside', 'sections' => array( array( 'type' => 'divi/icon-list-item' ) ) ) ),
			'text in an icon list'     => array( 'nexo_mcp_divi_nesting_invalid', array( 'operation' => 'insert', 'path' => '0.1.0.0.1', 'position' => 'inside', 'sections' => array( array( 'type' => 'divi/text' ) ) ) ),
			'shortcode'                => array( 'nexo_mcp_divi_shortcode_denied', array( 'sections' => array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>[gallery ids="1"]</p>' ) ) ) ) ) ) ) ) ) ) ) ) ) ),
			'tablet attr'              => array( 'nexo_mcp_divi_attr_denied', array( 'path' => '0.0.0.0.0', 'sections' => array( array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'tablet' => array( 'value' => 'x' ) ) ) ) ) ) ) ),
			'missing path'             => array( 'nexo_mcp_builder_node_missing', array( 'path' => '0.9' ) ),
			'bad path'                 => array( 'nexo_mcp_builder_node_missing', array( 'path' => "0.0\n" ) ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 * @param array<string, mixed> $input Parámetros que cambian.
	 */
	public function test_an_invalid_request_is_rejected_with_its_code_and_nothing_is_written( string $code, array $input ): void {
		$before = $this->snapshot();

		$result = $this->run_edit( $input );

		$this->assertRejected( $code, $result, $before );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
	}

	public function test_a_stale_fingerprint_is_rejected_without_writing_or_creating_a_copy(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->run_edit( array( 'fingerprint' => str_repeat( 'a', 64 ) ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->run_edit( array( 'fingerprint' => '' ) ), $before );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
	}

	public function test_the_fingerprint_of_a_node_that_changed_after_the_read_is_stale(): void {
		$first = $this->ok( $this->run_edit() );
		$old   = $this->node( self::page(), '0.0' )['fingerprint'];
		$copy  = (int) $first['id'];
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->run_edit( array( 'fingerprint' => $old ), $copy ), $before );
	}

	public function test_a_page_that_is_not_divi5_is_rejected_without_writing(): void {
		$this->put_page( 102, 'publish', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$before = $this->snapshot();

		$result = $this->editor()->edit( get_post( 102 ), array( 'operation' => 'delete', 'path' => '0', 'fingerprint' => 'x' ) ); // @phpstan-ignore-line

		$this->assertRejected( 'nexo_mcp_divi_not_divi5', $result, $before );
	}

	public function test_without_divi5_active_it_asks_for_divi5_and_writes_nothing(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi5_required', $this->run_edit( array(), self::ORIGINAL, null, static fn(): bool => false ), $before );
	}

	public function test_drafts_only_mode_refuses_to_write_live_without_a_copy(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'drafts_only' => true, 'working_copy_default' => false ) );
		$before    = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_draft_required', $this->run_edit(), $before );
	}

	public function test_an_edit_lock_held_by_someone_else_blocks_the_write(): void {
		$before = $this->snapshot();
		$lock   = static fn() => new \WP_Error( 'nexo_mcp_builder_locked', 'bloqueado' );

		$this->assertRejected( 'nexo_mcp_builder_locked', $this->run_edit( array(), self::ORIGINAL, $lock ), $before );
	}

	public function test_kses_filters_are_removed_only_during_the_save_and_restored(): void {
		\WP_Test_State::$kses_active = true;

		$this->ok( $this->run_edit() );

		$this->assertSame( false, end( \WP_Test_State::$kses_during_save ) );
		$this->assertTrue( \WP_Test_State::$kses_active );
	}

	public function test_a_failed_save_restores_kses_and_discards_the_fresh_working_copy(): void {
		\WP_Test_State::$kses_active      = true;
		\WP_Test_State::$fail_update_post = true;

		$result = $this->run_edit();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'db_update_error', $result->get_error_code() );
		$this->assertTrue( \WP_Test_State::$kses_active );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ), 'la copia nueva no existe' ); // @phpstan-ignore-line
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
		$this->assertArrayNotHasKey( '_et_pb_use_divi_5', \WP_Test_State::$meta[ self::ORIGINAL ] ?? array() );
	}

	public function test_a_failed_backup_aborts_without_writing_and_discards_the_fresh_copy(): void {
		\WP_Test_State::$json_fail_in = 'capture';

		$result = $this->run_edit();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_backup_failed', $result->get_error_code() );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
		$this->assertSame( array(), \WP_Test_State::$kses_during_save, 'no se llegó a escribir' );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
	}

	public function test_a_lock_lost_before_the_first_write_aborts_without_writing_or_creating_a_copy(): void {
		$this->lose_lock_after_acquiring( self::ORIGINAL );
		$before = $this->snapshot();

		$result = $this->run_edit();

		$this->assertRejected( 'nexo_mcp_busy', $result, $before );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
	}

	public function test_the_edit_releases_the_lock(): void {
		$this->ok( $this->run_edit() );

		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}
}
