<?php
/**
 * `Divi5Adapter::flush()`: qué CSS y qué cachés vacía según el contenido (página, copia de trabajo de un layout, layout vivo)
 * y `flush_unassigned()` para un layout recién creado y sin asignar.
 *
 * Corre en un proceso aparte porque los dobles de Divi declaran clases y funciones globales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Divi5AdapterFlushTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/DiviLayoutWpStubs.php';
		require_once __DIR__ . '/DiviLayoutCssStubs.php';

		\WP_Layout_Stub_State::$css_calls   = array();
		\WP_Layout_Stub_State::$cache_calls = array();

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[200] = new \WP_Post( array( 'ID' => 200, 'post_type' => 'et_body_layout', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[210] = new \WP_Post( array( 'ID' => 210, 'post_type' => 'et_body_layout', 'post_status' => 'draft' ) );
		update_post_meta( 210, WorkingCopy::META_DRAFT_OF, 200 );
		update_post_meta( 200, WorkingCopy::META_DRAFT, 210 );
		\WP_Test_State::$posts[110] = new \WP_Post( array( 'ID' => 110, 'post_type' => 'page', 'post_status' => 'draft' ) );
		update_post_meta( 110, WorkingCopy::META_DRAFT_OF, 100 );
		update_post_meta( 100, WorkingCopy::META_DRAFT, 110 );
	}

	public function test_a_page_clears_only_its_own_css(): void {
		( new Divi5Adapter() )->flush( \WP_Test_State::$posts[100] );

		$this->assertSame( array( array( 100, 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_page_working_copy_clears_only_its_own_css(): void {
		( new Divi5Adapter() )->flush( \WP_Test_State::$posts[110] );

		$this->assertSame( array( array( 110, 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_layout_working_copy_clears_only_its_own_css(): void {
		( new Divi5Adapter() )->flush( \WP_Test_State::$posts[210] );

		$this->assertSame( array( array( 210, 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_live_layout_clears_the_whole_static_css_and_the_page_caches(): void {
		( new Divi5Adapter() )->flush( \WP_Test_State::$posts[200] );

		$this->assertSame( array( array( 200, 'all' ), array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_new_unassigned_layout_clears_only_its_own_css(): void {
		( new Divi5Adapter() )->flush_unassigned( \WP_Test_State::$posts[200] );

		$this->assertSame( array( array( 200, 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
	}
}
