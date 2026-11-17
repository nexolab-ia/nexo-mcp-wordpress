<?php
/**
 * Diff unificado por líneas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\LineDiff;
use PHPUnit\Framework\TestCase;

final class LineDiffTest extends TestCase {

	public function test_textos_iguales_no_dan_diff(): void {
		$this->assertSame(
			array( 'diff' => '', 'truncated' => false ),
			LineDiff::unified( "a\nb\nc", "a\nb\nc" )
		);
	}

	public function test_un_cambio_lleva_tres_lineas_de_contexto_y_encabezado(): void {
		$before = "<h1>Inicio</h1>\n<p>Hola</p>\n<p>Texto</p>\n<p>Precio: \$10</p>\n<p>Uno</p>\n<p>Dos</p>\n<p>Tres</p>\n<p>Cuatro</p>";
		$after  = str_replace( 'Precio: $10', 'Precio: $12', $before );

		$expected = "@@ -1,7 +1,7 @@\n"
			. " <h1>Inicio</h1>\n"
			. " <p>Hola</p>\n"
			. " <p>Texto</p>\n"
			. "-<p>Precio: \$10</p>\n"
			. "+<p>Precio: \$12</p>\n"
			. " <p>Uno</p>\n"
			. " <p>Dos</p>\n"
			. ' <p>Tres</p>';

		$this->assertSame( array( 'diff' => $expected, 'truncated' => false ), LineDiff::unified( $before, $after ) );
	}

	public function test_el_contexto_se_puede_reducir_a_cero(): void {
		$result = LineDiff::unified( "a\nb\nc\nd\ne", "a\nb\nX\nd\ne", 0 );

		$this->assertSame( "@@ -3,1 +3,1 @@\n-c\n+X", $result['diff'] );
	}

	public function test_lineas_agregadas_al_inicio(): void {
		$result = LineDiff::unified( "a\nb", "x\na\nb" );

		$this->assertSame( "@@ -1,2 +1,3 @@\n+x\n a\n b", $result['diff'] );
	}

	public function test_lineas_quitadas_al_final(): void {
		$result = LineDiff::unified( "a\nb\nc", "a\nb" );

		$this->assertSame( "@@ -1,3 +1,2 @@\n a\n b\n-c", $result['diff'] );
	}

	public function test_de_vacio_a_contenido_y_de_contenido_a_vacio(): void {
		$this->assertSame( "@@ -0,0 +1,2 @@\n+a\n+b", LineDiff::unified( '', "a\nb" )['diff'] );
		$this->assertSame( "@@ -1,2 +0,0 @@\n-a\n-b", LineDiff::unified( "a\nb", '' )['diff'] );
	}

	public function test_cambios_lejanos_dan_dos_bloques_y_los_cercanos_uno(): void {
		$base = array( 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8', 'l9', 'l10', 'l11', 'l12' );

		// Entre el cambio de l1 y el de l12 hay 10 líneas iguales: dos bloques.
		$far          = $base;
		$far[0]       = 'A';
		$far[11]      = 'B';
		$far_expected = "@@ -1,4 +1,4 @@\n-l1\n+A\n l2\n l3\n l4\n@@ -9,4 +9,4 @@\n l9\n l10\n l11\n-l12\n+B";
		$this->assertSame( $far_expected, LineDiff::unified( implode( "\n", $base ), implode( "\n", $far ) )['diff'] );

		// Con 6 líneas iguales de por medio el contexto se toca: un solo bloque.
		$near     = $base;
		$near[0]  = 'A';
		$near[7]  = 'B';
		$expected = "@@ -1,11 +1,11 @@\n-l1\n+A\n l2\n l3\n l4\n l5\n l6\n l7\n-l8\n+B\n l9\n l10\n l11";
		$this->assertSame( $expected, LineDiff::unified( implode( "\n", $base ), implode( "\n", $near ) )['diff'] );
	}

	public function test_finales_de_linea_windows_no_cuentan_como_cambio(): void {
		$this->assertSame( '', LineDiff::unified( "a\r\nb", "a\nb" )['diff'] );
	}

	public function test_el_tope_corta_en_limite_de_linea_y_lo_avisa(): void {
		$before = implode( "\n", array_map( static fn( $n ) => 'linea ' . $n, range( 1, 50 ) ) );
		$after  = implode( "\n", array_map( static fn( $n ) => 'nueva ' . $n, range( 1, 50 ) ) );

		$result = LineDiff::unified( $before, $after, 3, 60 );

		$this->assertTrue( $result['truncated'] );
		$this->assertLessThanOrEqual( 60, strlen( $result['diff'] ) );
		$this->assertStringStartsWith( '@@ -1,50 +1,50 @@', $result['diff'] );
		$this->assertStringNotContainsString( "\n\n", $result['diff'] );
	}

	public function test_sin_pasar_el_tope_no_se_marca_truncado(): void {
		$this->assertFalse( LineDiff::unified( 'a', 'b', 3, 1000 )['truncated'] );
	}
}
