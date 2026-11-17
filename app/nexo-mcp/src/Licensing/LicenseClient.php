<?php
/**
 * Cliente del servicio de licencias.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Licensing;

use RuntimeException;
use WP_Error;

/**
 * Habla con la plataforma para activar, revalidar y desconectar el sitio.
 *
 * El plugin no decide nada sobre la licencia: pregunta y obedece. Los cupos,
 * el plan y los límites viven en la plataforma, así que abrir un plan nuevo no
 * exige publicar una versión del plugin.
 */
final class LicenseClient {

	/**
	 * Distingue «la plataforma dijo que no» de «no pude preguntar».
	 *
	 * Es la diferencia entre apagar el conector y esperar: un servidor caído no
	 * puede dejar sin funcionar el sitio de nadie.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * La plataforma respondió, pero con un fallo suyo.
	 *
	 * Se trata igual que la incomunicación para no cortar el servicio —el
	 * problema no es la licencia—, pero el mensaje tiene que ser otro: decirle
	 * a alguien que revise su conexión cuando el que falló fui yo lo manda a
	 * buscar el problema donde no está.
	 */
	public const SERVICE_ERROR = 'service_error';

	/**
	 * Canjea la clave de licencia por un token de instalación.
	 *
	 * @param string $license_key     Clave tal como la pegó el administrador.
	 * @param string $installation_id UUID estable de la instalación.
	 * @return array<string, mixed>
	 * @throws RuntimeException Si la plataforma rechaza la activación.
	 */
	public function activate( string $license_key, string $installation_id ): array {
		return $this->post(
			array(
				'action'           => 'activate',
				'licenseKey'       => $license_key,
				'installationId'   => $installation_id,
				'siteUrl'          => home_url( '/' ),
				'siteName'         => get_bloginfo( 'name' ),
				'pluginVersion'    => NEXO_MCP_VERSION,
				'wordpressVersion' => get_bloginfo( 'version' ),
				'phpVersion'       => PHP_VERSION,
			)
		);
	}

	/**
	 * Pregunta si la licencia sigue vigente.
	 *
	 * @param string $installation_id UUID estable de la instalación.
	 * @param string $token           Token local de la instalación.
	 * @return array<string, mixed>
	 * @throws RuntimeException Si la licencia dejó de ser válida o no hubo respuesta.
	 */
	public function status( string $installation_id, string $token ): array {
		return $this->post(
			array(
				'action'           => 'status',
				'installationId'   => $installation_id,
				'token'            => $token,
				'pluginVersion'    => NEXO_MCP_VERSION,
				'wordpressVersion' => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * Desconecta este sitio y devuelve su cupo.
	 *
	 * @param string $installation_id UUID estable de la instalación.
	 * @param string $token           Token local de la instalación.
	 * @return array<string, mixed>
	 * @throws RuntimeException Si la plataforma no confirma la desconexión.
	 */
	public function release( string $installation_id, string $token ): array {
		return $this->post(
			array(
				'action'         => 'release',
				'installationId' => $installation_id,
				'token'          => $token,
			)
		);
	}

	/**
	 * Ejecuta la llamada contra una URL fija que el usuario no controla.
	 *
	 * @param array<string, mixed> $payload Cuerpo JSON.
	 * @return array<string, mixed>
	 * @throws RuntimeException Cuando la plataforma rechaza o no responde.
	 */
	private function post( array $payload ): array {
		// El nombre de la ruta es el mismo que el de la función en el servidor,
		// así que apuntar `NEXO_MCP_API_URL` directo al proveedor —saltándose
		// el dominio propio— funciona sin traducir nada.
		if ( ! ApiEndpoint::is_configured() ) {
			throw new RuntimeException( esc_html( self::UNREACHABLE ) );
		}

		$response = wp_safe_remote_post(
			ApiEndpoint::url( 'bridge-activate' ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
				'user-agent'  => 'Nexo-MCP/' . NEXO_MCP_VERSION,
			)
		);

		if ( $response instanceof WP_Error ) {
			throw new RuntimeException( esc_html( self::UNREACHABLE ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			// Un intermediario que devuelve HTML —un portal cautivo, un WAF— no
			// es una respuesta de la plataforma: se trata como incomunicación.
			throw new RuntimeException( esc_html( self::UNREACHABLE ) );
		}

		if ( $status >= 500 ) {
			throw new RuntimeException( esc_html( self::SERVICE_ERROR ) );
		}

		if ( $status < 200 || $status >= 300 || empty( $body['success'] ) ) {
			$message = isset( $body['error'] ) && is_string( $body['error'] )
				? sanitize_text_field( $body['error'] )
				: __( 'No fue posible completar la operación.', 'nexo-mcp' );

			throw new RuntimeException( esc_html( $message ) );
		}

		return $body;
	}
}
