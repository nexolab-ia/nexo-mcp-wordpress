<?php
/**
 * Resolución conservadora de IP.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * No confía en headers reenviados salvo opt-in explícito.
 */
final class ClientIpResolver {

	/**
	 * Obtiene una IP validada o null.
	 */
	public function resolve(): ?string {
		$remote_address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Permite reemplazar REMOTE_ADDR en infraestructura con proxy confiable.
		 *
		 * @param string $remote_address IP observada por PHP.
		 */
		$candidate = (string) apply_filters( 'nexo_mcp_client_ip', $remote_address );

		return false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : null;
	}
}
