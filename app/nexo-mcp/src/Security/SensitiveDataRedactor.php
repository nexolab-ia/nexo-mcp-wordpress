<?php
/**
 * Redacción antes de persistir auditoría.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Elimina secretos conocidos y limita el volumen registrado.
 */
final class SensitiveDataRedactor {

	private const REDACTED = '[REDACTED]';

	/**
	 * Resume un valor como JSON seguro.
	 *
	 * @param mixed $value Valor a resumir.
	 */
	public function summarize( $value ): string {
		$redacted = $this->redact( $value );
		$json     = wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return '{"summary":"unavailable"}';
		}

		return mb_substr( $json, 0, 4000 );
	}

	/**
	 * Recorre estructuras y reemplaza campos sensibles.
	 *
	 * @param mixed       $value Valor actual.
	 * @param string|null $key   Clave asociada.
	 * @return mixed
	 */
	private function redact( $value, ?string $key = null ) {
		if ( null !== $key && preg_match( '/pass|password|secret|token|authorization|cookie|api[_-]?key/i', $key ) ) {
			return self::REDACTED;
		}

		// El contenido de un archivo no es un secreto, pero medio kilobyte de
		// base64 por subida deja la auditoría ilegible sin aportar nada: para
		// revisar qué pasó basta con saber que venía y cuánto pesaba.
		if ( null !== $key && 'content_base64' === strtolower( $key ) && is_string( $value ) ) {
			return sprintf( '[%d bytes en base64]', strlen( $value ) );
		}

		if ( is_array( $value ) ) {
			$result = array();

			foreach ( array_slice( $value, 0, 100, true ) as $child_key => $child_value ) {
				$result[ $child_key ] = $this->redact( $child_value, (string) $child_key );
			}

			return $result;
		}

		/*
		 * Un error resumido como «{"type":"WP_Error"}» dice que algo falló y nada
		 * más, que es justo lo que no sirve al revisar la auditoría: el motivo se
		 * perdía en la única fila donde importaba. El código y el mensaje son
		 * texto redactado por el propio plugin, no datos del usuario, y el
		 * truncado de abajo los acota igual.
		 */
		if ( $value instanceof \WP_Error ) {
			return array(
				'type'    => 'WP_Error',
				'code'    => $value->get_error_code(),
				'message' => mb_substr( $value->get_error_message(), 0, 500 ),
			);
		}

		if ( is_object( $value ) ) {
			return array( 'type' => get_class( $value ) );
		}

		// El enlace de subida lleva el ticket en la URL, en `upload_url` y en
		// `curl` (ADR-030). En la base sólo puede quedar su hash.
		if ( is_string( $value ) ) {
			return mb_substr( (string) preg_replace( '/([?&]ticket=)[^&"\s]+/i', '$1' . self::REDACTED, $value ), 0, 500 );
		}

		return $value;
	}
}
