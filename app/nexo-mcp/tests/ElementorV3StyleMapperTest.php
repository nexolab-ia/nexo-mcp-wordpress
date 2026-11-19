<?php
/**
 * Traductor de estilos Elementor 3 → atómicos (clase pura, con la fuente del Kit inyectada).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use NexoDev\NexoMCP\Builders\ElementorV3Migrator;
use NexoDev\NexoMCP\Builders\ElementorV3StyleMapper;
use NexoDev\NexoMCP\Builders\V3StyleSource;
use PHPUnit\Framework\TestCase;

/**
 * Cada regla de estilos de S5/S7 de `odd/tasks/migrar-v3-v4.md` con sus casos.
 */
final class ElementorV3StyleMapperTest extends TestCase {

	/**
	 * Fuente en memoria.
	 *
	 * @param array<string, array{title: string, color: string}> $colors     Colores del Kit por id.
	 * @param array<string, array<string, mixed>>                $typography Tipografías del Kit por id.
	 * @param list<array{id: string, label: string}>             $variables  Variables de color.
	 */
	private function source( array $colors = array(), array $typography = array(), array $variables = array() ): V3StyleSource {
		return new class( $colors, $typography, $variables ) implements V3StyleSource {
			/**
			 * @param array<string, array{title: string, color: string}> $colors     Colores.
			 * @param array<string, array<string, mixed>>                $typography Tipografías.
			 * @param list<array{id: string, label: string}>             $variables  Variables.
			 */
			public function __construct( private array $colors, private array $typography, private array $variables ) {}

			public function kit_color( string $id ): ?array {
				return $this->colors[ $id ] ?? null;
			}

			public function kit_typography( string $id ): ?array {
				return $this->typography[ $id ] ?? null;
			}

			public function color_variables(): array {
				return $this->variables;
			}
		};
	}

	/**
	 * @param array<string, mixed> $settings Ajustes v3.
	 * @return array{styles: array<int, array<string, mixed>>, unmapped: list<string>}
	 */
	private function map( string $type, array $settings, ?V3StyleSource $source = null ): array {
		return ( new ElementorV3StyleMapper( $source ?? $this->source() ) )->map( $type, $settings );
	}

	/**
	 * Props de la variante de un breakpoint.
	 *
	 * @param array{styles: array<int, array<string, mixed>>, unmapped: list<string>} $result Resultado.
	 * @return array<string, mixed>
	 */
	private function props( array $result, string $breakpoint = 'desktop' ): array {
		foreach ( $result['styles'] as $variant ) {
			if ( $breakpoint === $variant['meta']['breakpoint'] ) {
				return $variant['props'];
			}
		}

		return array();
	}

	/**
	 * @param array<string, mixed> $value Valor.
	 * @return array<string, mixed>
	 */
	private function sz( $size, string $unit ): array {
		return array(
			'$$type' => 'size',
			'value'  => array(
				'size' => $size,
				'unit' => $unit,
			),
		);
	}

	private function str( string $value, string $type = 'string' ): array {
		return array(
			'$$type' => $type,
			'value'  => $value,
		);
	}

	/**
	 * @param array<string, mixed> $v Lados.
	 * @return array<string, mixed>
	 */
	private function dims( $top, $right, $bottom, $left, string $unit, bool $linked = false ): array {
		return array(
			'unit'     => $unit,
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => $linked,
		);
	}

	public function test_nothing_to_translate_gives_no_variants_and_nothing_unmapped(): void {
		$this->assertSame(
			array(
				'styles'   => array(),
				'unmapped' => array(),
			),
			$this->map( 'heading', array() )
		);
	}

	public function test_custom_typography_group_is_translated(): void {
		$result = $this->map(
			'heading',
			array(
				'typography_typography'     => 'custom',
				'typography_font_family'    => 'Roboto',
				'typography_font_size'      => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
				'typography_font_weight'    => '700',
				'typography_line_height'    => array( 'unit' => 'em', 'size' => 1.5 ),
				'typography_letter_spacing' => array( 'unit' => 'px', 'size' => 2 ),
				'typography_text_transform' => 'uppercase',
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				'font-family'    => $this->str( 'Roboto', 'font-family' ),
				'font-size'      => $this->sz( 24, 'px' ),
				'font-weight'    => $this->str( '700' ),
				'line-height'    => $this->sz( 1.5, 'em' ),
				'letter-spacing' => $this->sz( 2, 'px' ),
				'text-transform' => $this->str( 'uppercase' ),
			),
			$this->props( $result )
		);
		$this->assertSame( array( 'breakpoint' => 'desktop', 'state' => null ), $result['styles'][0]['meta'] );
	}

