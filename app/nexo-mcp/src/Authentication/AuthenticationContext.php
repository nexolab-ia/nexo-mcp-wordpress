<?php
/**
 * Contexto de Application Password por request.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authentication;

/**
 * Captura sólo metadatos no secretos de la credencial autenticada.
 */
final class AuthenticationContext {

	/**
	 * UUID no secreto de la credencial del request.
	 *
	 * @var string|null
	 */
	private static ?string $credential_uuid = null;

	/**
	 * Registra el hook oficial de autenticación.
	 */
	public function register(): void {
		add_action(
			'application_password_did_authenticate',
			array( $this, 'capture' ),
			10,
			2
		);
	}

	/**
	 * Captura el UUID sin conservar hash ni secreto.
	 *
	 * @param \WP_User             $user Usuario autenticado.
	 * @param array<string, mixed> $item Metadatos de Application Password.
	 */
	public function capture( \WP_User $user, array $item ): void {
		unset( $user );

		$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';

		self::$credential_uuid = wp_is_uuid( $uuid ) ? $uuid : null;
	}

	/**
	 * Obtiene el UUID autenticado durante el request actual.
	 */
	public static function credential_uuid(): ?string {
		return self::$credential_uuid;
	}
}
