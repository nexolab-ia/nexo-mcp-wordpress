<?php
/**
 * Proveedor SEO para All in One SEO.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Escribe en All in One SEO a través de su propio modelo.
 *
 * A diferencia de Yoast, Rank Math y SEOPress, **AIOSEO 4 no guarda nada en
 * `post_meta`**: tiene una tabla propia, `{prefijo}aioseo_posts`, con una
 * columna por campo. Copiar el patrón de los otros proveedores habría producido
 * exactamente el fallo que este módulo existe para evitar: la herramienta
 * informaría éxito, los metadatos quedarían escritos donde nadie los lee, y el
 * título seguiría saliendo de AIOSEO.
 *
 * Tampoco se escribe en la tabla por SQL: se usa el modelo del plugin, que es
 * lo único que sobrevive a un cambio de esquema entre versiones.
 */
final class AioseoProvider implements SeoProvider {

	/**
	 * Modelo de AIOSEO que representa una entrada.
	 */
	private const MODELO = '\AIOSEO\Plugin\Common\Models\Post';

	/**
	 * Correspondencia entre campo del conector y columna de AIOSEO.
	 *
	 * @var array<string, string>
	 */
	private const KEYS = array(
		'title'          => 'title',
		'description'    => 'description',
		'canonical'      => 'canonical_url',
		'og_title'       => 'og_title',
		'og_description' => 'og_description',
		'og_image'       => 'og_image_custom_url',
	);

	/**
	 * Identificador estable del proveedor.
	 */
	public function slug(): string {
		return 'aioseo';
	}

	/**
	 * Nombre legible del proveedor.
	 */
	public function label(): string {
		return 'All in One SEO';
	}

	/**
	 * Detecta AIOSEO exigiendo el modelo, no sólo la constante.
	 *
	 * Sin el modelo no hay forma admitida de escribir, y declararse activo sin
	 * poder escribir devolvería el fallo silencioso por otra puerta.
	 */
	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' ) && class_exists( self::MODELO );
	}

	/**
	 * AIOSEO construye su propio JSON-LD.
	 */
	public function supports_schema(): bool {
		return false;
	}

	/**
	 * Lee los campos SEO desde el modelo de AIOSEO.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$detail = array();
		$modelo = $this->modelo( $post_id );

		foreach ( self::KEYS as $field => $columna ) {
			$valor            = null !== $modelo ? ( $modelo->{$columna} ?? '' ) : '';
			$detail[ $field ] = is_scalar( $valor ) ? (string) $valor : '';
		}

		$detail['noindex'] = null !== $modelo && ! empty( $modelo->robots_noindex );
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
		$modelo = $this->modelo( $post_id );

		if ( null === $modelo ) {
			return;
		}

		foreach ( self::KEYS as $field => $columna ) {
			if ( array_key_exists( $field, $fields ) ) {
				$modelo->{$columna} = (string) $fields[ $field ];
			}
		}

		if ( array_key_exists( 'noindex', $fields ) ) {
			// `robots_default` manda sobre el resto: mientras siga activo, AIOSEO
			// ignora la marca de noindex y aplica la preferencia global.
			$modelo->robots_default = false;
			$modelo->robots_noindex = ! empty( $fields['noindex'] );
		}

		$modelo->post_id = $post_id;
		$modelo->save();
	}

	/**
	 * Obtiene el modelo de una entrada.
	 *
	 * @param int $post_id Entrada consultada.
	 * @return object|null
	 */
	private function modelo( int $post_id ) {
		if ( ! $this->is_active() ) {
			return null;
		}

		$modelo = call_user_func( array( self::MODELO, 'getPost' ), $post_id );

		return is_object( $modelo ) ? $modelo : null;
	}
}
