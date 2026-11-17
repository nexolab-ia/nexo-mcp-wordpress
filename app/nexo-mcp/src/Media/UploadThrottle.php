<?php
/**
 * Techo de subidas compartido por todas las vías de carga.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Media;

use NexoDev\NexoMCP\Security\ClientIpResolver;
use NexoDev\NexoMCP\Security\RateLimiter;

/**
 * Limita cuántos archivos puede dejar en disco una misma identidad.
 *
 * Vive aparte del transporte porque sus 240 llamadas por minuto están pensadas
 * para lecturas encadenadas, y aquí cada llamada escribe en disco.
 *
 * Lo importante es que el contador sea **el mismo** para el endpoint REST y
 * para la herramienta MCP: si cada vía llevara el suyo, alternarlas duplicaría
 * la cuota y el techo dejaría de significar nada.
 */
final class UploadThrottle {

	/**
	 * Ámbito compartido en la tabla de límites.
	 */
	private const SCOPE = 'media-upload';

	/**
	 * Subidas permitidas por minuto e identidad.
	 */
	private const POR_MINUTO = 30;

	/**
	 * Limitador persistente.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Resolución de IP.
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $ip_resolver;

	/**
	 * Construye el techo.
	 *
	 * @param RateLimiter|null $limiter Limitador persistente.
	 */
	public function __construct( ?RateLimiter $limiter = null ) {
		$this->limiter     = $limiter ?? new RateLimiter();
		$this->ip_resolver = new ClientIpResolver();
	}

	/**
	 * Consume una subida de la ventana en curso.
	 *
	 * @return true|\WP_Error
	 */
	public function consume() {
		$identidad = (string) get_current_user_id() . '|' . ( $this->ip_resolver->resolve() ?? 'unknown' );

		/**
		 * Permite ajustar el techo de subidas por minuto.
		 *
		 * @param int $limit Subidas permitidas por minuto.
		 */
		$por_minuto = (int) apply_filters( 'nexo_mcp_media_upload_rate_limit', self::POR_MINUTO );
		$resultado  = $this->limiter->consume( $identidad, self::SCOPE, $por_minuto, MINUTE_IN_SECONDS );

		if ( is_wp_error( $resultado ) ) {
			return $resultado;
		}

		if ( ! $resultado->allowed ) {
			return new \WP_Error(
				'nexo_mcp_rate_limit_exceeded',
				__( 'Demasiadas subidas seguidas. Intenta nuevamente en un minuto.', 'nexo-mcp' ),
				array(
					'status'      => 429,
					'retry_after' => $resultado->retry_after,
				)
			);
		}

		return true;
	}
}
