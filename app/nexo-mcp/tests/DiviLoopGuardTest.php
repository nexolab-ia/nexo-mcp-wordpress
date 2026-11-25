<?php
/**
 * Guarda de visibilidad de los bucles del conector: sólo publicado y sin contraseña, fijado en el filtro de Divi.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLoopGuard;
use NexoDev\NexoMCP\Builders\DiviLoopSchema;

final class DiviLoopGuardTest extends NexoTestCase {

	/**
	 * @param array<string, mixed> $args Argumentos de WP_Query.
	 * @return array<string, mixed>
	 */
	private function data( array $args = array(), string $type = 'post_types' ): array {
		return array(
			'loop_enabled' => 'on',
			'query_args'   => array_merge( array( 'post_type' => array( 'post' ), 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC', 'posts_per_page' => 3 ), $args ),
			'query_type'   => $type,
			'post_type'    => array( 'post' ),
		);
	}

	private function schema(): DiviLoopSchema {
		return new DiviLoopSchema(
			static fn( string $type ): ?array => array(
				'post' => array( 'public' => true, 'label' => 'Entradas' ),
				'page' => array( 'public' => true, 'label' => 'Páginas' ),
			)[ $type ] ?? null,
			static fn( string $type ): array => array( 'post' => array( 'category' ) )[ $type ] ?? array(),
			static fn( int $id, string $taxonomy ): ?string => array( 'category' => array( 3 => 'Noticias' ) )[ $taxonomy ][ $id ] ?? null
		);
	}

	private function guard(): DiviLoopGuard {
		return new DiviLoopGuard( $this->schema() );
	}

	/**
	 * Atributos de un bloque con un bucle del conector VÁLIDO (lo que escribe set-divi-loop), salvo los cambios.
	 *
	 * @param array<string, mixed> $edit Claves del valor guardado que alguien cambia a mano.
	 * @return array<string, mixed>
	 */
	private function attrs( string $loop_id, array $edit = array() ): array {
		$params = $this->schema()->validate( array( 'post_type' => 'post', 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc', 'terms' => array( 'category' => array( 3 ) ) ) );
		$this->assertIsArray( $params );
		$value = array_merge( $this->schema()->stored( $params, $loop_id ), $edit );

		return array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => $value ) ) ) ) );
	}

	public function test_it_hooks_the_final_loop_query_filter_of_divi_after_everything_else(): void {
		\WP_Test_State::$hooks = array();

		( new DiviLoopGuard() )->register();

		$found = array_values( array_filter( \WP_Test_State::$hooks, static fn( array $h ): bool => 'divi_loop_data_after_execution' === $h[0] ) );
		$this->assertCount( 1, $found );
		$this->assertSame( PHP_INT_MAX, $found[0][2] );
		$this->assertSame( 2, $found[0][3] );
		$this->assertIsCallable( $found[0][1] );
		$this->assertSame( 'divi_loop_data_after_execution', DiviLoopGuard::FILTER );
	}

	public function test_a_connector_loop_is_forced_to_published_and_no_password(): void {
		$guard = $this->guard();

		foreach ( array( 'private', 'draft', array( 'publish', 'inherit', 'private' ), 'any', 'future' ) as $status ) {
			$out = $guard->force( $this->data( array( 'post_status' => $status ) ), $this->attrs( 'loop-nxabcd1234' ) );

			$this->assertSame( 'publish', $out['query_args']['post_status'] );
			$this->assertFalse( $out['query_args']['has_password'] );
			$this->assertSame( 'post_types', $out['query_type'] );
			$this->assertSame( array( 'post' ), $out['query_args']['post_type'] );
		}
	}

	public function test_a_password_argument_set_by_something_else_is_overridden(): void {
		$out = $this->guard()->force( $this->data( array( 'has_password' => true, 'post_password' => 'x' ) ), $this->attrs( 'loop-nxabcd1234' ) );

		$this->assertFalse( $out['query_args']['has_password'] );
		$this->assertArrayNotHasKey( 'post_password', $out['query_args'] );
	}

	public function test_the_per_page_is_capped_at_24_even_if_the_stored_value_was_edited(): void {
		$guard = $this->guard();

		$this->assertSame( 24, $guard->force( $this->data( array( 'posts_per_page' => 5000 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$this->assertSame( 24, $guard->force( $this->data( array( 'posts_per_page' => -1 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$this->assertSame( 7, $guard->force( $this->data( array( 'posts_per_page' => 7 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$unset = $this->data();
		unset( $unset['query_args']['posts_per_page'] );
		$this->assertSame( 24, $guard->force( $unset, $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
	}

	public function test_loops_without_the_connector_marker_are_left_exactly_as_divi_built_them(): void {
		$guard = $this->guard();

		foreach ( array( 'loop-abcdefghij', 'loop-nx', 'loop-nxabcd12345', 'LOOP-NXABCD1234', '', 'otro' ) as $id ) {
			$data = $this->data( array( 'post_status' => array( 'publish', 'inherit', 'private' ), 'posts_per_page' => 500 ) );
			$this->assertSame( $data, $guard->force( $data, $this->attrs( $id ) ), $id );
		}

		$this->assertSame( $this->data(), $guard->force( $this->data(), array() ) );
	}

	public function test_non_connector_loops_are_untouched_for_every_query_type(): void {
		$guard = $this->guard();

		foreach ( array( 'user_roles', 'terms', 'menus', 'users', 'repeater_x', 'current_page', 'post_taxonomies' ) as $type ) {
			foreach ( array( 'loop-abcdefghij', 'loop-nx', 'otro' ) as $id ) {
				$data = $this->data( array(), $type );
				$this->assertSame( $data, $guard->force( $data, $this->attrs( $id, array( 'queryType' => $type ) ) ), $type . ' ' . $id );
			}
			$data = $this->data( array(), $type );
			$this->assertSame( $data, $guard->force( $data, array() ), $type );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function non_post_query_types(): array {
		return array(
			'user_roles'      => array( 'user_roles' ),
			'users'           => array( 'users' ),
			'terms'           => array( 'terms' ),
			'post_taxonomies' => array( 'post_taxonomies' ),
			'menus'           => array( 'menus' ),
			'current_page'    => array( 'current_page' ),
			'repeater'        => array( 'repeater_field' ),
		);
	}

	/**
	 * Divi deriva `query_type` de `queryType` guardado: un bucle del conector editado a otro tipo llega así al filtro.
	 *
	 * @dataProvider non_post_query_types
	 */
	public function test_a_connector_loop_edited_to_a_non_post_query_type_renders_nothing( string $type ): void {
		$real = array(
			'loop_enabled' => 'on',
			'query_args'   => array( 'role__in' => array( 'administrator' ), 'number' => 50, 'taxonomy' => 'category', 'include' => array( 1 ), 'orderby' => 'user_email' ),
			'query_type'   => $type,
			'post_type'    => array( 'post' ),
		);

		$out = $this->guard()->force( $real, $this->attrs( 'loop-nxabcd1234', array( 'queryType' => $type ) ) );

		// Divi ejecuta lo que sale del filtro según `query_type`: debe ser una consulta de entradas vacía.
		$this->assertSame( 'post_types', $out['query_type'] );
		$this->assertSame( array( 0 ), $out['query_args']['post__in'] );
		$this->assertSame( 'publish', $out['query_args']['post_status'] );
		$this->assertFalse( $out['query_args']['has_password'] );
		$this->assertArrayNotHasKey( 'role__in', $out['query_args'] );
		$this->assertArrayNotHasKey( 'include', $out['query_args'] );
		$this->assertLessThanOrEqual( 24, $out['query_args']['posts_per_page'] );
		$this->assertTrue( $out['query_args']['ignore_sticky_posts'] );
	}

	public function test_a_connector_loop_with_a_non_post_query_type_is_emptied_even_if_the_stored_value_looks_valid(): void {
		$out = $this->guard()->force( $this->data( array(), 'user_roles' ), $this->attrs( 'loop-nxabcd1234' ) );

		$this->assertSame( 'post_types', $out['query_type'] );
		$this->assertSame( array( 0 ), $out['query_args']['post__in'] );
	}

	public function test_malformed_filter_input_is_returned_unchanged(): void {
		$guard = $this->guard();

		$this->assertSame( 'x', $guard->force( 'x', $this->attrs( 'loop-nxabcd1234' ) ) );
		$this->assertSame( array(), $guard->force( array(), $this->attrs( 'loop-nxabcd1234' ) ) );
		$this->assertSame( array( 'query_type' => 'post_types' ), $guard->force( array( 'query_type' => 'post_types' ), $this->attrs( 'loop-nxabcd1234' ) ) );
		$data = $this->data();
		$this->assertSame( $data, $guard->force( $data, 'no es un arreglo' ) );
		$this->assertSame( $data, $guard->force( $data, array( 'module' => array( 'advanced' => array( 'loop' => 'x' ) ) ) ) );
	}

	public function test_a_valid_connector_loop_keeps_its_query_and_only_gets_the_hard_conditions(): void {
		$out = $this->guard()->force( $this->data(), $this->attrs( 'loop-nxabcd1234' ) );

		$this->assertArrayNotHasKey( 'post__in', $out['query_args'] );
		$this->assertSame( 3, $out['query_args']['posts_per_page'] );
		$this->assertSame( array( 'post' ), $out['query_args']['post_type'] );
	}

	public function test_sticky_promotion_is_always_off_for_a_connector_loop(): void {
		$data = $this->data( array( 'ignore_sticky_posts' => 0, 'et_builder_loop_manual_sticky_prepend' => 1, 'et_builder_loop_ignore_stickys_post' => '' ) );

		foreach ( array( 'on', 'off', '' ) as $sticky ) {
			// Aunque alguien edite ignoreStickysPost (entonces el valor ni es del conector).
			$out = $this->guard()->force( $data, $this->attrs( 'loop-nxabcd1234', array( 'ignoreStickysPost' => $sticky ) ) );

			$this->assertTrue( $out['query_args']['ignore_sticky_posts'], $sticky );
			$this->assertArrayNotHasKey( 'et_builder_loop_manual_sticky_prepend', $out['query_args'] );
			$this->assertTrue( $out['query_args']['et_builder_loop_ignore_stickys_post'] );
			$this->assertFalse( $out['query_args']['has_password'] );
			$this->assertSame( 'publish', $out['query_args']['post_status'] );
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1?: string}>
	 */
	public static function edited_values(): array {
		return array(
			'post_type any'         => array( array( 'subTypes' => array( array( 'value' => 'any', 'label' => 'Todos' ) ) ) ),
			'post_type attachment'  => array( array( 'subTypes' => array( array( 'value' => 'attachment', 'label' => 'Medios' ) ) ) ),
			'no sub types'          => array( array( 'subTypes' => array() ) ),
			'two types'             => array( array( 'subTypes' => array( array( 'value' => 'post', 'label' => 'a' ), array( 'value' => 'page', 'label' => 'b' ) ) ) ),
			'meta query'            => array( array( 'metaQuery' => array( array( 'key' => '_secret', 'value' => '^x', 'compare' => 'REGEXP' ) ) ) ),
			'search'                => array( array( 'search' => 'privado' ) ),
			'specific posts'        => array( array( 'includeSpecificPosts' => array( array( 'value' => 12 ) ) ) ),
			'exclude terms'         => array( array( 'excludePostWithSpecificTerms' => array() ) ),
			'offset above the cap'  => array( array( 'postOffset' => '1001' ) ),
			'per page above the cap' => array( array( 'postPerPage' => '500' ) ),
			'a type that is gone'   => array( array( 'subTypes' => array( array( 'value' => 'borrado', 'label' => 'x' ) ) ) ),
			'user query'            => array( array( 'queryType' => 'user_roles' ), 'user_roles' ),
			'terms query'           => array( array( 'queryType' => 'terms' ), 'terms' ),
			'menus query'           => array( array( 'queryType' => 'menus' ), 'menus' ),
		);
	}

	/**
	 * @dataProvider edited_values
	 * @param array<string, mixed> $edit       Cambio manual del valor guardado.
	 * @param string               $query_type Tipo que Divi deriva de `queryType` y pasa al filtro.
	 */
	public function test_a_stored_value_edited_after_the_connector_wrote_it_renders_an_empty_query( array $edit, string $query_type = 'post_types' ): void {
		$out = $this->guard()->force(
			$this->data( array( 'post_type' => array( 'any' ), 's' => 'privado', 'meta_query' => array( array( 'key' => '_secret' ) ), 'offset' => 5000, 'posts_per_page' => 500, 'post__in' => array( 12 ) ), $query_type ),
			$this->attrs( 'loop-nxabcd1234', $edit )
		);

		$this->assertSame( 'post_types', $out['query_type'] );
		$this->assertSame( array( 0 ), $out['query_args']['post__in'] );
		$this->assertSame( 'publish', $out['query_args']['post_status'] );
		$this->assertFalse( $out['query_args']['has_password'] );
		$this->assertLessThanOrEqual( 24, $out['query_args']['posts_per_page'] );
		$this->assertTrue( $out['query_args']['ignore_sticky_posts'] );
	}

	public function test_a_loop_value_with_an_extra_key_added_to_a_valid_one_is_also_emptied(): void {
		$attrs = $this->attrs( 'loop-nxabcd1234' );
		$attrs['module']['advanced']['loop']['desktop']['value']['unknown'] = 'x';

		$this->assertSame( array( 0 ), $this->guard()->force( $this->data(), $attrs )['query_args']['post__in'] );
	}

	public function test_loops_that_are_not_the_connectors_never_get_the_empty_query(): void {
		$data = $this->data();
		$edit = $this->attrs( 'loop-abcdefghij', array( 'metaQuery' => array( array( 'key' => 'x' ) ), 'subTypes' => array() ) );

		$this->assertSame( $data, $this->guard()->force( $data, $edit ) );
	}
}
