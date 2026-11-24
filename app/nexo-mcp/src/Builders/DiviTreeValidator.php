<?php
/**
 * Validación y saneado del árbol de módulos de Divi 5 que manda el cliente.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide si un árbol (o un parche de un módulo) de Divi 5 se puede guardar y devuelve su copia saneada.
 *
 * Formato del árbol del cliente: una lista de nodos
 * `[ { "type": "divi/section", "attrs": { … }, "children": [ … ] } ]`, con `type` del
 * {@see DiviModuleCatalog}, `attrs` opcional (los atributos de bloque de Divi tal como se
 * guardan: `{"title":{"innerContent":{"desktop":{"value":"Hola"}}}}`) e hijos opcionales.
 * No lleva `builderVersion`, `modulePreset` ni ids: los pone quien escribe. El parche de un
 * módulo es `{ "content"?: attrs, "design"?: attrs }`: `content` sólo admite rutas de contenido
 * y `design` sólo rutas de diseño del catálogo.
 *
 * Todo atributo debe estar en una ruta del catálogo y llevar únicamente `desktop.value`: tablet,
 * teléfono, hover y cualquier otra clave se rechazan (al editar, quien escribe conserva las que ya existen).
 * Es una clase pura: la existencia de un shortcode y la URL de un adjunto llegan como
 * funciones, de modo que se prueba sin WordPress. El resultado nunca se escribe sin pasar por aquí.
 *
 * Todo error lleva el código de la regla, la ruta en el mensaje (`nodes[0].children[1].attrs.title.innerContent.tablet: …`)
 * y la ruta en `error_data`. Códigos: `nexo_mcp_divi_module_denied`, `_attr_denied`, `_value_invalid`,
 * `_nesting_invalid`, `_shortcode_denied`, `_tree_invalid` (forma), `_too_many_nodes` y `_too_large`.
 */
final class DiviTreeValidator {

	public const MAX_NODES = 200;
	public const MAX_BYTES = 3145728;

	/**
	 * Claves de un nodo.
	 *
	 * @var array<int, string>
	 */
	private const NODE_KEYS = array( 'type', 'attrs', 'children' );

	/**
	 * Etiquetas y atributos del texto rico.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const ALLOWED_HTML = array(
		'p'      => array(),
		'h1'     => array(),
		'h2'     => array(),
		'h3'     => array(),
		'h4'     => array(),
		'h5'     => array(),
		'h6'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		// Sin `target`: abrir en otra pestaña permitiría tabnabbing, y forzar `rel` reescribiendo el
		// HTML después de wp_kses rompió el entrecomillado (XSS). wp_kses lo quita y nada lo toca después.
		'a'      => array(
			'href' => true,
			'rel'  => true,
		),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'br'     => array(),
		'span'   => array(),
	);

	/**
	 * Comprobación de existencia de un shortcode.
	 *
	 * @var callable
	 */
	private $shortcode_exists;

	/**
	 * Resolución de un adjunto: URL o false.
	 *
	 * @var callable
	 */
	private $attachment_url;

	/**
	 * Nodos vistos en la validación en curso.
	 *
	 * @var int
	 */
	private int $nodes = 0;

	/**
	 * Construye el validador.
	 *
	 * @param callable|null $shortcode_exists fn( string $name ): bool. Por omisión, `shortcode_exists()`.
	 * @param callable|null $attachment_url   fn( int $id ): string|false, URL del adjunto. Por omisión, `wp_get_attachment_url()`.
	 */
	public function __construct( ?callable $shortcode_exists = null, ?callable $attachment_url = null ) {
		$this->shortcode_exists = $shortcode_exists ?? static fn( string $name ): bool => shortcode_exists( $name );
		$this->attachment_url   = $attachment_url ?? static fn( int $id ) => wp_get_attachment_url( $id );
	}

