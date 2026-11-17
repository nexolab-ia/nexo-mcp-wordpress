<?php
/**
 * Proveedor SEO para Rank Math.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Escribe en los metadatos de Rank Math.
 *
 * A diferencia de Yoast, guarda las directivas de robots en un solo metadato con
 * varios valores, así que `noindex` se alterna dentro de esa lista en lugar de
 * sobrescribirla: quitarla entera borraría opciones que el dueño configuró.
 */
final class RankMathProvider implements SeoProvider {

	/**
	 * Correspondencia entre campo del conector y metadato de Rank Math.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = array(
		'title'          => 'rank_math_title',
		'description'    => 'rank_math_description',
		'canonical'      => 'rank_math_canonical_url',
		'og_title'       => 'rank_math_facebook_title',
		'og_description' => 'rank_math_facebook_description',
		'og_image'       => 'rank_math_facebook_image',
	);

	/**
	 * Metadato con las directivas de robots.
	 */
	private const ROBOTS_KEY = 'rank_math_robots';

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'rank-math';
	}

	/**
	 * Nombre legible del proveedor.
	 */
	public function label(): string {
		return 'Rank Math SEO';
	}

	/**
	 * Detecta Rank Math por su clase principal y su constante de versión.
	 */
	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' );
	}

	/**
	 * Rank Math genera su propio JSON-LD.
	 */
	public function supports_schema(): bool {
		return false;
	}

	/**
	 * Lee los campos SEO desde los metadatos de Rank Math.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$detail = array();

		foreach ( self::KEYS as $field => $meta_key ) {
			$detail[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		$detail['noindex'] = in_array( 'noindex', $this->robots( $post_id ), true );
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

		if ( ! array_key_exists( 'noindex', $fields ) ) {
			return;
		}

		$robots   = array_values( array_diff( $this->robots( $post_id ), array( 'noindex', 'index' ) ) );
		$robots[] = ! empty( $fields['noindex'] ) ? 'noindex' : 'index';

		update_post_meta( $post_id, self::ROBOTS_KEY, $robots );
	}

	/**
	 * Devuelve las directivas de robots guardadas.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<int, string>
	 */
	private function robots( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::ROBOTS_KEY, true );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}
}
