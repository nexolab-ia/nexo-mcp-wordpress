<?php
/**
 * Operaciones editoriales avanzadas de WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\DiviLayoutGuard;
use NexoDev\NexoMCP\Media\UploadThrottle;
use NexoDev\NexoMCP\Media\UploadTicket;
use NexoDev\NexoMCP\Media\UploadValidator;
use NexoDev\NexoMCP\Media\ValidatedUpload;
use NexoDev\NexoMCP\Support\LineDiff;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Gestiona medios, taxonomías, revisiones y estados sin acceso genérico.
 */
final class ContentAdminGateway {

	private const ALLOWED_TAXONOMIES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );

	/**
	 * Tope del diff de contenido de una copia de trabajo, en bytes.
	 *
	 * Un diff mayor llega cortado y marcado con `content_diff_truncated`.
	 */
	public const DIFF_MAX_BYTES = 20000;

	/**
	 * Largo máximo, en caracteres, de un valor de meta que se muestra completo.
	 */
	private const META_SHORT_CHARS = 200;

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
	 * Permiso del Theme Builder para tocar un layout: fn(): bool. Por omisión, `PermissionPolicy::can_write_divi_theme_builder()`.
	 *
	 * @var callable|null
	 */
	private $layout_allowed;

	/**
	 * Construye el gateway.
	 *
	 * @param Settings            $settings       Configuración.
	 * @param UploadThrottle|null $throttle       Techo de subidas.
	 * @param UploadTicket|null   $tickets        Enlaces de subida.
	 * @param WorkingCopy|null    $copies         Copias de trabajo.
	 * @param callable|null       $layout_allowed fn(): bool. Permiso del Theme Builder (las pruebas lo inyectan); por omisión, el de la política con estos ajustes.
	 */
	public function __construct( Settings $settings, ?UploadThrottle $throttle = null, ?UploadTicket $tickets = null, ?WorkingCopy $copies = null, ?callable $layout_allowed = null ) {
		$this->settings       = $settings;
		$this->validator      = new UploadValidator();
		$this->throttle       = $throttle ?? new UploadThrottle();
		$this->tickets        = $tickets ?? new UploadTicket();
		$this->copies         = $copies ?? new WorkingCopy( $settings );
		$this->layout_allowed = $layout_allowed;
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

		// Restaurar reescribe el contenido vivo: un layout del Theme Builder pide su permiso y los demás tipos `et_*` no se restauran por aquí.
		$guard = $this->layout_guard( $post );

		if ( is_wp_error( $guard ) ) {
			return $guard;
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
	 * Compara una copia de trabajo con su original.
	 *
	 * Acepta el ID de cualquiera de los dos. «Antes» es el original y «después»
	 * la copia; la meta y los términos son los mismos que la fusión copiaría.
	 *
	 * @param int $id Original o copia.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_working_copy_diff( int $id ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'nexo_mcp_content_not_found', __( 'No se encontró el contenido.', 'nexo-mcp' ) );
		}

		// El diff enseña el contenido de un layout del Theme Builder (todo el sitio): pide su permiso, no el de una página.
		$guard = $this->layout_guard( $post );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( WorkingCopy::original_of( $post ) > 0 ) {
			$copy     = $post;
			$original = $this->copies->live_original( $copy );

			if ( null === $original ) {
				return new \WP_Error(
					'nexo_mcp_draft_orphan',
					__( 'La página original ya no existe, está en la papelera o tiene otra copia. Descarta esta copia.', 'nexo-mcp' )
				);
			}
		} else {
			$original = $post;
			$copy     = get_post( WorkingCopy::copy_of( $original ) );

			if ( ! $copy instanceof \WP_Post ) {
				return new \WP_Error( 'nexo_mcp_draft_none', __( 'Este contenido no tiene copia de trabajo ni es una.', 'nexo-mcp' ) );
			}
		}

		// Leer una copia exige poder editar el original: es un borrador suyo.
		if ( ! current_user_can( 'edit_post', $original->ID ) ) {
			return new \WP_Error(
				'nexo_mcp_content_forbidden',
				__( 'No tienes permiso para ver los cambios de este contenido.', 'nexo-mcp' )
			);
		}

		$result = array(
			'original_id' => (int) $original->ID,
			'copy_id'     => (int) $copy->ID,
			'preview_url' => (string) get_preview_post_link( $copy ),
			'stale'       => $this->copies->is_stale( $copy, $original ),
			'changed'     => array(),
		);

		$fields = array(
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
		);

		foreach ( $fields as $field => $property ) {
			if ( (string) $original->$property !== (string) $copy->$property ) {
				$result['changed'][] = $field;
				$result[ $field ]    = array(
					'before' => (string) $original->$property,
					'after'  => (string) $copy->$property,
				);
			}
		}

		$content = LineDiff::unified( (string) $original->post_content, (string) $copy->post_content, 3, self::DIFF_MAX_BYTES );

		if ( '' !== $content['diff'] ) {
			$result['changed'][]    = 'content';
			$result['content_diff'] = $content['diff'];

			if ( $content['truncated'] ) {
				$result['content_diff_truncated'] = true;
			}
		}

		$meta = $this->meta_changes( (int) $original->ID, (int) $copy->ID );

		if ( array() !== $meta['changed'] || array() !== $meta['added'] || array() !== $meta['kept_on_publish'] ) {
			$result['changed'][] = 'meta';
			$result['meta']      = $meta;
		}

		$terms = $this->term_changes( $original, $copy );

		if ( array() !== $terms ) {
			$result['changed'][] = 'terms';
			$result['terms']     = $terms;
		}

		return $result;
	}

	/**
	 * Cambios de meta entre original y copia, sólo de la que la fusión copia.
	 *
	 * Una clave que existe en el original y falta en la copia no se informa
	 * como quitada: la fusión sólo agrega o actualiza meta, nunca la borra, así
	 * que al publicar el original la conserva. Va en `kept_on_publish`, con
	 * `after` igual al valor que seguirá teniendo.
	 *
	 * @param int $original_id Original.
	 * @param int $copy_id     Copia.
	 * @return array{changed: list<array<string, mixed>>, added: list<array<string, mixed>>, kept_on_publish: list<array<string, mixed>>}
	 */
	private function meta_changes( int $original_id, int $copy_id ): array {
		$before  = $this->copies->travelling_meta( $original_id );
		$after   = $this->copies->travelling_meta( $copy_id );
		$changes = array(
			'changed'         => array(),
			'added'           => array(),
			'kept_on_publish' => array(),
		);

		foreach ( $after as $key => $values ) {
			if ( ! array_key_exists( $key, $before ) ) {
				$changes['added'][] = $this->meta_entry( $key, null, $this->meta_value( $values ) );
			} elseif ( $before[ $key ] !== $values ) {
				$changes['changed'][] = $this->meta_entry( $key, $this->meta_value( $before[ $key ] ), $this->meta_value( $values ) );
			}
		}

		foreach ( $before as $key => $values ) {
			if ( ! array_key_exists( $key, $after ) ) {
				$kept                         = $this->meta_value( $values );
				$changes['kept_on_publish'][] = $this->meta_entry( $key, $kept, $kept );
			}
		}

		return $changes;
	}

	/**
	 * Un solo valor se muestra tal cual; varios, como lista.
	 *
	 * @param array<int, mixed> $values Valores de la clave.
	 * @return mixed
	 */
	private function meta_value( array $values ) {
		return 1 === count( $values ) ? $values[0] : $values;
	}

	/**
	 * Arma la entrada de una clave: valores si ambos son escalares cortos, si no tamaños.
	 *
	 * @param string $key    Clave de meta.
	 * @param mixed  $before Valor en el original, o null si no existe.
	 * @param mixed  $after  Valor en la copia, o null si no existe.
	 * @return array<string, mixed>
	 */
	private function meta_entry( string $key, $before, $after ): array {
		if ( $this->is_short_value( $before ) && $this->is_short_value( $after ) ) {
			return array(
				'key'    => $key,
				'before' => $before,
				'after'  => $after,
			);
		}

		return array(
			'key'          => $key,
			'before_bytes' => $this->value_bytes( $before ),
			'after_bytes'  => $this->value_bytes( $after ),
		);
	}

	/**
	 * Indica si un valor es nulo o un escalar de hasta 200 caracteres.
	 *
	 * @param mixed $value Valor evaluado.
	 */
	private function is_short_value( $value ): bool {
		return null === $value || ( is_scalar( $value ) && mb_strlen( (string) $value ) <= self::META_SHORT_CHARS );
	}

	/**
	 * Tamaño en bytes de un valor de meta; 0 si no existe.
	 *
	 * @param mixed $value Valor medido.
	 */
	private function value_bytes( $value ): int {
		if ( null === $value ) {
			return 0;
		}

		return strlen( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
	}

	/**
	 * Términos agregados y quitados por taxonomía en la copia.
	 *
	 * @param \WP_Post $original Original.
	 * @param \WP_Post $copy     Copia.
	 * @return array<string, array{added: list<string>, removed: list<string>}>
	 */
	private function term_changes( \WP_Post $original, \WP_Post $copy ): array {
		$changes = array();

		foreach ( $this->copies->copied_taxonomies( $original->post_type ) as $taxonomy ) {
			$before = wp_get_object_terms( $original->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			$after  = wp_get_object_terms( $copy->ID, $taxonomy, array( 'fields' => 'slugs' ) );

			if ( is_wp_error( $before ) || is_wp_error( $after ) ) {
				continue;
			}

			$added   = array_values( array_diff( $after, $before ) );
			$removed = array_values( array_diff( $before, $after ) );

			if ( array() !== $added || array() !== $removed ) {
				$changes[ $taxonomy ] = array(
					'added'   => $added,
					'removed' => $removed,
				);
			}
		}

		return $changes;
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

	/**
	 * Guarda de tipo de contenido (T5): un layout del Theme Builder pide el permiso del Theme Builder; los demás tipos `et_*` se rechazan.
	 *
	 * @param \WP_Post $post Contenido (o la revisión ya resuelta a su padre).
	 *
	 * @return true|\WP_Error
	 */
	private function layout_guard( \WP_Post $post ) {
		return DiviLayoutGuard::check(
			$post,
			$this->layout_allowed ?? fn(): bool => ( new PermissionPolicy( $this->settings ) )->can_write_divi_theme_builder()
		);
	}
}
