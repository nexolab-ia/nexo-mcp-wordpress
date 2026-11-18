<?php
/**
 * Acceso de las operaciones de clases globales a Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lo único que `DesignClassOperations` necesita de Elementor, para poder probarlo sin él.
 */
interface DesignClassBackend {

	/**
	 * Propiedades de estilo que Elementor conoce (`Style_Schema::get()`).
	 *
	 * @return array<int, string>
	 */
	public function style_props(): array;

	/**
	 * Valida y sanea un estilo con el analizador de Elementor (`Style_Parser`).
	 *
	 * @param array<string, mixed> $style Estilo `{id, type, label, variants}`.
	 *
	 * @return array<string, mixed>|\WP_Error Estilo saneado, o error con `path` en los datos (ruta relativa al estilo, p. ej. `variants[0].props.color`).
	 */
	public function parse_style( array $style );

	/**
	 * Clases actuales: items por id y orden.
	 *
	 * @return array{items: array<string, array<string, mixed>>, order: array<int, string>}
	 */
	public function read(): array;

	/**
	 * Páginas donde se usa una clase.
	 *
	 * @param string $id Id de la clase.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public function used_in( string $id ): array;

	/**
	 * Escribe sólo los items tocados con `Global_Classes_Repository::apply_changes`.
	 *
	 * @param array<string, array<string, mixed>> $touched Items nuevos o modificados, por id.
	 * @param array<string, mixed>                $changes `{added, deleted, modified, order}`.
	 * @param array<int, string>                  $order   Orden final.
	 *
	 * @throws \Throwable Si Elementor no pudo escribir.
	 */
	public function write( array $touched, array $changes, array $order ): void;
}
