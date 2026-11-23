<?php
/**
 * create-divi-page: borrador con módulos reales, kses sólo durante el insert y rollback.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5PageCreator;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;

final class Divi5PageCreatorTest extends NexoTestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function sections( string $text = 'Hola' ): array {
		return Divi5SectionEditorTestFixtures::sections( $text );
	}

	private function creator( ?callable $active = null, ?callable $inserter = null ): Divi5PageCreator {
		return new Divi5PageCreator(
			new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name ),
			$active ?? static fn(): bool => true,
			static fn(): string => '5.19.1',
			$inserter
		);
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param array<string, mixed> $input Parámetros.
	 */
	private function assertRejected( string $code, array $input, ?callable $active = null ): void {
		$before = $this->snapshot();
		$result = $this->creator( $active )->create( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'no escribe nada' );
	}

	public function test_it_creates_a_draft_page_with_real_modules_meta_and_a_css_flush(): void {
		$result = $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) );

		$this->assertIsArray( $result );
		$id   = (int) $result['id'];
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'Nueva', $post->post_title );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'on', get_post_meta( $id, '_et_pb_use_builder', true ) );
		$this->assertSame( 'on', get_post_meta( $id, '_et_pb_use_divi_5', true ) );
		$this->assertContains( $id, \WP_Test_State::$cleaned );

		$tree = Divi5Tree::build( $post->post_content );
		$this->assertSame( 'divi/placeholder', $tree[0]['type'] );
		$this->assertSame( 'divi/section', $tree[0]['children'][0]['type'] );
		$this->assertSame( 'Hola', Divi5Tree::locate( $tree, '0.0.0.0.0' )['text'] ); // @phpstan-ignore-line
		$this->assertStringContainsString( '<!-- wp:divi/text {"builderVersion":"5.19.1"', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:divi/section {"builderVersion":"5.19.1"', $post->post_content );
	}

	public function test_it_returns_every_path_with_type_and_the_fingerprint_that_a_reread_gives(): void {
		$result = $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.0' ), array_column( $result['nodes'], 'path' ) );
		$tree = Divi5Tree::build( get_post( (int) $result['id'] )->post_content ); // @phpstan-ignore-line

		foreach ( $result['nodes'] as $row ) {
			$this->assertSame( Divi5Tree::locate( $tree, $row['path'] )['fingerprint'], $row['fingerprint'] ); // @phpstan-ignore-line
		}
		$this->assertArrayHasKey( 'preview_url', $result );
	}

	public function test_kses_is_removed_only_during_the_insert_and_restored(): void {
		\WP_Test_State::$kses_active = true;

		$this->assertIsArray( $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) ) );

		$this->assertSame( array( false ), \WP_Test_State::$kses_during_insert );
		$this->assertTrue( \WP_Test_State::$kses_active );
	}

	public function test_kses_that_was_not_active_stays_inactive(): void {
		\WP_Test_State::$kses_active = false;

		$this->assertIsArray( $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) ) );
		$this->assertFalse( \WP_Test_State::$kses_active );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function rejections(): array {
		$text = static fn( string $value ): array => array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => $value ) ) ) ) );
		$wrap = static fn( array $module ): array => array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $module ) ) ) ) ) ) );

		return array(
			'no title'            => array( 'nexo_mcp_divi_title_required', array( 'title' => '  ', 'sections' => Divi5SectionEditorTestFixtures::sections() ) ),
			'no sections'         => array( 'nexo_mcp_divi_tree_invalid', array( 'title' => 'x' ) ),
			'empty sections'      => array( 'nexo_mcp_divi_tree_invalid', array( 'title' => 'x', 'sections' => array() ) ),
			'placeholder at root' => array( 'nexo_mcp_divi_nesting_invalid', array( 'title' => 'x', 'sections' => array( array( 'type' => 'divi/placeholder' ) ) ) ),
			'module at root'      => array( 'nexo_mcp_divi_nesting_invalid', array( 'title' => 'x', 'sections' => array( $text( 'x' ) ) ) ),
			'code module'         => array( 'nexo_mcp_divi_module_denied', array( 'title' => 'x', 'sections' => $wrap( array( 'type' => 'divi/code' ) ) ) ),
			'shortcode'           => array( 'nexo_mcp_divi_shortcode_denied', array( 'title' => 'x', 'sections' => $wrap( $text( '<p>[gallery ids="1"]</p>' ) ) ) ),
			'javascript url'      => array( 'nexo_mcp_divi_value_invalid', array( 'title' => 'x', 'sections' => $wrap( array( 'type' => 'divi/button', 'attrs' => array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'linkUrl' => 'javascript:alert(1)' ) ) ) ) ) ) ) ) ),
			'tablet'              => array( 'nexo_mcp_divi_attr_denied', array( 'title' => 'x', 'sections' => $wrap( array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'tablet' => array( 'value' => 'x' ) ) ) ) ) ) ) ),
		);
	}

	/**
	 * @dataProvider rejections
	 * @param array<string, mixed> $input Parámetros.
	 */
	public function test_a_rejection_carries_its_code_and_creates_nothing( string $code, array $input ): void {
		$this->assertRejected( $code, $input );
		$this->assertSame( array(), \WP_Test_State::$kses_during_insert, 'ni se intentó insertar' );
	}

	public function test_without_divi5_active_it_asks_for_divi5_and_creates_nothing(): void {
		$this->assertRejected( 'nexo_mcp_divi5_required', array( 'title' => 'x', 'sections' => self::sections() ), static fn(): bool => false );
	}

	public function test_a_failed_insert_returns_the_error_and_leaves_no_post(): void {
		\WP_Test_State::$kses_active      = true;
		\WP_Test_State::$fail_insert_post = true;
		$before                           = $this->snapshot();

		$result = $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'db_insert_error', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertTrue( \WP_Test_State::$kses_active );
	}

	public function test_content_that_a_filter_rewrote_is_rolled_back_and_the_half_created_post_is_deleted(): void {
		\WP_Test_State::$kses_active   = true;
		\WP_Test_State::$mangle_insert = true;
		$before                        = $this->snapshot();

		$result = $this->creator()->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'el borrador a medias se borró' );
		$this->assertTrue( \WP_Test_State::$kses_active );
	}

	public function test_an_exception_while_inserting_restores_kses_and_leaves_no_post(): void {
		\WP_Test_State::$kses_active = true;
		$before                      = $this->snapshot();
		$creator                     = $this->creator(
			null,
			static function (): void {
				throw new \RuntimeException( 'boom' );
			}
		);

		$result = $creator->create( array( 'title' => 'Nueva', 'sections' => self::sections() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_save_failed', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertTrue( \WP_Test_State::$kses_active );
	}
}
