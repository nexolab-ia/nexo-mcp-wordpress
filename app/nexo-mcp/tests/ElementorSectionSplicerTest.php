<?php
/**
 * Empalme de un subárbol en el documento decodificado (clase pura).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorSectionSplicer;
use NexoDev\NexoMCP\Builders\ElementorTree;
use PHPUnit\Framework\TestCase;

final class ElementorSectionSplicerTest extends TestCase {

	/**
	 * Subárbol ya armado (con ids del plugin).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function built( string $root = 'nw00001', string $child = 'nw00002' ): array {
		return array(
			array(
				'id'       => $root,
				'elType'   => 'e-flexbox',
				'settings' => array(),
				'styles'   => array(),
				'elements' => array(
					array(
						'id'         => $child,
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => array(),
						'styles'     => array(),
						'elements'   => array(),
					),
				),
			),
		);
	}

	/**
	 * @param array<int, mixed> $tree Documento.
	 * @return array<int, string>
	 */
	private function ids( array $tree ): array {
		return array_keys( ElementorTree::collect_ids( $tree ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function plan( array $tree, string $op, string $node, string $position = '' ): array {
		$plan = ElementorSectionSplicer::plan( $tree, $op, $node, $position );
		$this->assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );

		return $plan;
	}

	public function test_reemplazar_una_seccion_v3_deja_el_nuevo_en_su_posicion_y_el_resto_igual(): void {
		$tree = ElementorSectionFixtures::v3();
		$plan = $this->plan( $tree, 'replace', 'sec0001' );

		$this->assertSame( 'root', $plan['parent_kind'] );
		$this->assertSame( 0, $plan['base_depth'] );
		$this->assertSame( 5, $plan['nodes_without'] );

		$result = ElementorSectionSplicer::apply( $tree, $plan, $this->built() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'nw00001', 'sec0002', 'cnt0001' ), array_column( $result['tree'], 'id' ) );
		$this->assertSame( array( 'nw00001', 'nw00002' ), $result['node_ids'] );
		$this->assertSame( array( 'sec0001', 'col0001', 'hd00001', 'tx00001' ), $result['removed'] );
		// Lo no tocado, idéntico una vez decodificado.
		$this->assertSame( json_encode( $tree[1] ), json_encode( $result['tree'][1] ) );
		$this->assertSame( json_encode( $tree[2] ), json_encode( $result['tree'][2] ) );
	}

	public function test_insertar_antes_y_despues_respeta_la_posicion(): void {
		$tree = ElementorSectionFixtures::v3();

		$before = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'sec0002', 'before' ), $this->built() );
		$after  = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'sec0002', 'after' ), $this->built() );
		$last   = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'cnt0001', 'after' ), $this->built() );
		$first  = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'sec0001', 'before' ), $this->built() );

		$this->assertSame( array( 'sec0001', 'nw00001', 'sec0002', 'cnt0001' ), array_column( $before['tree'], 'id' ) );
		$this->assertSame( array( 'sec0001', 'sec0002', 'nw00001', 'cnt0001' ), array_column( $after['tree'], 'id' ) );
		$this->assertSame( array( 'sec0001', 'sec0002', 'cnt0001', 'nw00001' ), array_column( $last['tree'], 'id' ) );
		$this->assertSame( array( 'nw00001', 'sec0001', 'sec0002', 'cnt0001' ), array_column( $first['tree'], 'id' ) );
		$this->assertSame( array(), $before['removed'] );
	}

	public function test_insertar_dentro_agrega_al_final_del_contenedor(): void {
		$tree   = ElementorSectionFixtures::atomic();
		$plan   = $this->plan( $tree, 'insert', 'fx00001', 'inside' );
		$result = ElementorSectionSplicer::apply( $tree, $plan, $this->built() );

		$this->assertSame( 'atomic', $plan['parent_kind'] );
		$this->assertSame( 1, $plan['base_depth'] );
		$this->assertSame( array( 'eh00001', 'ep00001', 'nw00001' ), array_column( $result['tree'][0]['elements'], 'id' ) );
	}

	public function test_insertar_en_una_columna_v3_usa_las_reglas_de_la_columna(): void {
		$tree = ElementorSectionFixtures::v3();

		$inside = $this->plan( $tree, 'insert', 'col0001', 'inside' );
		$before = $this->plan( $tree, 'insert', 'hd00001', 'before' );

		$this->assertSame( 'column', $inside['parent_kind'] );
		$this->assertSame( 2, $inside['base_depth'] );
		$this->assertSame( 'column', $before['parent_kind'] );
		$this->assertSame( 2, $before['base_depth'] );
		$this->assertSame( 'section', $this->plan( $tree, 'insert', 'sec0001', 'inside' )['parent_kind'] );
		$this->assertSame( 'widget', $this->plan( $tree, 'insert', 'hd00001', 'inside' )['parent_kind'] );
		$this->assertSame( 'container', $this->plan( $tree, 'insert', 'cnt0001', 'inside' )['parent_kind'] );
	}

	public function test_borrar_quita_el_nodo_con_todo_su_subarbol_y_deja_el_resto_igual(): void {
		$tree   = ElementorSectionFixtures::v3();
		$result = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'delete', 'col0001' ), array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'col0001', 'hd00001', 'tx00001' ), $result['removed'] );
		$this->assertSame( array(), $result['node_ids'] );
		$this->assertSame( array(), $result['tree'][0]['elements'] );
		$this->assertSame( json_encode( $tree[1] ), json_encode( $result['tree'][1] ) );
		$this->assertSame( json_encode( $tree[2] ), json_encode( $result['tree'][2] ) );
	}

	public function test_borrar_la_ultima_raiz_se_rechaza(): void {
		$tree = ElementorSectionFixtures::atomic();
		$plan = ElementorSectionSplicer::plan( $tree, 'delete', 'fx00001' );

		$this->assertInstanceOf( \WP_Error::class, $plan );
		$this->assertSame( 'nexo_mcp_elementor_last_root', $plan->get_error_code() );
	}

	public function test_borrar_una_raiz_con_hermanas_es_valido(): void {
		$tree = ElementorSectionFixtures::v3();
		$plan = $this->plan( $tree, 'delete', 'sec0001' );

		$this->assertSame( 5, $plan['nodes_without'] );
		$this->assertSame( array( 'sec0002', 'cnt0001' ), array_column( ElementorSectionSplicer::apply( $tree, $plan, array() )['tree'], 'id' ) );
	}

	public function test_los_rechazos_de_planificacion(): void {
		$tree = ElementorSectionFixtures::v3();

		$cases = array(
			array( 'replace', 'no-existe', '', 'nexo_mcp_builder_node_missing' ),
			array( 'replace', '', '', 'nexo_mcp_builder_node_missing' ),
			array( 'mover', 'sec0001', '', 'nexo_mcp_elementor_operation_invalid' ),
			array( 'insert', 'sec0001', '', 'nexo_mcp_elementor_position_invalid' ),
			array( 'insert', 'sec0001', 'encima', 'nexo_mcp_elementor_position_invalid' ),
			array( 'replace', 'sec0001', 'before', 'nexo_mcp_elementor_position_invalid' ),
			array( 'delete', 'sec0001', 'after', 'nexo_mcp_elementor_position_invalid' ),
		);

		foreach ( $cases as $case ) {
			$plan = ElementorSectionSplicer::plan( $tree, $case[0], $case[1], $case[2] );
			$this->assertInstanceOf( \WP_Error::class, $plan, implode( '/', $case ) );
			$this->assertSame( $case[3], $plan->get_error_code(), implode( '/', $case ) );
		}
	}

	public function test_un_id_repetido_en_el_documento_es_ambiguo(): void {
		$tree                       = ElementorSectionFixtures::v3();
		$tree[2]['elements'][0]['id'] = 'hd00001';

		$plan = ElementorSectionSplicer::plan( $tree, 'delete', 'hd00001' );

		$this->assertInstanceOf( \WP_Error::class, $plan );
		$this->assertSame( 'nexo_mcp_elementor_node_ambiguous', $plan->get_error_code() );
	}

	public function test_un_id_nuevo_que_ya_existe_se_rechaza(): void {
		$tree = ElementorSectionFixtures::v3();
		$plan = $this->plan( $tree, 'insert', 'sec0001', 'after' );

		foreach ( array( $this->built( 'sec0002', 'nw00002' ), $this->built( 'nw00001', 'hd00001' ), $this->built( 'nw00001', 'nw00001' ) ) as $built ) {
			$result = ElementorSectionSplicer::apply( $tree, $plan, $built );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_elementor_id_collision', $result->get_error_code() );
		}
	}

	public function test_reemplazar_no_admite_reutilizar_ni_los_ids_quitados(): void {
		$tree   = ElementorSectionFixtures::v3();
		$result = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'replace', 'sec0001' ), $this->built( 'sec0001', 'nw00002' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_id_collision', $result->get_error_code() );
	}

	public function test_insertar_dentro_de_un_contenedor_sin_clave_elements_la_trata_como_lista_vacia(): void {
		foreach ( array( 0 => 'atomic', 1 => 'container' ) as $unused => $kind ) {
			$tree = 'atomic' === $kind ? ElementorSectionFixtures::atomic() : ElementorSectionFixtures::v3();
			$id   = 'atomic' === $kind ? 'fx00001' : 'cnt0001';
			$path = 'atomic' === $kind ? array( 0 ) : array( 2 );
			unset( $tree[ $path[0] ]['elements'] );

			$plan   = $this->plan( $tree, 'insert', $id, 'inside' );
			$result = ElementorSectionSplicer::apply( $tree, $plan, $this->built() );

			$this->assertIsArray( $result, $kind . ( $result instanceof \WP_Error ? $result->get_error_message() : '' ) );
			$this->assertSame( array( 'nw00001' ), array_column( $result['tree'][ $path[0] ]['elements'], 'id' ), $kind );
		}
	}

	public function test_sin_clave_elements_en_una_columna_o_seccion_no_se_inventa_la_lista(): void {
		$tree = ElementorSectionFixtures::v3();
		unset( $tree[0]['elements'][0]['elements'] );

		$result = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'col0001', 'inside' ), $this->built() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_structure_changed', $result->get_error_code() );
	}

	public function test_reemplazar_un_subarbol_cuyo_id_esta_repetido_en_otra_parte_se_rechaza(): void {
		$dup  = static fn( string $id ): array => array(
			'id'       => $id,
			'elType'   => 'e-flexbox',
			'settings' => array(),
			'elements' => array( array( 'id' => 'dup', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array(), 'elements' => array() ) ),
		);
		$tree = array( $dup( 'k1' ), $dup( 'k2' ) );

		$plan   = $this->plan( $tree, 'replace', 'k1' );
		$result = ElementorSectionSplicer::apply( $tree, $plan, $this->built() );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_insertar_dentro_de_un_contenedor_atomico_despues_de_los_hijos_conserva_el_orden(): void {
		$tree = array(
			array(
				'id'       => 'box',
				'elType'   => 'e-flexbox',
				'settings' => array(),
				'elements' => array( array( 'id' => 'h', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array(), 'elements' => array() ) ),
			),
		);

		$built  = array( array( 'id' => 'n', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array(), 'elements' => array() ) );
		$result = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'insert', 'box', 'inside' ), $built );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'h', 'n' ), array_column( $result['tree'][0]['elements'], 'id' ) );
	}

	public function test_los_ids_del_resultado_son_los_previos_menos_los_quitados_mas_los_nuevos(): void {
		$tree   = ElementorSectionFixtures::v3();
		$result = ElementorSectionSplicer::apply( $tree, $this->plan( $tree, 'replace', 'sec0002' ), $this->built() );

		$expected = array_values( array_diff( $this->ids( $tree ), array( 'sec0002', 'col0002', 'bt00001' ) ) );
		$expected = array_merge( $expected, array( 'nw00001', 'nw00002' ) );
		$actual   = $this->ids( $result['tree'] );
		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}
}