	public function test_typography_without_custom_is_ignored_and_not_listed(): void {
		$result = $this->map(
			'heading',
			array(
				'typography_typography'  => '',
				'typography_font_family' => 'Roboto',
				'typography_font_size'   => array( 'unit' => 'px', 'size' => 24 ),
			)
		);

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_typography_values_that_do_not_fit_go_to_unmapped(): void {
		$result = $this->map(
			'heading',
			array(
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'typography_font_size'   => array( 'unit' => 'custom', 'size' => '' ),
				'typography_font_weight' => '650',
				'typography_font_style'  => 'italic',
			)
		);

		$this->assertSame( array( 'font-family' => $this->str( 'Roboto', 'font-family' ) ), $this->props( $result ) );
		$this->assertEqualsCanonicalizing( array( 'typography_font_weight', 'typography_font_style' ), $result['unmapped'] );
	}

	public function test_tablet_and_mobile_suffixes_become_their_own_variants(): void {
		$result = $this->map(
			'heading',
			array(
				'typography_typography'       => 'custom',
				'typography_font_size'        => array( 'unit' => 'px', 'size' => 32 ),
				'typography_font_size_tablet' => array( 'unit' => 'px', 'size' => 24 ),
				'typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 18 ),
				'align_mobile'                => 'center',
				'title_color'                 => '#111111',
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame( array( 'desktop', 'tablet', 'mobile' ), array_map( static fn( array $v ): string => $v['meta']['breakpoint'], $result['styles'] ) );
		$this->assertSame(
			array(
				'font-size' => $this->sz( 32, 'px' ),
				'color'     => $this->str( '#111111', 'color' ),
			),
			$this->props( $result, 'desktop' )
		);
		$this->assertSame( array( 'font-size' => $this->sz( 24, 'px' ) ), $this->props( $result, 'tablet' ) );
		$this->assertSame(
			array(
				'font-size'  => $this->sz( 18, 'px' ),
				'text-align' => $this->str( 'center' ),
			),
			$this->props( $result, 'mobile' )
		);
	}

	public function test_alignment_becomes_text_align_for_text_widgets(): void {
		$this->assertSame( array( 'text-align' => $this->str( 'start' ) ), $this->props( $this->map( 'heading', array( 'align' => 'left' ) ) ) );
		$this->assertSame( array( 'text-align' => $this->str( 'end' ) ), $this->props( $this->map( 'text-editor', array( 'align' => 'right' ) ) ) );
		$this->assertSame( array( 'text-align' => $this->str( 'justify' ) ), $this->props( $this->map( 'text-editor', array( 'align' => 'justify' ) ) ) );
		$this->assertSame( array( 'text-align' => $this->str( 'center' ) ), $this->props( $this->map( 'button', array( 'align' => 'center' ) ) ) );
	}

	public function test_alignment_without_a_text_equivalent_is_unmapped(): void {
		$image = $this->map( 'image', array( 'align' => 'center' ) );
		$this->assertSame( array(), $image['styles'] );
		$this->assertSame( array( 'align' ), $image['unmapped'] );

		$button = $this->map( 'button', array( 'align' => 'justify' ) );
		$this->assertSame( array(), $button['styles'] );
		$this->assertSame( array( 'align' ), $button['unmapped'] );
	}

	public function test_text_color_keys_per_widget(): void {
		$this->assertSame( array( 'color' => $this->str( '#ff0000', 'color' ) ), $this->props( $this->map( 'heading', array( 'title_color' => '#ff0000' ) ) ) );
		$this->assertSame( array( 'color' => $this->str( 'rgba(0,0,0,0.5)', 'color' ) ), $this->props( $this->map( 'text-editor', array( 'text_color' => 'rgba(0,0,0,0.5)' ) ) ) );
		$this->assertSame( array( 'color' => $this->str( '#fff', 'color' ) ), $this->props( $this->map( 'button', array( 'button_text_color' => '#fff' ) ) ) );
	}

	public function test_a_color_key_of_another_widget_is_unmapped(): void {
		$result = $this->map( 'heading', array( 'text_color' => '#ff0000' ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array( 'text_color' ), $result['unmapped'] );
	}

	public function test_classic_background_color_is_translated(): void {
		$result = $this->map(
			'column',
			array(
				'background_background' => 'classic',
				'background_color'      => '#eeeeee',
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				'background' => array(
					'$$type' => 'background',
					'value'  => array( 'color' => $this->str( '#eeeeee', 'color' ) ),
				),
			),
			$this->props( $result )
		);
	}

	public function test_widget_advanced_background_uses_the_underscore_prefix(): void {
		$result = $this->map(
			'image',
			array(
				'_background_background' => 'classic',
				'_background_color'      => '#101010',
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame( '#101010', $this->props( $result )['background']['value']['color']['value'] );
	}

	public function test_gradient_background_is_unmapped(): void {
		$result = $this->map(
			'section',
			array(
				'background_background' => 'gradient',
				'background_color'      => '#111111',
				'background_color_b'    => '#222222',
				'background_gradient_angle' => array( 'unit' => 'deg', 'size' => 90 ),
			)
		);

		$this->assertSame( array(), $result['styles'] );
		$this->assertEqualsCanonicalizing(
			array( 'background_background', 'background_color', 'background_color_b', 'background_gradient_angle' ),
			$result['unmapped']
		);
	}

	public function test_background_image_is_unmapped_but_the_color_is_kept(): void {
		$result = $this->map(
			'section',
			array(
				'background_background' => 'classic',
				'background_color'      => '#333333',
				'background_image'      => array( 'url' => 'https://example.com/a.jpg', 'id' => 5 ),
				'background_position'   => 'center center',
			)
		);

		$this->assertSame( '#333333', $this->props( $result )['background']['value']['color']['value'] );
		$this->assertEqualsCanonicalizing( array( 'background_image', 'background_position' ), $result['unmapped'] );
	}

	public function test_background_color_without_a_background_type_is_ignored(): void {
		$result = $this->map( 'section', array( 'background_color' => '#333333' ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_margin_and_padding_become_dimensions(): void {
		$result = $this->map(
			'heading',
			array(
				'_margin'         => $this->dims( 1, 2, 3, 4, 'px' ),
				'_padding'        => $this->dims( 10, 10, 10, 10, 'em', true ),
				'_margin_mobile'  => $this->dims( 0, '', 5, '', '%' ),
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				'margin'  => array(
					'$$type' => 'dimensions',
					'value'  => array(
						'block-start'  => $this->sz( 1, 'px' ),
						'inline-end'   => $this->sz( 2, 'px' ),
						'block-end'    => $this->sz( 3, 'px' ),
						'inline-start' => $this->sz( 4, 'px' ),
					),
				),
				'padding' => array(
					'$$type' => 'dimensions',
					'value'  => array(
						'block-start'  => $this->sz( 10, 'em' ),
						'inline-end'   => $this->sz( 10, 'em' ),
						'block-end'    => $this->sz( 10, 'em' ),
						'inline-start' => $this->sz( 10, 'em' ),
					),
				),
			),
			$this->props( $result )
		);
		$this->assertSame(
			array(
				'margin' => array(
					'$$type' => 'dimensions',
					'value'  => array(
						'block-start' => $this->sz( 0, '%' ),
						'block-end'   => $this->sz( 5, '%' ),
					),
				),
			),
			$this->props( $result, 'mobile' )
		);
	}

	public function test_container_margin_padding_and_button_text_padding(): void {
		$container = $this->map(
			'container',
			array(
				'margin'  => $this->dims( 4, 4, 4, 4, 'px', true ),
				'padding' => $this->dims( 8, 8, 8, 8, 'px', true ),
			)
		);
		$this->assertSame( array(), $container['unmapped'] );
		$this->assertSame( array( 'margin', 'padding' ), array_keys( $this->props( $container ) ) );

		$button = $this->map( 'button', array( 'text_padding' => $this->dims( 12, 24, 12, 24, 'px' ) ) );
		$this->assertSame( array(), $button['unmapped'] );
		$this->assertSame( 24, $this->props( $button )['padding']['value']['inline-end']['value']['size'] );
	}

	public function test_dimensions_with_an_unsupported_unit_are_unmapped(): void {
		$result = $this->map( 'column', array( 'padding' => $this->dims( 1, 1, 1, 1, 'custom' ) ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array( 'padding' ), $result['unmapped'] );
	}

	public function test_empty_dimensions_are_ignored(): void {
		$result = $this->map( 'column', array( 'padding' => $this->dims( '', '', '', '', 'px', true ) ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_border_is_translated(): void {
		$result = $this->map(
			'column',
			array(
				'border_border' => 'solid',
				'border_width'  => $this->dims( 1, 2, 3, 4, 'px' ),
				'border_color'  => '#cccccc',
				'border_radius' => $this->dims( 5, 6, 7, 8, 'px' ),
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				'border-style'  => $this->str( 'solid' ),
				'border-width'  => array(
					'$$type' => 'border-width-v2',
					'value'  => array(
						'block-start'  => $this->sz( 1, 'px' ),
						'inline-end'   => $this->sz( 2, 'px' ),
						'block-end'    => $this->sz( 3, 'px' ),
						'inline-start' => $this->sz( 4, 'px' ),
					),
				),
				'border-color'  => $this->str( '#cccccc', 'color' ),
				'border-radius' => array(
					'$$type' => 'border-radius-v2',
					'value'  => array(
						'start-start' => $this->sz( 5, 'px' ),
						'start-end'   => $this->sz( 6, 'px' ),
						'end-end'     => $this->sz( 7, 'px' ),
						'end-start'   => $this->sz( 8, 'px' ),
					),
				),
			),
			$this->props( $result )
		);
	}

	public function test_border_radius_is_translated_without_a_border_style(): void {
		$result = $this->map( 'button', array( 'border_radius' => $this->dims( 4, 4, 4, 4, 'px', true ) ) );

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame( array( 'border-radius' ), array_keys( $this->props( $result ) ) );
	}

	public function test_border_width_and_color_without_a_border_style_are_ignored(): void {
		$result = $this->map(
			'column',
			array(
				'border_border' => '',
				'border_width'  => $this->dims( 1, 1, 1, 1, 'px', true ),
				'border_color'  => '#cccccc',
			)
		);

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_widget_and_image_border_prefixes(): void {
		$advanced = $this->map( 'heading', array( '_border_border' => 'dashed', '_border_width' => $this->dims( 2, 2, 2, 2, 'px', true ), '_border_color' => '#000' ) );
		$this->assertSame( array(), $advanced['unmapped'] );
		$this->assertSame( 'dashed', $this->props( $advanced )['border-style']['value'] );

		$image = $this->map( 'image', array( 'image_border_border' => 'solid', 'image_border_width' => $this->dims( 1, 1, 1, 1, 'px', true ), 'image_border_radius' => $this->dims( 9, 9, 9, 9, 'px', true ) ) );
		$this->assertSame( array(), $image['unmapped'] );
		$this->assertSame( array( 'border-style', 'border-width', 'border-radius' ), array_keys( $this->props( $image ) ) );
	}

	public function test_global_color_with_a_matching_variable_label_uses_the_variable(): void {
		$source = $this->source(
			array( 'brand' => array( 'title' => 'Brand', 'color' => '#ff0000' ) ),
			array(),
			array( array( 'id' => 'e-gv-123', 'label' => 'brand' ) )
		);
		$result = $this->map( 'heading', array( 'title_color' => '#ff0000', '__globals__' => array( 'title_color' => 'globals/colors?id=brand' ) ), $source );

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame( array( 'color' => $this->str( 'e-gv-123', 'global-color-variable' ) ), $this->props( $result ) );
	}

	public function test_global_color_matches_the_variable_by_sync_id(): void {
		$source = $this->source( array(), array(), array( array( 'id' => 'e-gv-9', 'label' => 'Accent' ) ) );
		$result = $this->map( 'heading', array( '__globals__' => array( 'title_color' => 'globals/colors?id=v4-accent' ) ), $source );

		$this->assertSame( array( 'color' => $this->str( 'e-gv-9', 'global-color-variable' ) ), $this->props( $result ) );
	}

	public function test_global_color_matches_the_variable_by_kit_title(): void {
		$source = $this->source(
			array( 'a1b2c3' => array( 'title' => 'Brand Blue', 'color' => '#0000ff' ) ),
			array(),
			array( array( 'id' => 'e-gv-7', 'label' => 'brand blue' ) )
		);
		$result = $this->map( 'button', array( '__globals__' => array( 'button_text_color' => 'globals/colors?id=a1b2c3' ) ), $source );

		$this->assertSame( array( 'color' => $this->str( 'e-gv-7', 'global-color-variable' ) ), $this->props( $result ) );
	}

	public function test_global_color_without_a_variable_uses_the_kit_literal(): void {
		$source = $this->source( array( 'primary' => array( 'title' => 'Primary', 'color' => '#6EC1E4' ) ) );
		$result = $this->map(
			'column',
			array(
				'background_background' => 'classic',
				'__globals__'           => array( 'background_color' => 'globals/colors?id=primary' ),
			),
			$source
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame( '#6EC1E4', $this->props( $result )['background']['value']['color']['value'] );
	}

	public function test_global_color_overrides_the_local_value_and_unknown_global_falls_back_to_it(): void {
		$source = $this->source( array( 'primary' => array( 'title' => 'Primary', 'color' => '#6EC1E4' ) ) );

		$known = $this->map( 'heading', array( 'title_color' => '#000000', '__globals__' => array( 'title_color' => 'globals/colors?id=primary' ) ), $source );
		$this->assertSame( '#6EC1E4', $this->props( $known )['color']['value'] );

		$unknown = $this->map( 'heading', array( 'title_color' => '#000000', '__globals__' => array( 'title_color' => 'globals/colors?id=gone' ) ), $source );
		$this->assertSame( '#000000', $this->props( $unknown )['color']['value'] );
		$this->assertSame( array(), $unknown['unmapped'] );
	}

	public function test_unresolvable_global_color_without_a_local_value_is_unmapped(): void {
		$result = $this->map( 'heading', array( '__globals__' => array( 'title_color' => 'globals/colors?id=gone', 'text_color' => '' ) ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array( '__globals__.title_color' ), $result['unmapped'] );
	}

	public function test_global_typography_becomes_the_kit_literal_values(): void {
		$source = $this->source(
			array(),
			array(
				'primary' => array(
					'_id'                          => 'primary',
					'title'                        => 'Primary',
					'typography_typography'        => 'custom',
					'typography_font_family'       => 'Inter',
					'typography_font_weight'       => '600',
					'typography_font_size'         => array( 'unit' => 'px', 'size' => 20 ),
					'typography_font_size_mobile'  => array( 'unit' => 'px', 'size' => 16 ),
				),
			)
		);
		$result = $this->map(
			'heading',
			array(
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto',
				'__globals__'            => array( 'typography_typography' => 'globals/typography?id=primary' ),
			),
			$source
		);

		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				'font-family' => $this->str( 'Inter', 'font-family' ),
				'font-size'   => $this->sz( 20, 'px' ),
				'font-weight' => $this->str( '600' ),
			),
			$this->props( $result )
		);
		$this->assertSame( array( 'font-size' => $this->sz( 16, 'px' ) ), $this->props( $result, 'mobile' ) );
	}

	public function test_unknown_global_typography_without_custom_values_is_unmapped(): void {
		$result = $this->map( 'heading', array( '__globals__' => array( 'typography_typography' => 'globals/typography?id=gone' ) ) );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array( '__globals__.typography_typography' ), $result['unmapped'] );
	}

	public function test_invalid_css_values_go_to_unmapped(): void {
		$result = $this->map(
			'heading',
			array(
				'title_color'            => 'red; } body { display:none',
				'typography_typography'  => 'custom',
				'typography_font_family' => 'Roboto" , url(x)',
				'typography_font_weight' => '700',
				'border_border'          => 'solid',
				'border_color'           => 'url(https://example.com/x)',
			)
		);

		$this->assertSame(
			array(
				'font-weight'  => $this->str( '700' ),
				'border-style' => $this->str( 'solid' ),
			),
			$this->props( $result )
		);
		$this->assertEqualsCanonicalizing( array( 'title_color', 'typography_font_family', 'border_color' ), $result['unmapped'] );
	}

	public function test_invalid_kit_literal_does_not_pass_through(): void {
		$source = $this->source( array( 'evil' => array( 'title' => 'Evil', 'color' => 'red;}' ) ) );
		$result = $this->map( 'heading', array( '__globals__' => array( 'title_color' => 'globals/colors?id=evil' ) ), $source );

		$this->assertSame( array(), $result['styles'] );
		$this->assertSame( array( '__globals__.title_color' ), $result['unmapped'] );
	}

	public function test_untranslated_style_settings_are_listed_once_and_content_is_not(): void {
		$result = $this->map(
			'button',
			array(
				'size'                         => 'lg',
				'text'                         => 'Hola',
				'button_type'                  => 'info',
				'_title'                       => 'Mi botón',
				'button_background_hover_color' => '#000',
				'_box_shadow_box_shadow'       => array( 'horizontal' => 1 ),
				'_box_shadow_box_shadow_type'  => 'yes',
				'_z_index'                     => '5',
				'_css_classes'                 => 'foo',
				'hover_animation'              => 'grow',
				'_margin_laptop'               => $this->dims( 1, 1, 1, 1, 'px', true ),
				'__dynamic__'                  => array( 'text' => '[elementor-tag]' ),
				'button_text_color'            => '#fff',
				'empty_color'                  => '',
			)
		);

		$this->assertSame( array( 'color' => $this->str( '#fff', 'color' ) ), $this->props( $result ) );
		$this->assertEqualsCanonicalizing(
			array( 'size', 'button_type', 'button_background_hover_color', '_box_shadow_box_shadow', '_box_shadow_box_shadow_type', '_z_index', '_css_classes', 'hover_animation', '_margin_laptop' ),
			$result['unmapped']
		);
	}

	public function test_content_keys_are_never_listed(): void {
		$result = $this->map(
			'text-editor',
			array(
				'editor'      => '<p>Hola</p>',
				'title'       => 'x',
				'link'        => array( 'url' => 'https://example.com' ),
				'selected_icon' => array( 'value' => 'fas fa-star' ),
				'image'       => array( 'id' => 3 ),
				'_title'      => 'Texto',
			)
		);

		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_output_is_accepted_by_the_validator_as_a_local_style(): void {
		$source = $this->source( array( 'primary' => array( 'title' => 'Primary', 'color' => '#6EC1E4' ) ), array(), array( array( 'id' => 'e-gv-1', 'label' => 'primary' ) ) );
		$result = $this->map(
			'heading',
			array(
				'typography_typography'       => 'custom',
				'typography_font_family'      => 'Roboto',
				'typography_font_size'        => array( 'unit' => 'px', 'size' => 24 ),
				'typography_font_size_tablet' => array( 'unit' => 'px', 'size' => 20 ),
				'typography_font_weight'      => '700',
				'typography_line_height'      => array( 'unit' => 'em', 'size' => 1.2 ),
				'typography_letter_spacing'   => array( 'unit' => 'px', 'size' => 1 ),
				'typography_text_transform'   => 'capitalize',
				'align'                       => 'center',
				'__globals__'                 => array( 'title_color' => 'globals/colors?id=primary' ),
				'_background_background'      => 'classic',
				'_background_color'           => '#fafafa',
				'_margin'                     => $this->dims( 1, 2, 3, 4, 'px' ),
				'_padding_mobile'             => $this->dims( 1, 1, 1, 1, 'px', true ),
				'_border_border'              => 'solid',
				'_border_width'               => $this->dims( 1, 1, 1, 1, 'px', true ),
				'_border_color'               => '#000',
				'_border_radius'              => $this->dims( 3, 3, 3, 3, 'px', true ),
			),
			$source
		);

		$this->assertSame( array(), $result['unmapped'] );

		$tree = array(
			array(
				'type'     => 'e-heading',
				'settings' => array(
					'title'   => array( '$$type' => 'escaped-html', 'value' => 'Hola' ),
					'classes' => array( '$$type' => 'classes', 'value' => array( 'e-x-1' ) ),
				),
				'styles'   => array(
					'e-x-1' => array(
						'id'       => 'e-x-1',
						'type'     => 'class',
						'label'    => 'local',
						'variants' => $result['styles'],
					),
				),
				'elements' => array(),
			),
		);

		$wrapped = array(
			array(
				'type'     => 'e-flexbox',
				'settings' => array(),
				'elements' => $tree,
			),
		);
		$this->assertTrue( $this->validator()->validate( $wrapped ) );
	}

	public function test_migrator_with_the_real_mapper_produces_a_valid_atomic_page(): void {
		$source   = $this->source( array( 'primary' => array( 'title' => 'Primary', 'color' => '#6EC1E4' ) ) );
		$n        = 0;
		$migrator = new ElementorV3Migrator(
			new ElementorV3StyleMapper( $source ),
			static function () use ( &$n ): string {
				return sprintf( 'n%06d', ++$n );
			}
		);
		$tree     = array(
			array(
				'id'       => 's1',
				'elType'   => 'section',
				'settings' => array(
					'background_background' => 'classic',
					'background_color'      => '#f5f5f5',
					'padding'               => $this->dims( 20, 20, 20, 20, 'px', true ),
				),
				'elements' => array(
					array(
						'id'       => 'c1',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'h1',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array(
									'title'                       => 'Hola',
									'align'                       => 'center',
									'typography_typography'       => 'custom',
									'typography_font_size'        => array( 'unit' => 'px', 'size' => 30 ),
									'typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 20 ),
									'__globals__'                 => array( 'title_color' => 'globals/colors?id=primary' ),
									'_box_shadow_box_shadow_type' => 'yes',
								),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);

		$result = $migrator->migrate( $tree );

		$this->assertSame( array(), $result['kept_v3'] );
		$this->assertSame( array( array( 'id' => 'n000005', 'keys' => array( '_box_shadow_box_shadow_type' ) ) ), $result['unmapped'] );

		$heading = $result['tree'][0]['elements'][0]['elements'][0];
		$this->assertSame( 'e-heading', $heading['widgetType'] );
		$variants = array_values( $heading['styles'] )[0]['variants'];
		$this->assertSame( array( 'desktop', 'mobile' ), array_map( static fn( array $v ): string => $v['meta']['breakpoint'], $variants ) );
		$this->assertSame( '#6EC1E4', $variants[0]['props']['color']['value'] );

		$this->assertTrue( $this->validator()->validate( $this->to_client_format( $result['tree'] ) ) );
	}

	private function validator(): ElementorTreeValidator {
		$box = array( 'classes', 'tag', 'link', 'attributes', '_cssid' );

		return new ElementorTreeValidator(
			array(
				'e-flexbox'   => $box,
				'e-div-block' => $box,
				'e-heading'   => array( 'classes', 'tag', 'title', 'link', 'attributes', '_cssid' ),
			),
			array(),
			static fn( int $id ) => true,
			array(
				'font-family',
				'font-size',
				'font-weight',
				'line-height',
				'letter-spacing',
				'text-transform',
				'text-align',
				'color',
				'background',
				'margin',
				'padding',
				'border-style',
				'border-width',
				'border-color',
				'border-radius',
				'width',
				'flex-direction',
			)
		);
	}

	/**
	 * Formato guardado → formato del cliente que valida ElementorTreeValidator.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodos atómicos.
	 * @return array<int, array<string, mixed>>
	 */
	private function to_client_format( array $nodes ): array {
		$out = array();

		foreach ( $nodes as $node ) {
			$out[] = array(
				'type'     => $node['widgetType'] ?? $node['elType'],
				'settings' => $node['settings'],
				'styles'   => $node['styles'],
				'elements' => $this->to_client_format( $node['elements'] ),
			);
		}

		return $out;
	}
}
