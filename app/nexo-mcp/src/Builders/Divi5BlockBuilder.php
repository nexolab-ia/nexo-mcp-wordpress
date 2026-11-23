<?php
/**
 * Convierte el árbol validado del cliente en bloques de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Clase pura: del árbol `[{type, attrs, children}]` que devuelve
 * {@see DiviTreeValidator::validate_tree()} a bloques con la forma de `parse_blocks()`.
 *
 * Cada módulo lleva `builderVersion` (la versión de Divi instalada) primero en sus atributos,
 * salvo `divi/placeholder`, que Divi guarda sin atributos. Los hijos se separan con saltos de
 * línea, como el contenido que escribe Divi.
 */
final class Divi5BlockBuilder {

	/**
	 * Versión de Divi instalada, o '' si no se puede saber.
	 */
	public static function installed_version(): string {
		if ( defined( 'ET_BUILDER_VERSION' ) ) {
			return (string) constant( 'ET_BUILDER_VERSION' );
		}

		$theme = wp_get_theme( 'Divi' );

		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	/**
	 * Arma los bloques de una lista de nodos validados.
	 *
	 * @param array<int, array<string, mixed>> $nodes   Nodos validados.
	 * @param string                           $version Valor de `builderVersion` ('' para omitirlo).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( array $nodes, string $version ): array {
		$out = array();

		foreach ( $nodes as $node ) {
			$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();

			if ( '' !== $version && 'divi/placeholder' !== $node['type'] ) {
				$attrs = array_merge( array( 'builderVersion' => $version ), $attrs );
			}

			$inner   = self::build( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array(), $version );
			$content = array();

			$total = count( $inner );

			for ( $i = 0; $i < $total; $i++ ) {
				$content[] = "\n";
				$content[] = null;
			}

			if ( array() !== $inner ) {
				$content[] = "\n";
			}

			$out[] = array(
				'blockName'    => (string) $node['type'],
				'attrs'        => $attrs,
				'innerBlocks'  => $inner,
				'innerHTML'    => array() === $inner ? '' : str_repeat( "\n", count( $inner ) + 1 ),
				'innerContent' => $content,
			);
		}

		return $out;
	}
}
