<?php
/**
 * Almacén del sistema de diseño: lo que `DesignSystemWriter` respalda y restaura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Contrato mínimo entre el escritor (bloqueo, respaldo, deshacer) y Elementor.
 *
 * Los tipos de estado son `classes`, `variables` y `kit`. Un estado es opaco para
 * el escritor: sólo lo que `snapshot()` entrega es lo que `restore()` acepta.
 */
interface DesignSystemStore {

	/**
	 * Indica si Elementor está cargado.
	 */
	public function available(): bool;

	/**
	 * Indica si los elementos atómicos (Editor V4) están activos.
	 */
	public function atomic(): bool;

	/**
	 * Id del Kit activo; 0 si no hay.
	 */
	public function kit_id(): int;

	/**
	 * Estado completo actual de un tipo (classes, variables o kit).
	 *
	 * @param string $kind Tipo.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot( string $kind ): array;

	/**
	 * Devuelve un tipo exactamente al estado que entregó `snapshot()`.
	 *
	 * @param string               $kind  Tipo.
	 * @param array<string, mixed> $state Estado previo.
	 *
	 * @throws \Throwable Si no se pudo restaurar.
	 */
	public function restore( string $kind, array $state ): void;

	/**
	 * Descarta el CSS generado para que Elementor lo regenere.
	 */
	public function clear_cache(): void;
}