	/**
	 * Valida un árbol y devuelve su copia saneada.
	 *
	 * @param mixed  $nodes       Lista de nodos recibida del cliente.
	 * @param string $parent_type Padre de la lista (`DiviModuleCatalog::ROOT` o el nombre de bloque).
	 * @param int    $base_depth  Reservado para el llamador (niveles sobre la lista); no cambia las reglas.
	 * @param int    $base_nodes  Nodos del documento que no forman parte de la lista, para el tope de {@see self::MAX_NODES}.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function validate_tree( $nodes, string $parent_type = DiviModuleCatalog::ROOT, int $base_depth = 0, int $base_nodes = 0 ) {
		unset( $base_depth );

		if ( ! is_array( $nodes ) || array() === $nodes || ! array_is_list( $nodes ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'nodes', __( 'debe ser una lista no vacía de módulos.', 'nexo-mcp' ) );
		}

		$encoded = wp_json_encode( $nodes );

		if ( ! is_string( $encoded ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'nodes', __( 'contiene datos que no se pueden codificar como JSON.', 'nexo-mcp' ) );
		}

		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			return $this->error( 'nexo_mcp_divi_too_large', 'nodes', __( 'el árbol pesa más de 3 MB.', 'nexo-mcp' ) );
		}

		$this->nodes = $base_nodes;

		return $this->walk_list( $nodes, 'nodes', $parent_type );
	}

	/**
	 * Valida el parche `{content?, design?}` de un módulo y devuelve su copia saneada.
	 *
	 * @param string $type  Nombre de bloque del módulo que se edita.
	 * @param mixed  $patch Parche recibido.
	 *
	 * @return array{content?: array<string, mixed>, design?: array<string, mixed>}|\WP_Error
	 */
	public function validate_patch( string $type, $patch ) {
		if ( ! DiviModuleCatalog::has( $type ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', 'type', $this->denied_reason( $type ) );
		}

		if ( ! is_array( $patch ) || array() === $patch || array_is_list( $patch ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'patch', __( 'debe ser un objeto con «content» y/o «design».', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $patch ) as $key ) {
			if ( 'content' !== $key && 'design' !== $key ) {
				return $this->error( 'nexo_mcp_divi_attr_denied', 'patch.' . $key, __( 'sólo se admiten «content» y «design».', 'nexo-mcp' ) );
			}
		}

		$out = array();

		foreach ( array( 'content', 'design' ) as $slot ) {
			if ( ! array_key_exists( $slot, $patch ) ) {
				continue;
			}

			if ( ! is_array( $patch[ $slot ] ) || ( array() !== $patch[ $slot ] && array_is_list( $patch[ $slot ] ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $slot, __( 'debe ser un objeto de atributos.', 'nexo-mcp' ) );
			}

			$specs  = 'content' === $slot ? DiviModuleCatalog::content_paths( $type ) : DiviModuleCatalog::design_paths( $type );
			$result = $this->walk_attrs( $patch[ $slot ], $specs, '', $slot );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( array() !== $result ) {
				$out[ $slot ] = $result;
			}
		}

		if ( array() === $out ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'patch', __( 'no cambia nada: indica al menos un atributo en «content» o «design».', 'nexo-mcp' ) );
		}

		return $out;
	}

	/**
	 * Valida los `attrs` de un preset de módulo o de grupo y devuelve su copia saneada.
	 *
	 * Un preset sólo lleva diseño de escritorio: las rutas de diseño del catálogo del módulo (con el mismo
	 * `desktop.value` y las mismas reglas de valor que un nodo del árbol) y, si es un preset de grupo, sólo las
	 * que cuelgan del grupo. Cualquier otra clave (manejadores de eventos, `attributes` personalizados,
	 * contenido, tablet, hover…) se rechaza, tenga o no el usuario `unfiltered_html`.
	 *
	 * @param string      $module   Nombre de bloque del módulo para el que es el preset.
	 * @param mixed       $attrs    Atributos recibidos.
	 * @param string|null $group_id Id del grupo de opciones (`module.decoration.background`), o null en un preset de módulo.
	 * @param string      $display  Nombre del campo en los mensajes y en `data.path`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate_preset_attrs( string $module, $attrs, ?string $group_id = null, string $display = 'attrs' ) {
		if ( ! DiviModuleCatalog::has( $module ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', 'module', $this->denied_reason( $module ) );
		}

		if ( ! is_array( $attrs ) || array() === $attrs || array_is_list( $attrs ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $display, __( 'debe ser un objeto de atributos con al menos un valor.', 'nexo-mcp' ) );
		}

		$specs = DiviModuleCatalog::design_paths( $module );

		if ( null !== $group_id ) {
			$specs = array_filter(
				$specs,
				static fn( string $path ): bool => $path === $group_id || str_starts_with( $path, $group_id . '.' ),
				ARRAY_FILTER_USE_KEY
			);
		}

		if ( array() === $specs ) {
			return $this->error( 'nexo_mcp_divi_attr_denied', $display, __( 'el catálogo no admite atributos de diseño para este módulo o grupo.', 'nexo-mcp' ) );
		}

		$clean = $this->walk_attrs( $attrs, $specs, '', $display );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( array() === $clean ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $display, __( 'no tiene ningún valor de diseño.', 'nexo-mcp' ) );
		}

		return $clean;
	}

	/**
	 * Recorre una lista de nodos hermanos.
	 *
	 * @param array<int, mixed> $nodes       Nodos.
	 * @param string            $path        Ruta de la lista.
	 * @param string            $parent_type Padre.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function walk_list( array $nodes, string $path, string $parent_type ) {
		$out = array();

		foreach ( $nodes as $index => $node ) {
			$result = $this->walk_node( $node, $path . '[' . $index . ']', $parent_type );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$out[] = $result;
		}

		return $out;
	}

	/**
	 * Valida un nodo y sus hijos.
	 *
	 * @param mixed  $node        Nodo.
	 * @param string $path        Ruta del nodo.
	 * @param string $parent_type Padre.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_node( $node, string $path, string $parent_type ) {
		if ( ! is_array( $node ) || ( array() !== $node && array_is_list( $node ) ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $path, __( 'cada módulo debe ser un objeto con «type».', 'nexo-mcp' ) );
		}

		++$this->nodes;

		if ( $this->nodes > self::MAX_NODES ) {
			return $this->error(
				'nexo_mcp_divi_too_many_nodes',
				$path,
				sprintf(
					/* translators: %d: máximo de módulos. */
					__( 'se supera el máximo de %d módulos.', 'nexo-mcp' ),
					self::MAX_NODES
				)
			);
		}

		foreach ( array_keys( $node ) as $key ) {
			if ( ! in_array( $key, self::NODE_KEYS, true ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$path . '.' . $key,
					sprintf(
						/* translators: 1: clave, 2: claves admitidas. */
						__( 'la clave «%1$s» no se admite en un módulo. Admitidas: %2$s.', 'nexo-mcp' ),
						(string) $key,
						implode( ', ', self::NODE_KEYS )
					)
				);
			}
		}

		$type = $node['type'] ?? null;

		if ( ! is_string( $type ) || '' === $type ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.type', __( 'indica el tipo del módulo.', 'nexo-mcp' ) );
		}

		if ( ! DiviModuleCatalog::has( $type ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', $path . '.type', $this->denied_reason( $type ) );
		}

		if ( ! in_array( $type, DiviModuleCatalog::children( $parent_type ), true ) ) {
			return $this->error(
				'nexo_mcp_divi_nesting_invalid',
				$path . '.type',
				sprintf(
					/* translators: 1: tipo, 2: padre, 3: hijos admitidos. */
					__( '«%1$s» no cabe dentro de «%2$s». Admitidos ahí: %3$s.', 'nexo-mcp' ),
					$type,
					$parent_type,
					array() === DiviModuleCatalog::children( $parent_type ) ? __( 'ninguno', 'nexo-mcp' ) : implode( ', ', DiviModuleCatalog::children( $parent_type ) )
				)
			);
		}

		$out = array( 'type' => $type );

		if ( array_key_exists( 'attrs', $node ) ) {
			$attrs = $node['attrs'];

			if ( ! is_array( $attrs ) || ( array() !== $attrs && array_is_list( $attrs ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.attrs', __( 'debe ser un objeto de atributos.', 'nexo-mcp' ) );
			}

			$clean = $this->walk_attrs( $attrs, array_merge( DiviModuleCatalog::content_paths( $type ), DiviModuleCatalog::design_paths( $type ) ), '', $path . '.attrs' );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out['attrs'] = $clean;
		}

		if ( array_key_exists( 'children', $node ) ) {
			$children = $node['children'];

			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.children', __( 'debe ser una lista de módulos.', 'nexo-mcp' ) );
			}

			$clean = $this->walk_list( $children, $path . '.children', $type );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out['children'] = $clean;
		}

		return $out;
	}

	/**
	 * Recorre atributos hasta las rutas del catálogo y valida cada hoja.
	 *
	 * @param array<int|string, mixed> $attrs   Objeto de atributos en el nivel `$dotted`.
	 * @param array<string, mixed>     $specs   Rutas admitidas (ruta → especificación).
	 * @param string                   $dotted  Ruta del catálogo del nivel actual (`title.innerContent`).
	 * @param string                   $display Ruta del nivel actual para los mensajes.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_attrs( array $attrs, array $specs, string $dotted, string $display ) {
		$out = array();

		foreach ( $attrs as $key => $child ) {
			$key   = (string) $key;
			$path  = '' === $dotted ? $key : $dotted . '.' . $key;
			$shown = $display . '.' . $key;

			if ( array_key_exists( $path, $specs ) ) {
				$leaf = $this->walk_leaf( $child, $specs[ $path ], $shown );

				if ( is_wp_error( $leaf ) ) {
					return $leaf;
				}

				$out[ $key ] = $leaf;
				continue;
			}

			if ( ! $this->is_prefix( $path, $specs ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown,
					__( 'este atributo no está en la lista admitida del módulo (sólo contenido y diseño de escritorio del catálogo).', 'nexo-mcp' )
				);
			}

			if ( ! is_array( $child ) || ( array() !== $child && array_is_list( $child ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $shown, __( 'debe ser un objeto.', 'nexo-mcp' ) );
			}

			$inner = $this->walk_attrs( $child, $specs, $path, $shown );

			if ( is_wp_error( $inner ) ) {
				return $inner;
			}

			if ( array() !== $inner ) {
				$out[ $key ] = $inner;
			}
		}

		return $out;
	}

	/**
	 * Indica si `$path` es el comienzo de alguna ruta del catálogo.
	 *
	 * @param string               $path  Ruta parcial.
	 * @param array<string, mixed> $specs Rutas admitidas.
	 */
	private function is_prefix( string $path, array $specs ): bool {
		foreach ( array_keys( $specs ) as $known ) {
			if ( str_starts_with( (string) $known, $path . '.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Valida el valor de una ruta: sólo `{desktop: {value}}`.
	 *
	 * @param mixed  $node  Valor recibido.
	 * @param mixed  $spec  Especificación del catálogo.
	 * @param string $shown Ruta para los mensajes.
	 *
	 * @return array{desktop: array{value: mixed}}|\WP_Error
	 */
	private function walk_leaf( $node, $spec, string $shown ) {
		if ( ! is_array( $node ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown, __( 'debe ser {"desktop": {"value": …}}.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $node ) as $key ) {
			if ( 'desktop' !== $key ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown . '.' . $key,
					sprintf(
						/* translators: %s: clave rechazada (tablet, phone, hover…). */
						__( 'sólo se admite el valor de escritorio («desktop»); «%s» no se escribe.', 'nexo-mcp' ),
						(string) $key
					)
				);
			}
		}

		$desktop = $node['desktop'] ?? null;

		if ( ! is_array( $desktop ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown . '.desktop', __( 'debe ser {"value": …}.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $desktop ) as $key ) {
			if ( 'value' !== $key ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown . '.desktop.' . $key,
					sprintf(
						/* translators: %s: clave rechazada. */
						__( 'sólo se admite «value» en el valor de escritorio; «%s» no se escribe.', 'nexo-mcp' ),
						(string) $key
					)
				);
			}
		}

		if ( ! array_key_exists( 'value', $desktop ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown . '.desktop.value', __( 'falta el valor.', 'nexo-mcp' ) );
		}

		$value = $this->check_value( $desktop['value'], $spec, $shown . '.desktop.value' );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		return array( 'desktop' => array( 'value' => $value ) );
	}

	/**
	 * Valida y sanea un valor según su especificación.
	 *
	 * @param mixed  $value Valor.
	 * @param mixed  $spec  Especificación: tipo escalar, `enum` u `object`.
	 * @param string $path  Ruta del valor.
	 *
	 * @return mixed|\WP_Error
	 */
	private function check_value( $value, $spec, string $path ) {
		if ( is_array( $spec ) && isset( $spec['enum'] ) ) {
			if ( ! is_string( $value ) || ! in_array( $value, $spec['enum'], true ) ) {
				return $this->invalid( $path, sprintf( /* translators: %s: valores admitidos. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ', ', $spec['enum'] ) ) );
			}

			return $value;
		}

		if ( is_array( $spec ) && isset( $spec['object'] ) ) {
			return $this->check_object( $value, $spec['object'], $path );
		}

		if ( ! is_string( $spec ) ) {
			return $this->invalid( $path, __( 'tipo de valor desconocido.', 'nexo-mcp' ) );
		}

		if ( 'src' === $spec ) {
			return $this->check_src( $value, $path );
		}

		if ( ! is_string( $value ) ) {
			return $this->invalid( $path, __( 'debe ser un texto.', 'nexo-mcp' ) );
		}

		switch ( $spec ) {
			case 'plain':
				return $this->check_plain( $value, $path );
			case 'rich':
				return $this->check_rich( $value, $path );
			case 'link':
				return '' === $value ? '' : $this->check_url( $value, $path, false, __( 'debe ser una dirección http, https, mailto, tel o una ruta del sitio (/…, #…, ?…).', 'nexo-mcp' ) );
			case 'media_url':
				return $this->check_url( $value, $path, true, __( 'debe ser una dirección http, https o una ruta del sitio (/…).', 'nexo-mcp' ) );
			case 'color':
				return DiviModuleCatalog::is_color( $value ) ? $value : $this->invalid( $path, __( 'debe ser #hex, rgb()/rgba()/hsl()/hsla() o una variable global de color.', 'nexo-mcp' ) );
			case 'size':
				return DiviModuleCatalog::is_size( $value ) ? $value : $this->invalid( $path, __( 'debe ser un tamaño con unidad (px, em, rem, %, vw, vh).', 'nexo-mcp' ) );
			case 'size_or_empty':
				return '' === $value || DiviModuleCatalog::is_size( $value ) ? $value : $this->invalid( $path, __( 'debe ser vacío o un tamaño con unidad (px, em, rem, %, vw, vh).', 'nexo-mcp' ) );
			case 'onoff':
				return 'on' === $value || 'off' === $value ? $value : $this->invalid( $path, __( 'debe ser «on» u «off».', 'nexo-mcp' ) );
			case 'family':
				return 1 === preg_match( '/^[\p{L}\p{N} _\-]{1,80}\z/u', $value ) ? $value : $this->invalid( $path, __( 'debe ser un nombre de fuente (letras, números, espacios, - y _).', 'nexo-mcp' ) );
			case 'weight':
				return 1 === preg_match( '/^(?:[1-9]00|normal|bold)\z/', $value ) ? $value : $this->invalid( $path, __( 'debe ser 100-900, normal o bold.', 'nexo-mcp' ) );
			case 'icon_unicode':
				return 1 === preg_match( '/^(?:&#x[0-9a-f]{1,6};|%%\d{1,4}%%)\z/i', $value ) ? $value : $this->invalid( $path, __( 'debe ser un código de ícono de Divi (&#x21; o %%3%%).', 'nexo-mcp' ) );
			default:
				return $this->invalid( $path, __( 'tipo de valor desconocido.', 'nexo-mcp' ) );
		}
	}

	/**
	 * Valida un objeto de valor: sólo las claves de la especificación.
	 *
	 * @param mixed                $value  Valor.
	 * @param array<string, mixed> $fields Claves admitidas y su especificación.
	 * @param string               $path   Ruta del valor.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_object( $value, array $fields, string $path ) {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			return $this->invalid( $path, __( 'debe ser un objeto.', 'nexo-mcp' ) );
		}

		$out = array();

		foreach ( $value as $key => $child ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, $fields ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$path . '.' . $key,
					sprintf(
						/* translators: 1: clave, 2: claves admitidas. */
						__( 'la clave «%1$s» no se admite aquí. Admitidas: %2$s.', 'nexo-mcp' ),
						$key,
						implode( ', ', array_keys( $fields ) )
					)
				);
			}

			$checked = $this->check_value( $child, $fields[ $key ], $path . '.' . $key );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$out[ $key ] = $checked;
		}

		return $out;
	}

	/**
	 * Valida una imagen: id de adjunto existente (se devuelve su URL) o URL http/https/relativa.
	 *
	 * @param mixed  $value Valor.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_src( $value, string $path ) {
		if ( is_int( $value ) ) {
			$url = $value > 0 ? ( $this->attachment_url )( $value ) : false;

			if ( ! is_string( $url ) || '' === $url ) {
				return $this->invalid( $path, __( 'no existe ningún adjunto con ese identificador.', 'nexo-mcp' ) );
			}

			return $url;
		}

		if ( ! is_string( $value ) ) {
			return $this->invalid( $path, __( 'debe ser el identificador de un adjunto o una URL http/https.', 'nexo-mcp' ) );
		}

		return $this->check_url( $value, $path, true, __( 'debe ser el identificador de un adjunto o una URL http/https.', 'nexo-mcp' ) );
	}

	/**
	 * Valida una URL con el catálogo y la normaliza con `esc_url_raw`.
	 *
	 * @param string $value  URL.
	 * @param string $path   Ruta del valor.
	 * @param bool   $media  true: URL de medio (sólo http/https o ruta); false: enlace (también mailto y tel).
	 * @param string $reason Motivo si no vale.
	 *
	 * @return string|\WP_Error
	 */
	private function check_url( string $value, string $path, bool $media, string $reason ) {
		$valid = $media ? DiviModuleCatalog::is_media_url( $value ) : DiviModuleCatalog::is_url( $value );
		$clean = $valid ? esc_url_raw( trim( $value ), $media ? array( 'http', 'https' ) : DiviModuleCatalog::SCHEMES ) : '';

		if ( '' === $clean ) {
			return $this->invalid( $path, $reason );
		}

		return $clean;
	}

	/**
	 * Texto sin marcado: se quitan las etiquetas (y el contenido de script/style) y no admite shortcodes.
	 *
	 * @param string $value Texto.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_plain( string $value, string $path ) {
		// Se revisa también el resultado: quitar etiquetas puede armar un shortcode (`[gall<b></b>ery]`).
		$clean     = wp_strip_all_tags( $this->without_active_blocks( $value ) );
		$shortcode = $this->registered_shortcode( $value ) ?? $this->registered_shortcode( $clean );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		return $clean;
	}

	/**
	 * Texto rico: sin shortcodes, enlaces seguros y sólo la lista corta de etiquetas.
	 *
	 * @param string $value HTML.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_rich( string $value, string $path ) {
		$shortcode = $this->registered_shortcode( $value );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		if ( preg_match_all( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $value, $matches, PREG_SET_ORDER ) > 0 ) {
			foreach ( $matches as $match ) {
				$href = $match[1] . ( $match[2] ?? '' ) . ( $match[3] ?? '' );

				if ( '' !== $href && ! DiviModuleCatalog::is_url( $href ) ) {
					return $this->invalid( $path, __( 'un enlace del texto usa una dirección no permitida (sólo http, https, mailto, tel o rutas del sitio).', 'nexo-mcp' ) );
				}
			}
		}

		// Lo que se guarda es la salida de wp_kses: también se revisa, porque quitar etiquetas puede armar un shortcode.
		$clean     = wp_kses( $this->without_active_blocks( $value ), self::ALLOWED_HTML, DiviModuleCatalog::SCHEMES );
		$shortcode = $this->registered_shortcode( $clean );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		return $clean;
	}

	/**
	 * Quita los bloques `<script>` y `<style>` con su contenido.
	 *
	 * @param string $value HTML.
	 */
	private function without_active_blocks( string $value ): string {
		return (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value );
	}

	/**
	 * Primer shortcode registrado que aparece en el texto (también con corchetes escritos como entidad), o null.
	 *
	 * Sigue la gramática de WordPress (`get_shortcode_regex`): el nombre registrado va justo después
	 * de `[` o `[/` y termina donde sigue un carácter que no es letra, dígito, `_` ni `-`; así
	 * `[gallery.]` o `[caption,]` cuentan como `gallery` y `caption`.
	 *
	 * @param string $value Texto.
	 */
	private function registered_shortcode( string $value ): ?string {
		foreach ( array( $value, html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $text ) {
			if ( preg_match_all( '/\[\/?([^\s\[\]<>&\/=\x00-\x20]+)/', $text, $matches ) > 0 ) {
				foreach ( $matches[1] as $run ) {
					$name = $this->shortcode_prefix( $run );

					if ( null !== $name ) {
						return $name;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Prefijo de `$run` que es un shortcode registrado y que WordPress cortaría ahí, o null.
	 *
	 * @param string $run Caracteres seguidos tras `[`.
	 */
	private function shortcode_prefix( string $run ): ?string {
		$length = strlen( $run );

		for ( $end = 1; $end <= $length; $end++ ) {
			if ( $end < $length && 1 === preg_match( '/[\w-]/', $run[ $end ] ) ) {
				continue;
			}

			$name = substr( $run, 0, $end );

			if ( (bool) ( $this->shortcode_exists )( $name ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Error de shortcode.
	 *
	 * @param string $path Ruta del valor.
	 * @param string $name Shortcode.
	 */
	private function shortcode_error( string $path, string $name ): \WP_Error {
		return $this->error(
			'nexo_mcp_divi_shortcode_denied',
			$path,
			sprintf(
				/* translators: %s: nombre del shortcode. */
				__( 'el texto contiene el shortcode «[%s]»: los shortcodes ejecutan código y no se admiten.', 'nexo-mcp' ),
				$name
			)
		);
	}

	/**
	 * Motivo por el que un módulo no se admite.
	 *
	 * @param string $type Tipo recibido.
	 */
	private function denied_reason( string $type ): string {
		return sprintf(
			/* translators: 1: tipo, 2: módulos admitidos. */
			__( '«%1$s» no está en el catálogo de módulos de Divi 5 admitidos (el código, el HTML crudo y los shortcodes nunca se crean). Admitidos: %2$s.', 'nexo-mcp' ),
			$type,
			implode( ', ', DiviModuleCatalog::modules() )
		);
	}

	/**
	 * Error de valor.
	 *
	 * @param string $path   Ruta del valor.
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return $this->error( 'nexo_mcp_divi_value_invalid', $path, $reason );
	}

	/**
	 * Construye un error con la ruta en el mensaje y en los datos.
	 *
	 * @param string $code   Código de la regla.
	 * @param string $path   Ruta del nodo o atributo.
	 * @param string $reason Motivo.
	 */
	private function error( string $code, string $path, string $reason ): \WP_Error {
		return new \WP_Error( $code, $path . ': ' . $reason, array( 'path' => $path ) );
	}
}
