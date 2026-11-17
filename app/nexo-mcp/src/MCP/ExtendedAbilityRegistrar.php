<?php
/**
 * Abilities modulares avanzadas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\DenialExplainer;
use NexoDev\NexoMCP\Authorization\IrreversibleActions;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Fields\FieldGateway;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Registra herramientas opcionales manteniendo una allowlist auditable.
 */
final class ExtendedAbilityRegistrar {

	public const ABILITIES = array(
		'nexo-mcp/list-media',
		'nexo-mcp/update-media',
		'nexo-mcp/import-media',
		'nexo-mcp/upload-media',
		'nexo-mcp/list-terms',
		'nexo-mcp/create-term',
		'nexo-mcp/list-revisions',
		'nexo-mcp/restore-revision',
		'nexo-mcp/set-content-status',
		'nexo-mcp/get-working-copy-diff',
		'nexo-mcp/get-post-seo',
		'nexo-mcp/update-post-seo',
		'nexo-mcp/get-seo-settings',
		'nexo-mcp/update-seo-settings',
		'nexo-mcp/get-site-appearance',
		'nexo-mcp/update-site-identity',
		'nexo-mcp/update-custom-css',
		'nexo-mcp/list-menus',
		'nexo-mcp/upsert-menu-item',
		'nexo-mcp/list-fields',
		'nexo-mcp/update-field',
		'nexo-mcp/list-plugins',
		'nexo-mcp/list-themes',
		'nexo-mcp/prepare-plugin-action',
		'nexo-mcp/execute-plugin-action',
		'nexo-mcp/list-products',
		'nexo-mcp/get-product',
		'nexo-mcp/upsert-product',
		'nexo-mcp/delete-product',
		'nexo-mcp/get-page-structure',
		'nexo-mcp/get-design-system',
		'nexo-mcp/update-node-text',
		'nexo-mcp/update-node-attribute',
		'nexo-mcp/restore-builder-backup',
		'nexo-mcp/edit-structure',
	);

	/**
	 * Tope de IDs por llamada en set-content-status.
	 */
	private const LOTE_MAXIMO = 50;

	/**
	 * Configuración.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Política.
	 *
	 * @var PermissionPolicy
	 */
	private PermissionPolicy $policy;

	/**
	 * Ejecutor.
	 *
	 * @var ToolExecutor
	 */
	private ToolExecutor $executor;

	/**
	 * Contenido avanzado.
	 *
	 * @var ContentAdminGateway
	 */
	private ContentAdminGateway $content;

	/**
	 * Campos personalizados.
	 *
	 * @var FieldGateway
	 */
	private FieldGateway $fields;

	/**
	 * SEO.
	 *
	 * @var SeoGateway
	 */
	private SeoGateway $seo;

	/**
	 * Apariencia.
	 *
	 * @var AppearanceGateway
	 */
	private AppearanceGateway $appearance;

	/**
	 * Mantenimiento.
	 *
	 * @var MaintenanceGateway
	 */
	private MaintenanceGateway $maintenance;

	/**
	 * WooCommerce.
	 *
	 * @var ProductGateway
	 */
	private ProductGateway $products;

	/**
	 * Constructores visuales.
	 *
	 * @var BuilderGateway
	 */
	private BuilderGateway $builders;

	/**
	 * Traductor de denegaciones.
	 *
	 * @var DenialExplainer
	 */
	private DenialExplainer $explainer;

	/**
	 * Construye el registro.
	 *
	 * @param Settings            $settings    Configuración.
	 * @param PermissionPolicy    $policy      Política.
	 * @param ToolExecutor        $executor    Ejecutor auditado.
	 * @param ContentAdminGateway $content     Contenido avanzado.
	 * @param SeoGateway          $seo         SEO.
	 * @param AppearanceGateway   $appearance  Apariencia.
	 * @param MaintenanceGateway  $maintenance Mantenimiento.
	 * @param ProductGateway      $products    WooCommerce.
	 * @param BuilderGateway|null $builders    Constructores visuales.
	 * @param FieldGateway|null   $fields      Campos personalizados.
	 */
	public function __construct(
		Settings $settings,
		PermissionPolicy $policy,
		ToolExecutor $executor,
		ContentAdminGateway $content,
		SeoGateway $seo,
		AppearanceGateway $appearance,
		MaintenanceGateway $maintenance,
		ProductGateway $products,
		?BuilderGateway $builders = null,
		?FieldGateway $fields = null
	) {
		$this->settings    = $settings;
		$this->policy      = $policy;
		$this->executor    = $executor;
		$this->content     = $content;
		$this->seo         = $seo;
		$this->appearance  = $appearance;
		$this->maintenance = $maintenance;
		$this->products    = $products;
		$this->builders    = $builders ?? new BuilderGateway();
		$this->fields      = $fields ?? new FieldGateway();
		$this->explainer   = new DenialExplainer( $settings );
	}

	/**
	 * Registra hook de Abilities API.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registra todas las abilities opcionales.
	 */
	public function register_abilities(): void {
		$this->register_content_abilities();
		$this->register_seo_abilities();
		$this->register_appearance_abilities();
		$this->register_maintenance_abilities();
		$this->register_commerce_abilities();
		$this->register_builder_abilities();
	}

