<?php
/**
 * Traductor de estilos falso para probar el migrador sin las reglas reales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests\Fakes;

use NexoDev\NexoMCP\Builders\V3StyleMapper;

/**
 * Devuelve lo configurado por tipo y recuerda cada llamada.
 */
final class FakeV3StyleMapper implements V3StyleMapper {

	/**
	 * Variantes por tipo v3.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $styles = array();

	/**
	 * Claves no trasladadas por tipo v3.
	 *
	 * @var array<string, list<string>>
	 */
	public array $unmapped = array();

	/**
	 * Llamadas recibidas.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = array();

	/**
	 * Traduce.
	 *
	 * @param string               $widget_type Tipo v3.
	 * @param array<string, mixed> $settings    Ajustes.
	 *
	 * @return array{styles: array<int, array<string, mixed>>, unmapped: list<string>}
	 */
	public function map( string $widget_type, array $settings ): array {
		$this->calls[] = array( $widget_type, $settings );

		return array(
			'styles'   => $this->styles[ $widget_type ] ?? array(),
			'unmapped' => $this->unmapped[ $widget_type ] ?? array(),
		);
	}
}
