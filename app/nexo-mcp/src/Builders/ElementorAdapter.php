<?php
/**
 * Adaptador de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee y edita el árbol JSON que Elementor guarda en `_elementor_data`.
 *
 * El documento es un arreglo de elementos anidados. Cada uno declara su
 * `elType` —`container`, `section`, `column` o `widget`— y los widgets añaden
 * `widgetType`. El texto vive dentro de `settings`, en claves que dependen del
 * widget, así que la correspondencia se declara explícitamente en lugar de
 * buscar cualquier cadena del objeto.
 */
final class ElementorAdapter implements BuilderAdapter, StructureAdapter {

	/**
	 * Clave donde Elementor guarda el árbol de la página.
	 */
	private const DATA_KEY = '_elementor_data';

	/**
	 * Clave que marca el contenido como editado con Elementor.
	 */
	private const MODE_KEY = '_elementor_edit_mode';

	/**
	 * Tamaño máximo admitido del documento, en bytes.
	 */
	private const MAX_BYTES = 3145728;

	/**
	 * Tope de elementos que se pueden duplicar de una vez.
	 */
	private const MAX_CLONE = 100;

	/**
	 * Clave de `settings` que contiene el texto de cada widget.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_KEY = array(
		'heading'        => 'title',
		'text-editor'    => 'editor',
		'button'         => 'text',
		'icon-box'       => 'title_text',
		'image-box'      => 'title_text',
		'testimonial'    => 'testimonial_content',
		'call-to-action' => 'title',
		'price-table'    => 'heading',
		'toggle'         => 'title',
		'accordion'      => 'title',
		'tabs'           => 'title',
		'alert'          => 'alert_title',
		'blockquote'     => 'blockquote_content',
		'counter'        => 'title',
		'progress'       => 'title',
		'star-rating'    => 'title',
	);

	/**
	 * Prop de texto de cada elemento atómico de Elementor 4.
	 *
	 * @var array<string, string>
	 */
	private const ATOMIC_TEXT_PROP = array(
		'e-heading'   => 'title',
		'e-paragraph' => 'paragraph',
		'e-button'    => 'text',
	);

	/**
	 * Entrega el identificador del constructor.
	 */
	public function slug(): string {
		return 'elementor';
	}

	/**
	 * Entrega el nombre legible.
	 */
	public function label(): string {
		return 'Elementor';
	}

	/**
	 * Indica si el contenido fue construido con Elementor.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool {
		if ( 'builder' !== get_post_meta( $post->ID, self::MODE_KEY, true ) ) {
			return false;
		}

		return '' !== (string) get_post_meta( $post->ID, self::DATA_KEY, true );
	}

	/**
	 * El árbol está en `post_meta`, así que escribir el contenido no hace nada.
	 */
	public function accepts_full_content_write(): bool {
		return false;
	}

	/**
	 * Recorre el árbol y devuelve sus nodos de texto.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array {
		$tree = $this->decode( $post );

		if ( ! is_array( $tree ) ) {
			return array();
		}

		$nodes = array();
		$this->walk( $tree, $nodes );

		return $nodes;
	}

	/**
	 * Sustituye el texto de un widget conservando el resto del documento.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Identificador del widget.
	 * @param string   $text    Texto nuevo.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		$tree = $this->decode( $post );

		if ( ! is_array( $tree ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_unreadable',
				__( 'El documento de Elementor no pudo leerse.', 'nexo-mcp' )
			);
		}

		$before  = $this->count_nodes( $tree );
		$applied = false;

		$this->apply( $tree, $node_id, $text, $applied );

		if ( ! $applied ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe o no admite edición.', 'nexo-mcp' )
			);
		}

		// Una edición de texto no puede alterar la forma del árbol. Si el número
		// de elementos cambió, algo se perdió por el camino y no se persiste.
		if ( $this->count_nodes( $tree ) !== $before ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'La edición alteró la estructura del documento y fue descartada.', 'nexo-mcp' )
			);
		}

		$encoded = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'nexo_mcp_builder_too_large',
				__( 'El documento resultante excede el tamaño admitido.', 'nexo-mcp' )
			);
		}

		// Elementor guarda el JSON con las barras escapadas por WordPress.
		update_post_meta( $post->ID, self::DATA_KEY, wp_slash( $encoded ) );

		return true;
	}

	/**
	 * Regenera el CSS que Elementor cachea por página.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( isset( $plugin->files_manager ) ) {
			$plugin->files_manager->clear_cache();
		}
	}

	/**
	 * Decodifica el documento guardado.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, mixed>|null
	 */
	private function decode( \WP_Post $post ): ?array {
		$raw = (string) get_post_meta( $post->ID, self::DATA_KEY, true );

		if ( '' === $raw || strlen( $raw ) > self::MAX_BYTES ) {
			return null;
		}

		$tree = json_decode( $raw, true );

		return is_array( $tree ) ? $tree : null;
	}

