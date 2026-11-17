<?php
/**
 * Lectura de elementos atómicos de Elementor 4 en el árbol de la página.
 *
 * @package NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorAdapter;

final class ElementorAtomicReadTest extends NexoTestCase {

	private const PAGE = 300;

	/**
	 * Guarda una página de Elementor con el árbol dado.
	 *
	 * @param array<int, mixed> $tree Árbol de `_elementor_data`.
	 */
	private function page( array $tree ): \WP_Post {
		\WP_Test_State::$posts[ self::PAGE ] = new \WP_Post(
			array(
				'ID'          => self::PAGE,
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( self::PAGE, '_elementor_edit_mode', 'builder' );
		update_post_meta( self::PAGE, '_elementor_data', wp_json_encode( $tree ) );

		return \WP_Test_State::$posts[ self::PAGE ];
	}

	/**
	 * @param array<int, mixed> $tree Árbol de `_elementor_data`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read( array $tree ): array {
		$adapter = new ElementorAdapter();

		return array_map(
			static fn( $node ) => $node->to_array(),
			$adapter->read_nodes( $this->page( $tree ) )
		);
	}

	private static function widget( string $id, string $type, array $settings ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'styles'     => array(),
			'elements'   => array(),
		);
	}

	public function test_atomic_widgets_expose_their_text_and_classes_read_only(): void {
		$reason = 'Elemento atómico de Elementor 4: por ahora sólo lectura.';
		$nodes  = $this->read(
			array(
				array(
					'id'       => 'f1',
					'elType'   => 'e-flexbox',
					'settings' => array(
						'classes' => array( '$$type' => 'classes', 'value' => array( 'e-f1-local', 'g-hero' ) ),
					),
					'styles'   => array(),
					'elements' => array(
						self::widget(
							'h1',
							'e-heading',
							array(
								'tag'     => array( '$$type' => 'string', 'value' => 'h1' ),
								'title'   => array( '$$type' => 'escaped-html', 'value' => 'Hola' ),
								'classes' => array( '$$type' => 'classes', 'value' => array( 'g-title' ) ),
							)
						),
						self::widget(
							'p1',
							'e-paragraph',
							array(
								'paragraph' => array(
									'$$type' => 'html-v3',
									'value'  => array(
										'content'  => array( '$$type' => 'string', 'value' => 'Texto <strong>fuerte</strong>' ),
										'children' => array(),
									),
								),
							)
						),
						self::widget( 'b1', 'e-button', array( 'text' => array( '$$type' => 'escaped-html', 'value' => 'Comprar' ) ) ),
						self::widget( 'i1', 'e-image', array() ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'       => 'f1',
					'type'     => 'e-flexbox',
					'editable' => false,
					'reason'   => $reason,
					'classes'  => array( 'e-f1-local', 'g-hero' ),
				),
				array(
					'id'       => 'h1',
					'type'     => 'e-heading',
					'text'     => 'Hola',
					'editable' => false,
					'reason'   => $reason,
					'classes'  => array( 'g-title' ),
				),
				array(
					'id'       => 'p1',
					'type'     => 'e-paragraph',
					'text'     => 'Texto <strong>fuerte</strong>',
					'editable' => false,
					'reason'   => $reason,
				),
				array(
					'id'       => 'b1',
					'type'     => 'e-button',
					'text'     => 'Comprar',
					'editable' => false,
					'reason'   => $reason,
				),
				array(
					'id'       => 'i1',
					'type'     => 'e-image',
					'editable' => false,
					'reason'   => $reason,
				),
			),
			$nodes
		);
	}

	public function test_malformed_atomic_props_read_as_no_text(): void {
		$nodes = $this->read(
			array(
				self::widget( 'h1', 'e-heading', array( 'title' => 'texto suelto sin $$type' ) ),
				self::widget( 'h2', 'e-heading', array( 'title' => array( '$$type' => 'escaped-html', 'value' => array( 'x' ) ) ) ),
				self::widget( 'h3', 'e-heading', array( 'classes' => array( '$$type' => 'classes', 'value' => 'no-es-lista' ) ) ),
			)
		);

		foreach ( $nodes as $node ) {
			$this->assertArrayNotHasKey( 'text', $node );
			$this->assertArrayNotHasKey( 'classes', $node );
		}
		$this->assertCount( 3, $nodes );
	}

	public function test_v3_widgets_and_containers_keep_their_previous_shape(): void {
		$nodes = $this->read(
			array(
				array(
					'id'       => 'c1',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(
						self::widget( 'w1', 'heading', array( 'title' => 'Hola v3' ) ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				array( 'id' => 'c1', 'type' => 'container', 'editable' => false ),
				array(
					'id'         => 'w1',
					'type'       => 'heading',
					'text'       => 'Hola v3',
					'attributes' => array( 'link' => '' ),
				),
			),
			$nodes
		);
	}
}
