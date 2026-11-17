<?php
/**
 * Base para constructores basados en shortcodes.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee y edita constructores que guardan su estructura en `post_content`.
 *
 * Divi y WPBakery no usan `post_meta`: anidan shortcodes propietarios dentro
 * del contenido. Eso los hace visibles para WordPress —y para las revisiones,
 * a diferencia de Elementor— pero exige interpretar su gramática en lugar de
 * decodificar un JSON.
 *
 * Los identificadores se derivan del orden de aparición de cada tipo, porque
 * estos shortcodes no llevan uno propio. Son estables mientras no se agreguen
 * ni eliminen elementos del mismo tipo por delante, que es justamente lo que
 * esta fase no permite hacer.
 */
abstract class ShortcodeBuilderAdapter implements BuilderAdapter {

	/**
	 * Tamaño máximo admitido del contenido, en bytes.
	 */
	private const MAX_BYTES = 3145728;

	/**
	 * Entrega el shortcode que delata al constructor.
	 */
	abstract protected function signature(): string;

	/**
	 * Indica si el contenido fue construido con este constructor.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool {
		$content = (string) $post->post_content;

		if ( '' === $content || strlen( $content ) > self::MAX_BYTES ) {
			return false;
		}

		return false !== strpos( $content, '[' . $this->signature() );
	}

	/**
	 * El layout vive en shortcodes anidados: sustituirlos por HTML lo destruye.
	 */
	public function accepts_full_content_write(): bool {
		return false;
	}

	/**
	 * Recorre el contenido y devuelve sus nodos de texto.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array {
		$content = (string) $post->post_content;
		$nodes   = array();

		foreach ( EditableTypes::for_builder( $this->slug() ) as $tag ) {
			foreach ( $this->matches( $content, $tag ) as $index => $match ) {
				$nodes[] = new BuilderNode(
					$this->node_id( $tag, $index ),
					$tag,
					$match['inner']
				);
			}
		}

		// Los elementos vedados se informan igualmente, para que el cliente sepa
		// que existen y por qué no puede tocarlos.
		foreach ( $this->forbidden_tags() as $tag ) {
			foreach ( $this->matches( $content, $tag ) as $index => $match ) {
				unset( $match );
				$nodes[] = BuilderNode::blocked( $this->node_id( $tag, $index ), $tag );
			}
		}

		return $nodes;
	}

	/**
	 * Sustituye el contenido interno de un shortcode.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Identificador del nodo.
	 * @param string   $text    Texto nuevo.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		$parsed = $this->parse_node_id( $node_id );

		if ( null === $parsed ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El identificador del elemento no es válido.', 'nexo-mcp' )
			);
		}

		list( $tag, $index ) = $parsed;

		if ( ! EditableTypes::allows( $this->slug(), $tag ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_type_forbidden',
				__( 'Ese tipo de elemento no admite edición.', 'nexo-mcp' )
			);
		}

		$content = (string) $post->post_content;
		$matches = $this->matches( $content, $tag );

		if ( ! isset( $matches[ $index ] ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe.', 'nexo-mcp' )
			);
		}

		$match  = $matches[ $index ];
		$before = $this->count_shortcodes( $content );

		$updated = substr_replace(
			$content,
			$match['open'] . $text . $match['close'],
			$match['offset'],
			strlen( $match['full'] )
		);

		// Igual que en Elementor: una edición de texto no puede alterar la
		// cantidad de shortcodes. Si cambia, el texto traía marcado propio.
		if ( $this->count_shortcodes( $updated ) !== $before ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_changed',
				__( 'El texto introducido altera la estructura del contenido.', 'nexo-mcp' )
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

	/**
	 * Los atributos todavía no se editan en constructores de shortcode.
	 *
	 * Divi y WPBakery guardan enlaces e imágenes como parámetros dentro del
	 * propio shortcode, con comillas anidadas y a veces con su propia
	 * codificación. Escribirlos exige un analizador distinto del que resuelve el
	 * texto, y hacerlo a medias es peor que no hacerlo.
	 *
	 * Se responde con un error explícito y no con `true`: informar éxito sin
	 * efecto es justo el fallo que este plugin persigue en todas partes.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Identificador del nodo.
	 * @param string   $attribute Atributo solicitado.
	 * @param mixed    $value     Valor ya validado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		unset( $post, $node_id, $value );

		return new \WP_Error(
			'nexo_mcp_builder_attr_unsupported',
			sprintf(
				/* translators: 1: nombre del constructor, 2: atributo solicitado. */
				__( '%1$s todavía no admite editar «%2$s». Por ahora sólo se pueden cambiar sus textos.', 'nexo-mcp' ),
				$this->label(),
				$attribute
			)
		);
	}

	/**
	 * Purga la caché del constructor.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		clean_post_cache( $post->ID );
	}

	/**
	 * Entrega los shortcodes vedados que este constructor puede contener.
	 *
	 * @return array<int, string>
	 */
	abstract protected function forbidden_tags(): array;

	/**
	 * Localiza las apariciones de un shortcode con contenido.
	 *
	 * El patrón no es reentrante: asume que un elemento de texto no contiene
	 * otro del mismo tipo, que es el caso en Divi y WPBakery.
	 *
	 * @param string $content Contenido completo.
	 * @param string $tag     Shortcode buscado.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function matches( string $content, string $tag ): array {
		$pattern = '/\[' . preg_quote( $tag, '/' ) . '\b([^\]]*)\](.*?)\[\/' . preg_quote( $tag, '/' ) . '\]/s';
		$found   = array();

		if ( ! preg_match_all( $pattern, $content, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}

		foreach ( $hits as $hit ) {
			$full  = (string) $hit[0][0];
			$attrs = (string) $hit[1][0];
			$inner = (string) $hit[2][0];

			$found[] = array(
				'full'   => $full,
				'inner'  => $inner,
				'offset' => (int) $hit[0][1],
				'open'   => '[' . $tag . $attrs . ']',
				'close'  => '[/' . $tag . ']',
			);
		}

		return $found;
	}

	/**
	 * Compone el identificador de un nodo.
	 *
	 * @param string $tag   Shortcode.
	 * @param int    $index Orden de aparición.
	 */
	private function node_id( string $tag, int $index ): string {
		return $tag . ':' . $index;
	}

	/**
	 * Descompone un identificador de nodo.
	 *
	 * @param string $node_id Identificador recibido.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private function parse_node_id( string $node_id ): ?array {
		if ( ! preg_match( '/^([a-z0-9_]+):(\d+)$/i', $node_id, $parts ) ) {
			return null;
		}

		return array( $parts[1], (int) $parts[2] );
	}

	/**
	 * Cuenta las aperturas de shortcode del contenido.
	 *
	 * @param string $content Contenido evaluado.
	 */
	private function count_shortcodes( string $content ): int {
		return preg_match_all( '/\[[a-z0-9_]+[^\]]*\]/i', $content );
	}
}
