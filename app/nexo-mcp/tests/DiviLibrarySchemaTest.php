<?php
/**
 * Esquema de la biblioteca de Divi 5 (T9): entrada de guardar, forma por tipo, términos y metas del Visual Builder, lectura
 * de lo guardado sin lanzar con datos basura y filtros del listado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLibrarySchema;

final class DiviLibrarySchemaTest extends NexoTestCase {

	private function schema(): DiviLibrarySchema {
		return new DiviLibrarySchema();
	}

	/**
	 * @param array<string, mixed> $extra Campos que se suman o pisan.
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array() ): array {
		return array_merge(
			array(
				'title' => 'Mi sección',
				'type'  => 'section',
				'nodes' => array( array( 'type' => 'divi/section' ) ),
			),
			$extra
		);
	}

	private function assertInvalid( $result, string $path ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_library_invalid', $result->get_error_code() );
		$this->assertSame( $path, $result->get_error_data()['path'] );
	}

	public function test_a_valid_create_is_normalized_with_non_global_by_default(): void {
		$parsed = $this->schema()->parse_save( $this->input( array( 'title' => '  Mi sección  ', 'categories' => array( 'Héroes', 'Pie' ) ) ) );

		$this->assertSame( 'Mi sección', $parsed['title'] );
		$this->assertSame( 'section', $parsed['type'] );
		$this->assertSame( 'non_global', $parsed['scope'] );
		$this->assertSame( array( 'Héroes', 'Pie' ), $parsed['categories'] );
		$this->assertFalse( $parsed['replace_foreign'] );
		$this->assertSame( array( array( 'type' => 'divi/section' ) ), $parsed['nodes'] );
	}

	public function test_a_global_scope_is_accepted_on_create(): void {
		$this->assertSame( 'global', $this->schema()->parse_save( $this->input( array( 'scope' => 'global' ) ) )['scope'] );
	}

	public function test_an_unknown_field_is_rejected_with_its_path(): void {
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'post_status' => 'draft' ) ) ), 'post_status' );
	}

	public function test_a_non_object_input_is_rejected(): void {
		$this->assertInvalid( $this->schema()->parse_save( 'basura' ), '' );
		$this->assertInvalid( $this->schema()->parse_save( null ), '' );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_titles(): array {
		return array(
			'vacío'    => array( '   ' ),
			'número'   => array( 5 ),
			'lista'    => array( array( 'a' ) ),
			'largo'    => array( str_repeat( 'a', 101 ) ),
			'con tags' => array( 'Hola <b>' ),
		);
	}

	/**
	 * @dataProvider bad_titles
	 * @param mixed $title Título inválido.
	 */
	public function test_a_bad_title_is_rejected( $title ): void {
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'title' => $title ) ) ), 'title' );
	}

	public function test_title_type_and_nodes_are_required_on_create(): void {
		foreach ( array( 'title', 'type', 'nodes' ) as $key ) {
			$input = $this->input();
			unset( $input[ $key ] );

			$this->assertInvalid( $this->schema()->parse_save( $input ), $key );
		}
	}

	public function test_a_title_of_exactly_100_characters_is_accepted(): void {
		$this->assertSame( 100, strlen( $this->schema()->parse_save( $this->input( array( 'title' => str_repeat( 'a', 100 ) ) ) )['title'] ) );
	}

	public function test_type_and_scope_come_from_a_closed_list(): void {
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'type' => 'page' ) ) ), 'type' );
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'type' => array( 'row' ) ) ) ), 'type' );
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'scope' => 'not_global' ) ) ), 'scope' );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_categories(): array {
		return array(
			'no es lista'   => array( 'Uno' ),
			'objeto'        => array( array( 'a' => 'Uno' ) ),
			'vacío'         => array( array( ' ' ) ),
			'con coma'      => array( array( 'a,b' ) ),
			'con tags'      => array( array( '<b>' ) ),
			'repetida'      => array( array( 'Uno', 'Uno' ) ),
			'número'        => array( array( 4 ) ),
			'muy larga'     => array( array( str_repeat( 'a', 51 ) ) ),
			'demasiadas'    => array( array_map( static fn( int $i ): string => 'c' . $i, range( 1, 11 ) ) ),
		);
	}

	/**
	 * @dataProvider bad_categories
	 * @param mixed $categories Categorías inválidas.
	 */
	public function test_bad_categories_are_rejected( $categories ): void {
		$result = $this->schema()->parse_save( $this->input( array( 'categories' => $categories ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringStartsWith( 'categories', $result->get_error_data()['path'] );
	}

	public function test_replace_foreign_must_be_a_boolean(): void {
		$this->assertInvalid( $this->schema()->parse_save( $this->input( array( 'replace_foreign' => 'yes' ) ) ), 'replace_foreign' );
		$this->assertTrue( $this->schema()->parse_save( $this->input( array( 'replace_foreign' => true ) ) )['replace_foreign'] );
	}

	public function test_an_update_only_needs_one_change_and_keeps_the_rest_null(): void {
		$current = array( 'type' => 'row', 'scope' => 'non_global' );
		$parsed  = $this->schema()->parse_save( array( 'id' => 5, 'title' => 'Nuevo' ), $current );

		$this->assertSame( 'Nuevo', $parsed['title'] );
		$this->assertSame( 'row', $parsed['type'] );
		$this->assertSame( 'non_global', $parsed['scope'] );
		$this->assertNull( $parsed['categories'] );
		$this->assertNull( $parsed['nodes'] );
	}

	public function test_an_update_with_nothing_to_change_is_rejected(): void {
		$this->assertInvalid( $this->schema()->parse_save( array( 'id' => 5 ), array( 'type' => 'row', 'scope' => 'non_global' ) ), '' );
	}

	public function test_an_update_cannot_change_type_or_scope_but_can_repeat_them(): void {
		$current = array( 'type' => 'row', 'scope' => 'non_global' );

		$this->assertInvalid( $this->schema()->parse_save( array( 'id' => 5, 'title' => 'x', 'type' => 'section' ), $current ), 'type' );
		$this->assertInvalid( $this->schema()->parse_save( array( 'id' => 5, 'title' => 'x', 'scope' => 'global' ), $current ), 'scope' );
		$this->assertIsArray( $this->schema()->parse_save( array( 'id' => 5, 'title' => 'x', 'type' => 'row', 'scope' => 'non_global' ), $current ) );
	}

	public function test_the_root_parent_depends_on_the_type(): void {
		$this->assertSame( 'divi/placeholder', DiviLibrarySchema::root_parent( 'layout' ) );
		$this->assertSame( 'divi/placeholder', DiviLibrarySchema::root_parent( 'section' ) );
		$this->assertSame( 'divi/section', DiviLibrarySchema::root_parent( 'row' ) );
		$this->assertSame( 'divi/column', DiviLibrarySchema::root_parent( 'module' ) );
	}

	public function test_non_layout_items_carry_exactly_one_node_and_layouts_one_or_more(): void {
		$one = array( array( 'type' => 'divi/section' ) );
		$two = array( array( 'type' => 'divi/section' ), array( 'type' => 'divi/section' ) );

		$this->assertTrue( $this->schema()->check_shape( 'layout', $two ) );
		$this->assertTrue( $this->schema()->check_shape( 'section', $one ) );
		$this->assertInvalid( $this->schema()->check_shape( 'section', $two ), 'nodes' );
		$this->assertInvalid( $this->schema()->check_shape( 'row', $two ), 'nodes' );
		$this->assertInvalid( $this->schema()->check_shape( 'module', array() ), 'nodes' );
	}

	public function test_module_type_is_the_catalog_block_of_the_single_node(): void {
		$this->assertSame( 'divi/text', DiviLibrarySchema::module_type( array( array( 'type' => 'divi/text' ) ) ) );
		$this->assertNull( DiviLibrarySchema::module_type( array( array( 'type' => 'divi/nope' ) ) ) );
		$this->assertNull( DiviLibrarySchema::module_type( array() ) );
	}

	public function test_stored_writes_the_terms_and_metas_of_the_visual_builder(): void {
		$module = $this->schema()->stored( 'module', 'global', 'divi/text' );

		$this->assertSame(
			array(
				'layout_type'  => array( 'module' ),
				'scope'        => array( 'global' ),
				'module_width' => array( 'regular' ),
			),
			$module['terms']
		);
		$this->assertSame(
			array(
				'_et_pb_built_for_post_type'     => 'page',
				'_et_pb_template_type'          => 'module',
				'_et_pb_use_divi_5'             => 'on',
				'_et_pb_module_type'            => 'divi/text',
				'_et_pb_excluded_global_options' => '[]',
			),
			$module['meta']
		);
	}

	public function test_stored_leaves_module_metas_out_of_other_types_and_of_non_global_modules(): void {
		$row = $this->schema()->stored( 'row', 'non_global' );

		$this->assertSame( array( '_et_pb_built_for_post_type', '_et_pb_template_type', '_et_pb_use_divi_5' ), array_keys( $row['meta'] ) );
		$this->assertSame( 'row', $row['meta']['_et_pb_template_type'] );
		$this->assertSame( array( 'non_global' ), $row['terms']['scope'] );

		$local = $this->schema()->stored( 'module', 'non_global', 'divi/text' );
		$this->assertArrayHasKey( '_et_pb_module_type', $local['meta'] );
		$this->assertArrayNotHasKey( '_et_pb_excluded_global_options', $local['meta'] );
	}

	public function test_read_returns_what_the_item_has(): void {
		$schema = new DiviLibrarySchema(
			static fn( int $id, string $tax ): array => array(
				'layout_type'     => array( 'module' ),
				'scope'           => array( 'global' ),
				'layout_category' => array( 'Héroes' ),
			)[ $tax ] ?? array(),
			static fn( int $id, string $key ) => '_et_pb_module_type' === $key ? 'divi/text' : ''
		);

		$this->assertSame(
			array(
				'type'        => 'module',
				'scope'       => 'global',
				'categories'  => array( 'Héroes' ),
				'module_type' => 'divi/text',
			),
			$schema->read( 8 )
		);
		$this->assertTrue( $schema->is_global( 8 ) );
	}

	public function test_read_never_throws_with_garbage_sources(): void {
		$throwing = new DiviLibrarySchema(
			static function (): array {
				throw new \RuntimeException( 'boom' );
			},
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		$garbage  = new DiviLibrarySchema( static fn(): array => array( 5, null, array( 'x' ), 'layout' ), static fn(): array => array( 1 ) );
		$wp_error = new DiviLibrarySchema( static fn() => new \WP_Error( 'invalid_taxonomy', 'x' ), static fn() => null );
		$empty    = array( 'type' => '', 'scope' => 'non_global', 'categories' => array(), 'module_type' => '' );

		$this->assertSame( $empty, $throwing->read( 1 ) );
		$this->assertSame( array( 'type' => 'layout', 'scope' => 'non_global', 'categories' => array( 'layout' ), 'module_type' => '' ), $garbage->read( 1 ) );
		$this->assertSame( $empty, $wp_error->read( 1 ) );
		$this->assertSame( $empty, $this->schema()->read( -4 ) );
	}

	public function test_a_scope_that_cannot_be_read_counts_as_global(): void {
		$this->assertTrue( ( new DiviLibrarySchema( static fn() => new \WP_Error( 'invalid_taxonomy', 'x' ) ) )->is_global( 3 ) );
		$this->assertTrue( ( new DiviLibrarySchema( static function () {
			throw new \RuntimeException( 'boom' );
		} ) )->is_global( 3 ) );
		$this->assertFalse( ( new DiviLibrarySchema( static fn(): array => array( 'non_global' ) ) )->is_global( 3 ) );
	}

	public function test_foreign_types_lists_divi_blocks_outside_the_catalog(): void {
		$content = '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->'
			. '<!-- wp:divi/text {"a":1} /--><!-- wp:divi/fullwidth-menu {"b":1} /--><!-- wp:divi/global-layout {"globalModule":"9"} /-->'
			. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

		$this->assertSame( array( 'divi/fullwidth-menu', 'divi/global-layout' ), $this->schema()->foreign_types( $content ) );
		$this->assertSame( array(), $this->schema()->foreign_types( '<!-- wp:divi/placeholder --><!-- wp:divi/text /--><!-- /wp:divi/placeholder -->' ) );
		$this->assertSame( array(), $this->schema()->foreign_types( '' ) );
		$this->assertSame( array(), $this->schema()->foreign_types( "texto \0 <!-- wp:divi/ -->" ) );
	}

	public function test_summary_describes_a_post_without_its_content(): void {
		$schema  = new DiviLibrarySchema( static fn( int $id, string $tax ): array => array( 'layout_type' => array( 'row' ) )[ $tax ] ?? array(), static fn() => '' );
		$summary = $schema->summary( new \WP_Post( array( 'ID' => 4, 'post_type' => 'et_pb_layout', 'post_status' => 'publish', 'post_title' => 'Fila', 'post_content' => 'secreto' ) ) );

		$this->assertSame( array( 'id', 'title', 'status', 'type', 'scope', 'categories', 'module_type', 'modified' ), array_keys( $summary ) );
		$this->assertSame( 4, $summary['id'] );
		$this->assertSame( 'row', $summary['type'] );
		$this->assertSame( 'non_global', $summary['scope'] );
	}

	public function test_filters_have_defaults_and_validate_each_field(): void {
		$this->assertSame(
			array( 'type' => '', 'scope' => '', 'category' => '', 'search' => '', 'limit' => 50 ),
			$this->schema()->parse_filters( array() )
		);
		$this->assertSame(
			array( 'type' => 'row', 'scope' => 'global', 'category' => 'Héroes', 'search' => 'azul', 'limit' => 100 ),
			$this->schema()->parse_filters( array( 'type' => 'row', 'scope' => 'global', 'category' => ' Héroes ', 'search' => 'azul', 'limit' => 100 ) )
		);

		foreach ( array( 'type' => 'page', 'scope' => 'x', 'category' => 5, 'search' => array(), 'limit' => 0, 'extra' => 1 ) as $key => $value ) {
			$this->assertInvalid( $this->schema()->parse_filters( array( $key => $value ) ), $key );
		}

		$this->assertInvalid( $this->schema()->parse_filters( array( 'limit' => 101 ) ), 'limit' );
		$this->assertInvalid( $this->schema()->parse_filters( array( 'limit' => '5' ) ), 'limit' );
		$this->assertInvalid( $this->schema()->parse_filters( 'basura' ), '' );
	}
}
