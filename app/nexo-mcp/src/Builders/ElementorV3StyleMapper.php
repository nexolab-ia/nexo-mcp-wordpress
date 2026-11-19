<?php
/**
 * Traductor de estilos de Elementor 3 a estilos locales atómicos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Implementa {@see V3StyleMapper} (S5/S7 de `odd/tasks/migrar-v3-v4.md`).
 *
 * Traduce, con variantes `desktop` / `tablet` / `mobile` (sufijos `_tablet` / `_mobile`):
 * - tipografía: sólo con `typography_typography` = `custom` (familia, tamaño, peso, alto de línea,
 *   espaciado, transformación); sin `custom` los `typography_*` se ignoran (v3 tampoco los aplica);
 * - alineación (`align` → `text-align`) en heading, text-editor y button;
 * - color de texto (`title_color`, `text_color`, `button_text_color`, según el widget);
 * - `background_color` / `_background_color` sólo con fondo clásico (`*background_background` = `classic`);
 * - `margin` / `_margin`, `padding` / `_padding` y `text_padding` (botón) → `margin` / `padding` como
 *   `dimensions` (top/right/bottom/left → block-start/inline-end/block-end/inline-start);
 * - borde: `border_border` (estilo; sin estilo, ancho y color se ignoran), `border_width`, `border_color`
 *   y `border_radius` (que va aparte, como en v3); con prefijo `_` (widgets) o `image_` (imagen).
 *
 * `__globals__`: color global → variable atómica de color si hay una cuyo nombre coincide (la etiqueta
 * sincronizada `v4-<etiqueta>` que usa Elementor como id v3, o el título del color del Kit igual a la
 * etiqueta, sin distinguir mayúsculas), como `{"$$type":"global-color-variable","value":"e-gv-…"}`;
 * si no, el color literal del Kit. Tipografía global → los valores literales del Kit. Un global manda
 * sobre el valor local, como en v3; si no se puede resolver se usa el local y, si no hay, la clave
 * `__globals__.<control>` va a `unmapped`.
 *
 * Regla de `unmapped`: una clave es «de estilo» si, sin el sufijo de dispositivo (`_tablet`, `_mobile`,
 * `_laptop`, `_widescreen`, `_mobile_extra`, `_tablet_extra`), coincide con {@see self::STYLE_PATTERNS}
 * (tipografía, márgenes, alineación, colores, fondo, borde, sombras, posición, transformaciones,
 * animaciones, clases CSS, visibilidad, tamaño, etc.). Se lista en `unmapped` toda clave de estilo con
 * valor no vacío que no se tradujo (valor inválido o fuera de las unidades, tipo de fondo distinto del
 * clásico y sus extras, ajustes de otro dispositivo, hover, sombras…). No se listan: el contenido y lo
 * que no es estilo (texto, enlace, iconos, imagen, `_title`, `__dynamic__`), los valores vacíos ni lo que
 * v3 ignoraría (tipografía sin `custom`, ancho y color de borde sin estilo de borde, color de fondo sin
 * tipo de fondo). Un valor de texto que no pasa `ElementorTreeValidator::css_text_problem()` no se emite
 * y va a `unmapped`.
 */
final class ElementorV3StyleMapper implements V3StyleMapper {

	/**
	 * Sufijo de ajuste v3 → breakpoint atómico.
	 *
	 * @var array<string, string>
	 */
	private const DEVICES = array(
		''        => 'desktop',
		'_tablet' => 'tablet',
		'_mobile' => 'mobile',
	);

	/**
	 * Claves de estilo v3 (sin sufijo de dispositivo).
	 *
	 * @var list<string>
	 */
	private const STYLE_PATTERNS = array(
		'/^typography_/',
		'/_typography_/',
		'/^_?(margin|padding)$/',
		'/^text_padding$/',
		'/^align$/',
		'/(^|_)color$/',
		'/^_?(background|border)_/',
		'/_(background|border)_/',
		'/(box_shadow|text_shadow|text_stroke)/',
		'/^_?(position|offset_|z_index|element_|transform|css_filters|mask_|animation|motion_fx|css_classes|flex_|order|align_self|overflow|opacity)/',
		'/^(hover_animation|animation|size|button_type|drop_cap|opacity|text_decoration|blend_mode|shape_divider_|min_height|height|content_position|content_width|boxed_width|width|gap|custom_css|hide)(_|$)/',
	);

