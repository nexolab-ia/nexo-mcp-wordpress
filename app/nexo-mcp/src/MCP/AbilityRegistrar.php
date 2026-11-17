<?php
/**
 * Registro explícito de abilities WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Authorization\DenialExplainer;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\ReadGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;
use NexoDev\NexoMCP\WordPress\WriteGateway;

/**
 * Publica sólo las herramientas aprobadas para las etapas 4 y 5.
 */
final class AbilityRegistrar {

	public const CATEGORY = 'nexo-mcp';

	public const ABILITIES = array(
		'nexo-mcp/get-site-info',
		'nexo-mcp/list-pages',
		'nexo-mcp/get-page',
		'nexo-mcp/list-posts',
		'nexo-mcp/get-post',
		'nexo-mcp/create-page',
		'nexo-mcp/update-page',
		'nexo-mcp/create-post',
		'nexo-mcp/update-post',
	);

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Política acumulativa de permisos.
	 *
	 * @var PermissionPolicy
	 */
	private PermissionPolicy $permission_policy;

	/**
	 * Gateway de WordPress.
	 *
	 * @var ReadGateway
	 */
	private ReadGateway $gateway;

	/**
	 * Ejecutor con auditoría.
	 *
	 * @var ToolExecutor
	 */
	private ToolExecutor $executor;

	/**
	 * Gateway de escritura.
	 *
	 * @var WriteGateway
	 */
	private WriteGateway $write_gateway;

	/**
	 * Traductor de denegaciones.
	 *
	 * @var DenialExplainer
	 */
	private DenialExplainer $explainer;

	/**
	 * Construye el registro.
	 *
	 * @param Settings         $settings          Configuración operativa.
	 * @param PermissionPolicy $permission_policy Política de permisos.
	 * @param ReadGateway      $gateway           Lecturas de WordPress.
	 * @param ToolExecutor     $executor          Ejecutor auditado.
	 * @param WriteGateway     $write_gateway     Escrituras de WordPress.
	 */
	public function __construct(
		Settings $settings,
		PermissionPolicy $permission_policy,
		ReadGateway $gateway,
		ToolExecutor $executor,
		WriteGateway $write_gateway
	) {
		$this->settings          = $settings;
		$this->permission_policy = $permission_policy;
		$this->gateway           = $gateway;
		$this->executor          = $executor;
		$this->write_gateway     = $write_gateway;
		$this->explainer         = new DenialExplainer( $settings );
	}

	/**
	 * Registra hooks de la Abilities API.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registra la categoría visible.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Nexo MCP', 'nexo-mcp' ),
				'description' => __( 'Herramientas seguras del conector Nexo MCP.', 'nexo-mcp' ),
			)
		);
	}

	/**
	 * Registra las abilities de sólo lectura.
	 */
	public function register_abilities(): void {
		$this->register_site_info();
		$this->register_list( 'pages', 'page' );
		$this->register_get( 'page', 'page' );
		$this->register_list( 'posts', 'post' );
		$this->register_get( 'post', 'post' );
		$this->register_create( 'page' );
		$this->register_update( 'page' );
		$this->register_create( 'post' );
		$this->register_update( 'post' );
	}

