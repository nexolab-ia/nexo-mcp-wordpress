<?php
/**
 * Política de tipos de campo admitidos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

/**
 * Decide qué tipos de campo puede tocar el conector y con qué saneamiento.
 *
 * La lista es blanca a propósito. Un tipo desconocido no se escribe: puede venir
 * de un complemento de terceros con un formato propio, y escribirlo a ciegas
 * deja el dato corrupto y la plantilla que lo pinta caída.
 */
final class FieldTypes {

	/**
	 * Tipos de texto plano.
	 */
	private const TEXTO = array( 'text', 'textarea', 'select', 'radio', 'button_group' );

	/**
	 * Tipos con validación propia.
	 */
	private const ESPECIALES = array( 'number', 'range', 'email', 'url', 'true_false', 'wysiwyg' );

	/**
	 * Tipos que guardan referencias a otros objetos de WordPress.
	 */
	private const REFERENCIA = array( 'image', 'file', 'post_object', 'page_link', 'taxonomy', 'user' );

	/**
	 * Estructuras anidadas que la primera versión no escribe.
	 *
	 * Un repetidor mal escrito no falla de forma visible: guarda un contador de
	 * filas que deja de cuadrar con las filas reales, y a partir de ahí el campo
	 * devuelve datos incompletos sin error.
	 */
	private const ANIDADOS = array( 'repeater', 'flexible_content', 'group', 'clone', 'gallery', 'relationship' );

	/**
	 * Indica si el tipo admite escritura.
	 *
	 * @param string $type Tipo declarado por el plugin.
	 */
	public static function writable( string $type ): bool {
		return in_array( $type, self::TEXTO, true )
			|| in_array( $type, self::ESPECIALES, true )
			|| in_array( $type, self::REFERENCIA, true );
	}

	/**
	 * Indica si el tipo es una estructura anidada.
	 *
	 * @param string $type Tipo declarado por el plugin.
	 */
	public static function nested( string $type ): bool {
		return in_array( $type, self::ANIDADOS, true );
	}

	/**
	 * Indica si el tipo guarda una referencia a otro objeto.
	 *
	 * @param string $type Tipo declarado por el plugin.
	 */
	public static function reference( string $type ): bool {
		return in_array( $type, self::REFERENCIA, true );
	}

	/**
	 * Explica por qué un tipo no admite escritura.
	 *
	 * @param string $type Tipo declarado por el plugin.
	 */
	public static function reason( string $type ): string {
		if ( self::nested( $type ) ) {
			return __( 'Estructura anidada: se lee pero no se escribe desde el conector.', 'nexo-mcp' );
		}

		return __( 'Tipo de campo no reconocido: no se escribe para no corromper el dato.', 'nexo-mcp' );
	}
}
