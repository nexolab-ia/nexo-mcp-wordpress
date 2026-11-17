<?php
/**
 * Creación de una página atómica de Elementor 4 en borrador.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Valida el árbol y crea la página: nunca la publica.
 *
 * Orden: título, requisitos de Elementor (v4 activo), validación propia
 * ({@see ElementorTreeValidator}), validación de Elementor (`Props_Parser` y
 * `Style_Parser` con el esquema de cada tipo) y sólo entonces se escribe. Si el
 * guardado falla se borra el borrador recién creado, de modo que un error no deja
 * páginas a medias.
 *
 * Toda clase de Elementor se usa tras comprobar que existe: sin Elementor la
 * herramienta responde un error claro en vez de un fatal.
 */
final class ElementorPageCreator {

	/**
	 * Sistema de diseño: acceso guardado a Elementor y a las clases globales.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $design;

	/**
	 * Construye el creador.
	 *
	 * @param ElementorDesignSystem|null $design Acceso a Elementor.
	 */
	public function __construct( ?ElementorDesignSystem $design = null ) {
		$this->design = $design ?? new ElementorDesignSystem();
	}

	/**
	 * Crea la página.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ) {
		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';

		if ( '' === $title ) {
			return new \WP_Error(
				'nexo_mcp_elementor_title_required',
				__( 'Indica el título de la página.', 'nexo-mcp' )
			);
		}

		$ready = $this->require_v4();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$elements  = $input['elements'] ?? null;
		$validator = new ElementorTreeValidator(
			$this->allowed_settings(),
			$this->design->global_class_ids(),
			null,
			$this->style_prop_keys()
		);
		$checked   = $validator->validate( $elements );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$checked = $this->validate_with_elementor( $elements, 'elements' );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$tree = ( new ElementorTreeBuilder() )->build( $elements );

		return $this->save_page( $title, isset( $input['slug'] ) && is_string( $input['slug'] ) ? $input['slug'] : '', $tree );
	}

	/**
	 * Comprueba que Elementor esté activo y con los elementos atómicos encendidos.
	 *
	 * @return true|\WP_Error
	 */
	private function require_v4() {
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
	private function validate_with_elementor( array $nodes, string $path ) {
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
				$child = $this->validate_with_elementor( $node['elements'], $here . '.elements' );

				if ( is_wp_error( $child ) ) {
					return $child;
				}
			}
		}

		return true;
	}

	/**
	 * Crea el borrador y guarda el árbol; si falla, borra lo creado.
	 *
	 * @param string                           $title Título.
	 * @param string                           $slug  Slug opcional.
	 * @param array<int, array<string, mixed>> $tree  Árbol armado.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function save_page( string $title, string $slug, array $tree ) {
		$post = array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $title,
		);

		if ( '' !== $slug ) {
			$post['post_name'] = sanitize_title( $slug );
		}

		$post_id = wp_insert_post( $post, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$plugin   = $this->design->plugin();
		$expected = ElementorTreeBuilder::count_nodes( $tree );

		try {
			$documents = null !== $plugin && isset( $plugin->documents ) && is_object( $plugin->documents ) ? $plugin->documents : null;
			$document  = null !== $documents && method_exists( $documents, 'get' ) ? $documents->get( $post_id ) : false;

			if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) || ! method_exists( $document, 'set_is_built_with_elementor' ) ) {
				return $this->rollback( $post_id, __( 'Elementor no reconoce las páginas como documentos editables en este sitio.', 'nexo-mcp' ) );
			}

			$document->set_is_built_with_elementor( true );

			if ( true !== $document->save( array( 'elements' => $tree ) ) ) {
				return $this->rollback( $post_id, __( 'Elementor no pudo guardar el árbol.', 'nexo-mcp' ) );
			}
		} catch ( \Throwable $e ) {
			return $this->rollback(
				$post_id,
				sprintf(
					/* translators: %s: mensaje de Elementor. */
					__( 'Elementor no pudo guardar el árbol: %s', 'nexo-mcp' ),
					$e->getMessage()
				)
			);
		}

		// Elementor descarta en silencio los tipos que no tiene registrados: si
		// lo guardado no es todo lo pedido, la página no sirve y se deshace.
		$stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );

		if ( ! is_array( $stored ) || ElementorTreeBuilder::count_nodes( $stored ) !== $expected ) {
			return $this->rollback( $post_id, __( 'Elementor guardó menos elementos de los pedidos.', 'nexo-mcp' ) );
		}

		return array(
			'id'          => $post_id,
			'status'      => (string) get_post_status( $post_id ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'edit_url'    => method_exists( $document, 'get_edit_url' ) ? (string) $document->get_edit_url() : '',
			'nodes'       => $expected,
		);
	}

	/**
	 * Borra el borrador creado y devuelve el error.
	 *
	 * @param int    $post_id Borrador creado por esta llamada.
	 * @param string $reason  Motivo.
	 */
	private function rollback( int $post_id, string $reason ): \WP_Error {
		wp_delete_post( $post_id, true );

		return new \WP_Error(
			'nexo_mcp_elementor_save_failed',
			$reason . ' ' . __( 'El borrador creado se eliminó.', 'nexo-mcp' )
		);
	}
}
