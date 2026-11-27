<?php
/**
 * Esquema de plantillas del Theme Builder de Divi 5 (T3 de D8a): catálogo cerrado de asignaciones, forma de la
 * plantilla, metas `_et_*`, lectura de lo guardado, reglas de sitio entero y de cuerpo, y prioridades.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviThemeBuilderSchema;

final class DiviThemeBuilderSchemaTest extends NexoTestCase {

	private const POST_CONTENT_LAYOUTS = array( 301, 311 );

	/**
	 * Esquema con fuentes simuladas: tipos page/post/product públicos y `secreto` no; taxonomías category/product_cat
	 * públicas y `oculta` no; entradas 10 (page), 12 (post) y 50 (product) publicadas; layouts 301-303 (header),
	 * 311-313 (body) y 321 (footer); sólo 301 y 311 tienen `divi/post-content`.
	 */
	private function schema( bool $woo = true ): DiviThemeBuilderSchema {
		return new DiviThemeBuilderSchema(
			static fn( string $type ): ?array => array(
				'post'    => array( 'public' => true, 'label' => 'Entradas' ),
				'page'    => array( 'public' => true, 'label' => 'Páginas' ),
				'product' => array( 'public' => true, 'label' => 'Productos' ),
				'secreto' => array( 'public' => false, 'label' => 'Secreto' ),
			)[ $type ] ?? null,
			static fn( string $tax ): ?array => array(
				'category'    => array( 'public' => true, 'label' => 'Categorías' ),
				'product_cat' => array( 'public' => true, 'label' => 'Categorías de producto' ),
				'oculta'      => array( 'public' => false, 'label' => 'Oculta' ),
			)[ $tax ] ?? null,
			static fn( int $id, string $tax ): ?string => array( 'category' => array( 3 => 'Noticias' ), 'product_cat' => array( 7 => 'Ropa' ) )[ $tax ][ $id ] ?? null,
			static fn( int $id ): ?array => array(
				10 => array( 'type' => 'page', 'label' => 'Inicio' ),
				12 => array( 'type' => 'post', 'label' => 'Hola' ),
				50 => array( 'type' => 'product', 'label' => 'Camiseta' ),
			)[ $id ] ?? null,
			static fn( int $id ): ?string => array( 2 => 'ana' )[ $id ] ?? null,
			static fn(): bool => $woo,
			static fn( int $id, string $slot ): bool => in_array(
				$id,
				array(
					'header' => array( 301, 302, 303 ),
					'body'   => array( 311, 312, 313 ),
					'footer' => array( 321 ),
				)[ $slot ] ?? array(),
				true
			),
			static fn( int $id ): bool => in_array( $id, self::POST_CONTENT_LAYOUTS, true )
		);
	}

	/**
	 * @param array<string, mixed> $extra Campos que se suman o pisan.
	 * @return array<string, mixed>
	 */
	private function tpl( array $extra = array() ): array {
		return array_merge( array( 'title' => 'Mi plantilla' ), $extra );
	}

	private function assertInvalid( $result, string $path, string $needle = '', string $code = 'nexo_mcp_divi_template_invalid' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );

		if ( '' !== $needle ) {
			$this->assertStringContainsString( $needle, $result->get_error_message() );
		}
	}

	/**
	 * @param array<string, mixed> $input Plantilla.
	 */
	private function assertAccepted( array $input ): array {
		$out = $this->schema()->validate( $input );
		$this->assertIsArray( $out, $out instanceof \WP_Error ? $out->get_error_message() : '' );

		return $out;
	}

	// ---- Forma de la plantilla ----

	public function test_minimal_template_is_normalised_with_safe_defaults(): void {
		$this->assertSame(
			array(
				'title'                      => 'Mi plantilla',
				'enabled'                    => false,
				'default'                    => false,
				'use_on'                     => array(),
				'exclude_from'               => array(),
				'header'                     => array( 'layout_id' => null, 'enabled' => true ),
				'body'                       => array( 'layout_id' => null, 'enabled' => true ),
				'footer'                     => array( 'layout_id' => null, 'enabled' => true ),
				'confirm_site_wide'          => false,
				'allow_without_post_content' => false,
			),
			$this->assertAccepted( $this->tpl() )
		);
	}

	public function test_full_template_is_accepted_and_kept(): void {
		$out = $this->assertAccepted(
			$this->tpl(
				array(
					'enabled'      => true,
					'use_on'       => array( 'homepage', 'search' ),
					'exclude_from' => array( 'singular:post_type:page:id:10' ),
					'header'       => array( 'layout_id' => 301, 'enabled' => true ),
					'body'         => array( 'layout_id' => 311, 'enabled' => false ),
					'footer'       => array( 'layout_id' => null, 'enabled' => false ),
				)
			)
		);

		$this->assertTrue( $out['enabled'] );
		$this->assertSame( array( 'homepage', 'search' ), $out['use_on'] );
		$this->assertSame( array( 'singular:post_type:page:id:10' ), $out['exclude_from'] );
		$this->assertSame( array( 'layout_id' => 301, 'enabled' => true ), $out['header'] );
		$this->assertSame( array( 'layout_id' => 311, 'enabled' => false ), $out['body'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => false ), $out['footer'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function not_an_object(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'plantilla' ),
			'lista'  => array( array( 'a', 'b' ) ),
			'vacío'  => array( array() ),
			'número' => array( 5 ),
		);
	}

	/**
	 * @dataProvider not_an_object
	 * @param mixed $input Entrada.
	 */
	public function test_input_must_be_an_object( $input ): void {
		$this->assertInvalid( $this->schema()->validate( $input ), 'template', 'objeto' );
	}

	public function test_unknown_keys_are_rejected_with_their_name_as_path(): void {
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'priority' => 5 ) ) ), 'priority', 'Admitidas' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => array( 'layout_id' => 301, 'x' => 1 ) ) ) ), 'header.x' );
	}

	public function test_title_is_required_trimmed_and_at_most_100_characters(): void {
		$this->assertInvalid( $this->schema()->validate( array() + array( 'enabled' => false ) ), 'title' );
		$this->assertInvalid( $this->schema()->validate( array( 'title' => '   ' ) ), 'title' );
		$this->assertInvalid( $this->schema()->validate( array( 'title' => 5 ) ), 'title' );
		$this->assertInvalid( $this->schema()->validate( array( 'title' => "a\nb" ) ), 'title' );
		$this->assertInvalid( $this->schema()->validate( array( 'title' => 'a<b>' ) ), 'title' );

		$this->assertSame( str_repeat( 'ñ', 100 ), $this->assertAccepted( array( 'title' => str_repeat( 'ñ', 100 ) ) )['title'], '100 caracteres (no bytes) valen' );
		$this->assertInvalid( $this->schema()->validate( array( 'title' => str_repeat( 'ñ', 101 ) ) ), 'title', '100' );
		$this->assertSame( 'Hola', $this->assertAccepted( array( 'title' => '  Hola  ' ) )['title'] );
	}

	public function test_flags_must_be_real_booleans(): void {
		foreach ( array( 'enabled', 'default', 'confirm_site_wide', 'allow_without_post_content' ) as $key ) {
			$this->assertInvalid( $this->schema()->validate( $this->tpl( array( $key => 'true' ) ) ), $key, 'booleano' );
			$this->assertInvalid( $this->schema()->validate( $this->tpl( array( $key => 1 ) ) ), $key );
		}
	}

	public function test_slots_validate_layout_id_and_enabled(): void {
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => 301 ) ) ), 'header' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => array( 'layout_id' => '301' ) ) ) ), 'header.layout_id' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => array( 'layout_id' => 0 ) ) ) ), 'header.layout_id' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => array( 'layout_id' => 999 ) ) ) ), 'header.layout_id', 'layout' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'header' => array( 'layout_id' => 311 ) ) ) ), 'header.layout_id', 'layout' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'footer' => array( 'layout_id' => 301 ) ) ) ), 'footer.layout_id' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'footer' => array( 'enabled' => 'no' ) ) ) ), 'footer.enabled' );
		$this->assertSame( array( 'layout_id' => 321, 'enabled' => true ), $this->assertAccepted( $this->tpl( array( 'footer' => array( 'layout_id' => 321 ) ) ) )['footer'] );
	}

	// ---- Catálogo cerrado de asignaciones ----

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function valid_contexts(): array {
		return array(
			'homepage'            => array( 'homepage', 110 ),
			'404'                 => array( '404', 1 ),
			'search'              => array( 'search', 1 ),
			'singular all'        => array( 'singular:post_type:page:all', 70 ),
			'singular id'         => array( 'singular:post_type:page:id:10', 100 ),
			'singular term'       => array( 'singular:taxonomy:category:term:id:3', 80 ),
			'archive all'         => array( 'archive:all', 30 ),
			'archive post_type'   => array( 'archive:post_type:post', 60 ),
			'archive tax all'     => array( 'archive:taxonomy:category:all', 70 ),
			'archive term'        => array( 'archive:taxonomy:category:term:id:3', 75 ),
			'archive user all'    => array( 'archive:user:all', 50 ),
			'archive user id'     => array( 'archive:user:id:2', 55 ),
			'archive date'        => array( 'archive:date:all', 40 ),
			'woo shop'            => array( 'woocommerce:shop', 120 ),
			'woo cart'            => array( 'woocommerce:cart', 120 ),
			'woo checkout'        => array( 'woocommerce:checkout', 120 ),
			'woo my_account'      => array( 'woocommerce:my_account', 130 ),
		);
	}

	/**
	 * @dataProvider valid_contexts
	 */
	public function test_every_catalogued_context_is_accepted_in_use_on_and_exclude_from_with_its_priority( string $context, int $priority ): void {
		$this->assertSame( array( $context ), $this->assertAccepted( $this->tpl( array( 'use_on' => array( $context ), 'confirm_site_wide' => true ) ) )['use_on'] );
		$this->assertSame( array( $context ), $this->assertAccepted( $this->tpl( array( 'exclude_from' => array( $context ) ) ) )['exclude_from'] );
		$this->assertSame( $priority, DiviThemeBuilderSchema::priority( $context ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function rejected_contexts(): array {
		return array(
			'rol de autor'            => array( 'archive:user:role:editor' ),
			'hijos'                   => array( 'singular:post_type:page:children:id:10' ),
			'desconocido'             => array( 'bogus:thing:id:99999' ),
			'vacío'                   => array( '' ),
			'no texto'                => array( 10 ),
			'null'                    => array( null ),
			'mayúsculas'              => array( 'Homepage' ),
			'espacio'                 => array( 'homepage ' ),
			'separador final'         => array( 'homepage:' ),
			'doble separador'         => array( 'singular::post_type:page:all' ),
			'id con ceros'            => array( 'singular:post_type:page:id:010' ),
			'id cero'                 => array( 'singular:post_type:page:id:0' ),
			'id negativo'             => array( 'singular:post_type:page:id:-1' ),
			'id texto'                => array( 'singular:post_type:page:id:abc' ),
			'id enorme'               => array( 'singular:post_type:page:id:99999999999' ),
			'id no existe'            => array( 'singular:post_type:page:id:99999' ),
			'id de otro tipo'         => array( 'singular:post_type:page:id:12' ),
			'tipo no público'         => array( 'singular:post_type:secreto:all' ),
			'tipo no registrado'      => array( 'singular:post_type:nada:all' ),
			'attachment'              => array( 'singular:post_type:attachment:all' ),
			'archivo de tipo oculto'  => array( 'archive:post_type:secreto' ),
			'taxonomía no pública'    => array( 'archive:taxonomy:oculta:all' ),
			'taxonomía no existe'     => array( 'singular:taxonomy:nada:term:id:3' ),
			'término no existe'       => array( 'archive:taxonomy:category:term:id:99' ),
			'término de otra tax'     => array( 'archive:taxonomy:category:term:id:7' ),
			'usuario no existe'       => array( 'archive:user:id:99' ),
			'sobra una parte'         => array( 'homepage:all' ),
			'falta una parte'         => array( 'singular:post_type:page' ),
			'archive vacío'           => array( 'archive' ),
			'woo desconocido'         => array( 'woocommerce:product' ),
			'woo comodín'             => array( 'woocommerce:all' ),
			'comodín'                 => array( '*' ),
			'control'                 => array( "homepage\n" ),
		);
	}

	/**
	 * @dataProvider rejected_contexts
	 * @param mixed $context Contexto.
	 */
	public function test_uncatalogued_or_unknown_contexts_are_rejected_with_the_item_path( $context ): void {
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'homepage', $context ) ) ) ), 'use_on.1' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'exclude_from' => array( $context ) ) ) ), 'exclude_from.0' );
	}

	public function test_priority_is_null_for_what_the_catalog_does_not_know(): void {
		foreach ( array( 'archive:user:role:editor', 'singular:post_type:page:children:id:10', 'bogus', '', 'woocommerce:all', 'singular:post_type:page:id:010' ) as $context ) {
			$this->assertNull( DiviThemeBuilderSchema::priority( $context ), $context );
		}
	}

	public function test_woocommerce_contexts_need_woocommerce_active(): void {
		$off = $this->schema( false );

		foreach ( array( 'woocommerce:shop', 'woocommerce:cart', 'woocommerce:checkout', 'woocommerce:my_account' ) as $context ) {
			$this->assertInvalid( $off->validate( $this->tpl( array( 'use_on' => array( $context ) ) ) ), 'use_on.0', 'WooCommerce' );
		}

		$this->assertIsArray( $off->validate( $this->tpl( array( 'use_on' => array( 'archive:post_type:product' ) ) ) ) );
	}

	public function test_use_on_and_exclude_from_accept_exactly_20_contexts_and_reject_21(): void {
		$catalog = array( 'homepage', '404', 'search', 'archive:all', 'archive:date:all', 'archive:user:all', 'archive:user:id:2', 'archive:post_type:post', 'archive:post_type:page', 'archive:post_type:product', 'archive:taxonomy:category:all', 'archive:taxonomy:category:term:id:3', 'singular:post_type:page:all', 'singular:post_type:post:all', 'singular:post_type:page:id:10', 'singular:post_type:post:id:12', 'singular:taxonomy:category:term:id:3', 'woocommerce:shop', 'woocommerce:cart', 'woocommerce:checkout', 'woocommerce:my_account' );
		$this->assertCount( 21, $catalog );

		$out = $this->assertAccepted( $this->tpl( array( 'use_on' => array_slice( $catalog, 0, 20 ), 'confirm_site_wide' => true ) ) );
		$this->assertCount( 20, $out['use_on'] );
		$this->assertAccepted( $this->tpl( array( 'exclude_from' => array_slice( $catalog, 0, 20 ) ) ) );

		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => $catalog, 'confirm_site_wide' => true ) ) ), 'use_on', '20' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'exclude_from' => $catalog ) ) ), 'exclude_from', '20' );
	}

	public function test_lists_must_be_lists_without_duplicates_and_not_overlap(): void {
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => 'homepage' ) ) ), 'use_on', 'lista' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'a' => 'homepage' ) ) ) ), 'use_on', 'lista' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'homepage', 'search', 'homepage' ) ) ) ), 'use_on.2', 'repet' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'exclude_from' => array( 'search', 'search' ) ) ) ), 'exclude_from.1', 'repet' );
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'search' ), 'exclude_from' => array( 'homepage', 'search' ) ) ) ), 'exclude_from.1', 'use_on' );
	}

	public function test_context_lookup_errors_are_treated_as_missing_not_thrown(): void {
		$schema = new DiviThemeBuilderSchema(
			static function ( string $type ): ?array {
				throw new \RuntimeException( 'boom ' . $type );
			},
			static function ( string $tax ): ?array {
				throw new \RuntimeException( 'boom ' . $tax );
			},
			static function ( int $id, string $tax ): ?string {
				throw new \RuntimeException( 'boom' );
			},
			static function ( int $id ): ?array {
				throw new \RuntimeException( 'boom' );
			},
			static function ( int $id ): ?string {
				throw new \RuntimeException( 'boom' );
			},
			static function (): bool {
				throw new \RuntimeException( 'boom' );
			},
			static function ( int $id, string $slot ): bool {
				throw new \RuntimeException( 'boom' );
			},
			static function ( int $id ): bool {
				throw new \RuntimeException( 'boom' );
			}
		);

		$this->assertIsArray( $schema->validate( $this->tpl( array( 'use_on' => array( 'homepage', '404', 'search', 'archive:all', 'archive:date:all', 'archive:user:all' ), 'confirm_site_wide' => true ) ) ) );

		foreach ( array( 'singular:post_type:page:all', 'singular:post_type:page:id:10', 'archive:taxonomy:category:all', 'archive:user:id:2', 'woocommerce:cart' ) as $context ) {
			$this->assertInvalid( $schema->validate( $this->tpl( array( 'use_on' => array( $context ) ) ) ), 'use_on.0' );
		}

		$this->assertInvalid( $schema->validate( $this->tpl( array( 'header' => array( 'layout_id' => 301 ) ) ) ), 'header.layout_id' );
	}

	// ---- S6: confirmación de sitio entero ----

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function site_wide_templates(): array {
		return array(
			'por defecto'       => array( array( 'default' => true ) ),
			'404'               => array( array( 'use_on' => array( '404' ) ) ),
			'archive:all'       => array( array( 'use_on' => array( 'archive:all' ) ) ),
			'singular all'      => array( array( 'use_on' => array( 'homepage', 'singular:post_type:page:all' ) ) ),
			'archivo de tax'    => array( array( 'use_on' => array( 'archive:taxonomy:category:all' ) ) ),
			'archivo de autores'=> array( array( 'use_on' => array( 'archive:user:all' ) ) ),
			'archivo de fechas' => array( array( 'use_on' => array( 'archive:date:all' ) ) ),
		);
	}

	/**
	 * @dataProvider site_wide_templates
	 * @param array<string, mixed> $extra Campos.
	 */
	public function test_site_wide_templates_need_confirm_site_wide_true( array $extra ): void {
		$result = $this->schema()->validate( $this->tpl( $extra ) );
		$this->assertInvalid( $result, 'confirm_site_wide', 'todo el sitio', 'nexo_mcp_divi_template_site_wide' );

		$this->assertInvalid( $this->schema()->validate( $this->tpl( $extra + array( 'confirm_site_wide' => false ) ) ), 'confirm_site_wide', '', 'nexo_mcp_divi_template_site_wide' );

		$out = $this->assertAccepted( $this->tpl( $extra + array( 'confirm_site_wide' => true ) ) );
		$this->assertTrue( $out['confirm_site_wide'] );
	}

	public function test_specific_templates_do_not_need_confirmation(): void {
		foreach ( array( 'homepage', 'search', 'singular:post_type:page:id:10', 'singular:taxonomy:category:term:id:3', 'archive:post_type:post', 'archive:taxonomy:category:term:id:3', 'archive:user:id:2', 'woocommerce:shop' ) as $context ) {
			$this->assertAccepted( $this->tpl( array( 'use_on' => array( $context ) ) ) );
		}

		$this->assertAccepted( $this->tpl( array( 'exclude_from' => array( '404', 'archive:all' ) ) ) );
	}

	public function test_a_malformed_template_reports_the_shape_error_before_the_confirmation(): void {
		$this->assertInvalid( $this->schema()->validate( $this->tpl( array( 'default' => true, 'use_on' => array( 'bogus' ) ) ) ), 'use_on.0' );
	}

	// ---- S6: cuerpo sin divi/post-content ----

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function content_contexts(): array {
		return array(
			'homepage' => array( 'homepage' ),
			'singular' => array( 'singular:post_type:page:id:10' ),
			'término'  => array( 'singular:taxonomy:category:term:id:3' ),
			'carrito'  => array( 'woocommerce:cart' ),
			'mi cuenta'=> array( 'woocommerce:my_account' ),
		);
	}

	/**
	 * @dataProvider content_contexts
	 */
	public function test_body_without_post_content_on_a_content_context_is_rejected( string $context ): void {
		$input = $this->tpl( array( 'use_on' => array( $context ), 'body' => array( 'layout_id' => 312 ) ) );

		$this->assertInvalid( $this->schema()->validate( $input ), 'body.layout_id', 'post-content', 'nexo_mcp_divi_template_body_replaces_content' );
		$this->assertIsArray( $this->schema()->validate( $input + array( 'allow_without_post_content' => true ) ) );
		$this->assertIsArray( $this->schema()->validate( $this->tpl( array( 'use_on' => array( $context ), 'body' => array( 'layout_id' => 311 ) ) ) ), 'con post-content pasa' );
	}

	public function test_body_without_post_content_is_fine_on_archives_404_search_or_without_layout_or_hidden(): void {
		foreach ( array( 'archive:post_type:post', 'archive:taxonomy:category:term:id:3', 'search', '404' ) as $context ) {
			$this->assertIsArray( $this->schema()->validate( $this->tpl( array( 'use_on' => array( $context ), 'body' => array( 'layout_id' => 312 ), 'confirm_site_wide' => true ) ) ), $context );
		}

		$this->assertIsArray( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'homepage' ), 'body' => array( 'layout_id' => null ) ) ) ) );
		$this->assertIsArray( $this->schema()->validate( $this->tpl( array( 'use_on' => array( 'homepage' ), 'body' => array( 'layout_id' => 312, 'enabled' => false ) ) ) ), 'área oculta: no sustituye nada' );
		$this->assertIsArray( $this->schema()->validate( $this->tpl( array( 'exclude_from' => array( 'homepage' ), 'body' => array( 'layout_id' => 312 ) ) ) ), 'exclude_from no asigna' );
	}

	public function test_body_check_applies_to_the_default_template_and_site_wide_all_but_confirmation_comes_first(): void {
		$input = $this->tpl( array( 'default' => true, 'body' => array( 'layout_id' => 312 ) ) );

		$this->assertInvalid( $this->schema()->validate( $input ), 'confirm_site_wide', '', 'nexo_mcp_divi_template_site_wide' );
		$this->assertInvalid( $this->schema()->validate( $input + array( 'confirm_site_wide' => true ) ), 'body.layout_id', '', 'nexo_mcp_divi_template_body_replaces_content' );
		$this->assertIsArray( $this->schema()->validate( $input + array( 'confirm_site_wide' => true, 'allow_without_post_content' => true ) ) );
	}

	public function test_a_throwing_post_content_predicate_counts_as_no_post_content(): void {
		$schema = new DiviThemeBuilderSchema(
			null,
			null,
			null,
			null,
			null,
			null,
			static fn(): bool => true,
			static function ( int $id ): bool {
				throw new \RuntimeException( 'boom' );
			}
		);

		$this->assertInvalid( $schema->validate( $this->tpl( array( 'use_on' => array( 'homepage' ), 'body' => array( 'layout_id' => 5 ) ) ) ), 'body.layout_id', '', 'nexo_mcp_divi_template_body_replaces_content' );
	}

	// ---- Metas guardadas ----

	public function test_stored_gives_the_exact_et_metas(): void {
		$params = $this->assertAccepted(
			$this->tpl(
				array(
					'enabled'      => true,
					'use_on'       => array( 'homepage', 'singular:post_type:page:id:10' ),
					'exclude_from' => array( 'search' ),
					'header'       => array( 'layout_id' => 301, 'enabled' => true ),
					'body'         => array( 'layout_id' => 311, 'enabled' => false ),
				)
			)
		);

		$this->assertSame(
			array(
				'_et_default'               => '0',
				'_et_enabled'               => '1',
				'_et_autogenerated_title'   => '0',
				'_et_use_on'                => array( 'homepage', 'singular:post_type:page:id:10' ),
				'_et_exclude_from'          => array( 'search' ),
				'_et_header_layout_id'      => '301',
				'_et_header_layout_enabled' => '1',
				'_et_body_layout_id'        => '311',
				'_et_body_layout_enabled'   => '0',
				'_et_footer_layout_id'      => '0',
				'_et_footer_layout_enabled' => '1',
			),
			$this->schema()->stored( $params )
		);
	}

	public function test_stored_of_a_default_disabled_template_uses_zero_and_empty_lists(): void {
		$params = $this->assertAccepted( $this->tpl( array( 'default' => true, 'confirm_site_wide' => true ) ) );
		$stored = $this->schema()->stored( $params );

		$this->assertSame( '1', $stored['_et_default'] );
		$this->assertSame( '0', $stored['_et_enabled'] );
		$this->assertSame( array(), $stored['_et_use_on'] );
		$this->assertSame( array(), $stored['_et_exclude_from'] );
	}

	public function test_the_confirmation_flags_are_never_stored(): void {
		$params = $this->assertAccepted( $this->tpl( array( 'default' => true, 'confirm_site_wide' => true, 'allow_without_post_content' => true ) ) );

		$this->assertSame( array(), array_filter( array_keys( $this->schema()->stored( $params ) ), static fn( string $k ): bool => ! str_starts_with( $k, '_et_' ) ) );
	}

	public function test_valid_stored_recognises_what_stored_writes(): void {
		$params = $this->assertAccepted(
			$this->tpl(
				array(
					'enabled'      => true,
					'use_on'       => array( 'homepage', 'archive:user:id:2' ),
					'exclude_from' => array( 'search' ),
					'header'       => array( 'layout_id' => 301, 'enabled' => true ),
					'footer'       => array( 'layout_id' => 321, 'enabled' => false ),
				)
			)
		);
		$read   = $this->schema()->valid_stored( $this->schema()->stored( $params ), 'Mi plantilla' );

		$this->assertSame( $params, $read );
	}

	public function test_valid_stored_accepts_divis_own_extra_metas_but_not_unknown_et_metas(): void {
		$stored = $this->schema()->stored( $this->assertAccepted( $this->tpl() ) );

		$this->assertNotNull( $this->schema()->valid_stored( $stored + array( '_edit_lock' => '1:1', '_et_onboarding_created' => '1' ), 'x' ) );
		$this->assertNotNull( $this->schema()->valid_stored( array_merge( $stored, array( '_et_autogenerated_title' => '1' ) ), 'x' ) );
		$this->assertNull( $this->schema()->valid_stored( $stored + array( '_et_library_item_id' => '9' ), 'x' ) );
		$this->assertNull( $this->schema()->valid_stored( $stored + array( '_et_header_layout_global' => '1' ), 'x' ) );
	}

	public function test_valid_stored_rejects_foreign_values(): void {
		$stored = $this->schema()->stored( $this->assertAccepted( $this->tpl( array( 'use_on' => array( 'homepage' ) ) ) ) );

		$foreign = array(
			'contexto de rol'        => array( '_et_use_on' => array( 'homepage', 'archive:user:role:editor' ) ),
			'contexto desconocido'   => array( '_et_use_on' => array( 'bogus:thing:id:9' ) ),
			'id de página inexistente' => array( '_et_use_on' => array( 'singular:post_type:page:id:99999' ) ),
			'exclude desconocido'    => array( '_et_exclude_from' => array( 'children:id:3' ) ),
			'use_on no es lista'     => array( '_et_use_on' => 'homepage' ),
			'use_on con no texto'    => array( '_et_use_on' => array( 5 ) ),
			'21 contextos'           => array( '_et_use_on' => array_fill( 0, 21, 'homepage' ) ),
			'default raro'           => array( '_et_default' => 'yes' ),
			'default entero'         => array( '_et_default' => 1 ),
			'enabled raro'           => array( '_et_enabled' => '2' ),
			'layout con texto'       => array( '_et_body_layout_id' => 'abc' ),
			'layout negativo'        => array( '_et_body_layout_id' => '-3' ),
			'layout que no existe'   => array( '_et_body_layout_id' => '999' ),
			'layout de otro tipo'    => array( '_et_header_layout_id' => '311' ),
			'layout enabled raro'    => array( '_et_footer_layout_enabled' => 'on' ),
			'valor array'            => array( '_et_default' => array( '1' ) ),
		);

		foreach ( $foreign as $why => $override ) {
			$this->assertNull( $this->schema()->valid_stored( array_merge( $stored, $override ), 'Mi plantilla' ), $why );
		}

		$this->assertNull( $this->schema()->valid_stored( array_diff_key( $stored, array( '_et_default' => 1 ) ), 'Mi plantilla' ), 'sin _et_default no es nuestra' );
		$this->assertNull( $this->schema()->valid_stored( array_diff_key( $stored, array( '_et_enabled' => 1 ) ), 'Mi plantilla' ), 'sin _et_enabled no es nuestra' );
		$this->assertNull( $this->schema()->valid_stored( $stored, str_repeat( 'a', 101 ) ), 'título largo' );
		$this->assertNull( $this->schema()->valid_stored( $stored, '' ), 'sin título' );
		$this->assertNull( $this->schema()->valid_stored( $stored, array( 'x' ) ), 'título no texto' );
	}

	public function test_valid_stored_treats_missing_layout_metas_as_no_layout_and_visible(): void {
		$read = $this->schema()->valid_stored( array( '_et_default' => '0', '_et_enabled' => '1' ), 'Sin layouts' );

		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $read['body'] );
		$this->assertSame( array(), $read['use_on'] );
	}

	public function test_valid_stored_never_throws_and_returns_null_on_garbage_or_throwing_lookups(): void {
		$throwing = new DiviThemeBuilderSchema(
			static function (): ?array {
				throw new \RuntimeException( 'boom' );
			},
			static function (): ?array {
				throw new \RuntimeException( 'boom' );
			},
			static function (): ?string {
				throw new \RuntimeException( 'boom' );
			},
			static function (): ?array {
				throw new \RuntimeException( 'boom' );
			},
			static function (): ?string {
				throw new \RuntimeException( 'boom' );
			},
			static function (): bool {
				throw new \RuntimeException( 'boom' );
			},
			static function (): bool {
				throw new \RuntimeException( 'boom' );
			}
		);
		$metas    = array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( 'singular:post_type:page:id:10' ), '_et_body_layout_id' => '311' );

		$this->assertNull( $throwing->valid_stored( $metas, 'x' ) );

		foreach ( array( null, 'texto', 5, true, array(), array( 1, 2 ), array( '_et_default' => new \stdClass() ), array( '_et_use_on' => array( array( array() ) ) ) ) as $garbage ) {
			$this->assertNull( $this->schema()->valid_stored( $garbage, 'x' ) );
			$this->assertNull( $throwing->valid_stored( $garbage, 'x' ) );
		}
	}

	// ---- summary() de lo ajeno ----

	public function test_summary_of_our_template(): void {
		$stored = $this->schema()->stored(
			$this->assertAccepted( $this->tpl( array( 'enabled' => true, 'use_on' => array( 'homepage', 'singular:post_type:page:id:10' ), 'exclude_from' => array( '404' ), 'header' => array( 'layout_id' => 301, 'enabled' => true ), 'footer' => array( 'layout_id' => null, 'enabled' => false ) ) ) )
		);

		$this->assertSame(
			array(
				'ours'         => true,
				'title'        => 'Mi plantilla',
				'enabled'      => true,
				'default'      => false,
				'site_wide'    => false,
				'use_on'       => array(
					array( 'id' => 'homepage', 'supported' => true, 'priority' => 110 ),
					array( 'id' => 'singular:post_type:page:id:10', 'supported' => true, 'priority' => 100 ),
				),
				'exclude_from' => array( array( 'id' => '404', 'supported' => true, 'priority' => 1 ) ),
				'header'       => array( 'layout_id' => 301, 'enabled' => true ),
				'body'         => array( 'layout_id' => null, 'enabled' => true ),
				'footer'       => array( 'layout_id' => null, 'enabled' => false ),
			),
			$this->schema()->summary( $stored, 'Mi plantilla' )
		);
	}

	public function test_summary_of_a_foreign_template_keeps_unknown_ids_and_flags_them(): void {
		$summary = $this->schema()->summary(
			array(
				'_et_default'          => '1',
				'_et_enabled'          => '1',
				'_et_use_on'           => array( 'archive:user:role:editor', 'singular:post_type:page:children:id:10', 'singular:post_type:page:id:99999', 'homepage', 'woocommerce:cart' ),
				'_et_exclude_from'     => array( 'what:ever' ),
				'_et_header_layout_id' => '777',
				'_et_body_layout_id'   => '311',
				'_et_body_layout_enabled' => '0',
				'_et_library_item_id'  => '5',
			),
			'Heredada del Visual Builder'
		);

		$this->assertFalse( $summary['ours'] );
		$this->assertSame( 'Heredada del Visual Builder', $summary['title'] );
		$this->assertTrue( $summary['default'] );
		$this->assertTrue( $summary['enabled'] );
		$this->assertTrue( $summary['site_wide'] );
		$this->assertSame(
			array(
				array( 'id' => 'archive:user:role:editor', 'supported' => false, 'priority' => null ),
				array( 'id' => 'singular:post_type:page:children:id:10', 'supported' => false, 'priority' => null ),
				array( 'id' => 'singular:post_type:page:id:99999', 'supported' => false, 'priority' => 100 ),
				array( 'id' => 'homepage', 'supported' => true, 'priority' => 110 ),
				array( 'id' => 'woocommerce:cart', 'supported' => true, 'priority' => 120 ),
			),
			$summary['use_on']
		);
		$this->assertSame( array( array( 'id' => 'what:ever', 'supported' => false, 'priority' => null ) ), $summary['exclude_from'] );
		$this->assertSame( array( 'layout_id' => 777, 'enabled' => true ), $summary['header'] );
		$this->assertSame( array( 'layout_id' => 311, 'enabled' => false ), $summary['body'] );
	}

	public function test_summary_of_a_default_template_is_site_wide_and_wildcards_too(): void {
		$this->assertTrue( $this->schema()->summary( array( '_et_default' => '1', '_et_enabled' => '1' ), 'x' )['site_wide'] );
		$this->assertTrue( $this->schema()->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( '404' ) ), 'x' )['site_wide'] );
		$this->assertTrue( $this->schema()->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( 'archive:all' ) ), 'x' )['site_wide'] );
		$this->assertTrue( $this->schema()->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( 'singular:post_type:page:all' ) ), 'x' )['site_wide'] );
		$this->assertFalse( $this->schema()->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( 'homepage' ) ), 'x' )['site_wide'] );
	}

	public function test_summary_survives_garbage_and_never_exposes_free_text(): void {
		$empty = array(
			'ours'         => false,
			'title'        => '',
			'enabled'      => null,
			'default'      => false,
			'site_wide'    => false,
			'use_on'       => array(),
			'exclude_from' => array(),
			'header'       => array( 'layout_id' => null, 'enabled' => true ),
			'body'         => array( 'layout_id' => null, 'enabled' => true ),
			'footer'       => array( 'layout_id' => null, 'enabled' => true ),
		);

		foreach ( array( null, 'x', 5, array(), array( 1, 2 ) ) as $garbage ) {
			$this->assertSame( $empty, $this->schema()->summary( $garbage, array( $garbage ) ), var_export( $garbage, true ) );
		}

		$summary = $this->schema()->summary(
			array(
				'_et_default'          => array( 'x' ),
				'_et_enabled'          => new \stdClass(),
				'_et_use_on'           => array( 5, null, array( 'a' ), "bad\x00id", '<script>alert(1)</script>', str_repeat( 'a', 300 ), 'ok:id' ),
				'_et_exclude_from'     => 'homepage',
				'_et_header_layout_id' => array( '1' ),
				'_et_body_layout_id'   => '-4',
				'_et_footer_layout_id' => '9999999999999999999999',
			),
			"Título\n<b>con</b>\x00 control " . str_repeat( 'z', 200 )
		);

		$this->assertFalse( $summary['ours'] );
		$this->assertLessThanOrEqual( 100, mb_strlen( $summary['title'] ) );
		$this->assertSame( 1, preg_match( '/^[^\x00-\x1F\x7F<>]*\z/u', $summary['title'] ) );
		$this->assertNull( $summary['enabled'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $summary['header'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $summary['body'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $summary['footer'] );
		$this->assertCount( 7, $summary['use_on'] );

		foreach ( $summary['use_on'] as $entry ) {
			$this->assertFalse( $entry['supported'] );
			$this->assertNull( $entry['priority'] );
		}

		$this->assertSame( array( null, null, null, null, null, null, 'ok:id' ), array_column( $summary['use_on'], 'id' ) );
		$this->assertSame( array( array( 'id' => 'homepage', 'supported' => true, 'priority' => 110 ) ), $summary['exclude_from'] );
	}

	public function test_summary_never_throws_even_when_wordpress_lookups_throw(): void {
		$boom = static function (): never {
			throw new \RuntimeException( 'boom' );
		};
		$schema = new DiviThemeBuilderSchema( $boom, $boom, $boom, $boom, $boom, $boom, $boom, $boom );
		$metas  = array(
			'_et_default'          => '0',
			'_et_enabled'          => '1',
			'_et_use_on'           => array( 'singular:post_type:page:id:10', 'homepage', 'woocommerce:cart', 'archive:taxonomy:category:term:id:3', 'archive:user:id:2' ),
			'_et_header_layout_id' => '301',
			'_et_body_layout_id'   => '311',
		);

		$summary = $schema->summary( $metas, 'Con fuentes caídas' );

		$this->assertFalse( $summary['ours'] );
		$this->assertSame( 'Con fuentes caídas', $summary['title'] );
		$this->assertSame( array( false, true, false, false, false ), array_column( $summary['use_on'], 'supported' ) );
		$this->assertSame( array( 100, 110, 120, 75, 55 ), array_column( $summary['use_on'], 'priority' ) );
		$this->assertSame( 311, $summary['body']['layout_id'] );
	}

	public function test_summary_caps_the_contexts_it_lists_at_50(): void {
		$summary = $this->schema()->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array_fill( 0, 80, 'homepage' ) ), 'x' );

		$this->assertCount( 50, $summary['use_on'] );
	}

	public function test_default_sources_do_not_fail_without_wordpress_functions(): void {
		$schema = new DiviThemeBuilderSchema();

		$this->assertIsArray( $schema->validate( $this->tpl( array( 'use_on' => array( 'homepage', 'search' ) ) ) ) );
		$this->assertInvalid( $schema->validate( $this->tpl( array( 'use_on' => array( 'singular:post_type:page:all' ) ) ) ), 'use_on.0' );
		$this->assertInvalid( $schema->validate( $this->tpl( array( 'use_on' => array( 'woocommerce:cart' ) ) ) ), 'use_on.0' );
		$this->assertInvalid( $schema->validate( $this->tpl( array( 'header' => array( 'layout_id' => 5 ) ) ) ), 'header.layout_id' );
		$this->assertTrue( $schema->summary( array( '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => array( 'homepage' ) ), 'x' )['ours'] );
	}

	// ---- Prioridades ----

	/**
	 * @param array<string, mixed> $extra Campos.
	 * @return array<string, mixed>
	 */
	private function t( string $key, array $extra = array() ): array {
		return array_merge( array( 'key' => $key, 'enabled' => true, 'default' => false, 'use_on' => array(), 'exclude_from' => array() ), $extra );
	}

	public function test_priority_table_matches_divis_catalog(): void {
		$expected = array(
			'404'                                  => 1,
			'search'                               => 1,
			'archive:all'                          => 30,
			'archive:date:all'                     => 40,
			'archive:user:all'                     => 50,
			'archive:user:id:7'                    => 55,
			'archive:post_type:product'            => 60,
			'singular:post_type:post:all'          => 70,
			'archive:taxonomy:category:all'        => 70,
			'archive:taxonomy:category:term:id:3'  => 75,
			'singular:taxonomy:category:term:id:3' => 80,
			'singular:post_type:post:id:12'        => 100,
			'homepage'                             => 110,
			'woocommerce:shop'                     => 120,
			'woocommerce:my_account'               => 130,
		);

		foreach ( $expected as $context => $priority ) {
			$this->assertSame( $priority, DiviThemeBuilderSchema::priority( (string) $context ), (string) $context );
		}
	}

	public function test_winner_is_the_highest_priority_use_on_that_matches(): void {
		$all  = $this->t( 'all', array( 'use_on' => array( 'singular:post_type:page:all' ) ) );
		$one  = $this->t( 'one', array( 'use_on' => array( 'singular:post_type:page:id:10' ) ) );
		$home = $this->t( 'home', array( 'use_on' => array( 'homepage' ) ) );

		$this->assertSame(
			array( 'key' => 'one', 'via' => 'use_on', 'context' => 'singular:post_type:page:id:10', 'priority' => 100 ),
			$this->schema()->winner( array( $all, $one, $home ), array( 'singular:post_type:page:all', 'singular:post_type:page:id:10' ) )
		);
		$this->assertSame( 'home', $this->schema()->winner( array( $all, $one, $home ), array( 'homepage', 'singular:post_type:page:all', 'singular:post_type:page:id:10' ) )['key'] );
		$this->assertNull( $this->schema()->winner( array( $one, $home ), array( 'search' ) ) );
	}

	public function test_winner_skips_disabled_and_excluded_templates_and_falls_back_to_the_enabled_default(): void {
		$default = $this->t( 'def', array( 'default' => true ) );
		$all     = $this->t( 'all', array( 'use_on' => array( 'singular:post_type:page:all' ), 'exclude_from' => array( 'singular:post_type:page:id:8' ) ) );
		$off     = $this->t( 'off', array( 'enabled' => false, 'use_on' => array( 'singular:post_type:page:id:8' ) ) );
		$match   = array( 'singular:post_type:page:all', 'singular:post_type:page:id:8' );

		$this->assertSame( array( 'key' => 'def', 'via' => 'default', 'context' => null, 'priority' => 0 ), $this->schema()->winner( array( $default, $all, $off ), $match ) );
		$this->assertSame( 'all', $this->schema()->winner( array( $default, $all, $off ), array( 'singular:post_type:page:all' ) )['key'] );
		$this->assertNull( $this->schema()->winner( array( $this->t( 'd', array( 'default' => true, 'enabled' => false ) ) ), array() ), 'por defecto deshabilitada' );
		$this->assertNull( $this->schema()->winner( array( $this->t( 'd', array( 'default' => true, 'exclude_from' => array( 'search' ) ) ) ), array( 'search' ) ), 'por defecto excluida' );
	}

	public function test_winner_breaks_ties_by_the_smaller_key_and_ignores_garbage_entries(): void {
		$b = $this->t( '12', array( 'use_on' => array( 'archive:all' ) ) );
		$a = $this->t( '9', array( 'use_on' => array( 'archive:all' ) ) );

		$this->assertSame( '9', $this->schema()->winner( array( $b, $a ), array( 'archive:all' ) )['key'] );
		$this->assertSame( '9', $this->schema()->winner( array( 'x', null, array( 'key' => array() ), $b, $a ), array( 'archive:all' ) )['key'] );
		$this->assertNull( $this->schema()->winner( array(), array( 'archive:all' ) ) );
		$this->assertNull( $this->schema()->winner( array( $a ), array() ) );
	}

	public function test_shadows_says_which_template_covers_which(): void {
		$archives = $this->t( 'arch', array( 'use_on' => array( 'archive:all' ) ) );
		$posts    = $this->t( 'posts', array( 'use_on' => array( 'archive:post_type:post' ) ) );
		$pages    = $this->t( 'pages', array( 'use_on' => array( 'singular:post_type:page:all' ) ) );
		$page10   = $this->t( 'p10', array( 'use_on' => array( 'singular:post_type:page:id:10' ) ) );
		$off      = $this->t( 'off', array( 'enabled' => false, 'use_on' => array( 'singular:post_type:page:id:11' ) ) );
		$home     = $this->t( 'home', array( 'use_on' => array( 'homepage' ) ) );

		$this->assertSame(
			array(
				array( 'template' => 'arch', 'context' => 'archive:all', 'priority' => 30, 'covered_by' => 'posts', 'by_context' => 'archive:post_type:post', 'by_priority' => 60 ),
				array( 'template' => 'pages', 'context' => 'singular:post_type:page:all', 'priority' => 70, 'covered_by' => 'p10', 'by_context' => 'singular:post_type:page:id:10', 'by_priority' => 100 ),
			),
			$this->schema()->shadows( array( $archives, $posts, $pages, $page10, $off, $home ) )
		);
	}

	public function test_shadows_default_template_is_covered_by_every_other_enabled_template_with_its_best_context(): void {
		$default = $this->t( 'def', array( 'default' => true ) );
		$other   = $this->t( 'o', array( 'use_on' => array( '404', 'homepage' ) ) );
		$off     = $this->t( 'off', array( 'enabled' => false, 'use_on' => array( 'search' ) ) );
		$empty   = $this->t( 'empty' );

		$this->assertSame(
			array( array( 'template' => 'def', 'context' => null, 'priority' => 0, 'covered_by' => 'o', 'by_context' => 'homepage', 'by_priority' => 110 ) ),
			$this->schema()->shadows( array( $default, $other, $off, $empty ) )
		);
	}

	public function test_shadows_ignores_equal_contexts_unrelated_ones_and_garbage(): void {
		$a = $this->t( 'a', array( 'use_on' => array( 'archive:all' ) ) );
		$b = $this->t( 'b', array( 'use_on' => array( 'archive:all' ) ) );
		$c = $this->t( 'c', array( 'use_on' => array( 'singular:post_type:page:all' ) ) );
		$d = $this->t( 'd', array( 'use_on' => array( 'singular:post_type:post:id:12' ) ) );

		$this->assertSame( array(), $this->schema()->shadows( array( $a, $b, $c, $d, 'x', null, array( 'key' => 'k', 'use_on' => 'homepage' ) ) ) );
		$this->assertSame( array(), $this->schema()->shadows( array() ) );
	}

	public function test_catalog_lists_every_context_pattern_with_priority_and_woocommerce_only_when_active(): void {
		$on  = $this->schema( true )->catalog();
		$off = $this->schema( false )->catalog();

		$this->assertSame( array( 'pattern', 'priority', 'site_wide', 'needs_woocommerce' ), array_keys( $on[0] ) );
		$this->assertContains( 'singular:post_type:<type>:id:<id>', array_column( $on, 'pattern' ) );
		$this->assertContains( 'woocommerce:my_account', array_column( $on, 'pattern' ) );
		$this->assertNotContains( 'woocommerce:my_account', array_column( $off, 'pattern' ) );
		$this->assertNotContains( 'archive:user:role:<role>', array_column( $on, 'pattern' ) );
		$this->assertCount( 17, $on );
		$this->assertCount( 13, $off );
	}
}
