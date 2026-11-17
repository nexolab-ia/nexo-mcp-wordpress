<?php
/**
 * Verificación de desafíos PKCE.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

/**
 * Implementa el método S256 de Proof Key for Code Exchange.
 *
 * Sólo se admite S256. El método `plain` está permitido por RFC 7636 pero
 * OAuth 2.1 lo desaconseja y no aporta protección real ante la captura del
 * authorization code.
 */
final class Pkce {

	/**
	 * Único método de desafío admitido.
	 */
	public const METHOD = 'S256';

	/**
	 * Longitud mínima de un verificador según RFC 7636.
	 */
	private const MIN_VERIFIER_LENGTH = 43;

	/**
	 * Longitud máxima de un verificador según RFC 7636.
	 */
	private const MAX_VERIFIER_LENGTH = 128;

	/**
	 * Impide instanciar la utilidad.
	 */
	private function __construct() {
	}

	/**
	 * Calcula el desafío correspondiente a un verificador.
	 *
	 * @param string $verifier Verificador generado por el cliente.
	 */
	public static function challenge_for( string $verifier ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación base64url exigida por RFC 7636, no ofuscación.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Indica si el método de desafío es admisible.
	 *
	 * @param string $method Método declarado por el cliente.
	 */
	public static function is_supported_method( string $method ): bool {
		return self::METHOD === $method;
	}

	/**
	 * Valida el formato de un verificador.
	 *
	 * @param string $verifier Verificador recibido.
	 */
	public static function is_valid_verifier( string $verifier ): bool {
		$length = strlen( $verifier );

		if ( $length < self::MIN_VERIFIER_LENGTH || $length > self::MAX_VERIFIER_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9\-._~]+$/', $verifier );
	}

	/**
	 * Comprueba un verificador contra el desafío registrado.
	 *
	 * La comparación es de tiempo constante para no filtrar información por
	 * diferencias de duración entre intentos.
	 *
	 * @param string $verifier  Verificador recibido en el canje.
	 * @param string $challenge Desafío almacenado con el código.
	 */
	public static function verify( string $verifier, string $challenge ): bool {
		if ( '' === $challenge || ! self::is_valid_verifier( $verifier ) ) {
			return false;
		}

		return hash_equals( $challenge, self::challenge_for( $verifier ) );
	}
}
