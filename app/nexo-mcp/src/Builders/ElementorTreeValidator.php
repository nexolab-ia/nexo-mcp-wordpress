<?php
/**
 * Validación previa del árbol atómico que el cliente quiere crear.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide si un árbol atómico de Elementor 4 se puede guardar.
 *
 * Es una clase pura: no toca Elementor ni la base de datos. Las claves de
 * `settings` que admite cada tipo, las clases globales que existen y la
 * comprobación de adjuntos llegan como datos, de modo que se prueba sin
 * Elementor. La validación de Elementor (`Props_Parser`, `Style_Parser`) corre
 * después y no reemplaza a ésta: aquí se cierra lo que Elementor acepta pero el
 * conector no debe crear (código, URL externas, enlaces ejecutables).
 *
 * Todo error lleva el código de la regla, la ruta del nodo en el mensaje
 * (`elements[0].elements[1].settings.title: …`) y la ruta en `error_data`.
 */
final class ElementorTreeValidator {

	/**
	 * Contenedores: los únicos que admiten hijos.
	 *
	 * @var array<int, string>
	 */
	public const CONTAINERS = array( 'e-flexbox', 'e-div-block' );

	/**
	 * Widgets atómicos admitidos.
	 *
	 * @var array<int, string>
	 */
	public const WIDGETS = array( 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-divider' );

	public const MAX_DEPTH = 10;
	public const MAX_NODES = 200;
	public const MAX_BYTES = 3145728;

	/**
	 * Formato de ids de estilo y nombres de clase (el mismo que exige Elementor).
	 */
	public const NAME_PATTERN = '/^[a-z][a-z-_0-9]*$/i';

	/**
	 * Claves admitidas en un estilo, en una variante y en su `meta`.
	 *
	 * Lo demás se rechaza: Elementor usa `cssName` (si viene) como selector CSS
	 * sin escapar, así que una clave de más es una puerta a CSS arbitrario.
	 *
	 * @var array<int, string>
	 */
	public const STYLE_KEYS   = array( 'id', 'type', 'label', 'variants' );
	public const VARIANT_KEYS = array( 'meta', 'props', 'custom_css' );
	public const META_KEYS    = array( 'breakpoint', 'state' );

	/**
	 * Etiquetas admitidas en `e-button`.
	 *
	 * @var array<int, string>
	 */
	private const BUTTON_TAGS = array( 'button', 'a', 'span', 'div' );

	/**
	 * Propiedades de `settings` que se rechazan aunque el esquema las tenga.
	 *
	 * @var array<int, string>
	 */
	private const DENIED_SETTINGS = array( 'attributes' );

	/**
	 * Lista cerrada de `$$type` admitidos, en ajustes y en estilos.
	 *
	 * `url` no está: sólo vale como destino de un enlace y se comprueba aparte.
	 * Quedan fuera `html*` y `svg-src` (marcado), `query*` y los datos dinámicos,
	 * `attributes` y `key-value` (atributos HTML) y los medios por URL.
	 *
	 * @var array<int, string>
	 */
	private const PROP_TYPES = array(
		'string',
		'number',
		'boolean',
		'size',
		'color',
		'dimensions',
		'box-shadow',
		'shadow',
		'border-radius-v2',
		'border-width-v2',
		'background',
		'background-overlay',
		'background-color-overlay',
		'background-gradient-overlay',
		'background-image-overlay',
		'background-image-position-offset',
		'background-image-size-scale',
		'gradient-color-stop',
		'color-stop',
		'stroke',
		'filter',
		'css-filter-func',
		'blur',
		'intensity',
		'color-tone',
		'hue-rotate',
		'drop-shadow',
		'backdrop-filter',
		'transform',
		'transform-functions',
		'transform-move',
		'transform-rotate',
		'transform-scale',
		'transform-skew',
		'transform-origin',
		'perspective-origin',
		'transition',
		'flex',
		'layout-direction',
		'font-family',
		'object-position',
		'selection-size',
		'number-range',
		'span',
		'grid-track-size',
		'global-color-variable',
		'global-font-variable',
		'global-size-variable',
		'escaped-html',
		'classes',
		'link',
		'image',
		'image-src',
		'image-attachment-id',
	);

	/**
	 * Claves de `settings` por tipo.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $allowed_settings;

	/**
	 * Ids de clases globales existentes.
	 *
	 * @var array<int, string>
	 */
	private array $global_classes;

	/**
	 * Comprobación de un adjunto: true o WP_Error.
	 *
	 * @var callable
	 */
	private $image_check;

	/**
	 * Propiedades de estilo que existen (claves de `Style_Schema::get()`).
	 *
	 * @var array<int, string>
	 */
	private array $style_props;

	/**
	 * Nodos vistos en la validación en curso.
	 *
	 * @var int
	 */
	private int $nodes = 0;

	/**
	 * Clase del padre de la lista que se valida (constantes de ElementorNesting).
	 *
	 * @var string
	 */
	private string $parent_kind = ElementorNesting::ROOT;

	/**
	 * Niveles que ya hay por encima de la lista que se valida.
	 *
	 * @var int
	 */
	private int $base_depth = 0;

	/**
	 * Construye el validador.
	 *
	 * @param array<string, array<int, string>> $allowed_settings Claves de `settings` por tipo.
	 * @param array<int, string>                $global_classes   Ids de clases globales existentes.
	 * @param callable|null                     $image_check      fn( int $id ): true|\WP_Error. Por omisión, AttributeValue::image().
	 * @param array<int, string>                $style_props      Propiedades de estilo que existen; sin lista no se admite ninguna.
	 */
	public function __construct( array $allowed_settings, array $global_classes = array(), ?callable $image_check = null, array $style_props = array() ) {
		$this->style_props      = $style_props;
		$this->allowed_settings = $allowed_settings;
		$this->global_classes   = $global_classes;
		$this->image_check      = $image_check ?? static fn( int $id ) => AttributeValue::image( $id );
	}

	/**
	 * Tipos admitidos, contenedores primero.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_types(): array {
		return array_merge( self::CONTAINERS, self::WIDGETS );
	}

	/**
	 * Valida el árbol completo.
	 *
	 * Por omisión la lista cuelga de la raíz de una página nueva, donde sólo caben
	 * contenedores atómicos. Al editar una sección de una página existente la lista
	 * cuelga de otro padre: `$parent_kind` fija qué tipos admite el primer nivel,
	 * `$base_depth` los niveles que ya hay encima y `$base_nodes` los elementos que
	 * el documento conserva, de modo que los topes valgan para el documento
	 * resultante y no sólo para el subárbol.
	 *
	 * @param mixed  $elements    Lista de nodos recibida del cliente.
	 * @param string $parent_kind Clase del padre (constantes de ElementorNesting).
	 * @param int    $base_depth  Niveles por encima de la lista (0 = raíz).
	 * @param int    $base_nodes  Elementos del documento que no forman parte de la lista.
	 *
	 * @return true|\WP_Error
	 */
	public function validate( $elements, string $parent_kind = ElementorNesting::ROOT, int $base_depth = 0, int $base_nodes = 0 ) {
		if ( ! is_array( $elements ) || array() === $elements || ! array_is_list( $elements ) ) {
			return $this->error(
				'nexo_mcp_elementor_tree_invalid',
				'elements',
				__( 'debe ser una lista no vacía de elementos.', 'nexo-mcp' )
			);
		}

		$encoded = wp_json_encode( $elements );

		if ( ! is_string( $encoded ) ) {
			return $this->error(
				'nexo_mcp_elementor_tree_invalid',
				'elements',
				__( 'contiene datos que no se pueden codificar como JSON.', 'nexo-mcp' )
			);
		}

		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			return $this->error(
				'nexo_mcp_elementor_too_large',
				'elements',
				__( 'el árbol pesa más de 3 MB.', 'nexo-mcp' )
			);
		}

		$this->nodes       = $base_nodes;
		$this->parent_kind = $parent_kind;
		$this->base_depth  = $base_depth;

		return $this->walk_list( $elements, 'elements', $base_depth + 1 );
	}

