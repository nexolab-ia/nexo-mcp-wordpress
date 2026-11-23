<?php
/**
 * edit-divi-module: copia de trabajo, huella, parche de escritorio, kses y rechazos sin escribir.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5ModuleEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	/** Texto del módulo `0.0.0.0.0`. */
	private const TEXT = '0.0.0.0.0';

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
	 * Página con tablet, teléfono y hover en el texto, y otros módulos que no se tocan.
	 */
	public static function page(): string {
		$raw = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/text {"builderVersion":"5.19.1","content":{"innerContent":{"desktop":{"value":"<p>Hola</p>"},"tablet":{"value":"<p>Hola tablet</p>"},"phone":{"value":"<p>Hola móvil</p>"},"hover":{"value":"<p>Hola hover</p>"}}},"module":{"decoration":{"spacing":{"desktop":{"value":{"margin":{"top":"5px","left":"7px"}}},"tablet":{"value":{"margin":{"top":"1px"}}}}}}} /-->' . "\n"
			. '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Comprar","linkUrl":"https://ex.test/tienda"}}}}} /-->' . "\n"
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

	private function editor( ?callable $lock = null, ?callable $active = null ): Divi5ModuleEditor {
		return new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name, static fn( int $id ) => 7 === $id ? 'https://ex.test/7.jpg' : false ),
			$lock ?? static fn() => true,
			$active ?? static fn(): bool => true
		);
	}

	/**
	 * @param array<string, mixed> $input Parámetros que cambian.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_edit( array $input = array(), int $id = self::ORIGINAL, ?callable $lock = null, ?callable $active = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->editor( $lock, $active )->edit(
			$post,
			array_filter(
				array_merge(
					array(
						'path'        => self::TEXT,
						'fingerprint' => $this->node( $post->post_content, self::TEXT )['fingerprint'],
						'content'     => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ),
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
	 * @param array<string, mixed>|\WP_Error $result   Resultado.
	 */
	private function assertRejected( string $code, $result, string $before ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'no escribe nada' );
	}

	public function test_a_published_page_is_edited_in_its_working_copy_and_never_published(): void {
		$result = $this->run_edit();

		$this->assertIsArray( $result );
		$copy = (int) $result['id'];
		$this->assertNotSame( self::ORIGINAL, $copy );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( 'draft', get_post( $copy )->post_status ); // @phpstan-ignore-line
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ), 'el original no cambia' );
		$this->assertSame( 'Nuevo', $this->node( $this->content( $copy ), self::TEXT )['text'] );
		$this->assertSame( self::TEXT, $result['path'] );
		$this->assertSame( $this->node( $this->content( $copy ), self::TEXT )['fingerprint'], $result['fingerprint'] );
		$this->assertSame( 'on', get_post_meta( $copy, '_et_pb_use_builder', true ) );
		$this->assertSame( 'on', get_post_meta( $copy, '_et_pb_use_divi_5', true ) );
		$this->assertTrue( ( new BuilderBackup() )->exists( $copy ), 'respaldo de la copia' );
		$this->assertContains( $copy, \WP_Test_State::$cleaned, 'se invalida la caché de Divi' );
	}

	public function test_a_draft_is_edited_in_place(): void {
		$this->put_page( 101, 'draft' );

		$result = $this->run_edit( array(), 101 );

		$this->assertIsArray( $result );
		$this->assertSame( 101, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( 'Nuevo', $this->node( $this->content( 101 ), self::TEXT )['text'] );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
	}

	public function test_tablet_phone_hover_and_untouched_modules_stay_byte_identical(): void {
		$before = self::page();
		$result = $this->run_edit();
		$this->assertIsArray( $result );
		$after = $this->content( (int) $result['id'] );

		$old = parse_blocks( $before );
		$new = parse_blocks( $after );
		$a   = Divi5Tree::locate_block( $old, self::TEXT );
		$b   = Divi5Tree::locate_block( $new, self::TEXT );
		$this->assertIsArray( $a );
		$this->assertIsArray( $b );

		$this->assertSame( $a['attrs']['content']['innerContent']['tablet'], $b['attrs']['content']['innerContent']['tablet'] );
		$this->assertSame( $a['attrs']['content']['innerContent']['phone'], $b['attrs']['content']['innerContent']['phone'] );
		$this->assertSame( $a['attrs']['content']['innerContent']['hover'], $b['attrs']['content']['innerContent']['hover'] );
		$this->assertSame( $a['attrs']['module'], $b['attrs']['module'] );
		$this->assertSame( '<p>Nuevo</p>', $b['attrs']['content']['innerContent']['desktop']['value'] );
		$this->assertSame( '5.19.1', $b['attrs']['builderVersion'] );

		foreach ( array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.1' ) as $path ) {
			$was = Divi5Tree::locate_block( $old, $path );
			$now = Divi5Tree::locate_block( $new, $path );
			if ( '0.0.0.0.1' === $path ) {
				$this->assertSame( serialize_block( $was ), serialize_block( $now ), 'el botón queda igual' );
			} else {
				$this->assertSame( $was['attrs'], $now['attrs'], $path );
			}
		}
	}

	public function test_design_patch_merges_only_the_given_desktop_paths(): void {
		$result = $this->run_edit(
			array(
				'content' => null,
				'design'  => array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '20px' ) ) ) ) ) ) ),
			)
		);

		$this->assertIsArray( $result );
		$block   = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), self::TEXT );
		$spacing = $block['attrs']['module']['decoration']['spacing']; // @phpstan-ignore-line
		$this->assertSame( array( 'top' => '20px', 'left' => '7px' ), $spacing['desktop']['value']['margin'] );
		$this->assertSame( array( 'margin' => array( 'top' => '1px' ) ), $spacing['tablet']['value'] );
		$this->assertSame( '<p>Hola</p>', $block['attrs']['content']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
	}

	public function test_a_second_edit_with_the_returned_fingerprint_works_and_the_old_one_is_stale(): void {
		$first = $this->run_edit();
		$this->assertIsArray( $first );
		$old = $this->node( self::page(), self::TEXT )['fingerprint'];

		$second = $this->run_edit(
			array( 'fingerprint' => $first['fingerprint'], 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Otra vez</p>' ) ) ) ) ),
			(int) $first['id']
		);
		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( 'Otra vez', $this->node( $this->content( (int) $first['id'] ), self::TEXT )['text'] );

		$before = $this->snapshot();
		$stale  = $this->run_edit( array( 'fingerprint' => $old ), (int) $first['id'] );
		$this->assertRejected( 'nexo_mcp_divi_node_changed', $stale, $before );
	}

	public function test_a_stale_fingerprint_is_rejected_without_writing_or_creating_a_copy(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->run_edit( array( 'fingerprint' => str_repeat( 'a', 64 ) ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->run_edit( array( 'fingerprint' => '' ) ), $before );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
	}

	public function test_a_missing_path_is_rejected_without_writing(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->run_edit( array( 'path' => '0.9.9' ) ), $before );
		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->run_edit( array( 'path' => "0.0\n" ) ), $before );
		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->run_edit( array( 'path' => 'abc' ) ), $before );
	}

	public function test_a_structure_node_is_not_editable(): void {
		$before = $this->snapshot();
		$post   = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$result = $this->run_edit( array( 'path' => '0.0', 'fingerprint' => $this->node( $post->post_content, '0.0' )['fingerprint'] ) );

		$this->assertRejected( 'nexo_mcp_divi_module_denied', $result, $before );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function invalid_patches(): array {
		$content = static fn( $value ): array => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => $value ) ) ) );

		return array(
			'shortcode'  => array( 'nexo_mcp_divi_shortcode_denied', array( 'content' => $content( '<p>[gallery ids="1"]</p>' ) ) ),
			'tablet'     => array( 'nexo_mcp_divi_attr_denied', array( 'content' => array( 'content' => array( 'innerContent' => array( 'tablet' => array( 'value' => 'x' ) ) ) ) ) ),
			'bad color'  => array( 'nexo_mcp_divi_value_invalid', array( 'content' => null, 'design' => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => "#fff\n" ) ) ) ) ) ) ) ),
			'unknown'    => array( 'nexo_mcp_divi_attr_denied', array( 'content' => array( 'module' => array( 'advanced' => array( 'htmlBefore' => array( 'desktop' => array( 'value' => 'x' ) ) ) ) ) ) ),
			'empty'      => array( 'nexo_mcp_divi_tree_invalid', array( 'content' => null ) ),
		);
	}

	/**
	 * @dataProvider invalid_patches
	 * @param array<string, mixed> $patch Parche que cambia.
	 */
	public function test_an_invalid_patch_is_rejected_with_its_code_and_nothing_is_written( string $code, array $patch ): void {
		$before = $this->snapshot();

		$this->assertRejected( $code, $this->run_edit( $patch ), $before );
	}

	public function test_a_javascript_link_in_a_button_is_rejected_without_writing(): void {
		$before = $this->snapshot();
		$post   = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$result = $this->run_edit(
			array(
				'path'        => '0.0.0.0.1',
				'fingerprint' => $this->node( $post->post_content, '0.0.0.0.1' )['fingerprint'],
				'content'     => array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'linkUrl' => 'javascript:alert(1)' ) ) ) ) ),
			)
		);

		$this->assertRejected( 'nexo_mcp_divi_value_invalid', $result, $before );
	}

	public function test_a_page_that_is_not_divi5_is_rejected_without_writing(): void {
		$this->put_page( 102, 'publish', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$before = $this->snapshot();

		$result = $this->editor()->edit( get_post( 102 ), array( 'path' => '0', 'fingerprint' => 'x', 'content' => array() ) ); // @phpstan-ignore-line

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

		$result = $this->run_edit();

		$this->assertIsArray( $result );
		$this->assertSame( false, end( \WP_Test_State::$kses_during_save ), 'sin kses al guardar' );
		$this->assertTrue( \WP_Test_State::$kses_active, 'se restauran' );
		$this->assertSame( '<p>Nuevo</p>', Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), self::TEXT )['attrs']['content']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
	}

	public function test_kses_filters_that_were_not_active_stay_inactive(): void {
		\WP_Test_State::$kses_active = false;

		$this->assertIsArray( $this->run_edit() );
		$this->assertFalse( \WP_Test_State::$kses_active );
	}

	public function test_kses_filters_are_restored_when_the_save_fails_and_the_new_copy_is_discarded(): void {
		\WP_Test_State::$kses_active    = true;
		\WP_Test_State::$fail_update_post = true;

		$result = $this->run_edit();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'db_update_error', $result->get_error_code() );
		$this->assertTrue( \WP_Test_State::$kses_active, 'se restauran también si falla' );
		$this->assertSame( array( false ), \WP_Test_State::$kses_during_save );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
		$this->assertArrayNotHasKey( '_et_pb_use_divi_5', \WP_Test_State::$meta[ self::ORIGINAL ] ?? array() );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ), 'la copia recién creada se descartó' ); // @phpstan-ignore-line
		$this->assertSame( array( self::ORIGINAL ), array_keys( array_filter( \WP_Test_State::$posts, static fn( $p ): bool => 'trash' !== $p->post_status ) ), 'no queda otra página viva' );
	}

	public function test_a_lock_lost_before_the_first_write_aborts_without_writing_or_creating_a_copy(): void {
		$this->lose_lock_after_acquiring( self::ORIGINAL );
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_busy', $this->run_edit(), $before );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
		$this->assertSame( array(), \WP_Test_State::$kses_during_save );
	}

	public function test_a_failed_backup_aborts_without_writing_and_discards_the_fresh_copy(): void {
		\WP_Test_State::$json_fail_in = 'capture';

		$result = $this->run_edit();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_backup_failed', $result->get_error_code() );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( self::ORIGINAL ) ) ); // @phpstan-ignore-line
		$this->assertSame( array(), \WP_Test_State::$kses_during_save, 'no se escribió' );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );
		$this->assertArrayNotHasKey( '_et_pb_use_divi_5', \WP_Test_State::$meta[ self::ORIGINAL ] ?? array() );
	}

	public function test_restore_builder_backup_round_trips_a_divi5_edit(): void {
		$result  = $this->run_edit();
		$this->assertIsArray( $result );
		$copy    = (int) $result['id'];
		$gateway = new BuilderGateway( null, null, $this->cfg );
		$this->assertNotSame( self::page(), $this->content( $copy ) );

		$restored = $gateway->restore_backup( array( 'id' => $copy ) );

		$this->assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->get_error_message() : '' );
		$this->assertTrue( $restored['restored'] );
		$this->assertSame( self::page(), $this->content( $copy ), 'vuelve al contenido previo a la edición' );
		$this->assertArrayNotHasKey( '_et_pb_use_divi_5', \WP_Test_State::$meta[ $copy ] ?? array(), 'la meta previa (ausente) se restaura' );
	}

	public function test_kses_filters_are_restored_when_the_save_throws(): void {
		\WP_Test_State::$kses_active = true;

		$editor = new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			new DiviTreeValidator(),
			static fn() => true,
			static function (): bool {
				return true;
			},
			static function (): void {
				throw new \RuntimeException( 'boom' );
			}
		);
		$post = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );

		try {
			$editor->edit(
				$post,
				array(
					'path'        => self::TEXT,
					'fingerprint' => $this->node( $post->post_content, self::TEXT )['fingerprint'],
					'content'     => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'x' ) ) ) ),
				)
			);
			$this->fail( 'debía lanzar' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertTrue( \WP_Test_State::$kses_active );
	}
}
