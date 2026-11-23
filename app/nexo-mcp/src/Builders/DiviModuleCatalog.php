<?php
/**
 * Catálogo cerrado de módulos de Divi 5 que el conector crea y edita.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lista fija (no configurable) de módulos, hijos admitidos y rutas de atributos.
 *
 * Los nombres de bloque y las rutas se confirmaron en Divi 5.19.1
 * (`includes/builder-5/visual-builder/packages/module-library/src/components/<módulo>/module.json`
 * y `server/_all_modules_conversion_outline.php`; las formas de valor, convirtiendo
 * shortcodes de Divi 4 con `Conversion::maybeConvertContent`). Todo atributo se guarda
 * como `<ruta>.desktop.value`; aquí sólo se listan las rutas hasta antes de `desktop`.
 *
 * Cada ruta lleva una especificación de valor ({@see self::SCALARS} o un arreglo
 * `enum` / `object`). Contenido y diseño son listas separadas: un atributo de diseño
 * no se puede escribir como contenido ni al revés. Quedan fuera de la lista, por
 * diseño: `module.advanced.html*` (htmlBefore/htmlAfter, elemento), `module.advanced.htmlAttributes`,
 * todo `css.*`, enlaces de módulo, fondos con imagen o video, y cualquier clave de tablet, teléfono u hover.
 */
final class DiviModuleCatalog {

	/**
	 * Padre virtual de la lista raíz de un documento.
	 */
	public const ROOT = 'root';

	/**
	 * Estructura, de fuera hacia dentro.
	 *
	 * @var array<int, string>
	 */
	public const STRUCTURE = array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column' );

	/**
	 * Módulos de contenido. `divi/icon-list-item` sólo cuelga de `divi/icon-list` (la «lista»).
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_MODULES = array(
		'divi/text',
		'divi/heading',
		'divi/button',
		'divi/image',
		'divi/divider',
		'divi/blurb',
		'divi/icon',
		'divi/video',
		'divi/icon-list',
		'divi/icon-list-item',
	);

	/**
	 * Esquemas de URL admitidos (las rutas relativas deben empezar por `/`, `#` o `?`).
	 *
	 * @var array<int, string>
	 */
	public const SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	/**
	 * Especificaciones escalares. Las demás son `array( 'enum' => [...] )` o `array( 'object' => [clave => spec] )`.
	 *
	 * - plain: texto sin marcado. rich: HTML con lista corta de etiquetas.
	 * - link: URL de enlace (vacía = sin enlace). media_url: URL http/https o relativa, no vacía. src: id de adjunto o media_url.
	 * - color, size, size_or_empty, onoff, family, weight, icon_unicode.
	 *
	 * @var array<int, string>
	 */
	public const SCALARS = array( 'plain', 'rich', 'link', 'media_url', 'src', 'color', 'size', 'size_or_empty', 'onoff', 'family', 'weight', 'icon_unicode' );

	/**
	 * Nombres de bloque de los módulos de contenido que admite una columna.
	 *
	 * @return array<int, string>
	 */
	private static function column_children(): array {
		return array_values( array_diff( self::CONTENT_MODULES, array( 'divi/icon-list-item' ) ) );
	}

	/**
	 * Todos los módulos del catálogo.
	 *
	 * @return array<int, string>
	 */
	public static function modules(): array {
		return array_merge( self::STRUCTURE, self::CONTENT_MODULES );
	}

	/**
	 * Indica si el módulo está en el catálogo.
	 *
	 * @param string $type Nombre de bloque (`divi/text`).
	 */
	public static function has( string $type ): bool {
		return in_array( $type, self::modules(), true );
	}

	/**
	 * Hijos que admite un padre (`ROOT` para la lista raíz); vacío para hojas y tipos desconocidos.
	 *
	 * @param string $type Nombre de bloque del padre.
	 *
	 * @return array<int, string>
	 */
	public static function children( string $type ): array {
		switch ( $type ) {
			case self::ROOT:
				return array( 'divi/placeholder', 'divi/section' );
			case 'divi/placeholder':
				return array( 'divi/section' );
			case 'divi/section':
				return array( 'divi/row' );
			case 'divi/row':
				return array( 'divi/column' );
			case 'divi/column':
				return self::column_children();
			case 'divi/icon-list':
				return array( 'divi/icon-list-item' );
			default:
				return array();
		}
	}

