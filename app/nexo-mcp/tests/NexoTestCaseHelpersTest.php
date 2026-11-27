<?php
/**
 * El helper `pos()` de las pruebas falla en voz alta cuando falta un marcador.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use PHPUnit\Framework\AssertionFailedError;

final class NexoTestCaseHelpersTest extends NexoTestCase {

	public function test_pos_devuelve_la_posicion_del_marcador(): void {
		$this->assertSame( 0, $this->pos( 'abc', 'a' ) );
		$this->assertSame( 4, $this->pos( 'a-b-a', 'a', 1 ) );
	}

	public function test_pos_falla_si_falta_el_marcador(): void {
		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( 'falta el marcador: nada' );

		$this->pos( 'abc', 'nada' );
	}
}
