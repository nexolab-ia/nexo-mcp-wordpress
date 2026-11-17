<?php
/**
 * Traducción de una denegación a su causa concreta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authorization;

use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Explica por qué el conector negó una herramienta.
 *
 * La autorización acumula media docena de condiciones —el servidor encendido,
 * el módulo activo, el perfil de conexión, el scope del token y la capacidad de
 * WordPress— y todas colapsaban en un mismo `false`. El adaptador MCP lo
 * traduce entonces a «Permission denied» a secas, que no distingue un conector
 * apagado de un token autorizado en sólo lectura. El dueño del sitio no tiene
 * cómo saber cuál de las seis revisar, y el modelo tampoco: insiste con la
 * misma llamada porque nada le indica qué corregir.
 *
 * Las condiciones se revisan de la más general a la más específica y gana la
 * primera que falle, que es la que hay que arreglar antes de que las demás
 * importen.
 */
final class DenialExplainer {

	/**
	 * Código común de las denegaciones.
	 */
	public const CODE = 'nexo_mcp_forbidden';

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construye el traductor.
	 *
	 * @param Settings $settings Configuración operativa.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Redacta la causa de una denegación.
	 *
	 * Quien llega hasta acá ya presentó una credencial válida del sitio, así que
	 * nombrar el interruptor que falta no le revela nada que el panel y el
	 * documento de descubrimiento no expongan por su cuenta.
	 *
	 * @param string $operation `read` o `write`.
	 */
	public function explain( string $operation ): \WP_Error {
		if ( ! (bool) $this->settings->get( 'server_enabled' ) ) {
			return $this->error(
				__( 'El conector MCP está desactivado en este sitio. Actívalo en el panel Nexo MCP.', 'nexo-mcp' )
			);
		}

		if ( 'write' === $operation ) {
			$write = $this->explain_write();

			if ( null !== $write ) {
				return $write;
			}
		} elseif ( ! current_user_can( Capabilities::READ ) ) {
			return $this->error(
				__( 'Tu usuario de WordPress no tiene permiso de lectura en el conector.', 'nexo-mcp' )
			);
		}

		$scope = $this->explain_scope( $operation );

		if ( null !== $scope ) {
			return $scope;
		}

		return $this->error(
			__( 'El módulo que necesita esta herramienta está desactivado en el panel, tu usuario no tiene la capacidad de WordPress que exige, o esta conexión no tiene el alcance necesario. Revísalo en Nexo MCP › Módulos y límites y en Accesos (OAuth).', 'nexo-mcp' )
		);
	}

	/**
	 * Revisa el alcance básico concedido a la conexión OAuth en curso.
	 *
	 * @param string $operation `read` o `write`.
	 */
	private function explain_scope( string $operation ): ?\WP_Error {
		if ( ! BearerTokenGuard::is_authenticated()
			|| ! (bool) $this->settings->get( 'enforce_connection_scopes' )
		) {
			return null;
		}

		$needed = 'write' === $operation ? 'mcp:write' : 'mcp:read';

		if ( ScopeMap::satisfies( BearerTokenGuard::scopes(), array( $needed ) ) ) {
			return null;
		}

		return $this->error(
			sprintf(
				/* translators: %s: scope OAuth que falta. */
				__( 'Esta conexión fue autorizada sin el alcance «%s». Amplía sus alcances en Nexo MCP › Accesos (OAuth) o vuelve a autorizar el cliente.', 'nexo-mcp' ),
				$needed
			)
		);
	}

	/**
	 * Revisa las condiciones propias de la escritura.
	 *
	 * Devuelve `null` cuando ninguna de ellas es la culpable.
	 */
	private function explain_write(): ?\WP_Error {
		// El cambio rige de inmediato para las conexiones ya autorizadas: no hay
		// que reconectar la IA (ADR-027).
		if ( (bool) $this->settings->get( 'read_only' ) ) {
			return $this->error(
				__( 'El sitio está en el perfil «Solo revisar». Cámbialo a «Revisar y editar» en el panel Nexo MCP.', 'nexo-mcp' )
			);
		}

		if ( ! current_user_can( Capabilities::WRITE ) ) {
			return $this->error(
				__( 'Tu usuario de WordPress no tiene permiso de edición en el conector.', 'nexo-mcp' )
			);
		}

		return null;
	}

	/**
	 * Construye el error con el código común.
	 *
	 * @param string $message Causa redactada.
	 */
	private function error( string $message ): \WP_Error {
		return new \WP_Error( self::CODE, $message, array( 'status' => 403 ) );
	}
}
