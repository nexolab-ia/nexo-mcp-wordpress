<?php
/**
 * Compatibilidad del handshake HTTP de Codex.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

/**
 * Evita que Codex intente decodificar como JSON una respuesta 202 vacía.
 *
 * El transporte oficial de WordPress responde 202 sin cuerpo a las
 * notificaciones, como exige MCP. Codex 0.146 intenta deserializar ese cuerpo
 * vacío al enviar `notifications/initialized` y cierra el transporte. Para
 * ese único mensaje se usa 204, que conserva la ausencia de cuerpo. La
 * detección no depende del User-Agent porque algunos proxies lo reemplazan
 * antes de que la solicitud llegue a WordPress.
 */
final class CodexInitializedCompatibility {
	/**
	 * Registra la normalización después de que el adaptador construya la respuesta.
	 */
	public function register(): void {
		add_filter( 'rest_request_after_callbacks', array( $this, 'normalize_response' ), 20, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'normalize_response' ), 20, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_no_content' ), 20, 4 );
	}

	/**
	 * Convierte únicamente el 202 vacío de `notifications/initialized` en 204.
	 *
	 * @param mixed $response Respuesta REST generada.
	 * @param mixed $server   Servidor REST, no utilizado.
	 * @param mixed $request  Solicitud REST atendida.
	 * @return mixed
	 */
	public function normalize_response( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( ! $this->is_initialized_notification( $request )
			|| ! in_array( $response->get_status(), array( 202, 204 ), true )
			|| ! in_array( $response->get_data(), array( null, array() ), true )
		) {
			return $response;
		}

		$response->set_status( 204 );

		return $response;
	}

	/**
	 * Evita que WordPress anuncie JSON cuando la respuesta 204 no tiene cuerpo.
	 *
	 * Codex decide si debe deserializar según el Content-Type. Cambiar solamente
	 * el estado a 204 no basta cuando la capa REST ya anunció application/json.
	 *
	 * @param mixed $served  Si otro filtro ya atendió la respuesta.
	 * @param mixed $result  Respuesta preparada por WordPress.
	 * @param mixed $request Solicitud REST atendida.
	 * @param mixed $server  Servidor REST, no utilizado.
	 * @return mixed
	 */
	public function serve_no_content( $served, $result, $request, $server ) {
		unset( $server );

		if ( true === $served
			|| ! $result instanceof \WP_REST_Response
			|| ! $request instanceof \WP_REST_Request
			|| ! $this->is_initialized_notification( $request )
			|| ! in_array( $result->get_status(), array( 202, 204 ), true )
			|| ! in_array( $result->get_data(), array( null, array() ), true )
		) {
			return $served;
		}

		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			header_remove( 'Content-Length' );
		}

		status_header( 204 );

		return true;
	}

	/**
	 * Reconoce exclusivamente la notificación final del handshake MCP.
	 *
	 * @param \WP_REST_Request $request Solicitud REST atendida.
	 */
	private function is_initialized_notification( \WP_REST_Request $request ): bool {
		if ( false === strpos( (string) $request->get_route(), '/nexo-mcp/v1/mcp' )
			|| 'POST' !== $request->get_method()
		) {
			return false;
		}

		$message = $request->get_json_params();

		return is_array( $message )
			&& ! isset( $message['id'] )
			&& 'notifications/initialized' === ( $message['method'] ?? null );
	}
}
