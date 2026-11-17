<?php
/**
 * get-design-system: una lectura que falla se avisa en vez de entregar listas vacías.
 *
 * Corre en un proceso aparte porque define las clases `\Elementor\...` (doble de
 * Elementor) y el resto de las pruebas necesita que no existan.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use Elementor\Modules\Variables\Storage\Fake_Variable;
use Elementor\Modules\Variables\Storage\Variables_Repository;
use NexoDev\NexoMCP\Builders\ElementorDesignSystem;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDesignSystemWarningsTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/ElementorDesignFakes.php';
	}

	/**
	 * Carga los repositorios falsos con tres variables sanas.
	 */
	private function with_repositories(): void {
		require_once __DIR__ . '/ElementorDesignRepositoryFakes.php';
		Global_Classes_Repository::$fail = null;
		Variables_Repository::$fail      = null;
		Variables_Repository::$variables = array(
			new Fake_Variable( 'a' ),
			new Fake_Variable( 'b' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read(): array {
		$result = ( new ElementorDesignSystem() )->read();
		$this->assertIsArray( $result );

		return $result;
	}

	public function test_sin_las_clases_de_elementor_las_listas_salen_vacias_sin_avisos(): void {
		$out = $this->read();

		$this->assertSame( array(), $out['classes'] );
		$this->assertSame( array(), $out['variables'] );
		$this->assertArrayNotHasKey( 'warnings', $out );
	}

	public function test_sin_fallos_no_hay_avisos_y_se_lee_todo(): void {
		$this->with_repositories();
		$out = $this->read();

		$this->assertArrayNotHasKey( 'warnings', $out );
		$this->assertSame( array( array( 'id' => 'g-hero', 'label' => 'Hero' ) ), $out['classes'] );
		$this->assertSame( array( 'a', 'b' ), array_column( $out['variables'], 'id' ) );
	}

	public function test_si_falla_la_lectura_de_clases_se_avisa_y_lo_demas_se_conserva(): void {
		$this->with_repositories();
		Global_Classes_Repository::$fail = new \RuntimeException( 'repositorio roto' );

		$out = $this->read();

		$this->assertSame( array(), $out['classes'] );
		$this->assertSame( array( 'a', 'b' ), array_column( $out['variables'], 'id' ) );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertSame( 'classes', $out['warnings'][0]['source'] );
		$this->assertStringContainsString( 'repositorio roto', $out['warnings'][0]['message'] );
	}

	public function test_si_falla_la_carga_de_variables_se_avisa_y_las_clases_se_conservan(): void {
		$this->with_repositories();
		Variables_Repository::$fail = new \RuntimeException( 'carga rota' );

		$out = $this->read();

		$this->assertSame( array(), $out['variables'] );
		$this->assertSame( 'g-hero', $out['classes'][0]['id'] );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertSame( 'variables', $out['warnings'][0]['source'] );
		$this->assertStringContainsString( 'carga rota', $out['warnings'][0]['message'] );
	}

	public function test_si_una_variable_falla_se_conservan_las_ya_leidas_y_se_avisa(): void {
		$this->with_repositories();
		Variables_Repository::$variables = array(
			new Fake_Variable( 'a' ),
			new Fake_Variable( 'b', true ),
			new Fake_Variable( 'c' ),
		);

		$out = $this->read();

		$this->assertSame( array( 'a', 'c' ), array_column( $out['variables'], 'id' ) );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertSame( 'variables', $out['warnings'][0]['source'] );
		$this->assertStringContainsString( 'valor ilegible', $out['warnings'][0]['message'] );
	}
}
