<?php
/**
 * Contrato de los proveedores de campos personalizados.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Fields;

/**
 * Lee los campos personalizados de una entrada.
 *
 * ACF, Meta Box, Pods y JetEngine resuelven lo mismo con claves y formatos
 * distintos. El conector no expone `post_meta` en crudo —eso permitiría pisar
 * claves internas de WordPress— sino sólo lo que el plugin de campos reconoce
 * como suyo.
 */
interface FieldProvider {

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string;

	/**
	 * Nombre legible del plugin que gobierna los campos.
	 */
	public function label(): string;

	/**
	 * Indica si el plugin está activo en el sitio.
	 */
	public function is_active(): bool;

	/**
	 * Lee los campos definidos para una entrada.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<int, FieldValue>
	 */
	public function read( int $post_id ): array;
}
