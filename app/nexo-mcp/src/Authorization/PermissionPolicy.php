<?php
/**
 * Política de permisos acumulativos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authorization;

use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Combina capacidades WordPress, interruptores del panel y alcances por conexión.
 *
 * Una herramienta se permite sólo si se cumplen TODAS estas condiciones:
 *
 * 1. El módulo que la contiene está encendido en «Módulos y límites».
 * 2. Si escribe, el sitio no está en modo sólo lectura.
 * 3. Si destruye, «Acciones destructivas» está encendido.
 * 4. El usuario titular tiene la capacidad Nexo y la capacidad de WordPress.
 * 5. Si la conexión llegó con un token OAuth y `enforce_connection_scopes` está
 *    encendido, el token incluye el scope que corresponde (alcance por
 *    conexión). Con Application Password no hay scope y rige sólo lo anterior.
 */
final class PermissionPolicy {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construye la política.
	 *
	 * @param Settings $settings Configuración operativa.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Comprueba que el solicitante tenga concedida una capacidad Nexo.
	 *
	 * @param string $nexo_capability Capacidad Nexo exigida.
	 * @param string $operation       `read` o `write`.
	 */
	private function grants( string $nexo_capability, string $operation = 'read' ): bool {
		if ( ! current_user_can( $nexo_capability ) ) {
			return false;
		}

		if ( ! BearerTokenGuard::is_authenticated() ) {
			return true;
		}

		$scopes = BearerTokenGuard::scopes();

		// El transporte ya rechaza un token sin `mcp:connect`; se repite aquí
		// para que ninguna herramienta dependa de haber pasado por él.
		if ( ! ScopeMap::satisfies( $scopes, array( ScopeMap::CONNECT ) ) ) {
			return false;
		}

		if ( ! $this->enforces_connection_scopes() ) {
			return true;
		}

		return ScopeMap::satisfies_any(
			$scopes,
			ScopeMap::scopes_for_capability( $nexo_capability, $operation )
		);
	}

	/**
	 * Indica si se aplican los alcances concedidos a cada conexión.
	 */
	public function enforces_connection_scopes(): bool {
		return (bool) $this->settings->get( 'enforce_connection_scopes' );
	}

	/**
	 * Indica si el sitio está en modo sólo lectura.
	 */
	public function is_read_only(): bool {
		return (bool) $this->settings->get( 'read_only' );
	}

	/**
	 * Comprueba acceso base al servidor.
	 */
	public function can_connect(): bool {
		return (bool) $this->settings->get( 'server_enabled' )
			&& $this->grants( Capabilities::CONNECT );
	}

	/**
	 * Comprueba una lectura y su capacidad WordPress específica.
	 *
	 * @param string $wordpress_capability Capacidad de Core o WooCommerce.
	 * @param mixed  ...$args              Argumentos opcionales de la capacidad.
	 */
	public function can_read( string $wordpress_capability, ...$args ): bool {
		return $this->can_connect()
			&& $this->grants( Capabilities::READ )
			&& current_user_can( $wordpress_capability, ...$args );
	}

	/**
	 * Comprueba escritura y respeta el modo sólo lectura.
	 *
	 * @param string $wordpress_capability Capacidad de Core o WooCommerce.
	 * @param mixed  ...$args              Argumentos opcionales de la capacidad.
	 */
	public function can_write( string $wordpress_capability, ...$args ): bool {
		return ! $this->is_read_only()
			&& $this->can_connect()
			&& $this->grants( Capabilities::WRITE, 'write' )
			&& current_user_can( $wordpress_capability, ...$args );
	}

	/**
	 * Comprueba lectura de un módulo opcional.
	 *
	 * @param string $setting_key          Interruptor del módulo.
	 * @param string $nexo_capability      Capacidad Nexo específica.
	 * @param string $wordpress_capability Capacidad nativa.
	 * @param mixed  ...$args              Argumentos de meta capability.
	 */
	public function can_module_read(
		string $setting_key,
		string $nexo_capability,
		string $wordpress_capability,
		...$args
	): bool {
		return (bool) $this->settings->get( $setting_key )
			&& $this->can_read( $wordpress_capability, ...$args )
			&& $this->grants( $nexo_capability, 'read' );
	}

	/**
	 * Comprueba escritura de un módulo opcional.
	 *
	 * @param string $setting_key          Interruptor del módulo.
	 * @param string $nexo_capability      Capacidad Nexo específica.
	 * @param string $wordpress_capability Capacidad nativa.
	 * @param mixed  ...$args              Argumentos de meta capability.
	 */
	public function can_module_write(
		string $setting_key,
		string $nexo_capability,
		string $wordpress_capability,
		...$args
	): bool {
		return (bool) $this->settings->get( $setting_key )
			&& $this->can_write( $wordpress_capability, ...$args )
			&& $this->grants( $nexo_capability, 'write' );
	}

	/**
	 * Comprueba una acción destructiva separada.
	 *
	 * @param string $wordpress_capability Capacidad nativa.
	 * @param mixed  ...$args              Argumentos de meta capability.
	 */
	public function can_destroy( string $wordpress_capability, ...$args ): bool {
		return (bool) $this->settings->get( 'destructive_enabled' )
			&& $this->can_write( $wordpress_capability, ...$args )
			&& $this->grants( Capabilities::DESTRUCTIVE, 'write' );
	}
}
