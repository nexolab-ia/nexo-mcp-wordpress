<?php
/**
 * Contrato de acceso a las variables globales de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lo que `DesignVariableOperations` necesita de Elementor: leer la colección, escribir
 * una variable y buscar dónde se usa. La implementación real es `DesignVariableElementorStore`;
 * PHPUnit usa una en memoria.
 */
interface DesignVariableStore {

	/**
	 * Toda la colección, borradas incluidas, por id.
	 *
	 * @return array<string, array{type: string, label: string, value: string, deleted: bool}>
	 */
	public function variables(): array;

	/**
	 * Crea una variable (todo o nada).
	 *
	 * @param string $type  Tipo de Elementor.
	 * @param string $label Etiqueta.
	 * @param string $value Valor ya validado.
	 *
	 * @return array{id: string, type: string, label: string, value: string}
	 */
	public function create( string $type, string $label, string $value ): array;

	/**
	 * Modifica una variable.
	 *
	 * @param string                $id      Id de la variable.
	 * @param array<string, string> $changes Sólo `label`, `value` y/o `type`.
	 *
	 * @return array{id: string, type: string, label: string, value: string}
	 */
	public function update( string $id, array $changes ): array;

	/**
	 * Borrado lógico de Elementor (`deleted_at`).
	 *
	 * @param string $id Id de la variable.
	 */
	public function delete( string $id ): void;

	/**
	 * Contenidos de Elementor (borradores y Kit incluidos) cuyo `_elementor_data` nombra la variable.
	 *
	 * @param string $id Id de la variable.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public function used_in( string $id ): array;
}
