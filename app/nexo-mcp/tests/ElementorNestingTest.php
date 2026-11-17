<?php
/**
 * Reglas de anidado de los elementos atómicos nuevos (clase pura).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorNesting;
use PHPUnit\Framework\TestCase;

final class ElementorNestingTest extends TestCase {

	/**
	 * Padre, tipo nuevo y si se admite.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function matrix(): array {
		return array(
			'raíz: e-flexbox'              => array( ElementorNesting::ROOT, 'e-flexbox', true ),
			'raíz: e-div-block'            => array( ElementorNesting::ROOT, 'e-div-block', true ),
			'raíz: widget rechazado'       => array( ElementorNesting::ROOT, 'e-heading', false ),
			'columna v3: widget'           => array( ElementorNesting::COLUMN, 'e-heading', true ),
			'columna v3: contenedor'       => array( ElementorNesting::COLUMN, 'e-flexbox', false ),
			'sección v3: widget'           => array( ElementorNesting::SECTION, 'e-heading', false ),
			'sección v3: contenedor'       => array( ElementorNesting::SECTION, 'e-flexbox', false ),
			'contenedor v3: widget'        => array( ElementorNesting::CONTAINER, 'e-heading', true ),
			'contenedor v3: contenedor'    => array( ElementorNesting::CONTAINER, 'e-div-block', true ),
			'contenedor atómico: widget'   => array( ElementorNesting::ATOMIC, 'e-button', true ),
			'contenedor atómico: contenedor' => array( ElementorNesting::ATOMIC, 'e-flexbox', true ),
			'widget: widget'               => array( ElementorNesting::WIDGET, 'e-heading', false ),
			'widget: contenedor'           => array( ElementorNesting::WIDGET, 'e-flexbox', false ),
			'padre desconocido'            => array( ElementorNesting::UNKNOWN, 'e-heading', false ),
		);
	}

	/**
	 * @dataProvider matrix
	 */
	public function test_la_matriz_de_anidado( string $parent, string $type, bool $allowed ): void {
		$reason = ElementorNesting::reason( $parent, $type );

		if ( $allowed ) {
			$this->assertNull( $reason );
		} else {
			$this->assertIsString( $reason );
			$this->assertNotSame( '', $reason );
		}
	}

	public function test_los_motivos_explican_la_regla(): void {
		$this->assertStringContainsString( 'raíz', (string) ElementorNesting::reason( ElementorNesting::ROOT, 'e-heading' ) );
		$this->assertStringContainsString( 'columna', (string) ElementorNesting::reason( ElementorNesting::COLUMN, 'e-flexbox' ) );
		$this->assertStringContainsString( 'sección', (string) ElementorNesting::reason( ElementorNesting::SECTION, 'e-heading' ) );
		$this->assertStringContainsString( 'widget', (string) ElementorNesting::reason( ElementorNesting::WIDGET, 'e-heading' ) );
	}

	public function test_el_tipo_de_un_elemento_guardado(): void {
		$this->assertSame( ElementorNesting::SECTION, ElementorNesting::kind_of( array( 'elType' => 'section' ) ) );
		$this->assertSame( ElementorNesting::COLUMN, ElementorNesting::kind_of( array( 'elType' => 'column' ) ) );
		$this->assertSame( ElementorNesting::CONTAINER, ElementorNesting::kind_of( array( 'elType' => 'container' ) ) );
		$this->assertSame( ElementorNesting::ATOMIC, ElementorNesting::kind_of( array( 'elType' => 'e-flexbox' ) ) );
		$this->assertSame( ElementorNesting::ATOMIC, ElementorNesting::kind_of( array( 'elType' => 'e-div-block' ) ) );
		$this->assertSame( ElementorNesting::WIDGET, ElementorNesting::kind_of( array( 'elType' => 'widget', 'widgetType' => 'e-heading' ) ) );
		$this->assertSame( ElementorNesting::WIDGET, ElementorNesting::kind_of( array( 'elType' => 'widget', 'widgetType' => 'heading' ) ) );
		$this->assertSame( ElementorNesting::UNKNOWN, ElementorNesting::kind_of( array( 'elType' => 'raro' ) ) );
		$this->assertSame( ElementorNesting::ROOT, ElementorNesting::kind_of_type( 'root' ) );
	}
}
