<?php
/**
 * Reglas de anidado de los elementos atómicos nuevos dentro de un documento de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Qué se puede colgar de cada tipo de padre (clase pura).
 *
 * Elementor casi no valida el anidado en el servidor, pero lo que no encaja se
 * pierde en silencio o rompe el editor: una columna v3 descarta los contenedores
 * atómicos, una sección v3 trata a todo hijo como columna, y el editor y el MCP
 * de Elementor no admiten widgets sueltos en la raíz. Aquí se cierra de antemano.
 */
final class ElementorNesting {

	public const ROOT      = 'root';
	public const SECTION   = 'section';
	public const COLUMN    = 'column';
	public const CONTAINER = 'container';
	public const ATOMIC    = 'atomic';
	public const WIDGET    = 'widget';
	public const UNKNOWN   = 'unknown';

	/**
	 * Clase de padre de un elemento guardado.
	 *
	 * @param array<string, mixed> $element Elemento del documento.
	 */
	public static function kind_of( array $element ): string {
		return self::kind_of_type( (string) ( $element['elType'] ?? '' ) );
	}

	/**
	 * Clase de padre a partir del `elType` guardado (o `root`).
	 *
	 * @param string $el_type Valor de `elType`.
	 */
	public static function kind_of_type( string $el_type ): string {
		if ( in_array( $el_type, ElementorTreeValidator::CONTAINERS, true ) ) {
			return self::ATOMIC;
		}

		switch ( $el_type ) {
			case 'root':
				return self::ROOT;
			case 'section':
				return self::SECTION;
			case 'column':
				return self::COLUMN;
			case 'container':
				return self::CONTAINER;
			case 'widget':
				return self::WIDGET;
			default:
				return self::UNKNOWN;
		}
	}

	/**
	 * Motivo por el que un tipo atómico nuevo no cabe bajo un padre, o null si cabe.
	 *
	 * @param string $parent_kind Clase del padre (constantes de esta clase).
	 * @param string $type        Tipo atómico nuevo (`e-flexbox`, `e-heading`…).
	 */
	public static function reason( string $parent_kind, string $type ): ?string {
		$is_container = in_array( $type, ElementorTreeValidator::CONTAINERS, true );

		switch ( $parent_kind ) {
			case self::ROOT:
				if ( $is_container ) {
					return null;
				}

				return sprintf(
					/* translators: 1: tipo del elemento, 2: contenedores admitidos. */
					__( 'en la raíz de la página sólo se admiten contenedores atómicos (%2$s); «%1$s» va dentro de uno.', 'nexo-mcp' ),
					$type,
					implode( ', ', ElementorTreeValidator::CONTAINERS )
				);

			case self::COLUMN:
				if ( ! $is_container ) {
					return null;
				}

				return sprintf(
					/* translators: %s: tipo del elemento. */
					__( 'una columna de Elementor 3 descarta en silencio los contenedores atómicos («%s»): ahí sólo van widgets atómicos. Para un contenedor, reemplaza la sección completa.', 'nexo-mcp' ),
					$type
				);

			case self::SECTION:
				return sprintf(
					/* translators: %s: tipo del elemento. */
					__( 'una sección de Elementor 3 sólo admite columnas: «%s» no cabe dentro. Inserta en una de sus columnas o reemplaza la sección completa.', 'nexo-mcp' ),
					$type
				);

			case self::CONTAINER:
			case self::ATOMIC:
				return null;

			case self::WIDGET:
				return sprintf(
					/* translators: %s: tipo del elemento. */
					__( 'un widget no admite hijos: «%s» no cabe dentro. Usa before o after, o apunta a un contenedor.', 'nexo-mcp' ),
					$type
				);

			default:
				return __( 'no se reconoce el tipo del elemento donde iría; no se inserta nada.', 'nexo-mcp' );
		}
	}
}