	/**
	 * Constructores visuales.
	 *
	 * La lectura sólo exige el módulo activo; la escritura suma el respaldo
	 * obligatorio que aplica el gateway antes de tocar nada.
	 */
	private function register_builder_abilities(): void {
		$this->ability(
			'list-fields',
			'Listar campos personalizados',
			'Lista los campos personalizados de un contenido y cuáles admiten escritura.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->fields->list_fields( (int) $input['id'] ),
			fn( array $input ) => $this->module_read(
				'content_tools_enabled',
				Capabilities::CONTENT_ADVANCED,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'update-field',
			'Actualizar campo personalizado',
			'Escribe un campo personalizado, validado según su tipo.',
			'write',
			$this->field_schema(),
			fn( array $input ) => $this->fields->update_field(
				(int) $input['id'],
				(string) $input['name'],
				$input['value'] ?? ''
			),
			fn( array $input ) => $this->module_write(
				'content_tools_enabled',
				Capabilities::CONTENT_ADVANCED,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'get-page-structure',
			'Obtener estructura de la página',
			'Detecta el constructor visual y lista los textos, enlaces e imágenes editables de una página o entrada, sin el HTML. Es la lectura liviana previa a nexo-mcp-update-node-text. Los nodos que no se pueden editar traen editable: false y el motivo.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->builders->get_structure( $input ),
			fn( array $input ) => $this->module_read(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'get-design-system',
			'Obtener sistema de diseño de Elementor',
			'Lista el sistema de diseño de Elementor: si los elementos atómicos (v4) están activos, y las clases globales, variables, colores y tipografía del Kit activo. Úsalo antes de armar o leer páginas atómicas para reutilizar lo que ya existe.',
			'read',
			$this->empty_schema(),
			fn() => $this->builders->get_design_system(),
			fn() => $this->module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' )
		);
		$this->ability(
			'update-node-text',
			'Editar texto del constructor',
			'Reemplaza el texto de un elemento sin alterar la estructura de la página.',
			'write',
			$this->builder_text_schema(),
			fn( array $input ) => $this->builders->update_node_text( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'update-node-attribute',
			'Editar enlace o imagen del constructor',
			'Cambia el enlace, la imagen o el texto alternativo de un elemento. La imagen se indica por su identificador en la biblioteca de medios, no por URL.',
			'write',
			$this->builder_attribute_schema(),
			fn( array $input ) => $this->builders->update_node_attribute( $input ),
			// Capacidad propia y no BUILDERS: cambiar a dónde apunta un enlace no
			// es lo mismo que corregir una palabra, así que se concede aparte.
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDER_ATTRS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'restore-builder-backup',
			'Restaurar respaldo del constructor',
			'Devuelve la página al estado previo a la última edición del conector.',
			'write',
			$this->id_schema(),
			fn( array $input ) => $this->builders->restore_backup( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
		$this->ability(
			'edit-structure',
			'Duplicar elemento del constructor',
			'Duplica un elemento de Elementor (widget, columna, sección o contenedor) junto al original o antes/después de target, y devuelve los ids de la copia para editarla con nexo-mcp-update-node-text.',
			'write',
			$this->builder_structure_schema(),
			fn( array $input ) => $this->builders->edit_structure( $input ),
			fn( array $input ) => $this->module_write(
				'builders_tools_enabled',
				Capabilities::BUILDERS,
				'edit_post',
				(int) ( $input['id'] ?? 0 )
			)
		);
	}

	/**
	 * Esquema de duplicación de elementos en un constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_structure_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id'  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'target'   => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'position' => array(
					'type' => 'string',
					'enum' => array( 'before', 'after' ),
				),
			),
			'required'             => array( 'id', 'node_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de edición de texto en un constructor.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_text_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'text'    => array(
					'type'      => 'string',
					'maxLength' => 65535,
				),
			),
			'required'             => array( 'id', 'node_id', 'text' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de edición de atributos en un constructor.
	 *
	 * `value` acepta texto o número porque el tipo depende del atributo: una
	 * dirección para el enlace, un identificador de la biblioteca para la
	 * imagen. Declararlo así evita una herramienta por atributo.
	 *
	 * @return array<string, mixed>
	 */
	private function builder_attribute_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'node_id'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				),
				'attribute' => array(
					'type'        => 'string',
					'description' => 'Atributo a cambiar.',
					'enum'        => array( 'link', 'image', 'alt' ),
				),
				'value'     => array(
					'type'        => array( 'string', 'integer' ),
					'description' => 'Para link, la dirección (https, mailto, tel o ruta que empiece por «/»). Para image, el identificador del adjunto. Para alt, el texto.',
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'id', 'node_id', 'attribute', 'value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Contenido avanzado.
	 */
	private function register_content_abilities(): void {
		$this->ability(
			'list-media',
			'Listar medios',
			'Lista adjuntos y sus metadatos editoriales.',
			'read',
			$this->pagination_schema(),
			fn( array $input ) => $this->content->list_media( $input ),
			fn() => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'update-media',
			'Actualizar medio',
			'Actualiza título, texto alternativo, leyenda y descripción.',
			'write',
			$this->media_update_schema(),
			fn( array $input ) => $this->content->update_media( $input ),
			fn( array $input ) => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'import-media',
			'Importar medio',
			'Importa una imagen o PDF desde una URL HTTPS.',
			'write',
			$this->media_import_schema(),
			fn( array $input ) => $this->content->import_media( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'upload-media',
			'Subir medio',
			'Sube una imagen o PDF en base64 (hasta 2 MB). Sin content_base64 devuelve una URL de un solo uso para subirlo con curl, sin pasar el archivo por el contexto.',
			'write',
			$this->media_upload_schema(),
			fn( array $input ) => $this->content->upload_media( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'upload_files' )
		);
		$this->ability(
			'list-terms',
			'Listar términos',
			'Lista categorías y etiquetas aprobadas.',
			'read',
			$this->term_list_schema(),
			fn( array $input ) => $this->content->list_terms( $input ),
			fn() => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'read' )
		);
		$this->ability(
			'create-term',
			'Crear término',
			'Crea una categoría o etiqueta aprobada.',
			'write',
			$this->term_create_schema(),
			fn( array $input ) => $this->content->create_term( $input ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'manage_categories' )
		);
		$this->ability(
			'list-revisions',
			'Listar revisiones',
			'Lista hasta 25 revisiones de una página o artículo.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->content->list_revisions( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'read_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'restore-revision',
			'Restaurar revisión',
			'Restaura una revisión respetando el modo sólo borradores. Es irreversible para el contenido actual: exige "confirmation": "RESTORE" tras la aprobación de la persona.',
			'write',
			$this->confirmed_id_schema( 'restore-revision' ),
			fn( array $input ) => $this->content->restore_revision( (int) $input['id'] ),
			fn() => $this->module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_posts' )
		);
		$this->ability(
			'set-content-status',
			'Cambiar estado de contenido',
			'Publica, cambia el estado o envía a la papelera cuando el módulo de publicación está habilitado. Usa id, o ids para un lote de hasta 50.',
			'write',
			$this->status_schema(),
			fn( array $input ) => isset( $input['ids'] )
				? $this->set_status_batch( $input )
				: $this->content->set_status( (int) $input['id'], (string) $input['status'] ),
			fn( array $input ) => $this->status_permission( $input )
		);
		$this->ability(
			'get-working-copy-diff',
			'Comparar copia de trabajo',
			'Muestra qué cambió una copia de trabajo respecto a su original: título, extracto, un diff del contenido por líneas, metadatos y categorías o etiquetas, y si el original cambió desde que se creó la copia (stale). Úsala antes de pedirle a la persona que publique o descarte una copia, para explicarle exactamente qué va a cambiar. En meta, kept_on_publish lista claves que faltan en la copia pero que el original conserva al publicar (publicar nunca borra meta). Acepta el id del original o el de la copia (el que devuelve update-page o update-post).',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->content->get_working_copy_diff( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_post', WorkingCopy::original_id_for( (int) ( $input['id'] ?? 0 ) ) )
		);
	}

	/**
	 * Permiso de set-content-status, con un id o con un lote.
	 *
	 * Con `ids` aquí sólo se exigen los interruptores y las capacidades del
	 * conector: la capacidad de WordPress se comprueba después, elemento por
	 * elemento, igual que en una llamada individual. Así un contenido ajeno
	 * cae en `failed` sin frenar al resto.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function status_permission( array $input ) {
		if ( isset( $input['id'] ) === isset( $input['ids'] ) ) {
			return new \WP_Error(
				'nexo_mcp_status_target',
				__( 'Indica id o ids, uno de los dos.', 'nexo-mcp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $input['ids'] ) ) {
			return $this->can_set_status( (int) $input['id'], (string) ( $input['status'] ?? '' ) );
		}

		$total = is_array( $input['ids'] ) ? count( $input['ids'] ) : 0;

		// El esquema ya lo limita; se repite para no depender de que lo valide
		// quien llame a la herramienta.
		if ( $total < 1 || $total > self::LOTE_MAXIMO ) {
			return new \WP_Error(
				'nexo_mcp_status_batch_size',
				sprintf(
					/* translators: %d: máximo de IDs por llamada. */
					__( 'ids admite de 1 a %d elementos.', 'nexo-mcp' ),
					self::LOTE_MAXIMO
				),
				array( 'status' => 400 )
			);
		}

		return $this->module_write( 'publishing_enabled', Capabilities::PUBLISH, 'read' );
	}

	/**
	 * Permiso de cambiar el estado de un contenido concreto.
	 *
	 * Es el de la llamada individual y el que se aplica a cada elemento de un
	 * lote: el mismo punto para que las dos vías no puedan divergir.
	 *
	 * @param int    $post_id Contenido.
	 * @param string $status  Estado pedido.
	 */
	private function can_set_status( int $post_id, string $status ): bool {
		$post     = get_post( $post_id );
		$original = $post instanceof \WP_Post ? WorkingCopy::original_of( $post ) : 0;

		if ( $original > 0 ) {
			return $this->can_set_copy_status( $post, $original, $status );
		}

		return $this->module_write(
			'publishing_enabled',
			Capabilities::PUBLISH,
			'trash' === $status ? 'delete_post' : 'publish_posts',
			$post_id
		);
	}

	/**
	 * Permiso sobre una copia de trabajo (ADR-031).
	 *
	 * Rige lo que se hace con el original, no con la copia: descartarla pide
	 * editarlo, sin «Acciones destructivas» porque la copia no es contenido
	 * real, y publicarla pide además la capacidad de publicar el tipo. Si el
	 * original ya no existe se mira la copia, para que la fusión se rechace
	 * con su causa y una copia huérfana se pueda descartar.
	 *
	 * @param \WP_Post $copy     Copia de trabajo.
	 * @param int      $original ID del original.
	 * @param string   $status   Estado pedido.
	 */
	private function can_set_copy_status( \WP_Post $copy, int $original, string $status ): bool {
		$target = get_post( $original ) instanceof \WP_Post ? $original : (int) $copy->ID;

		if ( ! $this->module_write( 'publishing_enabled', Capabilities::PUBLISH, 'edit_post', $target ) ) {
			return false;
		}

		return 'publish' !== $status || current_user_can( 'page' === $copy->post_type ? 'publish_pages' : 'publish_posts' );
	}

	/**
	 * Cambia el estado de un lote, elemento por elemento.
	 *
	 * Una falla no detiene al resto y la respuesta sólo trae los IDs: devolver
	 * cada contenido entero gastaría en tokens lo que el lote ahorra en
	 * llamadas (ADR-030).
	 *
	 * @param array<string, mixed> $input Parámetros validados.
	 * @return array<string, mixed>
	 */
	private function set_status_batch( array $input ): array {
		$status = (string) $input['status'];
		$ids    = array_values( array_unique( array_map( 'intval', (array) $input['ids'] ) ) );
		$ok     = array();
		$failed = array();

		// Una consulta para todo el lote en vez de una por elemento.
		_prime_post_caches( $ids, false, false );

		foreach ( $ids as $id ) {
			try {
				$result = $this->can_set_status( $id, $status )
					? $this->content->set_status( $id, $status )
					: new \WP_Error( DenialExplainer::CODE, __( 'Tu usuario no puede cambiar este contenido.', 'nexo-mcp' ) );
			} catch ( \Throwable $exception ) {
				$result = new \WP_Error( 'nexo_mcp_tool_failed', __( 'La herramienta no pudo completar la solicitud.', 'nexo-mcp' ) );
			}

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'    => $id,
					'error' => $result->get_error_message(),
				);
				continue;
			}

			$ok[] = $id;
		}

		return array(
			'status' => $status,
			'ok'     => $ok,
			'failed' => $failed,
		);
	}

	/**
	 * SEO.
	 */
	private function register_seo_abilities(): void {
		$this->ability(
			'get-post-seo',
			'Obtener SEO',
			'Obtiene metadatos SEO propios de una entrada.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->seo->get_post_seo( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'seo_tools_enabled', Capabilities::SEO, 'read_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'update-post-seo',
			'Actualizar SEO',
			'Actualiza title, description, canonical, Open Graph, noindex y Schema.',
			'write',
			$this->seo_schema(),
			fn( array $input ) => $this->seo->update_post_seo( $input ),
			fn( array $input ) => $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'get-seo-settings',
			'Obtener ajustes SEO',
			'Obtiene estado del sitemap y reglas adicionales de robots.',
			'read',
			$this->empty_schema(),
			fn() => $this->seo->get_settings(),
			fn() => $this->module_read( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
		);
		$this->ability(
			'update-seo-settings',
			'Actualizar ajustes SEO',
			'Controla sitemap nativo y reglas Disallow validadas.',
			'write',
			$this->seo_settings_schema(),
			fn( array $input ) => $this->seo->update_settings( $input ),
			fn() => $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
		);
	}

	/**
	 * Apariencia.
	 */
	private function register_appearance_abilities(): void {
		$this->ability(
			'get-site-appearance',
			'Obtener apariencia global',
			'Obtiene identidad, tema activo y CSS adicional.',
			'read',
			$this->empty_schema(),
			fn() => $this->appearance->get_site_appearance(),
			fn() => $this->module_read( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' )
		);
		$this->ability(
			'update-site-identity',
			'Actualizar identidad del sitio',
			'Actualiza nombre, descripción, zona horaria y visibilidad en buscadores.',
			'write',
			$this->identity_schema(),
			fn( array $input ) => $this->appearance->update_site_identity( $input ),
			fn( array $input ) => $this->identity_permission( $input )
		);
		$this->ability(
			'update-custom-css',
			'Actualizar CSS global',
			'Actualiza CSS adicional sin editar archivos del tema.',
			'write',
			$this->css_schema(),
			fn( array $input ) => $this->appearance->update_custom_css( (string) $input['css'] ),
			fn() => $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_css' )
		);
		$this->ability(
			'list-menus',
			'Listar menús',
			'Lista menús clásicos y sus elementos.',
			'read',
			$this->empty_schema(),
			fn() => $this->appearance->list_menus(),
			fn() => $this->module_read( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' )
		);
		$this->ability(
			'upsert-menu-item',
			'Crear, actualizar o quitar elemento de menú',
			'Gestiona un elemento de menú clásico. delete:true con item_id lo quita (permiso destructivo).',
			'write',
			$this->menu_item_schema(),
			fn( array $input ) => $this->appearance->upsert_menu_item( $input ),
			fn( array $input ) => $this->menu_item_permission( $input )
		);
	}

	/**
	 * Permiso de upsert-menu-item.
	 *
	 * Quitar un elemento no pasa por la papelera, así que pide además «Acciones
	 * destructivas», y si falta lo dice con nombre para que la IA no reintente.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function menu_item_permission( array $input ) {
		if ( ! $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'edit_theme_options' ) ) {
			return false;
		}

		if ( empty( $input['delete'] ) ) {
			return true;
		}

		if ( ! (bool) $this->settings->get( 'destructive_enabled' ) ) {
			return new \WP_Error(
				DenialExplainer::CODE,
				__( 'Quitar elementos del menú requiere activar «Acciones destructivas» en el panel Nexo MCP.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		return $this->policy->can_destroy( 'delete_post', (int) ( $input['item_id'] ?? 0 ) );
	}

	/**
	 * Permiso de update-site-identity, campo por campo.
	 *
	 * Nombre, descripción y zona horaria son del módulo de apariencia; la
	 * visibilidad en buscadores es SEO, y ocultar el sitio pide además
	 * «Acciones destructivas»: sacarlo de Google cuesta semanas recuperarlo.
	 * Basta un campo sin permiso para negar la llamada entera, porque aplicar
	 * la mitad dejaría el sitio en un estado que nadie pidió.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 * @return bool|\WP_Error
	 */
	private function identity_permission( array $input ) {
		$visibilidad = array_key_exists( 'search_visible', $input );
		$apariencia  = array_intersect_key( $input, array_flip( array( 'name', 'description', 'timezone' ) ) );

		// Sin campos se exige el permiso que la herramienta tuvo siempre.
		if ( ( array() !== $apariencia || ! $visibilidad )
			&& ! $this->module_write( 'appearance_tools_enabled', Capabilities::APPEARANCE, 'manage_options' )
		) {
			return false;
		}

		if ( ! $visibilidad ) {
			return true;
		}

		$ocultar = ! rest_sanitize_boolean( $input['search_visible'] );

		if ( $ocultar && ! (bool) $this->settings->get( 'destructive_enabled' ) ) {
			return new \WP_Error(
				DenialExplainer::CODE,
				__( 'Ocultar el sitio de los buscadores requiere activar «Acciones destructivas» en el panel Nexo MCP.', 'nexo-mcp' ),
				array( 'status' => 403 )
			);
		}

		return $this->module_write( 'seo_tools_enabled', Capabilities::SEO, 'manage_options' )
			&& ( ! $ocultar || $this->policy->can_destroy( 'manage_options' ) );
	}

	/**
	 * Mantenimiento.
	 */
	private function register_maintenance_abilities(): void {
		$this->ability(
			'list-plugins',
			'Listar plugins',
			'Lista plugins, estado y actualizaciones disponibles.',
			'read',
			$this->empty_schema(),
			fn() => $this->maintenance->list_plugins(),
			fn() => $this->module_read( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'update_plugins' )
		);
		$this->ability(
			'list-themes',
			'Listar temas',
			'Lista temas instalados, cuál está activo y sus actualizaciones.',
			'read',
			$this->empty_schema(),
			fn() => $this->maintenance->list_themes(),
			fn() => $this->module_read( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'switch_themes' )
		);
		$this->ability(
			'prepare-plugin-action',
			'Preparar acción de plugin o tema',
			'Valida una actualización, activación, desactivación o instalación de plugin o tema y genera aprobación temporal. Si el panel lo permite, también borra plugins o temas desactivados.',
			'write',
			$this->plugin_action_schema(),
			fn( array $input ) => $this->maintenance->prepare_action( $input ),
			fn( array $input ) => $this->module_write(
				'maintenance_tools_enabled',
				Capabilities::MAINTENANCE,
				$this->maintenance_capability( (string) ( $input['action'] ?? '' ), (string) ( $input['target'] ?? 'plugin' ) )
			)
		);
		$this->ability(
			'execute-plugin-action',
			'Ejecutar acción de plugin',
			'Consume una aprobación temporal y ejecuta una sola operación.',
			'write',
			$this->approval_schema(),
			fn( array $input ) => $this->maintenance->execute_action( (string) $input['approval_token'] ),
			fn() => $this->module_write( 'maintenance_tools_enabled', Capabilities::MAINTENANCE, 'update_plugins' )
		);
	}

	/**
	 * WooCommerce.
	 */
	private function register_commerce_abilities(): void {
		$this->ability(
			'list-products',
			'Listar productos',
			'Lista productos WooCommerce mediante CRUD oficial.',
			'read',
			$this->product_list_schema(),
			fn( array $input ) => $this->products->list_products( $input ),
			fn() => $this->module_read( 'woocommerce_tools_enabled', Capabilities::COMMERCE, 'edit_products' )
		);
		$this->ability(
			'get-product',
			'Obtener producto',
			'Obtiene un producto simple o variable con sus variaciones.',
			'read',
			$this->id_schema(),
			fn( array $input ) => $this->products->get_product( (int) $input['id'] ),
			fn( array $input ) => $this->module_read( 'woocommerce_tools_enabled', Capabilities::COMMERCE, 'edit_post', (int) ( $input['id'] ?? 0 ) )
		);
		$this->ability(
			'upsert-product',
			'Crear o actualizar producto',
			'Gestiona productos simples, variables, atributos, precios, stock e imágenes.',
			'write',
			$this->product_schema(),
			fn( array $input ) => $this->products->upsert_product( $input ),
			fn( array $input ) => $this->module_write(
				'woocommerce_tools_enabled',
				Capabilities::COMMERCE,
				! empty( $input['id'] ) ? 'edit_post' : 'edit_products',
				...( ! empty( $input['id'] ) ? array( (int) $input['id'] ) : array() )
			)
		);
		$this->ability(
			'delete-product',
			'Eliminar producto',
			'Envía un producto a papelera o lo elimina definitivamente con permiso destructivo.',
			'write',
			$this->product_delete_schema(),
			fn( array $input ) => $this->products->delete_product( (int) $input['id'], ! empty( $input['force'] ) ),
			fn( array $input ) => (bool) $this->settings->get( 'woocommerce_tools_enabled' )
				&& $this->policy->can_destroy( 'delete_post', (int) ( $input['id'] ?? 0 ) )
				&& current_user_can( Capabilities::COMMERCE )
		);
	}

	/**
	 * Registra una ability con ejecución auditada.
	 *
	 * @param string               $name        Nombre corto.
	 * @param string               $label       Etiqueta.
	 * @param string               $description Descripción.
	 * @param string               $operation   read o write.
	 * @param array<string, mixed> $input_schema Esquema.
	 * @param callable             $callback    Negocio.
	 * @param callable             $permission  Autorización.
	 */
	private function ability(
		string $name,
		string $label,
		string $description,
		string $operation,
		array $input_schema,
		callable $callback,
		callable $permission
	): void {
		$array_outputs = array( 'list-media', 'list-terms', 'list-revisions', 'list-menus', 'list-plugins', 'list-themes' );

		// MCP exige que `outputSchema` describa un objeto. El adaptador envuelve
		// por su cuenta los esquemas que no lo son, pero esa transformación no
		// ocurre en todos los entornos y un cliente estricto descarta entonces el
		// catálogo completo. El envoltorio se declara aquí para que el contrato
		// sea el mismo en cualquier servidor.
		$wraps_result  = in_array( $name, $array_outputs, true );
		$output_schema = $wraps_result
			? array(
				'type'                 => 'object',
				'properties'           => array(
					'result' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
				),
				'required'             => array( 'result' ),
				'additionalProperties' => false,
			)
			: array(
				'type'                 => 'object',
				'additionalProperties' => true,
			);

		wp_register_ability(
			'nexo-mcp/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => AbilityRegistrar::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => function ( array $input ) use ( $name, $operation, $callback, $wraps_result ) {
					// Acciones irreversibles: sin la palabra exacta no se ejecuta.
					$confirmed = IrreversibleActions::check( $name, $input );

					if ( is_wp_error( $confirmed ) ) {
						return $this->executor->denied( $name, $operation, $input, $confirmed );
					}

					$output = 'read' === $operation
						? $this->executor->read( $name, $input, fn() => $callback( $input ) )
						: $this->executor->write( $name, $input, fn() => $callback( $input ) );

					// El envoltorio acompaña al esquema declarado más arriba: si el
					// contrato promete un objeto con `result`, la salida debe traerlo.
					if ( $wraps_result && ! is_wp_error( $output ) ) {
						return array( 'result' => $output );
					}

					return $output;
				},
				// Todas las abilities extendidas pasan por acá, así que basta este
				// punto para que una denegación diga su causa en vez del
				// «Permission denied» que el adaptador emite ante un `false`.
				// El parámetro es opcional porque la Abilities API sólo lo entrega
				// cuando la ability declara un esquema de entrada, y este envoltorio
				// cubre a todas por igual.
				'permission_callback' => function ( array $input = array() ) use ( $name, $permission, $operation ) {
					$allowed = $permission( $input );

					if ( true === $allowed ) {
						return true;
					}

					$reason = is_wp_error( $allowed ) ? $allowed : $this->explainer->explain( $operation );

					return $this->executor->denied( $name, $operation, $input, $reason );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => 'read' === $operation,
						'destructive' => in_array( $name, array( 'delete-product', 'execute-plugin-action', 'restore-revision' ), true ),
						'idempotent'  => in_array( $name, array( 'get-post-seo', 'get-seo-settings', 'get-site-appearance', 'get-product' ), true ),
					),
				),
			)
		);
	}

	/**
	 * Permiso de lectura modular.
	 *
	 * @param string $setting  Interruptor.
	 * @param string $nexo_cap Capacidad Nexo.
	 * @param string $wp_cap   Capacidad WordPress.
	 * @param mixed  ...$args  Argumentos de meta capability.
	 */
	private function module_read( string $setting, string $nexo_cap, string $wp_cap, ...$args ): bool {
		return $this->policy->can_module_read( $setting, $nexo_cap, $wp_cap, ...$args );
	}

	/**
	 * Permiso de escritura modular.
	 *
	 * @param string $setting  Interruptor.
	 * @param string $nexo_cap Capacidad Nexo.
	 * @param string $wp_cap   Capacidad WordPress.
	 * @param mixed  ...$args  Argumentos de meta capability.
	 */
	private function module_write( string $setting, string $nexo_cap, string $wp_cap, ...$args ): bool {
		return $this->policy->can_module_write( $setting, $nexo_cap, $wp_cap, ...$args );
	}

	/**
	 * Esquemas compartidos.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_schema(): array {
		return array(
			'type'                 => 'object',
			// Un arreglo PHP vacío se serializa como `[]`, y JSON Schema exige un
			// objeto en `properties`. Los clientes MCP que validan el catálogo
			// rechazan la lista completa por ese detalle, así que se fuerza `{}`
			// en lugar de confiar en que otra capa lo normalice.
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de identificador con confirmación de acción irreversible.
	 *
	 * @param string $tool Nombre corto de la herramienta.
	 * @return array<string, mixed>
	 */
	private function confirmed_id_schema( string $tool ): array {
		$schema = $this->id_schema();

		$schema['properties']['confirmation'] = IrreversibleActions::schema_for( $tool );
		$schema['required'][]                 = 'confirmation';

		return $schema;
	}

	/**
	 * Devuelve el esquema de identificador.
	 *
	 * @return array<string, mixed>
	 */
	private function id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de paginación.
	 *
	 * @return array<string, mixed>
	 */
	private function pagination_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de actualización de medios.
	 *
	 * @return array<string, mixed>
	 */
	private function media_update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'         => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'caption'     => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 20000,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de importación de medios.
	 *
	 * @return array<string, mixed>
	 */
	private function media_import_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'   => array(
					'type'      => 'string',
					'format'    => 'uri',
					'maxLength' => 2000,
				),
				'title' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'   => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de carga directa de medios.
	 *
	 * El tope de `content_base64` son 2 MB de archivo ya codificados, que en
	 * base64 ocupan un tercio más. Cortarlo en el esquema evita que un cliente
	 * mande megabytes de texto para que se rechacen al final. Es opcional: sin
	 * él la herramienta entrega un enlace de subida (ADR-030).
	 *
	 * @return array<string, mixed>
	 */
	private function media_upload_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_base64' => array(
					'type'        => 'string',
					'description' => 'Contenido del archivo codificado en base64.',
					'maxLength'   => 2796204,
				),
				'filename'       => array(
					'type'        => 'string',
					'description' => 'Nombre propuesto. La extensión final la decide el contenido real.',
					'maxLength'   => 200,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'            => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de listado de términos.
	 *
	 * @return array<string, mixed>
	 */
	private function term_list_schema(): array {
		$schema                           = $this->pagination_schema();
		$schema['properties']['taxonomy'] = array(
			'type' => 'string',
			'enum' => array( 'category', 'post_tag', 'product_cat', 'product_tag' ),
		);
		$schema['required']               = array( 'taxonomy' );
		return $schema;
	}

	/**
	 * Devuelve el esquema de creación de términos.
	 *
	 * @return array<string, mixed>
	 */
	private function term_create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag', 'product_cat', 'product_tag' ),
				),
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'slug'        => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9-]+$',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de estados.
	 *
	 * @return array<string, mixed>
	 */
	private function field_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'  => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'value' => array(
					'type' => array( 'string', 'number', 'boolean' ),
				),
			),
			'required'             => array( 'id', 'name', 'value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de estados.
	 *
	 * @return array<string, mixed>
	 */
	private function status_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				// Sin `oneOf` para exigir uno de los dos: los clientes que lo
				// rechazan en la raíz descartarían el catálogo. Lo exige el permiso.
				'ids'    => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::LOTE_MAXIMO,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'status' => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'private', 'publish', 'trash' ),
				),
			),
			'required'             => array( 'status' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema SEO por contenido.
	 *
	 * @return array<string, mixed>
	 */
	private function seo_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'canonical'      => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'noindex'        => array( 'type' => 'boolean' ),
				'og_title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'og_description' => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'og_image'       => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'schema'         => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema SEO global.
	 *
	 * @return array<string, mixed>
	 */
	private function seo_settings_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sitemaps_enabled' => array( 'type' => 'boolean' ),
				'disallow_paths'   => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de identidad.
	 *
	 * @return array<string, mixed>
	 */
	private function identity_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'           => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'timezone'       => array(
					'type'        => 'string',
					'description' => 'Zona IANA, p. ej. America/Santiago.',
					'maxLength'   => 64,
				),
				'search_visible' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de CSS.
	 *
	 * @return array<string, mixed>
	 */
	private function css_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'css' => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
			),
			'required'             => array( 'css' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de elementos de menú.
	 *
	 * @return array<string, mixed>
	 */
	private function menu_item_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'menu_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'item_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'url'       => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'object_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'parent'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'position'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'delete'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'menu_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de acciones de plugins.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_action_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action' => array(
					'type' => 'string',
					'enum' => array( 'update', 'activate', 'deactivate', 'install', 'delete' ),
				),
				'target' => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
				'plugin' => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'theme'  => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'slug'   => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9-]+$',
					'maxLength' => 200,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Traduce acción y objetivo a la capacidad que WordPress exige.
	 *
	 * Un tema y un plugin no comparten permisos: quien puede actualizar plugins
	 * no necesariamente puede cambiar el aspecto del sitio.
	 *
	 * @param string $action Acción solicitada.
	 * @param string $target plugin o theme.
	 */
	private function maintenance_capability( string $action, string $target ): string {
		if ( 'theme' === $target ) {
			if ( 'install' === $action ) {
				return 'install_themes';
			}

			if ( 'delete' === $action ) {
				return 'delete_themes';
			}

			return 'activate' === $action ? 'switch_themes' : 'update_themes';
		}

		if ( 'install' === $action ) {
			return 'install_plugins';
		}

		if ( 'delete' === $action ) {
			return 'delete_plugins';
		}

		return in_array( $action, array( 'activate', 'deactivate' ), true ) ? 'activate_plugins' : 'update_plugins';
	}

	/**
	 * Devuelve el esquema de aprobación.
	 *
	 * @return array<string, mixed>
	 */
	private function approval_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'approval_token' => array(
					'type'      => 'string',
					'minLength' => 20,
					'maxLength' => 100,
				),
			),
			'required'             => array( 'approval_token' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de listado de productos.
	 *
	 * @return array<string, mixed>
	 */
	private function product_list_schema(): array {
		$schema                         = $this->pagination_schema();
		$schema['properties']['status'] = array(
			'type'    => 'string',
			'enum'    => array( 'any', 'draft', 'pending', 'private', 'publish' ),
			'default' => 'any',
		);
		$schema['properties']['type']   = array(
			'type' => 'string',
			'enum' => array( 'simple', 'variable' ),
		);
		$schema['properties']['sku']    = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		return $schema;
	}

	/**
	 * Devuelve el esquema de producto.
	 *
	 * @return array<string, mixed>
	 */
	private function product_schema(): array {
		$price = array(
			'type'    => 'string',
			'pattern' => '^[0-9]+(?:\\.[0-9]{1,6})?$',
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'type'              => array(
					'type'    => 'string',
					'enum'    => array( 'simple', 'variable' ),
					'default' => 'simple',
				),
				'name'              => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 300,
				),
				'slug'              => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'       => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'short_description' => array(
					'type'      => 'string',
					'maxLength' => 10000,
				),
				'sku'               => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'regular_price'     => $price,
				'sale_price'        => $price,
				'stock_status'      => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'manage_stock'      => array( 'type' => 'boolean' ),
				'stock_quantity'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => 'Producto sin envío. Actívalo en servicios y productos digitales o WooCommerce pedirá dirección de entrega.',
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => 'El comprador recibe un archivo. Marca también el producto como virtual si no se indica lo contrario.',
				),
				'status'            => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'pending', 'private', 'publish' ),
					'default' => 'draft',
				),
				'categories'        => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'images'            => array(
					'type'     => 'array',
					'maxItems' => 21,
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'attributes'        => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => $this->product_attribute_schema(),
				),
				'variations'        => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => $this->variation_schema( $price ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de atributo.
	 *
	 * @return array<string, mixed>
	 */
	private function product_attribute_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'options'   => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
				'visible'   => array( 'type' => 'boolean' ),
				'variation' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'name', 'options' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de variación.
	 *
	 * @param array<string, mixed> $price Esquema de precio.
	 * @return array<string, mixed>
	 */
	private function variation_schema( array $price ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sku'            => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'regular_price'  => $price,
				'sale_price'     => $price,
				'stock_status'   => array(
					'type' => 'string',
					'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'manage_stock'   => array( 'type' => 'boolean' ),
				'stock_quantity' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'attributes'     => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Devuelve el esquema de eliminación.
	 *
	 * @return array<string, mixed>
	 */
	private function product_delete_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'force'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'confirmation' => IrreversibleActions::schema_for( 'delete-product' ),
			),
			'required'             => array( 'id', 'confirmation' ),
			'additionalProperties' => false,
		);
	}
}
