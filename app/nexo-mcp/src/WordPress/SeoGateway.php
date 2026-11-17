<?php
/**
 * SEO técnico administrado por Nexo MCP.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Seo\SeoProviderRegistry;

/**
 * Administra el SEO por entrada delegando en el plugin que gobierna el sitio.
 *
 * Cuando hay un plugin SEO instalado se escriben sus metadatos, porque es el que
 * emite el título y las etiquetas. El conector sólo toma el mando —y emite las
 * suyas— si no encuentra ninguno reconocido.
 */
final class SeoGateway {

	public const SETTINGS_OPTION = 'nexo_mcp_seo_settings';

	private const META_TITLE = '_nexo_mcp_seo_title';

	private const META_DESCRIPTION = '_nexo_mcp_seo_description';

	private const META_CANONICAL = '_nexo_mcp_seo_canonical';

	private const META_NOINDEX = '_nexo_mcp_seo_noindex';

	private const META_OG_TITLE = '_nexo_mcp_seo_og_title';

	private const META_OG_DESCRIPTION = '_nexo_mcp_seo_og_description';

	private const META_OG_IMAGE = '_nexo_mcp_seo_og_image';

	private const META_SCHEMA = '_nexo_mcp_seo_schema';

	/**
	 * Selección del proveedor vigente.
	 *
	 * @var SeoProviderRegistry
	 */
	private SeoProviderRegistry $providers;

	/**
	 * Construye el gateway.
	 *
	 * @param SeoProviderRegistry|null $providers Registro de proveedores SEO.
	 */
	public function __construct( ?SeoProviderRegistry $providers = null ) {
		$this->providers = $providers ?? new SeoProviderRegistry();
	}

	/**
	 * Registra filtros frontend una sola vez.
	 */
	public function register_frontend_hooks(): void {
		// Los ajustes globales de sitemap y robots los configura el dueño en el
		// panel, así que se respetan siempre.
		add_filter( 'wp_sitemaps_enabled', array( $this, 'sitemaps_enabled' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap_posts' ), 10, 2 );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );

		// Emitir título, canonical y Open Graph propios mientras otro plugin hace
		// lo mismo produce etiquetas duplicadas y un ganador impredecible.
		if ( $this->providers->delegated() ) {
			return;
		}

		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ) );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_action( 'wp_head', array( $this, 'render_head_meta' ), 2 );
	}

