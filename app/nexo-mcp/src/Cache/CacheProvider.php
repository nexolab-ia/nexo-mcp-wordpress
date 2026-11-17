<?php
/**
 * Contrato de los plugins de caché reconocidos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Cache;

/**
 * Invalida la caché de página tras una escritura.
 *
 * Sin esto el conector informa éxito y el visitante sigue viendo lo anterior,
 * que es el peor resultado posible: no hay error que perseguir y el dueño del
 * sitio concluye que la herramienta no funciona. Cada plugin expone su propia
 * forma de purgar, así que se reconoce uno por uno en vez de suponer una API
 * común que no existe.
 */
interface CacheProvider {

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string;

	/**
	 * Nombre legible del plugin de caché.
	 */
	public function label(): string;

	/**
	 * Indica si el plugin está activo en el sitio.
	 */
	public function is_active(): bool;

	/**
	 * Purga sólo lo que corresponde a un contenido.
	 *
	 * Se prefiere siempre a la purga total: rehacer el sitio entero porque
	 * cambió un párrafo obliga a regenerar cada página y hunde el tiempo de
	 * respuesta durante minutos en sitios con volumen.
	 *
	 * @param int $post_id Contenido modificado.
	 */
	public function purge_post( int $post_id ): bool;

	/**
	 * Purga el sitio completo.
	 *
	 * Reservado para cambios globales: CSS adicional, identidad o menús, que
	 * afectan a todas las páginas a la vez.
	 */
	public function purge_all(): bool;
}
