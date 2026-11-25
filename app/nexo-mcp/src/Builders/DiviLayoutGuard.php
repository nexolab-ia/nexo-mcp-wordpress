<?php
/**
 * Guarda de tipo de contenido de las herramientas de constructor: layouts del Theme Builder y demás tipos `et_*`.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Un único punto que decide qué hacen las herramientas de constructor con un contenido según su tipo.
 *
 * - Un layout del Theme Builder (`et_header_layout`, `et_body_layout`, `et_footer_layout`, o una copia de trabajo cuyo
 *   original lo sea) se muestra en TODO el sitio: sólo se lee y se escribe con `PermissionPolicy::can_write_divi_theme_builder()`
 *   (S5), nunca con el `edit_post` de una página. La lectura pide lo mismo que la escritura: no hay scope de sólo
 *   lectura del Theme Builder, el contenido es del administrador y un cliente que sólo lee no lo necesita.
 * - Cualquier otro tipo `et_*` (`et_template`, `et_theme_builder`, `et_pb_layout` —la biblioteca llega en D8b—,
 *   `et_code_snippet`…) se rechaza siempre: no es una página que estas herramientas sepan editar.
 * - Lo demás (páginas, entradas…) no cambia: sigue mandando el permiso de siempre.
 *
 * Se aplica en dos capas: los permisos de las herramientas ({@see \NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar} y
 * `PermissionPolicy`) y, de nuevo, al resolver el contenido ({@see BuilderGateway}, {@see Divi5ContentWriter},
 * `ContentAdminGateway`), de modo que una llamada directa al gateway tampoco lo salta.
 */
final class DiviLayoutGuard {

	public const KIND_NONE     = 'none';
	public const KIND_LAYOUT   = 'layout';
	public const KIND_REJECTED = 'rejected';

	public const CODE_DENIED = 'nexo_mcp_divi_layout_denied';
	public const CODE_TYPE   = 'nexo_mcp_divi_post_type_denied';

	/**
	 * Tipos de contenido de los layouts del Theme Builder, por ranura.
	 *
	 * @var array<string, string>
	 */
	public const LAYOUT_TYPES = array(
		'header' => 'et_header_layout',
		'body'   => 'et_body_layout',
		'footer' => 'et_footer_layout',
	);

	/**
	 * Clasifica un tipo de contenido.
	 *
	 * @param string $post_type Tipo de contenido.
	 */
	public static function kind_of_type( string $post_type ): string {
		if ( in_array( $post_type, self::LAYOUT_TYPES, true ) ) {
			return self::KIND_LAYOUT;
		}

		return str_starts_with( $post_type, 'et_' ) ? self::KIND_REJECTED : self::KIND_NONE;
	}

	/**
	 * Clasifica un contenido por su tipo y por el de su original (si es una copia de trabajo): manda lo más restrictivo.
	 *
	 * @param \WP_Post $post Contenido.
	 */
	public static function kind( \WP_Post $post ): string {
		$kinds = array( self::kind_of_type( $post->post_type ) );
		$orig  = WorkingCopy::original_of( $post );

		if ( $orig > 0 && $orig !== (int) $post->ID ) {
			$original = get_post( $orig );

			if ( $original instanceof \WP_Post ) {
				$kinds[] = self::kind_of_type( $original->post_type );
			}
		}

		if ( in_array( self::KIND_REJECTED, $kinds, true ) ) {
			return self::KIND_REJECTED;
		}

		return in_array( self::KIND_LAYOUT, $kinds, true ) ? self::KIND_LAYOUT : self::KIND_NONE;
	}

	/**
	 * Clasifica un contenido por su id (un id que no existe no es nada).
	 *
	 * @param int $post_id Contenido.
	 */
	public static function kind_of_id( int $post_id ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		return $post instanceof \WP_Post ? self::kind( $post ) : self::KIND_NONE;
	}

	/**
	 * Indica si el contenido es un layout del Theme Builder (o la copia de uno).
	 *
	 * @param \WP_Post $post Contenido.
	 */
	public static function is_layout( \WP_Post $post ): bool {
		return self::KIND_LAYOUT === self::kind( $post );
	}

	/**
	 * Ranura (`header`, `body`, `footer`) de un layout o de la copia de uno, o null si no lo es.
	 *
	 * @param \WP_Post $post Contenido.
	 */
	public static function slot_of( \WP_Post $post ): ?string {
		$slot = array_search( $post->post_type, self::LAYOUT_TYPES, true );

		if ( false === $slot ) {
			$orig     = WorkingCopy::original_of( $post );
			$original = $orig > 0 ? get_post( $orig ) : null;
			$slot     = $original instanceof \WP_Post ? array_search( $original->post_type, self::LAYOUT_TYPES, true ) : false;
		}

		return false === $slot ? null : (string) $slot;
	}

	/**
	 * Comprueba si las herramientas de constructor pueden tocar este contenido.
	 *
	 * @param \WP_Post      $post           Contenido.
	 * @param callable|null $layout_allowed fn(): bool. Permiso del Theme Builder; sin él un layout se deniega.
	 *
	 * @return true|\WP_Error
	 */
	public static function check( \WP_Post $post, ?callable $layout_allowed ) {
		switch ( self::kind( $post ) ) {
			case self::KIND_REJECTED:
				return self::type_error();
			case self::KIND_LAYOUT:
				return null !== $layout_allowed && $layout_allowed() ? true : self::denied();
			default:
				return true;
		}
	}

	/**
	 * Error de un layout sin el permiso del Theme Builder.
	 */
	public static function denied(): \WP_Error {
		return new \WP_Error(
			self::CODE_DENIED,
			__( 'Los layouts del Theme Builder de Divi (encabezado, cuerpo y pie) se muestran en todo el sitio: sólo se leen y se editan con el permiso del Theme Builder (scope mcp:divi_theme_builder con su interruptor encendido y un administrador con el permiso theme_builder de Divi), no con el permiso de una página.', 'nexo-mcp' )
		);
	}

	/**
	 * Error de un tipo `et_*` que no es un layout del Theme Builder.
	 */
	public static function type_error(): \WP_Error {
		return new \WP_Error(
			self::CODE_TYPE,
			__( 'Este contenido es de un tipo interno de Divi (plantilla del Theme Builder, biblioteca u otro) que las herramientas de página no editan. Sólo se editan páginas, entradas y los layouts del Theme Builder (encabezado, cuerpo y pie).', 'nexo-mcp' )
		);
	}

	/**
	 * Indica si el contenido tiene algún `divi/post-content` a cualquier profundidad.
	 *
	 * @param string $content Contenido serializado.
	 */
	public static function has_post_content( string $content ): bool {
		return self::blocks_have_post_content( parse_blocks( $content ) );
	}

	/**
	 * Indica si alguno de los bloques (o sus descendientes) es `divi/post-content`.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques de `parse_blocks()`.
	 */
	public static function blocks_have_post_content( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'divi/post-content' === ( $block['blockName'] ?? null ) ) {
				return true;
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::blocks_have_post_content( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}
}
