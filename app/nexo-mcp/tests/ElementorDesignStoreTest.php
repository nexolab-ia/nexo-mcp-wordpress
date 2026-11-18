<?php
/**
 * `ElementorDesignStore`: restauración de clases (ramas y comprobación posterior) y forma real de los estados.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use Elementor\Modules\GlobalClasses\Global_Classes_Repository as Repo;
use Elementor\Plugin;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;
use NexoDev\NexoMCP\Builders\ElementorDesignStore;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorDesignStoreTest extends NexoTestCase {

	/** @var array<string, array<string, mixed>> */
	private array $items;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/ElementorDesignStoreFakes.php';

		$this->items = array(
			'g-a' => array( 'id' => 'g-a', 'label' => 'a', 'variants' => array( array( 'meta' => array( 'breakpoint' => 'desktop', 'state' => null ), 'props' => array( 'gap' => array( 'size' => 1 ) ) ) ) ),
			'g-b' => array( 'id' => 'g-b', 'label' => 'b', 'variants' => array() ),
		);

		Repo::$items                  = $this->items;
		Repo::$order                  = array( 'g-a', 'g-b' );
		Repo::$applied                = array();
		Repo::$mode                   = 'ok';
		Plugin::instance()->kit->json = array();
	}

	private function store(): ElementorDesignStore {
		return new ElementorDesignStore();
	}

	public function test_el_snapshot_de_clases_trae_items_y_orden(): void {
		$this->assertSame( array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ), $this->store()->snapshot( 'classes' ) );
	}

	public function test_restaurar_sin_diferencias_no_toca_nada(): void {
		// Mismo contenido con las claves en otro orden: no cuenta como cambio.
		$same = array(
			'g-b' => array( 'variants' => array(), 'label' => 'b', 'id' => 'g-b' ),
			'g-a' => $this->items['g-a'],
		);

		$this->store()->restore( 'classes', array( 'items' => $same, 'order' => array( 'g-a', 'g-b' ) ) );

		$this->assertSame( array(), Repo::$applied );
	}

	public function test_restaurar_una_clase_modificada_escribe_solo_esa_y_no_el_orden(): void {
		$state                       = $this->items;
		$state['g-a']['label']       = 'vieja';
		Repo::$items['g-a']['label'] = 'nueva';

		$this->store()->restore( 'classes', array( 'items' => $state, 'order' => array( 'g-a', 'g-b' ) ) );

		$this->assertCount( 1, Repo::$applied );
		$this->assertSame( array( 'g-a' => $state['g-a'] ), Repo::$applied[0][0] );
		$this->assertSame( array( 'added' => array(), 'deleted' => array(), 'modified' => array( 'g-a' ), 'order' => false ), Repo::$applied[0][1] );
		$this->assertSame( array( 'g-a', 'g-b' ), Repo::$applied[0][2] );
		$this->assertSame( 'vieja', Repo::$items['g-a']['label'] );
	}

	public function test_restaurar_solo_el_orden_no_toca_ningun_item(): void {
		Repo::$order = array( 'g-b', 'g-a' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ) );

		$this->assertCount( 1, Repo::$applied );
		$this->assertSame( array(), Repo::$applied[0][0] );
		$this->assertSame( array( 'added' => array(), 'deleted' => array(), 'modified' => array(), 'order' => true ), Repo::$applied[0][1] );
		$this->assertSame( array( 'g-a', 'g-b' ), Repo::$order );
	}

	public function test_el_cambio_de_tipo_en_un_valor_es_un_cambio(): void {
		// Con `==` el 1 entero y el '1' texto serían iguales y la clase no se restauraría.
		$state                                               = $this->items;
		$state['g-a']['variants'][0]['props']['gap']['size'] = '1';

		$this->store()->restore( 'classes', array( 'items' => $state, 'order' => array( 'g-a', 'g-b' ) ) );

		$this->assertSame( array( 'g-a' ), Repo::$applied[0][1]['modified'] );
		$this->assertSame( '1', Repo::$items['g-a']['variants'][0]['props']['gap']['size'] );
	}

	public function test_restaurar_agrega_las_borradas_y_quita_las_nuevas(): void {
		Repo::$items = array( 'g-a' => $this->items['g-a'], 'g-z' => array( 'id' => 'g-z', 'label' => 'z', 'variants' => array() ) );
		Repo::$order = array( 'g-a', 'g-z' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ) );

		$this->assertSame( array( 'added' => array( 'g-b' ), 'deleted' => array( 'g-z' ), 'modified' => array(), 'order' => true ), Repo::$applied[0][1] );
		$this->assertSame( $this->items, Repo::$items );
		$this->assertSame( array( 'g-a', 'g-b' ), Repo::$order );
	}

	public function test_si_elementor_no_guarda_las_clases_restaurar_lanza(): void {
		Repo::$items['g-a']['label'] = 'nueva';
		Repo::$mode                  = 'ignore';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'orden respaldado' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-b', 'g-a' ) ) );
	}

	public function test_si_elementor_deja_el_orden_distinto_restaurar_lanza(): void {
		Repo::$items['g-a']['label'] = 'nueva';
		Repo::$mode                  = 'wrong_order';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'orden respaldado' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ) );
	}

	public function test_si_elementor_deja_otro_contenido_restaurar_lanza(): void {
		Repo::$items['g-a']['label'] = 'nueva';
		Repo::$mode                  = 'altered';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'g-a' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ) );
	}

	public function test_si_faltan_clases_tras_restaurar_lanza(): void {
		Repo::$items = array( 'g-a' => $this->items['g-a'] );
		Repo::$order = array( 'g-a' );
		Repo::$mode  = 'ignore';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'todas las clases' );

		$this->store()->restore( 'classes', array( 'items' => $this->items, 'order' => array( 'g-a', 'g-b' ) ) );
	}

	/**
	 * Lo que Elementor guarda de verdad (ids en el orden sin item, registro de variables sin marca de agua)
	 * pasa la comprobación del escritor: el respaldo se guarda, se escribe y deshacer funciona.
	 */
	public function test_un_snapshot_con_la_forma_real_de_elementor_pasa_la_validacion_del_escritor(): void {
		Repo::$order                  = array( 'g-fantasma', 'g-a', 'g-b' );
		Plugin::instance()->kit->json = array( '_elementor_global_variables' => array( 'data' => array( 'e-gv-1' => array( 'type' => 'global-color-variable', 'label' => 'rojo', 'value' => '#f00' ) ) ) );
		$writer                       = new DesignSystemWriter( $this->store(), static function (): void {} );

		$classes = $writer->write( 'classes', 'create-class', fn() => array(), fn() => array( 'id' => 'g-c' ) );
		$this->assertIsArray( $classes );
		$this->assertSame( array( 'g-a', 'g-b' ), \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ]['state']['order'] );
		$this->assertSame( 9, \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ]['kit_id'] );

		$variables = $writer->write( 'variables', 'update-variable', fn() => array(), fn() => array() );
		$this->assertIsArray( $variables );
		$this->assertSame( 0, \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ]['state']['watermark'] );

		$this->assertIsArray( $writer->undo() );
		$this->assertSame( 1, Plugin::instance()->kit->json['_elementor_global_variables']['watermark'] );
	}

	public function test_un_snapshot_real_con_un_item_que_no_es_arreglo_no_se_escribe(): void {
		Repo::$items['g-a'] = 'roto';
		$writer             = new DesignSystemWriter( $this->store(), static function (): void {} );

		$result = $writer->write( 'classes', 'create-class', fn() => array(), fn() => $this->fail( 'no debe escribir' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_backup_failed', $result->get_error_code() );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
	}
}
