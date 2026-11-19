<?php
/**
 * Migración de un árbol de Elementor 3 a elementos atómicos de Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Convierte un árbol v3 (la lista de nodos de `_elementor_data`) en un árbol mixto
 * donde cada elemento con equivalente pasa a ser atómico y el resto se conserva.
 *
 * Clase pura: no toca WordPress ni Elementor (sólo `AttributeValue::link()` para
 * decidir si un enlace es admisible, como hace el validador). Los estilos los
 * traduce un {@see V3StyleMapper} inyectado.
 *
 * Reglas (S7/S8 de `odd/tasks/migrar-v3-v4.md`):
 * - `section` → `e-flexbox` en fila (`html_tag` → `tag` si está en el enum); `column` → `e-div-block`
 *   con `width` en % (`_inline_size` o `_column_size`); `container` flex → `e-flexbox` con
 *   `flex-direction`, `justify-content`, `align-items`, `flex-wrap`, `gap`; `container` grid se
 *   conserva v3 y sus hijos se migran.
 * - heading, text-editor (un `e-paragraph` por párrafo, sólo con `p`, `a`, `span` y texto), button,
 *   image y divider se convierten; cualquier otro widget, o uno con algo que el atómico no admite,
 *   se conserva idéntico (mismo id y ajustes) y va a `kept_v3` con el motivo.
 * - Los nodos ya atómicos no cambian (sí se recorren sus hijos, por si cuelgan widgets v3).
 *
 * Forma de salida (formato guardado, no el del cliente de `ElementorTreeValidator`: `elType`/`widgetType`
 * en lugar de `type`): `array{tree, migrated, kept_v3: [{id, type, reason}], unmapped: [{id, keys}]}`.
 * `migrated` cuenta los nodos v3 convertidos (un text-editor con varios párrafos cuenta una vez);
 * `unmapped[].id` es el id del nodo atómico nuevo (el primer párrafo, si hay varios).
 *
 * Ids: los nodos convertidos reciben ids nuevos con el mismo generador que `ElementorTreeBuilder`
 * (7 dígitos hexadecimales; inyectable para pruebas), sin repetir ninguno del árbol de entrada. El
 * estilo local se llama `e-<id del nodo>-<id nuevo>`, como lo renombra el armador. La salida ya trae
 * ids definitivos: no hay que pasarla por `ElementorTreeBuilder` (que espera el formato del cliente).
 *
 * Estilo local: un estilo `{id, type: class, label: local, variants}` por nodo con variantes, referenciado
 * desde `settings.classes`. Las variantes son las del mapper más las estructurales del migrador (ancho de
 * columna, dirección flex, línea del divisor), que prevalecen sobre las del mapper en la misma
 * pantalla. Al mapper se le pasan los ajustes sin las claves que el migrador traslada él mismo.
 */
final class ElementorV3Migrator {

	private const LABEL = 'local';

	/**
	 * Etiquetas de contenedor admitidas por `tag` de `e-flexbox`.
	 *
	 * @var list<string>
	 */
	private const BOX_TAGS = array( 'div', 'header', 'section', 'article', 'aside', 'footer', 'main', 'nav' );

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
	 * Unidades de tamaño de diseño admitidas en `gap`, `width` y `height`.
	 *
	 * @var list<string>
	 */
	private const UNITS = array( 'px', '%', 'em', 'rem', 'vw', 'vh' );

	/**
	 * Valores de `justify-content` y `align-items` que acepta Elementor.
	 *
	 * @var array<string, list<string>>
	 */
	private const FLEX_ENUM = array(
		'justify-content' => array( 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ),
		'align-items'     => array( 'flex-start', 'center', 'flex-end', 'stretch' ),
		'flex-direction'  => array( 'row', 'column', 'row-reverse', 'column-reverse' ),
		'flex-wrap'       => array( 'wrap', 'nowrap', 'wrap-reverse' ),
	);

	/**
	 * Traductor de estilos.
	 *
	 * @var V3StyleMapper
	 */
	private V3StyleMapper $mapper;

	/**
	 * Generador de ids: devuelve una cadena hexadecimal.
	 *
	 * @var callable
	 */
	private $generator;

	/**
	 * Ids usados o reservados en la migración en curso.
	 *
	 * @var array<string, true>
	 */
	private array $used = array();

