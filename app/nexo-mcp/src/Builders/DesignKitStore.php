<?php
/**
 * Lectura y guardado de las listas de colores y tipografía del Kit.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lo único que `DesignKitOperations` necesita de Elementor.
 *
 * Las listas son `system_colors`, `custom_colors`, `system_typography` y `custom_typography`,
 * con la forma que guarda Elementor (cada item con su `_id`).
 */
interface DesignKitStore {

	/**
	 * Las cuatro listas vigentes del Kit (las de fábrica si nunca se guardaron).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function lists(): array;

	/**
	 * Guarda listas completas (reemplaza, no fusiona) sin tocar el resto de ajustes del Kit.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists Sólo las listas que cambian, por clave.
	 *
	 * @throws \Throwable Si Elementor no guarda lo pedido.
	 */
	public function save( array $lists ): void;
}
