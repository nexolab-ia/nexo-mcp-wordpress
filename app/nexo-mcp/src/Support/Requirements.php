<?php
/**
 * Validación de requisitos de ejecución.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Support;

/**
 * Comprueba que el runtime soporte Abilities API.
 */
final class Requirements {

	public const MINIMUM_PHP = '8.1';

	public const MINIMUM_WORDPRESS = '6.9';

	/**
	 * Indica si todos los requisitos están disponibles.
	 */
	public function is_satisfied(): bool {
		return array() === $this->get_errors();
	}

	/**
	 * Obtiene mensajes de requisitos incumplidos.
	 *
	 * @return list<string>
	 */
	public function get_errors(): array {
		global $wp_version;

		$errors = array();

		if ( version_compare( PHP_VERSION, self::MINIMUM_PHP, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: minimum PHP version. */
				__( 'Nexo MCP requiere PHP %s o superior.', 'nexo-mcp' ),
				self::MINIMUM_PHP
			);
		}

		if ( version_compare( (string) $wp_version, self::MINIMUM_WORDPRESS, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: minimum WordPress version. */
				__( 'Nexo MCP requiere WordPress %s o superior.', 'nexo-mcp' ),
				self::MINIMUM_WORDPRESS
			);
		}

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$errors[] = __(
				'La Abilities API de WordPress no está disponible en esta instalación.',
				'nexo-mcp'
			);
		}

		// La auditoría recorta cada resultado con mb_substr, así que sin esta
		// extensión el conector cae con un error fatal en la primera escritura
		// en vez de avisar al activarse.
		if ( ! extension_loaded( 'mbstring' ) ) {
			$errors[] = __(
				'Nexo MCP requiere la extensión PHP mbstring.',
				'nexo-mcp'
			);
		}

		return $errors;
	}
}
