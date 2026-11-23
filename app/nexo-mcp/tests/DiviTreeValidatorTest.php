<?php
/**
 * Validación y saneado del árbol de módulos de Divi 5 que manda el cliente.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace {
	// El arranque de las pruebas no trae `wp_kses`: réplica mínima (etiquetas y atributos de la
	// lista, sin el contenido de script/style) sólo si el núcleo no está cargado.
	if ( ! function_exists( 'wp_kses' ) ) {
		/**
		 * @param string                              $content           HTML.
		 * @param array<string, array<string, mixed>> $allowed_html      Etiquetas y atributos admitidos.
		 * @param array<int, string>                  $allowed_protocols Esquemas admitidos.
		 */
		function wp_kses( $content, $allowed_html, $allowed_protocols = array() ): string {
			$content = (string) preg_replace( '#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $content );

			return (string) preg_replace_callback(
				'#<(/?)([a-z0-9]+)([^>]*)>#i',
				static function ( array $m ) use ( $allowed_html, $allowed_protocols ): string {
					$tag = strtolower( $m[2] );

					if ( ! isset( $allowed_html[ $tag ] ) ) {
						return '';
					}

					if ( '/' === $m[1] ) {
						return '</' . $tag . '>';
					}

					$out = '';

					preg_match_all( '#([a-z-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $m[3], $attrs, PREG_SET_ORDER );

					foreach ( $attrs as $attr ) {
						$name  = strtolower( $attr[1] );
						$value = '' !== ( $attr[3] ?? '' ) ? $attr[3] : ( $attr[4] ?? '' );

						if ( ! isset( $allowed_html[ $tag ][ $name ] ) ) {
							continue;
						}

						if ( 'href' === $name && 1 === preg_match( '#^([a-z][a-z0-9+.-]*):#i', $value, $scheme ) && ! in_array( strtolower( $scheme[1] ), $allowed_protocols, true ) ) {
							continue;
						}

						$out .= ' ' . $name . '="' . $value . '"';
					}

					return '<' . $tag . $out . '>';
				},
				$content
			);
		}
	}
}

namespace NexoDev\NexoMCP\Tests {

	use NexoDev\NexoMCP\Builders\DiviModuleCatalog;
	use NexoDev\NexoMCP\Builders\DiviTreeValidator;
	use PHPUnit\Framework\TestCase;

	/**
	 * Cada rechazo se comprueba con el código exacto y la ruta en el mensaje.
	 */
	final class DiviTreeValidatorTest extends TestCase {

		/**
		 * Validador con shortcodes registrados `gallery` y `caption` y adjuntos 7 (imagen) y 8 (no existe).
		 */
		private function validator(): DiviTreeValidator {
			return new DiviTreeValidator(
				static fn( string $name ): bool => in_array( $name, array( 'gallery', 'caption' ), true ),
				static fn( int $id ) => 7 === $id ? 'https://example.test/wp-content/uploads/a.jpg' : false
			);
		}

		/**
		 * @param array<string, mixed> $module Módulo de contenido.
		 * @return array<int, array<string, mixed>>
		 */
		private function page( array $module ): array {
			return array(
				array(
					'type'     => 'divi/section',
					'children' => array(
						array(
							'type'     => 'divi/row',
							'children' => array(
								array(
									'type'     => 'divi/column',
									'attrs'    => array( 'module' => array( 'advanced' => array( 'type' => array( 'desktop' => array( 'value' => '4_4' ) ) ) ) ),
									'children' => array( $module ),
								),
							),
						),
					),
				),
			);
		}

		private const MODULE_AT = 'nodes[0].children[0].children[0].children[0]';

		/**
		 * @param array<int, array<string, mixed>>|mixed $result Resultado.
		 */
		private function assertError( string $code, string $path, $result ): void {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
			$this->assertSame( array( 'path' => $path ), $result->get_error_data( $code ) );
		}

		/**
		 * @param array<string, mixed> $attrs Atributos.
		 * @return array<string, mixed>
		 */
		private static function text( array $attrs ): array {
			return array(
				'type'  => 'divi/text',
				'attrs' => $attrs,
			);
		}

		private static function value( $value ): array {
			return array( 'desktop' => array( 'value' => $value ) );
		}