	/**
	 * Recorre una lista de nodos hermanos.
	 *
	 * @param array<int, mixed> $nodes Nodos.
	 * @param string            $path  Ruta de la lista.
	 * @param int               $depth Nivel de los nodos (1 = raíz).
	 *
	 * @return true|\WP_Error
	 */
	private function walk_list( array $nodes, string $path, int $depth ) {
		foreach ( $nodes as $index => $node ) {
			$result = $this->walk_node( $node, $path . '[' . $index . ']', $depth );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Valida un nodo y, si es contenedor, sus hijos.
	 *
	 * @param mixed  $node  Nodo.
	 * @param string $path  Ruta del nodo.
	 * @param int    $depth Nivel del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function walk_node( $node, string $path, int $depth ) {
		if ( ! is_array( $node ) || ( array() !== $node && array_is_list( $node ) ) ) {
			return $this->error(
				'nexo_mcp_elementor_tree_invalid',
				$path,
				__( 'cada elemento debe ser un objeto con «type».', 'nexo-mcp' )
			);
		}

		++$this->nodes;

		if ( $this->nodes > self::MAX_NODES ) {
			return $this->error(
				'nexo_mcp_elementor_too_many_nodes',
				$path,
				sprintf(
					/* translators: %d: máximo de elementos. */
					__( 'se supera el máximo de %d elementos.', 'nexo-mcp' ),
					self::MAX_NODES
				)
			);
		}

		if ( $depth > self::MAX_DEPTH ) {
			return $this->error(
				'nexo_mcp_elementor_too_deep',
				$path,
				sprintf(
					/* translators: %d: niveles máximos. */
					__( 'se supera la profundidad máxima de %d niveles.', 'nexo-mcp' ),
					self::MAX_DEPTH
				)
			);
		}

		$type = $node['type'] ?? null;

		if ( ! is_string( $type ) || '' === $type ) {
			return $this->error(
				'nexo_mcp_elementor_tree_invalid',
				$path . '.type',
				__( 'indica el tipo del elemento.', 'nexo-mcp' )
			);
		}

		if ( ! in_array( $type, self::allowed_types(), true ) ) {
			return $this->error( 'nexo_mcp_elementor_type_denied', $path . '.type', $this->denied_type_reason( $type ) );
		}

		if ( ! isset( $this->allowed_settings[ $type ] ) ) {
			return $this->error(
				'nexo_mcp_elementor_type_denied',
				$path . '.type',
				sprintf(
					/* translators: %s: tipo del elemento. */
					__( 'el tipo «%s» no está disponible en la versión de Elementor instalada.', 'nexo-mcp' ),
					$type
				)
			);
		}

		// Sólo el primer nivel de la lista cuelga del padre indicado; los hijos
		// del subárbol nuevo se rigen por su propio contenedor.
		if ( $depth === $this->base_depth + 1 ) {
			$nesting = ElementorNesting::reason( $this->parent_kind, $type );

			if ( null !== $nesting ) {
				return $this->error( 'nexo_mcp_elementor_nesting_denied', $path . '.type', $nesting );
			}
		}

		$children = $node['elements'] ?? null;

		if ( null !== $children && ! is_array( $children ) ) {
			return $this->error(
				'nexo_mcp_elementor_tree_invalid',
				$path . '.elements',
				__( 'debe ser una lista de elementos.', 'nexo-mcp' )
			);
		}

		if ( ! in_array( $type, self::CONTAINERS, true ) && array() !== (array) $children ) {
			return $this->error(
				'nexo_mcp_elementor_children_denied',
				$path . '.elements',
				sprintf(
					/* translators: 1: tipo del elemento, 2: contenedores admitidos. */
					__( '«%1$s» no admite elementos hijos; sólo los contenedores (%2$s).', 'nexo-mcp' ),
					$type,
					implode( ', ', self::CONTAINERS )
				)
			);
		}

		$locals = $this->local_style_ids( $node['styles'] ?? null );

		$result = $this->check_settings( $node['settings'] ?? null, $type, $path . '.settings', $locals );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->check_styles( $node['styles'] ?? null, $path . '.styles', $locals );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $children ) && array() !== $children ) {
			if ( ! array_is_list( $children ) ) {
				return $this->error(
					'nexo_mcp_elementor_tree_invalid',
					$path . '.elements',
					__( 'debe ser una lista de elementos.', 'nexo-mcp' )
				);
			}

			return $this->walk_list( $children, $path . '.elements', $depth + 1 );
		}

		return true;
	}

