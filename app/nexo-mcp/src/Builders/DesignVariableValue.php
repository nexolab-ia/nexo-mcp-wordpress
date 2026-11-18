<?php
/**
 * Validación del valor de una variable global según su tipo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Un valor llega a CSS tal cual, así que cada tipo admite sólo su forma.
 *
 * - color: hex, `rgb()/rgba()/hsl()/hsla()` con números.
 * - fuente: nombres de familia (letras, números, espacios, `-_.,` y comillas).
 * - tamaño: número + unidad de Elementor en minúsculas (la forma que `Prop_Type_Adapter` interpreta).
 * - tamaño personalizado: texto CSS libre (`calc()`, `clamp()`…) con las mismas restricciones de hojas
 *   que las hojas de `ElementorTreeValidator` (F2/F3, `css_text_problem()`).
 */
final class DesignVariableValue {

	public const COLOR = 'global-color-variable';

	public const FONT = 'global-font-variable';

	public const SIZE = 'global-size-variable';

	public const CUSTOM_SIZE = 'global-custom-size-variable';

	public const MAX_LENGTH = 512;

	/**
	 * Unidades de tamaño si Elementor no está cargado (las de `Size_Constants::all_supported_units()`).
	 *
	 * @var array<int, string>
	 */
	private const FALLBACK_UNITS = array( 'px', 'em', 'rem', 'vw', 'vh', 'ch', 's', 'ms', 'deg', 'rad', 'grad', 'turn', 'vmin', 'vmax', '%', 'fr' );

	/**
	 * Tipos admitidos.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array( self::COLOR, self::FONT, self::SIZE, self::CUSTOM_SIZE );
	}

	/**
	 * Valida un valor ya recortado contra su tipo.
	 *
	 * @param string $type  Uno de `types()`.
	 * @param string $value Valor.
	 *
	 * @return true|string `true`, o el motivo del rechazo.
	 */
	public static function check( string $type, string $value ) {
		if ( '' === $value ) {
			return __( 'no puede estar vacío.', 'nexo-mcp' );
		}

		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			return sprintf(
				/* translators: %d: máximo de caracteres. */
				__( 'no puede pasar de %d caracteres.', 'nexo-mcp' ),
				self::MAX_LENGTH
			);
		}

		if ( self::CUSTOM_SIZE === $type ) {
			return self::check_custom_size( $value );
		}

		if ( 1 === preg_match( '/[;{}<>\\\\@\x00-\x1F\x7F]|\/\*|!\s*important|(?:url|src|expression|image-set|image|cross-fade|element|var)\s*\(/i', $value ) ) {
			return __( 'contiene caracteres o funciones que no se admiten (; { } < > \\ @, saltos de línea, url(), expression(), var()…).', 'nexo-mcp' );
		}

		switch ( $type ) {
			case self::COLOR:
				return self::check_color( $value );
			case self::FONT:
				return self::check_font( $value );
			case self::SIZE:
				return self::check_size( $value );
		}

		return __( 'el tipo no es válido.', 'nexo-mcp' );
	}

	/**
	 * Color: hex de 3, 4, 6 u 8 dígitos, o función de color con sólo números.
	 *
	 * @param string $value Valor.
	 *
	 * @return true|string
	 */
	private static function check_color( string $value ) {
		if ( 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return true;
		}

		$part = '[+-]?(?:\d+\.?\d*|\.\d+)(?:%|deg|rad|grad|turn)?';

		if ( 1 === preg_match( '/^(?:rgb|hsl)a?\(\s*' . $part . '(?:\s*[,\s]\s*' . $part . '){2}(?:\s*[,\/]\s*' . $part . ')?\s*\)$/i', $value ) ) {
			return true;
		}

		return __( 'no es un color válido: usá hex (#rgb, #rrggbb, #rrggbbaa) o rgb()/rgba()/hsl()/hsla() con números.', 'nexo-mcp' );
	}

	/**
	 * Fuente: nombres de familia separados por comas.
	 *
	 * @param string $value Valor.
	 *
	 * @return true|string
	 */
	private static function check_font( string $value ) {
		if (
			1 !== preg_match( '/^[\p{L}\p{N} _.,\'"-]+$/u', $value )
			|| 1 !== preg_match( '/[\p{L}\p{N}]/u', $value )
			|| 0 !== substr_count( $value, '"' ) % 2
			|| 0 !== substr_count( $value, "'" ) % 2
		) {
			return __( 'no es un nombre de fuente válido: sólo nombres de familia (letras, números, espacios, comas y comillas cerradas).', 'nexo-mcp' );
		}

		return true;
	}

	/**
	 * Tamaño: número + unidad de Elementor.
	 *
	 * @param string $value Valor.
	 *
	 * @return true|string
	 */
	private static function check_size( string $value ) {
		// Misma expresión que `Prop_Type_Adapter::parse_size_value`, para que Elementor lo lea igual.
		if ( 1 !== preg_match( '/^(-?\d*\.?\d+)([a-z%]+)$/', $value, $matches ) || ! in_array( $matches[2], self::units(), true ) ) {
			return sprintf(
				/* translators: %s: unidades. */
				__( 'no es un tamaño válido: usá un número seguido de una unidad en minúsculas, sin espacios (%s).', 'nexo-mcp' ),
				implode( ', ', self::units() )
			);
		}

		return true;
	}

	/**
	 * Tamaño personalizado: texto CSS con las mismas restricciones que las hojas de F2/F3.
	 *
	 * @param string $value Valor.
	 *
	 * @return true|string
	 */
	private static function check_custom_size( string $value ) {
		$problem = ElementorTreeValidator::css_text_problem( $value );

		return null === $problem ? true : $problem;
	}

	/**
	 * Unidades de tamaño de Elementor, sin `auto` ni `custom` (ésa es la otra variable).
	 *
	 * @return array<int, string>
	 */
	private static function units(): array {
		$constants = '\Elementor\Modules\AtomicWidgets\Styles\Size_Constants';
		$units     = class_exists( $constants ) ? $constants::all_supported_units() : self::FALLBACK_UNITS;

		return array_values( array_diff( $units, array( 'auto', 'custom' ) ) );
	}
}
