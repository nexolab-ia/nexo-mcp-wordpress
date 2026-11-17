<?php
/**
 * Contrato de los proveedores SEO.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Lee y escribe los metadatos SEO de una entrada.
 *
 * Cada plugin SEO guarda lo suyo con sus propias claves y formatos. Escribir en
 * los metadatos del conector cuando el sitio usa otro plugin no cambiaba nada de
 * lo que ve un buscador: la herramienta informaba éxito y el título seguía
 * saliendo del otro plugin.
 */
interface SeoProvider {

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string;

	/**
	 * Nombre legible, para explicar quién manda en este sitio.
	 */
	public function label(): string;

	/**
	 * Indica si este proveedor está gobernando el SEO del sitio.
	 */
	public function is_active(): bool;

	/**
	 * Indica si el proveedor admite JSON-LD propio del conector.
	 */
	public function supports_schema(): bool;

	/**
	 * Lee los campos SEO de una entrada.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array;

	/**
	 * Escribe sólo los campos presentes.
	 *
	 * @param int                  $post_id Entrada a modificar.
	 * @param array<string, mixed> $fields  Campos ya saneados.
	 */
	public function write( int $post_id, array $fields ): void;
}
