<?php
/**
 * Proveedor SEO para Yoast.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Escribe en los metadatos de Yoast SEO.
 *
 * Yoast está en decenas de millones de sitios, así que es el caso habitual y no
 * la excepción. Al editar sus propias claves el cambio aparece también dentro de
 * la interfaz de Yoast, que es donde el dueño del sitio lo va a buscar.
 */
final class YoastProvider implements SeoProvider {

	/**
	 * Correspondencia entre campo del conector y metadato de Yoast.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = array(
		'title'          => '_yoast_wpseo_title',
		'description'    => '_yoast_wpseo_metadesc',
		'canonical'      => '_yoast_wpseo_canonical',
		'og_title'       => '_yoast_wpseo_opengraph-title',
		'og_description' => '_yoast_wpseo_opengraph-description',
		'og_image'       => '_yoast_wpseo_opengraph-image',
	);

	/**
	 * Metadato del indicador noindex.
	 */
	private const NOINDEX_KEY = '_yoast_wpseo_meta-robots-noindex';

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * Nombre legible del proveedor.
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * Detecta Yoast por su constante y por su clase principal.
	 */
	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Options' );
	}

	/**
	 * Yoast construye su propio JSON-LD, así que el conector no lo duplica.
	 */
	public function supports_schema(): bool {
		return false;
	}

	/**
	 * Lee los campos SEO desde los metadatos de Yoast.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$detail = array();

		foreach ( self::KEYS as $field => $meta_key ) {
			$detail[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		// Yoast guarda «1» para noindex y «2» para index; vacío deja el valor por
		// omisión del tipo de contenido.
		$detail['noindex'] = '1' === (string) get_post_meta( $post_id, self::NOINDEX_KEY, true );
		$detail['schema']  = '';

		return $detail;
	}

	/**
	 * Escribe sólo los campos presentes.
	 *
	 * @param int                  $post_id Entrada a modificar.
	 * @param array<string, mixed> $fields  Campos ya saneados.
	 */
	public function write( int $post_id, array $fields ): void {
		foreach ( self::KEYS as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}

			$value = (string) $fields[ $field ];

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		if ( array_key_exists( 'noindex', $fields ) ) {
			update_post_meta( $post_id, self::NOINDEX_KEY, ! empty( $fields['noindex'] ) ? '1' : '2' );
		}
	}
}
