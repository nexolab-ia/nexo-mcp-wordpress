<?php
/**
 * Persistencia del historial de actividad.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Audit;

/**
 * Aísla las consultas de auditoría.
 */
final class AuditRepository {

	/**
	 * Inserta una entrada ya sanitizada.
	 *
	 * @param array<string, mixed> $entry Datos de auditoría.
	 */
	public function insert( array $entry ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Tabla propia de auditoría, sin API de Core.
		return false !== $wpdb->insert(
			$wpdb->prefix . 'nexo_mcp_audit_log',
			$entry,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f' )
		);
	}

	/**
	 * Obtiene entradas recientes sin exponer contenido completo.
	 *
	 * @param int $limit Máximo de entradas.
	 * @return list<object>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$table = $wpdb->prefix . 'nexo_mcp_audit_log';
		$sql   = $wpdb->prepare(
			'SELECT id, occurred_at, user_id, tool, operation, status, result_summary
			FROM %i
			ORDER BY id DESC
			LIMIT %d',
			$table,
			$limit
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql fue preparado con placeholders arriba.
		return (array) $wpdb->get_results( $sql );
	}
}