	/**
	 * Obtiene SEO de una entrada.
	 *
	 * @param int $post_id Entrada.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_post_seo( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró contenido SEO editable.', 'nexo-mcp' ) );
		}

		return $this->post_seo_detail( $post_id );
	}

	/**
	 * Actualiza sólo metadatos SEO aprobados.
	 *
	 * @param array<string, mixed> $input Datos validados.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_post_seo( array $input ) {
		$post_id = (int) $input['id'];
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró contenido SEO editable.', 'nexo-mcp' ) );
		}

		$provider = $this->providers->active();
		$fields   = array();

		foreach ( array( 'title', 'description', 'og_title', 'og_description' ) as $input_key ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$fields[ $input_key ] = sanitize_text_field( (string) $input[ $input_key ] );
			}
		}

		foreach ( array( 'canonical', 'og_image' ) as $input_key ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$fields[ $input_key ] = esc_url_raw( (string) $input[ $input_key ], array( 'https', 'http' ) );
			}
		}

		if ( array_key_exists( 'noindex', $input ) ) {
			$fields['noindex'] = ! empty( $input['noindex'] );
		}

		if ( array_key_exists( 'schema', $input ) ) {
			$schema = trim( (string) $input['schema'] );

			// Yoast y Rank Math arman su propio JSON-LD. Escribir el nuestro
			// produciría dos bloques compitiendo, así que se rechaza indicando
			// quién manda en lugar de guardarlo donde nadie lo va a leer.
			if ( ! $provider->supports_schema() && '' !== $schema ) {
				return new \WP_Error(
					'nexo_mcp_schema_delegated',
					sprintf(
						/* translators: %s: nombre del plugin SEO activo. */
						__( '%s genera el JSON-LD de este sitio. Edita el schema desde ese plugin.', 'nexo-mcp' ),
						$provider->label()
					)
				);
			}

			if ( '' === $schema ) {
				$fields['schema'] = '';
			} else {
				$decoded = json_decode( $schema, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					return new \WP_Error( 'nexo_mcp_schema_invalid', __( 'Schema debe ser JSON válido.', 'nexo-mcp' ) );
				}

				$fields['schema'] = (string) wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		$provider->write( $post_id, $fields );

		return $this->post_seo_detail( $post_id );
	}

	/**
	 * Obtiene ajustes SEO globales.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge(
			array(
				'sitemaps_enabled' => true,
				'disallow_paths'   => array(),
			),
			$stored
		);
	}

	/**
	 * Actualiza ajustes SEO globales por allowlist.
	 *
	 * @param array<string, mixed> $input Ajustes.
	 * @return array<string, mixed>
	 */
	public function update_settings( array $input ): array {
		$current = $this->get_settings();

		if ( array_key_exists( 'sitemaps_enabled', $input ) ) {
			$current['sitemaps_enabled'] = ! empty( $input['sitemaps_enabled'] );
		}

		if ( array_key_exists( 'disallow_paths', $input ) ) {
			$paths = array_map(
				static function ( $path ): string {
					$clean = '/' . ltrim( sanitize_text_field( (string) $path ), '/' );
					return preg_replace( '/[^a-zA-Z0-9_\/.*?&=-]/', '', $clean ) ?? '';
				},
				(array) $input['disallow_paths']
			);

			$current['disallow_paths'] = array_values( array_unique( array_filter( array_slice( $paths, 0, 50 ) ) ) );
		}

		update_option( self::SETTINGS_OPTION, $current, false );

		return $current;
	}

	/**
	 * Sustituye el título documental completo cuando existe uno propio.
	 *
	 * @param string $title Título generado por WordPress.
	 */
	public function filter_document_title( string $title ): string {
		if ( is_singular() ) {
			$custom_title = (string) get_post_meta( get_queried_object_id(), self::META_TITLE, true );

			if ( '' !== $custom_title ) {
				return $custom_title;
			}
		}

		return $title;
	}

	/**
	 * Sustituye canonical cuando fue configurado.
	 *
	 * @param string   $canonical URL original.
	 * @param \WP_Post $post      Entrada.
	 */
	public function filter_canonical_url( string $canonical, \WP_Post $post ): string {
		$custom = (string) get_post_meta( $post->ID, self::META_CANONICAL, true );

		return '' !== $custom ? $custom : $canonical;
	}

	/**
	 * Agrega noindex a WordPress robots API.
	 *
	 * @param array<string, bool|string> $robots Directivas.
	 * @return array<string, bool|string>
	 */
	public function filter_robots( array $robots ): array {
		if ( is_singular() && '1' === get_post_meta( get_queried_object_id(), self::META_NOINDEX, true ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		return $robots;
	}

	/**
	 * Controla sitemap nativo.
	 */
	public function sitemaps_enabled(): bool {
		return ! empty( $this->get_settings()['sitemaps_enabled'] );
	}

	/**
	 * Excluye contenidos noindex del sitemap.
	 *
	 * @param array<string, mixed> $args      WP_Query args.
	 * @param string               $post_type Tipo.
	 * @return array<string, mixed>
	 */
	public function filter_sitemap_posts( array $args, string $post_type ): array {
		unset( $post_type );
		$noindex_query  = array(
			'relation' => 'OR',
			array(
				'key'     => self::META_NOINDEX,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_NOINDEX,
				'value'   => '1',
				'compare' => '!=',
			),
		);
		$existing_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
			? $args['meta_query']
			: array();

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- El sitemap requiere excluir sólo metadatos noindex.
		$args['meta_query'] = array() === $existing_query
			? $noindex_query
			: array(
				'relation' => 'AND',
				$existing_query,
				$noindex_query,
			);

		return $args;
	}

	/**
	 * Añade reglas Disallow seguras al robots virtual.
	 *
	 * @param string $output Robots actual.
	 * @param bool   $is_public Visibilidad.
	 */
	public function filter_robots_txt( string $output, bool $is_public ): string {
		if ( ! $is_public ) {
			return $output;
		}

		foreach ( (array) $this->get_settings()['disallow_paths'] as $path ) {
			$output .= "\nDisallow: " . $path;
		}

		return rtrim( $output ) . "\n";
	}

	/**
	 * Renderiza meta description, Open Graph y JSON-LD.
	 */
	public function render_head_meta(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id        = get_queried_object_id();
		$description    = (string) get_post_meta( $post_id, self::META_DESCRIPTION, true );
		$og_title       = (string) get_post_meta( $post_id, self::META_OG_TITLE, true );
		$og_description = (string) get_post_meta( $post_id, self::META_OG_DESCRIPTION, true );
		$og_image       = (string) get_post_meta( $post_id, self::META_OG_IMAGE, true );
		$schema         = (string) get_post_meta( $post_id, self::META_SCHEMA, true );

		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $description ) );
		}

		foreach (
			array(
				'og:title'       => $og_title,
				'og:description' => $og_description,
				'og:image'       => $og_image,
			) as $property => $content
		) {
			if ( '' !== $content ) {
				printf( "<meta property=\"%s\" content=\"%s\">\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}

		if ( '' !== $schema ) {
			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				wp_json_encode(
					json_decode( $schema, true ),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				)
			);
		}
	}

	/**
	 * Devuelve el documento SEO normalizado.
	 *
	 * @param int $post_id Entrada.
	 * @return array<string, mixed>
	 */
	private function post_seo_detail( int $post_id ): array {
		$provider = $this->providers->active();

		// El origen se informa para que el cliente sepa de dónde salen estos
		// valores y dónde se van a reflejar.
		return array_merge(
			array( 'id' => $post_id ),
			$provider->read( $post_id ),
			array( 'managed_by' => $provider->label() )
		);
	}
}
