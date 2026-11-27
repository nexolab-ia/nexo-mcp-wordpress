<?php
/**
 * Respaldo de un ítem de la biblioteca de Divi 5 previo a cada cambio.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Una sola copia por ítem, la del último estado bueno, en la meta {@see self::META_KEY} del propio ítem: título, estado,
 * contenido, categorías y módulo. Un ítem de la biblioteca no pasa por {@see BuilderBackup} (esa herramienta de restauración
 * no acepta tipos `et_*`): el conector lo restaura con `restore-divi-library-item`.
 */
final class DiviLibraryBackup {

	public const META_KEY = '_nexo_mcp_library_backup';

	/**
	 * Estado actual de un ítem como respaldo.
	 *
	 * @param \WP_Post                                                                                $post      Ítem.
	 * @param string                                                                                  $operation `save`, `delete` o `restore`.
	 * @param array{type: string, scope: string, categories: array<int, string>, module_type: string} $read      Lo que leyó el esquema.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot( \WP_Post $post, string $operation, array $read ): array {
		return array(
			'operation'   => $operation,
			'captured_at' => gmdate( 'Y-m-d H:i:s' ),
			'title'       => (string) $post->post_title,
			'status'      => (string) $post->post_status,
			'content'     => (string) $post->post_content,
			'categories'  => $read['categories'],
			'module_type' => $read['module_type'],
		);
	}

	/**
	 * Guarda el respaldo.
	 *
	 * @param int                  $id       Ítem.
	 * @param array<string, mixed> $snapshot Estado de {@see self::snapshot()}.
	 */
	public function save( int $id, array $snapshot ): bool {
		$encoded = wp_json_encode( $snapshot );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		if ( false !== update_post_meta( $id, self::META_KEY, wp_slash( $encoded ) ) ) {
			return true;
		}

		// `false` también significa «ya estaba igual»: el respaldo que se quería ya está.
		return (string) get_post_meta( $id, self::META_KEY, true ) === $encoded;
	}

	/**
	 * Lee el respaldo; null si no hay o no es un respaldo válido.
	 *
	 * @param int $id Ítem.
	 *
	 * @return array{operation: string, captured_at: string, title: string, status: string, content: string, categories: array<int, string>, module_type: string}|null
	 */
	public function read( int $id ): ?array {
		$raw = get_post_meta( $id, self::META_KEY, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		foreach ( array( 'operation', 'captured_at', 'title', 'status', 'content', 'module_type' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return null;
			}
		}

		if ( ! isset( $data['categories'] ) || ! is_array( $data['categories'] ) ) {
			return null;
		}

		return array(
			'operation'   => $data['operation'],
			'captured_at' => $data['captured_at'],
			'title'       => $data['title'],
			'status'      => $data['status'],
			'content'     => $data['content'],
			'categories'  => array_values( array_filter( $data['categories'], 'is_string' ) ),
			'module_type' => $data['module_type'],
		);
	}

	/**
	 * Qué respaldo hay, sin su contenido.
	 *
	 * @param int $id Ítem.
	 *
	 * @return array{operation: string, captured_at: string}|null
	 */
	public function describe( int $id ): ?array {
		$backup = $this->read( $id );

		return null === $backup ? null : array(
			'operation'   => $backup['operation'],
			'captured_at' => $backup['captured_at'],
		);
	}
}
