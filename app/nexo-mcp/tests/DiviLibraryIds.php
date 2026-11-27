<?php
/**
 * Ids y nombres del escenario de `DiviLibraryFixtures`. Van en una interfaz porque un trait no puede tener constantes en
 * PHP 8.1 (llegaron en 8.2).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

/**
 * Constantes del escenario de la biblioteca de Divi 5.
 */
interface DiviLibraryIds {

	public const SWITCH = 'divi_library_tools_enabled';

	public const PAGE = 100;

	public const SECTION = 800;

	public const ROW = 801;

	public const MODULE = 802;

	public const GLOBAL = 803;

	public const TRASHED = 804;

	public const AUTO_DRAFT = 805;

	public const FOREIGN = 806;
}
