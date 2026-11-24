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
	 * Devuelve los bloques con el de esa ruta sustituido; null si la ruta no existe.
	 *
	 * Conserva el resto tal cual (incluidos los freeform en blanco y el orden de `innerContent`).
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques sin filtrar.
	 * @param string                           $path   Ruta `0.1.2`.
	 * @param array<string, mixed>             $block  Bloque nuevo.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function replace_block( array $blocks, string $path, array $block ): ?array {
		$indices = self::indices( $path );

		return null === $indices ? null : self::replace_at( $blocks, $indices, $block );
	}

	/**
	 * Sustituye `$remove` hijos de un nivel por bloques nuevos, sin tocar el resto.
	 *
	 * `$parent_path` vacío es el nivel superior del documento; `$index` cuenta como la ruta
	 * (sin los freeform en blanco). Del resto del documento sólo cambia la lista de hijos del
	 * padre: los hijos que quedan conservan sus bytes, y el espacio entre ellos se rehace en
	 * `innerContent` (en el nivel superior, como bloques freeform en blanco). Devuelve null si
	 * el padre o el rango no existen.
	 *
	 * @param array<int, array<string, mixed>> $blocks      Bloques sin filtrar.
	 * @param string                           $parent_path Ruta del padre, o '' para la raíz.
	 * @param int                              $index       Primer hijo que se quita o posición de inserción.
	 * @param int                              $remove      Cuántos hijos se quitan.
	 * @param array<int, array<string, mixed>> $added         Bloques nuevos.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function splice( array $blocks, string $parent_path, int $index, int $remove, array $added ): ?array {
		if ( '' === $parent_path ) {
			return self::splice_top( $blocks, $index, $remove, $added );
		}

		$indices = self::indices( $parent_path );

		return null === $indices ? null : self::splice_in( $blocks, $indices, $index, $remove, $added );
	}

	/**
	 * Cuenta los bloques que ocupan un índice, con todos sus descendientes.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques sin filtrar.
	 */
	public static function count_blocks( array $blocks ): int {
		$total = 0;

		foreach ( $blocks as $block ) {
			if ( ! self::counts( $block ) ) {
				continue;
			}

			++$total;
			$total += self::count_blocks( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array() );
		}

		return $total;
	}

	/**
	 * Lista plana `{path, type, fingerprint}` de un nodo y sus descendientes.
	 *
	 * @param array<string, mixed> $node Nodo de {@see self::build()}.
	 *
	 * @return array<int, array{path: string, type: string, fingerprint: string}>
	 */
	public static function summarize( array $node ): array {
		$out = array(
			array(
				'path'        => (string) $node['path'],
				'type'        => (string) $node['type'],
				'fingerprint' => (string) $node['fingerprint'],
			),
		);

		foreach ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() as $child ) {
			$out = array_merge( $out, self::summarize( $child ) );
		}

		return $out;
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
	 * Baja por los índices hasta el padre y cambia sus hijos.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Bloques del nivel.
	 * @param array<int, int>                  $indices Índices que faltan hasta el padre.
	 * @param int                              $index   Primer hijo.
	 * @param int                              $remove  Hijos que se quitan.
	 * @param array<int, array<string, mixed>> $added     Bloques nuevos.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function splice_in( array $blocks, array $indices, int $index, int $remove, array $added ): ?array {
		$wanted = array_shift( $indices );
		$seen   = 0;

		foreach ( $blocks as $key => $candidate ) {
			if ( ! self::counts( $candidate ) ) {
				continue;
			}

			if ( $seen++ !== $wanted ) {
				continue;
			}

			if ( array() === $indices ) {
				$changed = self::splice_children( $candidate, $index, $remove, $added );

				if ( null === $changed ) {
					return null;
				}

				$blocks[ $key ] = $changed;

				return $blocks;
			}

			$inner = isset( $candidate['innerBlocks'] ) && is_array( $candidate['innerBlocks'] ) ? $candidate['innerBlocks'] : array();
			$next  = self::splice_in( $inner, $indices, $index, $remove, $added );

			if ( null === $next ) {
				return null;
			}

			$blocks[ $key ]['innerBlocks'] = $next;

			return $blocks;
		}

		return null;
	}

	/**
	 * Cambia los hijos de un bloque y rehace su `innerContent`.
	 *
	 * @param array<string, mixed>             $block  Padre.
	 * @param int                              $index  Primer hijo.
	 * @param int                              $remove Hijos que se quitan.
	 * @param array<int, array<string, mixed>> $added    Bloques nuevos.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function splice_children( array $block, int $index, int $remove, array $added ): ?array {
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : array();
		$gaps  = array( '' );

		foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
			if ( is_string( $chunk ) ) {
				$gaps[ count( $gaps ) - 1 ] .= $chunk;
			} else {
				$gaps[] = '';
			}
		}

		$done = self::splice_gaps( $inner, $gaps, $index, $remove, $added, "\n" );

		if ( null === $done ) {
			return null;
		}

		list( $inner, $gaps ) = $done;

		if ( array() !== $inner && '' === $gaps[ count( $gaps ) - 1 ] ) {
			$gaps[ count( $gaps ) - 1 ] = "\n";
		}

		$content = array();

		foreach ( $gaps as $position => $gap ) {
			if ( '' !== $gap ) {
				$content[] = $gap;
			}

			if ( $position < count( $inner ) ) {
				$content[] = null;
			}
		}

		if ( array() === $inner && '' === trim( implode( '', $gaps ) ) ) {
			$content = array();
		}

		$block['innerBlocks']  = $inner;
		$block['innerContent'] = $content;
		$block['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );

		return $block;
	}

	/**
	 * Cambia los bloques del nivel superior; los freeform en blanco son el espacio entre ellos.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques del documento.
	 * @param int                              $index  Primer hijo.
	 * @param int                              $remove Hijos que se quitan.
	 * @param array<int, array<string, mixed>> $added    Bloques nuevos.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function splice_top( array $blocks, int $index, int $remove, array $added ): ?array {
		$items = array();
		$gaps  = array( '' );

		foreach ( $blocks as $block ) {
			if ( self::counts( $block ) ) {
				$items[] = $block;
				$gaps[]  = '';
				continue;
			}

			$gaps[ count( $gaps ) - 1 ] .= (string) ( $block['innerHTML'] ?? '' );
		}

		$done = self::splice_gaps( $items, $gaps, $index, $remove, $added, "\n\n" );

		if ( null === $done ) {
			return null;
		}

		list( $items, $gaps ) = $done;

		$out = array();

		foreach ( $gaps as $position => $gap ) {
			if ( '' !== $gap ) {
				$out[] = array(
					'blockName'    => null,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $gap,
					'innerContent' => array( $gap ),
				);
			}

			if ( $position < count( $items ) ) {
				$out[] = $items[ $position ];
			}
		}

		return $out;
	}

	/**
	 * Cambia hijos y espacios a la vez: quita el espacio previo de cada hijo quitado y
	 * pone un separador antes de cada hijo nuevo.
	 *
	 * @param array<int, array<string, mixed>> $items  Hijos actuales.
	 * @param array<int, string>               $gaps   Espacios (uno más que los hijos).
	 * @param int                              $index  Primer hijo.
	 * @param int                              $remove Hijos que se quitan.
	 * @param array<int, array<string, mixed>> $added    Bloques nuevos.
	 * @param string                           $sep    Separador de lo nuevo.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}|null
	 */
	private static function splice_gaps( array $items, array $gaps, int $index, int $remove, array $added, string $sep ): ?array {
		if ( $index < 0 || $remove < 0 || $index + $remove > count( $items ) || count( $gaps ) !== count( $items ) + 1 ) {
			return null;
		}

		array_splice( $items, $index, $remove, array_values( $added ) );
		array_splice( $gaps, $index, $remove );
		array_splice( $gaps, $index, 0, array_fill( 0, count( $added ), $sep ) );

		return array( $items, $gaps );
	}

	/**
	 * Sustituye el bloque de la ruta de índices, o devuelve null si no existe.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Bloques del nivel.
	 * @param array<int, int>                  $indices Índices que faltan.
	 * @param array<string, mixed>             $block   Bloque nuevo.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function replace_at( array $blocks, array $indices, array $block ): ?array {
		$wanted = array_shift( $indices );
		$seen   = 0;

		foreach ( $blocks as $key => $candidate ) {
			if ( ! self::counts( $candidate ) ) {
				continue;
			}

			if ( $seen++ !== $wanted ) {
				continue;
			}

			if ( array() === $indices ) {
				$blocks[ $key ] = $block;

				return $blocks;
			}

			$inner = isset( $candidate['innerBlocks'] ) && is_array( $candidate['innerBlocks'] ) ? $candidate['innerBlocks'] : array();
			$next  = self::replace_at( $inner, $indices, $block );

			if ( null === $next ) {
				return null;
			}

			$blocks[ $key ]['innerBlocks'] = $next;

			return $blocks;
		}

		return null;
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
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $path ) ) {
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

			$preset = self::preset_refs( $attrs );

			if ( array() !== $preset ) {
				$node['preset'] = $preset;
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
	 * Referencias a presets de un bloque: `{module?: [ids], groups?: {<groupId>: [ids]}}` (vacío si no hay ninguna).
	 *
	 * `modulePreset` puede ser una cadena o una lista; `groupPreset` guarda `presetId` por id de grupo.
	 *
	 * @param array<string, mixed> $attrs Atributos del bloque.
	 *
	 * @return array<string, mixed>
	 */
	private static function preset_refs( array $attrs ): array {
		$out    = array();
		$module = self::ids( $attrs['modulePreset'] ?? null );

		if ( array() !== $module ) {
			$out['module'] = $module;
		}

		$groups = isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ? $attrs['groupPreset'] : array();
		$found  = array();

		foreach ( $groups as $group_id => $entry ) {
			$ids = is_array( $entry ) ? self::ids( $entry['presetId'] ?? null ) : array();

			if ( array() !== $ids ) {
				$found[ (string) $group_id ] = $ids;
			}
		}

		if ( array() !== $found ) {
			$out['groups'] = $found;
		}

		return $out;
	}

	/**
	 * Lista de ids de preset de una cadena o una lista (sin vacíos).
	 *
	 * @param mixed $value Valor guardado.
	 *
	 * @return array<int, string>
	 */
	private static function ids( $value ): array {
		$list = is_array( $value ) ? $value : array( $value );

		return array_values( array_filter( $list, static fn( $id ): bool => is_string( $id ) && '' !== $id ) );
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
