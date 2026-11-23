<?php
/**
 * Árboles de cliente de ejemplo para las pruebas de create-divi-page.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

final class Divi5SectionEditorTestFixtures {

	/**
	 * Una sección con una fila, una columna y un texto.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sections( string $text = 'Hola' ): array {
		return array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array(
									array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . $text . '</p>' ) ) ) ) ),
								),
							),
						),
					),
				),
			),
		);
	}
}
