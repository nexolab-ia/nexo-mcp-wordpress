<?php
/**
 * set-divi-loop (T6 de D6): esquema propio de `module.advanced.loop` con los límites de S7.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLoopSchema;

final class DiviLoopSchemaTest extends NexoTestCase {

	private function schema(): DiviLoopSchema {
		return new DiviLoopSchema(
			static fn( string $type ): ?array => array(
				'post'       => array( 'public' => true, 'label' => 'Entradas' ),
				'page'       => array( 'public' => true, 'label' => 'Páginas' ),
				'product'    => array( 'public' => true, 'label' => 'Productos' ),
				'attachment' => array( 'public' => true, 'label' => 'Medios' ),
				'secreto'    => array( 'public' => false, 'label' => 'Secreto' ),
			)[ $type ] ?? null,
			static fn( string $type ): array => array( 'post' => array( 'category', 'post_tag' ), 'product' => array( 'product_cat' ) )[ $type ] ?? array(),
			static fn( int $id, string $taxonomy ): ?string => array( 'category' => array( 3 => 'Noticias', 4 => 'Avisos' ), 'post_tag' => array( 9 => 'oferta' ), 'product_cat' => array( 5 => 'Ropa' ) )[ $taxonomy ][ $id ] ?? null,
			static fn(): string => 'loop-nxabcd1234'
		);
	}

	/**
	 * @param array<string, mixed> $extra Claves que cambian.
	 * @return array<string, mixed>
	 */
	private function loop( array $extra = array() ): array {
		return array_merge( array( 'post_type' => 'post', 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc' ), $extra );
	}

	private function assertInvalid( $result, string $path, string $needle = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_loop_invalid', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );

		if ( '' !== $needle ) {
			$this->assertStringContainsString( $needle, $result->get_error_message() );
		}
	}

	public function test_a_minimal_loop_is_stored_in_divis_exact_shape(): void {
		$params = $this->schema()->validate( $this->loop() );

		$this->assertIsArray( $params );
		$this->assertSame(
			array(
				'enable'             => 'on',
				'queryType'          => 'post_types',
				'subTypes'           => array( array( 'value' => 'post', 'label' => 'Entradas' ) ),
				'orderBy'            => 'date',
				'order'              => 'descending',
				'postPerPage'        => '3',
				'postOffset'         => '0',
				'excludeCurrentPost' => 'off',
				'ignoreStickysPost'  => 'on',
				'loopId'             => 'loop-nxabcd1234',
			),
			$this->schema()->stored( $params, 'loop-nxabcd1234' )
		);
	}

	public function test_every_option_lands_on_its_divi_key(): void {
		$params = $this->schema()->validate(
			$this->loop( array( 'per_page' => 24, 'order_by' => 'id', 'order' => 'asc', 'offset' => 5, 'exclude_current' => true, 'terms' => array( 'category' => array( 3, 4 ), 'post_tag' => array( 9 ) ) ) )
		);
		$this->assertIsArray( $params );
		$stored = $this->schema()->stored( $params, 'loop-nxabcd1234' );

		$this->assertSame( 'ID', $stored['orderBy'], 'Divi guarda ID (el selector del Visual Builder)' );
		$this->assertSame( 'ascending', $stored['order'] );
		$this->assertSame( '24', $stored['postPerPage'] );
		$this->assertSame( '5', $stored['postOffset'] );
		$this->assertSame( 'on', $stored['excludeCurrentPost'] );
		$this->assertSame(
			array(
				array( 'categoryId' => 'category', 'selectedOptions' => array( array( 'value' => 3, 'label' => 'Noticias' ), array( 'value' => 4, 'label' => 'Avisos' ) ) ),
				array( 'categoryId' => 'post_tag', 'selectedOptions' => array( array( 'value' => 9, 'label' => 'oferta' ) ) ),
			),
			$stored['includePostWithSpecificTerms']
		);
		$this->assertSame( array( 'enable', 'queryType', 'subTypes', 'orderBy', 'order', 'postPerPage', 'postOffset', 'excludeCurrentPost', 'ignoreStickysPost', 'includePostWithSpecificTerms', 'loopId' ), array_keys( $stored ) );

		foreach ( array( 'metaQuery', 'search', 'includeSpecificPosts', 'excludeSpecificPosts', 'excludePostWithSpecificTerms' ) as $never ) {
			$this->assertArrayNotHasKey( $never, $stored );
		}
	}

	public function test_title_ordering_and_the_order_aliases_map_to_divi_values(): void {
		$title = $this->schema()->validate( $this->loop( array( 'order_by' => 'title', 'order' => 'asc' ) ) );
		$this->assertIsArray( $title );
		$stored = $this->schema()->stored( $title, 'loop-nxabcd1234' );

		$this->assertSame( 'title', $stored['orderBy'] );
		$this->assertSame( 'ascending', $stored['order'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_per_page(): array {
		return array(
			'cero'       => array( 0 ),
			'25'         => array( 25 ),
			'negativo'   => array( -1 ),
			'cadena'     => array( '5' ),
			'decimal'    => array( 2.5 ),
			'nulo'       => array( null ),
			'lista'      => array( array( 3 ) ),
			'enorme'     => array( 100000 ),
		);
	}

	/**
	 * @dataProvider bad_per_page
	 * @param mixed $value Valor.
	 */
	public function test_per_page_is_an_integer_between_1_and_24( $value ): void {
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'per_page' => $value ) ) ), 'loop.per_page', '1' );
	}

	public function test_per_page_boundaries_1_and_24_are_accepted(): void {
		$this->assertIsArray( $this->schema()->validate( $this->loop( array( 'per_page' => 1 ) ) ) );
		$this->assertIsArray( $this->schema()->validate( $this->loop( array( 'per_page' => 24 ) ) ) );
		$this->assertSame( 24, DiviLoopSchema::MAX_PER_PAGE );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_post_types(): array {
		return array(
			'any'         => array( 'any' ),
			'attachment'  => array( 'attachment' ),
			'vacío'       => array( '' ),
			'no existe'   => array( 'noexiste' ),
			'no público'  => array( 'secreto' ),
			'lista'       => array( array( 'post', 'page' ) ),
			'coma'        => array( 'post,page' ),
			'mayúsculas'  => array( 'Post' ),
			'nulo'        => array( null ),
			'número'      => array( 5 ),
		);
	}

	/**
	 * @dataProvider bad_post_types
	 * @param mixed $value Valor.
	 */
	public function test_post_type_is_one_public_non_attachment_type( $value ): void {
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'post_type' => $value ) ) ), 'loop.post_type' );
	}

	public function test_pages_and_products_are_valid_single_types(): void {
		foreach ( array( 'page', 'product' ) as $type ) {
			$this->assertIsArray( $this->schema()->validate( $this->loop( array( 'post_type' => $type ) ) ), $type );
		}
	}

	public function test_order_by_and_order_are_closed_enums(): void {
		foreach ( array( 'meta', 'rand', 'ID', 'relevance', 'modified', '' ) as $bad ) {
			$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'order_by' => $bad ) ) ), 'loop.order_by', 'date' );
		}

		foreach ( array( 'ascending', 'DESC', 'random', '' ) as $bad ) {
			$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'order' => $bad ) ) ), 'loop.order', 'asc' );
		}
	}

	public function test_required_keys_are_post_type_per_page_order_by_and_order(): void {
		foreach ( array( 'post_type', 'per_page', 'order_by', 'order' ) as $key ) {
			$loop = $this->loop();
			unset( $loop[ $key ] );
			$this->assertInvalid( $this->schema()->validate( $loop ), 'loop.' . $key, 'obligatorio' );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function forbidden_keys(): array {
		return array(
			'meta_query'   => array( 'meta_query', array( array( 'key' => '_x', 'compare' => 'REGEXP' ) ) ),
			'metaQuery'    => array( 'metaQuery', array() ),
			'search'       => array( 'search', 'secreto' ),
			's'            => array( 's', 'x' ),
			'query_type'   => array( 'queryType', 'user_roles' ),
			'sub_types'    => array( 'subTypes', array() ),
			'loop_id'      => array( 'loopId', 'loop-abc' ),
			'enable'       => array( 'enable', 'on' ),
			'post_status'  => array( 'post_status', 'private' ),
			'nested'       => array( 'loop', array( 'post_type' => 'post' ) ),
			'ids'          => array( 'includeSpecificPosts', array( array( 'value' => 1 ) ) ),
			'post_password' => array( 'has_password', true ),
		);
	}

	/**
	 * @dataProvider forbidden_keys
	 * @param mixed $value Valor.
	 */
	public function test_keys_outside_the_closed_set_are_rejected_not_passed_through( string $key, $value ): void {
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( $key => $value ) ) ), 'loop.' . $key, 'no se admite' );
	}

	public function test_the_loop_must_be_an_object(): void {
		foreach ( array( 'post', 3, true, array( 'post', 3 ), array() ) as $bad ) {
			$result = $this->schema()->validate( $bad );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_divi_loop_invalid', $result->get_error_code() );
			$this->assertSame( array( 'path' => 'loop' ), $result->get_error_data() );
		}
	}

	public function test_offset_and_exclude_current_are_bounded(): void {
		foreach ( array( -1, 1001, '2', 1.5, null, true ) as $bad ) {
			$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'offset' => $bad ) ) ), 'loop.offset' );
		}

		foreach ( array( 0, 1000 ) as $good ) {
			$this->assertIsArray( $this->schema()->validate( $this->loop( array( 'offset' => $good ) ) ) );
		}

		foreach ( array( 'on', 1, null, 'true' ) as $bad ) {
			$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'exclude_current' => $bad ) ) ), 'loop.exclude_current' );
		}
	}

	public function test_terms_come_only_from_public_taxonomies_of_that_type_and_must_exist(): void {
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'product_cat' => array( 5 ) ) ) ) ), 'loop.terms.product_cat', 'taxonomía' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'nav_menu' => array( 1 ) ) ) ) ), 'loop.terms.nav_menu' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => array( 99 ) ) ) ) ), 'loop.terms.category', '99' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => array( 9 ) ) ) ) ), 'loop.terms.category', 'no existe' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => array( '3' ) ) ) ) ), 'loop.terms.category' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => array() ) ) ) ), 'loop.terms.category' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => 3 ) ) ) ), 'loop.terms.category' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => array( 3 ) ) ) ), 'loop.terms' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'terms' => 'category' ) ) ), 'loop.terms' );
		$this->assertInvalid( $this->schema()->validate( $this->loop( array( 'post_type' => 'page', 'terms' => array( 'category' => array( 3 ) ) ) ) ), 'loop.terms.category', 'taxonomía' );
		$this->assertIsArray( $this->schema()->validate( $this->loop( array( 'post_type' => 'product', 'terms' => array( 'product_cat' => array( 5 ) ) ) ) ) );
	}

	public function test_terms_are_capped_and_duplicates_are_collapsed(): void {
		// Términos que SÍ existen (100-130): lo que frena es el tope, no la existencia.
		$schema = new DiviLoopSchema(
			static fn( string $type ): ?array => array( 'public' => true, 'label' => 'Entradas' ),
			static fn( string $type ): array => array( 'category' ),
			static fn( int $id, string $taxonomy ): ?string => $id >= 100 && $id <= 130 ? 'Cat ' . $id : null
		);

		$twenty = $schema->validate( $this->loop( array( 'terms' => array( 'category' => range( 100, 119 ) ) ) ) );
		$this->assertIsArray( $twenty );
		$this->assertCount( 20, $twenty['terms']['category'] );

		$this->assertInvalid( $schema->validate( $this->loop( array( 'terms' => array( 'category' => range( 100, 120 ) ) ) ) ), 'loop.terms.category', 'hasta 20 términos' );
		$this->assertInvalid( $schema->validate( $this->loop( array( 'terms' => array( 'category' => range( 100, 130 ) ) ) ) ), 'loop.terms.category', 'hasta 20 términos' );
		// Los repetidos no cuentan: 21 entradas con un duplicado son 20 ids.
		$this->assertIsArray( $schema->validate( $this->loop( array( 'terms' => array( 'category' => array_merge( range( 100, 119 ), array( 100 ) ) ) ) ) ) );

		$params = $this->schema()->validate( $this->loop( array( 'terms' => array( 'category' => array( 3, 3, 4 ) ) ) ) );
		$this->assertIsArray( $params );
		$this->assertSame( array( 'category' => array( 3, 4 ) ), $params['terms'] );
	}

	public function test_a_stored_loop_is_recognised_as_ours_only_when_it_is_exactly_what_we_write(): void {
		$schema = $this->schema();
		$params = $schema->validate( $this->loop( array( 'exclude_current' => true, 'terms' => array( 'category' => array( 3 ) ) ) ) );
		$this->assertIsArray( $params );
		$stored = $schema->stored( $params, 'loop-nxabcd1234' );

		$read = $schema->valid_stored( $stored );
		$this->assertIsArray( $read );
		$this->assertSame( 'post', $read['post_type'] );
		$this->assertSame( 'loop-nxabcd1234', $read['loop_id'] );
		$this->assertSame( $params['terms'], $read['terms'] );

		$labels = $stored;
		$labels['subTypes'][0]['label'] = 'Otro idioma';
		$this->assertIsArray( $schema->valid_stored( $labels ), 'la etiqueta no cuenta' );

		$tampered = array(
			'metaQuery'     => array_merge( $stored, array( 'metaQuery' => array( array( 'key' => '_p', 'value' => '^a', 'compare' => 'REGEXP' ) ) ) ),
			'search'        => array_merge( $stored, array( 'search' => 'x' ) ),
			'user_roles'    => array_merge( $stored, array( 'queryType' => 'user_roles' ) ),
			'any'           => array_merge( $stored, array( 'subTypes' => array( array( 'value' => 'any', 'label' => 'Any' ) ) ) ),
			'empty types'   => array_merge( $stored, array( 'subTypes' => array() ) ),
			'two types'     => array_merge( $stored, array( 'subTypes' => array( array( 'value' => 'post', 'label' => 'a' ), array( 'value' => 'page', 'label' => 'b' ) ) ) ),
			'25'            => array_merge( $stored, array( 'postPerPage' => '25' ) ),
			'disabled'      => array_merge( $stored, array( 'enable' => 'off' ) ),
			'foreign id'    => array_merge( $stored, array( 'loopId' => 'loop-abcdefghij' ) ),
			'no id'         => array_diff_key( $stored, array( 'loopId' => 1 ) ),
			'stickies'      => array_merge( $stored, array( 'ignoreStickysPost' => 'off' ) ),
			'exclude terms' => array_merge( $stored, array( 'excludePostWithSpecificTerms' => $stored['includePostWithSpecificTerms'] ) ),
			'include posts' => array_merge( $stored, array( 'includeSpecificPosts' => array( array( 'value' => 5 ) ) ) ),
			'unknown term'  => array_merge( $stored, array( 'includePostWithSpecificTerms' => array( array( 'categoryId' => 'category', 'selectedOptions' => array( array( 'value' => 99, 'label' => 'x' ) ) ) ) ) ),
		);

		foreach ( $tampered as $why => $value ) {
			$this->assertNull( $schema->valid_stored( $value ), (string) $why );
		}

		$this->assertNull( $schema->valid_stored( 'on' ) );
		$this->assertNull( $schema->valid_stored( array() ) );
	}

	public function test_loop_ids_carry_the_marker_the_visibility_guard_looks_for(): void {
		$this->assertMatchesRegularExpression( DiviLoopSchema::ID_PATTERN, ( new DiviLoopSchema() )->new_id() );
		$this->assertSame( 1, preg_match( DiviLoopSchema::ID_PATTERN, 'loop-nxabcd1234' ) );
		$this->assertSame( 0, preg_match( DiviLoopSchema::ID_PATTERN, 'loop-abcd123456' ) );
		$this->assertSame( 0, preg_match( DiviLoopSchema::ID_PATTERN, "loop-nxabcd1234\n" ) );
		$this->assertNotSame( ( new DiviLoopSchema() )->new_id(), ( new DiviLoopSchema() )->new_id() );
	}

	public function test_has_loop_reads_the_enable_flag_of_any_loop(): void {
		$on = array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => array( 'enable' => 'on', 'queryType' => 'user_roles' ) ) ) ) ) );
		$off = array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => array( 'enable' => 'off' ) ) ) ) ) );

		$this->assertTrue( DiviLoopSchema::has_loop( $on ) );
		$this->assertFalse( DiviLoopSchema::has_loop( $off ) );
		$this->assertFalse( DiviLoopSchema::has_loop( array() ) );
		$this->assertFalse( DiviLoopSchema::has_loop( array( 'module' => array( 'advanced' => array( 'loop' => 'on' ) ) ) ) );
	}

	public function test_the_modules_that_can_carry_a_loop_are_the_d2_catalog_ones_that_divi_supports(): void {
		$this->assertSame(
			array( 'divi/section', 'divi/row', 'divi/column', 'divi/text', 'divi/heading', 'divi/button', 'divi/image', 'divi/divider', 'divi/blurb', 'divi/icon-list', 'divi/icon-list-item' ),
			DiviLoopSchema::MODULES
		);
	}
}
