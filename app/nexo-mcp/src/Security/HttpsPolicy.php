<?php
/**
 * Política de transporte seguro.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Bloquea transporte remoto inseguro.
 */
final class HttpsPolicy {

	/**
	 * Indica si el sitio está configurado completamente sobre HTTPS.
	 */
	public function is_configured(): bool {
		return wp_is_using_https();
	}

	/**
	 * Permite bypass sólo para desarrollo local expresamente configurado.
	 *
	 * La comprobación vive en {@see LocalEnvironment}: además de las dos
	 * constantes exige que la dirección del sitio no pueda existir en internet.
	 */
	public function allows_current_request(): bool {
		if ( $this->is_configured() && is_ssl() ) {
			return true;
		}

		return LocalEnvironment::bypass_allowed();
	}
}