	/**
	 * Motivo por el que un tipo no se admite.
	 *
	 * @param string $type Tipo recibido.
	 */
	private function denied_type_reason( string $type ): string {
		$list = implode( ', ', self::allowed_types() );

		if ( 'e-svg' === $type ) {
			return sprintf(
				/* translators: %s: tipos admitidos. */
				__( 'el tipo «e-svg» no está permitido: un SVG puede llevar código ejecutable. Tipos admitidos: %s.', 'nexo-mcp' ),
				$list
			);
		}

		if ( EditableTypes::is_forbidden( $type ) ) {
			return sprintf(
				/* translators: 1: tipo, 2: tipos admitidos. */
				__( '«%1$s» admite código arbitrario y nunca se crea. Tipos admitidos: %2$s.', 'nexo-mcp' ),
				$type,
				$list
			);
		}

		if ( ! str_starts_with( $type, 'e-' ) ) {
			return sprintf(
				/* translators: 1: tipo, 2: tipos admitidos. */
				__( '«%1$s» es un elemento de Elementor 3; sólo se admiten elementos atómicos. Tipos admitidos: %2$s.', 'nexo-mcp' ),
				$type,
				$list
			);
		}

		return sprintf(
			/* translators: 1: tipo, 2: tipos admitidos. */
			__( 'el tipo «%1$s» no está permitido. Tipos admitidos: %2$s.', 'nexo-mcp' ),
			$type,
			$list
		);
	}

