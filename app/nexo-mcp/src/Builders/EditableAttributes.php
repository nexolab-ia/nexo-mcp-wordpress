<?php
/**
 * Allowlist de atributos editables por constructor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide qué atributos admite cada tipo de elemento.
 *
 * Va aparte de {@see EditableTypes} porque el riesgo es distinto. Cambiar un
 * texto altera lo que se lee; cambiar un atributo altera a dónde va el
 * visitante o qué recurso carga su navegador. Por eso los atributos tienen su
 * propio scope y su propia lista, y un tipo que admite texto no admite
 * atributos por herencia.
 */
final class EditableAttributes {

	/**
	 * Destino de un enlace.
	 */
	public const LINK = 'link';

	/**
	 * Imagen, siempre por identificador de la biblioteca de medios.
	 */
	public const IMAGE = 'image';

	/**
	 * Texto alternativo de una imagen.
	 */
	public const ALT = 'alt';

	/**
	 * Atributos admitidos por cada widget de Elementor.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const ELEMENTOR = array(
		'heading'        => array( self::LINK ),
		'button'         => array( self::LINK ),
		'image'          => array( self::IMAGE, self::ALT, self::LINK ),
		'image-box'      => array( self::IMAGE, self::ALT, self::LINK ),
		'icon-box'       => array( self::LINK ),
		'call-to-action' => array( self::IMAGE, self::LINK ),
		'testimonial'    => array( self::IMAGE ),
	);

	/**
	 * Atributos admitidos por cada bloque del editor nativo.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GUTENBERG = array(
		'core/button' => array( self::LINK ),
		'core/image'  => array( self::IMAGE, self::ALT, self::LINK ),
	);

	/**
	 * Atributos admitidos en el HTML de las columnas de Flatsome.
	 *
	 * Un texto dentro de un enlace llega con tipo `a`, así que su destino se
	 * edita desde el propio texto.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FLATSOME = array(
		'a'   => array( self::LINK ),
		'img' => array( self::IMAGE, self::ALT, self::LINK ),
	);

	/**
	 * Esquemas de URL admitidos en un enlace.
	 *
	 * `javascript:`, `data:` y `vbscript:` quedan fuera por definición: un href
	 * es un vector de ejecución en el navegador de quien visita la página, así
	 * que la lista es cerrada y no se amplía por comodidad.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMES = array( 'https', 'mailto', 'tel' );

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}

	/**
	 * Indica si un tipo admite un atributo concreto.
	 *
	 * @param string $builder   Slug del constructor.
	 * @param string $type      Tipo o bloque evaluado.
	 * @param string $attribute Atributo solicitado.
	 */
	public static function allows( string $builder, string $type, string $attribute ): bool {
		// Un tipo vedado por admitir código no gana atributos por otra puerta.
		if ( EditableTypes::is_forbidden( $type ) ) {
			return false;
		}

		return in_array( $attribute, self::for_type( $builder, $type ), true );
	}

	/**
	 * Entrega los atributos admitidos por un tipo.
	 *
	 * @param string $builder Slug del constructor.
	 * @param string $type    Tipo o bloque evaluado.
	 *
	 * @return array<int, string>
	 */
	public static function for_type( string $builder, string $type ): array {
		if ( EditableTypes::is_forbidden( $type ) ) {
			return array();
		}

		switch ( $builder ) {
			case 'elementor':
				return self::ELEMENTOR[ $type ] ?? array();
			case 'gutenberg':
				return self::GUTENBERG[ $type ] ?? array();
			case 'flatsome':
				return self::FLATSOME[ $type ] ?? array();
			default:
				return array();
		}
	}

	/**
	 * Entrega los esquemas de URL admitidos.
	 *
	 * @return array<int, string>
	 */
	public static function schemes(): array {
		/**
		 * Permite ampliar los esquemas admitidos en un enlace.
		 *
		 * No se anuncian `javascript` ni `data` a propósito: aunque un filtro los
		 * añadiera, {@see AttributeValue::link()} los rechaza aparte.
		 *
		 * @param array<int, string> $schemes Esquemas admitidos.
		 */
		$schemes = apply_filters( 'nexo_mcp_builder_link_schemes', self::SCHEMES );

		return is_array( $schemes ) && array() !== $schemes ? $schemes : self::SCHEMES;
	}
}
