<?php
/**
 * Respaldo previo a cada escritura de constructor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Conserva el estado anterior de un contenido antes de modificarlo.
 *
 * Es la pieza que hace admisible todo el módulo. Las revisiones de WordPress
 * **no guardan `post_meta`**: si el conector escribiera un `_elementor_data`
 * malformado, la página quedaría rota sin historial que restaurar. Divi y
 * WPBakery sí quedan cubiertos por las revisiones al vivir en `post_content`,
 * pero se respaldan igual para que la restauración funcione igual en los tres.
 *
 * Se guarda una sola copia por contenido, la del último estado bueno conocido.
 * Acumular el historial completo en `post_meta` haría crecer la tabla sin límite.
 */
final class BuilderBackup {

	/**
	 * Clave donde se conserva el estado anterior.
	 */
	public const META_KEY = '_nexo_mcp_builder_backup';

	/**
	 * Guarda el estado actual antes de modificarlo.
	 *
	 * @param \WP_Post       $post    Contenido a modificar.
	 * @param BuilderAdapter $adapter Constructor que gobierna el contenido.
	 */
	public function capture( \WP_Post $post, BuilderAdapter $adapter ): bool {
		$snapshot = array(
			'builder'      => $adapter->slug(),
			'captured_at'  => gmdate( 'Y-m-d H:i:s' ),
			'post_content' => (string) $post->post_content,
			'meta'         => array(),
		);

		foreach ( $this->tracked_meta( $adapter ) as $key ) {
			$snapshot['meta'][ $key ] = get_post_meta( $post->ID, $key, true );
		}

		$encoded = wp_json_encode( $snapshot );

		if ( ! is_string( $encoded ) ) {
			return false;
		}

		return false !== update_post_meta( $post->ID, self::META_KEY, wp_slash( $encoded ) );
	}

	/**
	 * Indica si un contenido tiene respaldo disponible.
	 *
	 * @param int $post_id Contenido consultado.
	 */
	public function exists( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * Entrega los metadatos del respaldo sin su contenido.
	 *
	 * @param int $post_id Contenido consultado.
	 *
	 * @return array<string, string>|null
	 */
	public function describe( int $post_id ): ?array {
		$snapshot = $this->read( $post_id );

		if ( null === $snapshot ) {
			return null;
		}

		return array(
			'builder'     => (string) ( $snapshot['builder'] ?? '' ),
			'captured_at' => (string) ( $snapshot['captured_at'] ?? '' ),
		);
	}

	/**
	 * Restaura el último estado guardado.
	 *
	 * @param \WP_Post $post Contenido restaurado.
	 *
	 * @return true|\WP_Error
	 */
	public function restore( \WP_Post $post ) {
		$snapshot = $this->read( $post->ID );

		if ( null === $snapshot ) {
			return new \WP_Error(
				'nexo_mcp_builder_no_backup',
				__( 'Este contenido no tiene un respaldo disponible.', 'nexo-mcp' )
			);
		}

		if ( isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ) {
			foreach ( $snapshot['meta'] as $key => $value ) {
				if ( ! is_string( $key ) ) {
					continue;
				}

				if ( '' === $value || null === $value ) {
					delete_post_meta( $post->ID, $key );
					continue;
				}

				update_post_meta( $post->ID, $key, wp_slash( (string) $value ) );
			}
		}

		if ( isset( $snapshot['post_content'] ) ) {
			$saved = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => (string) $snapshot['post_content'],
				),
				true
			);

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return true;
	}

	/**
	 * Decodifica el respaldo guardado.
	 *
	 * @param int $post_id Contenido consultado.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read( int $post_id ): ?array {
		$raw = (string) get_post_meta( $post_id, self::META_KEY, true );

		if ( '' === $raw ) {
			return null;
		}

		$snapshot = json_decode( $raw, true );

		return is_array( $snapshot ) ? $snapshot : null;
	}

	/**
	 * Entrega las claves de metadatos que cada constructor necesita conservar.
	 *
	 * @param BuilderAdapter $adapter Constructor evaluado.
	 *
	 * @return array<int, string>
	 */
	private function tracked_meta( BuilderAdapter $adapter ): array {
		switch ( $adapter->slug() ) {
			case 'elementor':
				return array( '_elementor_data', '_elementor_edit_mode', '_elementor_version' );
			case 'divi':
				return array( '_et_pb_use_builder', '_et_pb_old_content' );
			case 'wpbakery':
				return array( '_wpb_vc_js_status' );
			default:
				return array();
		}
	}
}
