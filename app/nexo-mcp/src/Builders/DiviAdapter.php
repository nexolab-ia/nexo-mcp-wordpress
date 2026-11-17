<?php
/**
 * Adaptador de Divi.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Interpreta los shortcodes `et_pb_*` que Divi guarda en `post_content`.
 */
final class DiviAdapter extends ShortcodeBuilderAdapter {

	/**
	 * Entrega el identificador del constructor.
	 */
	public function slug(): string {
		return 'divi';
	}

	/**
	 * Entrega el nombre legible.
	 */
	public function label(): string {
		return 'Divi';
	}

	/**
	 * Shortcode que delata al constructor.
	 */
	protected function signature(): string {
		return 'et_pb_section';
	}

	/**
	 * Shortcodes de Divi que admiten código ejecutable.
	 *
	 * @return array<int, string>
	 */
	protected function forbidden_tags(): array {
		return array( 'et_pb_code', 'et_pb_fullwidth_code' );
	}

	/**
	 * Purga la caché estática que genera Divi.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		parent::flush( $post );

		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache();
		}
	}
}
