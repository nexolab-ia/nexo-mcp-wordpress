<?php
/**
 * Proveedor SEO para SEOPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Escribe en los metadatos de SEOPress.
 *
 * SEOPress guarda cada campo en su propia clave de `post_meta`, igual que Yoast,
 * así que la integración es directa. La única particularidad está en el
 * indicador de indexación: usa la cadena `yes` para marcar «no indexar», y
 * ausencia para dejar el valor por omisión del tipo de contenido.
 */
final class SeoPressProvider implements SeoProvider {

	/**
	 * Correspondencia entre campo del conector y metadato de SEOPress.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = array(
		'title'          => '_seopress_titles_title',
		'description'    => '_seopress_titles_desc',
		'canonical'      => '_seopress_robots_canonical',
		'og_title'       => '_seopress_social_fb_title',
		'og_description' => '_seopress_social_fb_desc',
		'og_image'       => '_seopress_social_fb_img',
	);

	/**
	 * Metadato del indicador noindex.
	 */
	private const NOINDEX_KEY = '_seopress_robots_index';

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * Nombre legible del proveedor.
	 */
	public function label(): string {
		return 'SEOPress';
	}

	/**
	 * Detecta SEOPress por su constante y por su servicio principal.
	 */
	public function is_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' );
	}

	/**
	 * SEOPress emite su propio JSON-LD, así que el conector no lo duplica.
	 */
	public function supports_schema(): bool {
		return false;
	}

	/**
	 * Lee los campos SEO desde los metadatos de SEOPress.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$detail = array();

		foreach ( self::KEYS as $field => $meta_key ) {
			$detail[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		$detail['noindex'] = 'yes' === (string) get_post_meta( $post_id, self::NOINDEX_KEY, true );
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
			if ( empty( $fields['noindex'] ) ) {
				// Borrar en vez de escribir «no»: SEOPress trata la ausencia como
				// «usa el valor por omisión», que no es lo mismo que forzar índice.
				delete_post_meta( $post_id, self::NOINDEX_KEY );
			} else {
				update_post_meta( $post_id, self::NOINDEX_KEY, 'yes' );
			}
		}
	}
}
