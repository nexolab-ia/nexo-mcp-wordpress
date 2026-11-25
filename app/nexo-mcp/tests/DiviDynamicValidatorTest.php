<?php
/**
 * DiviTreeValidator con contexto «permitir dinámico» (T4 de D5): un token de la lista cerrada, bien formado y en un
 * campo de su categoría, se acepta y se guarda re-serializado por el plugin; sin el contexto todo sigue como en 1.9.1.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use PHPUnit\Framework\TestCase;

final class DiviDynamicValidatorTest extends TestCase {

	private const PAGE    = 10;
	private const PRODUCT = 12;

	private const TITLE = '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$';
	private const OUT   = '$variable({"type":"content","value":{"name":"post_title","settings":{"enable_html":"off"}}})$';
	private const URL   = '$variable({"type":"content","value":{"name":"post_link_url","settings":{}}})$';
	private const IMAGE = '$variable({"type":"content","value":{"name":"site_logo","settings":{}}})$';

	private function catalog( bool $active = true ): DiviDynamicCatalog {
		$names = array( 'post_title', 'post_excerpt', 'post_date', 'post_link', 'post_author', 'site_title', 'site_tagline', 'current_date', 'post_link_url', 'home_url', 'post_featured_image', 'site_logo', 'post_meta_key', 'loop_post_title', 'loop_user_email' );

		return new DiviDynamicCatalog(
			static fn(): bool => $active,
			static function ( int $post_id ) use ( $names ): array {
				$all = self::PRODUCT === $post_id ? array_merge( $names, array( 'product_price' ) ) : $names;
				$url = array( 'post_link_url' => 'url', 'home_url' => 'url', 'post_featured_image' => 'image', 'site_logo' => 'image' );

				return array_combine( $all, array_map( static fn( string $n ): array => array( 'type' => $url[ $n ] ?? 'text' ), $all ) );
			},
			static fn(): array => array( array( 'name' => 'nexo_prueba_texto', 'label' => 'Texto', 'type' => 'text', 'group' => 'G' ) ),
			static fn( int $post_id ): string => self::PRODUCT === $post_id ? 'product' : 'page',
			static fn(): array => array()
		);
	}

	private function base(): DiviTreeValidator {
		return new DiviTreeValidator(
			static fn( string $name ): bool => in_array( $name, array( 'gallery', 'caption' ), true ),
			static fn( int $id ) => 7 === $id ? 'https://example.test/wp-content/uploads/a.jpg' : false,
			static fn(): array => array(),
			static fn(): array => array()
		);
	}

	private function allowing( int $post_id = self::PAGE, bool $active = true ): DiviTreeValidator {
		return $this->base()->with_dynamic( $this->catalog( $active ), $post_id );
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

	private const BASE = 'nodes[0].children[0].children[0].children[0].attrs.';

	private static function v( $value ): array {
		return array( 'desktop' => array( 'value' => $value ) );
	}

	/**
	 * @return mixed
	 */
	private function leaf( array $tree, string $dotted ) {
		$node = $tree[0]['children'][0]['children'][0]['children'][0]['attrs'];

		foreach ( explode( '.', $dotted ) as $key ) {
			$node = $node[ $key ];
		}

		return $node;
	}

	private function assertFails( string $code, string $path, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data( $code ) );
	}

	public function test_a_text_token_is_accepted_in_a_heading_and_text_module_and_stored_canonical(): void {
		$result = $this->allowing()->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::OUT, $this->leaf( $result, 'title.innerContent.desktop.value' ) );

		$result = $this->allowing()->validate_tree( $this->tree( 'divi/text', array( 'content' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( self::OUT, $this->leaf( $result, 'content.innerContent.desktop.value' ) );
	}

	public function test_button_text_url_and_image_src_alt_take_a_token_of_their_category(): void {
		$button = $this->allowing()->validate_tree(
			$this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( 'text' => self::TITLE, 'linkUrl' => self::URL ) ) ) ) )
		);
		$this->assertIsArray( $button );
		$this->assertSame( array( 'text' => self::OUT, 'linkUrl' => self::URL ), $this->leaf( $button, 'button.innerContent.desktop.value' ) );

		$image = $this->allowing()->validate_tree(
			$this->tree( 'divi/image', array( 'image' => array( 'innerContent' => self::v( array( 'src' => self::IMAGE, 'alt' => self::TITLE, 'linkUrl' => self::URL ) ) ) ) )
		);
		$this->assertIsArray( $image );
		$this->assertSame( array( 'src' => self::IMAGE, 'alt' => self::OUT, 'linkUrl' => self::URL ), $this->leaf( $image, 'image.innerContent.desktop.value' ) );
	}

	public function test_a_patch_accepts_a_token_and_stores_the_canonical_form_not_the_clients_string(): void {
		$client = '$variable({"value":{"settings":{"before":"Por: "},"name":"post_title"},"type":"content"})$';
		$patch  = $this->allowing()->validate_patch( 'divi/heading', array( 'content' => array( 'title' => array( 'innerContent' => self::v( $client ) ) ) ) );

		$this->assertSame(
			array( 'content' => array( 'title' => array( 'innerContent' => self::v( '$variable({"type":"content","value":{"name":"post_title","settings":{"before":"Por: ","enable_html":"off"}}})$' ) ) ) ),
			$patch
		);
	}

	public function test_ordinary_values_are_still_sanitized_with_the_context_on(): void {
		$tree = $this->allowing()->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( 'Hola <b>mundo</b>' ) ) ) ) );

		$this->assertSame( 'Hola mundo', $this->leaf( $tree, 'title.innerContent.desktop.value' ) );
		$this->assertFails(
			'nexo_mcp_divi_shortcode_denied',
			self::BASE . 'title.innerContent.desktop.value',
			$this->allowing()->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( '[gallery]' ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_value_invalid',
			self::BASE . 'button.innerContent.desktop.value.linkUrl',
			$this->allowing()->validate_tree( $this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( 'linkUrl' => 'javascript:alert(1)' ) ) ) ) ) )
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function denied(): array {
		$wrap = static fn( string $name, string $settings = '{}' ): string => '$variable({"type":"content","value":{"name":"' . $name . '","settings":' . $settings . '}})$';

		return array(
			'mixed before'       => array( 'denied', 'Hola ' . self::TITLE ),
			'mixed after'        => array( 'denied', self::TITLE . ' adiós' ),
			'two tokens'         => array( 'denied', self::TITLE . self::TITLE ),
			'inside html'        => array( 'denied', '<b>' . self::TITLE . '</b>' ),
			'unknown option'     => array( 'denied', $wrap( 'bogus' ) ),
			'meta key'           => array( 'denied', $wrap( 'post_meta_key', '{"meta_key":"_secret"}' ) ),
			'custom meta secret' => array( 'denied', $wrap( 'custom_meta__edit_last' ) ),
			'acf not in groups'  => array( 'denied', $wrap( 'custom_meta_otro' ) ),
			'loop source'        => array( 'denied', $wrap( 'loop_user_email' ) ),
			'loop post title'    => array( 'denied', $wrap( 'loop_post_title' ) ),
			'global variable'    => array( 'denied', $wrap( 'gvid-aaaaaaaaaa' ) ),
			'product on a page'  => array( 'denied', $wrap( 'product_price' ) ),
			'url in a text'      => array( 'denied', self::URL ),
			'image in a text'    => array( 'denied', self::IMAGE ),
			'color token'        => array( 'denied', '$variable({"type":"color","value":{"name":"gcid-aaaaaaaaaa","settings":{}}})$' ),
			'uppercase'          => array( 'denied', '$VARIABLE({"type":"content","value":{"name":"post_title","settings":{}}})$' ),
			'unicode dollar'     => array( 'denied', '\u0024variable({"type":"content","value":{"name":"post_title","settings":{}}})$' ),
			'entity dollar'      => array( 'denied', '&#36;variable({"type":"content","value":{"name":"post_title","settings":{}}})$' ),
			'url encoded'        => array( 'denied', '%24variable({"type":"content","value":{"name":"post_title","settings":{}}})$' ),
			'tag between'        => array( 'denied', '$<b></b>variable({"type":"content","value":{"name":"post_title","settings":{}}})$' ),
			'zero width'         => array( 'denied', "\$\u{200B}variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{}}})\$" ),
			'legacy marker'      => array( 'denied', '@ET-DC@eyJkeW5hbWljIjp0cnVlfQ==@' ),
			'enable_html on'     => array( 'invalid', $wrap( 'post_title', '{"enable_html":"on"}' ) ),
			'unknown setting'    => array( 'invalid', $wrap( 'post_title', '{"fallback":"x"}' ) ),
			'shortcode before'   => array( 'invalid', $wrap( 'post_title', '{"before":"[caption]x[/caption]"}' ) ),
			'html in after'      => array( 'invalid', $wrap( 'post_title', '{"after":"<img src=x onerror=1>"}' ) ),
			'bad enum'           => array( 'invalid', $wrap( 'post_date', '{"date_format":"x"}' ) ),
		);
	}

	/**
	 * @dataProvider denied
	 */
	public function test_invalid_tokens_are_rejected_in_a_text_field_with_their_path( string $kind, string $value ): void {
		$code = 'nexo_mcp_divi_dynamic_' . $kind;

		$this->assertFails(
			$code,
			self::BASE . 'title.innerContent.desktop.value',
			$this->allowing()->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( $value ) ) ) ) )
		);
		$this->assertFails(
			$code,
			'content.title.innerContent.desktop.value',
			$this->allowing()->validate_patch( 'divi/heading', array( 'content' => array( 'title' => array( 'innerContent' => self::v( $value ) ) ) ) )
		);
	}

	public function test_a_token_in_the_wrong_category_of_field_is_denied_with_the_field_path(): void {
		$validator = $this->allowing();

		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'button.innerContent.desktop.value.linkUrl',
			$validator->validate_tree( $this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( 'linkUrl' => self::TITLE ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'button.innerContent.desktop.value.text',
			$validator->validate_tree( $this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( 'text' => self::URL ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'image.innerContent.desktop.value.src',
			$validator->validate_tree( $this->tree( 'divi/image', array( 'image' => array( 'innerContent' => self::v( array( 'src' => self::URL ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'image.innerContent.desktop.value.alt',
			$validator->validate_tree( $this->tree( 'divi/image', array( 'image' => array( 'innerContent' => self::v( array( 'alt' => self::IMAGE ) ) ) ) ) )
		);
	}

	public function test_a_token_in_fields_that_are_not_text_url_or_image_content_is_denied(): void {
		$validator = $this->allowing();

		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'module.meta.adminLabel.desktop.value',
			$validator->validate_tree( $this->tree( 'divi/heading', array( 'module' => array( 'meta' => array( 'adminLabel' => self::v( self::TITLE ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'title.decoration.font.font.desktop.value.color',
			$validator->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::v( array( 'color' => self::TITLE ) ) ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'title.decoration.font.font.desktop.value.headingLevel',
			$validator->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::v( array( 'headingLevel' => self::TITLE ) ) ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'video.innerContent.desktop.value.src',
			$validator->validate_tree( $this->tree( 'divi/video', array( 'video' => array( 'innerContent' => self::v( array( 'src' => self::URL ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'module.decoration.background.desktop.value.color',
			$validator->validate_tree( $this->tree( 'divi/heading', array( 'module' => array( 'decoration' => array( 'background' => self::v( array( 'color' => '$variable({"type":"color","value":{"name":"gcid-aaaaaaaaaa","settings":{}}})$' ) ) ) ) ) ) )
		);
	}

	public function test_woocommerce_options_need_a_product_target(): void {
		$tree  = $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( '$variable({"type":"content","value":{"name":"product_price","settings":{}}})$' ) ) ) );
		$page  = $this->allowing( self::PAGE )->validate_tree( $tree );
		$store = $this->allowing( self::PRODUCT )->validate_tree( $tree );

		$this->assertFails( 'nexo_mcp_divi_dynamic_denied', self::BASE . 'title.innerContent.desktop.value', $page );
		$this->assertIsArray( $store );
		$this->assertStringContainsString( '"name":"product_price"', $this->leaf( $store, 'title.innerContent.desktop.value' ) );
	}

	public function test_acf_text_field_of_the_post_is_accepted_and_nothing_else_of_custom_meta(): void {
		$ok = '$variable({"type":"content","value":{"name":"custom_meta_nexo_prueba_texto","settings":{}}})$';

		$tree = $this->allowing()->validate_tree( $this->tree( 'divi/text', array( 'content' => array( 'innerContent' => self::v( $ok ) ) ) ) );
		$this->assertIsArray( $tree );
		$this->assertStringContainsString( '"name":"custom_meta_nexo_prueba_texto"', $this->leaf( $tree, 'content.innerContent.desktop.value' ) );
	}

	public function test_without_divi_5_a_token_is_unavailable_with_its_path(): void {
		$this->assertFails(
			'nexo_mcp_divi_dynamic_unavailable',
			self::BASE . 'title.innerContent.desktop.value',
			$this->allowing( self::PAGE, false )->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) )
		);
	}

	public function test_presets_never_take_a_token_even_with_the_context(): void {
		$attrs = array( 'module' => array( 'decoration' => array( 'background' => self::v( array( 'color' => self::TITLE ) ) ) ) );

		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			'attrs.module.decoration.background.desktop.value.color',
			$this->allowing()->validate_preset_attrs( 'divi/heading', $attrs )
		);
		$this->assertStringContainsString( 'no está habilitado', $this->allowing()->validate_preset_attrs( 'divi/heading', $attrs )->get_error_message() );
	}

	public function test_without_the_context_the_validator_stays_closed_exactly_as_in_1_9_1(): void {
		$base = $this->base();

		foreach ( array( self::TITLE, self::OUT, self::URL, self::IMAGE ) as $value ) {
			$result = $base->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( $value ) ) ) ) );

			$this->assertFails( 'nexo_mcp_divi_dynamic_denied', self::BASE . 'title.innerContent.desktop.value', $result );
			$this->assertStringContainsString( 'no está habilitado', $result->get_error_message() );
		}

		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'button.innerContent.desktop.value.linkUrl',
			$base->validate_tree( $this->tree( 'divi/button', array( 'button' => array( 'innerContent' => self::v( array( 'linkUrl' => self::URL ) ) ) ) ) )
		);
		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			'content.title.innerContent.desktop.value',
			$base->validate_patch( 'divi/heading', array( 'content' => array( 'title' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) )
		);
		$this->assertTrue( DiviTreeValidator::has_dynamic_token( self::TITLE ) );
	}

	public function test_with_dynamic_returns_a_new_validator_and_leaves_the_original_closed(): void {
		$base    = $this->base();
		$allowed = $base->with_dynamic( $this->catalog(), self::PAGE );
		$tree    = $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( self::TITLE ) ) ) );

		$this->assertNotSame( $base, $allowed );
		$this->assertIsArray( $allowed->validate_tree( $tree ) );
		$this->assertInstanceOf( \WP_Error::class, $base->validate_tree( $tree ) );
		$this->assertIsArray( $allowed->validate_tree( $tree ), 'la segunda llamada con contexto sigue valiendo' );
	}

	public function test_a_dynamic_error_does_not_leave_the_validator_in_token_mode(): void {
		$allowed = $this->allowing();
		$tree    = $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( 'Hola ' . self::TITLE ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $allowed->validate_tree( $tree ) );

		// Un texto con HTML se sanea como siempre (no queda en modo token) y los campos que no admiten token siguen cerrados.
		$clean = $allowed->validate_tree( $this->tree( 'divi/heading', array( 'title' => array( 'innerContent' => self::v( 'Hola <b onclick="x()">mundo</b><script>alert(1)</script>' ) ) ) ) );
		$this->assertIsArray( $clean );
		$this->assertSame( 'Hola mundo', $this->leaf( $clean, 'title.innerContent.desktop.value' ) );

		$patch = $allowed->validate_patch( 'divi/text', array( 'content' => array( 'content' => array( 'innerContent' => self::v( '<p>Hola <b>mundo</b> <u>x</u></p><iframe src="x"></iframe>' ) ) ) ) );
		$this->assertIsArray( $patch );
		$this->assertSame( '<p>Hola <b>mundo</b> x</p>', $patch['content']['content']['innerContent']['desktop']['value'] );

		$this->assertFails(
			'nexo_mcp_divi_dynamic_denied',
			self::BASE . 'module.meta.adminLabel.desktop.value',
			$allowed->validate_tree( $this->tree( 'divi/heading', array( 'module' => array( 'meta' => array( 'adminLabel' => self::v( self::TITLE ) ) ) ) ) )
		);
	}

	public function test_a_token_in_the_items_of_an_icon_list_is_accepted_per_item_and_a_text_around_it_is_denied(): void {
		$list = static fn( array $item_attrs ): array => array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array(
									array(
										'type'     => 'divi/icon-list',
										'children' => array(
											array( 'type' => 'divi/icon-list-item', 'attrs' => array( 'content' => array( 'innerContent' => self::v( 'Uno' ) ) ) ),
											array( 'type' => 'divi/icon-list-item', 'attrs' => $item_attrs ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
		$path = 'nodes[0].children[0].children[0].children[0].children[1].attrs.content.innerContent.desktop.value';

		$ok = $this->allowing()->validate_tree( $list( array( 'content' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) );
		$this->assertIsArray( $ok );
		$this->assertSame( self::OUT, $ok[0]['children'][0]['children'][0]['children'][0]['children'][1]['attrs']['content']['innerContent']['desktop']['value'] );
		$this->assertSame( 'Uno', $ok[0]['children'][0]['children'][0]['children'][0]['children'][0]['attrs']['content']['innerContent']['desktop']['value'] );

		$this->assertFails( 'nexo_mcp_divi_dynamic_denied', $path, $this->allowing()->validate_tree( $list( array( 'content' => array( 'innerContent' => self::v( 'Hola ' . self::TITLE ) ) ) ) ) );
		$this->assertFails( 'nexo_mcp_divi_dynamic_denied', $path, $this->allowing()->validate_tree( $list( array( 'content' => array( 'innerContent' => self::v( self::URL ) ) ) ) ) );
		$this->assertFails( 'nexo_mcp_divi_dynamic_denied', $path, $this->base()->validate_tree( $list( array( 'content' => array( 'innerContent' => self::v( self::TITLE ) ) ) ) ) );
	}

	public function test_the_stray_token_scan_accepts_only_the_listed_strings_at_their_paths_in_any_shape(): void {
		$canonical = array( 'a.desktop.value' => self::OUT );

		$this->assertNull( DiviTreeValidator::stray_token_path( array( 'a' => array( 'desktop' => array( 'value' => self::OUT ) ), 'b' => 'texto' ), '', $canonical ) );
		$this->assertSame( 'a.desktop.value', DiviTreeValidator::stray_token_path( array( 'a' => array( 'desktop' => array( 'value' => self::TITLE ) ) ), '', $canonical ), 'otra forma del mismo token no es la emitida' );
		$this->assertSame( 'c.desktop.value', DiviTreeValidator::stray_token_path( array( 'c' => array( 'desktop' => array( 'value' => self::OUT ) ) ), '', $canonical ), 'la cadena emitida en otra ruta' );
		$this->assertSame( 'x.items.1.title', DiviTreeValidator::stray_token_path( array( 'x' => array( 'items' => array( array( 'title' => 'ok' ), array( 'title' => self::OUT ) ) ) ), '', array() ), 'dentro de una lista anidada' );
		$this->assertSame( 'p.x.items.0', DiviTreeValidator::stray_token_path( array( 'x' => array( 'items' => array( '&#36;variable({})$' ) ) ), 'p', array() ), 'codificado' );
		$this->assertNull( DiviTreeValidator::stray_token_path( 'sin token', 'p', array() ) );
		$this->assertSame( array( 'a.b' => self::OUT, 'c.0' => self::URL ), DiviTreeValidator::token_strings( array( 'a' => array( 'b' => self::OUT ), 'c' => array( self::URL ), 'd' => 'no' ), '' ) );
	}
}
