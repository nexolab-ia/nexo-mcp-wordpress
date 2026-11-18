<?php
/**
 * `DesignKitElementorStore`: el aviso cuando Elementor guarda otra cosa que lo pedido.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use Elementor\Plugin;
use NexoDev\NexoMCP\Builders\DesignKitElementorStore;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DesignKitElementorStoreTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/ElementorDesignStoreFakes.php';
		Plugin::instance()->kit->strip_titles = true;
	}

	private function lists(): array {
		return array( 'custom_colors' => array( array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ) ) );
	}

	public function test_sin_unfiltered_html_el_error_lo_dice(): void {
		\WP_Test_State::$caps['unfiltered_html'] = false;

		try {
			( new DesignKitElementorStore() )->save( $this->lists() );
			$this->fail( 'debía lanzar' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'custom_colors', $e->getMessage() );
			$this->assertStringContainsString( 'unfiltered_html', $e->getMessage() );
		}
	}

	public function test_con_unfiltered_html_el_error_no_culpa_a_la_capacidad(): void {
		\WP_Test_State::$caps['unfiltered_html'] = true;

		try {
			( new DesignKitElementorStore() )->save( $this->lists() );
			$this->fail( 'debía lanzar' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'custom_colors', $e->getMessage() );
			$this->assertStringNotContainsString( 'unfiltered_html', $e->getMessage() );
		}
	}

	public function test_si_guarda_lo_pedido_no_lanza(): void {
		Plugin::instance()->kit->strip_titles = false;
		( new DesignKitElementorStore() )->save( $this->lists() );

		$this->assertSame( 'Marca', \WP_Test_State::$meta[9]['_elementor_page_settings']['custom_colors'][0]['title'] );
	}
}
