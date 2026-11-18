<?php
/**
 * Escrituras seguras de contenido WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Builders\BuilderRegistry;

use NexoDev\NexoMCP\Builders\ForeignBuilderDetector;

use NexoDev\NexoMCP\Cache\CachePurger;

use NexoDev\NexoMCP\Security\ContentSanitizer;

use NexoDev\NexoMCP\Support\Settings;

/**
 * Crea y actualiza páginas o artículos sin publicar automáticamente.
 */
final class WriteGateway {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Gateway de lectura para normalizar respuestas.
	 *
	 * @var ReadGateway
	 */
	private ReadGateway $read_gateway;

	/**
	 * Saneamiento del contenido entrante.
	 *
	 * @var ContentSanitizer
	 */
	private ContentSanitizer $sanitizer;

	/**
	 * Purga de caché tras escribir.
	 *
	 * @var CachePurger
	 */
	private CachePurger $cache;

	/**
	 * Copias de trabajo del contenido publicado.
	 *
	 * @var WorkingCopy
	 */
	private WorkingCopy $copies;

	/**
	 * Construye el gateway.
	 *
	 * @param Settings              $settings     Configuración operativa.
	 * @param ReadGateway           $read_gateway Lecturas normalizadas.
	 * @param ContentSanitizer|null $sanitizer    Saneador propio.
	 * @param CachePurger|null      $cache        Purgador propio.
	 * @param WorkingCopy|null      $copies       Copias de trabajo.
	 */
	public function __construct(
		Settings $settings,
		ReadGateway $read_gateway,
		?ContentSanitizer $sanitizer = null,
		?CachePurger $cache = null,
		?WorkingCopy $copies = null
	) {
		$this->settings     = $settings;
		$this->read_gateway = $read_gateway;
		$this->sanitizer    = $sanitizer ?? new ContentSanitizer();
		$this->cache        = $cache ?? new CachePurger( $settings );
		$this->copies       = $copies ?? new WorkingCopy( $settings, $this->cache );
	}

