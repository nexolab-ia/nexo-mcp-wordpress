<?php
/**
 * Rate limiter atómico respaldado por base de datos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Security;

/**
 * Aplica ventanas fijas por identidad y ámbito.
 */
final class RateLimiter {

	/**
	 * Consume una unidad de la ventana.
	 *
	 * @param string $identity       Identidad ya normalizada.
	 * @param string $scope          Ámbito de la operación.
	 * @param int    $limit          Máximo permitido.
	 * @param int    $window_seconds Duración de la ventana.
	 * @return RateLimitResult|\WP_Error
	 */
	public function consume(
		string $identity,
		string $scope,
		int $limit,
		int $window_seconds
	) {
		global $wpdb;

		$limit          = max( 1, $limit );
		$window_seconds = max( 1, $window_seconds );
		$now            = time();
		$window_number  = intdiv( $now, $window_seconds );
		$window_start   = $window_number * $window_seconds;
		$bucket_key     = hash( 'sha256', $identity . '|' . $scope . '|' . $window_number );
		$table          = $wpdb->prefix . 'nexo_mcp_rate_limits';

		$sql = $wpdb->prepare(
			'INSERT INTO %i (bucket_key, window_start, hits, expires_at)
			VALUES (%s, %s, 1, %s)
			ON DUPLICATE KEY UPDATE hits = hits + 1',
			$table,
			$bucket_key,
			gmdate( 'Y-m-d H:i:s', $window_start ),
			gmdate( 'Y-m-d H:i:s', $window_start + $window_seconds + 60 )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql fue preparado con placeholders arriba.
		if ( false === $wpdb->query( $sql ) ) {
			return new \WP_Error(
				'nexo_mcp_rate_limit_unavailable',
				__( 'No fue posible evaluar el límite de solicitudes.', 'nexo-mcp' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura inmediata del contador atómico.
		$hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT hits FROM %i WHERE bucket_key = %s',
				$table,
				$bucket_key
			)
		);

		$retry_after = max( 1, ( $window_start + $window_seconds ) - $now );

		return new RateLimitResult( $hits <= $limit, $hits, $limit, $retry_after );
	}
}
