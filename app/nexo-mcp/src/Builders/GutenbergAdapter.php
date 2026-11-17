<?php
/**
 * Adaptador del editor de bloques.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Edita bloques del editor nativo sin reescribir el documento completo.
 *
 * A diferencia del resto de constructores, aquí el formato es del propio
 * WordPress: `parse_blocks()` y `serialize_blocks()` hacen el trabajo pesado y
 * garantizan que lo que se devuelve sigue siendo válido. Sin este adaptador la
 * única forma de cambiar un párrafo era traer todo el `post_content`, reescribirlo
 * a mano y sobrescribirlo entero, con el riesgo de romper el marcado de bloques
 * y dejar la página con avisos de contenido inesperado.
 */
final class GutenbergAdapter implements BuilderAdapter {

	/**
	 * Identificador estable del constructor.
	 */
	public function slug(): string {
		return 'gutenberg';
	}

	/**
	 * Nombre legible para el propietario del sitio.
	 */
	public function label(): string {
		return 'Editor de bloques';
	}

	/**
	 * Sólo reclama contenido que realmente tenga bloques.
	 *
	 * Una entrada del editor clásico no los tiene, y tratarla como si fuera de
	 * bloques la convertiría en un único bloque HTML al guardar.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool {
		return function_exists( 'has_blocks' ) && has_blocks( $post->post_content );
	}

	/**
	 * El documento de bloques es `post_content`, así que reescribirlo funciona.
	 */
	public function accepts_full_content_write(): bool {
		return true;
	}

