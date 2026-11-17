<?php
/**
 * Adaptador de WPBakery.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Interpreta los shortcodes `vc_*` que WPBakery guarda en `post_content`.
 */
final class WpBakeryAdapter extends ShortcodeBuilderAdapter {

	/**
	 * Entrega el identificador del constructor.
	 */
	public function slug(): string {
		return 'wpbakery';
	}

	/**
	 * Entrega el nombre legible.
	 */
	public function label(): string {
		return 'WPBakery';
	}

	/**
	 * Shortcode que delata al constructor.
	 */
	protected function signature(): string {
		return 'vc_row';
	}

	/**
	 * Shortcodes de WPBakery que admiten código ejecutable.
	 *
	 * @return array<int, string>
	 */
	protected function forbidden_tags(): array {
		return array( 'vc_raw_html', 'vc_raw_js', 'vc_shortcode' );
	}
}
