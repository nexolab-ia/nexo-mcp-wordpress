<?php
/**
 * save-divi-library-item sin `et_pb_create_layout()` (Divi sin cargar, por ejemplo en WP-CLI): el mismo ítem, con los mismos
 * términos y metas, por `wp_insert_post()`.
 *
 * Corre en un proceso aparte y NO incluye el doble de `et_pb_create_layout()` (una función no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryCreateFallbackTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library( true, false );
	}

	public function test_without_et_pb_create_layout_the_item_has_the_same_terms_and_metas(): void {
		$this->assertFalse( function_exists( 'et_pb_create_layout' ) );

		$result = $this->run_tool( 'save-divi-library-item', array( 'title' => 'Aviso', 'type' => 'module', 'scope' => 'global', 'nodes' => array( $this->text_node( 'Aviso' ) ), 'categories' => array( 'Avisos' ) ) );
		$id     = max( array_keys( \WP_Test_State::$posts ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'et_pb_layout', \WP_Test_State::$posts[ $id ]->post_type );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ $id ]->post_status );
		$this->assertSame( array( 'module' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_type'] ) );
		$this->assertSame( array( 'global' ), array_keys( \WP_Test_State::$terms[ $id ]['scope'] ) );
		$this->assertSame( array( 'regular' ), array_keys( \WP_Test_State::$terms[ $id ]['module_width'] ) );
		$this->assertSame( array( 'Avisos' ), array_keys( \WP_Test_State::$terms[ $id ]['layout_category'] ) );
		$this->assertSame(
			array(
				'_et_pb_built_for_post_type'     => 'page',
				'_et_pb_template_type'          => 'module',
				'_et_pb_use_divi_5'             => 'on',
				'_et_pb_module_type'            => 'divi/text',
				'_et_pb_excluded_global_options' => '[]',
			),
			array_map( static fn( array $rows ): string => $rows[0], get_post_meta( $id ) )
		);
	}
}