		public function test_a_full_valid_tree_is_returned_sanitized_and_the_input_is_untouched(): void {
			$tree = array(
				array(
					'type'     => 'divi/section',
					'attrs'    => array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'color' => '#112233' ) ) ) ) ),
					'children' => array(
						array(
							'type'     => 'divi/row',
							'children' => array(
								array(
									'type'     => 'divi/column',
									'attrs'    => array( 'module' => array( 'advanced' => array( 'type' => self::value( '4_4' ) ) ) ),
									'children' => array(
										array(
											'type'  => 'divi/heading',
											'attrs' => array(
												'title' => array(
													'innerContent' => self::value( 'Hola' ),
													'decoration'   => array( 'font' => array( 'font' => self::value( array( 'size' => '30px', 'color' => '#ff0000', 'weight' => '700', 'textAlign' => 'center', 'headingLevel' => 'h2', 'family' => 'Roboto' ) ) ) ),
												),
											),
										),
										array(
											'type'  => 'divi/text',
											'attrs' => array( 'content' => array( 'innerContent' => self::value( '<p>Hola <strong>mundo</strong> <a href="/x" target="_blank" rel="noopener">link</a></p>' ) ) ),
										),
										array(
											'type'  => 'divi/button',
											'attrs' => array( 'button' => array( 'innerContent' => self::value( array( 'text' => 'Ir', 'linkUrl' => 'https://a.b', 'linkTarget' => 'on' ) ) ) ),
										),
										array(
											'type'  => 'divi/image',
											'attrs' => array( 'image' => array( 'innerContent' => self::value( array( 'src' => 7, 'alt' => 'Foto' ) ) ) ),
										),
										array( 'type' => 'divi/divider' ),
										array(
											'type'  => 'divi/blurb',
											'attrs' => array(
												'title'     => array( 'innerContent' => self::value( array( 'text' => 'B', 'url' => 'mailto:a@b.c' ) ) ),
												'imageIcon' => array( 'innerContent' => self::value( array( 'useIcon' => 'on', 'icon' => array( 'unicode' => '&#x21;', 'type' => 'divi', 'weight' => '400' ) ) ) ),
											),
										),
										array(
											'type'  => 'divi/icon',
											'attrs' => array( 'icon' => array( 'innerContent' => self::value( array( 'unicode' => '&#x21;', 'type' => 'divi', 'weight' => '400', 'url' => 'tel:+34123' ) ), 'advanced' => array( 'color' => self::value( 'gcid-primary-color' ), 'size' => self::value( '60px' ) ) ) ),
										),
										array(
											'type'  => 'divi/video',
											'attrs' => array( 'video' => array( 'innerContent' => self::value( array( 'src' => 'https://a.b/v.mp4' ) ) ) ),
										),
										array(
											'type'     => 'divi/icon-list',
											'children' => array(
												array(
													'type'  => 'divi/icon-list-item',
													'attrs' => array( 'content' => array( 'innerContent' => self::value( 'Uno' ) ) ),
												),
											),
										),
									),
								),
							),
						),
					),
				),
			);

			$before = $tree;
			$out    = $this->validator()->validate_tree( $tree );

			$this->assertSame( $before, $tree );
			$this->assertIsArray( $out );
			$column = $out[0]['children'][0]['children'][0];
			$this->assertSame( 'divi/column', $column['type'] );
			$this->assertSame( 'Hola', $column['children'][0]['attrs']['title']['innerContent']['desktop']['value'] );
			$this->assertSame(
				array( 'src' => 'https://example.test/wp-content/uploads/a.jpg', 'alt' => 'Foto' ),
				$column['children'][3]['attrs']['image']['innerContent']['desktop']['value']
			);
			$this->assertSame( 9, count( $column['children'] ) );
			$this->assertSame( 'divi/icon-list-item', $column['children'][8]['children'][0]['type'] );
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function denied_modules(): array {
			return array(
				'code'            => array( 'divi/code' ),
				'fullwidth code'  => array( 'divi/fullwidth-code' ),
				'shortcode'       => array( 'divi/shortcode-module' ),
				'accordion'       => array( 'divi/accordion' ),
				'core html'       => array( 'core/html' ),
				'divi 4'          => array( 'et_pb_text' ),
			);
		}

		/**
		 * @dataProvider denied_modules
		 */
		public function test_modules_outside_the_catalog_are_denied( string $type ): void {
			$result = $this->validator()->validate_tree( $this->page( array( 'type' => $type ) ) );

			$this->assertError( 'nexo_mcp_divi_module_denied', self::MODULE_AT . '.type', $result );
			$this->assertStringContainsString( $type, $result->get_error_message() ); // @phpstan-ignore-line
		}

		public function test_denied_module_nested_anywhere_is_denied_with_its_path(): void {
			$tree   = $this->page( array( 'type' => 'divi/text' ) );
			$tree[0]['children'][0]['children'][0]['children'][] = array( 'type' => 'divi/code' );

			$this->assertError( 'nexo_mcp_divi_module_denied', 'nodes[0].children[0].children[0].children[1].type', $this->validator()->validate_tree( $tree ) );
		}

		/**
		 * @return array<string, array{array<string, mixed>, string}>
		 */
		public static function denied_attrs(): array {
			return array(
				'htmlBefore'          => array( array( 'module' => array( 'advanced' => array( 'htmlBefore' => self::value( '<b>x</b>' ) ) ) ), 'module.advanced.htmlBefore' ),
				'htmlAfter'           => array( array( 'module' => array( 'advanced' => array( 'htmlAfter' => self::value( '<b>x</b>' ) ) ) ), 'module.advanced.htmlAfter' ),
				'html attributes'     => array( array( 'module' => array( 'advanced' => array( 'htmlAttributes' => self::value( array( array( 'name' => 'onclick', 'value' => 'x' ) ) ) ) ) ), 'module.advanced.htmlAttributes' ),
				'html element'        => array( array( 'module' => array( 'advanced' => array( 'html' => self::value( array( 'elementType' => 'script' ) ) ) ) ), 'module.advanced.html' ),
				'custom css'          => array( array( 'css' => array( 'desktop' => array( 'value' => array( 'freeForm' => 'body{display:none}' ) ) ) ), 'css' ),
				'css main element'    => array( array( 'css' => array( 'mainElement' => self::value( 'x' ) ) ), 'css' ),
				'advanced elsewhere'  => array( array( 'module' => array( 'advanced' => array( 'link' => self::value( array( 'url' => 'https://a.b' ) ) ) ) ), 'module.advanced.link' ),
				'builderVersion'      => array( array( 'builderVersion' => '5.19.1' ), 'builderVersion' ),
				'modulePreset'        => array( array( 'modulePreset' => array( 'x' ) ), 'modulePreset' ),
				'unknown group'       => array( array( 'foo' => self::value( 'x' ) ), 'foo' ),
				'unknown leaf'        => array( array( 'content' => array( 'innerContentX' => self::value( 'x' ) ) ), 'content.innerContentX' ),
				'below leaf'          => array( array( 'content' => array( 'innerContent' => array( 'extra' => self::value( 'x' ) ) ) ), 'content.innerContent.extra' ),
			);
		}

		/**
		 * @dataProvider denied_attrs
		 *
		 * @param array<string, mixed> $attrs Atributos.
		 */
		public function test_unknown_or_dangerous_attributes_are_denied( array $attrs, string $path ): void {
			$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.' . $path, $this->validator()->validate_tree( $this->page( self::text( $attrs ) ) ) );
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function breakpoints(): array {
			return array(
				'tablet' => array( 'tablet' ),
				'phone'  => array( 'phone' ),
				'hover'  => array( 'hover' ),
				'sticky' => array( 'sticky' ),
			);
		}

		/**
		 * @dataProvider breakpoints
		 */
		public function test_only_the_desktop_value_is_accepted( string $key ): void {
			$attrs  = array( 'content' => array( 'innerContent' => array( $key => array( 'value' => '<p>x</p>' ) ) ) );
			$result = $this->validator()->validate_tree( $this->page( self::text( $attrs ) ) );

			$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.content.innerContent.' . $key, $result );
		}

		public function test_desktop_together_with_tablet_is_denied_and_extra_keys_next_to_value_too(): void {
			$both = array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>x</p>' ), 'tablet' => array( 'value' => '<p>y</p>' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.content.innerContent.tablet', $this->validator()->validate_tree( $this->page( self::text( $both ) ) ) );

			$extra = array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>x</p>', 'hover' => '<p>y</p>' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.content.innerContent.desktop.hover', $this->validator()->validate_tree( $this->page( self::text( $extra ) ) ) );

			$none = array( 'content' => array( 'innerContent' => array( 'desktop' => array() ) ) );
			$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.content.innerContent.desktop.value', $this->validator()->validate_tree( $this->page( self::text( $none ) ) ) );
		}

		/**
		 * @return array<string, array{string, array<string, mixed>, string}>
		 */
		public static function invalid_values(): array {
			$btn = static fn( array $value ): array => array( 'button' => array( 'innerContent' => self::value( $value ) ) );

			return array(
				'javascript link' => array( 'divi/button', $btn( array( 'text' => 'x', 'linkUrl' => 'javascript:alert(1)' ) ), 'button.innerContent.desktop.value.linkUrl' ),
				'data link'       => array( 'divi/button', $btn( array( 'text' => 'x', 'linkUrl' => 'data:text/html;base64,AAAA' ) ), 'button.innerContent.desktop.value.linkUrl' ),
				'split scheme'    => array( 'divi/button', $btn( array( 'text' => 'x', 'linkUrl' => "java\nscript:alert(1)" ) ), 'button.innerContent.desktop.value.linkUrl' ),
				'ftp link'        => array( 'divi/button', $btn( array( 'text' => 'x', 'linkUrl' => 'ftp://a.b' ) ), 'button.innerContent.desktop.value.linkUrl' ),
				'link target'     => array( 'divi/button', $btn( array( 'text' => 'x', 'linkTarget' => 'yes' ) ), 'button.innerContent.desktop.value.linkTarget' ),
				'button shape'    => array( 'divi/button', $btn( array() + array( 'text' => array( 'a' ) ) ), 'button.innerContent.desktop.value.text' ),
				'string for obj'  => array( 'divi/button', array( 'button' => array( 'innerContent' => self::value( 'Ir' ) ) ), 'button.innerContent.desktop.value' ),
				'bad color'       => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'color' => 'red' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.color' ),
				'css color'       => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'color' => '#fff;background:url(x)' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.color' ),
				'bad size'        => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'size' => '30' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.size' ),
				'bad align'       => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'textAlign' => 'middle' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.textAlign' ),
				'bad level'       => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'headingLevel' => 'script' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.headingLevel' ),
				'bad weight'      => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'weight' => 'x;y' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.weight' ),
				'bad family'      => array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'family' => 'a;b{' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.family' ),
				'bad margin'      => array( 'divi/text', array( 'module' => array( 'decoration' => array( 'spacing' => self::value( array( 'margin' => array( 'top' => '1' ) ) ) ) ) ), 'module.decoration.spacing.desktop.value.margin.top' ),
				'bad sync'        => array( 'divi/text', array( 'module' => array( 'decoration' => array( 'spacing' => self::value( array( 'padding' => array( 'syncVertical' => 'maybe' ) ) ) ) ) ), 'module.decoration.spacing.desktop.value.padding.syncVertical' ),
				'bad bg'          => array( 'divi/text', array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'color' => 'blue' ) ) ) ) ), 'module.decoration.background.desktop.value.color' ),
				'bad column'      => array( 'divi/column', array( 'module' => array( 'advanced' => array( 'type' => self::value( '9_9' ) ) ) ), 'module.advanced.type.desktop.value' ),
				'label array'     => array( 'divi/text', array( 'module' => array( 'meta' => array( 'adminLabel' => self::value( array( 'x' ) ) ) ) ), 'module.meta.adminLabel.desktop.value' ),
				'video javascript' => array( 'divi/video', array( 'video' => array( 'innerContent' => self::value( array( 'src' => 'javascript:x' ) ) ) ), 'video.innerContent.desktop.value.src' ),
				'image data'      => array( 'divi/image', array( 'image' => array( 'innerContent' => self::value( array( 'src' => 'data:image/svg+xml;base64,AAAA' ) ) ) ), 'image.innerContent.desktop.value.src' ),
				'image missing'   => array( 'divi/image', array( 'image' => array( 'innerContent' => self::value( array( 'src' => 8 ) ) ) ), 'image.innerContent.desktop.value.src' ),
				'image link'      => array( 'divi/image', array( 'image' => array( 'innerContent' => self::value( array( 'src' => 7, 'linkUrl' => 'javascript:x' ) ) ) ), 'image.innerContent.desktop.value.linkUrl' ),
				'icon unicode'    => array( 'divi/icon', array( 'icon' => array( 'innerContent' => self::value( array( 'unicode' => '<script>' ) ) ) ), 'icon.innerContent.desktop.value.unicode' ),
				'icon size'       => array( 'divi/icon', array( 'icon' => array( 'advanced' => array( 'size' => self::value( '10' ) ) ) ), 'icon.advanced.size.desktop.value' ),
				'divider style'   => array( 'divi/divider', array( 'divider' => array( 'advanced' => array( 'line' => self::value( array( 'style' => 'wavy' ) ) ) ) ), 'divider.advanced.line.desktop.value.style' ),
				'divider color'   => array( 'divi/divider', array( 'divider' => array( 'advanced' => array( 'line' => self::value( array( 'color' => 'x' ) ) ) ) ), 'divider.advanced.line.desktop.value.color' ),
			);
		}

		/**
		 * @dataProvider invalid_values
		 *
		 * @param array<string, mixed> $attrs Atributos.
		 */
		public function test_invalid_values_are_rejected_with_the_value_path( string $type, array $attrs, string $path ): void {
			$node = array(
				'type'  => $type,
				'attrs' => $attrs,
			);

			if ( 'divi/column' === $type ) {
				$tree = $this->page( self::text( array() ) );
				$tree[0]['children'][0]['children'][0]['attrs'] = $attrs;
				$base = 'nodes[0].children[0].children[0]';
			} else {
				$tree = $this->page( $node );
				$base = self::MODULE_AT;
			}

			$this->assertError( 'nexo_mcp_divi_value_invalid', $base . '.attrs.' . $path, $this->validator()->validate_tree( $tree ) );
		}

		public function test_unknown_keys_inside_a_value_object_are_denied(): void {
			$cases = array(
				array( 'divi/button', array( 'button' => array( 'innerContent' => self::value( array( 'text' => 'x', 'onClick' => 'x' ) ) ) ), 'button.innerContent.desktop.value.onClick' ),
				array( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'customCss' => 'x' ) ) ) ) ) ), 'title.decoration.font.font.desktop.value.customCss' ),
				array( 'divi/text', array( 'module' => array( 'decoration' => array( 'spacing' => self::value( array( 'gap' => '1px' ) ) ) ) ), 'module.decoration.spacing.desktop.value.gap' ),
				array( 'divi/text', array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'image' => array( 'url' => 'https://a.b/x.png' ) ) ) ) ) ), 'module.decoration.background.desktop.value.image' ),
				array( 'divi/text', array( 'module' => array( 'decoration' => array( 'spacing' => self::value( array( 'margin' => array( 'middle' => '1px' ) ) ) ) ) ), 'module.decoration.spacing.desktop.value.margin.middle' ),
			);

			foreach ( $cases as $case ) {
				$result = $this->validator()->validate_tree( $this->page( array( 'type' => $case[0], 'attrs' => $case[1] ) ) );

				$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.' . $case[2], $result );
			}
		}

		public function test_a_content_path_inside_the_design_slot_and_the_reverse_are_denied_in_a_patch(): void {
			$content_in_design = array( 'design' => array( 'content' => array( 'innerContent' => self::value( '<p>x</p>' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', 'design.content.innerContent', $this->validator()->validate_patch( 'divi/text', $content_in_design ) );

			$design_in_content = array( 'content' => array( 'module' => array( 'decoration' => array( 'spacing' => self::value( array( 'margin' => array( 'top' => '1px' ) ) ) ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', 'content.module.decoration', $this->validator()->validate_patch( 'divi/text', $design_in_content ) );
		}

		public function test_patch_returns_the_sanitized_content_and_design_and_leaves_the_input_untouched(): void {
			$patch = array(
				'content' => array( 'content' => array( 'innerContent' => self::value( '<p onclick="x()">Hola <script>alert(1)</script><em>a</em></p>' ) ) ),
				'design'  => array(
					'module'  => array( 'decoration' => array( 'spacing' => self::value( array( 'margin' => array( 'top' => '10px', 'right' => '', 'syncVertical' => 'off' ), 'padding' => array( 'left' => '2em' ) ) ), 'background' => self::value( array( 'color' => 'rgba(0,0,0,0.5)' ) ) ) ),
					'content' => array( 'decoration' => array( 'bodyFont' => array( 'body' => array( 'font' => self::value( array( 'size' => '18px', 'color' => 'gcid-primary-color', 'lineHeight' => '1.5em', 'letterSpacing' => '2px', 'textAlign' => 'center' ) ) ) ) ) ),
				),
			);
			$copy  = $patch;
			$out   = $this->validator()->validate_patch( 'divi/text', $patch );

			$this->assertSame( $copy, $patch );
			$this->assertSame( array( 'content', 'design' ), array_keys( (array) $out ) );
			$this->assertSame( '<p>Hola <em>a</em></p>', $out['content']['content']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
			$this->assertSame( $patch['design'], $out['design'] ); // @phpstan-ignore-line
		}

		public function test_patch_with_only_one_slot_returns_only_that_slot(): void {
			$out = $this->validator()->validate_patch( 'divi/heading', array( 'content' => array( 'title' => array( 'innerContent' => self::value( 'Nuevo' ) ) ) ) );

			$this->assertSame( array( 'content' => array( 'title' => array( 'innerContent' => self::value( 'Nuevo' ) ) ) ), $out );
		}

		public function test_patch_rejects_unknown_keys_modules_outside_the_catalog_and_malformed_input(): void {
			$v = $this->validator();

			$this->assertError( 'nexo_mcp_divi_attr_denied', 'patch.attrs', $v->validate_patch( 'divi/text', array( 'attrs' => array() ) ) );
			$this->assertError( 'nexo_mcp_divi_module_denied', 'type', $v->validate_patch( 'divi/code', array( 'content' => array() ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'patch', $v->validate_patch( 'divi/text', array() ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'patch', $v->validate_patch( 'divi/text', 'x' ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'content', $v->validate_patch( 'divi/text', array( 'content' => 'x' ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', 'content.content.innerContent.tablet', $v->validate_patch( 'divi/text', array( 'content' => array( 'content' => array( 'innerContent' => array( 'tablet' => array( 'value' => 'x' ) ) ) ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', 'content.module.advanced', $v->validate_patch( 'divi/text', array( 'content' => array( 'module' => array( 'advanced' => array( 'htmlBefore' => self::value( 'x' ) ) ) ) ) ) );
			$this->assertError( 'nexo_mcp_divi_value_invalid', 'design.module.decoration.background.desktop.value.color', $v->validate_patch( 'divi/text', array( 'design' => array( 'module' => array( 'decoration' => array( 'background' => self::value( array( 'color' => 'x' ) ) ) ) ) ) ) );
		}

		public function test_registered_shortcodes_in_text_are_denied_and_unregistered_brackets_pass(): void {
			$v = $this->validator();

			foreach ( array( '<p>[gallery ids="1,2"]</p>', '<p>a [caption]x[/caption]</p>', '[/gallery]', '<p>&#91;gallery]</p>', '<p>[ gallery]</p>' ) as $html ) {
				$result = $v->validate_tree( $this->page( self::text( array( 'content' => array( 'innerContent' => self::value( $html ) ) ) ) ) );

				if ( '<p>[ gallery]</p>' === $html ) {
					$this->assertIsArray( $result, $html ); // Con espacio no es shortcode.
					continue;
				}

				$this->assertError( 'nexo_mcp_divi_shortcode_denied', self::MODULE_AT . '.attrs.content.innerContent.desktop.value', $result );
			}

			$ok = $v->validate_tree( $this->page( self::text( array( 'content' => array( 'innerContent' => self::value( '<p>Lista [nota] y [1] y a[0]</p>' ) ) ) ) ) );
			$this->assertIsArray( $ok );
		}

		public function test_shortcodes_are_denied_in_plain_text_fields_too(): void {
			$heading = array(
				'type'  => 'divi/heading',
				'attrs' => array( 'title' => array( 'innerContent' => self::value( 'Hola [gallery]' ) ) ),
			);
			$this->assertError( 'nexo_mcp_divi_shortcode_denied', self::MODULE_AT . '.attrs.title.innerContent.desktop.value', $this->validator()->validate_tree( $this->page( $heading ) ) );

			$button = array(
				'type'  => 'divi/button',
				'attrs' => array( 'button' => array( 'innerContent' => self::value( array( 'text' => '[caption]' ) ) ) ),
			);
			$this->assertError( 'nexo_mcp_divi_shortcode_denied', self::MODULE_AT . '.attrs.button.innerContent.desktop.value.text', $this->validator()->validate_tree( $this->page( $button ) ) );
		}

		public function test_rich_text_is_sanitized_with_the_short_allowlist(): void {
			$html = '<h2 class="x" style="color:red">T</h2><p onclick="x()">a <strong>b</strong> <em>c</em> <b>d</b> <i>e</i><br><span id="s">f</span></p>'
				. '<ul><li>u</li></ul><ol><li>o</li></ol><a href="https://a.b" target="_blank" rel="noopener" onmouseover="x()" class="c">l</a>'
				. '<script>alert(1)</script><iframe src="https://evil.test"></iframe><img src="x" onerror="x()"><div>dd</div><style>p{}</style>';
			$out  = $this->validator()->validate_tree( $this->page( self::text( array( 'content' => array( 'innerContent' => self::value( $html ) ) ) ) ) );

			$this->assertIsArray( $out );
			$this->assertSame(
				'<h2>T</h2><p>a <strong>b</strong> <em>c</em> <b>d</b> <i>e</i><br><span>f</span></p><ul><li>u</li></ul><ol><li>o</li></ol><a href="https://a.b" target="_blank" rel="noopener">l</a>dd',
				$out[0]['children'][0]['children'][0]['children'][0]['attrs']['content']['innerContent']['desktop']['value']
			);
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function bad_hrefs(): array {
			return array(
				'javascript' => array( '<a href="javascript:alert(1)">x</a>' ),
				'mixed'      => array( "<a href='JaVa\tScRiPt:alert(1)'>x</a>" ),
				'entity'     => array( '<a href="&#106;avascript:alert(1)">x</a>' ),
				'data'       => array( '<a href="data:text/html;base64,AAAA">x</a>' ),
				'unquoted'   => array( '<a href=javascript:alert(1)>x</a>' ),
			);
		}

		/**
		 * @dataProvider bad_hrefs
		 */
		public function test_dangerous_links_in_rich_text_are_denied( string $html ): void {
			$result = $this->validator()->validate_tree( $this->page( self::text( array( 'content' => array( 'innerContent' => self::value( $html ) ) ) ) ) );

			$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.content.innerContent.desktop.value', $result );
		}

		public function test_safe_links_in_rich_text_are_kept(): void {
			$html = '<a href="mailto:a@b.c">m</a><a href="tel:+34123">t</a><a href="/rel">r</a><a href="#a">h</a>';
			$out  = $this->validator()->validate_tree( $this->page( self::text( array( 'content' => array( 'innerContent' => self::value( $html ) ) ) ) ) );

			$this->assertIsArray( $out );
			$this->assertSame( $html, $out[0]['children'][0]['children'][0]['children'][0]['attrs']['content']['innerContent']['desktop']['value'] );
		}

		public function test_plain_text_fields_lose_their_markup(): void {
			$heading = array(
				'type'  => 'divi/heading',
				'attrs' => array( 'title' => array( 'innerContent' => self::value( 'Hola <b onclick="x()">mundo</b><script>alert(1)</script>' ) ) ),
			);
			$out     = $this->validator()->validate_tree( $this->page( $heading ) );

			$this->assertIsArray( $out );
			$this->assertSame( 'Hola mundo', $out[0]['children'][0]['children'][0]['children'][0]['attrs']['title']['innerContent']['desktop']['value'] );
		}

		public function test_nesting_rules(): void {
			$v = $this->validator();

			$this->assertIsArray( $v->validate_tree( array( array( 'type' => 'divi/placeholder', 'children' => array( array( 'type' => 'divi/section' ) ) ) ) ) );
			$this->assertIsArray( $v->validate_tree( array( array( 'type' => 'divi/section' ) ) ) );

			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].type', $v->validate_tree( array( array( 'type' => 'divi/row' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].type', $v->validate_tree( array( array( 'type' => 'divi/text' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].children[0].type', $v->validate_tree( array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/text' ) ) ) ) ) );
			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].children[0].type', $v->validate_tree( array( array( 'type' => 'divi/placeholder', 'children' => array( array( 'type' => 'divi/row' ) ) ) ) ) );
			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].children[0].type', $v->validate_tree( array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/section' ) ) ) ) ) );
			$this->assertError( self::nesting(), self::MODULE_AT . '.children[0].type', $v->validate_tree( $this->page( array( 'type' => 'divi/text', 'children' => array( array( 'type' => 'divi/text' ) ) ) ) ) );
			$this->assertError( self::nesting(), self::MODULE_AT . '.type', $v->validate_tree( $this->page( array( 'type' => 'divi/icon-list-item' ) ) ) );
			$this->assertError( self::nesting(), self::MODULE_AT . '.children[0].type', $v->validate_tree( $this->page( array( 'type' => 'divi/icon-list', 'children' => array( array( 'type' => 'divi/text' ) ) ) ) ) );
		}

		private static function nesting(): string {
			return 'nexo_mcp_divi_nesting_invalid';
		}

		public function test_a_subtree_is_validated_against_its_real_parent(): void {
			$v = $this->validator();

			$this->assertIsArray( $v->validate_tree( array( array( 'type' => 'divi/text' ) ), 'divi/column', 3, 5 ) );
			$this->assertIsArray( $v->validate_tree( array( array( 'type' => 'divi/row' ) ), 'divi/section', 1, 2 ) );
			$this->assertError( 'nexo_mcp_divi_nesting_invalid', 'nodes[0].type', $v->validate_tree( array( array( 'type' => 'divi/row' ) ), 'divi/column', 3, 5 ) );
			$this->assertError( 'nexo_mcp_divi_module_denied', 'nodes[0].type', $v->validate_tree( array( array( 'type' => 'divi/code' ) ), 'divi/column', 3, 5 ) );
		}

		public function test_malformed_trees(): void {
			$v = $this->validator();

			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes', $v->validate_tree( array() ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes', $v->validate_tree( 'x' ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes', $v->validate_tree( array( 'type' => 'divi/section' ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes[0]', $v->validate_tree( array( 'x' ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes[0].type', $v->validate_tree( array( array( 'attrs' => array() ) ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes[0].type', $v->validate_tree( array( array( 'type' => 5 ) ) ) );
			$this->assertError( 'nexo_mcp_divi_attr_denied', 'nodes[0].innerHTML', $v->validate_tree( array( array( 'type' => 'divi/section', 'innerHTML' => '<script>' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes[0].attrs', $v->validate_tree( array( array( 'type' => 'divi/section', 'attrs' => 'x' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_tree_invalid', 'nodes[0].children', $v->validate_tree( array( array( 'type' => 'divi/section', 'children' => 'x' ) ) ) );
		}

		public function test_node_cap_counts_the_whole_resulting_document(): void {
			$v    = $this->validator();
			$rows = array();

			for ( $i = 0; $i < DiviTreeValidator::MAX_NODES; ++$i ) {
				$rows[] = array( 'type' => 'divi/row' );
			}

			$tree = array( array( 'type' => 'divi/section', 'children' => $rows ) ); // 1 + MAX_NODES nodos.

			$this->assertError( 'nexo_mcp_divi_too_many_nodes', 'nodes[0].children[' . ( DiviTreeValidator::MAX_NODES - 1 ) . ']', $v->validate_tree( $tree ) );
			$this->assertIsArray( $v->validate_tree( array( array( 'type' => 'divi/section' ) ), DiviModuleCatalog::ROOT, 0, DiviTreeValidator::MAX_NODES - 1 ) );
			$this->assertError( 'nexo_mcp_divi_too_many_nodes', 'nodes[0]', $v->validate_tree( array( array( 'type' => 'divi/section' ) ), DiviModuleCatalog::ROOT, 0, DiviTreeValidator::MAX_NODES ) );
		}

		public function test_depth_and_size_caps(): void {
			$v    = $this->validator();
			$text = array(
				'type'  => 'divi/text',
				'attrs' => array( 'content' => array( 'innerContent' => self::value( str_repeat( 'a', DiviTreeValidator::MAX_BYTES ) ) ) ),
			);

			$this->assertError( 'nexo_mcp_divi_too_large', 'nodes', $v->validate_tree( $this->page( $text ) ) );
		}

		public function test_image_attachment_is_resolved_and_a_url_must_be_http(): void {
			$v   = $this->validator();
			$mk  = static fn( $src ): array => array(
				'type'  => 'divi/image',
				'attrs' => array( 'image' => array( 'innerContent' => self::value( array( 'src' => $src ) ) ) ),
			);
			$ok  = $v->validate_tree( $this->page( $mk( 'https://cdn.test/a.png' ) ) );
			$rel = $v->validate_tree( $this->page( $mk( '/wp-content/uploads/a.png' ) ) );

			$this->assertIsArray( $ok );
			$this->assertSame( 'https://cdn.test/a.png', $ok[0]['children'][0]['children'][0]['children'][0]['attrs']['image']['innerContent']['desktop']['value']['src'] );
			$this->assertIsArray( $rel );
			$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.image.innerContent.desktop.value.src', $v->validate_tree( $this->page( $mk( 'mailto:a@b.c' ) ) ) );
			$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.image.innerContent.desktop.value.src', $v->validate_tree( $this->page( $mk( 0 ) ) ) );
		}
	}

}
