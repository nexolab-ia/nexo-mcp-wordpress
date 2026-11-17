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

	public const PUBLISH = 'nexo_mcp_publish';

	public const DESTRUCTIVE = 'nexo_mcp_destructive';

	/**
	 * Impide instanciar el catálogo.
	 */
	private function __construct() {
	}
}
