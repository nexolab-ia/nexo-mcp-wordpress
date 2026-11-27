<?php
/**
 * Política de permisos acumulativos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authorization;

use NexoDev\NexoMCP\Builders\DiviLayoutGuard;
use NexoDev\NexoMCP\Builders\DiviLibrarySchema;
use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

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
	 * Comprueba si esta solicitud puede escribir contenido dinámico de Divi 5 en una página.
	 *
	 * Como set-dynamic-tag de Elementor: módulo de constructores y `edit_post` sobre el
	 * original (aunque llegue el id de su copia de trabajo) Y la capacidad
	 * `nexo_mcp_divi_dynamic` con su interruptor `divi_dynamic_tools_enabled` (y su scope en el token).
	 *
	 * @param int $post_id Página (o su copia de trabajo).
	 */
	public function can_write_divi_dynamic( int $post_id ): bool {
		// Un layout del Theme Builder (o su copia) se muestra en todo el sitio: el permiso del Theme Builder y el scope
		// propio, no el `edit_post` de una página.
		if ( DiviLayoutGuard::KIND_LAYOUT === DiviLayoutGuard::kind_of_id( $post_id ) ) {
			return $this->can_write_divi_theme_builder()
				&& $this->can_module_write( 'divi_dynamic_tools_enabled', Capabilities::DIVI_DYNAMIC, 'edit_theme_options' );
		}

		$original = WorkingCopy::original_id_for( $post_id );

		return $this->can_module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_post', $original )
			&& $this->can_module_write( 'divi_dynamic_tools_enabled', Capabilities::DIVI_DYNAMIC, 'edit_post', $original );
	}

	/**
	 * Comprueba si esta solicitud puede escribir condiciones de visualización de Divi 5 en una página.
	 *
	 * Como `can_write_divi_dynamic`: módulo de constructores y `edit_post` sobre el original (aunque llegue
	 * el id de su copia de trabajo) Y la capacidad `nexo_mcp_divi_conditions` con su interruptor
	 * `divi_conditions_tools_enabled` (y su scope en el token). No depende del interruptor dinámico.
	 *
	 * @param int $post_id Página (o su copia de trabajo).
	 */
	public function can_write_divi_conditions( int $post_id ): bool {
		// Como `can_write_divi_dynamic`: un layout pide el permiso del Theme Builder y el scope propio.
		if ( DiviLayoutGuard::KIND_LAYOUT === DiviLayoutGuard::kind_of_id( $post_id ) ) {
			return $this->can_write_divi_theme_builder()
				&& $this->can_module_write( 'divi_conditions_tools_enabled', Capabilities::DIVI_CONDITIONS, 'edit_theme_options' );
		}

		$original = WorkingCopy::original_id_for( $post_id );

		return $this->can_module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_post', $original )
			&& $this->can_module_write( 'divi_conditions_tools_enabled', Capabilities::DIVI_CONDITIONS, 'edit_post', $original );
	}

	/**
	 * Comprueba si esta solicitud puede escribir plantillas y layouts del Theme Builder de Divi 5.
	 *
	 * Sin id de página: una plantilla afecta a todo el sitio. Exige `edit_theme_options` y `edit_others_posts`, el
	 * permiso `theme_builder` de Divi cuando Divi está cargado, la capacidad `nexo_mcp_divi_theme_builder` con su
	 * interruptor `divi_theme_builder_tools_enabled` (y su scope en el token). No depende de los otros interruptores.
	 */
	public function can_write_divi_theme_builder(): bool {
		return $this->can_module_write( 'divi_theme_builder_tools_enabled', Capabilities::DIVI_THEME_BUILDER, 'edit_theme_options' )
			&& current_user_can( 'edit_others_posts' )
			&& ( ! function_exists( 'et_pb_is_allowed' ) || (bool) et_pb_is_allowed( 'theme_builder' ) );
	}

	/**
	 * Comprueba si esta solicitud puede escribir en la biblioteca de Divi 5.
	 *
	 * Como las rutas REST de la biblioteca de Divi: `edit_posts` y el permiso `divi_library` de Divi cuando Divi está
	 * cargado, más la capacidad `nexo_mcp_divi_library` con su interruptor `divi_library_tools_enabled` (y su scope en
	 * el token). Sin id (alta de un ítem) pide además `save_library`. Con id, el post tiene que existir, ser un ítem de
	 * la biblioteca (`et_pb_layout`) y el usuario poder editarlo; si el ítem es global (término `global` de `scope`)
	 * (o, como Divi, un módulo con `_et_pb_excluded_global_options`: un global importado de Divi 4) pide `edit_global_library`,
	 * porque cambiarlo cambia todas las páginas que lo usan.
	 *
	 * NO se alinea con la ruta `/divi-library/save` de Divi 5.19.1, que pide `edit_global_library` para CUALQUIER ítem
	 * (`DiviLibraryController::save_permission`): esa ruta es la del guardado del Visual Builder, compartida con los módulos
	 * globales, y no distingue qué ítem toca. Aquí sí se sabe (los términos y metas del ítem, fallando cerrado si no se pueden
	 * leer), y cambiar un ítem NO global sólo afecta a ese ítem (las páginas llevan una copia), así que el permiso global se
	 * pide exactamente donde hay efecto sobre otras páginas: actualizar, borrar o restaurar un global, y también insertarlo
	 * (por copia o por referencia) con `insert-divi-library-item`. Pedirlo siempre sólo dejaría sin editar sus propios ítems a
	 * los roles que Divi sí deja usar `divi_library`/`save_library`; los ítems no globales siguen exigiendo `edit_post`.
	 *
	 * @param int|null $item_id Ítem de la biblioteca, o null para dar de alta uno nuevo.
	 */
	public function can_write_divi_library( ?int $item_id = null ): bool {
		$divi = function_exists( 'et_pb_is_allowed' );

		if ( ! $this->can_module_write( 'divi_library_tools_enabled', Capabilities::DIVI_LIBRARY, 'edit_posts' )
			|| ( $divi && ! et_pb_is_allowed( 'divi_library' ) ) ) {
			return false;
		}

		if ( null === $item_id ) {
			return ! $divi || (bool) et_pb_is_allowed( 'save_library' );
		}

		$post = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $post instanceof \WP_Post || 'et_pb_layout' !== $post->post_type || ! current_user_can( 'edit_post', $item_id ) ) {
			return false;
		}

		if ( ! $divi ) {
			return true;
		}

		// Si no se pueden leer los términos (taxonomía sin registrar), se trata como global: falla cerrado.
		$scopes = wp_get_object_terms( $item_id, 'scope', array( 'fields' => 'slugs' ) );
		$global = ! is_array( $scopes ) || in_array( 'global', $scopes, true )
			|| DiviLibrarySchema::meta_is_global( get_post_meta( $item_id, '_et_pb_module_type', true ), get_post_meta( $item_id, '_et_pb_excluded_global_options', true ) );

		return ! $global || (bool) et_pb_is_allowed( 'edit_global_library' );
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