	/**
	 * Recorre el árbol acumulando los nodos de texto.
	 *
	 * @param array<int, mixed>       $elements Elementos del nivel actual.
	 * @param array<int, BuilderNode> $nodes    Acumulador de nodos.
	 */
	private function walk( array $elements, array &$nodes ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$atomic = $this->atomic_type( $element );

			if ( '' !== $atomic ) {
				if ( '' !== (string) ( $element['id'] ?? '' ) ) {
					$nodes[] = $this->atomic_node( $element, $atomic );
				}
			} elseif ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$node = $this->to_node( $element );

				if ( null !== $node ) {
					$nodes[] = $node;
				}
			} elseif ( '' !== (string) ( $element['id'] ?? '' ) ) {
				// Secciones, columnas y contenedores no tienen texto, pero se
				// listan para poder duplicarlos o usarlos como referencia.
				$nodes[] = new BuilderNode( (string) $element['id'], (string) ( $element['elType'] ?? '' ), null, false );
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk( $element['elements'], $nodes );
			}
		}
	}

	/**
	 * Tipo atómico (`e-*`) de un elemento, o cadena vacía si no lo es.
	 *
	 * @param array<string, mixed> $element Elemento del árbol.
	 */
	private function atomic_type( array $element ): string {
		$el_type = (string) ( $element['elType'] ?? '' );
		$type    = 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : $el_type;

		return str_starts_with( $type, 'e-' ) ? $type : '';
	}

	/**
	 * Convierte un elemento atómico en nodo de sólo lectura.
	 *
	 * @param array<string, mixed> $element Elemento atómico.
	 * @param string               $type    Tipo `e-*`.
	 */
	private function atomic_node( array $element, string $type ): BuilderNode {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
			? $element['settings']
			: array();

		$text = null;
		$prop = self::ATOMIC_TEXT_PROP[ $type ] ?? '';

		if ( '' !== $prop && isset( $settings[ $prop ] ) && is_array( $settings[ $prop ] ) ) {
			$value = $settings[ $prop ]['value'] ?? null;

			if ( is_string( $value ) ) {
				$text = $value;
			} elseif ( 'html-v3' === ( $settings[ $prop ]['$$type'] ?? '' ) && is_array( $value ) ) {
				$content = $value['content']['value'] ?? null;
				$text    = is_string( $content ) ? $content : null;
			}
		}

		$classes = array();
		$list    = isset( $settings['classes'] ) && is_array( $settings['classes'] )
			? ( $settings['classes']['value'] ?? null )
			: null;

		if ( is_array( $list ) && array_is_list( $list ) && array() === array_filter( $list, static fn( $c ): bool => ! is_string( $c ) ) ) {
			$classes = $list;
		}

		return new BuilderNode(
			(string) $element['id'],
			$type,
			$text,
			false,
			__( 'Elemento atómico de Elementor 4: por ahora sólo lectura.', 'nexo-mcp' ),
			array(),
			$classes
		);
	}

	/**
	 * Convierte un widget en nodo normalizado.
	 *
	 * @param array<string, mixed> $element Widget del árbol.
	 */
	private function to_node( array $element ): ?BuilderNode {
		$id   = (string) ( $element['id'] ?? '' );
		$type = (string) ( $element['widgetType'] ?? '' );

		if ( '' === $id || '' === $type ) {
			return null;
		}

		if ( EditableTypes::is_forbidden( $type ) ) {
			return BuilderNode::blocked( $id, $type );
		}

		if ( ! EditableTypes::allows( $this->slug(), $type ) ) {
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
				? $element['settings']
				: array();

			// Un widget de imagen no tiene texto, pero sí archivo, enlace y texto
			// alternativo. Sin esto quedaba fuera de la lista y sus atributos eran
			// inalcanzables: declarados y sin forma de llegar a ellos.
			$atributos = $this->read_attributes( $type, $settings );

			return new BuilderNode(
				$id,
				$type,
				null,
				false,
				array() !== $atributos
					? __( 'Sin texto editable; admite atributos.', 'nexo-mcp' )
					: __( 'Tipo sin correspondencia de texto conocida.', 'nexo-mcp' ),
				$atributos
			);
		}

		$key      = self::TEXT_KEY[ $type ] ?? '';
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
			? $element['settings']
			: array();

		$value = isset( $settings[ $key ] ) && is_string( $settings[ $key ] )
			? $settings[ $key ]
			: '';

		return new BuilderNode( $id, $type, $value, true, '', $this->read_attributes( $type, $settings ) );
	}

	/**
	 * Extrae los atributos editables de un widget.
	 *
	 * Sólo se devuelven los que el tipo admite, para que el cliente sepa de
	 * antemano qué puede pedir en lugar de descubrirlo a base de errores.
	 *
	 * @param string               $type     Tipo del widget.
	 * @param array<string, mixed> $settings Ajustes del widget.
	 *
	 * @return array<string, mixed>
	 */
	private function read_attributes( string $type, array $settings ): array {
		$salida = array();

		foreach ( EditableAttributes::for_type( $this->slug(), $type ) as $atributo ) {
			switch ( $atributo ) {
				case EditableAttributes::LINK:
					$enlace = isset( $settings['link'] ) && is_array( $settings['link'] )
						? $settings['link']
						: array();

					$salida[ EditableAttributes::LINK ] = (string) ( $enlace['url'] ?? '' );
					break;

				case EditableAttributes::IMAGE:
					$imagen = isset( $settings['image'] ) && is_array( $settings['image'] )
						? $settings['image']
						: array();

					$salida[ EditableAttributes::IMAGE ] = array(
						'id'  => (int) ( $imagen['id'] ?? 0 ),
						'url' => (string) ( $imagen['url'] ?? '' ),
					);
					break;

				case EditableAttributes::ALT:
					// Elementor no guarda el texto alternativo en el widget: lo
					// toma del adjunto. Se informa desde ahí para que lo que se
					// lee sea lo que el visitante recibe.
					$id_imagen = isset( $settings['image']['id'] ) ? (int) $settings['image']['id'] : 0;

					$salida[ EditableAttributes::ALT ] = $id_imagen > 0
						? (string) get_post_meta( $id_imagen, '_wp_attachment_image_alt', true )
						: '';
					break;
			}
		}

		return $salida;
	}

	/**
	 * Escribe un atributo en el widget indicado.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Identificador del widget.
	 * @param string   $attribute Atributo a escribir.
	 * @param mixed    $value     Valor ya validado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		$tree = $this->decode( $post );

		if ( ! is_array( $tree ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_unreadable',
				__( 'El documento de Elementor no pudo leerse.', 'nexo-mcp' )
			);
		}

		$before  = $this->count_nodes( $tree );
		$applied = false;

		$this->apply_attribute( $tree, $node_id, $attribute, $value, $applied );

		if ( ! $applied ) {
			return new \WP_Error(
				'nexo_mcp_builder_attr_missing',
				__( 'El elemento no existe o no admite ese atributo.', 'nexo-mcp' )
			);
		}

		// Igual que en la edición de texto: cambiar un atributo no puede alterar
		// la forma del árbol. Si el recuento no cuadra, algo se perdió.
		if ( $this->count_nodes( $tree ) !== $before ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'La edición alteró la estructura del documento y fue descartada.', 'nexo-mcp' )
			);
		}

		$encoded = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'nexo_mcp_builder_too_large',
				__( 'El documento resultante excede el tamaño admitido.', 'nexo-mcp' )
			);
		}

		update_post_meta( $post->ID, self::DATA_KEY, wp_slash( $encoded ) );

		return true;
	}

	/**
	 * Recorre el árbol y escribe el atributo en el widget que corresponda.
	 *
	 * @param array<int, mixed> $elements  Elementos del nivel actual.
	 * @param string            $node_id   Identificador buscado.
	 * @param string            $attribute Atributo a escribir.
	 * @param mixed             $value     Valor ya validado.
	 * @param bool              $applied   Señal de escritura efectuada.
	 */
	private function apply_attribute(
		array &$elements,
		string $node_id,
		string $attribute,
		$value,
		bool &$applied
	): void {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$matches = (string) ( $element['id'] ?? '' ) === $node_id
				&& 'widget' === ( $element['elType'] ?? '' );

			if ( $matches ) {
				$type = (string) ( $element['widgetType'] ?? '' );

				if ( EditableAttributes::allows( $this->slug(), $type, $attribute ) ) {
					if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
						$element['settings'] = array();
					}

					$this->write_attribute( $element['settings'], $attribute, $value );
					$applied = true;
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->apply_attribute( $element['elements'], $node_id, $attribute, $value, $applied );
			}
		}
	}

	/**
	 * Coloca el valor en la estructura que espera Elementor.
	 *
	 * @param array<string, mixed> $settings  Ajustes del widget, por referencia.
	 * @param string               $attribute Atributo a escribir.
	 * @param mixed                $value     Valor ya validado.
	 */
	private function write_attribute( array &$settings, string $attribute, $value ): void {
		switch ( $attribute ) {
			case EditableAttributes::LINK:
				$actual = isset( $settings['link'] ) && is_array( $settings['link'] )
					? $settings['link']
					: array();

				// Se conservan `is_external` y `nofollow`, que son decisiones del
				// propietario y no tienen por qué perderse al cambiar el destino.
				$settings['link'] = array_merge(
					array(
						'is_external' => '',
						'nofollow'    => '',
					),
					$actual,
					array( 'url' => (string) $value )
				);
				break;

			case EditableAttributes::IMAGE:
				// `$value` viene de AttributeValue::image(), así que trae id y url
				// de un adjunto que existe y es una imagen.
				$settings['image'] = array(
					'id'  => (int) ( $value['id'] ?? 0 ),
					'url' => (string) ( $value['url'] ?? '' ),
				);
				break;

			case EditableAttributes::ALT:
				// El texto alternativo vive en el adjunto, no en el widget: es lo
				// que lee Elementor al pintar y lo que aprovecha el resto del
				// sitio. Escribirlo en el árbol no tendría efecto visible.
				$id_imagen = isset( $settings['image']['id'] ) ? (int) $settings['image']['id'] : 0;

				if ( $id_imagen > 0 ) {
					update_post_meta( $id_imagen, '_wp_attachment_image_alt', (string) $value );
				}
				break;
		}
	}

	/**
	 * Escribe el texto en el widget indicado.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 * @param string            $node_id  Identificador buscado.
	 * @param string            $text     Texto nuevo.
	 * @param bool              $applied  Señal de escritura efectuada.
	 */
	private function apply( array &$elements, string $node_id, string $text, bool &$applied ): void {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$matches = (string) ( $element['id'] ?? '' ) === $node_id
				&& 'widget' === ( $element['elType'] ?? '' );

			if ( $matches ) {
				$type = (string) ( $element['widgetType'] ?? '' );

				if ( EditableTypes::allows( $this->slug(), $type ) ) {
					$key = self::TEXT_KEY[ $type ] ?? '';

					if ( '' !== $key ) {
						if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
							$element['settings'] = array();
						}

						$element['settings'][ $key ] = $text;
						$applied                     = true;
					}
				}

				return;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->apply( $element['elements'], $node_id, $text, $applied );

				if ( $applied ) {
					return;
				}
			}
		}
	}

	/**
	 * Cuenta los elementos del árbol para comprobar que la forma no cambió.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 */
	private function count_nodes( array $elements ): int {
		$total = 0;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			++$total;

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$total += $this->count_nodes( $element['elements'] );
			}
		}

		return $total;
	}

	/**
	 * Prepara la página con una copia del elemento, sin guardarla.
	 *
	 * La copia sale del propio documento: el cliente elige qué duplicar y
	 * dónde, pero nunca aporta ajustes. Así no hay forma de colar un widget
	 * nuevo, CSS a medida o atributos que el propietario no haya puesto ya.
	 *
	 * @param \WP_Post $post     Contenido editado.
	 * @param string   $node_id  Elemento a duplicar.
	 * @param string   $target   Elemento junto al que va la copia; vacío, el original.
	 * @param string   $position `before` o `after`.
	 *
	 * @return array{tree: array<int, mixed>, created: array<int, BuilderNode>}|\WP_Error
	 */
	public function prepare_duplicate( \WP_Post $post, string $node_id, string $target, string $position ) {
		$tree = $this->decode( $post );

		if ( ! is_array( $tree ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_unreadable',
				__( 'El documento de Elementor no pudo leerse.', 'nexo-mcp' )
			);
		}

		$source = $this->locate( $tree, $node_id );
		$anchor = '' === $target ? $source : $this->locate( $tree, $target );

		if ( null === $source || null === $anchor ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		$element = $source['element'];

		if ( $this->has_forbidden( array( $element ) ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_type_forbidden',
				__( 'Ese elemento contiene widgets que admiten código y no se duplica.', 'nexo-mcp' )
			);
		}

		$size = $this->count_nodes( array( $element ) );

		if ( $size > self::MAX_CLONE ) {
			return new \WP_Error(
				'nexo_mcp_builder_clone_too_large',
				sprintf(
					/* translators: %d: tope de elementos. */
					__( 'Ese elemento tiene más de %d elementos dentro; duplica una parte más chica.', 'nexo-mcp' ),
					self::MAX_CLONE
				)
			);
		}

		if ( ! $this->fits( (string) ( $element['elType'] ?? '' ), $source['parent'], $anchor['parent'] ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_bad_position',
				__( 'La copia no puede ir en ese lugar: elige como referencia un elemento del mismo nivel.', 'nexo-mcp' )
			);
		}

		$taken    = $this->collect_ids( $tree );
		$distinct = count( $taken );
		$clone    = $this->renew_ids( $element, $taken );
		$total    = $this->count_nodes( $tree );

		$siblings = &$this->children_at( $tree, $anchor['parent_path'] );
		array_splice( $siblings, $anchor['index'] + ( 'before' === $position ? 0 : 1 ), 0, array( $clone ) );
		unset( $siblings );

		// La página debe quedar con los mismos elementos más la copia, y cada
		// elemento copiado con un identificador que no exista: Elementor se
		// confunde si se repiten.
		if ( $this->count_nodes( $tree ) !== $total + $size || count( $this->collect_ids( $tree ) ) !== $distinct + $size ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'La copia alteró la estructura del documento y fue descartada.', 'nexo-mcp' )
			);
		}

		$created = array();
		$this->walk( array( $clone ), $created );

		return array(
			'tree'    => $tree,
			'created' => $created,
		);
	}

	/**
	 * Guarda un documento ya preparado y validado.
	 *
	 * @param \WP_Post          $post Contenido editado.
	 * @param array<int, mixed> $tree Documento completo.
	 *
	 * @return true|\WP_Error
	 */
	public function save_tree( \WP_Post $post, array $tree ) {
		$encoded = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'nexo_mcp_builder_too_large',
				__( 'El documento resultante excede el tamaño admitido.', 'nexo-mcp' )
			);
		}

		// Elementor guarda el JSON con las barras escapadas por WordPress.
		update_post_meta( $post->ID, self::DATA_KEY, wp_slash( $encoded ) );

		return true;
	}

	/**
	 * Localiza un elemento y el lugar que ocupa.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 * @param string            $id       Identificador buscado.
	 * @param array<int, int>   $path     Posiciones hasta el padre.
	 * @param string            $owner    Tipo del padre, o `root`.
	 *
	 * @return array{element: array<string, mixed>, parent: string, parent_path: array<int, int>, index: int}|null
	 */
	private function locate( array $elements, string $id, array $path = array(), string $owner = 'root' ): ?array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array(
					'element'     => $element,
					'parent'      => $owner,
					'parent_path' => $path,
					'index'       => (int) $index,
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->locate( $element['elements'], $id, array_merge( $path, array( (int) $index ) ), (string) ( $element['elType'] ?? '' ) );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Indica si la copia puede vivir bajo el padre de la referencia.
	 *
	 * Se exige el mismo tipo de padre que el original: una sección en la raíz
	 * sigue en la raíz y una columna sigue dentro de una sección. Un widget es
	 * la excepción, porque vale igual en una columna que en un contenedor.
	 *
	 * @param string $type        Tipo del elemento duplicado.
	 * @param string $source      Tipo del padre original.
	 * @param string $destination Tipo del padre de destino.
	 */
	private function fits( string $type, string $source, string $destination ): bool {
		if ( $source === $destination ) {
			return true;
		}

		return 'widget' === $type && in_array( $destination, array( 'column', 'container' ), true );
	}

	/**
	 * Devuelve por referencia la lista de hijos en una posición del árbol.
	 *
	 * @param array<int, mixed> $tree Documento completo.
	 * @param array<int, int>   $path Posiciones hasta el padre; vacío, la raíz.
	 *
	 * @return array<int, mixed>
	 */
	private function &children_at( array &$tree, array $path ): array {
		$level = &$tree;

		foreach ( $path as $index ) {
			$level = &$level[ $index ]['elements'];
		}

		return $level;
	}

	/**
	 * Indica si algún widget del subárbol admite código.
	 *
	 * @param array<int, mixed> $elements Elementos evaluados.
	 */
	private function has_forbidden( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'widget' === ( $element['elType'] ?? '' ) && EditableTypes::is_forbidden( (string) ( $element['widgetType'] ?? '' ) ) ) {
				return true;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && $this->has_forbidden( $element['elements'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reúne los identificadores usados en el documento.
	 *
	 * @param array<int, mixed> $elements Elementos del nivel actual.
	 *
	 * @return array<string, true>
	 */
	private function collect_ids( array $elements ): array {
		$ids = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$ids[ (string) ( $element['id'] ?? '' ) ] = true;

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids += $this->collect_ids( $element['elements'] );
			}
		}

		return $ids;
	}

	/**
	 * Da un identificador nuevo a un elemento y a todo lo que contiene.
	 *
	 * Mismo formato que Elementor: siete caracteres hexadecimales.
	 *
	 * @param array<string, mixed> $element Elemento copiado.
	 * @param array<string, true>  $taken   Identificadores ya usados.
	 *
	 * @return array<string, mixed>
	 */
	private function renew_ids( array $element, array &$taken ): array {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $taken[ $id ] ) );

		$taken[ $id ]  = true;
		$element['id'] = $id;

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $index => $child ) {
				if ( is_array( $child ) ) {
					$element['elements'][ $index ] = $this->renew_ids( $child, $taken );
				}
			}
		}

		return $element;
	}
}