	/**
	 * Unidades de tamaño admitidas.
	 *
	 * @var list<string>
	 */
	private const UNITS = array( 'px', '%', 'em', 'rem', 'vw', 'vh' );

	/**
	 * Pesos de fuente admitidos.
	 *
	 * @var list<string>
	 */
	private const WEIGHTS = array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold', 'bolder', 'lighter' );

	/**
	 * Transformaciones de texto admitidas.
	 *
	 * @var list<string>
	 */
	private const TRANSFORMS = array( 'none', 'capitalize', 'uppercase', 'lowercase' );

	/**
	 * Estilos de borde v3 que existen en v4.
	 *
	 * @var list<string>
	 */
	private const BORDER_STYLES = array( 'solid', 'double', 'dotted', 'dashed', 'groove' );

	/**
	 * Alineación v3 → `text-align`.
	 *
	 * @var array<string, string>
	 */
	private const ALIGN = array(
		'left'    => 'start',
		'center'  => 'center',
		'right'   => 'end',
		'justify' => 'justify',
	);

	/**
	 * Widgets en los que `align` es alineación de texto, y su clave de color de texto.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_COLOR_KEY = array(
		'heading'     => 'title_color',
		'text-editor' => 'text_color',
		'button'      => 'button_text_color',
	);

	/**
	 * Lados de `dimensions` y de `border-width-v2`.
	 *
	 * @var array<string, string>
	 */
	private const SIDES = array(
		'top'    => 'block-start',
		'right'  => 'inline-end',
		'bottom' => 'block-end',
		'left'   => 'inline-start',
	);

	/**
	 * Esquinas de `border-radius-v2`.
	 *
	 * @var array<string, string>
	 */
	private const CORNERS = array(
		'top'    => 'start-start',
		'right'  => 'start-end',
		'bottom' => 'end-end',
		'left'   => 'end-start',
	);

	/**
	 * Campos del grupo de tipografía que se traducen, en el orden de salida.
	 *
	 * @var list<string>
	 */
	private const TYPOGRAPHY_FIELDS = array( 'font_family', 'font_size', 'font_weight', 'line_height', 'letter_spacing', 'text_transform' );

	/**
	 * Kit y variables.
	 *
	 * @var V3StyleSource
	 */
	private V3StyleSource $source;

	/**
	 * Ajustes de la llamada en curso.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	/**
	 * `__globals__` de la llamada en curso (sin vacíos).
	 *
	 * @var array<string, string>
	 */
	private array $globals = array();

	/**
	 * Props por breakpoint de la llamada en curso.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $props = array();

	/**
	 * Claves tratadas (traducidas o ignoradas a propósito); `__globals__.<clave>` para los globales.
	 *
	 * @var array<string, true>
	 */
	private array $handled = array();

	/**
	 * Claves no trasladadas que se detectan al traducir (valores del Kit).
	 *
	 * @var list<string>
	 */
	private array $extra = array();

