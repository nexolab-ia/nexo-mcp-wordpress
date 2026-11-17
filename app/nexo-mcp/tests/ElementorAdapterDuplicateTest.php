<?php
/**
 * Duplicado de elementos (edit-structure): su comportamiento no cambia al compartir los ayudantes del árbol.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorAdapter;

final class ElementorAdapterDuplicateTest extends NexoTestCase {

	private ElementorAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_post_meta( 100, '_elementor_edit_mode', 'builder' );
		update_post_meta( 100, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3() ) );
		$this->adapter = new ElementorAdapter();
	}

	public function test_duplicar_un_widget_lo_pone_despues_del_original_con_ids_nuevos(): void {
		$prepared = $this->adapter->prepare_duplicate( \WP_Test_State::$posts[100], 'hd00001', '', 'after' );

		$this->assertIsArray( $prepared );
		$column = $prepared['tree'][0]['elements'][0]['elements'];
		$this->assertCount( 3, $column );
		$this->assertSame( 'hd00001', $column[0]['id'] );
		$this->assertSame( 'tx00001', $column[2]['id'] );
		$this->assertNotSame( 'hd00001', $column[1]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $column[1]['id'] );
		$this->assertSame( $column[0]['settings'], $column[1]['settings'] );
		$this->assertCount( 1, $prepared['created'] );
		$this->assertSame( $column[1]['id'], $prepared['created'][0]->id );
	}

	public function test_duplicar_una_seccion_antes_de_otra_y_con_todo_su_subarbol(): void {
		$prepared = $this->adapter->prepare_duplicate( \WP_Test_State::$posts[100], 'sec0001', 'sec0002', 'before' );

		$this->assertIsArray( $prepared );
		$this->assertSame( 'sec0001', $prepared['tree'][0]['id'] );
		$this->assertNotSame( 'sec0001', $prepared['tree'][1]['id'] );
		$this->assertSame( 'sec0002', $prepared['tree'][2]['id'] );
		$this->assertCount( 2, $prepared['tree'][1]['elements'][0]['elements'] );
		$ids = array();
		array_walk_recursive( $prepared['tree'], static function ( $value, $key ) use ( &$ids ): void {
			if ( 'id' === $key ) {
				$ids[] = $value;
			}
		} );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
	}

	public function test_un_widget_no_cabe_junto_a_una_seccion_y_un_nodo_inexistente_falla(): void {
		$bad = $this->adapter->prepare_duplicate( \WP_Test_State::$posts[100], 'hd00001', 'sec0002', 'after' );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_builder_bad_position', $bad->get_error_code() );

		$missing = $this->adapter->prepare_duplicate( \WP_Test_State::$posts[100], 'nada', '', 'after' );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'nexo_mcp_builder_node_missing', $missing->get_error_code() );
	}
}
