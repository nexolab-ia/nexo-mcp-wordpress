<?php
/**
 * Cliente de registro remoto.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Registration;

use RuntimeException;

/**
 * Envía solicitudes acotadas al servicio de registro configurado en NEXO_MCP_API_URL.
 */
final class RegistrationClient {


	/**
	 * Solicita un código de verificación.
	 *
	 * @param string $email           Email del administrador.
	 * @param string $installation_id UUID estable de la instalación.
	 * @return array<string, mixed>
	 */
	public function request_code( string $email, string $installation_id ): array {
		return $this->post(
			array(
				'action'         => 'request_code',
				'email'          => $email,
				'installationId' => $installation_id,
				'siteUrl'        => home_url( '/' ),
				'siteName'       => get_bloginfo( 'name' ),
				'consent'        => true,
			)
		);
	}

	/**
	 * Verifica el código y obtiene un token revocable.
	 *
	 * @param string $email           Email del administrador.
	 * @param string $installation_id UUID estable de la instalación.
	 * @param string $code            Código de seis dígitos.
	 * @return array<string, mixed>
	 */
	public function verify_code( string $email, string $installation_id, string $code ): array {
		return $this->post(
			array(
				'action'           => 'verify_code',
				'email'            => $email,
				'installationId'   => $installation_id,
				'code'             => $code,
				'pluginVersion'    => NEXO_MCP_VERSION,
				'wordpressVersion' => get_bloginfo( 'version' ),
				'phpVersion'       => PHP_VERSION,
			)
		);
	}

	/**
	 * Confirma que el token continúa activo.
	 *
	 * @param string $installation_id UUID estable de la instalación.
	 * @param string $token           Token local revocable.
	 * @return array<string, mixed>
	 */
	public function check( string $installation_id, string $token ): array {
		return $this->post(
			array(
				'action'           => 'check',
				'installationId'   => $installation_id,
				'token'            => $token,
				'pluginVersion'    => NEXO_MCP_VERSION,
				'wordpressVersion' => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * Envía un reporte o sugerencia desde una instalación verificada.
	 *
	 * El email y el host no viajan en el cuerpo: el servicio los lee de la
	 * instalación asociada al token para que no puedan falsificarse.
	 *
	 * @param string                $installation_id UUID estable de la instalación.
	 * @param string                $token           Token local revocable.
	 * @param string                $kind            Tipo de reporte.
	 * @param string                $message         Mensaje escrito por el administrador.
	 * @param array<string, string> $context         Contexto técnico acotado.
	 * @return array<string, mixed>
	 */
	public function report(
		string $installation_id,
		string $token,
		string $kind,
		string $message,
		array $context
	): array {
		return $this->post(
			array(
				'action'         => 'report',
				'installationId' => $installation_id,
				'token'          => $token,
				'kind'           => $kind,
				'message'        => $message,
				'context'        => $context,
			)
		);
	}

	/**
	 * Revoca el token remoto.
	 *
	 * @param string $installation_id UUID estable de la instalación.
	 * @param string $token           Token local revocable.
	 * @return array<string, mixed>
	 */
	public function deactivate( string $installation_id, string $token ): array {
		return $this->post(
			array(
				'action'         => 'deactivate',
				'installationId' => $installation_id,
				'token'          => $token,
			)
		);
	}

	/**
	 * Ejecuta una llamada HTTPS fija, sin URLs controladas por el usuario.
	 *
	 * @param array<string, mixed> $payload Cuerpo JSON.
	 * @return array<string, mixed>
	 * @throws RuntimeException Cuando el servicio no responde o rechaza la solicitud.
	 */
	private function post( array $payload ): array {
		if ( ! \NexoDev\NexoMCP\Licensing\ApiEndpoint::is_configured() ) {
			throw new RuntimeException(
				esc_html__( 'No hay un servicio de registro configurado (constante NEXO_MCP_API_URL).', 'nexo-mcp' )
			);
		}

		$response = wp_safe_remote_post(
			\NexoDev\NexoMCP\Licensing\ApiEndpoint::url( 'mcp-plugin' ),
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

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException(
				esc_html__( 'No fue posible contactar el servicio de verificación.', 'nexo-mcp' )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			throw new RuntimeException(
				esc_html__( 'El servicio de verificación entregó una respuesta inválida.', 'nexo-mcp' )
			);
		}

		if ( $status < 200 || $status >= 300 || empty( $body['success'] ) ) {
			$message = isset( $body['error'] ) && is_string( $body['error'] )
				? sanitize_text_field( $body['error'] )
				: __( 'No fue posible completar la verificación.', 'nexo-mcp' );

			throw new RuntimeException( esc_html( $message ) );
		}

		return $body;
	}
}
