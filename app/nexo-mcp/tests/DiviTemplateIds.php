<?php
/**
 * Ids y nombres del escenario de `DiviTemplateFixtures`. Van en una interfaz porque un trait no puede tener constantes en
 * PHP 8.1 (llegaron en 8.2).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

/**
 * Constantes del escenario de plantillas del Theme Builder.
 */
interface DiviTemplateIds {

	public const SWITCH = 'divi_theme_builder_tools_enabled';

	public const TB = 900;

	public const HEADER = 910;

	public const BODY = 911;

	public const BODY_BARE = 912;

	public const FOOTER = 913;

	public const HEADER_DRAFT = 914;
}
