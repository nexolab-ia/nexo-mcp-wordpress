<?php
/**
 * Diff unificado por líneas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Support;

/**
 * Compara dos textos línea por línea y los muestra como `git diff`.
 *
 * PHP puro, sin `wp_text_diff()`, para poder probarlo sin WordPress.
 */
final class LineDiff {

	/**
	 * Tope de celdas de la tabla de comparación (líneas distintas al cuadrado).
	 *
	 * Pasado este tamaño no se busca el calce fino: lo que difiere se muestra
	 * entero como quitado y agregado, que sigue siendo correcto.
	 */
	private const MAX_CELLS = 4000000;

	/**
	 * Genera el diff unificado entre dos textos.
	 *
	 * @param string $before    Texto original.
	 * @param string $after     Texto nuevo.
	 * @param int    $context   Líneas de contexto alrededor de cada cambio.
	 * @param int    $max_bytes Tope del diff en bytes; se corta en límite de línea.
	 * @return array{diff: string, truncated: bool}
	 */
	public static function unified( string $before, string $after, int $context = 3, int $max_bytes = 20000 ): array {
		$old   = self::lines( $before );
		$fresh = self::lines( $after );
		$ops   = self::operations( $old, $fresh );

		$lines = self::render( $ops, max( 0, $context ) );

		if ( array() === $lines ) {
			return array(
				'diff'      => '',
				'truncated' => false,
			);
		}

		$diff = implode( "\n", $lines );

		if ( strlen( $diff ) <= $max_bytes ) {
			return array(
				'diff'      => $diff,
				'truncated' => false,
			);
		}

		$kept   = array();
		$length = 0;

		foreach ( $lines as $line ) {
			$next = $length + strlen( $line ) + ( array() === $kept ? 0 : 1 );

			if ( $next > $max_bytes ) {
				break;
			}

			$kept[] = $line;
			$length = $next;
		}

		return array(
			'diff'      => implode( "\n", $kept ),
			'truncated' => true,
		);
	}

	/**
	 * Parte un texto en líneas, sin distinguir \r\n de \n.
	 *
	 * @param string $text Texto.
	 * @return list<string>
	 */
	private static function lines( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		return explode( "\n", str_replace( "\r\n", "\n", $text ) );
	}

	/**
	 * Calcula la secuencia de operaciones: igual, quitada o agregada.
	 *
	 * @param array<int, string> $old Líneas originales.
	 * @param array<int, string> $fresh Líneas nuevas.
	 * @return list<array{0: string, 1: string}> Pares [operación ' ', '-' o '+', línea].
	 */
	private static function operations( array $old, array $fresh ): array {
		$total_old = count( $old );
		$total_new = count( $fresh );
		$start     = 0;

		while ( $start < $total_old && $start < $total_new && $old[ $start ] === $fresh[ $start ] ) {
			++$start;
		}

		$end_old = $total_old;
		$end_new = $total_new;

		while ( $end_old > $start && $end_new > $start && $old[ $end_old - 1 ] === $fresh[ $end_new - 1 ] ) {
			--$end_old;
			--$end_new;
		}

		$ops = array();

		for ( $i = 0; $i < $start; $i++ ) {
			$ops[] = array( ' ', $old[ $i ] );
		}

		$mid_old = array_slice( $old, $start, $end_old - $start );
		$mid_new = array_slice( $fresh, $start, $end_new - $start );

		foreach ( self::middle( $mid_old, $mid_new ) as $op ) {
			$ops[] = $op;
		}

		for ( $i = $end_old; $i < $total_old; $i++ ) {
			$ops[] = array( ' ', $old[ $i ] );
		}

		return $ops;
	}

