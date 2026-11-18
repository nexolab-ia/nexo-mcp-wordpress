<?php
/**
 * create-elementor-page: si el guardado falla, no queda borrador y el error es el esperado.
 *
 * Corre en un proceso aparte porque define `\Elementor\Plugin` (doble de Elementor).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use Elementor\Fake_Documents;
use Elementor\Plugin;
use NexoDev\NexoMCP\Builders\ElementorDesignSystem;
use NexoDev\NexoMCP\Builders\ElementorPageCreator;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorPageCreatorRollbackTest extends NexoTestCase {

	private Fake_Documents $documents;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/ElementorDesignFakes.php';
		$this->documents                 = new Fake_Documents();
		Plugin::instance()->documents    = $this->documents;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create() {
		$creator = new ElementorPageCreator( new ElementorDesignSystem(), new FakeSchemaGuard() );

		return $creator->create( array( 'title' => 'Landing', 'elements' => ElementorSectionFixtures::new_box() ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function failures(): array {
		return array(
			'save() devuelve false'           => array( 'false', 'Elementor no pudo guardar el árbol.' ),
			'save() lanza una excepción'      => array( 'throw', 'Elementor no pudo guardar el árbol: Elementor se cayó' ),
			'guardó menos nodos de los pedidos' => array( 'drop', 'Elementor guardó menos elementos de los pedidos.' ),
		);
	}

	/**
	 * @dataProvider failures
	 */
	public function test_si_el_guardado_falla_no_queda_borrador_y_el_error_es_el_esperado( string $mode, string $reason ): void {
		$this->documents->mode = $mode;

		$result = $this->create();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_save_failed', $result->get_error_code() );
		$this->assertSame( $reason . ' El borrador creado se eliminó.', $result->get_error_message() );
		$this->assertSame( array(), \WP_Test_State::$posts, 'no queda ningún borrador' );
		$this->assertSame( array(), \WP_Test_State::$meta, 'ni metadatos huérfanos' );
	}

	public function test_si_elementor_no_entrega_un_documento_tampoco_queda_borrador(): void {
		Plugin::instance()->documents = new class() {
			public function get( int $id ) {
				return false;
			}
		};

		$result = $this->create();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_save_failed', $result->get_error_code() );
		$this->assertSame( array(), \WP_Test_State::$posts );
	}

	public function test_con_el_guardado_correcto_el_borrador_se_conserva(): void {
		$result = $this->create();

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 2, $result['nodes'] );
		$this->assertCount( 1, \WP_Test_State::$posts );
		$this->assertArrayHasKey( $result['id'], \WP_Test_State::$posts );
	}
}
