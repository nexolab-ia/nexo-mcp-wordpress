<?php
/**
 * DiviDynamicCatalog: lista cerrada de fuentes de contenido dinámico de Divi 5 (S6), esquema de ajustes y token canónico.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;
use PHPUnit\Framework\TestCase;

final class DiviDynamicCatalogTest extends TestCase {

	public const PAGE    = 10;
	public const POST    = 11;
	public const PRODUCT = 12;

	/**
	 * Lo que Divi registra para una página (`DynamicContentOptions::get_options`): incluye fuentes fuera de la lista.
	 *
	 * @var array<int, string>
	 */
	private const BASE = array(
		'post_title',
		'post_excerpt',
		'post_date',
		'post_modified_date',
		'post_comment_count',
		'post_link',
		'post_author',
		'post_author_bio',
		'site_title',
		'site_tagline',
		'current_date',
		'post_link_url',
		'post_author_url',
		'home_url',
		'post_link_url_post',
		'post_featured_image',
		'post_author_profile_picture',
		'site_logo',
		'post_meta_key',
		'loop_post_title',
		'loop_user_email',
	);

	/**
	 * @var array<int, array{name: string, label: string, type: string, group: string}>
	 */
	private array $acf = array();

	protected function setUp(): void {
		parent::setUp();
		$this->acf = array(
			array( 'name' => 'nexo_prueba_texto', 'label' => 'Texto de prueba', 'type' => 'text', 'group' => 'Grupo A' ),
			array( 'name' => 'resumen', 'label' => 'Resumen', 'type' => 'textarea', 'group' => 'Grupo A' ),
			array( 'name' => 'edad', 'label' => 'Edad', 'type' => 'number', 'group' => 'Grupo A' ),
			array( 'name' => 'correo', 'label' => 'Correo', 'type' => 'email', 'group' => 'Grupo A' ),
			array( 'name' => 'enlace', 'label' => 'Enlace', 'type' => 'url', 'group' => 'Grupo A' ),
			array( 'name' => 'wysiwyg', 'label' => 'Editor', 'type' => 'wysiwyg', 'group' => 'Grupo A' ),
			array( 'name' => 'foto', 'label' => 'Foto', 'type' => 'image', 'group' => 'Grupo A' ),
			array( 'name' => 'clave', 'label' => 'Clave', 'type' => 'password', 'group' => 'Grupo A' ),
			array( 'name' => '_oculto', 'label' => 'Oculto', 'type' => 'text', 'group' => 'Grupo A' ),
			array( 'name' => 'con espacio', 'label' => 'Raro', 'type' => 'text', 'group' => 'Grupo A' ),
			array( 'name' => '', 'label' => 'Sin nombre', 'type' => 'text', 'group' => 'Grupo A' ),
		);
	}

	private function catalog( bool $active = true ): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => $active,
			static function ( int $post_id ): array {
				$names = self::BASE;

				if ( self::POST === $post_id || self::PRODUCT === $post_id ) {
					$names = array_merge( $names, array( 'post_categories', 'post_tags' ) );
				}

				if ( self::PRODUCT === $post_id ) {
					$names = array_merge( $names, array( 'product_price', 'product_sku', 'product_short_description', 'product_description', 'product_stock_quantity' ) );
				}

				$types = array( 'post_link_url' => 'url', 'post_author_url' => 'url', 'home_url' => 'url', 'post_featured_image' => 'image', 'post_author_profile_picture' => 'image', 'site_logo' => 'image' );

				return array_combine( $names, array_map( static fn( string $n ): array => array( 'type' => $types[ $n ] ?? 'text' ), $names ) );
			},
			fn( ?int $post_id ): array => $this->acf,
			static fn( int $post_id ): string => array( self::PAGE => 'page', self::POST => 'post', self::PRODUCT => 'product' )[ $post_id ] ?? '',
			static fn( string $type ): array => array( 'post' => array( 'category', 'post_tag' ), 'product' => array( 'product_cat', 'product_tag' ) )[ $type ] ?? array()
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function names( DiviDynamicCatalog $catalog, ?int $post_id ): array {
		$listing = $catalog->listing( $post_id );
		$this->assertIsArray( $listing );

		return array_column( $listing, 'name' );
	}

	private function token( string $name, string $settings = '{}' ): string {
		return '$variable({"type":"content","value":{"name":"' . $name . '","settings":' . $settings . '}})$';
	}

	private function assertError( string $code, $result, string $needle = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		if ( '' !== $needle ) {
			$this->assertStringContainsString( $needle, $result->get_error_message() );
		}
	}

	public function test_the_allowlist_is_exactly_the_s6_decision(): void {
		$this->assertSame(
			array(
				'post_title',
				'post_excerpt',
				'post_date',
				'post_categories',
				'post_tags',
				'post_link',
				'post_link_url',
				'post_author',
				'post_featured_image',
				'site_title',
				'site_tagline',
				'home_url',
				'site_logo',
				'current_date',
				'product_price',
				'product_sku',
				'product_short_description',
			),
			DiviDynamicCatalog::names()
		);

		foreach ( DiviDynamicCatalog::names() as $name ) {
			$this->assertStringStartsNotWith( 'loop_', $name );
			$this->assertStringStartsNotWith( 'custom_meta', $name );
		}

		foreach ( array( 'post_meta_key', 'post_author_url', 'post_author_bio', 'post_author_profile_picture', 'post_comment_count', 'post_modified_date', 'post_link_url_post' ) as $out ) {
			$this->assertNotContains( $out, DiviDynamicCatalog::names(), $out );
		}
	}

	public function test_each_source_has_a_category_and_url_and_image_sources_take_no_settings(): void {
		$expected = array(
			'post_link_url'       => 'url',
			'home_url'            => 'url',
			'post_featured_image' => 'image',
			'site_logo'           => 'image',
			'post_title'          => 'text',
			'product_price'       => 'text',
		);
		$by_name  = array();

		foreach ( $this->catalog()->listing( null ) as $source ) {
			$by_name[ $source['name'] ] = $source;
			$this->assertContains( $source['category'], array( 'text', 'url', 'image' ) );
			$this->assertNotSame( '', $source['label'] );
		}

		foreach ( $expected as $name => $category ) {
			$this->assertSame( $category, $by_name[ $name ]['category'], $name );
		}

		foreach ( array( 'post_link_url', 'home_url', 'post_featured_image', 'site_logo' ) as $name ) {
			$this->assertSame( array(), $by_name[ $name ]['settings'], $name );
		}

		$this->assertSame( array( 'before', 'after' ), array_keys( $by_name['post_title']['settings'] ) );
		$this->assertSame( array( 'before', 'after', 'words', 'read_more_label' ), array_keys( $by_name['post_excerpt']['settings'] ) );
		$this->assertSame( 'enum', $by_name['post_date']['settings']['date_format']['type'] );
		$this->assertContains( 'F d, Y', $by_name['post_date']['settings']['date_format']['options'] );
		$this->assertSame( 'product', $by_name['product_price']['requires'] );
		$this->assertArrayNotHasKey( 'enable_html', $by_name['post_title']['settings'] );
	}

	public function test_without_divi_5_the_listing_and_every_token_are_unavailable(): void {
		$off = $this->catalog( false );

		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $off->listing( null ), 'Divi 5' );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $off->listing( self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $off->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ) );
	}

	public function test_with_a_post_the_listing_is_cut_to_what_divi_registers_for_it(): void {
		$page = $this->names( $this->catalog(), self::PAGE );

		$this->assertContains( 'post_title', $page );
		$this->assertContains( 'site_logo', $page );
		$this->assertNotContains( 'post_categories', $page, 'la página no tiene taxonomías' );
		$this->assertNotContains( 'product_price', $page );
		$this->assertNotContains( 'post_meta_key', $page );

		$post = $this->names( $this->catalog(), self::POST );
		$this->assertContains( 'post_categories', $post );
		$this->assertContains( 'post_tags', $post );
		$this->assertNotContains( 'product_sku', $post );

		$product = $this->names( $this->catalog(), self::PRODUCT );
		$this->assertContains( 'product_price', $product );
		$this->assertContains( 'product_sku', $product );
		$this->assertContains( 'product_short_description', $product );
		$this->assertNotContains( 'product_description', $product, 'sólo la descripción corta' );
		$this->assertNotContains( 'product_stock_quantity', $product );
	}

	public function test_a_source_divi_registers_with_another_type_is_not_offered(): void {
		$catalog = new DiviDynamicCatalog(
			static fn(): bool => true,
			static fn( int $post_id ): array => array( 'post_title' => array( 'type' => 'text' ), 'site_logo' => array( 'type' => 'text' ), 'home_url' => array() ),
			static fn(): array => array(),
			static fn(): string => 'page',
			static fn(): array => array()
		);

		$this->assertSame( array( 'post_title', 'home_url' ), array_column( $catalog->listing( self::PAGE ), 'name' ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'site_logo' ), 'image', self::PAGE ) );
	}

	public function test_acf_lists_only_text_textarea_and_number_fields_with_a_safe_name(): void {
		$sources = array();

		foreach ( $this->catalog()->listing( self::PAGE ) as $source ) {
			$sources[ $source['name'] ] = $source;
		}

		foreach ( array( 'nexo_prueba_texto', 'resumen', 'edad' ) as $field ) {
			$this->assertArrayHasKey( 'custom_meta_' . $field, $sources, $field );
			$this->assertSame( 'text', $sources[ 'custom_meta_' . $field ]['category'] );
			$this->assertSame( 'acf', $sources[ 'custom_meta_' . $field ]['source'] );
			$this->assertSame( array( 'before', 'after' ), array_keys( $sources[ 'custom_meta_' . $field ]['settings'] ) );
		}

		$this->assertStringContainsString( 'Texto de prueba', $sources['custom_meta_nexo_prueba_texto']['label'] );

		foreach ( array( 'correo', 'enlace', 'wysiwyg', 'foto', 'clave', '_oculto', 'con espacio', '' ) as $field ) {
			$this->assertArrayNotHasKey( 'custom_meta_' . $field, $sources, 'campo «' . $field . '»' );
		}

		$this->assertArrayNotHasKey( 'custom_meta__oculto', $sources );
	}

	public function test_acf_fields_come_from_the_resolver_with_the_post_in_hand(): void {
		$seen    = array();
		$catalog = new DiviDynamicCatalog(
			static fn(): bool => true,
			static fn(): array => array(),
			static function ( ?int $post_id ) use ( &$seen ): array {
				$seen[] = $post_id;

				return array();
			},
			static fn(): string => 'page',
			static fn(): array => array()
		);

		$catalog->listing( self::PAGE );
		$catalog->listing( null );
		$catalog->validate_token( '$variable({"type":"content","value":{"name":"custom_meta_x","settings":{}}})$', 'text', self::PAGE );

		$this->assertSame( array( self::PAGE, null ), $seen, 'el resolvedor se consulta una vez por página (memoizado en el catálogo)' );
	}

	public function test_a_valid_token_is_returned_in_canonical_form_with_enable_html_off_for_text_sources(): void {
		$catalog = $this->catalog();

		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_title","settings":{"enable_html":"off"}}})$',
			$catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE )
		);
		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_link_url","settings":{}}})$',
			$catalog->validate_token( $this->token( 'post_link_url' ), 'url', self::PAGE )
		);
		$this->assertSame(
			'$variable({"type":"content","value":{"name":"site_logo","settings":{}}})$',
			$catalog->validate_token( $this->token( 'site_logo' ), 'image', self::PAGE )
		);
		$this->assertSame(
			'$variable({"type":"content","value":{"name":"custom_meta_nexo_prueba_texto","settings":{"enable_html":"off"}}})$',
			$catalog->validate_token( $this->token( 'custom_meta_nexo_prueba_texto' ), 'text', self::PAGE )
		);
	}

	public function test_the_canonical_form_is_re_serialized_by_us_and_not_the_clients_string(): void {
		$client = '$variable({"value": {"settings": {"after": " /fin", "before": "Por: "}, "name": "post_title"}, "type": "content"})$';

		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_title","settings":{"before":"Por: ","after":" /fin","enable_html":"off"}}})$',
			$this->catalog()->validate_token( $client, 'text', self::PAGE )
		);
	}

	public function test_settings_are_validated_per_option_and_reported_with_their_name(): void {
		$catalog = $this->catalog();
		$ok      = static fn( string $name, string $settings, int $post = self::PAGE ) => $catalog->validate_token( '$variable({"type":"content","value":{"name":"' . $name . '","settings":' . $settings . '}})$', 'text', $post );

		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_excerpt","settings":{"words":"25","read_more_label":"Leer más","enable_html":"off"}}})$',
			$ok( 'post_excerpt', '{"words":25,"read_more_label":"Leer más"}' )
		);
		$this->assertStringContainsString( '"date_format":"F d, Y"', $ok( 'post_date', '{"date_format":"F d, Y"}' ) );
		$this->assertStringContainsString( '"custom_date_format":"d/m/Y"', $ok( 'current_date', '{"date_format":"custom","custom_date_format":"d/m/Y"}' ) );
		$this->assertStringContainsString( '"link_destination":"author_website"', $ok( 'post_author', '{"name_format":"first_name","link":"on","link_destination":"author_website"}' ) );
		$this->assertStringContainsString( '"category_type":"post_tag"', $ok( 'post_categories', '{"link_to_term_page":"off","separator":", ","category_type":"post_tag"}', self::POST ) );
		$this->assertStringContainsString( '"custom_text":"Ver"', $ok( 'post_link', '{"text":"custom","custom_text":"Ver"}' ) );

		$bad = array(
			'unknown key'              => array( 'post_title', '{"fallback":"x"}', 'fallback' ),
			'enable_html on'           => array( 'post_title', '{"enable_html":"on"}', 'enable_html' ),
			'enable_html bool'         => array( 'post_title', '{"enable_html":true}', 'enable_html' ),
			'enum value'               => array( 'post_date', '{"date_format":"Y-m-d H:i"}', 'date_format' ),
			'onoff value'              => array( 'post_author', '{"link":"yes"}', 'link' ),
			'words not number'         => array( 'post_excerpt', '{"words":"abc"}', 'words' ),
			'words too many'           => array( 'post_excerpt', '{"words":100000}', 'words' ),
			'words zero'               => array( 'post_excerpt', '{"words":0}', 'words' ),
			'before with html'         => array( 'post_title', '{"before":"<b>x</b>"}', 'before' ),
			'before with shortcode'    => array( 'post_title', '{"before":"[gallery]"}', 'before' ),
			'after with shortcode'     => array( 'post_title', '{"after":"[caption]c[/caption]"}', 'after' ),
			'before entity shortcode'  => array( 'post_title', '{"before":"&#91;gallery&#93;"}', 'before' ),
			'before with token'        => array( 'post_title', '{"before":"$variable({\"type\":\"content\"})$"}', 'before' ),
			'before dollar'            => array( 'post_title', '{"before":"US$ 5"}', 'before' ),
			'before backslash'         => array( 'post_title', '{"after":"a\\\\u0024b"}', 'after' ),
			'before control'           => array( 'post_title', '{"before":"a\\u0000b"}', 'before' ),
			'before not text'          => array( 'post_title', '{"before":5}', 'before' ),
			'before too long'          => array( 'post_title', '{"before":"' . str_repeat( 'a', 201 ) . '"}', 'before' ),
			'custom format chars'      => array( 'post_date', '{"custom_date_format":"<i>"}', 'custom_date_format' ),
			'custom format backslash'  => array( 'post_date', '{"custom_date_format":"\\\\Y"}', 'custom_date_format' ),
			'settings is a list'       => array( 'post_title', '["x"]', 'settings' ),
			'settings not an object'   => array( 'post_title', '"x"', 'settings' ),
			'taxonomy not on the post' => array( 'post_categories', '{"category_type":"product_cat"}', 'category_type' ),
			'taxonomy unknown'         => array( 'post_categories', '{"category_type":"nav_menu"}', 'category_type' ),
		);

		foreach ( $bad as $label => $case ) {
			$result = $ok( $case[0], $case[1], self::POST );
			$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $result, $case[2] );
			$this->assertIsString( $label );
		}

		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $this->catalog()->validate_token( $this->token( 'post_link_url', '{"before":"x"}' ), 'url', self::PAGE ), 'before' );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $this->catalog()->validate_token( $this->token( 'site_logo', '{"enable_html":"off"}' ), 'image', self::PAGE ), 'enable_html' );
	}

	public function test_a_missing_taxonomy_is_filled_with_the_one_of_the_type_because_divi_renders_nothing_without_it(): void {
		$catalog = $this->catalog();
		$token   = static fn( string $name, string $settings = '{}' ): string => '$variable({"type":"content","value":{"name":"' . $name . '","settings":' . $settings . '}})$';

		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_categories","settings":{"category_type":"category","enable_html":"off"}}})$',
			$catalog->validate_token( $token( 'post_categories' ), 'text', self::POST )
		);
		$this->assertStringContainsString( '"category_type":"post_tag"', (string) $catalog->validate_token( $token( 'post_tags' ), 'text', self::POST ) );
		$this->assertStringContainsString( '"category_type":"product_cat"', (string) $catalog->validate_token( $token( 'post_categories' ), 'text', self::PRODUCT ) );
		$this->assertStringContainsString( '"category_type":"product_tag"', (string) $catalog->validate_token( $token( 'post_tags' ), 'text', self::PRODUCT ) );
		$this->assertStringContainsString( '"separator":", ","category_type":"post_tag"', (string) $catalog->validate_token( $token( 'post_categories', '{"category_type":"post_tag","separator":", "}' ), 'text', self::POST ) );

		$by_name = array();

		foreach ( $catalog->listing( self::POST ) as $source ) {
			$by_name[ $source['name'] ] = $source;
		}

		$this->assertSame( array( 'type' => 'enum', 'options' => array( 'category', 'post_tag' ), 'default' => 'category' ), $by_name['post_categories']['settings']['category_type'] );
		$this->assertSame( 'post_tag', $by_name['post_tags']['settings']['category_type']['default'] );
	}

	public function test_the_author_link_destination_defaults_only_when_the_link_is_on(): void {
		$catalog = $this->catalog();
		$token   = static fn( string $settings ): string => '$variable({"type":"content","value":{"name":"post_author","settings":' . $settings . '}})$';

		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_author","settings":{"link":"on","link_destination":"author_archive","enable_html":"off"}}})$',
			$catalog->validate_token( $token( '{"link":"on"}' ), 'text', self::PAGE )
		);
		$this->assertStringContainsString( '"link_destination":"author_website"', (string) $catalog->validate_token( $token( '{"link":"on","link_destination":"author_website"}' ), 'text', self::PAGE ) );
		$this->assertStringNotContainsString( 'link_destination', (string) $catalog->validate_token( $token( '{"link":"off"}' ), 'text', self::PAGE ) );
		$this->assertStringNotContainsString( 'link_destination', (string) $catalog->validate_token( $token( '{}' ), 'text', self::PAGE ) );
	}

	public function test_token_forms_that_are_not_exactly_one_well_formed_content_token_are_denied(): void {
		$catalog = $this->catalog();
		$good    = $this->token( 'post_title' );
		$cases   = array(
			'text before'         => 'Hola ' . $good,
			'text after'          => $good . ' adiós',
			'two tokens'          => $good . $good,
			'html around'         => '<p>' . $good . '</p>',
			'not json'            => '$variable(post_title)$',
			'json list'           => '$variable(["post_title"])$',
			'color type'          => '$variable({"type":"color","value":{"name":"post_title","settings":{}}})$',
			'type missing'        => '$variable({"value":{"name":"post_title","settings":{}}})$',
			'extra top key'       => '$variable({"type":"content","value":{"name":"post_title","settings":{}},"x":1})$',
			'extra value key'     => '$variable({"type":"content","value":{"name":"post_title","settings":{},"x":1}})$',
			'name not string'     => '$variable({"type":"content","value":{"name":5,"settings":{}}})$',
			'settings missing'    => '$variable({"type":"content","value":{"name":"post_title"}})$',
			'uppercase'           => '$VARIABLE({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'space after dollar'  => '$ variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'entity dollar'       => '&#36;variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'json escaped dollar' => '\u0024variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'url encoded'         => '%24variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'tag inside'          => '$<b></b>variable({"type":"content","value":{"name":"post_title","settings":{}}})$',
			'no closing dollar'   => '$variable({"type":"content","value":{"name":"post_title","settings":{}}})',
			'trailing newline'    => $good . "\n",
		);

		foreach ( $cases as $label => $value ) {
			$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $value, 'text', self::PAGE ), '' );
			$this->assertIsString( $label );
		}
	}

	public function test_names_outside_the_closed_list_are_denied(): void {
		$catalog = $this->catalog();

		foreach ( array( 'post_meta_key', 'post_author_url', 'post_author_bio', 'post_author_profile_picture', 'post_comment_count', 'post_link_url_post', 'post_link_url_page', 'loop_post_title', 'loop_user_email', 'bogus', '', 'POST_TITLE', 'post_title ', 'custom_meta_', 'custom_meta__edit_last', 'custom_meta__oculto', 'custom_meta_correo', 'custom_meta_manual_custom_field_value', 'gvid-aaaaaaaaaa', 'product_description', 'product_stock_quantity' ) as $name ) {
			$result = $catalog->validate_token( $this->token( $name ), 'text', self::PRODUCT );
			$this->assertError( 'nexo_mcp_divi_dynamic_denied', $result, '' );
			$this->assertStringContainsString( 'lista', $result->get_error_message(), $name );
		}
	}

	public function test_a_source_of_another_category_than_the_field_is_denied(): void {
		$catalog = $this->catalog();

		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'post_title' ), 'url', self::PAGE ), 'text' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'post_title' ), 'image', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'post_link_url' ), 'text', self::PAGE ), 'url' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'site_logo' ), 'url', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'post_featured_image' ), 'text', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'custom_meta_edad' ), 'image', self::PAGE ) );
	}

	public function test_woocommerce_and_taxonomy_sources_only_apply_where_divi_registers_them(): void {
		$catalog = $this->catalog();

		$this->assertStringContainsString( 'product_price', (string) $catalog->validate_token( $this->token( 'product_price' ), 'text', self::PRODUCT ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'product_price' ), 'text', self::PAGE ), 'producto' );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'product_sku' ), 'text', self::POST ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'post_categories' ), 'text', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( 'product_sku' ), 'text', 999 ), 'producto' );
	}

	public function test_acf_fields_are_validated_against_the_fields_of_the_post_never_raw_meta_keys(): void {
		$catalog = $this->catalog();

		$this->assertIsString( $catalog->validate_token( $this->token( 'custom_meta_resumen', '{"before":"Resumen: "}' ), 'text', self::PAGE ) );

		foreach ( array( 'custom_meta__edit_last', 'custom_meta__thumbnail_id', 'custom_meta_correo', 'custom_meta_foto', 'custom_meta_desconocido', 'custom_meta_Resumen' ) as $name ) {
			$this->assertError( 'nexo_mcp_divi_dynamic_denied', $catalog->validate_token( $this->token( $name ), 'text', self::PAGE ), '' );
		}
	}

	public function test_targets_map_every_module_field_to_one_category(): void {
		$targets = DiviDynamicCatalog::targets();

		$this->assertContains( 'title.innerContent', $targets['divi/heading']['text'] );
		$this->assertContains( 'content.innerContent', $targets['divi/text']['text'] );
		$this->assertContains( 'button.innerContent.text', $targets['divi/button']['text'] );
		$this->assertSame( array( 'button.innerContent.linkUrl' ), $targets['divi/button']['url'] );
		$this->assertContains( 'image.innerContent.src', $targets['divi/image']['image'] );
		$this->assertContains( 'image.innerContent.alt', $targets['divi/image']['text'] );
		$this->assertContains( 'image.innerContent.linkUrl', $targets['divi/image']['url'] );

		foreach ( $targets as $module => $by_category ) {
			foreach ( $by_category as $paths ) {
				$this->assertNotContains( 'module.meta.adminLabel', $paths, $module );
			}
		}

		$this->assertSame( array( 'thumbnail.innerContent.src' ), $targets['divi/video']['image'], 'el video usa media_url: sólo su miniatura es una imagen' );
		$this->assertArrayNotHasKey( 'text', $targets['divi/video'] ?? array() );
		$this->assertArrayNotHasKey( 'divi/divider', $targets );
	}

	public function test_the_author_name_never_offers_the_login_because_users_are_out_of_the_allowlist(): void {
		$catalog = $this->catalog();
		$author  = null;

		foreach ( (array) $catalog->listing( self::PAGE ) as $source ) {
			if ( 'post_author' === $source['name'] ) {
				$author = $source;
			}
		}

		$this->assertIsArray( $author );
		$this->assertSame( array( 'display_name', 'first_last_name', 'last_first_name', 'first_name', 'last_name', 'nickname' ), $author['settings']['name_format']['options'] );
		$this->assertError( 'nexo_mcp_divi_dynamic_invalid', $catalog->validate_token( $this->token( 'post_author', '{"name_format":"username"}' ), 'text', self::PAGE ), 'settings.post_author.name_format' );
		$this->assertIsString( $catalog->validate_token( $this->token( 'post_author', '{"name_format":"nickname"}' ), 'text', self::PAGE ) );
	}

	/**
	 * @return array<string, array{callable}>
	 */
	public static function failing_resolvers(): array {
		return array(
			'registry throws'       => array( static fn( int $id ): array => throw new \RuntimeException( 'boom' ) ),
			'registry not an array' => array( static fn( int $id ) => null ),
		);
	}

	/**
	 * @dataProvider failing_resolvers
	 */
	public function test_a_failing_divi_registry_is_unavailable_and_not_a_false_not_registered(callable $registry ): void {
		$catalog = new DiviDynamicCatalog( static fn(): bool => true, $registry, fn(): array => array(), static fn(): string => 'page', static fn(): array => array() );

		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->listing( self::PAGE ) );
		$this->assertIsArray( $catalog->listing( null ), 'sin página no se consulta el registro de Divi' );
	}

	public function test_a_failing_acf_resolver_is_unavailable_for_the_listing_and_for_acf_tokens(): void {
		$catalog = new DiviDynamicCatalog(
			static fn(): bool => true,
			static fn(): array => array( 'post_title' => array( 'type' => 'text' ) ),
			static fn( ?int $id ): array => throw new \RuntimeException( 'acf' ),
			static fn(): string => 'page',
			static fn(): array => array()
		);

		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->listing( self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->validate_token( $this->token( 'custom_meta_nexo_prueba_texto' ), 'text', self::PAGE ) );
		$this->assertIsString( $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ), 'lo que no es de ACF no depende de ACF' );
	}

	public function test_the_production_resolver_fails_closed_when_divi_does_not_register_its_options_class(): void {
		// Divi activo según el catálogo pero sin la clase de opciones (cambió de versión o no cargó): no es «Divi no la registra».
		$catalog = new DiviDynamicCatalog( static fn(): bool => true, null, fn(): array => array(), static fn(): string => 'page', static fn(): array => array() );

		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE ) );
		$this->assertError( 'nexo_mcp_divi_dynamic_unavailable', $catalog->listing( self::PAGE ) );
	}

	public function test_the_registry_and_acf_are_asked_once_per_post_in_one_catalog(): void {
		$registry = 0;
		$acf      = array();
		$catalog  = new DiviDynamicCatalog(
			static fn(): bool => true,
			static function ( int $id ) use ( &$registry ): array {
				++$registry;

				return array( 'post_title' => array( 'type' => 'text' ), 'post_link_url' => array( 'type' => 'url' ) );
			},
			static function ( ?int $id ) use ( &$acf ): array {
				$acf[] = $id;

				return array( array( 'name' => 'nexo_prueba_texto', 'label' => 'T', 'type' => 'text', 'group' => 'G' ) );
			},
			static fn(): string => 'page',
			static fn(): array => array()
		);

		$catalog->listing( self::PAGE );
		$catalog->listing( self::PAGE );
		$catalog->validate_token( $this->token( 'post_title' ), 'text', self::PAGE );
		$catalog->validate_token( $this->token( 'post_link_url' ), 'url', self::PAGE );
		$catalog->validate_token( $this->token( 'custom_meta_nexo_prueba_texto' ), 'text', self::PAGE );

		$this->assertSame( 1, $registry, 'get_options una vez por página' );
		$this->assertSame( array( self::PAGE ), $acf, 'ACF una vez por página' );

		$catalog->validate_token( $this->token( 'post_title' ), 'text', self::POST );
		$catalog->listing( null );

		$this->assertSame( 2, $registry );
		$this->assertSame( array( self::PAGE, null ), $acf );
	}

	public function test_a_field_resolves_to_its_path_key_and_category_and_unknown_fields_are_null(): void {
		$this->assertSame( array( 'path' => 'title.innerContent', 'key' => '', 'category' => 'text' ), DiviDynamicCatalog::field( 'divi/heading', 'title.innerContent' ) );
		$this->assertSame( array( 'path' => 'button.innerContent', 'key' => 'linkUrl', 'category' => 'url' ), DiviDynamicCatalog::field( 'divi/button', 'button.innerContent.linkUrl' ) );
		$this->assertSame( array( 'path' => 'image.innerContent', 'key' => 'src', 'category' => 'image' ), DiviDynamicCatalog::field( 'divi/image', 'image.innerContent.src' ) );
		$this->assertNull( DiviDynamicCatalog::field( 'divi/heading', 'module.meta.adminLabel' ) );
		$this->assertNull( DiviDynamicCatalog::field( 'divi/heading', 'button.innerContent.text' ) );
		$this->assertNull( DiviDynamicCatalog::field( 'divi/button', 'button.innerContent.linkTarget' ) );
		$this->assertNull( DiviDynamicCatalog::field( 'divi/section', 'title.innerContent' ) );
		$this->assertContains( 'button.innerContent.linkUrl', DiviDynamicCatalog::fields_of( 'divi/button' ) );
		$this->assertSame( array(), DiviDynamicCatalog::fields_of( 'divi/section' ) );
	}
}
