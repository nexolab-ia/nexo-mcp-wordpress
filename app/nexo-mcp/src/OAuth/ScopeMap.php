<?php
/**
 * Traducción entre scopes OAuth y capacidades Nexo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Define el vocabulario de scopes del servidor de autorización.
 *
 * Un scope nunca reemplaza a la capacidad de WordPress ni a los interruptores
 * del panel: es un límite adicional por conexión. Con el ajuste
 * `enforce_connection_scopes` encendido (por defecto), cada token sólo ejerce
 * lo que se le concedió; apagado, se vuelve al comportamiento del original en
 * que del token sólo se exige `mcp:connect`.
 */
final class ScopeMap {

	/**
	 * Scope mínimo exigido a cualquier token del conector.
	 */
	public const CONNECT = 'mcp:connect';

	/**
	 * Correspondencia entre scope y capacidad Nexo.
	 *
	 * @var array<string, string>
	 */
	private const MAP = array(
		self::CONNECT             => Capabilities::CONNECT,
		'mcp:read'                => Capabilities::READ,
		'mcp:write'               => Capabilities::WRITE,
		'mcp:content'             => Capabilities::CONTENT_ADVANCED,
		'mcp:seo'                 => Capabilities::SEO,
		'mcp:appearance'          => Capabilities::APPEARANCE,
		'mcp:maintenance'         => Capabilities::MAINTENANCE,
		'mcp:commerce'            => Capabilities::COMMERCE,
		'mcp:builders'            => Capabilities::BUILDERS,
		'mcp:builder_attrs'       => Capabilities::BUILDER_ATTRS,
		'mcp:builders:dynamic'    => Capabilities::BUILDERS_DYNAMIC,
		'mcp:builders:components' => Capabilities::BUILDERS_COMPONENTS,
		'mcp:publish'             => Capabilities::PUBLISH,
		'mcp:destructive'         => Capabilities::DESTRUCTIVE,
		'mcp:design_system'       => Capabilities::DESIGN_SYSTEM,
		// Variantes de sólo consulta: apuntan a la misma capacidad que su módulo.
		'mcp:seo:read'            => Capabilities::SEO,
		'mcp:maintenance:read'    => Capabilities::MAINTENANCE,
	);

	/**
	 * Variante de sólo consulta de cada módulo que la tiene.
	 *
	 * Un módulo se pedía entero o no se pedía: `mcp:seo` cubría leer una
	 * auditoría y reescribir el `robots.txt` por igual, así que el perfil de
	 * sólo lectura —que no concede módulos— dejaba sin consultar el SEO y sin
	 * saber si había actualizaciones. Estas variantes permiten conceder la
	 * consulta sin la edición, y el scope pleno sigue implicando a la suya para
	 * no invalidar los tokens ya emitidos.
	 *
	 * @var array<string, string>
	 */
	private const READ_SCOPE = array(
		'mcp:seo'         => 'mcp:seo:read',
		'mcp:maintenance' => 'mcp:maintenance:read',
	);

	/**
	 * Conjunto anunciado en los metadatos de descubrimiento.
	 *
	 * La especificación pide que `scopes_supported` represente el mínimo
	 * necesario para la funcionalidad básica. Los módulos sensibles se piden
	 * después mediante step-up authorization.
	 *
	 * @var array<int, string>
	 */
	private const ADVERTISED = array(
		self::CONNECT,
		'mcp:read',
		'mcp:write',
	);

	/**
	 * Interruptor que habilita cada scope opcional.
	 *
	 * Los que no aparecen aquí se anuncian siempre: son el mínimo para conectar
	 * y trabajar con contenido.
	 *
	 * @var array<string, string>
	 */
	private const MODULE_SWITCH = array(
		'mcp:content'             => 'content_tools_enabled',
		'mcp:seo'                 => 'seo_tools_enabled',
		'mcp:appearance'          => 'appearance_tools_enabled',
		'mcp:maintenance'         => 'maintenance_tools_enabled',
		'mcp:commerce'            => 'woocommerce_tools_enabled',
		'mcp:builders'            => 'builders_tools_enabled',
		'mcp:builder_attrs'       => 'builders_tools_enabled',
		'mcp:publish'             => 'publishing_enabled',
		'mcp:destructive'         => 'destructive_enabled',
		'mcp:design_system'       => 'design_system_tools_enabled',
		// Al final: el baseline de PHPStan fija las primeras entradas de esta lista.
		'mcp:builders:dynamic'    => 'builders_dynamic_enabled',
		'mcp:builders:components' => 'builders_components_enabled',
	);