	/**
	 * Ids de los estilos locales de un nodo.
	 *
	 * @param mixed $styles Valor de `styles`.
	 *
	 * @return array<int, string>
	 */
	private function local_style_ids( $styles ): array {
		if ( ! is_array( $styles ) || array_is_list( $styles ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $styles ) );
	}

	/**
	 * Valida las propiedades de un nodo.
	 *
	 * @param mixed              $settings Valor de `settings`.
	 * @param string             $type     Tipo del nodo.
	 * @param string             $path     Ruta de `settings`.
	 * @param array<int, string> $locals   Estilos locales del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_settings( $settings, string $type, string $path, array $locals ) {
		if ( null === $settings || array() === $settings ) {
			return true;
		}

		if ( ! is_array( $settings ) || array_is_list( $settings ) ) {
			return $this->error(
				'nexo_mcp_elementor_setting_invalid',
				$path,
				__( 'debe ser un objeto de propiedades.', 'nexo-mcp' )
			);
		}

		$allowed = array_values( array_diff( $this->allowed_settings[ $type ] ?? array(), self::DENIED_SETTINGS ) );

		foreach ( $settings as $key => $prop ) {
			$key  = (string) $key;
			$here = $path . '.' . $key;

			if ( in_array( $key, self::DENIED_SETTINGS, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_setting_denied',
					$here,
					sprintf(
						/* translators: %s: propiedad rechazada. */
						__( '«%s» no se admite: permite atributos HTML arbitrarios, como los de eventos.', 'nexo-mcp' ),
						$key
					)
				);
			}

			if ( ! in_array( $key, $allowed, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_setting_unknown',
					$here,
					sprintf(
						/* translators: 1: propiedad, 2: tipo del elemento, 3: propiedades admitidas. */
						__( 'la propiedad «%1$s» no existe en «%2$s». Admitidas: %3$s.', 'nexo-mcp' ),
						$key,
						$type,
						implode( ', ', $allowed )
					)
				);
			}

			if ( ! is_array( $prop ) || ! isset( $prop['$$type'] ) || ! is_string( $prop['$$type'] ) || ! array_key_exists( 'value', $prop ) ) {
				return $this->error(
					'nexo_mcp_elementor_setting_invalid',
					$here,
					__( 'debe tener la forma {"$$type": «tipo», "value": …}.', 'nexo-mcp' )
				);
			}

			if ( 'e-button' === $type && 'tag' === $key && ( 'string' !== $prop['$$type'] || ! in_array( $prop['value'], self::BUTTON_TAGS, true ) ) ) {
				return $this->error(
					'nexo_mcp_elementor_setting_invalid',
					$here,
					sprintf(
						/* translators: %s: etiquetas admitidas. */
						__( 'la etiqueta de «e-button» debe ser una de: %s.', 'nexo-mcp' ),
						implode( ', ', self::BUTTON_TAGS )
					)
				);
			}

			$result = $this->walk_value( $prop, $here, $locals );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Recorre un valor buscando `$$type` y aplicando la lista cerrada.
	 *
	 * @param mixed              $value  Valor.
	 * @param string             $path   Ruta del valor.
	 * @param array<int, string> $locals Estilos locales del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function walk_value( $value, string $path, array $locals ) {
		if ( ! is_array( $value ) ) {
			return true;
		}

		if ( ! array_key_exists( '$$type', $value ) ) {
			foreach ( $value as $key => $child ) {
				$result = $this->walk_value( $child, $this->join( $path, $key ), $locals );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			return true;
		}

		$type = $value['$$type'];

		if ( ! is_string( $type ) || ! in_array( $type, self::PROP_TYPES, true ) ) {
			return $this->error(
				'nexo_mcp_elementor_prop_type_denied',
				$path . '.$$type',
				sprintf(
					/* translators: %s: tipo de propiedad. */
					__( 'el tipo de propiedad «%s» no está permitido.', 'nexo-mcp' ),
					is_string( $type ) ? $type : gettype( $type )
				)
			);
		}

		$inner = $value['value'] ?? null;

		switch ( $type ) {
			case 'classes':
				return $this->check_classes( $inner, $path . '.value', $locals );

			case 'link':
				return $this->check_link( $inner, $path . '.value', $locals );

			case 'image-src':
				return $this->check_image_src( $inner, $path . '.value', $locals );

			default:
				return $this->walk_value( $inner, $path . '.value', $locals );
		}
	}

	/**
	 * Une un nombre a una ruta.
	 *
	 * @param string     $path Ruta base.
	 * @param int|string $key  Clave o posición.
	 */
	private function join( string $path, $key ): string {
		return is_int( $key ) ? $path . '[' . $key . ']' : $path . '.' . $key;
	}

	/**
	 * Valida una lista de clases: formato y existencia.
	 *
	 * @param mixed              $names  Nombres recibidos.
	 * @param string             $path   Ruta de la lista.
	 * @param array<int, string> $locals Estilos locales del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_classes( $names, string $path, array $locals ) {
		if ( ! is_array( $names ) || ! array_is_list( $names ) ) {
			return $this->error(
				'nexo_mcp_elementor_class_invalid',
				$path,
				__( 'debe ser una lista de nombres de clase.', 'nexo-mcp' )
			);
		}

		foreach ( $names as $index => $name ) {
			$here = $path . '[' . $index . ']';

			if ( ! is_string( $name ) || 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
				return $this->error(
					'nexo_mcp_elementor_class_invalid',
					$here,
					sprintf(
						/* translators: 1: nombre recibido, 2: expresión regular. */
						__( 'el nombre de clase «%1$s» debe cumplir %2$s.', 'nexo-mcp' ),
						is_string( $name ) ? $name : gettype( $name ),
						self::NAME_PATTERN
					)
				);
			}

			if ( ! in_array( $name, $locals, true ) && ! in_array( $name, $this->global_classes, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_class_unknown',
					$here,
					sprintf(
						/* translators: %s: nombre de la clase. */
						__( 'la clase «%s» no existe: usa un estilo local de este mismo elemento o una clase global existente (get-design-system).', 'nexo-mcp' ),
						$name
					)
				);
			}
		}

		return true;
	}

	/**
	 * Valida un enlace: destino URL con los esquemas de AttributeValue.
	 *
	 * @param mixed              $link   Valor de la propiedad `link`.
	 * @param string             $path   Ruta del valor.
	 * @param array<int, string> $locals Estilos locales del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_link( $link, string $path, array $locals ) {
		if ( ! is_array( $link ) ) {
			return $this->error(
				'nexo_mcp_elementor_link_invalid',
				$path,
				__( 'el enlace debe ser un objeto con «destination».', 'nexo-mcp' )
			);
		}

		foreach ( $link as $key => $child ) {
			if ( 'destination' !== $key ) {
				$result = $this->walk_value( $child, $this->join( $path, $key ), $locals );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				continue;
			}

			$here = $path . '.destination';

			if ( is_array( $child ) && isset( $child['$$type'] ) && is_string( $child['$$type'] ) && 'url' !== $child['$$type'] && ! in_array( $child['$$type'], self::PROP_TYPES, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_prop_type_denied',
					$here . '.$$type',
					sprintf(
						/* translators: %s: tipo de propiedad. */
						__( 'el tipo de propiedad «%s» no está permitido.', 'nexo-mcp' ),
						$child['$$type']
					)
				);
			}

			if ( ! is_array( $child ) || 'url' !== ( $child['$$type'] ?? null ) || ! is_string( $child['value'] ?? null ) ) {
				return $this->error(
					'nexo_mcp_elementor_link_invalid',
					$here,
					__( 'el destino debe ser {"$$type": "url", "value": «https://…»}.', 'nexo-mcp' )
				);
			}

			$checked = AttributeValue::link( $child['value'] );

			if ( is_wp_error( $checked ) ) {
				return $this->error( $checked->get_error_code(), $here . '.value', $checked->get_error_message() );
			}
		}

		return true;
	}

	/**
	 * Valida el origen de una imagen: sólo un adjunto existente, nunca una URL.
	 *
	 * @param mixed              $src    Valor de `image-src`.
	 * @param string             $path   Ruta del valor.
	 * @param array<int, string> $locals Estilos locales del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_image_src( $src, string $path, array $locals ) {
		$src = is_array( $src ) ? $src : array();
		$url = $src['url'] ?? null;
		$url = is_array( $url ) ? ( $url['value'] ?? null ) : $url;

		if ( null !== $url && '' !== $url ) {
			return $this->error(
				'nexo_mcp_elementor_image_url_denied',
				$path . '.url',
				__( 'las imágenes se indican por el identificador de la biblioteca de medios (src.id), nunca por URL.', 'nexo-mcp' )
			);
		}

		$id = $src['id'] ?? null;

		if ( ! is_array( $id ) || 'image-attachment-id' !== ( $id['$$type'] ?? null ) || ! is_numeric( $id['value'] ?? null ) || (int) $id['value'] < 1 ) {
			return $this->error(
				'nexo_mcp_elementor_image_id_required',
				$path . '.id',
				__( 'indica el identificador de una imagen de la biblioteca de medios.', 'nexo-mcp' )
			);
		}

		$checked = ( $this->image_check )( (int) $id['value'] );

		if ( is_wp_error( $checked ) ) {
			return $this->error( $checked->get_error_code(), $path . '.id.value', $checked->get_error_message() );
		}

		foreach ( $src as $key => $child ) {
			if ( 'id' === $key || 'url' === $key ) {
				continue;
			}

			$result = $this->walk_value( $child, $this->join( $path, $key ), $locals );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Valida los estilos locales de un nodo.
	 *
	 * @param mixed              $styles Valor de `styles`.
	 * @param string             $path   Ruta de `styles`.
	 * @param array<int, string> $locals Ids de los estilos del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_styles( $styles, string $path, array $locals ) {
		if ( null === $styles || array() === $styles ) {
			return true;
		}

		if ( ! is_array( $styles ) || array_is_list( $styles ) ) {
			return $this->error(
				'nexo_mcp_elementor_style_invalid',
				$path,
				__( 'debe ser un objeto de estilos (id → estilo).', 'nexo-mcp' )
			);
		}

		foreach ( $styles as $key => $style ) {
			$key  = (string) $key;
			$here = $path . '.' . $key;

			if ( 1 !== preg_match( self::NAME_PATTERN, $key ) ) {
				return $this->error(
					'nexo_mcp_elementor_style_invalid',
					$here,
					sprintf(
						/* translators: %s: expresión regular. */
						__( 'el id de estilo debe cumplir %s.', 'nexo-mcp' ),
						self::NAME_PATTERN
					)
				);
			}

			$result = $this->check_style( $style, $key, $here, $locals );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Valida un estilo local.
	 *
	 * @param mixed              $style  Estilo.
	 * @param string             $key    Clave bajo la que viene.
	 * @param string             $path   Ruta del estilo.
	 * @param array<int, string> $locals Ids de los estilos del nodo.
	 *
	 * @return true|\WP_Error
	 */
	private function check_style( $style, string $key, string $path, array $locals ) {
		if ( ! is_array( $style ) ) {
			return $this->error( 'nexo_mcp_elementor_style_invalid', $path, __( 'debe ser un objeto.', 'nexo-mcp' ) );
		}

		$unknown = $this->unknown_key( $style, self::STYLE_KEYS, $path, __( 'un estilo', 'nexo-mcp' ) );

		if ( null !== $unknown ) {
			return $unknown;
		}

		if ( isset( $style['id'] ) && ( ! is_string( $style['id'] ) || $style['id'] !== $key ) ) {
			return $this->error(
				'nexo_mcp_elementor_style_invalid',
				$path . '.id',
				sprintf(
					/* translators: %s: clave del estilo. */
					__( 'debe coincidir con la clave del estilo («%s»).', 'nexo-mcp' ),
					$key
				)
			);
		}

		if ( 'class' !== ( $style['type'] ?? null ) ) {
			return $this->error( 'nexo_mcp_elementor_style_invalid', $path . '.type', __( 'debe ser «class».', 'nexo-mcp' ) );
		}

		if ( ! is_string( $style['label'] ?? null ) || '' === trim( $style['label'] ) ) {
			return $this->error(
				'nexo_mcp_elementor_style_invalid',
				$path . '.label',
				__( 'indica la etiqueta del estilo (texto).', 'nexo-mcp' )
			);
		}

		$variants = $style['variants'] ?? null;

		if ( ! is_array( $variants ) || ! array_is_list( $variants ) ) {
			return $this->error(
				'nexo_mcp_elementor_style_invalid',
				$path . '.variants',
				__( 'debe ser una lista de variantes.', 'nexo-mcp' )
			);
		}

		foreach ( $variants as $index => $variant ) {
			$here = $path . '.variants[' . $index . ']';

			if ( ! is_array( $variant ) ) {
				return $this->error( 'nexo_mcp_elementor_style_invalid', $here, __( 'debe ser un objeto.', 'nexo-mcp' ) );
			}

			$unknown = $this->unknown_key( $variant, self::VARIANT_KEYS, $here, __( 'una variante', 'nexo-mcp' ) );

			if ( null !== $unknown ) {
				return $unknown;
			}

			if ( isset( $variant['meta'] ) ) {
				$unknown = is_array( $variant['meta'] )
					? $this->unknown_key( $variant['meta'], self::META_KEYS, $here . '.meta', 'meta' )
					: $this->error( 'nexo_mcp_elementor_style_invalid', $here . '.meta', __( 'debe ser un objeto.', 'nexo-mcp' ) );

				if ( null !== $unknown ) {
					return $unknown;
				}
			}

			if ( ! empty( $variant['custom_css'] ) ) {
				return $this->error(
					'nexo_mcp_elementor_style_css_denied',
					$here . '.custom_css',
					__( 'el CSS personalizado no se admite.', 'nexo-mcp' )
				);
			}

			$result = $this->check_style_prop_keys( $variant['props'] ?? array(), $here . '.props' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = $this->walk_value( $variant['props'] ?? array(), $here . '.props', $locals );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = $this->check_css_leaves( $variant['props'] ?? array(), $here . '.props' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Rechaza las propiedades de estilo que Elementor no conoce.
	 *
	 * Elementor las descarta en silencio al guardar, y el cliente creería que
	 * aplicó un estilo que nunca existió. Las claves anidadas dentro de un prop de
	 * forma de objeto las comprueba la capa de Elementor, que conoce cada forma.
	 *
	 * @param mixed  $props Props de la variante.
	 * @param string $path  Ruta de `props`.
	 *
	 * @return true|\WP_Error
	 */
	private function check_style_prop_keys( $props, string $path ) {
		if ( ! is_array( $props ) ) {
			return true;
		}

		foreach ( array_keys( $props ) as $key ) {
			if ( ! in_array( (string) $key, $this->style_props, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_style_prop_unknown',
					$path . '.' . $key,
					sprintf(
						/* translators: %s: propiedad de estilo. */
						__( 'la propiedad de estilo «%s» no existe en el esquema de estilos de Elementor.', 'nexo-mcp' ),
						(string) $key
					)
				);
			}
		}

		return true;
	}

	/**
	 * Error por la primera clave que no está en la lista admitida.
	 *
	 * @param array<int|string, mixed> $value   Objeto revisado.
	 * @param array<int, string>       $allowed Claves admitidas.
	 * @param string                   $path    Ruta del objeto.
	 * @param string                   $what    Cómo se llama el objeto en el mensaje.
	 */
	private function unknown_key( array $value, array $allowed, string $path, string $what ): ?\WP_Error {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return $this->error(
					'nexo_mcp_elementor_style_invalid',
					$path . '.' . $key,
					sprintf(
						/* translators: 1: clave, 2: objeto, 3: claves admitidas. */
						__( 'la clave «%1$s» no se admite en %2$s. Admitidas: %3$s.', 'nexo-mcp' ),
						(string) $key,
						$what,
						implode( ', ', $allowed )
					)
				);
			}
		}

		return null;
	}

	/**
	 * Regla de los valores de texto de un estilo.
	 *
	 * Elementor concatena `propiedad:valor;` en el CSS de la página sin escapar el
	 * valor, así que cualquier texto (color, content, clip-path, aspect-ratio…)
	 * puede cerrar la declaración o la regla. Se rechaza todo valor de texto, a
	 * cualquier profundidad de los props de una variante, que contenga: `; { } < > \ @`,
	 * un carácter de control (incluye saltos de línea y tabuladores), `/*`,
	 * `!important`, o una función que carga recursos o rompe el aislamiento
	 * (`url(`, `src(`, `expression(`, `image-set(`, `image(`, `cross-fade(`, `element(`,
	 * `var(`; `-webkit-image-set(` cae en `image-set(`) o comillas sin cerrar. Comillas, comas, espacios,
	 * `#`, `%`, `.`, `-` y paréntesis de rgb()/hsl()/calc() siguen admitidos. `var(`
	 * no hace falta: las variables globales van como `$$type` global-*-variable.
	 * La ruta devuelta es la de la hoja (p. ej. `…props.color.value`).
	 *
	 * @param mixed  $value Valor o rama de props.
	 * @param string $path  Ruta.
	 *
	 * @return true|\WP_Error
	 */
	private function check_css_leaves( $value, string $path ) {
		if ( is_string( $value ) ) {
			if ( 1 === preg_match( '/[;{}<>\\\\@\x00-\x1F\x7F]|\/\*|!\s*important|(?:url|src|expression|image-set|image|cross-fade|element|var)\s*\(/i', $value ) ) {
				return $this->error(
					'nexo_mcp_elementor_style_value_denied',
					$path,
					__( 'el valor contiene caracteres o funciones que no se admiten en un valor de estilo (; { } < > \\ @, saltos de línea, /*, url(), image-set(), var(), !important…).', 'nexo-mcp' )
				);
			}

			// Comillas sin cerrar abrirían una cadena que se come el resto de la regla.
			if ( 0 !== substr_count( $value, '"' ) % 2 || 0 !== substr_count( $value, "'" ) % 2 ) {
				return $this->error(
					'nexo_mcp_elementor_style_value_denied',
					$path,
					__( 'el valor tiene comillas sin cerrar.', 'nexo-mcp' )
				);
			}

			return true;
		}

		if ( ! is_array( $value ) ) {
			return true;
		}

		foreach ( $value as $key => $child ) {
			if ( '$$type' === $key ) {
				continue;
			}

			$result = $this->check_css_leaves( $child, $this->join( $path, $key ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Construye un error con la ruta en el mensaje y en los datos.
	 *
	 * @param string $code   Código de la regla.
	 * @param string $path   Ruta del nodo o propiedad.
	 * @param string $reason Motivo.
	 */
	private function error( string $code, string $path, string $reason ): \WP_Error {
		return new \WP_Error( $code, $path . ': ' . $reason, array( 'path' => $path ) );
	}
}
