<?php
/**
 * Comprobaciones de Elementor que comparten las herramientas que escriben árboles atómicos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Guarda de esquema: requisitos de Elementor 4 y validación con sus analizadores.
 *
 * Reúne lo que necesitan `create-elementor-page` y `edit-elementor-section`: que
 * Elementor esté activo con los elementos atómicos, el validador propio armado
 * con los datos de Elementor (esquemas de props, clases globales, propiedades de
 * estilo) y la validación con `Props_Parser` y `Style_Parser`, porque Elementor
 * descarta en silencio los estilos inválidos al guardar.
 *
 * No es final para que las pruebas la reemplacen por una sin Elementor.
 */
class ElementorSchemaGuard {

	/**
	 * Sistema de diseño: acceso guardado a Elementor y a las clases globales.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $design;

	/**
	 * Construye la guarda.
	 *
	 * @param ElementorDesignSystem|null $design Acceso a Elementor.
	 */
	public function __construct( ?ElementorDesignSystem $design = null ) {
		$this->design = $design ?? new ElementorDesignSystem();
	}

	/**
	 * Validador propio con los datos que Elementor tiene instalados.
	 */
	public function validator(): ElementorTreeValidator {
		return new ElementorTreeValidator(
			$this->allowed_settings(),
			$this->design->global_class_ids(),
			null,
			$this->style_prop_keys()
		);
	}

	/**
	 * Comprueba que Elementor esté activo y con los elementos atómicos encendidos.
	 *
	 * @return true|\WP_Error
	 */
	public function require_v4() {
		if ( null === $this->design->plugin() ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Elementor no está activo en este sitio. Instala y activa Elementor 4 y enciende el Editor V4 (Elementor → Ajustes → Funciones → «Editor V4») para crear páginas con elementos atómicos.', 'nexo-mcp' )
			);
		}