	/**
	 * Rutas de contenido de un módulo y la especificación de su valor.
	 *
	 * @param string $type Nombre de bloque.
	 *
	 * @return array<string, mixed>
	 */
	public static function content_paths( string $type ): array {
		if ( ! self::has( $type ) ) {
			return array();
		}

		$paths = array( 'module.meta.adminLabel' => 'plain' );

		switch ( $type ) {
			case 'divi/column':
				$paths['module.advanced.type'] = array( 'enum' => array( '4_4', '1_2', '1_3', '2_3', '1_4', '3_4', '1_5', '2_5', '3_5', '4_5', '1_6', '5_6' ) );
				break;
			case 'divi/text':
				$paths['content.innerContent'] = 'rich';
				break;
			case 'divi/heading':
				$paths['title.innerContent'] = 'plain';
				break;
			case 'divi/button':
				$paths['button.innerContent'] = array(
					'object' => array(
						'text'       => 'plain',
						'linkUrl'    => 'link',
						'linkTarget' => 'onoff',
					),
				);
				break;
			case 'divi/image':
				$paths['image.innerContent'] = array(
					'object' => array(
						'src'        => 'src',
						'alt'        => 'plain',
						'titleText'  => 'plain',
						'linkUrl'    => 'link',
						'linkTarget' => 'onoff',
					),
				);
				break;
			case 'divi/blurb':
				$paths['title.innerContent']     = array(
					'object' => array(
						'text'   => 'plain',
						'url'    => 'link',
						'target' => 'onoff',
					),
				);
				$paths['content.innerContent']   = 'rich';
				$paths['imageIcon.innerContent'] = array(
					'object' => array(
						'useIcon' => 'onoff',
						'icon'    => self::icon_glyph(),
						'src'     => 'src',
						'alt'     => 'plain',
					),
				);
				break;
			case 'divi/icon':
				$paths['icon.innerContent'] = array(
					'object' => array_merge(
						self::icon_glyph()['object'],
						array(
							'url'    => 'link',
							'target' => 'onoff',
							'title'  => 'plain',
						)
					),
				);
				break;
			case 'divi/video':
				$paths['video.innerContent']     = array(
					'object' => array(
						'src'  => 'media_url',
						'webm' => 'media_url',
					),
				);
				$paths['thumbnail.innerContent'] = array( 'object' => array( 'src' => 'src' ) );
				break;
			case 'divi/icon-list-item':
				$paths['content.innerContent'] = 'plain';
				$paths['icon.innerContent']    = self::icon_glyph();
				break;
			default:
				break;
		}

		return $paths;
	}

	/**
	 * Rutas de diseño (valor de escritorio) de un módulo y la especificación de su valor.
	 *
	 * @param string $type Nombre de bloque.
	 *
	 * @return array<string, mixed>
	 */
	public static function design_paths( string $type ): array {
		if ( ! self::has( $type ) || 'divi/placeholder' === $type || 'divi/icon-list-item' === $type ) {
			return array();
		}

		$spacing_path = 'divi/image' === $type ? 'module.advanced.spacing' : 'module.decoration.spacing';
		$paths        = array(
			'module.decoration.background' => array( 'object' => array( 'color' => 'color' ) ),
			$spacing_path                  => array(
				'object' => array(
					'margin'  => self::box(),
					'padding' => self::box(),
				),
			),
		);

		$orientation = array( 'object' => array( 'orientation' => array( 'enum' => array( 'left', 'center', 'right', 'justify' ) ) ) );

		switch ( $type ) {
			case 'divi/text':
				$paths['content.decoration.bodyFont.body.font'] = self::font( false );
				$paths['module.advanced.text.text']             = $orientation;
				break;
			case 'divi/heading':
				$paths['title.decoration.font.font'] = self::font( true );
				break;
			case 'divi/button':
				$paths['button.decoration.font.font']  = self::font( false );
				$paths['button.decoration.background'] = array( 'object' => array( 'color' => 'color' ) );
				$paths['module.advanced.alignment']    = array( 'enum' => array( 'left', 'center', 'right' ) );
				break;
			case 'divi/image':
				$paths['module.advanced.align'] = array( 'enum' => array( 'left', 'center', 'right' ) );
				break;
			case 'divi/divider':
				$paths['divider.advanced.line'] = array(
					'object' => array(
						'show'     => 'onoff',
						'color'    => 'color',
						'style'    => array( 'enum' => array( 'solid', 'dashed', 'dotted', 'double' ) ),
						'weight'   => 'size',
						'position' => array( 'enum' => array( 'top', 'center', 'bottom' ) ),
					),
				);
				break;
			case 'divi/blurb':
				$paths['title.decoration.font.font']            = self::font( true );
				$paths['content.decoration.bodyFont.body.font'] = self::font( false );
				$paths['imageIcon.advanced.color']              = 'color';
				$paths['module.advanced.text.text']             = $orientation;
				break;
			case 'divi/icon':
				$paths['icon.advanced.color'] = 'color';
				$paths['icon.advanced.size']  = 'size';
				$paths['icon.advanced.align'] = array( 'enum' => array( 'left', 'center', 'right' ) );
				break;
			case 'divi/video':
				$paths['overlay.decoration.background'] = array( 'object' => array( 'color' => 'color' ) );
				break;
			case 'divi/icon-list':
				$paths['icon.advanced.color'] = 'color';
				$paths['icon.advanced.size']  = 'size';
				break;
			default:
				break;
		}

		return $paths;
	}

