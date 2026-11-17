<?php
/**
 * Documentos de Elementor de ejemplo para las pruebas de edit-elementor-section.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

final class ElementorSectionFixtures {

	/**
	 * @return array<string, mixed>
	 */
	public static function prop( string $type, $value ): array {
		return array(
			'$$type' => $type,
			'value'  => $value,
		);
	}

	/**
	 * Widget v3.
	 *
	 * @param array<string, mixed> $settings Ajustes.
	 * @return array<string, mixed>
	 */
	public static function v3_widget( string $id, string $type, array $settings ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Documento v3: dos secciones (sección > columna > widgets) y un contenedor v3.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function v3(): array {
		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'isInner'  => false,
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100, '_inline_size' => null ),
						'elements' => array(
							self::v3_widget( 'hd00001', 'heading', array( 'title' => 'Título ñandú € "comillas"', 'size' => 'large', 'opacity' => 0.5 ) ),
							self::v3_widget( 'tx00001', 'text-editor', array( 'editor' => '<p>Texto / barra</p>' ) ),
						),
					),
				),
			),
			array(
				'id'       => 'sec0002',
				'elType'   => 'section',
				'settings' => array( 'gap' => 'no' ),
				'elements' => array(
					array(
						'id'       => 'col0002',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							self::v3_widget( 'bt00001', 'button', array( 'text' => 'Botón', 'link' => array( 'url' => 'https://example.com', 'is_external' => '' ) ) ),
						),
					),
				),
			),
			array(
				'id'       => 'cnt0001',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					self::v3_widget( 'hd00002', 'heading', array( 'title' => 'En contenedor' ) ),
				),
			),
		);
	}

	/**
	 * Documento atómico: un e-flexbox con un heading y un párrafo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function atomic(): array {
		return array(
			array(
				'id'       => 'fx00001',
				'elType'   => 'e-flexbox',
				'settings' => array(),
				'styles'   => array(),
				'elements' => array(
					array(
						'id'         => 'eh00001',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => array( 'title' => self::prop( 'escaped-html', 'Hola' ) ),
						'styles'     => array(),
						'elements'   => array(),
					),
					array(
						'id'         => 'ep00001',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'settings'   => array( 'paragraph' => self::prop( 'escaped-html', 'Párrafo' ) ),
						'styles'     => array(),
						'elements'   => array(),
					),
				),
			),
		);
	}

	/**
	 * Subárbol nuevo del cliente: e-flexbox con un e-heading.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function new_box( string $text = 'Nuevo' ): array {
		return array(
			array(
				'type'     => 'e-flexbox',
				'elements' => array( self::new_heading( $text ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function new_heading( string $text = 'Nuevo' ): array {
		return array(
			'type'     => 'e-heading',
			'settings' => array( 'title' => self::prop( 'escaped-html', $text ) ),
		);
	}
}
