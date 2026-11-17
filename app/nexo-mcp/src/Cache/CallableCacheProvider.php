<?php
/**
 * Proveedor de caché definido por funciones.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Cache;

/**
 * Adapta un plugin de caché a partir de tres funciones.
 *
 * Evita una clase por plugin cuando lo único que cambia entre ellos es a qué
 * función llamar.
 */
final class CallableCacheProvider implements CacheProvider {

	/**
	 * Identificador estable.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Nombre legible.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Comprueba la presencia del plugin.
	 *
	 * @var callable
	 */
	private $detector;

	/**
	 * Purga de un contenido.
	 *
	 * @var callable
	 */
	private $por_contenido;

	/**
	 * Purga total.
	 *
	 * @var callable
	 */
	private $total;

	/**
	 * Construye el proveedor.
	 *
	 * @param string   $slug          Identificador.
	 * @param string   $label         Nombre legible.
	 * @param callable $detector      Comprobación de presencia.
	 * @param callable $por_contenido Purga por contenido.
	 * @param callable $total         Purga total.
	 */
	public function __construct(
		string $slug,
		string $label,
		callable $detector,
		callable $por_contenido,
		callable $total
	) {
		$this->slug          = $slug;
		$this->label         = $label;
		$this->detector      = $detector;
		$this->por_contenido = $por_contenido;
		$this->total         = $total;
	}

	/**
	 * Identificador estable.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Nombre legible.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Presencia del plugin.
	 */
	public function is_active(): bool {
		return (bool) call_user_func( $this->detector );
	}

	/**
	 * Purga lo correspondiente a un contenido.
	 *
	 * @param int $post_id Contenido modificado.
	 */
	public function purge_post( int $post_id ): bool {
		return (bool) call_user_func( $this->por_contenido, $post_id );
	}

	/**
	 * Purga el sitio completo.
	 */
	public function purge_all(): bool {
		return (bool) call_user_func( $this->total );
	}
}
