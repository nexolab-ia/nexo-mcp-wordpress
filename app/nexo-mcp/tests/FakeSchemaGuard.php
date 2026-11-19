<?php
/**
 * Guarda de esquema sin Elementor para las pruebas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorSchemaGuard;
use NexoDev\NexoMCP\Builders\ElementorTreeValidator;

/**
 * Guarda de esquema sin Elementor: acepta los tipos propios y no corre los analizadores.
 */
class FakeSchemaGuard extends ElementorSchemaGuard {

	public bool $v4 = true;

	public int $parsed = 0;

	public int $sanitized = 0;

	/**
	 * Propiedades de estilo que admite el validador (vacío: ninguna, como antes).
	 *
	 * @var array<int, string>
	 */
	public array $style_props = array();

	public function require_v4() {
		return $this->v4 ? true : new \WP_Error( 'nexo_mcp_elementor_v4_required', 'Editor V4 apagado.' );
	}

	public function validator(): ElementorTreeValidator {
		$box  = array( 'classes', 'tag', 'link', 'attributes', '_cssid' );
		$text = array( 'classes', 'tag', 'title', 'paragraph', 'text', 'link', 'attributes', '_cssid', 'image' );

		return new ElementorTreeValidator(
			array(
				'e-flexbox'   => $box,
				'e-div-block' => $box,
				'e-heading'   => $text,
				'e-paragraph' => $text,
				'e-button'    => $text,
				'e-image'     => $text,
				'e-divider'   => array( 'classes', '_cssid' ),
			),
			array(),
			null,
			$this->style_props
		);
	}

	/**
	 * Sanea como lo haría Elementor: sin etiquetas ni comillas en los valores de texto.
	 */
	public function sanitize( array $elements ) {
		++$this->sanitized;

		foreach ( $elements as $index => $node ) {
			if ( is_array( $node['settings'] ?? null ) ) {
				array_walk_recursive(
					$node['settings'],
					static function ( &$value, $key ): void {
						if ( is_string( $value ) && '$$type' !== $key ) {
							$value = str_replace( '"', '', strip_tags( $value ) );
						}
					}
				);
			}

			if ( is_array( $node['elements'] ?? null ) ) {
				$node['elements'] = $this->sanitize( $node['elements'] );
			}

			$elements[ $index ] = $node;
		}

		return $elements;
	}

	public function validate( array $elements, string $path = 'elements' ) {
		++$this->parsed;

		return true;
	}
}
