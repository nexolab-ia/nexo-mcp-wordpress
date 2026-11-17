<?php
/**
 * Detección de constructores que el conector no sabe manejar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Reconoce constructores ajenos para negarse a escribir sobre ellos.
 *
 * Hasta ahora, un constructor sin adaptador simplemente no se detectaba y el
 * contenido se trataba como una página corriente. Para los que guardan su
 * documento en `post_content` —Avada con sus shortcodes `fusion_`, Themify,
 * Cornerstone— eso significa **sobrescribir el layout completo**: el cliente
 * pide cambiar un título y la página se pierde. Para los que guardan en
 * `post_meta` no hay daño, pero la escritura no se ve en ninguna parte y la
 * herramienta informa éxito igual.
 *
 * No hace falta saber editar cada uno para dejar de romperlos. Basta con
 * reconocerlos y devolver un rechazo que diga cuál es y por dónde editarlo.
 */
final class ForeignBuilderDetector {

	/**
	 * Constructores cuyo documento vive en `post_content`.
	 *
	 * Escribir encima destruye el layout, así que son los graves.
	 *
	 * @var array<string, string>
	 */
	private const FIRMAS_CONTENIDO = array(
		'[fusion_builder_container' => 'Avada Fusion Builder',
		'[fusion_text'              => 'Avada Fusion Builder',
		'[themify_builder_static'   => 'Themify Builder',
		'[cs_content'               => 'Cornerstone',
		'[cs_element_'              => 'Cornerstone',
		'[tatsu_section'            => 'Tatsu Builder',
		'[kc_row'                   => 'King Composer',
		'[mk_page_section'          => 'Jupiter / Mk Builder',
	);

	/**
	 * Constructores cuyo documento vive en `post_meta`.
	 *
	 * Escribir no los rompe, pero tampoco surte efecto: el rechazo evita que el
	 * cliente dé por hecho un cambio que nadie va a ver.
	 *
	 * @var array<string, string>
	 */
	private const FIRMAS_META = array(
		'_bricks_page_content_2'     => 'Bricks',
		'_breakdance_data'           => 'Breakdance',
		'ct_builder_shortcodes'      => 'Oxygen',
		'_fl_builder_data'           => 'Beaver Builder',
		'_zionbuilder_page_elements' => 'Zion Builder',
		'panels_data'                => 'SiteOrigin Page Builder',
		'vcv-pageContent'            => 'Visual Composer Website Builder',
		'brizy'                      => 'Brizy',
		'mfn-page-items'             => 'BeTheme Muffin Builder',
		'_colibri_page_builder'      => 'Colibri Page Builder',
		'tve_updated_post'           => 'Thrive Architect',
	);

	/**
	 * Devuelve el nombre del constructor ajeno detectado, o null.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function detect( \WP_Post $post ): ?string {
		$contenido = (string) $post->post_content;

		if ( '' !== $contenido ) {
			foreach ( self::FIRMAS_CONTENIDO as $firma => $nombre ) {
				if ( false !== strpos( $contenido, $firma ) ) {
					return $nombre;
				}
			}
		}

		foreach ( self::FIRMAS_META as $clave => $nombre ) {
			$valor = get_post_meta( $post->ID, $clave, true );

			if ( '' !== $valor && array() !== $valor && null !== $valor && false !== $valor ) {
				return $nombre;
			}
		}

		return null;
	}

	/**
	 * Indica si el constructor detectado guarda su documento en `post_content`.
	 *
	 * @param string $nombre Nombre devuelto por `detect()`.
	 */
	public function destructivo( string $nombre ): bool {
		return in_array( $nombre, self::FIRMAS_CONTENIDO, true );
	}
}
