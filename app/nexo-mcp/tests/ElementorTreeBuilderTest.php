<?php
/**
 * Armado del árbol que se guarda: ids generados por el plugin, forma de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorTreeBuilder;
use PHPUnit\Framework\TestCase;

final class ElementorTreeBuilderTest extends TestCase {

	/**
	 * Generador determinista: 0000001, 0000002…
	 */
	private function builder(): ElementorTreeBuilder {
		$n = 0;

		return new ElementorTreeBuilder(
			static function () use ( &$n ): string {
				return sprintf( '%07x', ++$n );
			}
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function input(): array {
		return array(
			array(
				'id'       => 'del-cliente',
				'type'     => 'e-flexbox',
				'settings' => array( 'classes' => array( '$$type' => 'classes', 'value' => array( 'g-hero', 'mia' ) ) ),
				'styles'   => array(
					'mia' => array(
						'id'       => 'mia',
						'type'     => 'class',
						'label'    => 'mia',
						'variants' => array(),
					),
				),
				'elements' => array(
					array(
						'id'       => 'otro-del-cliente',
						'type'     => 'e-heading',
						'settings' => array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'Hola' ) ),
					),
					array( 'type' => 'e-divider' ),
				),
			),
		);
	}

	public function test_los_ids_los_genera_el_plugin_y_los_del_cliente_se_ignoran(): void {
		$tree = $this->builder()->build( $this->input() );

		$this->assertSame( '0000001', $tree[0]['id'] );
		$this->assertSame( '0000003', $tree[0]['elements'][0]['id'] );
		$this->assertSame( '0000004', $tree[0]['elements'][1]['id'] );
		$this->assertStringNotContainsString( 'cliente', (string) wp_json_encode( $tree ) );
	}

	public function test_forma_de_contenedores_y_widgets(): void {
		$tree = $this->builder()->build( $this->input() );

		$this->assertSame( 'e-flexbox', $tree[0]['elType'] );
		$this->assertArrayNotHasKey( 'widgetType', $tree[0] );
		$this->assertSame( array(), $tree[0]['elements'][1]['settings'] );
		$this->assertSame( array(), $tree[0]['elements'][1]['styles'] );

		$heading = $tree[0]['elements'][0];
		$this->assertSame( 'widget', $heading['elType'] );
		$this->assertSame( 'e-heading', $heading['widgetType'] );
		$this->assertSame( array(), $heading['elements'] );
		$this->assertSame( 'Hola', $heading['settings']['title']['value'] );
		$this->assertArrayNotHasKey( 'type', $heading );
	}

	public function test_los_estilos_locales_se_renombran_y_las_clases_del_nodo_los_siguen(): void {
		$tree = $this->builder()->build( $this->input() );
		$box  = $tree[0];

		$this->assertSame( array( 'e-0000001-0000002' ), array_keys( $box['styles'] ) );
		$this->assertSame( 'e-0000001-0000002', $box['styles']['e-0000001-0000002']['id'] );
		$this->assertSame( 'mia', $box['styles']['e-0000001-0000002']['label'] );
		// La global queda tal cual; la local apunta al id nuevo.
		$this->assertSame( array( 'g-hero', 'e-0000001-0000002' ), $box['settings']['classes']['value'] );
	}

	public function test_cuenta_los_nodos(): void {
		$this->assertSame( 3, ElementorTreeBuilder::count_nodes( $this->builder()->build( $this->input() ) ) );
	}

	public function test_el_estilo_guardado_tiene_solo_las_claves_permitidas(): void {
		$input = $this->input();
		$input[0]['styles']['mia']['cssName']  = 'x{}body{color:red}';
		$input[0]['styles']['mia']['variants'] = array(
			array(
				'meta'       => array(
					'breakpoint' => 'desktop',
					'state'      => null,
					'media'      => 'all',
				),
				'props'      => array( 'gap' => array( '$$type' => 'size', 'value' => array( 'size' => 1, 'unit' => 'px' ) ) ),
				'custom_css' => array( 'raw' => 'eA==' ),
				'selector'   => 'body',
			),
		);

		$tree  = $this->builder()->build( $input );
		$style = $tree[0]['styles']['e-0000001-0000002'];

		$this->assertSame( array( 'id', 'type', 'label', 'variants' ), array_keys( $style ) );
		$this->assertSame( array( 'meta', 'props', 'custom_css' ), array_keys( $style['variants'][0] ) );
		$this->assertSame( array( 'breakpoint', 'state' ), array_keys( $style['variants'][0]['meta'] ) );
		$this->assertNull( $style['variants'][0]['custom_css'] );
		$this->assertStringNotContainsString( 'body', (string) wp_json_encode( $tree ) );
	}

	public function test_los_ids_reservados_no_se_entregan(): void {
		// El generador devuelve primero ids ya usados por el documento.
		$queue   = array( 'aaaaaaa', 'bbbbbbb', 'ccccccc', 'ddddddd', 'eeeeeee' );
		$builder = new ElementorTreeBuilder(
			static function () use ( &$queue ): string {
				return (string) array_shift( $queue );
			}
		);
		$builder->reserve( array( 'aaaaaaa', 'bbbbbbb' ) );

		$tree = $builder->build( array( array( 'type' => 'e-flexbox', 'elements' => array( array( 'type' => 'e-divider' ) ) ) ) );

		$this->assertSame( 'ccccccc', $tree[0]['id'] );
		$this->assertSame( 'ddddddd', $tree[0]['elements'][0]['id'] );
	}

	public function test_la_reserva_sobrevive_a_varias_construcciones(): void {
		$builder = $this->builder();
		$builder->reserve( array( '0000001', '0000003' ) );

		$first = $builder->build( array( array( 'type' => 'e-divider' ) ) );
		$this->assertSame( '0000002', $first[0]['id'] );
	}
}
