<?php
/**
 * Operaciones editoriales avanzadas de WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Media\UploadThrottle;
use NexoDev\NexoMCP\Media\UploadTicket;
use NexoDev\NexoMCP\Media\UploadValidator;
use NexoDev\NexoMCP\Media\ValidatedUpload;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Gestiona medios, taxonomías, revisiones y estados sin acceso genérico.
 */
final class ContentAdminGateway {

	private const ALLOWED_TAXONOMIES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );

	/**
	 * Tope para la carga en base64: 2 MB.
	 *
	 * Muy por debajo de los 10 MB que admite el validador. No es una cuestión
	 * de disco sino de contexto: 2 MB de archivo son unos 2,7 MB de texto, que
	 * en un agente rondan los 700.000 tokens. Subir de aquí deja de tener
	 * sentido frente al endpoint REST.
	 */
	private const MAX_BYTES_BASE64 = 2097152;

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Validación de archivos entrantes.
	 *
	 * @var UploadValidator
	 */
	private UploadValidator $validator;

	/**
	 * Techo de subidas, compartido con el endpoint REST.
	 *
	 * @var UploadThrottle
	 */
	private UploadThrottle $throttle;

	/**
	 * Enlaces de subida de un solo uso.
	 *
	 * @var UploadTicket
	 */
	private UploadTicket $tickets;

	/**
	 * Copias de trabajo del contenido publicado.
	 *
	 * @var WorkingCopy
	 */
	private WorkingCopy $copies;

	/**
	 * Construye el gateway.
	 *
	 * @param Settings            $settings Configuración.
	 * @param UploadThrottle|null $throttle Techo de subidas.
	 * @param UploadTicket|null   $tickets  Enlaces de subida.
	 * @param WorkingCopy|null    $copies   Copias de trabajo.
	 */
	public function __construct( Settings $settings, ?UploadThrottle $throttle = null, ?UploadTicket $tickets = null, ?WorkingCopy $copies = null ) {
		$this->settings  = $settings;
		$this->validator = new UploadValidator();
		$this->throttle  = $throttle ?? new UploadThrottle();
		$this->tickets   = $tickets ?? new UploadTicket();
		$this->copies    = $copies ?? new WorkingCopy( $settings );
	}

	/**
	 * Lista medios con un límite estricto.
	 *
	 * @param array<string, mixed> $input Filtros validados.
	 * @return list<array<string, mixed>>
	 */
	public function list_media( array $input ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
				's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return array_map(
			static fn( \WP_Post $attachment ): array => array(
				'id'          => $attachment->ID,
				'title'       => get_the_title( $attachment ),
				'alt'         => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'caption'     => $attachment->post_excerpt,
				'description' => $attachment->post_content,
				'mime_type'   => $attachment->post_mime_type,
				'url'         => (string) wp_get_attachment_url( $attachment->ID ),
			),
			$query->posts
		);
	}

	/**
	 * Actualiza metadatos editoriales de un adjunto.
	 *
	 * @param array<string, mixed> $input Datos validados.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_media( array $input ) {
		$attachment_id = (int) $input['id'];
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'nexo_mcp_media_not_found', __( 'No se encontró el medio.', 'nexo-mcp' ) );
		}

		$post_data = array( 'ID' => $attachment_id );

		if ( array_key_exists( 'title', $input ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}

		if ( array_key_exists( 'caption', $input ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( (string) $input['caption'] );
		}

		if ( array_key_exists( 'description', $input ) ) {
			$post_data['post_content'] = wp_kses_post( (string) $input['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( wp_slash( $post_data ), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( array_key_exists( 'alt', $input ) ) {
			update_post_meta(
				$attachment_id,
				'_wp_attachment_image_alt',
				sanitize_text_field( (string) $input['alt'] )
			);
		}

		return $this->media_detail( $attachment_id );
	}

	/**
	 * Importa un medio remoto HTTPS con tamaño y MIME limitados.
	 *
	 * @param array<string, mixed> $input Datos validados.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import_media( array $input ) {
		$url = esc_url_raw( (string) $input['url'], array( 'https' ) );

		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return new \WP_Error( 'nexo_mcp_media_url_invalid', __( 'El medio debe usar una URL HTTPS válida.', 'nexo-mcp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $url, 30 );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$nombre = basename( $path );

		$input['filename'] = '' !== $nombre ? $nombre : 'nexo-import';

		return $this->store_upload( $temp_file, $input );
	}

	/**
	 * Recibe un archivo codificado en base64 dentro del propio JSON.
	 *
	 * Es la vía que funciona en cualquier cliente MCP, incluidos los que corren
	 * en el navegador y no pueden hacer una petición HTTP aparte. A cambio el
	 * archivo viaja como texto: pesa un tercio más y, en un agente, pasa entero
	 * por su ventana de contexto. Por eso el tope es mucho más bajo que el del
	 * endpoint REST —sirve para un logo suelto, no para un catálogo— y quien
	 * suba lotes debería usar `POST /nexo-mcp/v1/media`.
	 *
	 * Sin `content_base64` no hay error: se entrega un enlace de un solo uso
	 * para mandar el archivo por HTTP, que es lo que necesita un agente sin el
	 * token a mano (ADR-030).
	 *
	 * @param array<string, mixed> $input Datos validados por el esquema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function upload_media( array $input ) {
		// El techo de subidas no se cobra aquí: lo cobra el endpoint cuando
		// llega el archivo, y pedir enlaces tiene su propio límite.
		if ( ! array_key_exists( 'content_base64', $input ) ) {
			return $this->tickets->issue( $input );
		}

		// El mismo contador que el endpoint REST. Con techos separados bastaría
		// con alternar las dos vías para duplicar la cuota.
		$limite = $this->throttle->consume();

		if ( is_wp_error( $limite ) ) {
			return $limite;
		}

		$codificado = (string) ( $input['content_base64'] ?? '' );

		// El modo estricto rechaza cualquier carácter fuera del alfabeto, así
		// que un recorte a medias no pasa por bytes válidos sin avisar.
		$bytes = base64_decode( $codificado, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Es el transporte del archivo, no ofuscación.

		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error(
				'nexo_mcp_media_base64_invalid',
				__( 'El contenido no es base64 válido. Envía el archivo codificado completo, sin recortes ni saltos de línea añadidos.', 'nexo-mcp' )
			);
		}

		if ( strlen( $bytes ) > self::MAX_BYTES_BASE64 ) {
			return new \WP_Error(
				'nexo_mcp_media_too_large',
				sprintf(
					/* translators: %s: tamaño máximo en megabytes. */
					__( 'Por esta vía el máximo son %s MB. Para archivos mayores usa el endpoint de carga POST /nexo-mcp/v1/media, que recibe el archivo sin codificar.', 'nexo-mcp' ),
					(string) round( self::MAX_BYTES_BASE64 / 1048576, 1 )
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temporal = wp_tempnam( 'nexo-upload' );

		if ( '' === $temporal ) {
			return new \WP_Error(
				'nexo_mcp_media_tempfile',
				__( 'No se pudo crear el archivo temporal.', 'nexo-mcp' )
			);
		}

		$escritos = file_put_contents( $temporal, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Volcado a un temporal local; no hay API de WordPress equivalente.

		if ( false === $escritos ) {
			wp_delete_file( $temporal );

			return new \WP_Error(
				'nexo_mcp_media_tempfile',
				__( 'No se pudo escribir el archivo temporal.', 'nexo-mcp' )
			);
		}

		return $this->store_upload( $temporal, $input );
	}

	/**
	 * Valida un archivo ya en disco y lo mete en la biblioteca.
	 *
	 * Es la única puerta de entrada a la biblioteca de medios. La importación
	 * por URL y la carga directa por REST desembocan las dos aquí, para que no
	 * exista un camino con menos controles que el otro.
	 *
	 * El nombre que trae el cliente no se cree: el tipo, el peso, las
	 * dimensiones y el nombre final los decide el validador leyendo los bytes.
	 *
	 * @param string               $ruta  Archivo temporal en disco.
	 * @param array<string, mixed> $input Metadatos: filename, title y alt.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function store_upload( string $ruta, array $input ) {
		$validado = $this->validator->validate( $ruta, (string) ( $input['filename'] ?? '' ) );

		if ( is_wp_error( $validado ) ) {
			wp_delete_file( $ruta );
			return $validado;
		}

		return $this->adjuntar( $ruta, $validado, $input );
	}

	/**
	 * Mete en la biblioteca un archivo ya validado.
	 *
	 * Es el único punto que llama a `media_handle_sideload()`, para que las
	 * dos vías de entrada —importar por URL y cargar directo— compartan el
	 * mismo tratamiento del título y del texto alternativo.
	 *
	 * @param string               $ruta     Archivo temporal en disco.
	 * @param ValidatedUpload      $validado Resultado de la validación.
	 * @param array<string, mixed> $input    Metadatos editoriales opcionales.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function adjuntar( string $ruta, ValidatedUpload $validado, array $input ) {
		// Van aquí y no en cada vía de entrada: es el único punto que usa
		// media_handle_sideload(), así que ninguna puede olvidarse de cargarlos.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => $validado->filename,
			'tmp_name' => $ruta,
		);
		$post_data  = array(
			'post_title' => sanitize_text_field(
				(string) ( $input['title'] ?? pathinfo( $validado->filename, PATHINFO_FILENAME ) )
			),
		);
		$media_id   = media_handle_sideload( $file_array, 0, null, $post_data );

		if ( is_wp_error( $media_id ) ) {
			wp_delete_file( $ruta );
			return $media_id;
		}

		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}

		return $this->media_detail( (int) $media_id );
	}

	/**
	 * Lista términos de una taxonomía aprobada.
	 *
	 * @param array<string, mixed> $input Filtros.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public function list_terms( array $input ) {
		$taxonomy = (string) $input['taxonomy'];

		if ( ! $this->taxonomy_allowed( $taxonomy ) ) {
			return new \WP_Error( 'nexo_mcp_taxonomy_denied', __( 'Taxonomía no permitida.', 'nexo-mcp' ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => (int) ( $input['per_page'] ?? 50 ),
				'offset'     => ( (int) ( $input['page'] ?? 1 ) - 1 ) * (int) ( $input['per_page'] ?? 50 ),
				'search'     => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_map(
			static fn( \WP_Term $term ): array => array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			),
			$terms
		);
	}

	/**
	 * Crea un término sin aceptar taxonomías arbitrarias.
	 *
	 * @param array<string, mixed> $input Datos del término.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_term( array $input ) {
		$taxonomy = (string) $input['taxonomy'];

		if ( ! $this->taxonomy_allowed( $taxonomy ) ) {
			return new \WP_Error( 'nexo_mcp_taxonomy_denied', __( 'Taxonomía no permitida.', 'nexo-mcp' ) );
		}

		$result = wp_insert_term(
			sanitize_text_field( (string) $input['name'] ),
			$taxonomy,
			array(
				'slug'        => sanitize_title( (string) ( $input['slug'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
				'parent'      => (int) ( $input['parent'] ?? 0 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( (int) $result['term_id'], $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'nexo_mcp_term_missing', __( 'El término fue creado, pero no pudo leerse.', 'nexo-mcp' ) );
		}

		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
		);
	}

	/**
	 * Lista revisiones de una página o artículo.
	 *
	 * @param int $post_id Contenido.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public function list_revisions( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró el contenido.', 'nexo-mcp' ) );
		}

		$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 25 ) );

		return array_values(
			array_map(
				static fn( \WP_Post $revision ): array => array(
					'id'       => $revision->ID,
					'date_gmt' => $revision->post_date_gmt,
					'author'   => (int) $revision->post_author,
					'title'    => $revision->post_title,
					'excerpt'  => wp_trim_words( wp_strip_all_tags( $revision->post_content ), 30 ),
				),
				$revisions
			)
		);
	}

	/**
	 * Restaura una revisión conservando el estado actual.
	 *
	 * @param int $revision_id Revisión.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_revision( int $revision_id ) {
		$revision = wp_get_post_revision( $revision_id );

		if ( ! $revision instanceof \WP_Post ) {
			return new \WP_Error( 'nexo_mcp_revision_not_found', __( 'No se encontró la revisión.', 'nexo-mcp' ) );
		}

		$post = get_post( $revision->post_parent );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró el contenido.', 'nexo-mcp' ) );
		}

		if ( (bool) $this->settings->get( 'drafts_only' ) && 'draft' !== $post->post_status ) {
			return new \WP_Error( 'nexo_mcp_draft_required', __( 'El modo sólo borradores impide restaurar contenido publicado.', 'nexo-mcp' ) );
		}

		$restored_id = wp_restore_post_revision( $revision_id );

		if ( ! is_int( $restored_id ) || $restored_id < 1 ) {
			return new \WP_Error( 'nexo_mcp_restore_failed', __( 'No fue posible restaurar la revisión.', 'nexo-mcp' ) );
		}

		return array(
			'id'                => $restored_id,
			'status'            => (string) get_post_status( $restored_id ),
			'restored_revision' => $revision_id,
		);
	}

	/**
	 * Cambia el estado sólo entre valores editoriales aprobados.
	 *
	 * @param int    $post_id Contenido.
	 * @param string $status  Estado nuevo.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_status( int $post_id, string $status ) {
		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish', 'trash' ), true ) ) {
			return new \WP_Error( 'nexo_mcp_status_denied', __( 'Estado no permitido.', 'nexo-mcp' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró el contenido.', 'nexo-mcp' ) );
		}

		if ( WorkingCopy::original_of( $post ) > 0 ) {
			return $this->set_copy_status( $post, $status );
		}

		// La papelera se pide por su propia función, no escribiendo el estado a
		// mano: `wp_trash_post()` guarda el estado anterior en `post_meta` y es lo
		// que permite restaurar después desde WordPress. Nunca se borra de forma
		// permanente: una equivocación del cliente MCP no debe ser irreversible.
		if ( 'trash' === $status ) {
			if ( ! wp_trash_post( $post_id ) ) {
				return new \WP_Error( 'nexo_mcp_trash_failed', __( 'WordPress no pudo enviar el contenido a la papelera.', 'nexo-mcp' ) );
			}

			return array(
				'id'      => $post_id,
				'status'  => (string) get_post_status( $post_id ),
				'link'    => '',
				'message' => __( 'El contenido está en la papelera y puede restaurarse desde WordPress.', 'nexo-mcp' ),
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'     => $post_id,
			'status' => (string) get_post_status( $post_id ),
			'link'   => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * Publica o descarta una copia de trabajo (ADR-031).
	 *
	 * Publicar la fusiona en el original; la papelera la borra. Los
	 * interruptores del MCP se miran aquí y no en la fusión, que comparte con
	 * wp-admin: allí decide el dueño y no deben frenarlo.
	 *
	 * @param \WP_Post $copy   Copia de trabajo.
	 * @param string   $status Estado pedido.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set_copy_status( \WP_Post $copy, string $status ) {
		if ( 'trash' === $status ) {
			return $this->copies->discard( $copy );
		}

		if ( 'publish' !== $status ) {
			return new \WP_Error( 'nexo_mcp_draft_status', __( 'Una copia de trabajo sólo admite publish (fusionarla en el original) o trash (descartarla).', 'nexo-mcp' ) );
		}

		if ( (bool) $this->settings->get( 'drafts_only' ) ) {
			return new \WP_Error( 'nexo_mcp_draft_required', __( 'El modo sólo borradores impide publicar la copia. Publícala desde wp-admin o desactiva «Sólo borradores».', 'nexo-mcp' ) );
		}

		return $this->copies->merge( $copy );
	}

	/**
	 * Normaliza un adjunto.
	 *
	 * @param int $attachment_id ID.
	 * @return array<string, mixed>
	 */
	private function media_detail( int $attachment_id ): array {
		$attachment = get_post( $attachment_id );

		return array(
			'id'          => $attachment_id,
			'title'       => $attachment instanceof \WP_Post ? $attachment->post_title : '',
			'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment instanceof \WP_Post ? $attachment->post_excerpt : '',
			'description' => $attachment instanceof \WP_Post ? $attachment->post_content : '',
			'mime_type'   => (string) get_post_mime_type( $attachment_id ),
			'url'         => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Valida una taxonomía contra allowlist y existencia.
	 *
	 * @param string $taxonomy Taxonomía.
	 */
	private function taxonomy_allowed( string $taxonomy ): bool {
		return in_array( $taxonomy, self::ALLOWED_TAXONOMIES, true ) && taxonomy_exists( $taxonomy );
	}
}
