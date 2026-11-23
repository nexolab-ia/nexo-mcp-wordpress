<?php
/**
 * BuilderGateway::edit_divi_section: el camino exitoso lleva cada parámetro al editor.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Divi5GatewayEditSectionTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => 100,
				'operation'   => 'insert',
				'path'        => '0.0',
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), '0.0' )['fingerprint'] ?? '' ),
				'position'    => 'after',
				'sections'    => Divi5SectionEditorTestFixtures::sections( 'Insertada' ),
			),
			$extra
		);
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	public function test_with_draft_it_writes_the_copy_with_operation_path_position_and_sections_and_leaves_the_original(): void {
		$gateway  = new BuilderGateway( null, null, $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) ) );
		$original = $this->content( 100 );

		$result = $gateway->edit_divi_section( $this->input( array( 'draft' => true ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$copy = (int) $result['id'];
		$this->assertNotSame( 100, $copy );
		$this->assertSame( $original, $this->content( 100 ), 'el original no cambia' );
		$this->assertSame( $copy, WorkingCopy::copy_of( get_post( 100 ) ) ); // @phpstan-ignore-line

		$tree = Divi5Tree::build( $this->content( $copy ) );
		$this->assertCount( 3, $tree[0]['children'], 'insert + after: tres secciones' );
		$this->assertSame( 'Insertada', Divi5Tree::locate( $tree, '0.1.0.0.0' )['text'] ); // @phpstan-ignore-line
		$this->assertSame( 'A', Divi5Tree::locate( $tree, '0.0.0.0.0' )['text'] ); // @phpstan-ignore-line
		$this->assertSame( 'B', Divi5Tree::locate( $tree, '0.2.0.0.0' )['text'] ); // @phpstan-ignore-line
		$paths = array_column( $result['nodes'] ?? array(), 'path' );
		$this->assertContains( '0.1', $paths );
	}

	public function test_without_draft_and_without_working_copy_default_it_writes_in_place(): void {
		$gateway = new BuilderGateway( null, null, $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) ) );

		$result = $gateway->edit_divi_section( $this->input( array( 'operation' => 'replace', 'position' => '' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( 0, WorkingCopy::copy_of( get_post( 100 ) ) ); // @phpstan-ignore-line
		$this->assertSame( 'Insertada', Divi5Tree::locate( Divi5Tree::build( $this->content( 100 ) ), '0.0.0.0.0' )['text'] ); // @phpstan-ignore-line
	}

	public function test_a_stale_fingerprint_reaches_the_editor_and_writes_nothing(): void {
		$gateway = new BuilderGateway( null, null, $this->settings( array( 'read_only' => false ) ) );
		$before  = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $gateway->edit_divi_section( $this->input( array( 'fingerprint' => 'viejo' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_node_changed', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}
}
