<?php
/**
 * Validación de los valores que puede tomar un atributo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Convierte lo que manda el cliente en un valor seguro de escribir.
 *
 * Cada método devuelve el valor ya normalizado o un WP_Error. Nada se escribe
 * sin pasar por aquí: es el único punto donde se decide si una URL o una
 * imagen pueden entrar en la página de alguien.
 */
final class AttributeValue {

	/**
	 * Esquemas que nunca se admiten, aunque un filtro los anuncie.
	 *
	 * Se comprueban aparte de la allowlist porque son el vector de ejecución
	 * clásico de un `href`, y una lista permisiva mal configurada no debe poder
	 * abrirlos. `wp_kses` los filtra en el contenido, pero el árbol de un
	 * constructor no pasa por ahí.
	 *
	 * @var array<int, string>
	 */
	private const NEVER = array( 'javascript', 'data', 'vbscript', 'file', 'blob' );

	/**
	 * Impide instanciar el validador.
	 */
	private function __construct() {
	}

	/**
	 * Valida el destino de un enlace.
	 *
	 * Admite rutas del propio sitio y URL absolutas de esquema permitido. Una
	 * cadena vacía es válida y significa quitar el enlace.
	 *
	 * @param string $valor URL recibida.
	 * @return string|\WP_Error
	 */
	public static function link( string $valor ) {
		$valor = trim( $valor );

		if ( '' === $valor ) {
			return '';
		}

		if ( strlen( $valor ) > 2000 ) {
			return new \WP_Error(
				'nexo_mcp_attr_link_too_long',
				__( 'La dirección del enlace es demasiado larga.', 'nexo-mcp' )
			);
		}

		// Se mira antes de nada y sobre el valor sin espacios ni saltos, que es
		// como se cuela `java\nscript:` en los filtros ingenuos.
		$compacto = strtolower( preg_replace( '/[\s\x00-\x1F]+/', '', $valor ) ?? '' );

		foreach ( self::NEVER as $prohibido ) {
			if ( str_starts_with( $compacto, $prohibido . ':' ) ) {
				return new \WP_Error(
					'nexo_mcp_attr_link_denied',
					sprintf(
						/* translators: %s: esquema rechazado. */
						__( 'El esquema «%s» no se admite en un enlace: puede ejecutar código en el navegador de quien visite la página.', 'nexo-mcp' ),
						$prohibido
					)
				);
			}
		}

		// Ruta interna: se admite tal cual, no puede salir del sitio.
		if ( str_starts_with( $valor, '/' ) && ! str_starts_with( $valor, '//' ) ) {
			return esc_url_raw( $valor );
		}

		$esquema = strtolower( (string) wp_parse_url( $valor, PHP_URL_SCHEME ) );

		if ( '' === $esquema ) {
			return new \WP_Error(
				'nexo_mcp_attr_link_relative',
				__( 'Usa una dirección completa con https:// o una ruta que empiece por «/».', 'nexo-mcp' )
			);
		}

		if ( ! in_array( $esquema, EditableAttributes::schemes(), true ) ) {
			return new \WP_Error(
				'nexo_mcp_attr_link_scheme',
				sprintf(
					/* translators: 1: esquema recibido, 2: lista de esquemas admitidos. */
					__( 'El esquema «%1$s» no está permitido. Se admiten: %2$s.', 'nexo-mcp' ),
					$esquema,
					implode( ', ', EditableAttributes::schemes() )
				)
			);
		}

		$limpia = esc_url_raw( $valor, EditableAttributes::schemes() );

		if ( '' === $limpia ) {
			return new \WP_Error(
				'nexo_mcp_attr_link_invalid',
				__( 'La dirección del enlace no es válida.', 'nexo-mcp' )
			);
		}

		return $limpia;
	}

	/**
	 * Valida una imagen y devuelve sus datos.
	 *
	 * Se recibe el identificador de la biblioteca de medios, nunca una URL: una
	 * dirección arbitraria permitiría cargar un recurso de un tercero en la
	 * página del propietario, con lo que eso implica de rastreo y de contenido
	 * que él no controla. Con identificador sólo entra lo que ya está en su
	 * propio sitio.
	 *
	 * @param int $id Identificador del adjunto.
	 * @return array{id:int,url:string,alt:string}|\WP_Error
	 */
	public static function image( int $id ) {
		if ( $id < 1 ) {
			return new \WP_Error(
				'nexo_mcp_attr_image_invalid',
				__( 'Indica el identificador de una imagen de la biblioteca de medios.', 'nexo-mcp' )
			);
		}

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_attr_image_missing',
				__( 'No existe ningún medio con ese identificador.', 'nexo-mcp' )
			);
		}

		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_attr_image_not_image',
				__( 'El medio indicado no es una imagen.', 'nexo-mcp' )
			);
		}

		$url = wp_get_attachment_url( $id );

		if ( ! is_string( $url ) || '' === $url ) {
			return new \WP_Error(
				'nexo_mcp_attr_image_unavailable',
				__( 'La imagen existe pero no tiene un archivo accesible.', 'nexo-mcp' )
			);
		}

		return array(
			'id'  => $id,
			'url' => $url,
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Valida un texto alternativo.
	 *
	 * @param string $valor Texto recibido.
	 * @return string|\WP_Error
	 */
	public static function alt( string $valor ) {
		$limpio = sanitize_text_field( $valor );

		if ( strlen( $limpio ) > 500 ) {
			return new \WP_Error(
				'nexo_mcp_attr_alt_too_long',
				__( 'El texto alternativo no puede superar los 500 caracteres.', 'nexo-mcp' )
			);
		}

		return $limpio;
	}
}
