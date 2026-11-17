<?php
/**
 * Enlaces de subida de un solo uso.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Media;

use NexoDev\NexoMCP\Security\RateLimiter;

/**
 * Emite y canjea los tickets que entrega `upload-media` sin archivo.
 *
 * Un agente no tiene a mano el token del conector para usar curl, y mandar el
 * archivo en base64 lo hace pasar entero por su contexto. El ticket resuelve
 * las dos cosas: la herramienta MCP lo emite y el archivo viaja por HTTP
 * directo a `POST nexo-mcp/v1/media` (ADR-030).
 *
 * No es un token Bearer: sólo lo mira `UploadController`, sirve una vez, vence
 * a los diez minutos y en la base queda únicamente su HMAC.
 */
final class UploadTicket {

	/**
	 * Vigencia del ticket, en segundos.
	 */
	public const TTL = 600;

	/**
	 * Tickets que un mismo usuario puede pedir por hora.
	 */
	private const POR_HORA = 20;

	/**
	 * Prefijo del transient de cada ticket.
	 */
	private const PREFIJO = 'nexo_mcp_upload_ticket_';

	/**
	 * Metadatos que el ticket puede llevar aprobados.
	 */
	private const METADATOS = array( 'filename', 'title', 'alt' );

	/**
	 * Limitador persistente.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Construye el emisor.
	 *
	 * @param RateLimiter|null $limiter Limitador persistente.
	 */
	public function __construct( ?RateLimiter $limiter = null ) {
		$this->limiter = $limiter ?? new RateLimiter();
	}

	/**
	 * Emite un ticket para el usuario en curso.
	 *
	 * @param array<string, mixed> $params Parámetros de la llamada; se guardan filename, title y alt.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function issue( array $params ) {
		$usuario = get_current_user_id();
		$limite  = $this->limiter->consume( (string) $usuario, 'upload-ticket', self::POR_HORA, HOUR_IN_SECONDS );

		if ( is_wp_error( $limite ) ) {
			return $limite;
		}

		if ( ! $limite->allowed ) {
			return new \WP_Error(
				'nexo_mcp_rate_limit_exceeded',
				__( 'Pediste demasiados enlaces de subida en la última hora. Intenta nuevamente más tarde.', 'nexo-mcp' ),
				array(
					'status'      => 429,
					'retry_after' => $limite->retry_after,
				)
			);
		}

		$ticket = bin2hex( random_bytes( 32 ) );
		$hash   = self::hash( $ticket );

		set_transient(
			self::PREFIJO . $hash,
			array(
				'hash'    => $hash,
				'user_id' => $usuario,
				'expires' => time() + self::TTL,
				'params'  => array_intersect_key( $params, array_flip( self::METADATOS ) ),
			),
			self::TTL
		);

		$url = esc_url_raw( add_query_arg( 'ticket', $ticket, rest_url( 'nexo-mcp/v1/media' ) ) );

		return array(
			'upload_url' => $url,
			'expires_in' => self::TTL,
			'curl'       => sprintf( 'curl -F "file=@RUTA" "%s"', $url ),
		);
	}

	/**
	 * Canjea un ticket, que sirve una sola vez.
	 *
	 * @param string $ticket Ticket recibido en la URL.
	 * @return array<string, mixed>|null Titular y metadatos aprobados, o null si no sirve.
	 */
	public function consume( string $ticket ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $ticket ) ) {
			return null;
		}

		$hash  = self::hash( $ticket );
		$clave = self::PREFIJO . $hash;
		$datos = get_transient( $clave );

		// Gana quien logra borrarlo: de dos peticiones con el mismo ticket,
		// sólo una recibe true de delete_transient(). Leer y usar sin borrar
		// dejaría subir dos veces con el mismo enlace.
		if ( ! is_array( $datos ) || ! delete_transient( $clave ) ) {
			return null;
		}

		// La clave ya sale del HMAC, pero la base no distingue mayúsculas al
		// buscarla: la comparación que decide va en tiempo constante. El
		// vencimiento se mira aparte porque una caché de objetos puede tardar
		// en retirar un transient vencido.
		if ( ! hash_equals( (string) ( $datos['hash'] ?? '' ), $hash )
			|| (int) ( $datos['expires'] ?? 0 ) < time()
			|| (int) ( $datos['user_id'] ?? 0 ) < 1
		) {
			return null;
		}

		return $datos;
	}

	/**
	 * HMAC del ticket, lo único que llega a la base.
	 *
	 * @param string $ticket Ticket en claro.
	 */
	private static function hash( string $ticket ): string {
		return hash_hmac( 'sha256', $ticket, wp_salt( 'auth' ) );
	}
}
