<?php
/**
 * Capacidades propias del conector.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Authorization;

/**
 * Evita repetir strings de autorización.
 */
final class Capabilities {

	public const CONNECT = 'nexo_mcp_connect';

	public const READ = 'nexo_mcp_read';

	public const WRITE = 'nexo_mcp_write';

	public const MANAGE_CREDENTIALS = 'nexo_mcp_manage_credentials';

	public const CONTENT_ADVANCED = 'nexo_mcp_content_advanced';

	public const SEO = 'nexo_mcp_seo';

	public const APPEARANCE = 'nexo_mcp_appearance';

	public const MAINTENANCE = 'nexo_mcp_maintenance';

	public const COMMERCE = 'nexo_mcp_commerce';

	public const BUILDERS = 'nexo_mcp_builders';

	/**
	 * Edición de atributos dentro de un constructor.
	 *
	 * Va separada de BUILDERS a propósito. Cambiar un texto altera lo que se
	 * lee; cambiar un enlace decide a dónde va el visitante, y cambiar una
	 * imagen decide qué carga su navegador. Quien concede una cosa no concede
	 * necesariamente la otra.
	 */
	public const BUILDER_ATTRS = 'nexo_mcp_builder_attrs';

	/**
	 * Asignar datos dinámicos (dynamic tags) a las props de un elemento atómico.
	 *
	 * Va separada de BUILDERS a propósito: el tag se evalúa en el render para
	 * cualquier visitante (título del sitio, imagen destacada…). Además lo
	 * enciende aparte el interruptor `builders_dynamic_enabled`.
	 */
	public const BUILDERS_DYNAMIC = 'nexo_mcp_builders_dynamic';

	/**
	 * Crear componentes de Elementor 4 (reutilizables en todo el sitio).
	 *
	 * Va separada de BUILDERS a propósito: crear un componente cambia el sitio,
	 * no sólo una página. Además la enciende aparte el interruptor
	 * `builders_components_enabled`.
	 */
	public const BUILDERS_COMPONENTS = 'nexo_mcp_builders_components';

	public const PUBLISH = 'nexo_mcp_publish';

	public const DESTRUCTIVE = 'nexo_mcp_destructive';

	/**
	 * Escritura del sistema de diseño de Elementor (clases, variables, colores y tipografía).
	 *
	 * Va aparte de BUILDERS: cambia el aspecto de todo el sitio en vivo, no una página.
	 */
	public const DESIGN_SYSTEM = 'nexo_mcp_design_system';

	/**
	 * Cambiar el diseño global de Divi 5 (paleta, variables, fuentes y tipografía).
	 *
	 * Va aparte de BUILDERS y de DESIGN_SYSTEM (Elementor): cambia el aspecto de todo el sitio
	 * en vivo, y habilitar Elementor no habilita Divi. Además la enciende aparte el interruptor
	 * `divi_design_tools_enabled`.
	 */
	public const DIVI_DESIGN = 'nexo_mcp_divi_design';

	/**
	 * Cambiar los presets de módulos de Divi 5 (módulo y grupos de opciones).
	 *
	 * Va aparte de DIVI_DESIGN: un preset cambia el aspecto de todos los módulos que lo usan o que
	 * lo tienen como predeterminado. Además la enciende aparte el interruptor `divi_presets_tools_enabled`.
	 */
	public const DIVI_PRESETS = 'nexo_mcp_divi_presets';

	/**
	 * Poner contenido dinámico y bucles de consulta en páginas de Divi 5.
	 *
	 * Va aparte de BUILDERS y de BUILDERS_DYNAMIC (Elementor): un dato dinámico o un bucle muestra
	 * datos del sitio a cualquier visitante. Además lo enciende aparte el interruptor `divi_dynamic_tools_enabled`.
	 */
	public const DIVI_DYNAMIC = 'nexo_mcp_divi_dynamic';

	/**
	 * Poner condiciones de visualización en módulos de Divi 5.
	 *
	 * Va aparte de BUILDERS y de DIVI_DYNAMIC: una condición decide qué ve cada visitante (ocultar o mostrar
	 * contenido). Además lo enciende aparte el interruptor `divi_conditions_tools_enabled`.
	 */
	public const DIVI_CONDITIONS = 'nexo_mcp_divi_conditions';

	/**
	 * Crear y cambiar plantillas del Theme Builder de Divi 5 y sus layouts.
	 *
	 * Va aparte de BUILDERS, DIVI_DYNAMIC y DIVI_CONDITIONS: una plantilla decide el encabezado, el pie y el cuerpo de
	 * todo el sitio. Además lo enciende aparte el interruptor `divi_theme_builder_tools_enabled`.
	 */
	public const DIVI_THEME_BUILDER = 'nexo_mcp_divi_theme_builder';

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}
}
