<?php
/**
 * Servidor MCP de allowlist.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;
use WP\McpSchema\Server\Tools\DTO\Tool;

/**
 * Registra el endpoint HTTP del conector usando el adaptador oficial.
 */
final class ServerRegistrar {

	public const SERVER_ID = 'nexo-mcp';

	/**
	 * Guardia previa al transporte.
	 *
	 * @var TransportGuard
	 */
	private TransportGuard $guard;

	/**
	 * Construye el registro del servidor.
	 *
	 * @param TransportGuard $guard Política de acceso.
	 */
	public function __construct( TransportGuard $guard ) {
		$this->guard = $guard;
	}

	/**
	 * Inicializa el adaptador y agenda el servidor personalizado.
	 */
	public function register(): void {
		add_action( 'mcp_adapter_init', array( $this, 'create_server' ) );
		add_filter( 'mcp_adapter_tools_list', array( $this, 'slim_tools_list' ), 10, 2 );
		// Antes del desafío de BearerTokenGuard (prioridad 10), que sólo mira 401 y 403.
		add_filter( 'rest_post_dispatch', array( $this, 'limit_response' ), 9, 3 );
		McpAdapter::instance();
	}

	/**
	 * Crea el servidor con la allowlist explícita de abilities.
	 *
	 * @param McpAdapter $adapter Adaptador inicializado.
	 */
	public function create_server( McpAdapter $adapter ): void {
		$adapter->create_server(
			self::SERVER_ID,
			'nexo-mcp/v1',
			'mcp',
			'Nexo MCP',
			'Servidor MCP seguro para WordPress.',
			NEXO_MCP_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			array_merge( AbilityRegistrar::ABILITIES, ExtendedAbilityRegistrar::ABILITIES ),
			array(),
			array(),
			array( $this->guard, 'authorize' )
		);
	}

	/**
	 * Convierte el rechazo por límite del transporte en 429 con Retry-After.
	 *
	 * El adaptador reduce el `WP_Error` del guardia a `false` y WordPress
	 * responde `rest_forbidden` 403, sin decir cuánto esperar. Las demás
	 * denegaciones no se tocan.
	 *
	 * @param mixed $response Respuesta generada.
	 * @param mixed $server   Servidor REST.
	 * @param mixed $request  Pedido atendido.
	 *
	 * @return mixed
	 */
	public function limit_response( $response, $server, $request = null ) {
		unset( $server );

		if ( ! $response instanceof \WP_REST_Response
			|| ! $request instanceof \WP_REST_Request
			|| '/nexo-mcp/v1/mcp' !== $request->get_route()
		) {
			return $response;
		}

		$error = $this->guard->rate_limit_error( $request );

		if ( null === $error ) {
			return $response;
		}

		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) && isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : 1;

		$response->set_status( 429 );
		$response->set_data(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => array(
					'status'      => 429,
					'retry_after' => $retry_after,
				),
			)
		);
		$response->header( 'Retry-After', (string) $retry_after );

		return $response;
	}

	/**
	 * Retira los esquemas de salida del catálogo que recibe el cliente.
	 *
	 * El esquema de salida describe lo que devuelve cada herramienta, y la IA
	 * no lo necesita para llamarla: eran unos 2.700 tokens en cada conversación.
	 * MCP lo declara opcional. Sólo se quita del catálogo; la herramienta lo
	 * conserva registrado, así que WordPress sigue validando cada respuesta
	 * contra él antes de entregarla.
	 *
	 * @param mixed $tools  Herramientas que se anunciarán.
	 * @param mixed $server Servidor que atiende la petición.
	 *
	 * @return mixed
	 */
	public function slim_tools_list( $tools, $server ) {
		// Otros plugins pueden montar sus propios servidores MCP en el mismo
		// sitio; su catálogo no es asunto de este conector.
		if ( ! is_array( $tools ) || ! $server instanceof McpServer || self::SERVER_ID !== $server->get_server_id() ) {
			return $tools;
		}

		return array_map(
			static function ( $tool ) {
				if ( ! $tool instanceof Tool ) {
					return $tool;
				}

				// Se rearma con sus mismas piezas en vez de pasar por toArray() y
				// fromArray(): ese viaje falla con las herramientas sin parámetros,
				// porque toArray() entrega `properties` como objeto vacío y
				// fromArray() sólo admite arreglos. Caía el catálogo entero.
				return new Tool(
					$tool->getName(),
					$tool->getInputSchema(),
					$tool->getTitle(),
					$tool->getDescription(),
					$tool->getExecution(),
					null,
					$tool->getAnnotations(),
					$tool->get_meta(),
					$tool->getIcons()
				);
			},
			$tools
		);
	}
}