	/**
	 * Entrega los bloques con texto, recorriendo también los anidados.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array {
		$nodes = array();

		$this->walk(
			parse_blocks( $post->post_content ),
			'',
			function ( array $block, string $path ) use ( &$nodes ): void {
				$name = (string) ( $block['blockName'] ?? '' );

				if ( '' === $name ) {
					return;
				}

				if ( ! EditableTypes::allows( $this->slug(), $name ) ) {
					// Los bloques que aceptan HTML o shortcodes se listan, para que
					// el cliente sepa que existen, pero sin permitir escritura.
					if ( EditableTypes::is_forbidden( $name ) ) {
						$nodes[] = BuilderNode::blocked( $path, $name );

						return;
					}

					// Una imagen no tiene texto que editar, pero sí enlace, archivo
					// y texto alternativo. Sin esto quedaba fuera de la lista y sus
					// atributos eran inalcanzables: declarados y sin forma de llegar.
					$atributos = $this->read_attributes( $name, $block );

					if ( array() !== $atributos ) {
						$nodes[] = new BuilderNode(
							$path,
							$name,
							null,
							false,
							__( 'Sin texto editable; admite atributos.', 'nexo-mcp' ),
							$atributos
						);
					}

					return;
				}

				$nodes[] = new BuilderNode(
					$path,
					$name,
					$this->text_of( $block ),
					true,
					'',
					$this->read_attributes( $name, $block )
				);
			}
		);

		return $nodes;
	}

	/**
	 * Reemplaza el texto de un bloque conservando sus atributos y anidados.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Ruta del bloque.
	 * @param string   $text    Texto nuevo, ya sanitizado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		$blocks    = parse_blocks( $post->post_content );
		$outcome   = '';
		$bloqueado = '';

		$this->replace( $blocks, '', $node_id, $text, $outcome, $bloqueado );

		// Un bloque que existe pero no admite escritura no es lo mismo que uno
		// inexistente: decirlo permite al cliente elegir otro en vez de reintentar.
		if ( 'forbidden' === $outcome ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_locked',
				sprintf(
					/* translators: %s: nombre del bloque. */
					__( 'El bloque «%s» no admite edición de texto porque acepta código o contiene otros bloques.', 'nexo-mcp' ),
					$bloqueado
				)
			);
		}

		if ( 'linked' === $outcome ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_has_links',
				sprintf(
					/* translators: %s: nombre del bloque. */
					__( 'El bloque «%s» tiene enlaces en medio del texto y cambiarlo por texto plano los borraría. Edita ese bloque con nexo-mcp-update-page.', 'nexo-mcp' ),
					$bloqueado
				)
			);
		}

		if ( 'updated' !== $outcome ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'No se encontró el bloque indicado en esta página.', 'nexo-mcp' )
			);
		}

		$content = serialize_blocks( $blocks );

		$saved = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return true;
	}

	/**
	 * Reemplaza un atributo de un bloque conservando el resto del documento.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Ruta del bloque.
	 * @param string   $attribute Atributo a escribir.
	 * @param mixed    $value     Valor ya validado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		$blocks    = parse_blocks( $post->post_content );
		$outcome   = '';
		$bloqueado = '';

		$this->replace_attribute( $blocks, '', $node_id, $attribute, $value, $outcome, $bloqueado );

		if ( 'forbidden' === $outcome ) {
			return new \WP_Error(
				'nexo_mcp_builder_attr_denied',
				sprintf(
					/* translators: 1: nombre del bloque, 2: atributo solicitado. */
					__( 'El bloque «%1$s» no admite el atributo «%2$s».', 'nexo-mcp' ),
					$bloqueado,
					$attribute
				)
			);
		}

		if ( 'updated' !== $outcome ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'No se encontró el bloque indicado en esta página.', 'nexo-mcp' )
			);
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);

		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * El editor de bloques no mantiene caché propia por página.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		clean_post_cache( $post->ID );
	}

	/**
	 * Extrae los atributos editables de un bloque.
	 *
	 * Se leen del marcado y no de `attrs`, porque `attrs` sólo guarda lo que el
	 * editor considera no derivable: un `core/image` sin recorte ni tamaño
	 * personalizado llega con `attrs` casi vacío y el `src` real vive en el HTML.
	 *
	 * @param string               $name  Nombre del bloque.
	 * @param array<string, mixed> $block Bloque leído.
	 *
	 * @return array<string, mixed>
	 */
	private function read_attributes( string $name, array $block ): array {
		$admitidos = EditableAttributes::for_type( $this->slug(), $name );

		if ( array() === $admitidos ) {
			return array();
		}

		$html   = (string) ( $block['innerHTML'] ?? '' );
		$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$salida = array();

		foreach ( $admitidos as $atributo ) {
			switch ( $atributo ) {
				case EditableAttributes::LINK:
					$salida[ EditableAttributes::LINK ] = $this->get_attr( $html, 'A', 'href' );
					break;

				case EditableAttributes::IMAGE:
					$salida[ EditableAttributes::IMAGE ] = array(
						'id'  => (int) ( $attrs['id'] ?? 0 ),
						'url' => $this->get_attr( $html, 'IMG', 'src' ),
					);
					break;

				case EditableAttributes::ALT:
					$salida[ EditableAttributes::ALT ] = $this->get_attr( $html, 'IMG', 'alt' );
					break;
			}
		}

		return $salida;
	}

	/**
	 * Lee un atributo de la primera etiqueta que coincida.
	 *
	 * @param string $html     Marcado del bloque.
	 * @param string $etiqueta Etiqueta buscada, en mayúsculas.
	 * @param string $atributo Atributo leído.
	 */
	private function get_attr( string $html, string $etiqueta, string $atributo ): string {
		if ( '' === $html || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return '';
		}

		$procesador = new \WP_HTML_Tag_Processor( $html );

		if ( ! $procesador->next_tag( array( 'tag_name' => $etiqueta ) ) ) {
			return '';
		}

		$valor = $procesador->get_attribute( $atributo );

		return is_string( $valor ) ? $valor : '';
	}

	/**
	 * Localiza el bloque por su ruta y le escribe el atributo.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Bloques del nivel actual.
	 * @param string                           $prefix    Ruta del nivel padre.
	 * @param string                           $node_id   Ruta buscada.
	 * @param string                           $attribute Atributo a escribir.
	 * @param mixed                            $value     Valor ya validado.
	 * @param string                           $outcome   Resultado: updated, forbidden o vacío.
	 * @param string                           $bloqueado Nombre del bloque rechazado.
	 */
	private function replace_attribute(
		array &$blocks,
		string $prefix,
		string $node_id,
		string $attribute,
		$value,
		string &$outcome,
		string &$bloqueado
	): void {
		foreach ( $blocks as $index => &$block ) {
			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;

			if ( $path === $node_id ) {
				$name = (string) ( $block['blockName'] ?? '' );

				if ( '' !== $name && EditableAttributes::allows( $this->slug(), $name, $attribute ) ) {
					$this->write_attribute( $block, $name, $attribute, $value );
					$outcome = 'updated';
				} else {
					$outcome   = 'forbidden';
					$bloqueado = '' !== $name ? $name : 'sin nombre';
				}

				return;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->replace_attribute( $block['innerBlocks'], $path, $node_id, $attribute, $value, $outcome, $bloqueado );

				if ( 'updated' === $outcome ) {
					$this->rebuild_inner_content( $block );

					return;
				}

				if ( '' !== $outcome ) {
					return;
				}
			}
		}
	}

	/**
	 * Escribe el atributo en el bloque, tocando marcado y `attrs`.
	 *
	 * Un bloque del editor guarda el dato dos veces: en `attrs`, que es lo que
	 * lee el editor, y en el marcado, que es lo que ve el visitante. Cambiar
	 * sólo uno deja la página y el editor discrepando, y el primero que guarde
	 * desde el editor revierte lo escrito.
	 *
	 * @param array<string, mixed> $block     Bloque editado.
	 * @param string               $name      Nombre del bloque.
	 * @param string               $attribute Atributo a escribir.
	 * @param mixed                $value     Valor ya validado.
	 */
	private function write_attribute( array &$block, string $name, string $attribute, $value ): void {
		if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}

		$html = (string) ( $block['innerHTML'] ?? '' );

		switch ( $attribute ) {
			case EditableAttributes::LINK:
				$url = (string) $value;

				// En el botón actual la URL se lee del `href` del marcado; sólo los
				// botones antiguos la guardan además en `attrs`.
				if ( 'core/button' === $name && isset( $block['attrs']['url'] ) ) {
					$block['attrs']['url'] = $url;
				}

				$html = $this->set_attr( $html, 'A', 'href', $url );
				break;

			case EditableAttributes::IMAGE:
				$id  = (int) ( $value['id'] ?? 0 );
				$url = (string) ( $value['url'] ?? '' );

				$block['attrs']['id'] = $id;

				if ( isset( $block['attrs']['url'] ) ) {
					$block['attrs']['url'] = $url;
				}

				$html = $this->set_attr( $html, 'IMG', 'src', $url );
				// El editor identifica el adjunto por la clase `wp-image-N`; sin
				// actualizarla seguiría apuntando a la imagen anterior.
				$html = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $html ) ?? $html;

				if ( ! str_contains( $html, 'wp-image-' ) && $id > 0 ) {
					$html = $this->add_class( $html, 'IMG', 'wp-image-' . $id );
				}
				break;

			case EditableAttributes::ALT:
				$html = $this->set_attr( $html, 'IMG', 'alt', (string) $value );

				// Se refleja también en el adjunto, que es de donde lo toma el
				// resto del sitio cuando la imagen se reutiliza.
				$id_adjunto = (int) ( $block['attrs']['id'] ?? 0 );

				if ( $id_adjunto > 0 ) {
					update_post_meta( $id_adjunto, '_wp_attachment_image_alt', (string) $value );
				}
				break;
		}

		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );
	}

	/**
	 * Cambia un atributo de la primera etiqueta que coincida.
	 *
	 * Se usa `WP_HTML_Tag_Processor` y no una expresión regular porque es el
	 * analizador del propio WordPress: respeta comillas, atributos sin valor y
	 * marcado mal cerrado, que es donde una regex se equivoca y produce HTML
	 * roto sin avisar.
	 *
	 * @param string $html      Marcado del bloque.
	 * @param string $etiqueta  Etiqueta buscada, en mayúsculas.
	 * @param string $atributo  Atributo a escribir.
	 * @param string $valor     Valor nuevo.
	 */
	private function set_attr( string $html, string $etiqueta, string $atributo, string $valor ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$procesador = new \WP_HTML_Tag_Processor( $html );

		if ( ! $procesador->next_tag( array( 'tag_name' => $etiqueta ) ) ) {
			return $html;
		}

		$procesador->set_attribute( $atributo, $valor );

		return $procesador->get_updated_html();
	}

	/**
	 * Añade una clase a la primera etiqueta que coincida.
	 *
	 * @param string $html     Marcado del bloque.
	 * @param string $etiqueta Etiqueta buscada, en mayúsculas.
	 * @param string $clase    Clase a añadir.
	 */
	private function add_class( string $html, string $etiqueta, string $clase ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$procesador = new \WP_HTML_Tag_Processor( $html );

		if ( ! $procesador->next_tag( array( 'tag_name' => $etiqueta ) ) ) {
			return $html;
		}

		$procesador->add_class( $clase );

		return $procesador->get_updated_html();
	}

	/**
	 * Recorre el árbol de bloques aplicando una función a cada uno.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Bloques del nivel actual.
	 * @param string                           $prefix   Ruta del nivel padre.
	 * @param callable                         $callback Acción por bloque.
	 */
	private function walk( array $blocks, string $prefix, callable $callback ): void {
		foreach ( $blocks as $index => $block ) {
			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;

			$callback( $block, $path );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->walk( $block['innerBlocks'], $path, $callback );
			}
		}
	}

	/**
	 * Sustituye el texto del bloque cuya ruta coincide.
	 *
	 * @param array<int, array<string, mixed>> $blocks    Bloques del nivel actual.
	 * @param string                           $prefix    Ruta del nivel padre.
	 * @param string                           $node_id   Ruta buscada.
	 * @param string                           $text      Texto nuevo.
	 * @param string                           $outcome   Resultado: updated, forbidden o vacío.
	 * @param string                           $bloqueado Nombre del bloque rechazado.
	 */
	private function replace( array &$blocks, string $prefix, string $node_id, string $text, string &$outcome, string &$bloqueado ): void {
		foreach ( $blocks as $index => &$block ) {
			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;

			if ( $path === $node_id ) {
				$name = (string) ( $block['blockName'] ?? '' );

				if ( '' !== $name && EditableTypes::allows( $this->slug(), $name ) ) {
					if ( $this->write_text( $block, $text ) ) {
						$outcome = 'updated';
					} else {
						$outcome   = 'linked';
						$bloqueado = $name;
					}
				} else {
					$outcome   = 'forbidden';
					$bloqueado = '' !== $name ? $name : 'sin nombre';
				}

				return;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->replace( $block['innerBlocks'], $path, $node_id, $text, $outcome, $bloqueado );

				if ( 'updated' === $outcome ) {
					// El HTML del padre incluye el de sus hijos, así que hay que
					// rehacerlo tras cambiar uno de ellos.
					$this->rebuild_inner_content( $block );

					return;
				}

				// El hijo resolvió la búsqueda aunque haya sido para rechazarla.
				if ( '' !== $outcome ) {
					return;
				}
			}
		}
	}

	/**
	 * Extrae el texto visible de un bloque.
	 *
	 * @param array<string, mixed> $block Bloque leído.
	 */
	private function text_of( array $block ): string {
		$html = (string) ( $block['innerHTML'] ?? '' );

		return trim( wp_strip_all_tags( $html ) );
	}

	/**
	 * Escribe el texto dentro del marcado del bloque.
	 *
	 * Se conserva la etiqueta y sus atributos —clases, alineación, tamaño— y sólo
	 * se sustituye el contenido de texto, porque perderlos cambiaría el aspecto de
	 * la página aunque el texto quedara correcto.
	 *
	 * @param array<string, mixed> $block Bloque modificado.
	 * @param string               $text  Texto nuevo.
	 *
	 * @return bool false, sin tocar el bloque, si el cambio borraría un enlace.
	 */
	private function write_text( array &$block, string $text ): bool {
		$nuevo = $this->replace_inner_text( (string) ( $block['innerHTML'] ?? '' ), esc_html( $text ) );

		if ( null === $nuevo ) {
			return false;
		}

		$block['innerHTML']    = $nuevo;
		$block['innerContent'] = array( $nuevo );

		return true;
	}

	/**
	 * Baja por las etiquetas que envuelven todo el texto y cambia sólo lo de adentro.
	 *
	 * Un botón es `<div><a>Texto</a></div>`: si se reemplaza lo que hay dentro
	 * del `<div>`, se va el `<a>`, WordPress renderiza el botón vacío y este
	 * desaparece de la página. Por eso se desciende mientras una sola etiqueta
	 * envuelva todo el contenido (el `<p>`, el `<div>`, el `<a>`).
	 *
	 * @param string $html    Marcado del nivel actual.
	 * @param string $escaped Texto nuevo, ya escapado.
	 *
	 * @return string|null Marcado nuevo, o null si borraría un enlace.
	 */
	private function replace_inner_text( string $html, string $escaped ): ?string {
		if ( preg_match( '/^(\s*<([a-zA-Z][\w-]*)\b[^>]*>)(.*)(<\/\2>\s*)$/s', $html, $m ) && $this->wraps_all( $m[3], $m[2] ) ) {
			$inner = $this->replace_inner_text( $m[3], $escaped );

			return null === $inner ? null : $m[1] . $inner . $m[4];
		}

		// Aquí el texto se mezcla con otras etiquetas. Perder un `<em>` sólo
		// cambia el estilo, pero perder un `<a>` rompe el enlace: no hay forma
		// segura de saber dónde iría dentro del texto nuevo, así que se rechaza.
		if ( preg_match( '/<a\b/i', $html ) ) {
			return null;
		}

		return $escaped;
	}

	/**
	 * Indica si la etiqueta de apertura y la de cierre del extremo son pareja.
	 *
	 * En `<a>uno</a> y <a>dos</a>` el primer `<a>` y el último `</a>` no se
	 * corresponden: el medio cierra una etiqueta antes de abrir otra.
	 *
	 * @param string $inner Contenido entre la apertura y el cierre.
	 * @param string $tag   Nombre de la etiqueta.
	 */
	private function wraps_all( string $inner, string $tag ): bool {
		preg_match_all( '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $inner, $etiquetas );

		$nivel = 0;

		foreach ( $etiquetas[1] as $cierre ) {
			$nivel += '/' === $cierre ? -1 : 1;

			if ( $nivel < 0 ) {
				return false;
			}
		}

		return 0 === $nivel;
	}

	/**
	 * Rehace el marcado del bloque padre tras editar un hijo.
	 *
	 * @param array<string, mixed> $block Bloque contenedor.
	 */
	private function rebuild_inner_content( array &$block ): void {
		if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerContent'] ) ) {
			return;
		}

		// `innerContent` intercala el HTML propio del contenedor con marcadores
		// `null`, uno por cada bloque hijo. Se conserva esa estructura para que
		// serialize_blocks vuelva a insertarlos en su sitio.
		$block['innerHTML'] = implode(
			'',
			array_filter(
				$block['innerContent'],
				static fn( $fragment ): bool => is_string( $fragment )
			)
		);
	}
}
