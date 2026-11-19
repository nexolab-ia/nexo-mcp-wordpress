<?php
/**
 * La migración admite v3 conservados sólo en su propio paso: las demás herramientas siguen rechazándolos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\ElementorAdapter;
use NexoDev\NexoMCP\Builders\ElementorPageCreator;
use NexoDev\NexoMCP\Builders\ElementorSectionEditor;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ElementorV3RejectionRegressionTest extends NexoTestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function box_with_v3_widget(): array {
		return array(
			array(
				'type'     => 'e-flexbox',
				'elements' => array( array( 'type' => 'spacer', 'settings' => array( 'space' => array( 'size' => 30 ) ) ) ),
			),
		);
	}

	public function test_create_elementor_page_sigue_rechazando_un_widget_v3_en_un_subarbol_nuevo(): void {
		$result = ( new ElementorPageCreator( null, new FakeSchemaGuard() ) )->create( array( 'title' => 'Landing', 'elements' => $this->box_with_v3_widget() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_type_denied', $result->get_error_code() );
		$this->assertStringStartsWith( 'elements[0].elements[0].type: «spacer» es un elemento de Elementor 3', $result->get_error_message() );
		$this->assertSame( array(), \WP_Test_State::$posts );
	}

	public function test_edit_elementor_section_sigue_rechazando_un_widget_v3_en_un_subarbol_nuevo(): void {
		$cfg = $this->settings( array( 'read_only' => false ) );
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		update_post_meta( 100, '_elementor_edit_mode', 'builder' );
		update_post_meta( 100, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3() ) );
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$editor = new ElementorSectionEditor( $cfg, new ElementorAdapter(), new BuilderBackup(), new WorkingCopy( $cfg ), new FakeSchemaGuard(), static fn() => true );
		$post   = get_post( 100 );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$result = $editor->edit( $post, array( 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => $this->box_with_v3_widget() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_type_denied', $result->get_error_code() );
		$this->assertStringStartsWith( 'elements[0].elements[0].type: «spacer» es un elemento de Elementor 3', $result->get_error_message() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}
}
