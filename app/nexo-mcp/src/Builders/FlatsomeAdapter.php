<?php
/**
 * Adaptador de Flatsome (UX Builder).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Edita páginas de Flatsome tocando sólo el HTML que va dentro de sus columnas.
 *
 * A diferencia de Divi o WPBakery, en Flatsome el texto casi nunca vive en un
 * shortcode propio: `[section]`, `[row]` y `[col]` son contenedores y lo que se
 * lee es HTML corriente escrito dentro de ellos. Una allowlist de elementos
 * dejaría fuera casi toda la página, así que aquí el nodo es cada fragmento de
 * texto del HTML, con su enlace o su imagen.
 *
 * La regla de seguridad es la inversa de la habitual: los shortcodes nunca se
 * tocan. Antes de leer, se enmascaran todos —y el contenido completo de
 * `[ux_html]`, que admite código— conservando las posiciones, de modo que lo
 * que queda a la vista es sólo HTML escrito por el propietario.
 *
 * `[section]` y `[row]` son genéricos, así que el adaptador sólo reclama el
 * contenido cuando Flatsome es el tema o el bloque de UX Builder lo envuelve.
 */
final class FlatsomeAdapter implements BuilderAdapter {

	/**
	 * Tamaño máximo admitido del contenido, en bytes.
	 */
	private const MAX_BYTES = 3145728;

	/**
	 * Shortcodes cuyo contenido es código y no se lee ni se escribe.
	 *
	 * @var array<int, string>
	 */
	private const RAW = array( 'ux_html' );

	/**
	 * Elementos HTML sin cierre.
	 *
	 * @var array<int, string>
	 */
	private const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/**
	 * Comentarios, elementos de texto crudo y etiquetas, con atributos entre comillas.
	 */
	private const TAG = '/<!--.*?-->|<(script|style|iframe|textarea|noscript|svg)\b[^>]*>.*?<\/\1\s*>|<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)\s*(\/?)>/s';

	/**
	 * Un shortcode de apertura o cierre, con atributos entre comillas.
	 */
	private const SHORTCODE = '/\[\/?[a-zA-Z_][\w-]*(?:[^\]"\']|"[^"]*"|\'[^\']*\')*\]/';

	/**
	 * Identificador estable del constructor.
	 */
	public function slug(): string {
		return 'flatsome';
	}

	/**
	 * Nombre legible para el propietario del sitio.
	 */
	public function label(): string {
		return 'Flatsome UX Builder';
	}

	/**
	 * Reclama el contenido sólo si hay layout de UX Builder y Flatsome detrás.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool {
		$content = (string) $post->post_content;

		if ( '' === $content || strlen( $content ) > self::MAX_BYTES ) {
			return false;
		}

		if ( false === strpos( $content, '[section' ) && false === strpos( $content, '[row' ) ) {
			return false;
		}

		return false !== strpos( $content, '<!-- wp:flatsome/uxbuilder' ) || 'flatsome' === get_template();
	}

	/**
	 * El layout vive en shortcodes: reescribir el contenido entero lo destruye.
	 */
	public function accepts_full_content_write(): bool {
		return false;
	}