	/**
	 * Nodos v3 convertidos.
	 *
	 * @var int
	 */
	private int $migrated = 0;

	/**
	 * Nodos v3 conservados.
	 *
	 * @var array<int, array{id: string, type: string, reason: string}>
	 */
	private array $kept = array();

	/**
	 * Ajustes no trasladados por nodo.
	 *
	 * @var array<int, array{id: string, keys: list<string>}>
	 */
	private array $unmapped = array();

	/**
	 * Construye el migrador.
	 *
	 * @param V3StyleMapper $mapper    Traductor de estilos.
	 * @param callable|null $generator fn(): string. Por omisión, 7 dígitos hexadecimales aleatorios como los del editor.
	 */
	public function __construct( V3StyleMapper $mapper, ?callable $generator = null ) {
		$this->mapper    = $mapper;
		$this->generator = $generator ?? static fn(): string => substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Migra el árbol.
	 *
	 * @param array<int, array<string, mixed>> $tree Nodos como se guardan en `_elementor_data`.
	 *
	 * @return array{tree: array<int, array<string, mixed>>, migrated: int, kept_v3: array<int, array{id: string, type: string, reason: string}>, unmapped: array<int, array{id: string, keys: list<string>}>}
	 */
	public function migrate( array $tree ): array {
		$this->used     = array();
		$this->migrated = 0;
		$this->kept     = array();
		$this->unmapped = array();

		$this->reserve( $tree );

		$out = $this->convert_list( $tree );

		return array(
			'tree'     => $out,
			'migrated' => $this->migrated,
			'kept_v3'  => $this->kept,
			'unmapped' => $this->unmapped,
		);
	}

	/**
	 * Reserva los ids del árbol de entrada.
	 *
	 * @param array<int|string, mixed> $nodes Nodos.
	 */
	private function reserve( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
				$this->used[ (string) $node['id'] ] = true;
			}

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->reserve( $node['elements'] );
			}
		}
	}

	/**
	 * Convierte una lista de hermanos (un nodo puede dar varios).
	 *
	 * @param array<int|string, mixed> $nodes Nodos.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function convert_list( array $nodes ): array {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			foreach ( $this->convert_node( $node ) as $converted ) {
				$out[] = $converted;
			}
		}

		return $out;
	}

	/**
	 * Convierte un nodo.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function convert_node( array $node ): array {
		$el_type  = (string) ( $node['elType'] ?? '' );
		$children = is_array( $node['elements'] ?? null ) ? $node['elements'] : array();

		switch ( $el_type ) {
			case 'section':
				return array( $this->structure( 'e-flexbox', 'section', $node, $children ) );

			case 'column':
				return array( $this->structure( 'e-div-block', 'column', $node, $children ) );

			case 'container':
				if ( 'grid' === ( $this->settings_of( $node )['container_type'] ?? '' ) ) {
					$this->keep( $node, 'container', 'El contenedor grid no tiene equivalente atómico; se conserva y sus hijos se migran.' );
					$node['elements'] = $this->convert_list( $children );

					return array( $node );
				}

				return array( $this->structure( 'e-flexbox', 'container', $node, $children ) );

			case 'widget':
				$widget = (string) ( $node['widgetType'] ?? '' );

				if ( str_starts_with( $widget, 'e-' ) ) {
					return array( $node );
				}

				return $this->widget( $node, $widget );

			default:
				// Atómico (e-flexbox, e-div-block) o desconocido: igual, con los hijos recorridos.
				if ( array() !== $children ) {
					$node['elements'] = $this->convert_list( $children );
				}

				return array( $node );
		}
	}

	/**
	 * Ajustes del nodo (siempre un arreglo).
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_of( array $node ): array {
		return is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
	}

	/**
	 * Convierte un contenedor v3 (section, column o container flex).
	 *
	 * @param string               $type     Tipo atómico.
	 * @param string               $v3_type  Tipo v3 que se pasa al mapper.
	 * @param array<string, mixed> $node     Nodo v3.
	 * @param array<int, mixed>    $children Hijos v3.
	 *
	 * @return array<string, mixed>
	 */
	private function structure( string $type, string $v3_type, array $node, array $children ): array {
		$settings = $this->settings_of( $node );
		$id       = $this->next_id();
		$props    = array();
		$extra    = array();
		$consumed = array();
		$out_set  = array();

		if ( 'column' === $v3_type ) {
			$consumed = array( '_inline_size', '_inline_size_tablet', '_inline_size_mobile', '_column_size' );

			foreach ( self::DEVICES as $suffix => $device ) {
				$raw   = $settings[ '_inline_size' . $suffix ] ?? null;
				$raw   = ( null === $raw || '' === $raw ) && '' === $suffix ? ( $settings['_column_size'] ?? null ) : $raw;
				$width = $this->percent( $raw );

				if ( null !== $width ) {
					$props[ $device ]['width'] = $this->size( $width, '%' );
				}
			}
		} else {
			$consumed = array( 'html_tag' );
			$tag      = $settings['html_tag'] ?? '';

			if ( is_string( $tag ) && in_array( $tag, self::BOX_TAGS, true ) ) {
				$out_set['tag'] = $this->string( $tag );
			}

			if ( 'section' === $v3_type ) {
				$props['desktop']['flex-direction'] = $this->string( 'row' );
			} else {
				$consumed = array_merge( $consumed, array( 'container_type', 'flex_direction', 'flex_justify_content', 'flex_align_items', 'flex_wrap', 'flex_gap' ) );
				$this->flex_props( $settings, $props, $extra );
			}
		}

		$passed = array_diff_key( $settings, array_flip( $consumed ) );
		$node_  = $this->attach_style( $v3_type, $id, $passed, $props, $out_set, $extra );

		++$this->migrated;

		return array(
			'id'       => $id,
			'elType'   => $type,
			'settings' => $node_['settings'],
			'styles'   => $node_['styles'],
			'elements' => $this->convert_list( $children ),
		);
	}

	/**
	 * Propiedades de estilo flex de un contenedor v3.
	 *
	 * @param array<string, mixed>                $settings Ajustes v3.
	 * @param array<string, array<string, mixed>> $props    Props por breakpoint (se completa).
	 * @param array<int, string>                  $extra    Claves no trasladadas (se completa).
	 */
	private function flex_props( array $settings, array &$props, array &$extra ): void {
		$map = array(
			'flex_direction'       => 'flex-direction',
			'flex_justify_content' => 'justify-content',
			'flex_align_items'     => 'align-items',
			'flex_wrap'            => 'flex-wrap',
		);

		// El contenedor v3 es una columna por omisión; el atómico se fija explícito.
		$props['desktop']['flex-direction'] = $this->string( 'column' );

		foreach ( $map as $key => $prop ) {
			foreach ( self::DEVICES as $suffix => $device ) {
				$value = $settings[ $key . $suffix ] ?? '';

				if ( '' === $value ) {
					continue;
				}

				if ( is_string( $value ) && in_array( $value, self::FLEX_ENUM[ $prop ], true ) ) {
					$props[ $device ][ $prop ] = $this->string( $value );
				} else {
					$extra[] = $key . $suffix;
				}
			}
		}

		foreach ( self::DEVICES as $suffix => $device ) {
			$gap = $settings[ 'flex_gap' . $suffix ] ?? null;

			if ( ! is_array( $gap ) ) {
				continue;
			}

			$unit   = (string) ( $gap['unit'] ?? 'px' );
			$column = $this->number( $gap['column'] ?? null );
			$row    = $this->number( $gap['row'] ?? null );

			$column = $column ?? $row;
			$row    = $row ?? $column;

			if ( null === $column || null === $row ) {
				continue;
			}

			if ( ! in_array( $unit, self::UNITS, true ) ) {
				$extra[] = 'flex_gap' . $suffix;
				continue;
			}

			$props[ $device ]['gap'] = array(
				'$$type' => 'layout-direction',
				'value'  => array(
					'column' => $this->size( $column, $unit ),
					'row'    => $this->size( $row, $unit ),
				),
			);
		}
	}

	/**
	 * Convierte (o conserva) un widget v3.
	 *
	 * @param array<string, mixed> $node   Nodo.
	 * @param string               $widget Tipo de widget.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function widget( array $node, string $widget ): array {
		switch ( $widget ) {
			case 'heading':
				return $this->heading( $node );

			case 'text-editor':
				return $this->text_editor( $node );

			case 'button':
				return $this->button( $node );

			case 'image':
				return $this->image( $node );

			case 'divider':
				return $this->divider( $node );

			default:
				return $this->keep(
					$node,
					$widget,
					sprintf( 'El widget «%s» no tiene equivalente atómico.', $widget )
				);
		}
	}

	/**
	 * Heading → e-heading.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function heading( array $node ): array {
		$s    = $this->settings_of( $node );
		$size = (string) ( '' === ( $s['header_size'] ?? '' ) ? 'h2' : $s['header_size'] );
		$text = (string) ( $s['title'] ?? '' );

		if ( ! in_array( $size, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span' ), true ) ) {
			return $this->keep( $node, 'heading', sprintf( 'El encabezado usa la etiqueta «%s»; e-heading sólo admite h1-h6 y span.', $size ) );
		}

		if ( ! $this->has_only_tags( $text, array( 'a', 'span' ) ) ) {
			return $this->keep( $node, 'heading', 'El texto tiene formato (etiquetas distintas de a y span) que el elemento atómico no conserva.' );
		}

		list( $link, $error, $extra ) = $this->link_from( $s['link'] ?? null );

		if ( null !== $error ) {
			return $this->keep( $node, 'heading', $error );
		}

		$settings = array(
			'title' => $this->prop( 'escaped-html', $text ),
			'tag'   => $this->string( $size ),
		);

		if ( null !== $link ) {
			$settings['link'] = $link;
		}

		return array( $this->atomic_widget( 'e-heading', 'heading', $s, array( 'title', 'header_size', 'link' ), $settings, array(), $extra ) );
	}

	/**
	 * Button → e-button.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function button( array $node ): array {
		$s    = $this->settings_of( $node );
		$icon = $s['selected_icon'] ?? null;
		$icon = is_array( $icon ) ? ( $icon['value'] ?? '' ) : $icon;
		$text = (string) ( $s['text'] ?? '' );

		if ( ! empty( $icon ) ) {
			return $this->keep( $node, 'button', 'El botón tiene icono; e-button no lo admite.' );
		}

		if ( ! $this->has_only_tags( $text, array( 'a', 'span' ) ) ) {
			return $this->keep( $node, 'button', 'El texto tiene formato (etiquetas distintas de a y span) que el elemento atómico no conserva.' );
		}

		list( $link, $error, $extra ) = $this->link_from( $s['link'] ?? null );

		if ( null !== $error ) {
			return $this->keep( $node, 'button', $error );
		}

		$settings = array( 'text' => $this->prop( 'escaped-html', $text ) );

		if ( null !== $link ) {
			$settings['link'] = $link;
		}

		return array( $this->atomic_widget( 'e-button', 'button', $s, array( 'text', 'link', 'selected_icon' ), $settings, array(), $extra ) );
	}

	/**
	 * Image → e-image.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function image( array $node ): array {
		$s       = $this->settings_of( $node );
		$image   = is_array( $s['image'] ?? null ) ? $s['image'] : array();
		$id      = (int) ( $image['id'] ?? 0 );
		$caption = (string) ( $s['caption_source'] ?? '' );
		$extra   = array();

		if ( '' !== $caption && 'none' !== $caption ) {
			return $this->keep( $node, 'image', 'La imagen tiene pie de foto; e-image no lo admite.' );
		}

		if ( $id < 1 ) {
			return $this->keep( $node, 'image', 'La imagen no viene de la biblioteca de medios (sin identificador); e-image lo exige.' );
		}

		$link_to = (string) ( $s['link_to'] ?? '' );
		$link    = null;

		if ( 'custom' === $link_to ) {
			list( $link, $error, $extra ) = $this->link_from( $s['link'] ?? null );
		} elseif ( 'file' === $link_to ) {
			list( $link, $error, $extra ) = $this->link_from( array( 'url' => (string) ( $image['url'] ?? '' ) ) );
		} else {
			$error = null;
		}

		if ( null !== $error ) {
			return $this->keep( $node, 'image', $error );
		}

		$size = (string) ( $s['image_size'] ?? '' );

		if ( '' === $size ) {
			$size = 'large';
		} elseif ( 'custom' === $size ) {
			$size    = 'full';
			$extra[] = 'image_size';
		}

		$settings = array(
			'image' => $this->prop(
				'image',
				array(
					'src'  => $this->prop(
						'image-src',
						array(
							'id'  => $this->prop( 'image-attachment-id', $id ),
							'url' => null,
						)
					),
					'size' => $this->string( $size ),
				)
			),
		);

		if ( null !== $link ) {
			$settings['link'] = $link;
		}

		return array( $this->atomic_widget( 'e-image', 'image', $s, array( 'image', 'image_size', 'link_to', 'link', 'caption_source', 'caption' ), $settings, array(), $extra ) );
	}

	/**
	 * Divider → e-divider con la línea en el estilo.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function divider( array $node ): array {
		$s     = $this->settings_of( $node );
		$look  = (string) ( $s['look'] ?? '' );
		$extra = array();

		if ( '' !== $look && 'line' !== $look ) {
			return $this->keep( $node, 'divider', 'El divisor lleva texto o icono; e-divider sólo admite la línea.' );
		}

		$props = array();
		$line  = array();
		$color = $s['color'] ?? '';

		if ( ! empty( $s['__globals__']['color'] ) ) {
			$extra[] = '__globals__.color';
		}

		$line['background'] = $this->prop(
			'background',
			array( 'color' => $this->prop( 'color', is_string( $color ) && '' !== $color ? $color : '#000' ) )
		);

		$weight = $this->slider( $s['weight'] ?? null, array( 'px', 'em', 'rem' ) );

		if ( null !== $weight ) {
			$line['height'] = $weight;
		}

		$width = $this->slider( $s['width'] ?? null, self::UNITS );

		if ( null !== $width ) {
			$line['width'] = $width;
		}

		foreach ( array( 'style', 'align' ) as $key ) {
			if ( ! empty( $s[ $key ] ) && ! ( 'style' === $key && 'solid' === $s[ $key ] ) ) {
				$extra[] = $key;
			}
		}

		$props['desktop'] = $line;

		return array( $this->atomic_widget( 'e-divider', 'divider', $s, array( 'look', 'weight', 'color', 'width', 'style', 'text', 'icon', 'align' ), array(), $props, $extra ) );
	}

	/**
	 * Text-editor → uno o varios e-paragraph.
	 *
	 * @param array<string, mixed> $node Nodo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function text_editor( array $node ): array {
		$s     = $this->settings_of( $node );
		$items = $this->paragraphs( (string) ( $s['editor'] ?? '' ) );

		if ( null === $items ) {
			return $this->keep( $node, 'text-editor', 'El texto tiene formato (negrita, listas, saltos de línea u otras etiquetas) que e-paragraph no conserva; sólo se migran párrafos, enlaces y spans.' );
		}

		if ( array() === $items ) {
			return $this->keep( $node, 'text-editor', 'El editor de texto está vacío.' );
		}

		$out = array();

		foreach ( $items as $index => $html ) {
			$settings = array(
				'paragraph' => $this->prop( 'escaped-html', $html ),
				'tag'       => $this->string( 'p' ),
			);
			// Los avisos de ajustes no trasladados se dan una sola vez, en el primer párrafo.
			$out[] = $this->atomic_widget( 'e-paragraph', 'text-editor', $s, array( 'editor' ), $settings, array(), array(), 0 === $index );
		}

		// Un text-editor cuenta como un solo nodo v3 convertido, aunque dé varios párrafos.
		$this->migrated -= count( $out ) - 1;

		return $out;
	}

	/**
	 * Párrafos de un HTML que sólo lleva p, a, span y texto; null si lleva otra cosa.
	 *
	 * @param string $html HTML del editor.
	 *
	 * @return list<string>|null
	 */
	private function paragraphs( string $html ): ?array {
		if ( ! $this->has_only_tags( $html, array( 'p', 'a', 'span' ) ) ) {
			return null;
		}

		// `p` y `span` con atributos (alineación, clases, estilos) perderían esa información.
		if ( 1 === preg_match( '/<(?:p|span)\s[^>]*>/i', $html ) ) {
			return null;
		}

		$out   = array();
		$parts = preg_split( '#(<p>.*?</p>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		foreach ( false === $parts ? array() : $parts as $part ) {
			if ( 1 === preg_match( '#^<p>(.*)</p>$#is', $part, $m ) ) {
				if ( false !== stripos( $m[1], '<p' ) ) {
					return null;
				}

				$text = trim( $m[1] );

				if ( '' !== $text ) {
					$out[] = $text;
				}

				continue;
			}

			if ( false !== stripos( $part, '<p' ) || false !== stripos( $part, '</p' ) ) {
				return null;
			}

			foreach ( (array) preg_split( '/\R\s*\R/', $part ) as $chunk ) {
				$chunk = trim( (string) $chunk );

				if ( '' === $chunk ) {
					continue;
				}

				if ( 1 === preg_match( '/\R/', $chunk ) ) {
					return null;
				}

				$out[] = $chunk;
			}
		}

		return $out;
	}

	/**
	 * Indica si un texto sólo lleva las etiquetas indicadas.
	 *
	 * @param string             $html    Texto.
	 * @param array<int, string> $allowed Etiquetas permitidas.
	 */
	private function has_only_tags( string $html, array $allowed ): bool {
		if ( 0 === preg_match_all( '/<\s*\/?\s*([a-z!?][a-z0-9-]*)?/i', $html, $matches ) ) {
			return true;
		}

		foreach ( $matches[1] as $name ) {
			if ( ! in_array( strtolower( $name ), $allowed, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Arma el nodo de un widget atómico nuevo con su estilo local.
	 *
	 * @param string                              $type        Tipo atómico.
	 * @param string                              $v3_type     Tipo v3 para el mapper.
	 * @param array<string, mixed>                $v3_settings Ajustes v3.
	 * @param array<int, string>                  $consumed    Claves v3 que traslada el migrador.
	 * @param array<string, mixed>                $settings    Ajustes atómicos.
	 * @param array<string, array<string, mixed>> $props       Props de estilo del migrador por breakpoint.
	 * @param array<int, string>                  $extra       Claves no trasladadas que detectó el migrador.
	 * @param bool                                $report      Si se informan las claves no trasladadas.
	 *
	 * @return array<string, mixed>
	 */
	private function atomic_widget( string $type, string $v3_type, array $v3_settings, array $consumed, array $settings, array $props, array $extra, bool $report = true ): array {
		$id     = $this->next_id();
		$passed = array_diff_key( $v3_settings, array_flip( $consumed ) );
		$built  = $this->attach_style( $v3_type, $id, $passed, $props, $settings, $report ? $extra : array(), $report );

		++$this->migrated;

		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $built['settings'],
			'styles'     => $built['styles'],
			'elements'   => array(),
		);
	}

	/**
	 * Pide el estilo al mapper, lo une al estructural y lo referencia desde `classes`.
	 *
	 * @param string                              $v3_type  Tipo v3.
	 * @param string                              $id       Id del nodo nuevo.
	 * @param array<string, mixed>                $passed   Ajustes v3 para el mapper.
	 * @param array<string, array<string, mixed>> $props    Props estructurales por breakpoint.
	 * @param array<string, mixed>                $settings Ajustes atómicos.
	 * @param array<int, string>                  $extra    Claves no trasladadas del migrador.
	 * @param bool                                $report   Si se registran las claves no trasladadas.
	 *
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	private function attach_style( string $v3_type, string $id, array $passed, array $props, array $settings, array $extra, bool $report = true ): array {
		$mapped   = $this->mapper->map( $v3_type, $passed );
		$variants = array();

		foreach ( array_values( $mapped['styles'] ) as $variant ) {
			$meta       = is_array( $variant['meta'] ?? null ) ? $variant['meta'] : array();
			$variants[] = array(
				'meta'  => array(
					'breakpoint' => $meta['breakpoint'] ?? 'desktop',
					'state'      => $meta['state'] ?? null,
				),
				'props' => is_array( $variant['props'] ?? null ) ? $variant['props'] : array(),
			);
		}

		foreach ( $props as $device => $own ) {
			$found = false;

			foreach ( $variants as $index => $variant ) {
				if ( $device === $variant['meta']['breakpoint'] && null === $variant['meta']['state'] ) {
					$variants[ $index ]['props'] = array_merge( $variant['props'], $own );
					$found                       = true;
					break;
				}
			}

			if ( ! $found && array() !== $own ) {
				$variants[] = array(
					'meta'  => array(
						'breakpoint' => $device,
						'state'      => null,
					),
					'props' => $own,
				);
			}
		}

		$variants = array_values(
			array_filter( $variants, static fn( array $variant ): bool => array() !== $variant['props'] )
		);
		$styles   = array();

		if ( array() !== $variants ) {
			$style_id = 'e-' . $id . '-' . $this->next_id();
			$styles   = array(
				$style_id => array(
					'id'       => $style_id,
					'type'     => 'class',
					'label'    => self::LABEL,
					'variants' => $variants,
				),
			);

			$settings['classes'] = $this->prop( 'classes', array( $style_id ) );
		}

		if ( $report ) {
			$keys = array_values( array_unique( array_merge( $extra, array_map( 'strval', $mapped['unmapped'] ) ) ) );

			if ( array() !== $keys ) {
				$this->unmapped[] = array(
					'id'   => $id,
					'keys' => $keys,
				);
			}
		}

		return array(
			'settings' => $settings,
			'styles'   => $styles,
		);
	}

	/**
	 * Registra un nodo v3 conservado y lo devuelve tal cual.
	 *
	 * @param array<string, mixed> $node   Nodo.
	 * @param string               $type   Tipo a informar.
	 * @param string               $reason Motivo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function keep( array $node, string $type, string $reason ): array {
		$this->kept[] = array(
			'id'     => (string) ( $node['id'] ?? '' ),
			'type'   => $type,
			'reason' => $reason,
		);

		return array( $node );
	}

	/**
	 * Enlace v3 → prop `link`.
	 *
	 * @param mixed $link Valor de `link` v3 (`url`, `is_external`, `nofollow`, `custom_attributes`).
	 *
	 * @return array{0: array<string, mixed>|null, 1: string|null, 2: list<string>} Prop, motivo de rechazo y claves no trasladadas.
	 */
	private function link_from( $link ): array {
		$url = is_array( $link ) ? trim( (string) ( $link['url'] ?? '' ) ) : '';

		if ( '' === $url ) {
			return array( null, null, array() );
		}

		$clean = AttributeValue::link( $url );

		if ( is_wp_error( $clean ) ) {
			return array( null, 'El enlace no es admisible en Elementor 4: ' . $clean->get_error_message(), array() );
		}

		$value = array( 'destination' => $this->prop( 'url', $clean ) );
		$extra = array();

		if ( 'on' === ( $link['is_external'] ?? '' ) ) {
			$value['isTargetBlank'] = $this->prop( 'boolean', true );
		}

		if ( 'on' === ( $link['nofollow'] ?? '' ) ) {
			$extra[] = 'link_nofollow';
		}

		if ( ! empty( $link['custom_attributes'] ) ) {
			$extra[] = 'link_custom_attributes';
		}

		return array( $this->prop( 'link', $value ), null, $extra );
	}

	/**
	 * Valor de un slider v3 (`{size, unit}`) → prop `size`.
	 *
	 * @param mixed              $value Valor.
	 * @param array<int, string> $units Unidades admitidas.
	 *
	 * @return array<string, mixed>|null
	 */
	private function slider( $value, array $units ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$size = $this->number( $value['size'] ?? null );
		$unit = (string) ( $value['unit'] ?? 'px' );

		return null !== $size && in_array( $unit, $units, true ) ? $this->size( $size, $unit ) : null;
	}

	/**
	 * Porcentaje de columna válido (0 < n <= 100) o null.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return int|float|null
	 */
	private function percent( $value ) {
		$number = $this->number( $value );

		return null !== $number && $number > 0 && $number <= 100 ? $number : null;
	}

	/**
	 * Número de un valor v3 (cadena numérica incluida) o null.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return int|float|null
	 */
	private function number( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = $value + 0;

		return $number;
	}

	/**
	 * Prop `size`.
	 *
	 * @param int|float $size Tamaño.
	 * @param string    $unit Unidad.
	 *
	 * @return array<string, mixed>
	 */
	private function size( $size, string $unit ): array {
		return $this->prop(
			'size',
			array(
				'size' => $size,
				'unit' => $unit,
			)
		);
	}

	/**
	 * Prop `string`.
	 *
	 * @param string $value Valor.
	 *
	 * @return array<string, mixed>
	 */
	private function string( string $value ): array {
		return $this->prop( 'string', $value );
	}

	/**
	 * Prop atómico `{"$$type", "value"}`.
	 *
	 * @param string $type  Tipo.
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
	 * Siguiente id sin repetir.
	 */
	private function next_id(): string {
		do {
			$id = (string) ( $this->generator )();
		} while ( isset( $this->used[ $id ] ) );

		$this->used[ $id ] = true;

		return $id;
	}
}
