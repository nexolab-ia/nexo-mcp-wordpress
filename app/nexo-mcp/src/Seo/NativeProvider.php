<?php
/**
 * Proveedor SEO propio del conector.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Guarda el SEO en metadatos del conector y lo emite por su cuenta.
 *
 * Es el proveedor de respaldo: gobierna cuando el sitio no tiene ningún plugin
 * SEO reconocido, que es el único caso en que emitir etiquetas propias no compite
 * con las de otro.
 */
final class NativeProvider implements SeoProvider {

	public const META_TITLE       = '_nexo_mcp_seo_title';
	public const META_DESCRIPTION = '_nexo_mcp_seo_description';
	public const META_CANONICAL   = '_nexo_mcp_seo_canonical';
	public const META_NOINDEX     = '_nexo_mcp_seo_noindex';
	public const META_OG_TITLE    = '_nexo_mcp_seo_og_title';
	public const META_OG_DESC     = '_nexo_mcp_seo_og_description';
	public const META_OG_IMAGE    = '_nexo_mcp_seo_og_image';
	public const META_SCHEMA      = '_nexo_mcp_seo_schema';

	/**
	 * Correspondencia entre campo y metadato propio.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = array(
		'title'          => self::META_TITLE,
		'description'    => self::META_DESCRIPTION,
		'canonical'      => self::META_CANONICAL,
		'og_title'       => self::META_OG_TITLE,
		'og_description' => self::META_OG_DESC,
		'og_image'       => self::META_OG_IMAGE,
		'schema'         => self::META_SCHEMA,
	);

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'nexo';
	}

	/**
	 * Nombre legible del proveedor.
	 */
	public function label(): string {
		return 'Nexo MCP';
	}

	/**
	 * Es el respaldo: sólo gobierna si no hay otro proveedor.
	 */
	public function is_active(): bool {
		return true;
	}

	/**
	 * El conector sí emite su propio JSON-LD.
	 */
	public function supports_schema(): bool {
		return true;
	}

	/**
	 * Lee los campos SEO de los metadatos propios.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$detail = array();

		foreach ( self::KEYS as $field => $meta_key ) {
			$detail[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		$detail['noindex'] = '1' === (string) get_post_meta( $post_id, self::META_NOINDEX, true );

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
			update_post_meta( $post_id, self::META_NOINDEX, ! empty( $fields['noindex'] ) ? '1' : '0' );
		}
	}
}