	/**
	 * Descripciones mostradas en la pantalla de consentimiento.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		self::CONNECT             => 'Conectarse a este sitio',
		'mcp:read'                => 'Leer contenido, medios y configuración pública',
		'mcp:write'               => 'Crear y editar borradores',
		'mcp:content'             => 'Administrar medios, taxonomías y revisiones',
		'mcp:seo'                 => 'Gestionar metadatos SEO, sitemap y robots',
		'mcp:appearance'          => 'Modificar identidad del sitio, CSS y menús',
		'mcp:maintenance'         => 'Instalar, actualizar, activar y desactivar plugins',
		'mcp:commerce'            => 'Administrar productos de WooCommerce',
		'mcp:builders'            => 'Editar textos en páginas hechas con constructores visuales',
		'mcp:builder_attrs'       => 'Cambiar enlaces e imágenes en páginas hechas con constructores visuales',
		'mcp:builders:dynamic'    => 'Asignar datos dinámicos de Elementor (título del sitio, imagen destacada…) a textos e imágenes de las páginas',
		'mcp:builders:components' => 'Crear componentes de Elementor reutilizables en todo el sitio',
		'mcp:publish'             => 'Publicar contenido',
		'mcp:destructive'         => 'Eliminar contenido de forma permanente',
		'mcp:design_system'       => 'Cambiar clases, variables, colores y tipografía globales de Elementor (afecta a todo el sitio)',
		'mcp:seo:read'            => 'Consultar metadatos SEO, sitemap y robots, sin modificarlos',
		'mcp:maintenance:read'    => 'Ver plugins y temas instalados y sus actualizaciones, sin aplicarlas',
	);

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}

	/**
	 * Entrega los scopes anunciados en los metadatos.
	 *
	 * @param Settings|null $settings Configuración operativa del sitio.
	 *
	 * @return array<int, string>
	 */
	public static function advertised( ?Settings $settings = null ): array {
		$settings = $settings ?? new Settings();
		$profile  = (bool) $settings->get( 'read_only' ) ? 'read' : 'manage';

		return self::for_profile( $profile, $settings );
	}

	/**
	 * Entrega los scopes correspondientes a un perfil de conexión.
	 *
	 * El perfil de lectura se mantiene deliberadamente cerrado aunque haya
	 * módulos de edición habilitados en el sitio. El perfil de administración
	 * conserva el comportamiento modular existente.
	 *
	 * @param string        $profile  Perfil `read` o `manage`.
	 * @param Settings|null $settings Configuración operativa del sitio.
	 *
	 * @return array<int, string>
	 */
	public static function for_profile( string $profile, ?Settings $settings = null ): array {
		$settings = $settings ?? new Settings();

		if ( 'manage' !== $profile ) {
			$announced = array( self::CONNECT, 'mcp:read' );

			/*
			 * Consultar no es editar. El perfil de lectura suma la variante de
			 * consulta de los módulos que la tienen y que el sitio tenga
			 * encendidos, para que una auditoría pueda mirar el SEO y las
			 * actualizaciones pendientes. La edición sigue fuera: su scope
			 * pleno no se anuncia aquí, y `read_only` corta igual.
			 */
			foreach ( self::READ_SCOPE as $full => $read_only_scope ) {
				$switch = self::MODULE_SWITCH[ $full ] ?? '';

				if ( '' !== $switch && (bool) $settings->get( $switch ) ) {
					$announced[] = $read_only_scope;
				}
			}

			return $announced;
		}

		$announced = self::ADVERTISED;

		/*
		 * Un scope que no se anuncia es un scope que el cliente nunca pide: los
		 * clientes MCP construyen su solicitud a partir de estos metadatos. Con
		 * una lista fija, encender un módulo en el panel no servía de nada —el
		 * token seguía sin su scope y la herramienta respondía «permiso
		 * denegado»—, así que se anuncia lo que el sitio tiene realmente
		 * habilitado. Quien decide sigue siendo el dueño en la pantalla de
		 * consentimiento, que enumera cada permiso antes de concederlo.
		 */
		foreach ( self::MODULE_SWITCH as $scope => $switch ) {
			if ( (bool) $settings->get( $switch ) ) {
				$announced[] = $scope;
			}
		}

		return $announced;
	}

	/**
	 * Entrega todos los scopes reconocidos por el servidor.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::MAP );
	}

	/**
	 * Indica si un scope pertenece al vocabulario del conector.
	 *
	 * @param string $scope Scope evaluado.
	 */
	public static function is_known( string $scope ): bool {
		return isset( self::MAP[ $scope ] );
	}

