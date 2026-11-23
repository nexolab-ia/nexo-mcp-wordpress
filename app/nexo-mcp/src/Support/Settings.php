<?php
/**
 * Acceso tipado a la configuración.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Support;

/**
 * Gestiona la opción principal y sus valores iniciales.
 */
final class Settings {

	public const OPTION_NAME = 'nexo_mcp_settings';

	public const VERSION_OPTION = 'nexo_mcp_version';

	/**
	 * Devuelve la configuración inicial.
	 *
	 * Una conexión nueva empieza en solo lectura. El propietario puede habilitar
	 * administración desde el panel cuando realmente la necesite, pero el primer
	 * consentimiento OAuth nunca solicita escritura por sorpresa.
	 *
	 * Siguen apagadas las dos que causan daño difícil de deshacer: eliminar
	 * contenido e instalar o activar plugins. Se encienden con un clic cuando el
	 * propietario decide que las necesita.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function defaults(): array {
		return array(
			'server_enabled'              => true,
			'wordpress_tools_enabled'     => true,
			'woocommerce_tools_enabled'   => true,
			'content_tools_enabled'       => true,
			'seo_tools_enabled'           => true,
			'appearance_tools_enabled'    => true,
			'maintenance_tools_enabled'   => false,
			// Llave aparte del mantenimiento: borrar un plugin corre su
			// desinstalador y eso no se deshace (ADR-029).
			'extension_deletion_enabled'  => false,
			'builders_tools_enabled'      => true,
			// Cambia clases, variables, colores y tipografía de todo el sitio en vivo:
			// apagado hasta que el dueño lo enciende (con respaldo y deshacer).
			'design_system_tools_enabled' => false,
			// Asignar datos dinámicos (tags de Elementor Pro) a elementos: apagado hasta que el dueño lo enciende.
			'builders_dynamic_enabled'    => false,
			// Crear componentes de Elementor 4 (Pro): apagado hasta que el dueño lo enciende.
			'builders_components_enabled' => false,
			// Cambia paleta, variables y tipografía globales de Divi 5 de todo el sitio en vivo: apagado hasta que el dueño lo enciende.
			'divi_design_tools_enabled'   => false,
			'publishing_enabled'          => true,
			'destructive_enabled'         => false,
			'read_only'                   => true,
			// Con esto en true no se puede editar nada que ya esté publicado,
			// que es justo lo primero que intenta hacer cualquiera al conectar.
			'drafts_only'                 => false,
			// Toda edición a contenido publicado va primero a una copia de
			// trabajo (borrador enlazado); el original cambia al publicarla.
			'working_copy_default'        => true,
			// Encendida por defecto: sin purgar, el conector informa éxito y el
			// visitante sigue viendo lo anterior. No hay error que perseguir y el
			// dueño concluye que la herramienta no sirve. La purga es selectiva,
			// así que no rehace el sitio entero por cambiar un párrafo.
			'cache_purge_enabled'         => true,
			// Alcances por conexión: un token OAuth sólo ejerce los scopes que se
			// le concedieron. Los interruptores del panel siguen siendo el techo.
			'enforce_connection_scopes'   => true,
			'audit_retention_days'        => 90,
			// Dirección pública del conector cuando el sitio se publica detrás de
			// un túnel o un proxy. Vacía significa que no hay ninguno: el conector
			// sólo se reconoce por la dirección configurada del sitio.
			'proxy_resource'              => '',
		);
	}

	/**
	 * Obtiene la configuración completa.
	 *
	 * @return array<string, bool|int|string>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Obtiene un valor de configuración.
	 *
	 * @param string $key Identificador del ajuste.
	 * @return bool|int|string|null
	 */
	public function get( string $key ) {
		$settings = $this->all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Instala opciones sin sobrescribir la política existente.
	 */
	public function install_defaults(): void {
		add_option( self::OPTION_NAME, self::defaults(), '', false );
		update_option( self::VERSION_OPTION, NEXO_MCP_VERSION, false );
	}
}
