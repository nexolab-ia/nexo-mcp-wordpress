<?php
/**
 * Resultado inmutable de rate limiting.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Transporta decisión, uso y reintento.
 */
final class RateLimitResult {

	/**
	 * Decisión final.
	 *
	 * @var bool
	 */
	public bool $allowed;

	/**
	 * Consumo actual.
	 *
	 * @var int
	 */
	public int $hits;

	/**
	 * Máximo de la ventana.
	 *
	 * @var int
	 */
	public int $limit;

	/**
	 * Segundos restantes.
	 *
	 * @var int
	 */
	public int $retry_after;

	/**
	 * Construye el resultado.
	 *
	 * @param bool $allowed     Indica si puede continuar.
	 * @param int  $hits        Consumo de la ventana.
	 * @param int  $limit       Máximo permitido.
	 * @param int  $retry_after Segundos hasta una nueva ventana.
	 */
	public function __construct( bool $allowed, int $hits, int $limit, int $retry_after ) {
		$this->allowed     = $allowed;
		$this->hits        = $hits;
		$this->limit       = $limit;
		$this->retry_after = $retry_after;
	}
}