	/**
	 * Crea un contenido como borrador.
	 *
	 * @param string               $post_type page o post.
	 * @param array<string, mixed> $input     Datos validados por el esquema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( string $post_type, array $input ) {
		$post_data = array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( (string) $input['title'] ),
			'post_content' => $this->sanitizer->sanitize( (string) ( $input['content'] ?? '' ) ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $input['excerpt'] ?? '' ) ),
		);

		if ( ! empty( $input['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		$post_id = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Un contenido nuevo nace como borrador, así que no hay nada cacheado que
		// invalidar: se omite la purga en lugar de gastarla sin motivo.
		return $this->read_gateway->get_content( (int) $post_id, $post_type );
	}

	/**
	 * Actualiza contenido existente sin cambiar su estado.
	 *
	 * @param int                  $post_id   ID solicitado.
	 * @param string               $post_type page o post.
	 * @param array<string, mixed> $input     Datos validados por el esquema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( int $post_id, string $post_type, array $input ) {
		// Lectura y reescritura bajo el bloqueo del original: dos ediciones a la
		// vez no crean dos copias ni se pisan. Dentro se descarta la caché y se
		// vuelve a leer todo: `update_locked()` no recibe ningún objeto leído antes.
		return WorkingCopy::with_lock( $post_id, fn() => $this->update_locked( $post_id, $post_type, $input ) );
	}

	/**
	 * Actualiza ya con el bloqueo del original en mano.
	 *
	 * @param int                  $post_id   ID solicitado.
	 * @param string               $post_type page o post.
	 * @param array<string, mixed> $input     Datos validados por el esquema.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_locked( int $post_id, string $post_type, array $input ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || $post_type !== $post->post_type ) {
			return new \WP_Error(
				'nexo_mcp_content_not_found',
				__( 'No se encontró el contenido solicitado.', 'nexo-mcp' )
			);
		}

		// Con `draft` sobre contenido a la vista, el cambio va a una copia de
		// trabajo y el original no se toca (ADR-031). La copia es un borrador,
		// así que «sólo borradores» no la frena: es justo para eso.
		$staged = WorkingCopy::should_stage( $post, $input, $this->settings );

		if ( (bool) $this->settings->get( 'drafts_only' ) && 'draft' !== $post->post_status && ! $staged ) {
			return new \WP_Error(
				'nexo_mcp_draft_required',
				__( 'El modo sólo borradores impide editar contenido publicado.', 'nexo-mcp' )
			);
		}

		// La copia no lleva slug propio y la fusión no lo traspasa: aceptarlo
		// sería perderlo en silencio al publicar.
		if ( array_key_exists( 'slug', $input ) && ( $staged || WorkingCopy::original_of( $post ) > 0 ) ) {
			return new \WP_Error(
				'nexo_mcp_draft_slug',
				__( 'La copia de trabajo no lleva slug: cámbialo en el contenido original.', 'nexo-mcp' )
			);
		}

		// Escribir `content` en una página de constructor visual no produce ningún
		// efecto: Elementor lee su árbol de `post_meta`, y Divi o WPBakery esperan
		// sus propios shortcodes. Devolver éxito ahí sería peor que fallar, porque
		// el cliente daría el cambio por hecho. Se rechaza y se indica la vía.
		//
		// El editor de bloques queda fuera de ese rechazo: su documento es el
		// propio `post_content`, así que reescribirlo sí surte efecto. Tratarlo
		// como a los demás dejaba sin arreglo posible a cualquier página creada
		// por el conector, que las genera precisamente con bloques.
		if ( array_key_exists( 'content', $input ) ) {
			// Antes que nada, los constructores que el conector no sabe manejar.
			// Sin esta comprobación no se detectaban y la página se trataba como
			// una entrada corriente: en Avada, Themify o Cornerstone —que guardan
			// su documento en `post_content`— eso sobrescribe el layout entero y
			// se pierde la página. No hace falta saber editarlos para dejar de
			// romperlos; basta con reconocerlos y negarse.
			$ajeno = ( new ForeignBuilderDetector() )->detect( $post );

			if ( null !== $ajeno ) {
				return new \WP_Error(
					'nexo_mcp_foreign_builder',
					sprintf(
						/* translators: %s: nombre del constructor detectado. */
						__( 'Esta página está hecha con %s, que el conector todavía no sabe editar. No se escribe nada para no destruir el diseño: edítala desde su propio constructor.', 'nexo-mcp' ),
						$ajeno
					),
					array( 'builder' => $ajeno )
				);
			}

			$builder = ( new BuilderRegistry() )->resolve( $post );

			if ( null !== $builder && ! $builder->accepts_full_content_write() ) {
				return new \WP_Error(
					'nexo_mcp_builder_content',
					sprintf(
						/* translators: %s: nombre del constructor visual. */
						__( 'Esta página está hecha con %s y su contenido no vive en el editor estándar. Usa get-page-structure para ver sus elementos y update-node-text para editarlos.', 'nexo-mcp' ),
						$builder->label()
					),
					array( 'builder' => $builder->slug() )
				);
			}
		}

		// Las comprobaciones de arriba valen para la copia, que es un clon del
		// original: se hacen antes de crearla para no dejar una copia huérfana
		// de una escritura rechazada. La respuesta es compacta (ADR-030): el
		// cliente ya sabe lo que escribió y la copia no está a la vista, así
		// que no hay caché que purgar.
		if ( $staged ) {
			$copy = $this->copies->obtain( $post );

			if ( is_wp_error( $copy ) ) {
				return $copy;
			}

			$result = $this->write( (int) $copy->ID, $input );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'id'          => (int) $copy->ID,
				'draft_of'    => $post_id,
				'preview_url' => (string) get_preview_post_link( $copy ),
			);
		}

		$result = $this->write( $post_id, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$salida = $this->read_gateway->get_content( $post_id, $post_type );

		if ( is_array( $salida ) ) {
			$salida['cache'] = $this->cache->after_post_change( $post_id );
		}

		return $salida;
	}

	/**
	 * Escribe los campos recibidos en un contenido.
	 *
	 * @param int                  $post_id Contenido de destino.
	 * @param array<string, mixed> $input   Datos validados por el esquema.
	 * @return int|\WP_Error
	 */
	private function write( int $post_id, array $input ) {
		$post_data = array( 'ID' => $post_id );

		if ( array_key_exists( 'title', $input ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}

		if ( array_key_exists( 'content', $input ) ) {
			$post_data['post_content'] = $this->sanitizer->sanitize( (string) $input['content'] );
		}

		if ( array_key_exists( 'excerpt', $input ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$post_data['post_name'] = sanitize_title( (string) $input['slug'] );
		}

		return wp_update_post( wp_slash( $post_data ), true );
	}
}