	/**
	 * Registra una herramienta de creación.
	 *
	 * @param string $post_type page o post.
	 */
	private function register_create( string $post_type ): void {
		$capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		wp_register_ability(
			'nexo-mcp/create-' . $post_type,
			array(
				'label'               => sprintf(
					/* translators: %s: tipo de contenido. */
					__( 'Crear %s', 'nexo-mcp' ),
					$post_type
				),
				'description'         => __( 'Crea contenido WordPress siempre como borrador.', 'nexo-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->write_input_schema( true ),
				'output_schema'       => $this->detail_schema(),
				'execute_callback'    => fn( array $input ) => $this->executor->write(
					'create_' . $post_type,
					$input,
					fn() => $this->write_gateway->create( $post_type, $input )
				),
				'permission_callback' => fn( array $input = array() ) => $this->guard(
					'create_' . $post_type,
					'write',
					$input,
					$this->can_write( $capability )
				),
			)
		);
	}

	/**
	 * Registra una herramienta de actualización.
	 *
	 * @param string $post_type page o post.
	 */
	private function register_update( string $post_type ): void {
		wp_register_ability(
			'nexo-mcp/update-' . $post_type,
			array(
				'label'               => sprintf(
					/* translators: %s: tipo de contenido. */
					__( 'Actualizar %s', 'nexo-mcp' ),
					$post_type
				),
				'description'         => __( 'Actualiza contenido existente sin publicar ni cambiar su estado. Reescribe el contenido completo: para cambiar un texto, enlace o imagen puntual usa nexo-mcp-update-node-text o nexo-mcp-update-node-attribute.', 'nexo-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->write_input_schema( false ),
				'output_schema'       => $this->update_output_schema(),
				'execute_callback'    => fn( array $input ) => $this->executor->write(
					'update_' . $post_type,
					$input,
					fn() => $this->write_gateway->update( (int) $input['id'], $post_type, $input )
				),
				'permission_callback' => fn( array $input = array() ) => $this->can_update( $input, $post_type ),
			)
		);
	}

	/**
	 * Registra get_site_info.
	 */
	private function register_site_info(): void {
		wp_register_ability(
			'nexo-mcp/get-site-info',
			array(
				'label'               => __( 'Obtener información del sitio', 'nexo-mcp' ),
				'description'         => __( 'Devuelve datos básicos no sensibles del sitio WordPress.', 'nexo-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->empty_input_schema(),
				'output_schema'       => $this->site_info_schema(),
				'execute_callback'    => fn( array $input ) => $this->executor->read(
					'get_site_info',
					$input,
					fn() => $this->gateway->get_site_info()
				),
				'permission_callback' => fn( array $input = array() ) => $this->guard(
					'get_site_info',
					'read',
					$input,
					$this->can_read( 'read' )
				),
			)
		);
	}

	/**
	 * Registra una herramienta de listado.
	 *
	 * @param string $plural    Nombre plural de la herramienta.
	 * @param string $post_type Tipo de contenido WordPress.
	 */
	private function register_list( string $plural, string $post_type ): void {
		$ability = 'nexo-mcp/list-' . $plural;

		wp_register_ability(
			$ability,
			array(
				'label'               => sprintf(
					/* translators: %s: tipo de contenido. */
					__( 'Listar %s', 'nexo-mcp' ),
					$plural
				),
				'description'         => __( 'Lista contenido WordPress con filtros y paginación limitada.', 'nexo-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->list_input_schema(),
				// MCP exige que `outputSchema` describa un objeto. El envoltorio se
				// declara aquí, junto con la salida que lo acompaña, para no
				// depender de que el adaptador lo transforme: esa normalización no
				// ocurre en todos los servidores y un cliente estricto descarta el
				// catálogo completo cuando falta.
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'result' => array(
							'type'  => 'array',
							'items' => $this->summary_schema(),
						),
					),
					'required'             => array( 'result' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => function ( array $input ) use ( $post_type, $plural ) {
					$output = $this->executor->read(
						'list_' . $plural,
						$input,
						fn() => $this->gateway->list_content( $post_type, $input )
					);

					return is_wp_error( $output ) ? $output : array( 'result' => $output );
				},
				'permission_callback' => fn( array $input = array() ) => $this->guard(
					'list_' . $plural,
					'read',
					$input,
					$this->can_list( $post_type, $input )
				),
			)
		);
	}

	/**
	 * Registra una herramienta individual.
	 *
	 * @param string $singular  Nombre singular de la herramienta.
	 * @param string $post_type Tipo de contenido WordPress.
	 */
	private function register_get( string $singular, string $post_type ): void {
		wp_register_ability(
			'nexo-mcp/get-' . $singular,
			array(
				'label'               => sprintf(
					/* translators: %s: tipo de contenido. */
					__( 'Obtener %s', 'nexo-mcp' ),
					$singular
				),
				'description'         => __( 'Obtiene contenido WordPress por ID cuando el usuario puede leerlo. Trae el HTML completo: para editar textos, enlaces o imágenes es más liviano nexo-mcp-get-page-structure.', 'nexo-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->detail_schema(),
				'execute_callback'    => fn( array $input ) => $this->executor->read(
					'get_' . $singular,
					$input,
					fn() => $this->gateway->get_content( (int) $input['id'], $post_type )
				),
				'permission_callback' => fn( array $input = array() ) => $this->guard(
					'get_' . $singular,
					'read',
					$input,
					$this->can_get( (int) ( $input['id'] ?? 0 ), $post_type )
				),
			)
		);
	}

	/**
	 * Comprueba configuración global y una capacidad.
	 *
	 * @param string $capability Capacidad WordPress.
	 * @param mixed  ...$args    Argumentos de meta capability.
	 */
	private function can_read( string $capability, ...$args ): bool {
		return (bool) $this->settings->get( 'server_enabled' )
			&& (bool) $this->settings->get( 'wordpress_tools_enabled' )
			&& $this->permission_policy->can_read( $capability, ...$args );
	}

	/**
	 * Comprueba configuración global y permisos de escritura.
	 *
	 * @param string $capability Capacidad WordPress.
	 * @param mixed  ...$args    Argumentos de meta capability.
	 */
	private function can_write( string $capability, ...$args ): bool {
		return (bool) $this->settings->get( 'server_enabled' )
			&& (bool) $this->settings->get( 'wordpress_tools_enabled' )
			&& $this->permission_policy->can_write( $capability, ...$args );
	}

	/**
	 * Convierte una denegación en su causa concreta y la deja auditada.
	 *
	 * @param string $tool      Nombre corto de la herramienta.
	 * @param string $operation `read` o `write`.
	 * @param array  $input     Parámetros recibidos.
	 * @param bool   $allowed   Resultado de la comprobación.
	 *
	 * @return bool|\WP_Error
	 */
	private function guard( string $tool, string $operation, array $input, bool $allowed ) {
		if ( $allowed ) {
			return true;
		}

		return $this->executor->denied( $tool, $operation, $input, $this->explainer->explain( $operation ) );
	}

	/**
	 * Comprueba tipo, estado y meta capability antes de actualizar.
	 *
	 * Sus dos primeros rechazos tienen causa propia y conviene nombrarla: pedir
	 * el tipo equivocado y toparse con el límite a borradores son errores
	 * corrientes que el «permiso denegado» genérico volvía indistinguibles de
	 * una credencial insuficiente.
	 *
	 * @param array  $input     Parámetros recibidos.
	 * @param string $post_type Tipo esperado.
	 *
	 * @return bool|\WP_Error
	 */
	private function can_update( array $input, string $post_type ) {
		$post_id = (int) ( $input['id'] ?? 0 );
		$tool    = 'update_' . $post_type;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || $post_type !== $post->post_type ) {
			return $this->executor->denied(
				$tool,
				'write',
				$input,
				new \WP_Error(
					DenialExplainer::CODE,
					sprintf(
						/* translators: 1: tipo de contenido solicitado; 2: identificador pedido. */
						__( 'No hay ningún contenido de tipo %1$s con el identificador %2$d. Comprueba el ID, o usa la herramienta del tipo que corresponda.', 'nexo-mcp' ),
						$post_type,
						$post_id
					),
					array( 'status' => 404 )
				)
			);
		}

		// Con `draft` sobre contenido a la vista se escribe en la copia, que es
		// un borrador: pide lo mismo que editar el original, sin el límite a
		// borradores (ADR-031).
		$staged = WorkingCopy::should_stage( $post, $input, $this->settings );

		if ( (bool) $this->settings->get( 'drafts_only' ) && 'draft' !== $post->post_status && ! $staged ) {
			return $this->executor->denied(
				$tool,
				'write',
				$input,
				new \WP_Error(
					DenialExplainer::CODE,
					__( 'El conector está limitado a borradores y este contenido ya no lo es. Desactiva «Sólo borradores» en el panel Nexo MCP para poder editarlo.', 'nexo-mcp' ),
					array( 'status' => 403 )
				)
			);
		}

		return $this->guard( $tool, 'write', $input, $this->can_write( 'edit_post', $post_id ) );
	}

	/**
	 * Protege listados no públicos con capacidades editoriales.
	 *
	 * @param string               $post_type Tipo de contenido WordPress.
	 * @param array<string, mixed> $input     Parámetros.
	 */
	private function can_list( string $post_type, array $input ): bool {
		$status = (string) ( $input['status'] ?? 'publish' );

		if ( 'publish' === $status ) {
			return $this->can_read( 'read' );
		}

		$capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		if ( 'private' === $status ) {
			$capability = 'page' === $post_type ? 'read_private_pages' : 'read_private_posts';
		}

		return $this->can_read( $capability );
	}

	/**
	 * Protege una lectura individual mediante meta capability.
	 *
	 * @param int    $post_id   ID del contenido.
	 * @param string $post_type Tipo esperado.
	 */
	private function can_get( int $post_id, string $post_type ): bool {
		$post = get_post( $post_id );

		/*
		 * Cuando el contenido no existe o es de otro tipo, la comprobación se
		 * limita a la capacidad general y deja pasar la llamada: así el ejecutor
		 * responde «no se encontró» o indica el tipo real, en vez del
		 * «Permission denied» genérico que devuelve el adaptador cuando este
		 * callback dice que no. Un cliente que recibe «permiso denegado» al pedir
		 * un artículo con get-page insiste con permisos en lugar de usar
		 * get-post. La lectura vuelve a comprobar `read_post` sobre el objeto
		 * real antes de devolver nada.
		 */
		if ( ! $post instanceof \WP_Post || $post_type !== $post->post_type ) {
			return $this->can_read( 'edit_posts' );
		}

		return $this->can_read( 'read_post', $post_id );
	}

	/**
	 * Esquema vacío estricto.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_input_schema(): array {
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
	 * Esquema de listado.
	 *
	 * @return array<string, mixed>
	 */
	private function list_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
				'status'   => array(
					'type'    => 'string',
					'default' => 'publish',
					'enum'    => array( 'publish', 'draft', 'pending', 'private' ),
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
	 * Esquema de resumen compartido.
	 *
	 * @return array<string, mixed>
	 */
	private function summary_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'status'   => array( 'type' => 'string' ),
				'slug'     => array( 'type' => 'string' ),
				'date_gmt' => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string' ),
				'builder'  => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Constructor visual detectado, o null si el contenido usa el editor estándar.',
				),
			),
			'required'             => array( 'id', 'title', 'status', 'slug', 'date_gmt', 'modified', 'link' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de detalle.
	 *
	 * @return array<string, mixed>
	 */
	private function detail_schema(): array {
		$schema                          = $this->summary_schema();
		$schema['properties']['content'] = array( 'type' => 'string' );
		$schema['properties']['excerpt'] = array( 'type' => 'string' );
		$schema['properties']['author']  = array( 'type' => 'integer' );
		$schema['properties']['cache']   = self::cache_schema();
		$schema['required'][]            = 'content';
		$schema['required'][]            = 'excerpt';
		$schema['required'][]            = 'author';

		// Enlace de la copia de trabajo (ADR-031): el original trae `draft_id`
		// y la copia, `draft_of` y `preview_url`.
		$schema['properties']['draft_id']    = array( 'type' => 'integer' );
		$schema['properties']['draft_of']    = array( 'type' => 'integer' );
		$schema['properties']['preview_url'] = array( 'type' => 'string' );

		return $schema;
	}

	/**
	 * Esquema de salida de la actualización.
	 *
	 * Es el detalle, pero sólo exige `id`: con `draft` la respuesta es la
	 * compacta de la copia, `{id, draft_of, preview_url}` (ADR-031).
	 *
	 * @return array<string, mixed>
	 */
	private function update_output_schema(): array {
		$schema             = $this->detail_schema();
		$schema['required'] = array( 'id' );

		return $schema;
	}

	/**
	 * Esquema del informe de purga de caché.
	 *
	 * Va como propiedad opcional en toda salida de escritura. Los esquemas del
	 * conector son cerrados —`additionalProperties: false`— así que devolver un
	 * campo sin declararlo hace que el cliente descarte la respuesta entera y
	 * muestre un error, aunque la escritura sí se haya efectuado: el peor de los
	 * dos mundos, porque el usuario reintenta algo que ya ocurrió.
	 *
	 * @return array<string, mixed>
	 */
	public static function cache_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => 'Resultado de la purga de caché posterior a la escritura.',
			'properties'           => array(
				'purged'      => array( 'type' => 'boolean' ),
				'scope'       => array( 'type' => 'string' ),
				'reason'      => array( 'type' => 'string' ),
				'cdn_warning' => array( 'type' => 'string' ),
				'providers'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'failed'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'purged' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de información del sitio.
	 *
	 * @return array<string, mixed>
	 */
	private function site_info_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'           => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'url'            => array( 'type' => 'string' ),
				'language'       => array( 'type' => 'string' ),
				'timezone'       => array( 'type' => 'string' ),
				'search_visible' => array( 'type' => 'boolean' ),
				'wordpress'      => array( 'type' => 'string' ),
				'multisite'      => array( 'type' => 'boolean' ),
				'builders'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Constructores visuales activos en el sitio.',
				),
			),
			'required'             => array( 'name', 'description', 'url', 'language', 'timezone', 'search_visible', 'wordpress', 'multisite' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema compartido de creación y actualización.
	 *
	 * @param bool $creating Indica si el título es obligatorio.
	 * @return array<string, mixed>
	 */
	private function write_input_schema( bool $creating ): array {
		$properties = array(
			'title'   => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 200,
			),
			'content' => array(
				'type'      => 'string',
				'maxLength' => 200000,
			),
			'excerpt' => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'slug'    => array(
				'type'      => 'string',
				'maxLength' => 200,
				'pattern'   => '^[a-z0-9-]+$',
			),
		);

		if ( ! $creating ) {
			$properties = array_merge(
				array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				$properties
			);

			$properties['draft'] = array(
				'type'        => 'boolean',
				'description' => 'Si está publicado, edita una copia borrador y devuelve su id y preview_url. Con la opción «copia de trabajo» del sitio encendida (por defecto) esto ocurre siempre. set-content-status sobre esa copia: publish la fusiona, trash la descarta.',
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $creating ? array( 'title' ) : array( 'id' ),
			'additionalProperties' => false,
		);
	}
}