	/**
	 * Obtiene la capacidad Nexo exigida por un scope.
	 *
	 * @param string $scope Scope evaluado.
	 */
	public static function capability_for( string $scope ): ?string {
		return self::MAP[ $scope ] ?? null;
	}

	/**
	 * Obtiene la etiqueta legible de un scope.
	 *
	 * @param string $scope Scope evaluado.
	 */
	public static function label_for( string $scope ): string {
		return self::LABELS[ $scope ] ?? $scope;
	}

	/**
	 * Convierte una cadena `scope` de OAuth en una lista normalizada.
	 *
	 * Descarta los valores desconocidos en lugar de fallar, tal como exige
	 * OAuth 2.1 para scopes no reconocidos, y garantiza la presencia del
	 * scope base de conexión.
	 *
	 * @param string $raw Cadena separada por espacios.
	 *
	 * @return array<int, string>
	 */
	public static function parse( string $raw ): array {
		$requested = preg_split( '/\s+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $requested ) ) {
			$requested = array();
		}

		$granted = array();

		foreach ( $requested as $scope ) {
			if ( self::is_known( $scope ) && ! in_array( $scope, $granted, true ) ) {
				$granted[] = $scope;
			}
		}

		if ( ! in_array( self::CONNECT, $granted, true ) ) {
			array_unshift( $granted, self::CONNECT );
		}

		return $granted;
	}

	/**
	 * Serializa una lista de scopes al formato de OAuth.
	 *
	 * @param array<int, string> $scopes Lista normalizada.
	 */
	public static function to_string( array $scopes ): string {
		return implode( ' ', $scopes );
	}

	/**
	 * Filtra los scopes que el usuario realmente puede ejercer.
	 *
	 * Impide que un token nazca con más alcance que su titular: si el usuario
	 * no tiene la capacidad, el scope no se concede aunque lo haya pedido.
	 *
	 * @param array<int, string> $scopes  Scopes solicitados.
	 * @param int                $user_id Usuario que otorga el consentimiento.
	 *
	 * @return array<int, string>
	 */
	public static function filter_by_user( array $scopes, int $user_id ): array {
		$allowed = array();

		foreach ( $scopes as $scope ) {
			$capability = self::capability_for( $scope );

			if ( null !== $capability && user_can( $user_id, $capability ) ) {
				$allowed[] = $scope;
			}
		}

		return $allowed;
	}

	/**
	 * Scopes que acreditan el uso de una capacidad Nexo en una operación.
	 *
	 * Es la regla de «alcances por conexión»: basta con que el token tenga
	 * uno de los scopes devueltos. Para una lectura de un módulo con variante
	 * de consulta se acepta el scope pleno o el de consulta; para una lectura
	 * de un módulo sin variante basta con `mcp:read`; para una escritura se
	 * exige siempre el scope pleno.
	 *
	 * @param string $capability Capacidad Nexo exigida.
	 * @param string $operation  `read` o `write`.
	 *
	 * @return array<int, string> Lista vacía si la capacidad no tiene scope.
	 */
	public static function scopes_for_capability( string $capability, string $operation ): array {
		$full = array_search( $capability, self::MAP, true );

		if ( false === $full ) {
			return array();
		}

		// Las variantes de consulta comparten capacidad con su módulo; el
		// primero en MAP es siempre el pleno.
		$full = (string) $full;

		if ( 'write' === $operation ) {
			return array( $full );
		}

		if ( isset( self::READ_SCOPE[ $full ] ) ) {
			return array( $full, self::READ_SCOPE[ $full ] );
		}

		if ( in_array( $full, array( self::CONNECT, 'mcp:read', 'mcp:write', 'mcp:publish', 'mcp:destructive' ), true ) ) {
			return array( $full );
		}

		// Lectura de un módulo sin variante de consulta: alcanza con leer.
		return array( $full, 'mcp:read' );
	}

	/**
	 * Indica si el token tiene al menos uno de los scopes dados.
	 *
	 * @param array<int, string> $granted  Scopes del token.
	 * @param array<int, string> $accepted Scopes aceptables.
	 */
	public static function satisfies_any( array $granted, array $accepted ): bool {
		if ( array() === $accepted ) {
			return true;
		}

		return array() !== array_intersect( $granted, $accepted );
	}

	/**
	 * Verifica que un token contenga todos los scopes exigidos.
	 *
	 * @param array<int, string> $granted  Scopes del token.
	 * @param array<int, string> $required Scopes necesarios para la operación.
	 */
	public static function satisfies( array $granted, array $required ): bool {
		foreach ( $required as $scope ) {
			if ( ! in_array( $scope, $granted, true ) ) {
				return false;
			}
		}

		return true;
	}
}
