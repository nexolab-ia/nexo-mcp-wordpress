<?php
/**
 * Allowlist de tipos editables por constructor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide qué elementos de un constructor admiten edición de texto.
 *
 * La lista es explícita y cerrada, igual que la allowlist de herramientas del
 * conector. El motivo es concreto: Elementor, Divi y WPBakery incluyen
 * elementos que aceptan HTML, shortcodes o JavaScript. Permitir escribir en
 * ellos convertiría el conector en ejecución remota de código, que es
 * exactamente la superficie que el resto del plugin evita.
 *
 * Un tipo desconocido se rechaza. Añadir uno nuevo es una decisión deliberada,
 * no una consecuencia de que el constructor publique otro widget.
 */
final class EditableTypes {

	/**
	 * Tipos de Elementor que admiten edición de texto.
	 *
	 * @var array<int, string>
	 */
	private const ELEMENTOR = array(
		'heading',
		'text-editor',
		'button',
		'icon-box',
		'image-box',
		'testimonial',
		'call-to-action',
		'price-table',
		'toggle',
		'accordion',
		'tabs',
		'alert',
		'blockquote',
		'counter',
		'progress',
		'star-rating',
	);

	/**
	 * Shortcodes de Divi que admiten edición de texto.
	 *
	 * @var array<int, string>
	 */
	private const DIVI = array(
		'et_pb_text',
		'et_pb_blurb',
		'et_pb_cta',
		'et_pb_testimonial',
		'et_pb_toggle',
		'et_pb_accordion_item',
		'et_pb_tab',
		'et_pb_slide',
		'et_pb_team_member',
		'et_pb_number_counter',
		'et_pb_pricing_item',
	);

	/**
	 * Shortcodes de WPBakery que admiten edición de texto.
	 *
	 * @var array<int, string>
	 */
	private const WPBAKERY = array(
		'vc_column_text',
		'vc_custom_heading',
		'vc_toggle',
		'vc_tta_section',
		'vc_message',
		'vc_cta',
		'vc_call_to_action',
		'vc_single_image',
	);

	/**
	 * Tipos vedados de forma explícita por admitir código ejecutable.
	 *
	 * Se listan aparte de la allowlist para dejar constancia del motivo y para
	 * que una revisión futura no los agregue por descuido.
	 *
	 * @var array<int, string>
	 */
	private const NEVER = array(
		// Elementor.
		'html',
		'shortcode',
		'code-highlight',
		// Divi.
		'et_pb_code',
		'et_pb_fullwidth_code',
		// WPBakery.
		'vc_raw_html',
		'vc_raw_js',
		'vc_shortcode',
		// Flatsome.
		'ux_html',
		// Editor de bloques: admiten marcado o shortcodes arbitrarios.
		'core/html',
		'core/shortcode',
		'core/code',
		'core/freeform',
	);

	/**
	 * Bloques del editor nativo que admiten edición de texto.
	 *
	 * Sólo los que exponen texto plano al visitante. Quedan fuera los que
	 * envuelven otros bloques —grupos, columnas— porque su texto vive en los
	 * hijos, y los que aceptan marcado arbitrario.
	 *
	 * @var array<int, string>
	 */
	private const GUTENBERG = array(
		'core/paragraph',
		'core/heading',
		'core/list-item',
		'core/quote',
		'core/pullquote',
		'core/verse',
		'core/preformatted',
		'core/button',
		'core/table',
	);

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}

	/**
	 * Indica si un tipo admite edición dentro de un constructor.
	 *
	 * @param string $builder Slug del constructor.
	 * @param string $type    Tipo o shortcode evaluado.
	 */
	public static function allows( string $builder, string $type ): bool {
		if ( in_array( $type, self::NEVER, true ) ) {
			return false;
		}

		return in_array( $type, self::for_builder( $builder ), true );
	}

	/**
	 * Indica si un tipo está vedado de forma explícita.
	 *
	 * @param string $type Tipo o shortcode evaluado.
	 */
	public static function is_forbidden( string $type ): bool {
		return in_array( $type, self::NEVER, true );
	}

	/**
	 * Entrega los tipos editables de un constructor.
	 *
	 * @param string $builder Slug del constructor.
	 *
	 * @return array<int, string>
	 */
	public static function for_builder( string $builder ): array {
		switch ( $builder ) {
			case 'elementor':
				return self::ELEMENTOR;
			case 'divi':
				return self::DIVI;
			case 'wpbakery':
				return self::WPBAKERY;
			case 'gutenberg':
				return self::GUTENBERG;
			default:
				return array();
		}
	}
}
