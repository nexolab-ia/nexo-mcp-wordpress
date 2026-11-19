<?php
/**
 * Fuente de colores, tipografías y variables que usa el traductor de estilos v3.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lo que `ElementorV3StyleMapper` necesita del Kit y de las variables. Inyectable para que
 * PHPUnit corra sin WordPress; `ElementorV3StyleSource` es la implementación sobre Elementor.
 */
interface V3StyleSource {

	/**
	 * Color global del Kit por su id (`_id` de `system_colors` / `custom_colors`).
	 *
	 * @param string $id Id del color global (lo que va tras `globals/colors?id=`).
	 *
	 * @return array{title: string, color: string}|null Null si no existe.
	 */
	public function kit_color( string $id ): ?array;

	/**
	 * Tipografía global del Kit por su id, con las claves v3 del grupo (`typography_font_family`,
	 * `typography_font_size`, `typography_font_size_tablet`…).
	 *
	 * @param string $id Id de la tipografía global (lo que va tras `globals/typography?id=`).
	 *
	 * @return array<string, mixed>|null Null si no existe.
	 */
	public function kit_typography( string $id ): ?array;

	/**
	 * Variables atómicas de color existentes (sin las borradas).
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function color_variables(): array;
}