		if ( ! $this->design->atomic_enabled() ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Los elementos atómicos de Elementor 4 no están activos. Enciende el Editor V4 en Elementor → Ajustes → Funciones → «Editor V4» (experimento «Elementos atómicos») y vuelve a intentarlo. No se creó nada.', 'nexo-mcp' )
			);
		}

		return true;
	}

	/**
	 * Claves de `settings` de cada tipo, según el esquema que registra Elementor.
	 *
	 * Los tipos que esta versión de Elementor no registra se omiten, y el
	 * validador los rechaza como no disponibles.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function allowed_settings(): array {
		$out = array();

		foreach ( ElementorTreeValidator::allowed_types() as $type ) {
			$schema = $this->props_schema( $type );

			if ( null !== $schema ) {
				$out[ $type ] = array_map( 'strval', array_keys( $schema ) );
			}
		}

		return $out;
	}

	/**
	 * Propiedades de estilo que Elementor conoce (`Style_Schema::get()`).
	 *
	 * @return array<int, string>
	 */
	private function style_prop_keys(): array {
		$style_schema = '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema';

		if ( ! class_exists( $style_schema ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $style_schema::get() ) );
	}

	/**
	 * Claves de más dentro de un prop de forma de objeto, a cualquier profundidad.
	 *
	 * Elementor ignora en silencio lo que no está en la forma del tipo; aquí se
	 * rechaza para que el cliente no crea que aplicó algo que se perdió. Para una
	 * unión se elige el miembro por su `$$type`. Lo que no se puede resolver se
	 * deja a los analizadores de Elementor.
	 *
	 * @param mixed  $type  Objeto de tipo de propiedad de Elementor.
	 * @param mixed  $value Valor `{$$type, value}` del cliente.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|null Ruta de la primera clave desconocida.
	 */
	private function unknown_shape_key( $type, $value, string $path ): ?string {
		if ( ! is_object( $type ) || ! is_array( $value ) || ! is_string( $value['$$type'] ?? null ) ) {
			return null;
		}

		if ( method_exists( $type, 'get_prop_type' ) ) {
			$type = $type->get_prop_type( $value['$$type'] );
		}

		$inner = $value['value'] ?? null;

		if ( is_object( $type ) && method_exists( $type, 'get_item_type' ) && is_array( $inner ) && array_is_list( $inner ) ) {
			foreach ( $inner as $index => $item ) {
				$found = $this->unknown_shape_key( $type->get_item_type(), $item, $path . '.value[' . $index . ']' );

				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		}

		if ( ! is_object( $type ) || ! method_exists( $type, 'get_shape' ) || ! is_array( $inner ) || array_is_list( $inner ) ) {
			return null;
		}

		$shape = $type->get_shape();

		foreach ( $inner as $key => $child ) {
			if ( ! isset( $shape[ $key ] ) ) {
				return $path . '.value.' . $key;
			}

			$found = $this->unknown_shape_key( $shape[ $key ], $child, $path . '.value.' . $key );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Esquema de propiedades de un tipo, o null si no está registrado.
	 *
	 * @param string $type Tipo atómico.
	 *
	 * @return array<string, mixed>|null
	 */
	private function props_schema( string $type ): ?array {
		$plugin = $this->design->plugin();

		if ( null === $plugin ) {
			return null;
		}

		$instance = null;

		if ( in_array( $type, ElementorTreeValidator::CONTAINERS, true ) ) {
			if ( isset( $plugin->elements_manager ) && is_object( $plugin->elements_manager ) && method_exists( $plugin->elements_manager, 'get_element_types' ) ) {
				$instance = $plugin->elements_manager->get_element_types( $type );
			}
		} elseif ( isset( $plugin->widgets_manager ) && is_object( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) {
			$instance = $plugin->widgets_manager->get_widget_types( $type );
		}

		if ( ! is_object( $instance ) || ! method_exists( $instance, 'get_props_schema' ) ) {
			return null;
		}

		$schema = $instance::get_props_schema();

		return is_array( $schema ) ? $schema : null;
	}

	/**
	 * Valida ajustes y estilos con los analizadores de Elementor.
	 *
	 * Corre antes de armar el árbol para que las rutas sean las del cliente.
	 * Es necesario porque Elementor descarta en silencio los estilos inválidos al
	 * guardar y sólo lanza una excepción con los ajustes.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodos hermanos.
	 * @param string                           $path  Ruta de la lista.
	 *
	 * @return true|\WP_Error
	 */
	public function validate( array $nodes, string $path = 'elements' ) {
		$props_parser = '\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser';
		$style_parser = '\Elementor\Modules\AtomicWidgets\Parsers\Style_Parser';
		$style_schema = '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema';

		if ( ! class_exists( $props_parser ) || ! class_exists( $style_parser ) || ! class_exists( $style_schema ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Esta versión de Elementor no trae el validador de elementos atómicos. Actualiza Elementor a la 4.x.', 'nexo-mcp' )
			);
		}

		foreach ( $nodes as $index => $node ) {
			$here   = $path . '[' . $index . ']';
			$type   = (string) $node['type'];
			$schema = $this->props_schema( $type );

			try {
				if ( null !== $schema ) {
					$result = $props_parser::make( $schema )->validate( is_array( $node['settings'] ?? null ) ? $node['settings'] : array() );

					foreach ( $result->errors()->all() as $error ) {
						return new \WP_Error(
							'nexo_mcp_elementor_props_invalid',
							$here . '.settings.' . $error['key'] . ': ' . sprintf(
								/* translators: 1: motivo que da Elementor, 2: tipo del elemento. */
								__( 'Elementor rechazó el valor (%1$s). Revisa la forma que pide el esquema de «%2$s».', 'nexo-mcp' ),
								$error['error'],
								$type
							),
							array( 'path' => $here . '.settings.' . $error['key'] )
						);
					}
				}

				foreach ( is_array( $node['styles'] ?? null ) ? $node['styles'] : array() as $key => $style ) {
					$style['id'] = (string) $key;

					foreach ( is_array( $style['variants'] ?? null ) ? array_keys( $style['variants'] ) : array() as $position ) {
						$style['variants'][ $position ]['props']      = is_array( $style['variants'][ $position ]['props'] ?? null ) ? $style['variants'][ $position ]['props'] : array();
						$style['variants'][ $position ]['custom_css'] = null;
					}

					$all_props = $style_schema::get();

					foreach ( $style['variants'] ?? array() as $position => $variant ) {
						foreach ( $variant['props'] as $prop => $prop_value ) {
							$unknown = $this->unknown_shape_key( $all_props[ $prop ] ?? null, $prop_value, $here . '.styles.' . $key . '.variants[' . $position . '].props.' . $prop );

							if ( null !== $unknown ) {
								return new \WP_Error(
									'nexo_mcp_elementor_style_prop_unknown',
									$unknown . ': ' . __( 'esa clave no existe en la forma de esta propiedad de estilo de Elementor.', 'nexo-mcp' ),
									array( 'path' => $unknown )
								);
							}
						}
					}

					$result = $style_parser::make( $style_schema::get() )->parse( $style );

					foreach ( $result->errors()->all() as $error ) {
						return new \WP_Error(
							'nexo_mcp_elementor_style_rejected',
							$here . '.styles.' . $key . ': ' . sprintf(
								/* translators: %s: error de Elementor. */
								__( 'Elementor rechazó el estilo (%s).', 'nexo-mcp' ),
								$error['key'] . ': ' . $error['error']
							),
							array( 'path' => $here . '.styles.' . $key )
						);
					}
				}
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'nexo_mcp_elementor_props_invalid',
					$here . ': ' . sprintf(
						/* translators: %s: mensaje de Elementor. */
						__( 'Elementor no pudo validar el elemento (%s).', 'nexo-mcp' ),
						$e->getMessage()
					),
					array( 'path' => $here )
				);
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$child = $this->validate( $node['elements'], $here . '.elements' );

				if ( is_wp_error( $child ) ) {
					return $child;
				}
			}
		}

		return true;
	}

	/**
	 * Devuelve el árbol con los ajustes y estilos saneados por Elementor.
	 *
	 * `Document::save` pasa cada elemento por `Props_Parser::parse()` y
	 * `Style_Parser::parse()`, que además de validar sanean (kses del texto,
	 * atributos, ids). Quien guarda con `save_tree` no pasa por ahí, así que tiene
	 * que guardar esto y no lo que mandó el cliente. Se llama después de
	 * {@see self::validate()}: aquí un valor que el analizador rechace es un error.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodos hermanos ya validados.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function sanitize( array $nodes ) {
		$props_parser = '\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser';
		$style_parser = '\Elementor\Modules\AtomicWidgets\Parsers\Style_Parser';
		$style_schema = '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema';

		if ( ! class_exists( $props_parser ) || ! class_exists( $style_parser ) || ! class_exists( $style_schema ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Esta versión de Elementor no trae el validador de elementos atómicos. Actualiza Elementor a la 4.x.', 'nexo-mcp' )
			);
		}

		foreach ( $nodes as $index => $node ) {
			$type   = (string) ( $node['type'] ?? '' );
			$schema = $this->props_schema( $type );

			try {
				if ( null !== $schema ) {
					$result = $props_parser::make( $schema )->parse( is_array( $node['settings'] ?? null ) ? $node['settings'] : array() );

					if ( ! $result->is_valid() ) {
						return $this->sanitize_error( $index, $result->errors()->to_string() );
					}

					$node['settings'] = $result->unwrap();
				}

				$styles = array();

				foreach ( is_array( $node['styles'] ?? null ) ? $node['styles'] : array() as $key => $style ) {
					$style['id'] = (string) $key;

					foreach ( is_array( $style['variants'] ?? null ) ? array_keys( $style['variants'] ) : array() as $position ) {
						$style['variants'][ $position ]['props']      = is_array( $style['variants'][ $position ]['props'] ?? null ) ? $style['variants'][ $position ]['props'] : array();
						$style['variants'][ $position ]['custom_css'] = null;
					}

					$result = $style_parser::make( $style_schema::get() )->parse( $style );

					if ( ! $result->is_valid() ) {
						return $this->sanitize_error( $index, $result->errors()->to_string() );
					}

					$styles[ $key ] = $result->unwrap();
				}

				$node['styles'] = $styles;
			} catch ( \Throwable $e ) {
				return $this->sanitize_error( $index, $e->getMessage() );
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$children = $this->sanitize( $node['elements'] );

				if ( is_wp_error( $children ) ) {
					return $children;
				}

				$node['elements'] = $children;
			}

			$nodes[ $index ] = $node;
		}

		return $nodes;
	}

	/**
	 * Error de saneado de un elemento.
	 *
	 * @param int|string $index   Posición del elemento.
	 * @param string     $message Motivo que da Elementor.
	 */
	private function sanitize_error( $index, string $message ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_elementor_props_invalid',
			'elements[' . $index . ']: ' . sprintf(
				/* translators: %s: mensaje de Elementor. */
				__( 'Elementor no pudo sanear el elemento (%s).', 'nexo-mcp' ),
				$message
			),
			array( 'path' => 'elements[' . $index . ']' )
		);
	}
}
