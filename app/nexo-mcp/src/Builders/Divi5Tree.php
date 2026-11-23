<?php
/**
 * Ayudantes puros sobre el contenido de bloques de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Convierte el `post_content` de Divi 5 en un árbol de nodos direccionables.
 *
 * Divi 5 no guarda ids de nodo, así que cada nodo se identifica por dos datos:
 *
 * - `path`: índices dot-joined desde la raíz (`0.0.1.2`). El índice cuenta los
 *   hermanos del mismo nivel sin contar los bloques «freeform» vacíos o sólo
 *   con espacios que `parse_blocks()` crea entre bloques de primer nivel.
 * - `fingerprint`: sha256 de `serialize_block()` del nodo original (con sus
 *   hijos). Cambia si cambia cualquier cosa del nodo o de su subárbol, y es lo
 *   que las escrituras comparan para detectar que la página cambió desde la lectura.
 *
 * No toca la base de datos. Los ajustes de diseño de escritorio los cataloga
 * otra pieza; aquí sólo se lee contenido, enlace e imagen.
 */
final class Divi5Tree {

	/**
	 * Indica si el contenido tiene bloques `divi/`.
	 *
	 * @param string $content `post_content`.
	 */
	public static function is_divi5( string $content ): bool {
		return 1 === preg_match( '/<!--\s+wp:divi\//', $content );
	}

	/**
	 * Parsea el contenido y devuelve el árbol de nodos.
	 *
	 * @param string $content `post_content`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array();
		}

		return self::nodes( parse_blocks( $content ), '' );
	}

	/**
	 * Localiza un nodo del árbol por su ruta.
	 *
	 * @param array<int, array<string, mixed>> $tree Árbol de {@see self::build()}.
	 * @param string                           $path Ruta `0.1.2`.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function locate( array $tree, string $path ): ?array {
		$indices = self::indices( $path );
		$level   = $tree;
		$node    = null;

		if ( null === $indices ) {
			return null;
		}

		foreach ( $indices as $index ) {
			if ( ! isset( $level[ $index ] ) ) {
				return null;
			}

			$node  = $level[ $index ];
			$level = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		}

		return $node;
	}

	/**
	 * Localiza el bloque original (de `parse_blocks()`) por la misma ruta.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques sin filtrar.
	 * @param string                           $path   Ruta `0.1.2`.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function locate_block( array $blocks, string $path ): ?array {
		$indices = self::indices( $path );
		$block   = null;

		if ( null === $indices ) {
			return null;
		}

		foreach ( $indices as $index ) {
			$level = array_values( array_filter( $blocks, array( self::class, 'counts' ) ) );

			if ( ! isset( $level[ $index ] ) ) {
				return null;
			}

			$block  = $level[ $index ];
			$blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		}

		return $block;
	}

	/**
	 * Huella de un bloque: sha256 de su serialización con `serialize_block()`.
	 *
	 * @param array<string, mixed> $block Bloque de `parse_blocks()`.
	 */
	public static function fingerprint( array $block ): string {
		return hash( 'sha256', serialize_block( $block ) );
	}

	/**
	 * Comprueba que el nodo de esa ruta sigue teniendo la huella leída.
	 *
	 * @param array<int, array<string, mixed>> $tree        Árbol actual.
	 * @param string                           $path        Ruta del nodo.
	 * @param string                           $fingerprint Huella que leyó el cliente.
	 */
	public static function fingerprint_matches( array $tree, string $path, string $fingerprint ): bool {
		$node = self::locate( $tree, $path );

		return null !== $node && '' !== $fingerprint && hash_equals( (string) $node['fingerprint'], $fingerprint );
	}

	/**
	 * Indica si el bloque ocupa un índice: los freeform en blanco no.
	 *
	 * @param mixed $block Bloque.
	 */
	private static function counts( $block ): bool {
		if ( ! is_array( $block ) ) {
			return false;
		}

		return ! ( empty( $block['blockName'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) );
	}

	/**
	 * Descompone una ruta en índices, o null si no es válida.
	 *
	 * @param string $path Ruta.
	 *
	 * @return array<int, int>|null
	 */
	private static function indices( string $path ): ?array {
		if ( 1 !== preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			return null;
		}

		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Construye los nodos de un nivel.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques del nivel.
	 * @param string                           $prefix Ruta del padre.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function nodes( array $blocks, string $prefix ): array {
		$out   = array();
		$index = 0;

		foreach ( $blocks as $block ) {
			if ( ! self::counts( $block ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$node = array(
				'path'        => $path,
				'fingerprint' => self::fingerprint( $block ),
				'type'        => (string) ( $block['blockName'] ?? '' ),
			);

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$label = self::string_at( $attrs, array( 'module', 'meta', 'adminLabel', 'desktop', 'value' ) );
			$text  = self::text_of( $attrs );
			$link  = self::value_at( $attrs, array( 'button', 'innerContent', 'desktop', 'value' ) );
			$image = self::value_at( $attrs, array( 'image', 'innerContent', 'desktop', 'value' ) );

			if ( '' !== $label ) {
				$node['label'] = $label;
			}

			if ( '' !== $text ) {
				$node['text'] = $text;
			}

			if ( is_array( $link ) && is_string( $link['linkUrl'] ?? null ) && '' !== $link['linkUrl'] ) {
				$node['link'] = $link['linkUrl'];
			}

			if ( is_array( $image ) && is_string( $image['src'] ?? null ) && '' !== $image['src'] ) {
				$node['image'] = array(
					'src' => $image['src'],
					'alt' => is_string( $image['alt'] ?? null ) ? $image['alt'] : '',
				);
			}

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

			if ( array() !== $inner ) {
				$node['children'] = self::nodes( $inner, $path );
			}

			$out[] = $node;
			++$index;
		}

		return $out;
	}

	/**
	 * Texto legible: contenido, título o texto del botón, sin etiquetas.
	 *
	 * @param array<string, mixed> $attrs Atributos del bloque.
	 */
	private static function text_of( array $attrs ): string {
		$raw = self::string_at( $attrs, array( 'content', 'innerContent', 'desktop', 'value' ) );

		if ( '' === $raw ) {
			$raw = self::string_at( $attrs, array( 'title', 'innerContent', 'desktop', 'value' ) );
		}

		if ( '' === $raw ) {
			$button = self::value_at( $attrs, array( 'button', 'innerContent', 'desktop', 'value' ) );
			$raw    = is_array( $button ) && is_string( $button['text'] ?? null ) ? $button['text'] : '';
		}

		return trim( wp_strip_all_tags( $raw ) );
	}

	/**
	 * Lee una cadena en una ruta de claves, o '' si no está.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @param array<int, string>   $keys Claves.
	 */
	private static function string_at( array $data, array $keys ): string {
		$value = self::value_at( $data, $keys );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Lee un valor en una ruta de claves.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @param array<int, string>   $keys Claves.
	 *
	 * @return mixed
	 */
	private static function value_at( array $data, array $keys ) {
		$current = $data;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
				return null;
			}

			$current = $current[ $key ];
		}

		return $current;
	}
}