	/**
	 * Forma del glifo de ícono: `{unicode, type, weight}`.
	 *
	 * @return array{object: array<string, mixed>}
	 */
	private static function icon_glyph(): array {
		return array(
			'object' => array(
				'unicode' => 'icon_unicode',
				'type'    => array( 'enum' => array( 'divi', 'fa' ) ),
				'weight'  => array( 'enum' => array( '400', '900' ) ),
			),
		);
	}

	/**
	 * Caja de espaciado: lados y sincronía.
	 *
	 * @return array{object: array<string, mixed>}
	 */
	private static function box(): array {
		return array(
			'object' => array(
				'top'            => 'size_or_empty',
				'right'          => 'size_or_empty',
				'bottom'         => 'size_or_empty',
				'left'           => 'size_or_empty',
				'syncVertical'   => 'onoff',
				'syncHorizontal' => 'onoff',
			),
		);
	}

	/**
	 * Tipografía de escritorio.
	 *
	 * @param bool $level Si admite `headingLevel` (títulos).
	 *
	 * @return array{object: array<string, mixed>}
	 */
	private static function font( bool $level ): array {
		$font = array(
			'family'        => 'family',
			'weight'        => 'weight',
			'size'          => 'size',
			'color'         => 'color',
			'textAlign'     => array( 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
			'lineHeight'    => 'size',
			'letterSpacing' => 'size',
		);

		if ( $level ) {
			$font['headingLevel'] = array( 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) );
		}

		return array( 'object' => $font );
	}

	/**
	 * Color como lo guarda Divi: `#hex`, `rgb()/rgba()/hsl()/hsla()`, variable global (`gcid-…`, `var(--gcid-…)`) o `$variable({…})$`.
	 *
	 * @param string $value Valor.
	 */
	public static function is_color( string $value ): bool {
		return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\z/i', $value )
			|| 1 === preg_match( '/^(?:rgb|hsl)a?\(\s*[0-9.]+(?:deg)?%?\s*[, ]\s*[0-9.]+%?\s*[, ]\s*[0-9.]+%?(?:\s*[,\/]\s*[0-9.]+%?)?\s*\)\z/i', $value )
			|| 1 === preg_match( '/^gcid-[a-z0-9-]+\z/', $value )
			|| 1 === preg_match( '/^var\(--gcid-[a-z0-9-]+\)\z/', $value )
			|| 1 === preg_match( '/^\$variable\(\{"type":"color","value":\{"name":"gcid-[a-z0-9-]+","settings":\{\}\}\}\)\$\z/', $value );
	}

	/**
	 * Tamaño con unidad: `Npx|em|rem|%|vw|vh` (o `0`).
	 *
	 * @param string $value Valor.
	 */
	public static function is_size( string $value ): bool {
		return 1 === preg_match( '/^(?:0|-?(?:\d+|\d*\.\d+)(?:px|em|rem|%|vw|vh))\z/', $value );
	}

	/**
	 * Esquema de una URL tras quitar entidades HTML, espacios y caracteres de control (`java\tscript:`), o null si no tiene.
	 *
	 * @param string $value URL.
	 */
	public static function scheme_of( string $value ): ?string {
		$compact = self::compact( $value );

		return 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $compact, $m ) ? strtolower( $m[1] ) : null;
	}

	/**
	 * URL de enlace admitida: http, https, mailto, tel o ruta del sitio (`/…`, `#…`, `?…`).
	 *
	 * @param string $value URL.
	 */
	public static function is_url( string $value ): bool {
		$compact = self::compact( $value );

		if ( '' === $compact || strlen( $value ) > 2000 ) {
			return false;
		}

		$scheme = self::scheme_of( $value );

		if ( null !== $scheme ) {
			return in_array( $scheme, self::SCHEMES, true );
		}

		if ( 1 === preg_match( '#^[/\\\\]{2}#', $compact ) ) {
			return false;
		}

		return in_array( $compact[0], array( '/', '#', '?' ), true );
	}

	/**
	 * URL de medio admitida: http, https o ruta del sitio.
	 *
	 * @param string $value URL.
	 */
	public static function is_media_url( string $value ): bool {
		$scheme = self::scheme_of( $value );

		return self::is_url( $value ) && ( null === $scheme || in_array( $scheme, array( 'http', 'https' ), true ) );
	}

	/**
	 * Valor sin entidades HTML, espacios ni caracteres de control.
	 *
	 * @param string $value Valor.
	 */
	private static function compact( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (string) preg_replace( '/[\x00-\x20\x7F]+/', '', $decoded );
	}
}