	/**
	 * Lista los textos e imágenes del HTML, en el orden de la página.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array {
		$scan  = $this->scan( (string) $post->post_content );
		$found = array();

		foreach ( $scan['texts'] as $index => $text ) {
			$attributes = array();

			if ( null !== $text['anchor'] ) {
				$attributes[ EditableAttributes::LINK ] = $scan['anchors'][ $text['anchor'] ]['href'];
			}

			$found[] = array( $text['offset'], new BuilderNode( 't:' . $index, $text['type'], $text['text'], true, '', $attributes ) );
		}

		foreach ( $scan['images'] as $index => $image ) {
			$attributes = array(
				EditableAttributes::IMAGE => array(
					'id'  => $image['id'],
					'url' => $image['src'],
				),
				EditableAttributes::ALT   => $image['alt'],
			);

			if ( null !== $image['anchor'] ) {
				$attributes[ EditableAttributes::LINK ] = $scan['anchors'][ $image['anchor'] ]['href'];
			}

			$found[] = array(
				$image['offset'],
				new BuilderNode( 'img:' . $index, 'img', null, false, __( 'Sin texto editable; admite atributos.', 'nexo-mcp' ), $attributes ),
			);
		}

		foreach ( $scan['raw'] as $index => $offset ) {
			$found[] = array( $offset, BuilderNode::blocked( 'ux_html:' . $index, 'ux_html' ) );
		}

		foreach ( $scan['blocks'] as $index => $block ) {
			$found[] = array(
				$block['offset'],
				new BuilderNode(
					'block:' . $index,
					'block',
					null,
					false,
					sprintf(
						/* translators: %s: identificador del bloque UX. */
						__( 'Bloque UX compartido entre páginas (%s): por ahora se edita desde Flatsome > UX Blocks.', 'nexo-mcp' ),
						$block['id']
					)
				),
			);
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return array_map( static fn( array $item ): BuilderNode => $item[1], $found );
	}

	/**
	 * Reemplaza un fragmento de texto conservando el HTML que lo rodea.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Identificador del nodo.
	 * @param string   $text    Texto nuevo, ya sanitizado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		$content = (string) $post->post_content;
		$scan    = $this->scan( $content );
		$target  = $this->parse_node_id( $node_id, 't' );

		if ( null === $target || ! isset( $scan['texts'][ $target ] ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe.', 'nexo-mcp' )
			);
		}

		// El nodo es texto plano dentro de una etiqueta: el marcado no tiene
		// dónde ir, y un corchete abriría un shortcode nuevo.
		$plain = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( false !== strpbrk( $plain, '[]' ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'El texto no puede llevar corchetes: en Flatsome abren un shortcode.', 'nexo-mcp' )
			);
		}

		// Un texto vacío desaparece de la lectura y corre la numeración del resto.
		if ( '' === $plain ) {
			return new \WP_Error(
				'nexo_mcp_builder_text_empty',
				__( 'El texto no puede quedar vacío.', 'nexo-mcp' )
			);
		}

		$node = $scan['texts'][ $target ];

		// Se conservan los espacios y saltos de línea que rodeaban el texto.
		preg_match( '/^(\s*).*?(\s*)$/s', $node['raw'], $edges );
		$replacement = $edges[1] . esc_html( $plain ) . $edges[2];

		return $this->save( $post, substr_replace( $content, $replacement, $node['offset'], strlen( $node['raw'] ) ) );
	}

	/**
	 * Cambia el enlace, la imagen o el texto alternativo de un nodo.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Identificador del nodo.
	 * @param string   $attribute Atributo a escribir.
	 * @param mixed    $value     Valor ya validado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		$content = (string) $post->post_content;
		$scan    = $this->scan( $content );
		$text    = $this->parse_node_id( $node_id, 't' );
		$image   = $this->parse_node_id( $node_id, 'img' );

		if ( null !== $text && isset( $scan['texts'][ $text ] ) ) {
			$node = $scan['texts'][ $text ];
		} elseif ( null !== $image && isset( $scan['images'][ $image ] ) ) {
			$node = $scan['images'][ $image ];
		} else {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe.', 'nexo-mcp' )
			);
		}

		if ( EditableAttributes::LINK === $attribute ) {
			if ( null === $node['anchor'] ) {
				return new \WP_Error(
					'nexo_mcp_builder_attr_denied',
					__( 'Ese elemento no está dentro de un enlace.', 'nexo-mcp' )
				);
			}

			$tag     = $scan['anchors'][ $node['anchor'] ];
			$updated = $this->set_attr( $tag['html'], 'href', (string) $value );
		} else {
			// Imagen y texto alternativo sólo existen en un nodo de imagen; la
			// allowlist ya lo garantiza por tipo, esto es la segunda barrera.
			if ( null === $image ) {
				return new \WP_Error(
					'nexo_mcp_builder_attr_denied',
					__( 'Ese elemento no es una imagen.', 'nexo-mcp' )
				);
			}

			$tag = $node;

			if ( EditableAttributes::IMAGE === $attribute ) {
				$updated = $this->set_attr( $tag['html'], 'src', (string) ( $value['url'] ?? '' ) );
				// Con `srcset` el navegador seguiría mostrando la imagen anterior.
				$updated = $this->remove_attr( $this->remove_attr( $updated, 'srcset' ), 'sizes' );
				$updated = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . (int) ( $value['id'] ?? 0 ), $updated ) ?? $updated;
			} else {
				$updated = $this->set_attr( $tag['html'], 'alt', (string) $value );
			}
		}

		return $this->save( $post, substr_replace( $content, $updated, $tag['offset'], strlen( $tag['html'] ) ) );
	}

	/**
	 * Flatsome no guarda caché propia por página.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		clean_post_cache( $post->ID );
	}

	/**
	 * Recorre el contenido y localiza textos, enlaces, imágenes y zonas vedadas.
	 *
	 * @param string $content Contenido completo.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function scan( string $content ): array {
		$result = array(
			'texts'   => array(),
			'images'  => array(),
			'anchors' => array(),
			'raw'     => array(),
			'blocks'  => array(),
		);

		$masked = $this->mask( $content, $result );

		if ( ! preg_match_all( self::TAG, $masked, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$tags = array();
		}

		$stack = array();
		$pos   = 0;

		foreach ( $tags as $tag ) {
			$offset = (int) $tag[0][1];
			$this->collect_texts( $masked, $content, $pos, $offset - $pos, $stack, $result );
			$pos = $offset + strlen( (string) $tag[0][0] );

			$name = isset( $tag[3] ) ? strtolower( (string) $tag[3][0] ) : '';

			// Comentarios y elementos de texto crudo no abren ni cierran nada.
			if ( '' === $name ) {
				continue;
			}

			$html = substr( $content, $offset, strlen( (string) $tag[0][0] ) );

			if ( '/' === $tag[2][0] ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $stack[ $i ]['name'] === $name ) {
						array_splice( $stack, $i );
						break;
					}
				}

				continue;
			}

			if ( 'img' === $name ) {
				$result['images'][] = array(
					'offset' => $offset,
					'html'   => $html,
					'src'    => $this->get_attr( $html, 'src' ),
					'alt'    => $this->get_attr( $html, 'alt' ),
					'id'     => preg_match( '/\bwp-image-(\d+)\b/', $html, $id ) ? (int) $id[1] : 0,
					'anchor' => $this->current_anchor( $stack ),
				);
			}

			if ( in_array( $name, self::VOID, true ) || '/' === $tag[5][0] ) {
				continue;
			}

			$entry = array(
				'name'   => $name,
				'anchor' => null,
			);

			if ( 'a' === $name ) {
				$entry['anchor']     = count( $result['anchors'] );
				$result['anchors'][] = array(
					'offset' => $offset,
					'html'   => $html,
					'href'   => $this->get_attr( $html, 'href' ),
				);
			}

			$stack[] = $entry;
		}

		$this->collect_texts( $masked, $content, $pos, strlen( $masked ) - $pos, $stack, $result );

		return $result;
	}

	/**
	 * Tapa los shortcodes y el código de `[ux_html]` sin mover las posiciones.
	 *
	 * @param string                           $content Contenido completo.
	 * @param array<string, array<int, mixed>> $result  Resultado del recorrido.
	 */
	private function mask( string $content, array &$result ): string {
		$masked = $content;

		foreach ( self::RAW as $raw ) {
			$start = 0;

			while ( preg_match( '/\[' . $raw . '\b/', $masked, $open, PREG_OFFSET_CAPTURE, $start ) ) {
				$from  = (int) $open[0][1];
				$close = strpos( $masked, '[/' . $raw . ']', $from );
				// Sin cierre, se tapa hasta el final: mejor perder texto que exponer código.
				$to = false === $close ? strlen( $masked ) : $close + strlen( '[/' . $raw . ']' );

				$result['raw'][] = $from;
				$masked          = substr_replace( $masked, str_repeat( "\0", $to - $from ), $from, $to - $from );
				$start           = $to;
			}
		}

		if ( preg_match_all( self::SHORTCODE, $masked, $codes, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $codes[0] as $code ) {
				$length = strlen( (string) $code[0] );

				if ( preg_match( '/^\[block\b.*?\bid=["\']?([^"\'\]\s]+)/', (string) $code[0], $block ) ) {
					$result['blocks'][] = array(
						'offset' => (int) $code[1],
						'id'     => $block[1],
					);
				}

				$masked = substr_replace( $masked, str_repeat( "\0", $length ), (int) $code[1], $length );
			}
		}

		return $masked;
	}

	/**
	 * Registra los fragmentos de texto de un tramo entre dos etiquetas.
	 *
	 * @param string                           $masked  Contenido enmascarado.
	 * @param string                           $content Contenido original.
	 * @param int                              $from    Inicio del tramo.
	 * @param int                              $length  Largo del tramo.
	 * @param array<int, array<string, mixed>> $stack   Etiquetas abiertas.
	 * @param array<string, array<int, mixed>> $result  Resultado del recorrido.
	 */
	private function collect_texts( string $masked, string $content, int $from, int $length, array $stack, array &$result ): void {
		if ( $length <= 0 ) {
			return;
		}

		$pieces = preg_split( '/\0+/', substr( $masked, $from, $length ), -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $pieces as $piece ) {
			$raw  = (string) $piece[0];
			$text = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' === $text || false !== strpbrk( $raw, '[]' ) ) {
				continue;
			}

			$anchor = $this->current_anchor( $stack );
			$top    = end( $stack );

			$result['texts'][] = array(
				'offset' => $from + (int) $piece[1],
				'raw'    => substr( $content, $from + (int) $piece[1], strlen( $raw ) ),
				'text'   => $text,
				'type'   => null !== $anchor ? 'a' : ( false !== $top ? $top['name'] : 'text' ),
				'anchor' => $anchor,
			);
		}
	}

	/**
	 * Entrega el enlace abierto más interno, o null.
	 *
	 * @param array<int, array<string, mixed>> $stack Etiquetas abiertas.
	 */
	private function current_anchor( array $stack ): ?int {
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( null !== $stack[ $i ]['anchor'] ) {
				return $stack[ $i ]['anchor'];
			}
		}

		return null;
	}

	/**
	 * Patrón de un atributo dentro de una etiqueta.
	 *
	 * @param string $name Atributo buscado.
	 */
	private function attr_pattern( string $name ): string {
		return '/(\s)' . preg_quote( $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i';
	}

	/**
	 * Lee un atributo de una etiqueta.
	 *
	 * @param string $html Etiqueta completa.
	 * @param string $name Atributo leído.
	 */
	private function get_attr( string $html, string $name ): string {
		if ( ! preg_match( $this->attr_pattern( $name ), $html, $match ) ) {
			return '';
		}

		return html_entity_decode( trim( $match[2], '"\'' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Escribe un atributo en una etiqueta, añadiéndolo si no estaba.
	 *
	 * @param string $html  Etiqueta completa.
	 * @param string $name  Atributo escrito.
	 * @param string $value Valor nuevo.
	 */
	private function set_attr( string $html, string $name, string $value ): string {
		$pair = $name . '="' . esc_attr( $value ) . '"';

		if ( preg_match( $this->attr_pattern( $name ), $html ) ) {
			return (string) preg_replace_callback(
				$this->attr_pattern( $name ),
				static fn( array $m ): string => $m[1] . $pair,
				$html,
				1
			);
		}

		return (string) preg_replace( '/\s*(\/?>)$/', ' ' . str_replace( '$', '\$', $pair ) . '$1', $html, 1 );
	}

	/**
	 * Quita un atributo de una etiqueta.
	 *
	 * @param string $html Etiqueta completa.
	 * @param string $name Atributo quitado.
	 */
	private function remove_attr( string $html, string $name ): string {
		return (string) preg_replace( $this->attr_pattern( $name ), '', $html );
	}

	/**
	 * Descompone un identificador del tipo indicado.
	 *
	 * @param string $node_id Identificador recibido.
	 * @param string $prefix  Prefijo esperado.
	 */
	private function parse_node_id( string $node_id, string $prefix ): ?int {
		return preg_match( '/^' . $prefix . ':(\d+)$/', $node_id, $parts ) ? (int) $parts[1] : null;
	}

	/**
	 * Guarda el contenido comprobando que los shortcodes siguen intactos.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $updated Contenido nuevo.
	 *
	 * @return true|\WP_Error
	 */
	private function save( \WP_Post $post, string $updated ) {
		// Última barrera: una edición de texto o atributo no puede cambiar
		// ningún shortcode. Si cambió, algo se coló y no se guarda.
		preg_match_all( self::SHORTCODE, (string) $post->post_content, $before );
		preg_match_all( self::SHORTCODE, $updated, $after );

		if ( $before[0] !== $after[0] ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'El cambio altera la estructura del contenido.', 'nexo-mcp' )
			);
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $updated,
			),
			true
		);

		return is_wp_error( $saved ) ? $saved : true;
	}
}
