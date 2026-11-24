<?php
/**
 * Guarda de visibilidad de los bucles del conector: sólo publicado y sin contraseña, fijado en el filtro de Divi.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLoopGuard;

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

	/**
	 * @return array<string, mixed>
	 */
	private function attrs( string $loop_id ): array {
		return array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => array( 'enable' => 'on', 'loopId' => $loop_id ) ) ) ) ) );
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
		$guard = new DiviLoopGuard();

		foreach ( array( 'private', 'draft', array( 'publish', 'inherit', 'private' ), 'any', 'future' ) as $status ) {
			$out = $guard->force( $this->data( array( 'post_status' => $status ) ), $this->attrs( 'loop-nxabcd1234' ) );

			$this->assertSame( 'publish', $out['query_args']['post_status'] );
			$this->assertFalse( $out['query_args']['has_password'] );
			$this->assertSame( 'post_types', $out['query_type'] );
			$this->assertSame( array( 'post' ), $out['query_args']['post_type'] );
		}
	}

	public function test_a_password_argument_set_by_something_else_is_overridden(): void {
		$out = ( new DiviLoopGuard() )->force( $this->data( array( 'has_password' => true, 'post_password' => 'x' ) ), $this->attrs( 'loop-nxabcd1234' ) );

		$this->assertFalse( $out['query_args']['has_password'] );
		$this->assertArrayNotHasKey( 'post_password', $out['query_args'] );
	}

	public function test_the_per_page_is_capped_at_24_even_if_the_stored_value_was_edited(): void {
		$guard = new DiviLoopGuard();

		$this->assertSame( 24, $guard->force( $this->data( array( 'posts_per_page' => 5000 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$this->assertSame( 24, $guard->force( $this->data( array( 'posts_per_page' => -1 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$this->assertSame( 7, $guard->force( $this->data( array( 'posts_per_page' => 7 ) ), $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
		$unset = $this->data();
		unset( $unset['query_args']['posts_per_page'] );
		$this->assertSame( 24, $guard->force( $unset, $this->attrs( 'loop-nxabcd1234' ) )['query_args']['posts_per_page'] );
	}

	public function test_loops_without_the_connector_marker_are_left_exactly_as_divi_built_them(): void {
		$guard = new DiviLoopGuard();

		foreach ( array( 'loop-abcdefghij', 'loop-nx', 'loop-nxabcd12345', 'LOOP-NXABCD1234', '', 'otro' ) as $id ) {
			$data = $this->data( array( 'post_status' => array( 'publish', 'inherit', 'private' ), 'posts_per_page' => 500 ) );
			$this->assertSame( $data, $guard->force( $data, $this->attrs( $id ) ), $id );
		}

		$this->assertSame( $this->data(), $guard->force( $this->data(), array() ) );
	}

	public function test_only_post_queries_are_touched_never_users_terms_or_menus(): void {
		$guard = new DiviLoopGuard();

		foreach ( array( 'user_roles', 'terms', 'menus', 'users', 'repeater_x' ) as $type ) {
			$data = $this->data( array(), $type );
			$this->assertSame( $data, $guard->force( $data, $this->attrs( 'loop-nxabcd1234' ) ), $type );
		}
	}

	public function test_malformed_filter_input_is_returned_unchanged(): void {
		$guard = new DiviLoopGuard();

		$this->assertSame( 'x', $guard->force( 'x', $this->attrs( 'loop-nxabcd1234' ) ) );
		$this->assertSame( array(), $guard->force( array(), $this->attrs( 'loop-nxabcd1234' ) ) );
		$this->assertSame( array( 'query_type' => 'post_types' ), $guard->force( array( 'query_type' => 'post_types' ), $this->attrs( 'loop-nxabcd1234' ) ) );
		$data = $this->data();
		$this->assertSame( $data, $guard->force( $data, 'no es un arreglo' ) );
		$this->assertSame( $data, $guard->force( $data, array( 'module' => array( 'advanced' => array( 'loop' => 'x' ) ) ) ) );
	}
}
