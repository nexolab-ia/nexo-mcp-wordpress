<?php
/**
 * Fuentes `loop_*` del catálogo de contenido dinámico (T6 de D6): espejo de S6 por elemento del bucle, sólo con el
 * tipo del bucle del conector y nunca usuarios, términos ni meta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;

final class DiviDynamicLoopSourcesTest extends NexoTestCase {

	private const PAGE = 100;

	/**
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $registered = null;

	private function catalog(): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => true,
			function (): array {
				if ( null !== $this->registered ) {
					return $this->registered;
				}

				$names = array(
					'post_title', 'loop_post_title', 'loop_post_excerpt', 'loop_post_date', 'loop_post_author', 'loop_post_terms', 'loop_post_link', 'loop_post_featured_image',
					'loop_product_title', 'loop_product_price_current', 'loop_product_sku', 'loop_product_short_description',
					'loop_user_email', 'loop_user_name', 'loop_term_name', 'loop_post_meta_key_manual_custom_field', 'loop_post_author_bio', 'loop_post_id', 'loop_post_modified_date',
				);
				$types = array( 'loop_post_link' => 'url', 'loop_post_featured_image' => 'image', 'loop_post_meta_key_manual_custom_field' => 'any' );

				return array_combine( $names, array_map( static fn( string $n ): array => array( 'type' => $types[ $n ] ?? 'text' ), $names ) );
			},
			static fn(): array => array(),
			static fn(): string => 'page',
			static fn( string $type ): array => array( 'post' => array( 'category', 'post_tag' ), 'product' => array( 'product_cat', 'product_tag' ), 'page' => array() )[ $type ] ?? array()
		);
	}

	private function token( string $name, string $settings = '{}' ): string {
		return '$variable({"type":"content","value":{"name":"' . $name . '","settings":' . $settings . '}})$';
	}

	private function assertError( string $code, $result, string $needle = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );

		if ( '' !== $needle ) {
			$this->assertStringContainsString( $needle, $result->get_error_message() );
		}
	}

	public function test_the_loop_allowlist_mirrors_s6_by_item(): void {
		$this->assertSame(
			array(
				'loop_post_title',
				'loop_post_excerpt',
				'loop_post_date',
				'loop_post_author',
				'loop_post_terms',
				'loop_post_link',
				'loop_post_featured_image',
				'loop_product_title',
				'loop_product_price_current',
				'loop_product_sku',
				'loop_product_short_description',
			),
			DiviDynamicCatalog::loop_names()
		);

		foreach ( DiviDynamicCatalog::loop_names() as $name ) {
			$this->assertNotContains( $name, DiviDynamicCatalog::names(), 'las fuentes del bucle no se mezclan con la lista de S6' );
		}

		foreach ( array( 'loop_user_email', 'loop_user_username', 'loop_user_name', 'loop_term_name', 'loop_term_count', 'loop_post_meta_key_manual_custom_field', 'loop_post_author_bio', 'loop_post_id', 'loop_menu_text', 'loop_post_modified_date', 'loop_post_author_profile_picture' ) as $out ) {
			$this->assertNotContains( $out, DiviDynamicCatalog::loop_names(), $out );
		}
	}

	public function test_a_loop_token_outside_a_loop_is_denied_with_a_message_that_says_why(): void {
		$result = $this->catalog()->validate_token( $this->token( 'loop_post_title' ), 'text', self::PAGE );

		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $result, 'bucle' );
	}

	public function test_inside_a_post_loop_the_post_sources_return_their_canonical_token(): void {
		$catalog = $this->catalog();

		$this->assertSame( $this->token( 'loop_post_title', '{"enable_html":"off"}' ), $catalog->validate_token( $this->token( 'loop_post_title' ), 'text', self::PAGE, 'post' ) );
		$this->assertSame(
			$this->token( 'loop_post_excerpt', '{"before":"Resumen: ","words":"20","enable_html":"off"}' ),
			$catalog->validate_token( $this->token( 'loop_post_excerpt', '{"words":20,"before":"Resumen: "}' ), 'text', self::PAGE, 'post' )
		);
		$this->assertSame( $this->token( 'loop_post_link', '{}' ), $catalog->validate_token( $this->token( 'loop_post_link' ), 'url', self::PAGE, 'post' ) );
		$this->assertSame( $this->token( 'loop_post_featured_image', '{}' ), $catalog->validate_token( $this->token( 'loop_post_featured_image' ), 'image', self::PAGE, 'page' ) );
		$this->assertSame( $this->token( 'loop_post_date', '{"date_format":"m/d/Y","enable_html":"off"}' ), $catalog->validate_token( $this->token( 'loop_post_date', '{"date_format":"m/d/Y"}' ), 'text', self::PAGE, 'post' ) );
	}

	public function test_the_item_taxonomy_comes_from_the_loop_type_with_its_default_filled_in(): void {
		$catalog = $this->catalog();

		$this->assertSame(
			$this->token( 'loop_post_terms', '{"taxonomy_type":"category","enable_html":"off"}' ),
			$catalog->validate_token( $this->token( 'loop_post_terms' ), 'text', self::PAGE, 'post' )
		);
		$this->assertSame(
			$this->token( 'loop_post_terms', '{"taxonomy_type":"product_cat","enable_html":"off"}' ),
			$catalog->validate_token( $this->token( 'loop_post_terms' ), 'text', self::PAGE, 'product' )
		);
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_terms', '{"taxonomy_type":"product_cat"}' ), 'text', self::PAGE, 'post' ), 'taxonomy_type' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'loop_post_terms' ), 'text', self::PAGE, 'page' ), 'taxonomías' );
	}

	public function test_woocommerce_item_sources_need_a_product_loop(): void {
		$catalog = $this->catalog();

		foreach ( array( 'loop_product_title', 'loop_product_price_current', 'loop_product_sku', 'loop_product_short_description' ) as $name ) {
			$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( $name ), 'text', self::PAGE, 'post' ), 'producto' );
			$this->assertSame( $this->token( $name, '{"enable_html":"off"}' ), $catalog->validate_token( $this->token( $name ), 'text', self::PAGE, 'product' ), $name );
		}
	}

	public function test_user_term_menu_and_meta_loop_sources_are_never_valid_even_with_a_loop(): void {
		$catalog = $this->catalog();

		foreach ( array( 'loop_user_email', 'loop_user_name', 'loop_term_name', 'loop_post_meta_key_manual_custom_field', 'loop_post_author_bio', 'loop_post_id', 'loop_menu_text', 'loop_nada' ) as $name ) {
			foreach ( array( 'text', 'url', 'image' ) as $category ) {
				$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( $name ), $category, self::PAGE, 'post' ) );
			}
		}
	}

	public function test_the_category_of_the_field_must_match_the_source(): void {
		$catalog = $this->catalog();

		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'loop_post_link' ), 'text', self::PAGE, 'post' ), 'categoría' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'loop_post_title' ), 'url', self::PAGE, 'post' ), 'categoría' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'loop_post_featured_image' ), 'text', self::PAGE, 'post' ), 'categoría' );
	}

	public function test_settings_are_as_closed_as_the_page_sources(): void {
		$catalog = $this->catalog();

		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_title', '{"enable_html":"on"}' ), 'text', self::PAGE, 'post' ), 'enable_html' );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_title', '{"loop_position":"1"}' ), 'text', self::PAGE, 'post' ), 'loop_position' );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_author', '{"name_format":"username"}' ), 'text', self::PAGE, 'post' ), 'name_format' );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_title', '{"before":"[caption]x[/caption]"}' ), 'text', self::PAGE, 'post' ), 'before' );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'loop_post_featured_image', '{"thumbnail_size":"full"}' ), 'image', self::PAGE, 'post' ) );
		$this->assertSame(
			$this->token( 'loop_post_author', '{"name_format":"first_name","link":"on","link_destination":"author_archive","enable_html":"off"}' ),
			$catalog->validate_token( $this->token( 'loop_post_author', '{"name_format":"first_name","link":"on"}' ), 'text', self::PAGE, 'post' )
		);
	}

	public function test_divi_must_register_the_option_for_the_page(): void {
		$this->registered = array( 'post_title' => array( 'type' => 'text' ) );

		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $this->catalog()->validate_token( $this->token( 'loop_post_title' ), 'text', self::PAGE, 'post' ), 'Divi no registra' );

		$this->registered = array( 'loop_post_title' => array( 'type' => 'url' ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $this->catalog()->validate_token( $this->token( 'loop_post_title' ), 'text', self::PAGE, 'post' ), 'tipo' );
	}

	public function test_a_loop_type_does_not_change_how_the_page_sources_validate(): void {
		$catalog = $this->catalog();

		$this->assertSame( $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ), $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE, 'post' ) );
		$this->assertSame( $this->token( 'post_title', '{"enable_html":"off"}' ), $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ) );
	}

	public function test_loop_listing_publishes_the_closed_list_with_where_it_applies(): void {
		$listing = $this->catalog()->loop_listing( null );

		$this->assertIsArray( $listing );
		$this->assertSame( DiviDynamicCatalog::loop_names(), array_column( $listing, 'name' ) );

		$by = array_column( $listing, null, 'name' );
		$this->assertSame( 'loop', $by['loop_post_title']['requires'] );
		$this->assertSame( 'loop_product', $by['loop_product_sku']['requires'] );
		$this->assertSame( 'url', $by['loop_post_link']['category'] );
		$this->assertSame( 'image', $by['loop_post_featured_image']['category'] );
		$this->assertSame( 'divi-loop', $by['loop_post_title']['source'] );
		$this->assertSame( array( 'enable_html' => 'off' ), $by['loop_post_title']['forced'] );
		$this->assertSame( array(), $by['loop_post_link']['forced'] );
		$this->assertSame( array( 'before', 'after' ), array_keys( $by['loop_post_title']['settings'] ) );
		$this->assertSame( array( 'before', 'after', 'links', 'separator', 'taxonomy_type' ), array_keys( $by['loop_post_terms']['settings'] ) );
		$this->assertSame( array(), $by['loop_post_featured_image']['settings'] );
	}

	public function test_loop_listing_with_a_page_is_cut_to_what_divi_registers_and_propagates_errors(): void {
		$this->registered = array( 'loop_post_title' => array( 'type' => 'text' ) );
		$this->assertSame( array( 'loop_post_title' ), array_column( (array) $this->catalog()->loop_listing( self::PAGE ), 'name' ) );

		$off = new DiviDynamicCatalog( static fn(): bool => false );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $off->loop_listing( null ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $off->validate_token( $this->token( 'loop_post_title' ), 'text', self::PAGE, 'post' ) );

		$broken = new DiviDynamicCatalog( static fn(): bool => true, static function (): array {
			throw new \RuntimeException( 'x' );
		} );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $broken->loop_listing( self::PAGE ) );
	}

	public function test_single_token_and_loop_token_helpers(): void {
		$this->assertTrue( DiviDynamicCatalog::is_single_token( $this->token( 'post_title' ) ) );
		$this->assertTrue( DiviDynamicCatalog::is_single_token( '$variable({"type":"content", "value":{"name":"loop_user_email","settings":{}}})$' ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( 'Hola ' . $this->token( 'post_title' ) ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( $this->token( 'post_title' ) . ' adiós' ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( $this->token( 'post_title' ) . $this->token( 'site_title' ) ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( '<p>' . $this->token( 'post_title' ) . '</p>' ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( 'texto' ) );
		$this->assertFalse( DiviDynamicCatalog::is_single_token( '' ) );

		$this->assertSame( array( 'loop_post_title', 'loop_user_email' ), DiviDynamicCatalog::loop_token_names( $this->token( 'loop_post_title' ) . 'x' . $this->token( 'loop_user_email' ) ) );
		$this->assertSame( array(), DiviDynamicCatalog::loop_token_names( $this->token( 'post_title' ) ) );
		$this->assertSame( array(), DiviDynamicCatalog::loop_token_names( 'loop_post_title sin token' ) );
		$this->assertTrue( DiviDynamicCatalog::loop_allows( 'loop_post_title', 'page' ) );
		$this->assertFalse( DiviDynamicCatalog::loop_allows( 'loop_product_sku', 'page' ) );
		$this->assertTrue( DiviDynamicCatalog::loop_allows( 'loop_product_sku', 'product' ) );
		$this->assertFalse( DiviDynamicCatalog::loop_allows( 'loop_user_email', 'post' ) );
	}
}
