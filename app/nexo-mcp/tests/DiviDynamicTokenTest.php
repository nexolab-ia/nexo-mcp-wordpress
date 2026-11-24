<?php
/**
 * Parche 1.9.1: ningún valor que escriba el MCP en Divi 5 puede llevar un token de contenido dinámico `$variable(…)$`.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use PHPUnit\Framework\TestCase;

final class DiviDynamicTokenTest extends TestCase {

	private const TOKEN = '$variable({"type":"content","value":{"name":"custom_meta__edit_last","settings":{}}})$';

	private function validator(): DiviTreeValidator {
		return new DiviTreeValidator(
			static fn( string $name ): bool => in_array( $name, array( 'gallery', 'caption' ), true ),
			static fn( int $id ) => 7 === $id ? 'https://example.test/wp-content/uploads/a.jpg' : false,
			static fn(): array => array(),
			static fn(): array => array()
		);
	}

	/**
	 * @param array<string, mixed> $attrs Atributos.
	 * @return array<int, array<string, mixed>>
	 */
	private function tree( string $type, array $attrs ): array {
		return array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array( array( 'type' => $type, 'attrs' => $attrs ) ),
							),
						),
					),
				),
			),
		);
	}

	private static function v( $value ): array {
		return array( 'desktop' => array( 'value' => $value ) );
	}

	private function assertDynamic( string $path, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_dynamic_denied', $result->get_error_code() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
		$this->assertStringContainsString( 'no está habilitado', $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data( 'nexo_mcp_divi_dynamic_denied' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function forms(): array {
		return array(
			'literal'               => array( self::TOKEN ),
			'inside text'           => array( 'Hola ' . self::TOKEN . ' adiós' ),
			'inside html'           => array( '<p>Hola <b>' . self::TOKEN . '</b></p>' ),
			'uppercase'             => array( '$VARIABLE({"type":"content"})$' ),
			'mixed case'            => array( '$VaRiAbLe({"type":"content"})$' ),
			'whitespace'            => array( '$ variable ({"type":"content"})$' ),
			'json unicode dollar'   => array( '$variable({"type":"content"})$' ),
			'json unicode escaped'  => array( '\\\\u0024variable({"type":"content"})$' ),
			'json unicode letters'  => array( '$variable({"type":"content"})$' ),
			'hex escape'            => array( '\x24variable(' ),
			'decimal entity'        => array( '&#36;variable({"type":"content"})$' ),
			'decimal entity no ;'   => array( '&#36variable(' ),
			'padded entity'         => array( '&#0036;variable(' ),
			'hex entity'            => array( '&#x24;variable(' ),
			'hex entity upper'      => array( '&#X24;variable(' ),
			'named entity'          => array( '&dollar;variable(' ),
			'entity in letters'     => array( '$&#118;ariable(' ),
			'double encoded'        => array( '&amp;#36;variable(' ),
			'url encoded'           => array( '%24variable(' ),
			'tag between'           => array( '$<b></b>variable(' ),
			'script between'        => array( '$<script>x</script>variable(' ),
			'zero width'            => array( "\$\u{200B}variable(" ),
			'legacy divi 4 marker'  => array( '@ET-DC@eyJkeW5hbWljIjp0cnVlfQ==@' ),
		);
	}

	/**
	 * @dataProvider forms
	 */
	public function test_every_form_is_rejected_in_plain_and_rich_text_of_a_tree( string $value ): void {
		$validator = $this->validator();
		$base      = 'nodes[0].children[0].children[0].children[0].attrs.';

		$this->assertDynamic( $base . 'title.innerContent.desktop.value', $validator->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( $value ) ) ) ) ) );
		$this->assertDynamic( $base . 'content.innerContent.desktop.value', $validator->validate_tree( $this->tree( 'divi/text', array( 'content' => array( 'innerContent' => self::v( $value ) ) ) ) ) );
	}

	/**
	 * @dataProvider forms
	 */
	public function test_every_form_is_rejected_in_a_module_patch( string $value ): void {
		$result = $this->validator()->validate_patch( 'divi/text', array( 'content' => array( 'content' => array( 'innerContent' => self::v( $value ) ) ) ) );

		$this->assertDynamic( 'content.content.innerContent.desktop.value', $result );
	}

	public function test_links_images_and_object_fields_are_rejected_with_their_path(): void {
		$validator = $this->validator();
		$base      = 'nodes[0].children[0].children[0].children[0].attrs.';

		foreach ( array( 'linkUrl', 'text' ) as $key ) {
			$this->assertDynamic(
				$base . 'button.innerContent.desktop.value.' . $key,
				$validator->validate_tree( $this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( $key => self::TOKEN ) ) ) ) ) )
			);
		}

		foreach ( array( 'src', 'alt', 'titleText', 'linkUrl' ) as $key ) {
			$this->assertDynamic(
				$base . 'image.innerContent.desktop.value.' . $key,
				$validator->validate_tree( $this->tree( 'divi/image', array( 'image' => array( 'innerContent' => self::v( array( $key => self::TOKEN ) ) ) ) ) )
			);
		}

		$this->assertDynamic( $base . 'title.innerContent.desktop.value.url', $validator->validate_tree( $this->tree( 'divi/blurb', array( 'title' => array( 'innerContent' => self::v( array( 'url' => '/x?a=$variable(' ) ) ) ) ) ) );
	}

	public function test_a_token_in_design_values_and_in_preset_attrs_is_rejected(): void {
		$validator = $this->validator();
		$attrs     = array( 'module' => array( 'decoration' => array( 'background' => self::v( array( 'color' => self::TOKEN ) ) ) ) );

		$this->assertDynamic(
			'nodes[0].children[0].children[0].children[0].attrs.module.decoration.background.desktop.value.color',
			$validator->validate_tree( $this->tree( 'divi/text', $attrs ) )
		);
		$this->assertDynamic( 'design.module.decoration.background.desktop.value.color', $validator->validate_patch( 'divi/text', array( 'design' => $attrs ) ) );
		$this->assertDynamic( 'attrs.module.decoration.background.desktop.value.color', $validator->validate_preset_attrs( 'divi/text', $attrs ) );
	}

	public function test_a_payload_that_sanitizing_would_reassemble_is_caught_after_sanitizing_too(): void {
		$validator = $this->validator();
		$attrs     = static fn( string $v ): array => array( 'title' => array( 'innerContent' => self::v( $v ) ) );

		// Quitar `<i>…</i>` entre `$` y `variable(` arma el token: la entrada ya lo trae, pero se comprueba también la salida.
		$this->assertInstanceOf( \WP_Error::class, $validator->validate_tree( $this->tree( 'divi/heading', $attrs( '$<i x="1"></i>variable(' ) ) ) );
		$this->assertTrue( DiviTreeValidator::has_dynamic_token( '$<img src=x>variable(' ) );
	}

	public function test_a_rejection_in_a_later_sibling_returns_nothing(): void {
		$tree                                                    = $this->tree( 'divi/text', array( 'content' => array( 'innerContent' => self::v( '<p>ok</p>' ) ) ) );
		$tree[0]['children'][0]['children'][0]['children'][]      = array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => self::v( self::TOKEN ) ) ) );
		$result = $this->validator()->validate_tree( $tree );

		$this->assertDynamic( 'nodes[0].children[0].children[0].children[1].attrs.content.innerContent.desktop.value', $result );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function innocent(): array {
		return array(
			'price'        => array( 'Cuesta $5 y la variable (x) es otra cosa' ),
			'dollar only'  => array( 'US$ 10' ),
			'word'         => array( 'una variable(' ),
			'html'         => array( '<p>Hola <strong>mundo</strong> &amp; amigos &#38; &euro;</p>' ),
			'percent'      => array( '100% seguro, 50%24' ),
			'at sign'      => array( 'escribe a me@ex.test @ET' ),
			'url'          => array( 'https://ex.test/a?b=c&d=e' ),
		);
	}

	/**
	 * @dataProvider innocent
	 */
	public function test_normal_text_is_not_flagged_and_keeps_being_written( string $value ): void {
		$this->assertFalse( DiviTreeValidator::has_dynamic_token( $value ) );
		$result = $this->validator()->validate_patch( 'divi/text', array( 'content' => array( 'content' => array( 'innerContent' => self::v( $value ) ) ) ) );

		$this->assertIsArray( $result, $value );
	}

	public function test_normal_links_images_and_presets_still_validate_unchanged(): void {
		$validator = $this->validator();

		$button = $validator->validate_patch( 'divi/button', array( 'content' => array( 'button' => array( 'innerContent' => self::v( array( 'text' => 'Comprar', 'linkUrl' => 'https://ex.test/tienda?a=1&b=2' ) ) ) ) ) );
		$this->assertSame( array( 'text' => 'Comprar', 'linkUrl' => 'https://ex.test/tienda?a=1&b=2' ), $button['content']['button']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line

		$image = $validator->validate_patch( 'divi/image', array( 'content' => array( 'image' => array( 'innerContent' => self::v( array( 'src' => 7, 'alt' => 'Logo' ) ) ) ) ) );
		$this->assertSame( array( 'src' => 'https://example.test/wp-content/uploads/a.jpg', 'alt' => 'Logo' ), $image['content']['image']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line

		$preset = $validator->validate_preset_attrs( 'divi/text', array( 'module' => array( 'decoration' => array( 'background' => self::v( array( 'color' => '#abcdef' ) ) ) ) ) );
		$this->assertSame( '#abcdef', $preset['module']['decoration']['background']['desktop']['value']['color'] ); // @phpstan-ignore-line

		$rich = $validator->validate_patch( 'divi/text', array( 'content' => array( 'content' => array( 'innerContent' => self::v( '<p>Hola <b>mundo</b></p>' ) ) ) ) );
		$this->assertSame( '<p>Hola <b>mundo</b></p>', $rich['content']['content']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
	}

	/**
	 * Un bucle de consulta (`module.advanced.loop`) y cualquier atributo de bucle no se pueden escribir.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function loops(): array {
		$loop = array( 'enable' => 'on', 'queryType' => 'post_types', 'subTypes' => array(), 'postPerPage' => '100000' );

		return array(
			'module.advanced.loop'      => array( array( 'module' => array( 'advanced' => array( 'loop' => self::v( $loop ) ) ) ) ),
			'loop enable only'          => array( array( 'module' => array( 'advanced' => array( 'loop' => self::v( array( 'enable' => 'on' ) ) ) ) ) ),
			'loop beside advanced'      => array( array( 'module' => array( 'loop' => self::v( $loop ) ) ) ),
			'loop at the root'          => array( array( 'loop' => self::v( $loop ) ) ),
			'loop with tablet'          => array( array( 'module' => array( 'advanced' => array( 'loop' => array( 'tablet' => array( 'value' => $loop ) ) ) ) ) ),
		);
	}

	/**
	 * @dataProvider loops
	 * @param array<string, mixed> $attrs Atributos.
	 */
	public function test_loop_attributes_are_not_writable_anywhere( array $attrs ): void {
		$validator = $this->validator();

		foreach ( array( 'divi/section', 'divi/text', 'divi/heading', 'divi/button', 'divi/image' ) as $type ) {
			$tree = 'divi/section' === $type
				? array( array( 'type' => 'divi/section', 'attrs' => $attrs ) )
				: $this->tree( $type, $attrs );
			$result = $validator->validate_tree( $tree );

			$this->assertInstanceOf( \WP_Error::class, $result, $type );
			$this->assertSame( 'nexo_mcp_divi_attr_denied', $result->get_error_code(), $type );

			foreach ( array( 'content', 'design' ) as $slot ) {
				$patch = $validator->validate_patch( $type, array( $slot => $attrs ) );
				$this->assertInstanceOf( \WP_Error::class, $patch, $type . $slot );
				$this->assertSame( 'nexo_mcp_divi_attr_denied', $patch->get_error_code(), $type . $slot );
			}

			$preset = $validator->validate_preset_attrs( $type, $attrs );
			$this->assertInstanceOf( \WP_Error::class, $preset, $type );
			$this->assertSame( 'nexo_mcp_divi_attr_denied', $preset->get_error_code(), $type );
		}
	}
}