	/**
	 * Construye el traductor.
	 *
	 * @param V3StyleSource $source Colores, tipografías y variables.
	 */
	public function __construct( V3StyleSource $source ) {
		$this->source = $source;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $widget_type Tipo v3.
	 * @param array<string, mixed> $settings    Ajustes v3.
	 */
	public function map( string $widget_type, array $settings ): array {
		$this->settings = $settings;
		$this->props    = array();
		$this->handled  = array();
		$this->extra    = array();
		$this->globals  = array();

		if ( is_array( $settings['__globals__'] ?? null ) ) {
			foreach ( $settings['__globals__'] as $key => $ref ) {
				if ( is_string( $ref ) && '' !== $ref ) {
					$this->globals[ (string) $key ] = $ref;
				}
			}
		}

		$this->typography();
		$this->alignment( $widget_type );
		$this->text_color( $widget_type );
		$this->background();
		$this->spacing();
		$this->border();

		$styles = array();

		foreach ( self::DEVICES as $device ) {
			if ( ! empty( $this->props[ $device ] ) ) {
				$styles[] = array(
					'meta'  => array(
						'breakpoint' => $device,
						'state'      => null,
					),
					'props' => $this->props[ $device ],
				);
			}
		}

		return array(
			'styles'   => $styles,
			'unmapped' => $this->unmapped(),
		);
	}

	/**
	 * Claves de estilo con valor que no se tradujeron.
	 *
	 * @return list<string>
	 */
	private function unmapped(): array {
		$out = array();

		foreach ( $this->settings as $key => $value ) {
			$key = (string) $key;

			if ( '__globals__' === $key || '__dynamic__' === $key || isset( $this->handled[ $key ] ) ) {
				continue;
			}

			if ( $this->is_style_key( $key ) && ! $this->is_blank( $value ) ) {
				$out[] = $key;
			}
		}

		foreach ( array_keys( $this->globals ) as $key ) {
			if ( $this->is_style_key( $key ) && ! isset( $this->handled[ '__globals__.' . $key ] ) ) {
				$out[] = '__globals__.' . $key;
			}
		}

		return array_values( array_unique( array_merge( $out, $this->extra ) ) );
	}

	/**
	 * Indica si la clave es un ajuste de estilo (sin su sufijo de dispositivo).
	 *
	 * @param string $key Clave v3.
	 */
	private function is_style_key( string $key ): bool {
		$base = (string) preg_replace( '/_(?:tablet_extra|mobile_extra|laptop|widescreen|tablet|mobile)$/', '', $key );

		foreach ( self::STYLE_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $base ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Indica si un valor v3 está sin rellenar (cadena vacía, nulo, lista vacía, lados o tamaño vacíos).
	 *
	 * @param mixed $value Valor.
	 */
	private function is_blank( $value ): bool {
		if ( null === $value || '' === $value || array() === $value ) {
			return true;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( array_key_exists( 'top', $value ) || array_key_exists( 'left', $value ) ) {
			foreach ( array_keys( self::SIDES ) as $side ) {
				if ( ! $this->is_blank( $value[ $side ] ?? null ) ) {
					return false;
				}
			}

			return true;
		}

		return array_key_exists( 'size', $value ) && $this->is_blank( $value['size'] );
	}

	/**
	 * Valor local de una clave, o null si falta o está vacío.
	 *
	 * @param string $key Clave.
	 *
	 * @return mixed
	 */
	private function value( string $key ) {
		$value = $this->settings[ $key ] ?? null;

		return $this->is_blank( $value ) ? null : $value;
	}

	/**
	 * Marca una clave como tratada.
	 *
	 * @param string $key Clave (o `__globals__.<clave>`).
	 */
	private function mark( string $key ): void {
		$this->handled[ $key ] = true;
	}

	/**
	 * Fija una prop en un breakpoint; false si ya había una (la clave queda sin tratar).
	 *
	 * @param string               $device Breakpoint.
	 * @param string               $prop   Propiedad de estilo.
	 * @param array<string, mixed> $value  Valor `{$$type, value}`.
	 */
	private function set( string $device, string $prop, array $value ): bool {
		if ( isset( $this->props[ $device ][ $prop ] ) ) {
			return false;
		}

		$this->props[ $device ][ $prop ] = $value;

		return true;
	}

	/**
	 * Prop atómico.
	 *
	 * @param string $type  `$$type`.
	 * @param mixed  $value Valor.
	 *
	 * @return array<string, mixed>
	 */
	private function prop( string $type, $value ): array {
		return array(
			'$$type' => $type,
			'value'  => $value,
		);
	}

	/**
	 * Texto de valor CSS admisible, o null.
	 *
	 * @param mixed $raw Valor.
	 */
	private function css_text( $raw ): ?string {
		if ( ! is_string( $raw ) || '' === trim( $raw ) || null !== ElementorTreeValidator::css_text_problem( $raw ) ) {
			return null;
		}

		return trim( $raw );
	}

	/**
	 * Slider v3 `{size, unit}` → prop `size`.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return array<string, mixed>|false|null Prop; false si no vale; null si está vacío.
	 */
	private function slider( $value ) {
		if ( $this->is_blank( $value ) ) {
			return null;
		}

		if ( ! is_array( $value ) || ! is_numeric( $value['size'] ?? null ) ) {
			return false;
		}

		$unit = $value['unit'] ?? 'px';

		if ( ! is_string( $unit ) || ! in_array( $unit, self::UNITS, true ) ) {
			return false;
		}

		return $this->prop(
			'size',
			array(
				'size' => $value['size'] + 0,
				'unit' => $unit,
			)
		);
	}

	/**
	 * Dimensiones v3 → prop con lados lógicos.
	 *
	 * @param mixed                $value Valor `{top, right, bottom, left, unit}`.
	 * @param string               $type  `$$type` de salida.
	 * @param array<string,string> $names Lado v3 → nombre atómico.
	 *
	 * @return array<string, mixed>|false|null Prop; false si no vale; null si está vacío.
	 */
	private function dimensions( $value, string $type, array $names ) {
		if ( $this->is_blank( $value ) ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		$unit = $value['unit'] ?? 'px';

		if ( ! is_string( $unit ) || ! in_array( $unit, self::UNITS, true ) ) {
			return false;
		}

		$sides = array();

		foreach ( $names as $side => $name ) {
			$raw = $value[ $side ] ?? null;

			if ( $this->is_blank( $raw ) ) {
				continue;
			}

			if ( ! is_numeric( $raw ) ) {
				return false;
			}

			$sides[ $name ] = $this->prop(
				'size',
				array(
					'size' => $raw + 0,
					'unit' => $unit,
				)
			);
		}

		return $this->prop( $type, $sides );
	}

	/**
	 * Tipografía: grupo personalizado o global del Kit.
	 */
	private function typography(): void {
		$key    = 'typography_typography';
		$source = null;
		$global = false;
		$ref    = $this->globals[ $key ] ?? null;

		if ( null !== $ref ) {
			$id   = $this->global_id( $ref, 'typography' );
			$item = null !== $id ? $this->source->kit_typography( $id ) : null;

			if ( null !== $item ) {
				$source = $item;
				$global = true;
				$this->mark( '__globals__.' . $key );
			}
		}

		if ( null === $source && 'custom' === ( $this->settings[ $key ] ?? '' ) ) {
			$source = $this->settings;

			if ( null !== $ref ) {
				$this->mark( '__globals__.' . $key );
			}
		}

		// Sin tipografía activa (ni custom ni global resuelto), v3 no aplica los typography_* locales;
		// un global sin resolver queda en `unmapped`.
		if ( null === $source ) {
			$this->mark_local_typography();

			return;
		}

		$this->mark( $key );

		foreach ( self::DEVICES as $suffix => $device ) {
			foreach ( self::TYPOGRAPHY_FIELDS as $field ) {
				$name  = 'typography_' . $field . $suffix;
				$value = $this->typography_value( $field, $source[ $name ] ?? null );

				if ( null === $value ) {
					continue;
				}

				if ( false !== $value && $this->set( $device, str_replace( '_', '-', $field ), $value ) ) {
					$this->mark( $name );
				} elseif ( $global ) {
					$this->extra[] = '__globals__.' . $key;
				}
			}
		}

		if ( $global ) {
			$this->check_global_typography( $source );

			$this->mark_local_typography();
		}
	}

	/**
	 * Marca como tratados los `typography_*` locales.
	 */
	private function mark_local_typography(): void {
		foreach ( array_keys( $this->settings ) as $local ) {
			if ( str_starts_with( (string) $local, 'typography_' ) ) {
				$this->mark( (string) $local );
			}
		}
	}

	/**
	 * Avisa si la tipografía global del Kit trae ajustes que no se trasladan (cursiva, decoración…).
	 *
	 * @param array<string, mixed> $item Ajustes del Kit.
	 */
	private function check_global_typography( array $item ): void {
		foreach ( $item as $name => $value ) {
			$base = (string) preg_replace( '/_(?:tablet_extra|mobile_extra|laptop|widescreen|tablet|mobile)$/', '', (string) $name );

			if ( ! str_starts_with( $base, 'typography_' ) || 'typography_typography' === $base || $this->is_blank( $value ) ) {
				continue;
			}

			if ( ! in_array( substr( $base, 11 ), self::TYPOGRAPHY_FIELDS, true ) ) {
				$this->extra[] = '__globals__.typography_typography';
			}
		}
	}

	/**
	 * Valor de un campo de tipografía → prop.
	 *
	 * @param string $field Campo (`font_family`…).
	 * @param mixed  $raw   Valor v3.
	 *
	 * @return array<string, mixed>|false|null Prop; false si no vale; null si está vacío.
	 */
	private function typography_value( string $field, $raw ) {
		if ( $this->is_blank( $raw ) ) {
			return null;
		}

		switch ( $field ) {
			case 'font_family':
				$text = $this->css_text( $raw );

				return null === $text ? false : $this->prop( 'font-family', $text );

			case 'font_weight':
				return is_scalar( $raw ) && in_array( (string) $raw, self::WEIGHTS, true ) ? $this->prop( 'string', (string) $raw ) : false;

			case 'text_transform':
				return is_string( $raw ) && in_array( $raw, self::TRANSFORMS, true ) ? $this->prop( 'string', $raw ) : false;

			default:
				return $this->slider( $raw );
		}
	}

	/**
	 * Alineación → `text-align`.
	 *
	 * @param string $widget_type Tipo v3.
	 */
	private function alignment( string $widget_type ): void {
		if ( ! isset( self::TEXT_COLOR_KEY[ $widget_type ] ) ) {
			return;
		}

		foreach ( self::DEVICES as $suffix => $device ) {
			$raw = $this->value( 'align' . $suffix );

			if ( ! is_string( $raw ) || ! isset( self::ALIGN[ $raw ] ) || ( 'button' === $widget_type && 'justify' === $raw ) ) {
				continue;
			}

			if ( $this->set( $device, 'text-align', $this->prop( 'string', self::ALIGN[ $raw ] ) ) ) {
				$this->mark( 'align' . $suffix );
			}
		}
	}

	/**
	 * Color de texto del widget.
	 *
	 * @param string $widget_type Tipo v3.
	 */
	private function text_color( string $widget_type ): void {
		if ( isset( self::TEXT_COLOR_KEY[ $widget_type ] ) ) {
			$this->color_prop( self::TEXT_COLOR_KEY[ $widget_type ], 'color' );
		}
	}

	/**
	 * Traduce un control de color (con su global en el escritorio) a una prop de estilo.
	 *
	 * @param string $key  Clave v3 sin sufijo.
	 * @param string $prop Propiedad de estilo de salida.
	 */
	private function color_prop( string $key, string $prop ): void {
		foreach ( self::DEVICES as $suffix => $device ) {
			$color = $this->color_value( $key, $suffix );

			if ( null !== $color && $this->set( $device, $prop, $color ) ) {
				$this->mark( $key . $suffix );

				if ( '' === $suffix ) {
					$this->mark( '__globals__.' . $key );
				}
			}
		}
	}

	/**
	 * Valor de color de un control: global resuelto, si no el valor local.
	 *
	 * @param string $key    Clave v3 sin sufijo.
	 * @param string $suffix Sufijo de dispositivo.
	 *
	 * @return array<string, mixed>|null
	 */
	private function color_value( string $key, string $suffix ): ?array {
		if ( '' === $suffix && isset( $this->globals[ $key ] ) ) {
			$global = $this->global_color( $this->globals[ $key ] );

			if ( null !== $global ) {
				return $global;
			}
		}

		$text = $this->css_text( $this->value( $key . $suffix ) );

		return null === $text ? null : $this->prop( 'color', $text );
	}

	/**
	 * Id de un global (`globals/colors?id=…`) del tipo pedido.
	 *
	 * @param string $ref  Referencia.
	 * @param string $kind `colors` o `typography`.
	 */
	private function global_id( string $ref, string $kind ): ?string {
		if ( 1 !== preg_match( '~^globals/' . $kind . '\?id=(.+)$~', $ref, $m ) ) {
			return null;
		}

		return $m[1];
	}

	/**
	 * Color global → variable atómica con el mismo nombre, si no el color literal del Kit.
	 *
	 * @param string $ref Referencia `globals/colors?id=…`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function global_color( string $ref ): ?array {
		$id = $this->global_id( $ref, 'colors' );

		if ( null === $id ) {
			return null;
		}

		$kit = $this->source->kit_color( $id );

		foreach ( $this->source->color_variables() as $variable ) {
			$label = $variable['label'];

			if ( strtolower( $id ) === 'v4-' . strtolower( $label ) || ( null !== $kit && '' !== $label && 0 === strcasecmp( $kit['title'], $label ) ) ) {
				return $this->prop( DesignVariableValue::COLOR, $variable['id'] );
			}
		}

		$literal = null !== $kit ? $this->css_text( $kit['color'] ) : null;

		return null === $literal ? null : $this->prop( 'color', $literal );
	}

	/**
	 * Fondo clásico (color).
	 */
	private function background(): void {
		foreach ( array( '', '_' ) as $prefix ) {
			$type_key = $prefix . 'background_background';
			$type     = $this->settings[ $type_key ] ?? '';

			if ( ! is_string( $type ) || '' === $type ) {
				// Sin tipo de fondo v3 no pinta nada: color e imagen sueltos son restos.
				foreach ( array_keys( $this->settings ) as $local ) {
					$local = (string) $local;

					if ( str_starts_with( $local, $prefix . 'background_' ) && 1 !== preg_match( '/background_(hover|overlay)_/', $local ) ) {
						$this->mark( $local );
					}
				}

				continue;
			}

			if ( 'classic' !== $type ) {
				continue;
			}

			$this->mark( $type_key );

			foreach ( self::DEVICES as $suffix => $device ) {
				$color = $this->color_value( $prefix . 'background_color', $suffix );

				if ( null !== $color && $this->set( $device, 'background', $this->prop( 'background', array( 'color' => $color ) ) ) ) {
					$this->mark( $prefix . 'background_color' . $suffix );
					$this->mark( '__globals__.' . $prefix . 'background_color' );
				}
			}
		}
	}

	/**
	 * Márgenes y rellenos.
	 */
	private function spacing(): void {
		$this->dimension_keys( array( 'margin', '_margin' ), 'margin' );
		$this->dimension_keys( array( 'padding', '_padding', 'text_padding' ), 'padding' );
	}

	/**
	 * Traduce controles de dimensiones a una prop `dimensions`.
	 *
	 * @param array<int, string> $keys Claves v3 sin sufijo.
	 * @param string             $prop Propiedad de salida.
	 */
	private function dimension_keys( array $keys, string $prop ): void {
		foreach ( $keys as $key ) {
			foreach ( self::DEVICES as $suffix => $device ) {
				$value = $this->dimensions( $this->settings[ $key . $suffix ] ?? null, 'dimensions', self::SIDES );

				if ( is_array( $value ) && $this->set( $device, $prop, $value ) ) {
					$this->mark( $key . $suffix );
				}
			}
		}
	}

	/**
	 * Borde: estilo, ancho, color y radio.
	 */
	private function border(): void {
		foreach ( array( '', '_', 'image_' ) as $prefix ) {
			$style_key = $prefix . 'border_border';
			$style     = $this->settings[ $style_key ] ?? '';

			if ( ! is_string( $style ) || '' === $style || 'none' === $style ) {
				// Sin estilo de borde v3 no dibuja ancho ni color.
				foreach ( array( 'border_border', 'border_width', 'border_color' ) as $name ) {
					foreach ( self::DEVICES as $suffix => $unused ) {
						$this->mark( $prefix . $name . $suffix );
					}
				}
			} elseif ( in_array( $style, self::BORDER_STYLES, true ) ) {
				$this->mark( $style_key );
				$this->set( 'desktop', 'border-style', $this->prop( 'string', $style ) );

				foreach ( self::DEVICES as $suffix => $device ) {
					$width = $this->dimensions( $this->settings[ $prefix . 'border_width' . $suffix ] ?? null, 'border-width-v2', self::SIDES );

					if ( is_array( $width ) && $this->set( $device, 'border-width', $width ) ) {
						$this->mark( $prefix . 'border_width' . $suffix );
					}
				}

				$this->color_prop( $prefix . 'border_color', 'border-color' );
			}

			foreach ( self::DEVICES as $suffix => $device ) {
				$radius = $this->dimensions( $this->settings[ $prefix . 'border_radius' . $suffix ] ?? null, 'border-radius-v2', self::CORNERS );

				if ( is_array( $radius ) && $this->set( $device, 'border-radius', $radius ) ) {
					$this->mark( $prefix . 'border_radius' . $suffix );
				}
			}
		}
	}
}