	/**
	 * Compara el tramo central con la subsecuencia común más larga.
	 *
	 * @param array<int, string> $old Líneas originales del tramo.
	 * @param array<int, string> $fresh Líneas nuevas del tramo.
	 * @return list<array{0: string, 1: string}>
	 */
	private static function middle( array $old, array $fresh ): array {
		$rows = count( $old );
		$cols = count( $fresh );
		$ops  = array();

		if ( 0 === $rows || 0 === $cols || $rows * $cols > self::MAX_CELLS ) {
			foreach ( $old as $line ) {
				$ops[] = array( '-', $line );
			}

			foreach ( $fresh as $line ) {
				$ops[] = array( '+', $line );
			}

			return $ops;
		}

		// lcs[i][j]: largo de la subsecuencia común de old[i..] y new[j..].
		$lcs = array_fill( 0, $rows + 1, array_fill( 0, $cols + 1, 0 ) );

		for ( $i = $rows - 1; $i >= 0; $i-- ) {
			for ( $j = $cols - 1; $j >= 0; $j-- ) {
				$lcs[ $i ][ $j ] = $old[ $i ] === $fresh[ $j ]
					? $lcs[ $i + 1 ][ $j + 1 ] + 1
					: max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
			}
		}

		$i = 0;
		$j = 0;

		while ( $i < $rows && $j < $cols ) {
			if ( $old[ $i ] === $fresh[ $j ] ) {
				$ops[] = array( ' ', $old[ $i ] );
				++$i;
				++$j;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$ops[] = array( '-', $old[ $i++ ] );
			} else {
				$ops[] = array( '+', $fresh[ $j++ ] );
			}
		}

		for ( ; $i < $rows; $i++ ) {
			$ops[] = array( '-', $old[ $i ] );
		}

		for ( ; $j < $cols; $j++ ) {
			$ops[] = array( '+', $fresh[ $j ] );
		}

		return $ops;
	}

	/**
	 * Agrupa las operaciones en bloques con contexto y los escribe.
	 *
	 * @param list<array{0: string, 1: string}> $ops     Operaciones.
	 * @param int                               $context Líneas de contexto.
	 * @return list<string>
	 */
	private static function render( array $ops, int $context ): array {
		$count   = count( $ops );
		$changes = array();

		foreach ( $ops as $index => $op ) {
			if ( ' ' !== $op[0] ) {
				$changes[] = $index;
			}
		}

		if ( array() === $changes ) {
			return array();
		}

		// Cada cambio abre un rango con contexto; los que se tocan se unen.
		$ranges = array();

		foreach ( $changes as $index ) {
			$from = max( 0, $index - $context );
			$to   = min( $count - 1, $index + $context );
			$last = count( $ranges ) - 1;

			if ( $last >= 0 && $from <= $ranges[ $last ][1] + 1 ) {
				$ranges[ $last ][1] = $to;
			} else {
				$ranges[] = array( $from, $to );
			}
		}

		// Número de línea (vieja y nueva) que corresponde a cada operación.
		$old_at = array();
		$new_at = array();
		$old_n  = 0;
		$new_n  = 0;

		foreach ( $ops as $index => $op ) {
			$old_at[ $index ] = $old_n;
			$new_at[ $index ] = $new_n;

			if ( '+' !== $op[0] ) {
				++$old_n;
			}

			if ( '-' !== $op[0] ) {
				++$new_n;
			}
		}

		$out = array();

		foreach ( $ranges as list( $from, $to ) ) {
			$old_count = 0;
			$new_count = 0;
			$body      = array();

			for ( $index = $from; $index <= $to; $index++ ) {
				$body[] = $ops[ $index ][0] . $ops[ $index ][1];

				if ( '+' !== $ops[ $index ][0] ) {
					++$old_count;
				}

				if ( '-' !== $ops[ $index ][0] ) {
					++$new_count;
				}
			}

			// Un rango vacío se numera con la línea anterior, como hace git.
			$old_start = 0 === $old_count ? $old_at[ $from ] : $old_at[ $from ] + 1;
			$new_start = 0 === $new_count ? $new_at[ $from ] : $new_at[ $from ] + 1;

			$out[] = sprintf( '@@ -%d,%d +%d,%d @@', $old_start, $old_count, $new_start, $new_count );

			foreach ( $body as $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}
}
