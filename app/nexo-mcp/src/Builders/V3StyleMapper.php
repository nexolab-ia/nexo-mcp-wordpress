<?php
/**
 * Contrato del traductor de estilos de Elementor 3 a estilos atómicos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Traduce los ajustes de estilo de un elemento v3 a variantes de un estilo local atómico.
 *
 * `ElementorV3Migrator` lo recibe inyectado; `ElementorV3StyleMapper` lo implementa.
 */
interface V3StyleMapper {

	/**
	 * Traduce los ajustes de estilo de un elemento v3.
	 *
	 * @param string               $widget_type Tipo v3: `section`, `column`, `container` o el nombre del widget (`heading`, `text-editor`, `button`, `image`, `divider`).
	 * @param array<string, mixed> $settings    Ajustes v3 del elemento (el migrador ya quitó los que traslada él: contenido, enlace, estructura).
	 *
	 * @return array{styles: array<int, array<string, mixed>>, unmapped: list<string>} `styles`: lista de variantes
	 *         `{meta: {breakpoint, state}, props: {…}}` listas para un estilo local que acepta `ElementorTreeValidator`;
	 *         `unmapped`: claves de ajustes v3 que no se trasladaron.
	 */
	public function map( string $widget_type, array $settings ): array;
}
