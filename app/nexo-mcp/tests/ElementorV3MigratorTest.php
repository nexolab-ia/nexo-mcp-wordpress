<?php
/**
 * Migración de un árbol Elementor 3 a elementos atómicos (clase pura, sin Elementor).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use NexoDev\NexoMCP\Builders\ElementorV3Migrator;
use NexoDev\NexoMCP\Tests\Fakes\FakeV3StyleMapper;
use PHPUnit\Framework\TestCase;

/**
 * Cada regla de S7/S8 de `odd/tasks/migrar-v3-v4.md` con sus casos.
 */
final class ElementorV3MigratorTest extends TestCase {

	private FakeV3StyleMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new FakeV3StyleMapper();
	}

	/**
	 * Migrador con ids deterministas: n000001, n000002…
	 */
	private function migrator(): ElementorV3Migrator {
		$n = 0;

		return new ElementorV3Migrator(
			$this->mapper,
			static function () use ( &$n ): string {
				return sprintf( 'n%06d', ++$n );
			}
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Árbol.
	 * @return array<string, mixed>
	 */
	private function run_migration( array $tree ): array {
		return $this->migrator()->migrate( $tree );
	}

	/**
	 * @param array<string, mixed> $settings Ajustes.
	 * @return array<string, mixed>
	 */
	private function widget( string $id, string $type, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $children Hijos.
	 * @param array<string, mixed>             $settings Ajustes.
	 * @return array<string, mixed>
	 */
	private function el( string $el_type, string $id, array $children = array(), array $settings = array() ): array {
		return array(
			'id'       => $id,
			'elType'   => $el_type,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	/**
	 * Árbol mínimo: sección › columna › widget; devuelve el widget convertido.
	 *
	 * @param array<string, mixed> $widget Widget v3.
	 * @return array<string, mixed>
	 */
	private function result_with( array $widget ): array {
		return $this->run_migration( array( $this->el( 'section', 's1', array( $this->el( 'column', 'c1', array( $widget ) ) ) ) ) );
	}

	/**
	 * Nodos hoja del primer contenedor interior.
	 *
	 * @param array<string, mixed> $result Resultado.
	 * @return array<int, array<string, mixed>>
	 */
	private function leaves( array $result ): array {
		return $result['tree'][0]['elements'][0]['elements'];
	}

	private function str( string $value ): array {
		return array(
			'$$type' => 'string',
			'value'  => $value,
		);
	}

	private function sz( $size, string $unit ): array {
		return array(
			'$$type' => 'size',
			'value'  => array(
				'size' => $size,
				'unit' => $unit,
			),
		);
	}

	/**
	 * Variante de estilo del mapper.
	 *
	 * @param array<string, mixed> $props Props.
	 * @return array<string, mixed>
	 */
	private function variant( string $breakpoint, array $props ): array {
		return array(
			'meta'  => array(
				'breakpoint' => $breakpoint,
				'state'      => null,
			),
			'props' => $props,
		);
	}

	// ---------------------------------------------------------------- contenedores

	public function test_section_becomes_a_row_flexbox_with_a_new_id(): void {
		$result = $this->run_migration( array( $this->el( 'section', 's1', array( $this->el( 'column', 'c1' ) ) ) ) );
		$node   = $result['tree'][0];

		$this->assertSame( 'n000001', $node['id'] );
		$this->assertSame( 'e-flexbox', $node['elType'] );
		$this->assertArrayNotHasKey( 'widgetType', $node );
		$this->assertSame( 'n000003', $node['elements'][0]['id'] );
		$this->assertSame( 'e-div-block', $node['elements'][0]['elType'] );
		$this->assertSame( 2, $result['migrated'] );
		$this->assertSame( array(), $result['kept_v3'] );
		$this->assertSame( array(), $result['unmapped'] );

		$style_id = 'e-n000001-n000002';
		$this->assertSame( array( $style_id ), array_keys( $node['styles'] ) );
		$this->assertSame( $this->str( 'row' ), $node['styles'][ $style_id ]['variants'][0]['props']['flex-direction'] );
		$this->assertSame(
			array(
				'breakpoint' => 'desktop',
				'state'      => null,
			),
			$node['styles'][ $style_id ]['variants'][0]['meta']
		);
	}

	public function test_section_html_tag_becomes_tag_only_when_in_the_enum(): void {
		$ok  = $this->run_migration( array( $this->el( 'section', 's1', array(), array( 'html_tag' => 'header' ) ) ) );
		$bad = $this->run_migration( array( $this->el( 'section', 's1', array(), array( 'html_tag' => 'a' ) ) ) );

		$this->assertSame( $this->str( 'header' ), $ok['tree'][0]['settings']['tag'] );
		$this->assertArrayNotHasKey( 'tag', $bad['tree'][0]['settings'] );
	}

	public function test_column_becomes_div_block_with_percent_width_from_inline_size(): void {
		$result = $this->run_migration(
			array(
				$this->el(
					'section',
					's1',
					array( $this->el( 'column', 'c1', array(), array( '_inline_size' => 33.33, '_column_size' => 100 ) ) )
				),
			)
		);
		$column = $result['tree'][0]['elements'][0];
		$style  = reset( $column['styles'] );

		$this->assertSame( 'e-div-block', $column['elType'] );
		$this->assertSame( $this->sz( 33.33, '%' ), $style['variants'][0]['props']['width'] );
		$this->assertSame( array( key( $column['styles'] ) ), $column['settings']['classes']['value'] );
		$this->assertSame( 'classes', $column['settings']['classes']['$$type'] );
	}

	public function test_column_width_falls_back_to_column_size_and_has_responsive_variants(): void {
		$result = $this->run_migration(
			array(
				$this->el(
					'section',
					's1',
					array(
						$this->el(
							'column',
							'c1',
							array(),
							array(
								'_inline_size'        => null,
								'_column_size'        => '50',
								'_inline_size_tablet' => 100,
								'_inline_size_mobile' => '',
							)
						),
					)
				),
			)
		);
		$style    = reset( $result['tree'][0]['elements'][0]['styles'] );
		$variants = $style['variants'];

		$this->assertCount( 2, $variants );
		$this->assertSame( 'desktop', $variants[0]['meta']['breakpoint'] );
		$this->assertSame( $this->sz( 50, '%' ), $variants[0]['props']['width'] );
		$this->assertSame( 'tablet', $variants[1]['meta']['breakpoint'] );
		$this->assertSame( $this->sz( 100, '%' ), $variants[1]['props']['width'] );
	}

	public function test_flex_container_maps_its_flex_settings(): void {
		$result = $this->run_migration(
			array(
				$this->el(
					'container',
					'k1',
					array(),
					array(
						'container_type'       => 'flex',
						'flex_direction'       => 'row',
						'flex_justify_content' => 'space-between',
						'flex_align_items'     => 'center',
						'flex_wrap'            => 'wrap',
						'flex_gap'             => array(
							'unit'     => 'px',
							'column'   => '20',
							'row'      => '10',
							'isLinked' => false,
						),
						'html_tag'             => 'section',
					)
				),
			)
		);
		$node  = $result['tree'][0];
		$props = reset( $node['styles'] )['variants'][0]['props'];

		$this->assertSame( 'e-flexbox', $node['elType'] );
		$this->assertSame( $this->str( 'section' ), $node['settings']['tag'] );
		$this->assertSame( $this->str( 'row' ), $props['flex-direction'] );
		$this->assertSame( $this->str( 'space-between' ), $props['justify-content'] );
		$this->assertSame( $this->str( 'center' ), $props['align-items'] );
		$this->assertSame( $this->str( 'wrap' ), $props['flex-wrap'] );
		$this->assertSame(
			array(
				'$$type' => 'layout-direction',
				'value'  => array(
					'column' => $this->sz( 20, 'px' ),
					'row'    => $this->sz( 10, 'px' ),
				),
			),
			$props['gap']
		);
		$this->assertSame( 1, $result['migrated'] );
	}

	public function test_flex_container_without_direction_is_a_column_and_unknown_values_are_reported(): void {
		$result = $this->run_migration(
			array( $this->el( 'container', 'k1', array(), array( 'flex_justify_content' => 'weird', 'flex_align_items_tablet' => 'center' ) ) )
		);
		$variants = reset( $result['tree'][0]['styles'] )['variants'];

		$this->assertSame( $this->str( 'column' ), $variants[0]['props']['flex-direction'] );
		$this->assertArrayNotHasKey( 'justify-content', $variants[0]['props'] );
		$this->assertSame( 'tablet', $variants[1]['meta']['breakpoint'] );
		$this->assertSame( $this->str( 'center' ), $variants[1]['props']['align-items'] );
		$this->assertSame( array( array( 'id' => 'n000001', 'keys' => array( 'flex_justify_content' ) ) ), $result['unmapped'] );
	}

	public function test_grid_container_is_kept_v3_and_its_children_are_migrated(): void {
		$grid   = $this->el(
			'container',
			'g1',
			array( $this->widget( 'h1', 'heading', array( 'title' => 'Hola' ) ) ),
			array( 'container_type' => 'grid' )
		);
		$result = $this->run_migration( array( $grid ) );
		$node   = $result['tree'][0];

		$this->assertSame( 'g1', $node['id'] );
		$this->assertSame( 'container', $node['elType'] );
		$this->assertSame( array( 'container_type' => 'grid' ), $node['settings'] );
		$this->assertSame( 'widget', $node['elements'][0]['elType'] );
		$this->assertSame( 'e-heading', $node['elements'][0]['widgetType'] );
		$this->assertSame( 1, $result['migrated'] );
		$this->assertSame(
			array(
				array(
					'id'     => 'g1',
					'type'   => 'container',
					'reason' => 'El contenedor grid no tiene equivalente atómico; se conserva y sus hijos se migran.',
				),
			),
			$result['kept_v3']
		);
	}

	public function test_atomic_nodes_stay_the_same_and_their_v3_children_are_migrated(): void {
		$atomic = array(
			'id'         => 'a1',
			'elType'     => 'widget',
			'widgetType' => 'e-heading',
			'settings'   => array( 'title' => $this->str( 'x' ) ),
			'styles'     => array(),
			'elements'   => array(),
		);
		$box    = array(
			'id'       => 'b1',
			'elType'   => 'e-flexbox',
			'settings' => array(),
			'styles'   => array(),
			'elements' => array( $atomic, $this->widget( 'h2', 'heading', array( 'title' => 'v3' ) ) ),
		);
		$result = $this->run_migration( array( $box ) );
		$out    = $result['tree'][0];

		$this->assertSame( 'b1', $out['id'] );
		$this->assertSame( 'e-flexbox', $out['elType'] );
		$this->assertSame( $atomic, $out['elements'][0] );
		$this->assertSame( 'e-heading', $out['elements'][1]['widgetType'] );
		$this->assertNotSame( 'h2', $out['elements'][1]['id'] );
		$this->assertSame( 1, $result['migrated'] );

		$only = $this->run_migration( array( $box['elements'][0] ) );
		$this->assertSame( array( $atomic ), $only['tree'] );
		$this->assertSame( 0, $only['migrated'] );
	}

	// ---------------------------------------------------------------- widgets

	public function test_heading_becomes_e_heading_with_title_tag_and_link(): void {
		$result = $this->result_with(
			$this->widget(
				'h1',
				'heading',
				array(
					'title'       => 'Hola <span>mundo</span>',
					'header_size' => 'h3',
					'link'        => array(
						'url'         => 'https://example.com/a',
						'is_external' => 'on',
					),
				)
			)
		);
		$node = $this->leaves( $result )[0];

		$this->assertSame( 'widget', $node['elType'] );
		$this->assertSame( 'e-heading', $node['widgetType'] );
		$this->assertNotSame( 'h1', $node['id'] );
		$this->assertSame( array(), $node['elements'] );
		$this->assertSame( 'escaped-html', $node['settings']['title']['$$type'] );
		$this->assertSame( 'Hola <span>mundo</span>', $node['settings']['title']['value'] );
		$this->assertSame( $this->str( 'h3' ), $node['settings']['tag'] );
		$this->assertSame(
			array(
				'$$type' => 'link',
				'value'  => array(
					'destination'   => array(
						'$$type' => 'url',
						'value'  => 'https://example.com/a',
					),
					'isTargetBlank' => array(
						'$$type' => 'boolean',
						'value'  => true,
					),
				),
			),
			$node['settings']['link']
		);
		$this->assertSame( 3, $result['migrated'] );
	}

	public function test_heading_defaults_to_h2_and_accepts_span(): void {
		$default = $this->leaves( $this->result_with( $this->widget( 'h1', 'heading', array( 'title' => 'A' ) ) ) )[0];
		$span    = $this->leaves( $this->result_with( $this->widget( 'h1', 'heading', array( 'title' => 'A', 'header_size' => 'span' ) ) ) )[0];

		$this->assertSame( $this->str( 'h2' ), $default['settings']['tag'] );
		$this->assertSame( $this->str( 'span' ), $span['settings']['tag'] );
		$this->assertArrayNotHasKey( 'link', $default['settings'] );
	}

	public function test_heading_with_div_or_p_tag_is_kept_v3(): void {
		foreach ( array( 'div', 'p' ) as $tag ) {
			$widget = $this->widget( 'h1', 'heading', array( 'title' => 'A', 'header_size' => $tag ) );
			$result = $this->result_with( $widget );

			$this->assertSame( $widget, $this->leaves( $result )[0] );
			$this->assertSame(
				array(
					array(
						'id'     => 'h1',
						'type'   => 'heading',
						'reason' => sprintf( 'El encabezado usa la etiqueta «%s»; e-heading sólo admite h1-h6 y span.', $tag ),
					),
				),
				$result['kept_v3']
			);
			$this->assertSame( 2, $result['migrated'] );
		}
	}

	public function test_heading_with_formatted_title_or_unsafe_link_is_kept_v3(): void {
		$bold   = $this->widget( 'h1', 'heading', array( 'title' => 'A <strong>b</strong>' ) );
		$result = $this->result_with( $bold );

		$this->assertSame( $bold, $this->leaves( $result )[0] );
		$this->assertSame( 'El texto tiene formato (etiquetas distintas de a y span) que el elemento atómico no conserva.', $result['kept_v3'][0]['reason'] );

		$bad    = $this->widget( 'h2', 'heading', array( 'title' => 'A', 'link' => array( 'url' => 'javascript:alert(1)' ) ) );
		$result = $this->result_with( $bad );

		$this->assertSame( $bad, $this->leaves( $result )[0] );
		$this->assertSame( 'heading', $result['kept_v3'][0]['type'] );
		$this->assertStringStartsWith( 'El enlace no es admisible en Elementor 4: ', $result['kept_v3'][0]['reason'] );
	}

	public function test_text_editor_with_plain_text_becomes_one_paragraph(): void {
		$result = $this->result_with( $this->widget( 't1', 'text-editor', array( 'editor' => 'Texto simple' ) ) );
		$leaves = $this->leaves( $result );

		$this->assertCount( 1, $leaves );
		$this->assertSame( 'e-paragraph', $leaves[0]['widgetType'] );
		$this->assertSame( 'escaped-html', $leaves[0]['settings']['paragraph']['$$type'] );
		$this->assertSame( 'Texto simple', $leaves[0]['settings']['paragraph']['value'] );
		$this->assertSame( $this->str( 'p' ), $leaves[0]['settings']['tag'] );
		$this->assertSame( array(), $result['kept_v3'] );
	}

	public function test_text_editor_with_several_paragraphs_gives_one_node_per_paragraph(): void {
		$html   = "<p>Uno <a href=\"https://example.com\">enlace</a></p>\n<p>Dos <span>span</span></p>\n\n<p></p>";
		$result = $this->result_with( $this->widget( 't1', 'text-editor', array( 'editor' => $html ) ) );
		$leaves = $this->leaves( $result );

		$this->assertCount( 2, $leaves );
		$this->assertSame( 'Uno <a href="https://example.com">enlace</a>', $leaves[0]['settings']['paragraph']['value'] );
		$this->assertSame( 'Dos <span>span</span>', $leaves[1]['settings']['paragraph']['value'] );
		$this->assertNotSame( $leaves[0]['id'], $leaves[1]['id'] );
		$this->assertSame( 3, $result['migrated'] );
		$this->assertSame( array(), $result['kept_v3'] );
	}

	public function test_text_editor_plain_text_splits_on_blank_lines(): void {
		$result = $this->result_with( $this->widget( 't1', 'text-editor', array( 'editor' => "Uno\n\nDos" ) ) );

		$this->assertSame( array( 'Uno', 'Dos' ), array_map( static fn( array $n ): string => $n['settings']['paragraph']['value'], $this->leaves( $result ) ) );
	}

	public function test_text_editor_with_formatting_is_kept_v3_with_the_reason(): void {
		$reason = 'El texto tiene formato (negrita, listas, saltos de línea u otras etiquetas) que e-paragraph no conserva; sólo se migran párrafos, enlaces y spans.';
		$cases  = array(
			'<p>Hola <strong>mundo</strong></p>',
			'<ul><li>Uno</li></ul>',
			'<p>Uno<br>Dos</p>',
			'<h2>Título</h2>',
			'<p style="text-align:center">Centrado</p>',
			"Línea uno\nLínea dos",
		);

		foreach ( $cases as $html ) {
			$widget = $this->widget( 't1', 'text-editor', array( 'editor' => $html, 'text_color' => '#111' ) );
			$result = $this->result_with( $widget );

			$this->assertSame( $widget, $this->leaves( $result )[0], $html );
			$this->assertSame(
				array(
					array(
						'id'     => 't1',
						'type'   => 'text-editor',
						'reason' => $reason,
					),
				),
				$result['kept_v3'],
				$html
			);
		}
	}

	public function test_empty_text_editor_is_kept_v3(): void {
		$widget = $this->widget( 't1', 'text-editor', array( 'editor' => '  ' ) );
		$result = $this->result_with( $widget );

		$this->assertSame( $widget, $this->leaves( $result )[0] );
		$this->assertSame( 'El editor de texto está vacío.', $result['kept_v3'][0]['reason'] );
	}

	public function test_button_becomes_e_button_with_text_and_link(): void {
		$result = $this->result_with(
			$this->widget( 'b1', 'button', array( 'text' => 'Comprar', 'link' => array( 'url' => '/tienda' ), 'size' => 'lg' ) )
		);
		$node = $this->leaves( $result )[0];

		$this->assertSame( 'e-button', $node['widgetType'] );
		$this->assertSame( 'Comprar', $node['settings']['text']['value'] );
		$this->assertSame( 'escaped-html', $node['settings']['text']['$$type'] );
		$this->assertSame( '/tienda', $node['settings']['link']['value']['destination']['value'] );
		$this->assertArrayNotHasKey( 'isTargetBlank', $node['settings']['link']['value'] );
		$this->assertSame( array( 'size' => 'lg' ), $this->mapper->calls[ count( $this->mapper->calls ) - 1 ][1] );
	}

	public function test_button_with_icon_is_kept_v3(): void {
		$widget = $this->widget(
			'b1',
			'button',
			array(
				'text'          => 'Comprar',
				'selected_icon' => array(
					'value'   => 'fas fa-star',
					'library' => 'fa-solid',
				),
			)
		);
		$result = $this->result_with( $widget );

		$this->assertSame( $widget, $this->leaves( $result )[0] );
		$this->assertSame( 'El botón tiene icono; e-button no lo admite.', $result['kept_v3'][0]['reason'] );
		$this->assertSame( 'button', $result['kept_v3'][0]['type'] );

		$empty = $this->widget( 'b2', 'button', array( 'text' => 'X', 'selected_icon' => array( 'value' => '', 'library' => '' ) ) );
		$this->assertSame( 'e-button', $this->leaves( $this->result_with( $empty ) )[0]['widgetType'] );
	}

	public function test_image_becomes_e_image_with_attachment_size_and_links(): void {
		$base = array(
			'image'      => array(
				'id'  => 7,
				'url' => 'https://example.com/wp-content/uploads/a.jpg',
			),
			'image_size' => 'medium',
		);
		$node = $this->leaves( $this->result_with( $this->widget( 'i1', 'image', $base ) ) )[0];

		$this->assertSame( 'e-image', $node['widgetType'] );
		$this->assertSame(
			array(
				'$$type' => 'image',
				'value'  => array(
					'src'  => array(
						'$$type' => 'image-src',
						'value'  => array(
							'id'  => array(
								'$$type' => 'image-attachment-id',
								'value'  => 7,
							),
							'url' => null,
						),
					),
					'size' => $this->str( 'medium' ),
				),
			),
			$node['settings']['image']
		);
		$this->assertArrayNotHasKey( 'link', $node['settings'] );

		$custom = $this->leaves( $this->result_with( $this->widget( 'i1', 'image', $base + array( 'link_to' => 'custom', 'link' => array( 'url' => 'https://example.com/x' ) ) ) ) )[0];
		$this->assertSame( 'https://example.com/x', $custom['settings']['link']['value']['destination']['value'] );

		$file = $this->leaves( $this->result_with( $this->widget( 'i1', 'image', $base + array( 'link_to' => 'file' ) ) ) )[0];
		$this->assertSame( 'https://example.com/wp-content/uploads/a.jpg', $file['settings']['link']['value']['destination']['value'] );
	}

	public function test_image_without_size_uses_the_v3_default_and_custom_size_is_reported(): void {
		$image   = array( 'id' => 7, 'url' => 'https://example.com/a.jpg' );
		$default = $this->leaves( $this->result_with( $this->widget( 'i1', 'image', array( 'image' => $image ) ) ) )[0];
		$custom  = $this->result_with( $this->widget( 'i1', 'image', array( 'image' => $image, 'image_size' => 'custom' ) ) );

		$this->assertSame( $this->str( 'large' ), $default['settings']['image']['value']['size'] );
		$this->assertSame( $this->str( 'full' ), $this->leaves( $custom )[0]['settings']['image']['value']['size'] );
		$this->assertSame( array( 'image_size' ), $custom['unmapped'][0]['keys'] );
	}

	public function test_image_with_caption_or_without_attachment_id_is_kept_v3(): void {
		$caption = $this->widget( 'i1', 'image', array( 'image' => array( 'id' => 7, 'url' => 'https://e.com/a.jpg' ), 'caption_source' => 'custom', 'caption' => 'Pie' ) );
		$result  = $this->result_with( $caption );

		$this->assertSame( $caption, $this->leaves( $result )[0] );
		$this->assertSame( 'La imagen tiene pie de foto; e-image no lo admite.', $result['kept_v3'][0]['reason'] );

		$noid   = $this->widget( 'i2', 'image', array( 'image' => array( 'id' => '', 'url' => 'https://e.com/placeholder.png' ) ) );
		$result = $this->result_with( $noid );

		$this->assertSame( $noid, $this->leaves( $result )[0] );
		$this->assertSame( 'La imagen no viene de la biblioteca de medios (sin identificador); e-image lo exige.', $result['kept_v3'][0]['reason'] );

		$none = $this->widget( 'i3', 'image', array( 'image' => array( 'id' => 7, 'url' => 'https://e.com/a.jpg' ), 'caption_source' => 'none' ) );
		$this->assertSame( 'e-image', $this->leaves( $this->result_with( $none ) )[0]['widgetType'] );
	}

	public function test_divider_becomes_e_divider_with_the_line_in_its_style(): void {
		$result = $this->result_with(
			$this->widget(
				'd1',
				'divider',
				array(
					'style'  => 'solid',
					'weight' => array( 'unit' => 'px', 'size' => 3 ),
					'color'  => '#ff0000',
					'width'  => array( 'unit' => '%', 'size' => 50 ),
				)
			)
		);
		$node  = $this->leaves( $result )[0];
		$props = reset( $node['styles'] )['variants'][0]['props'];

		$this->assertSame( 'e-divider', $node['widgetType'] );
		$this->assertSame( array(), array_diff( array_keys( $node['settings'] ), array( 'classes' ) ) );
		$this->assertSame( $this->sz( 3, 'px' ), $props['height'] );
		$this->assertSame( $this->sz( 50, '%' ), $props['width'] );
		$this->assertSame(
			array(
				'$$type' => 'background',
				'value'  => array(
					'color' => array(
						'$$type' => 'color',
						'value'  => '#ff0000',
					),
				),
			),
			$props['background']
		);
		$this->assertSame( array(), $result['unmapped'] );
	}

	public function test_divider_reports_a_non_solid_style_and_a_global_color(): void {
		$result = $this->result_with(
			$this->widget( 'd1', 'divider', array( 'style' => 'dashed', '__globals__' => array( 'color' => 'globals/colors?id=secondary' ) ) )
		);

		$this->assertSame( array( '__globals__.color', 'style' ), $result['unmapped'][0]['keys'] );
	}

	public function test_divider_with_text_or_icon_is_kept_v3(): void {
		foreach ( array( 'line_text', 'line_icon' ) as $look ) {
			$widget = $this->widget( 'd1', 'divider', array( 'look' => $look, 'text' => 'Divisor' ) );
			$result = $this->result_with( $widget );

			$this->assertSame( $widget, $this->leaves( $result )[0] );
			$this->assertSame(
				array(
					array(
						'id'     => 'd1',
						'type'   => 'divider',
						'reason' => 'El divisor lleva texto o icono; e-divider sólo admite la línea.',
					),
				),
				$result['kept_v3']
			);
		}
	}

	public function test_any_other_widget_is_kept_v3_unchanged(): void {
		$widgets = array(
			$this->widget( 'w1', 'spacer', array( 'space' => array( 'unit' => 'px', 'size' => 50 ) ) ),
			$this->widget( 'w2', 'icon-box', array( 'title_text' => 'X' ) ),
			$this->widget( 'w3', 'testimonial', array() ),
		);
		$result  = $this->result_with_many( $widgets );

		$this->assertSame( $widgets, $result['tree'][0]['elements'][0]['elements'] );
		$this->assertSame( 2, $result['migrated'] );
		$this->assertSame( array(), $result['unmapped'] );
		$this->assertSame(
			array(
				array(
					'id'     => 'w1',
					'type'   => 'spacer',
					'reason' => 'El widget «spacer» no tiene equivalente atómico.',
				),
				array(
					'id'     => 'w2',
					'type'   => 'icon-box',
					'reason' => 'El widget «icon-box» no tiene equivalente atómico.',
				),
				array(
					'id'     => 'w3',
					'type'   => 'testimonial',
					'reason' => 'El widget «testimonial» no tiene equivalente atómico.',
				),
			),
			$result['kept_v3']
		);
		$this->assertSame( array(), $this->mapper->calls === array() ? array() : array_filter( $this->mapper->calls, static fn( array $c ): bool => in_array( $c[0], array( 'spacer', 'icon-box', 'testimonial' ), true ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $widgets Widgets.
	 * @return array<string, mixed>
	 */
	private function result_with_many( array $widgets ): array {
		return $this->run_migration( array( $this->el( 'section', 's1', array( $this->el( 'column', 'c1', $widgets ) ) ) ) );
	}

	// ---------------------------------------------------------------- estilos y ids

	public function test_mapper_styles_become_a_local_style_referenced_from_classes(): void {
		$this->mapper->styles['heading'] = array( $this->variant( 'desktop', array( 'color' => array( '$$type' => 'color', 'value' => '#123456' ) ) ), $this->variant( 'mobile', array( 'font-size' => $this->sz( 18, 'px' ) ) ) );
		$node                            = $this->leaves( $this->result_with( $this->widget( 'h1', 'heading', array( 'title' => 'A', 'title_color' => '#123456' ) ) ) )[0];

		$this->assertSame( array( 'e-' . $node['id'] . '-' . substr( $node['id'], 0, 1 ) . sprintf( '%06d', (int) substr( $node['id'], 1 ) + 1 ) ), array_keys( $node['styles'] ) );

		$style_id = array_key_first( $node['styles'] );
		$style    = $node['styles'][ $style_id ];

		$this->assertSame( $style_id, $style['id'] );
		$this->assertSame( 'class', $style['type'] );
		$this->assertSame( 'local', $style['label'] );
		$this->assertCount( 2, $style['variants'] );
		$this->assertSame( 'mobile', $style['variants'][1]['meta']['breakpoint'] );
		$this->assertSame( array( '$$type' => 'color', 'value' => '#123456' ), $style['variants'][0]['props']['color'] );
		$this->assertSame( array( $style_id ), $node['settings']['classes']['value'] );
	}

	public function test_node_without_mapper_styles_has_no_style_and_no_classes(): void {
		$node = $this->leaves( $this->result_with( $this->widget( 'h1', 'heading', array( 'title' => 'A' ) ) ) )[0];

		$this->assertSame( array(), $node['styles'] );
		$this->assertArrayNotHasKey( 'classes', $node['settings'] );
	}

	public function test_structural_props_win_over_the_mapper_in_the_same_breakpoint(): void {
		$this->mapper->styles['column'] = array( $this->variant( 'desktop', array( 'width' => $this->sz( 1, 'px' ), 'padding' => $this->sz( 4, 'px' ) ) ) );
		$result                         = $this->run_migration( array( $this->el( 'section', 's1', array( $this->el( 'column', 'c1', array(), array( '_column_size' => 40 ) ) ) ) ) );
		$variants                       = reset( $result['tree'][0]['elements'][0]['styles'] )['variants'];

		$this->assertCount( 1, $variants );
		$this->assertSame( $this->sz( 40, '%' ), $variants[0]['props']['width'] );
		$this->assertSame( $this->sz( 4, 'px' ), $variants[0]['props']['padding'] );
	}

	public function test_mapper_receives_the_v3_type_and_the_settings_the_migrator_did_not_consume(): void {
		$this->run_migration(
			array(
				$this->el(
					'section',
					's1',
					array(
						$this->el(
							'column',
							'c1',
							array( $this->widget( 'h1', 'heading', array( 'title' => 'A', 'header_size' => 'h2', 'title_color' => '#fff', 'align' => 'center' ) ) ),
							array( '_column_size' => 50, 'background_color' => '#000' )
						),
					),
					array( 'html_tag' => 'div', 'padding' => array( 'top' => '1' ) )
				),
			)
		);

		$this->assertSame(
			array(
				array( 'section', array( 'padding' => array( 'top' => '1' ) ) ),
				array( 'column', array( 'background_color' => '#000' ) ),
				array( 'heading', array( 'title_color' => '#fff', 'align' => 'center' ) ),
			),
			$this->mapper->calls
		);
	}

	public function test_unmapped_keys_from_the_mapper_are_reported_per_new_node(): void {
		$this->mapper->unmapped['section'] = array( 'background_image' );
		$this->mapper->unmapped['heading'] = array( 'text_shadow_text_shadow', 'title_color' );
		$result                            = $this->result_with( $this->widget( 'h1', 'heading', array( 'title' => 'A', 'link' => array( 'url' => 'https://example.com', 'nofollow' => 'on' ) ) ) );
		$heading                           = $this->leaves( $result )[0];

		$this->assertSame(
			array(
				array( 'id' => $result['tree'][0]['id'], 'keys' => array( 'background_image' ) ),
				array( 'id' => $heading['id'], 'keys' => array( 'link_nofollow', 'text_shadow_text_shadow', 'title_color' ) ),
			),
			$result['unmapped']
		);
	}

	public function test_unmapped_of_a_split_text_editor_is_reported_once_on_the_first_paragraph(): void {
		$this->mapper->unmapped['text-editor'] = array( 'text_color' );
		$result                                = $this->result_with( $this->widget( 't1', 'text-editor', array( 'editor' => '<p>A</p><p>B</p>' ) ) );

		$this->assertSame( array( array( 'id' => $this->leaves( $result )[0]['id'], 'keys' => array( 'text_color' ) ) ), $result['unmapped'] );
		$this->assertCount( 2, $this->mapper->calls[ count( $this->mapper->calls ) - 1 ] === null ? array() : array_filter( $this->mapper->calls, static fn( array $c ): bool => 'text-editor' === $c[0] ) );
	}

	public function test_new_ids_are_unique_and_never_collide_with_the_input(): void {
		$tree = array(
			$this->el(
				'section',
				'n000001',
				array(
					$this->el(
						'column',
						'n000002',
						array(
							$this->widget( 'n000003', 'spacer' ),
							$this->widget( 'x1', 'text-editor', array( 'editor' => '<p>A</p><p>B</p>' ) ),
						)
					),
				)
			),
		);
		$result = $this->run_migration( $tree );
		$ids    = array();
		$walk   = static function ( array $nodes ) use ( &$walk, &$ids ): void {
			foreach ( $nodes as $node ) {
				$ids[] = $node['id'];
				$walk( $node['elements'] );
			}
		};
		$walk( $result['tree'] );

		$this->assertCount( 5, $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		$this->assertSame( 'n000003', $result['tree'][0]['elements'][0]['elements'][0]['id'] );
		$this->assertNotContains( 'n000001', array( $result['tree'][0]['id'], $result['tree'][0]['elements'][0]['id'] ) );
	}

	public function test_input_tree_is_not_modified(): void {
		$tree = array( $this->el( 'section', 's1', array( $this->el( 'column', 'c1', array( $this->widget( 'h1', 'heading', array( 'title' => 'A' ) ) ) ) ) ) );
		$copy = $tree;

		$this->run_migration( $tree );

		$this->assertSame( $copy, $tree );
	}

	public function test_an_empty_tree_migrates_nothing(): void {
		$this->assertSame(
			array(
				'tree'     => array(),
				'migrated' => 0,
				'kept_v3'  => array(),
				'unmapped' => array(),
			),
			$this->run_migration( array() )
		);
	}

	// ---------------------------------------------------------------- anidado y validador

	public function test_nesting_roots_hold_only_containers_and_columns_only_atomic_widgets(): void {
		$this->mapper->styles = array();
		$result               = $this->run_migration(
			array(
				$this->el(
					'section',
					's1',
					array(
						$this->el(
							'column',
							'c1',
							array(
								$this->widget( 'h1', 'heading', array( 'title' => 'A' ) ),
								$this->el( 'section', 's2', array( $this->el( 'column', 'c2', array( $this->widget( 'b1', 'button', array( 'text' => 'B' ) ) ) ) ) ),
							)
						),
					)
				),
				$this->el( 'container', 'k1', array( $this->widget( 'w1', 'spacer' ) ) ),
			)
		);

		foreach ( $result['tree'] as $root ) {
			$this->assertContains( $root['elType'], array( 'e-flexbox', 'e-div-block' ) );
		}

		$column = $result['tree'][0]['elements'][0];

		$this->assertSame( 'e-div-block', $column['elType'] );
		$this->assertSame( 'e-heading', $column['elements'][0]['widgetType'] );
		$this->assertSame( 'e-flexbox', $column['elements'][1]['elType'] );
		$this->assertSame( 'e-div-block', $column['elements'][1]['elements'][0]['elType'] );
		$this->assertSame( 'spacer', $result['tree'][1]['elements'][0]['widgetType'] );
	}

	public function test_a_fully_converted_tree_passes_the_tree_validator(): void {
		$this->mapper->styles['heading'] = array( $this->variant( 'desktop', array( 'color' => array( '$$type' => 'color', 'value' => '#123456' ) ) ) );
		$tree = array(
			$this->el(
				'section',
				's1',
				array(
					$this->el(
						'column',
						'c1',
						array(
							$this->widget( 'h1', 'heading', array( 'title' => 'Hola', 'header_size' => 'h2', 'link' => array( 'url' => 'https://example.com' ) ) ),
							$this->widget( 't1', 'text-editor', array( 'editor' => '<p>Uno</p><p>Dos <a href="https://example.com">x</a></p>' ) ),
							$this->widget( 'b1', 'button', array( 'text' => 'Ir', 'link' => array( 'url' => '/ir', 'is_external' => 'on' ) ) ),
							$this->widget( 'i1', 'image', array( 'image' => array( 'id' => 7, 'url' => 'https://example.com/a.jpg' ), 'link_to' => 'file' ) ),
							$this->widget( 'd1', 'divider', array( 'weight' => array( 'unit' => 'px', 'size' => 2 ), 'color' => '#000' ) ),
						),
						array( '_column_size' => 50 )
					),
					$this->el( 'column', 'c2', array(), array( '_column_size' => 50, '_inline_size_mobile' => 100 ) ),
				),
				array( 'html_tag' => 'section' )
			),
			$this->el( 'container', 'k1', array( $this->widget( 'h2', 'heading', array( 'title' => 'Flex' ) ) ), array( 'flex_direction' => 'row', 'flex_gap' => array( 'unit' => 'px', 'column' => 8, 'row' => 8 ) ) ),
		);
		$result = $this->run_migration( $tree );

		$this->assertSame( array(), $result['kept_v3'] );
		$this->assertSame( 10, $result['migrated'] );

		$box   = array( 'classes', 'tag', 'link', 'attributes', '_cssid' );
		$check = new ElementorTreeValidator(
			array(
				'e-flexbox'   => $box,
				'e-div-block' => $box,
				'e-heading'   => array( 'classes', 'tag', 'title', 'link', 'attributes', '_cssid' ),
				'e-paragraph' => array( 'classes', 'paragraph', 'tag', 'link', 'attributes', '_cssid' ),
				'e-button'    => array( 'classes', 'text', 'link', 'tag', 'attributes', '_cssid' ),
				'e-image'     => array( 'classes', 'image', 'link', 'attributes', '_cssid' ),
				'e-divider'   => array( 'classes', 'attributes', '_cssid' ),
			),
			array(),
			static fn( int $id ) => true,
			array( 'width', 'flex-direction', 'justify-content', 'align-items', 'flex-wrap', 'gap', 'color', 'height', 'background' )
		);

		$this->assertTrue( $check->validate( $this->to_client_format( $result['tree'] ) ) );
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
