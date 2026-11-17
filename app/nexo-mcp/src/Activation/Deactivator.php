<?php
/**
 * Desactivación no destructiva.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Activation;

use NexoDev\NexoMCP\Admin\LicenseController;

/**
 * Gestiona la desactivación sin eliminar datos.
 */
final class Deactivator {

	/**
	 * Desactiva el plugin conservando la configuración.
	 */
	public static function deactivate(): void {
		// La configuración se conserva para permitir una reactivación segura.
		// Sólo se retiran las tareas programadas, que no pueden quedar huérfanas.
		$timestamp = wp_next_scheduled( 'nexo_mcp_oauth_purge' );

		if ( false !== $timestamp ) {
			wp_unschedule_event( (int) $timestamp, 'nexo_mcp_oauth_purge' );
		}

		// La licencia no se libera al desactivar: desactivar es a menudo un paso
		// de una actualización o de una prueba, y soltar el cupo cada vez
		// obligaría a reactivar por gusto. Para devolverlo está el botón de
		// desconectar del panel.
		LicenseController::unschedule();
	}
}
