<?php
/**
 * Saneamiento de valores de campos personalizados.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

use NexoDev\NexoMCP\Security\ContentSanitizer;

/**
 * Limpia el valor de un campo según su tipo declarado.
 *
 * Un saneamiento único para todos los tipos no sirve: `sanitize_text_field()`
 * sobre un campo de texto enriquecido borra el formato que el cliente acaba de
 * escribir, y no aplicar nada convierte ese mismo campo en un hueco por donde
 * entra HTML arbitrario. Los tipos que guardan identificadores se comprueban
 * contra la base, porque un ID inventado deja la plantilla pintando un vacío.
 */
final class FieldSanitizer {

	/**
	 * Saneamiento del contenido enriquecido.
	 *
	 * @var ContentSanitizer
	 */
	private ContentSanitizer $content;

	/**
	 * Construye el saneador.
	 *
	 * @param ContentSanitizer|null $content Saneador de contenido.
	 */
	public function __construct( ?ContentSanitizer $content = null ) {
		$this->content = $content ?? new ContentSanitizer();
	}

	/**
	 * Limpia un valor según el tipo del campo.
	 *
	 * @param string $type  Tipo declarado por el plugin de campos.
	 * @param mixed  $value Valor recibido del cliente.
	 * @return mixed|\WP_Error
	 */
	public function clean( string $type, $value ) {
		switch ( $type ) {
			case 'text':
			case 'select':
			case 'radio':
			case 'button_group':
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'wysiwyg':
				return $this->content->sanitize( (string) $value );

			case 'number':
			case 'range':
				if ( ! is_numeric( $value ) ) {
					return new \WP_Error(
						'nexo_mcp_field_not_numeric',
						__( 'El campo espera un número.', 'nexo-mcp' )
					);
				}

				return 0 === (int) $value - $value ? (int) $value : (float) $value;

			case 'true_false':
				return (bool) $value ? 1 : 0;

			case 'email':
				$email = sanitize_email( (string) $value );

				if ( '' !== (string) $value && '' === $email ) {
					return new \WP_Error(
						'nexo_mcp_field_invalid_email',
						__( 'El campo espera un correo válido.', 'nexo-mcp' )
					);
				}

				return $email;

			case 'url':
				$url = esc_url_raw( (string) $value, array( 'http', 'https', 'mailto', 'tel' ) );

				if ( '' !== (string) $value && '' === $url ) {
					return new \WP_Error(
						'nexo_mcp_field_invalid_url',
						__( 'El campo espera una URL con un esquema admitido.', 'nexo-mcp' )
					);
				}

				return $url;

			case 'image':
			case 'file':
				return $this->referencia_adjunto( $value );

			case 'post_object':
			case 'page_link':
				return $this->referencia_contenido( $value );

			case 'taxonomy':
				return $this->referencia_termino( $value );

			case 'user':
				return $this->referencia_usuario( $value );
		}

		return new \WP_Error(
			'nexo_mcp_field_type_denied',
			FieldTypes::reason( $type ),
			array( 'type' => $type )
		);
	}

	/**
	 * Comprueba que el identificador apunte a un adjunto real.
	 *
	 * @param mixed $value Valor recibido.
	 * @return int|\WP_Error
	 */
	private function referencia_adjunto( $value ) {
		$id = (int) $value;

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_field_attachment_missing',
				__( 'El campo espera el identificador de un archivo de la biblioteca de medios.', 'nexo-mcp' )
			);
		}

		return $id;
	}

	/**
	 * Comprueba que el identificador apunte a un contenido real.
	 *
	 * @param mixed $value Valor recibido.
	 * @return int|\WP_Error
	 */
	private function referencia_contenido( $value ) {
		$id = (int) $value;

		if ( $id <= 0 || ! get_post( $id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'nexo_mcp_field_post_missing',
				__( 'El campo espera el identificador de un contenido existente.', 'nexo-mcp' )
			);
		}

		return $id;
	}

	/**
	 * Comprueba que el identificador apunte a un término real.
	 *
	 * @param mixed $value Valor recibido.
	 * @return int|\WP_Error
	 */
	private function referencia_termino( $value ) {
		$id = (int) $value;

		if ( $id <= 0 || ! term_exists( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_field_term_missing',
				__( 'El campo espera el identificador de un término existente.', 'nexo-mcp' )
			);
		}

		return $id;
	}

	/**
	 * Comprueba que el identificador apunte a un usuario real.
	 *
	 * @param mixed $value Valor recibido.
	 * @return int|\WP_Error
	 */
	private function referencia_usuario( $value ) {
		$id = (int) $value;

		if ( $id <= 0 || false === get_userdata( $id ) ) {
			return new \WP_Error(
				'nexo_mcp_field_user_missing',
				__( 'El campo espera el identificador de un usuario existente.', 'nexo-mcp' )
			);
		}

		return $id;
	}
}
