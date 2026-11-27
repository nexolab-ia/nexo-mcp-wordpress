<?php
/**
 * Dónde se usa un ítem global (T10): coincidencia exacta por id, qué estados y tipos cuentan, copias de trabajo, lotes y permisos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviLibraryUsage;

final class DiviLibraryUsageTest extends NexoTestCase {

	private function ref( $id ): string {
		return '<!-- wp:divi/global-layout {"globalModule":"' . $id . '","blockName":"divi/text"} /-->';
	}

	/**
	 * @param array<string, mixed> $row Fila.
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $content, array $row = array() ): array {
		return array_merge( array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'P' . $id, 'post_content' => $content ), $row );
	}

	public function test_the_match_is_exact_by_id_in_every_form_divi_writes(): void {
		$this->assertTrue( DiviLibraryUsage::references( $this->ref( 12 ), 12 ) );
		$this->assertTrue( DiviLibraryUsage::references( '<!-- wp:divi/global-layout {"globalModule":12} /-->', 12 ), 'id como número' );
		$this->assertTrue( DiviLibraryUsage::references( '<!-- wp:divi/global-layout {\"globalModule\":\"12\"} /-->', 12 ), 'atributos con comillas escapadas' );
		$this->assertTrue( DiviLibraryUsage::references( '[et_pb_text global_module="12"]x[/et_pb_text]', 12 ), 'shortcode de Divi 4' );
		$this->assertFalse( DiviLibraryUsage::references( $this->ref( 123 ), 12 ) );
		$this->assertFalse( DiviLibraryUsage::references( $this->ref( 912 ), 12 ) );
		$this->assertFalse( DiviLibraryUsage::references( '[et_pb_text global_module="123"]', 12 ) );
		$this->assertFalse( DiviLibraryUsage::references( '<!-- wp:divi/text {"content":"globalModule 12"} /-->', 12 ) );
		$this->assertFalse( DiviLibraryUsage::references( '', 12 ) );
	}

	public function test_only_live_contents_that_reference_the_item_count_and_a_working_copy_is_flagged(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		update_post_meta( 101, '_nexo_mcp_draft_of', 100 );
		$rows = array(
			$this->row( 100, $this->ref( 803 ) ),
			$this->row( 101, $this->ref( 803 ), array( 'post_status' => 'draft' ) ),
			$this->row( 102, $this->ref( 8030 ) ),
			$this->row( 103, $this->ref( 803 ), array( 'post_status' => 'trash' ) ),
			$this->row( 104, $this->ref( 803 ), array( 'post_status' => 'auto-draft' ) ),
			$this->row( 105, $this->ref( 803 ), array( 'post_type' => 'revision', 'post_status' => 'inherit' ) ),
			$this->row( 803, $this->ref( 803 ), array( 'post_type' => 'et_pb_layout' ) ),
			$this->row( 106, $this->ref( 803 ), array( 'post_type' => 'et_header_layout', 'post_status' => 'publish' ) ),
		);

		$usage = ( new DiviLibraryUsage( static fn( int $after, int $item ): array => array_values( array_filter( $rows, static fn( array $r ): bool => $r['ID'] > $after ) ), static fn( int $id ): bool => 106 !== $id ) )->of( 803 );

		$this->assertSame( 3, $usage['count'] );
		$this->assertFalse( $usage['truncated'] );
		$this->assertSame( array( 100, 101, 106 ), array_column( $usage['pages'], 'id' ) );
		$this->assertSame( 100, $usage['pages'][1]['working_copy_of'] );
		$this->assertArrayNotHasKey( 'working_copy_of', $usage['pages'][0] );
		$this->assertSame( array( true, true, false ), array_column( $usage['pages'], 'editable' ) );
		$this->assertSame( array( 106 ), $usage['not_editable'] );
		$this->assertSame( 1, $usage['not_editable_count'] );
	}

	public function test_a_big_site_is_read_in_batches_and_the_list_is_capped_but_every_content_is_counted_and_checked(): void {
		$rows    = array();
		$batches = 0;

		for ( $i = 1; $i <= 230; $i++ ) {
			$rows[] = $this->row( $i, $this->ref( 900 ) );
		}

		$usage = ( new DiviLibraryUsage(
			static function ( int $after, int $item ) use ( $rows, &$batches ): array {
				++$batches;

				return array_slice( array_values( array_filter( $rows, static fn( array $r ): bool => $r['ID'] > $after ) ), 0, 100 );
			},
			static fn( int $id ): bool => $id < 225
		) )->of( 900 );

		$this->assertSame( 3, $batches );
		$this->assertSame( 230, $usage['count'] );
		$this->assertCount( DiviLibraryUsage::MAX_LISTED, $usage['pages'] );
		$this->assertTrue( $usage['truncated'] );
		$this->assertSame( 6, $usage['not_editable_count'], 'también los que no se listan' );
		$this->assertSame( array( 225, 226, 227, 228, 229, 230 ), $usage['not_editable'] );
	}

	public function test_garbage_from_the_source_never_throws_and_counts_as_no_use(): void {
		foreach ( array( static fn() => null, static fn() => 'x', static function () {
			throw new \RuntimeException( 'boom' );
		}, static fn() => array( 'x', array( 'nope' => 1 ), array( 'ID' => 'abc' ) ) ) as $fetch ) {
			$usage = ( new DiviLibraryUsage( $fetch ) )->of( 5 );

			$this->assertSame( 0, $usage['count'] );
			$this->assertSame( array(), $usage['pages'] );
		}
	}

	public function test_a_failing_source_is_unknown_while_junk_rows_are_just_ignored(): void {
		foreach ( array( static fn() => null, static fn() => 'x', static function () {
			throw new \RuntimeException( 'boom' );
		} ) as $fetch ) {
			$this->assertTrue( ( new DiviLibraryUsage( $fetch ) )->of( 5 )['unknown'] );
		}

		$this->assertFalse( ( new DiviLibraryUsage( static fn() => array( 'x', array( 'ID' => 'abc' ) ) ) )->of( 5 )['unknown'] );
	}

	public function test_the_total_and_the_permission_check_include_what_divi_detaches_but_the_list_does_not(): void {
		$rows  = array(
			$this->row( 1, $this->ref( 5 ) ),
			$this->row( 2, $this->ref( 5 ), array( 'post_status' => 'trash' ) ),
			$this->row( 3, $this->ref( 5 ), array( 'post_type' => 'revision', 'post_status' => 'inherit' ) ),
		);
		$usage = ( new DiviLibraryUsage( static fn( int $after ): array => 0 === $after ? $rows : array(), static fn( int $id ): bool => 1 === $id ) )->of( 5 );

		$this->assertSame( 1, $usage['count'] );
		$this->assertSame( 3, $usage['total'] );
		$this->assertSame( array( 1 ), $usage['ids'] );
		$this->assertSame( array( 2, 3 ), $usage['not_editable'] );
	}

	public function test_a_permission_check_that_throws_counts_as_not_editable(): void {
		$usage = ( new DiviLibraryUsage(
			fn( int $after, int $item ): array => 0 === $after ? array( $this->row( 7, $this->ref( 5 ) ) ) : array(),
			static function ( int $id ): bool {
				throw new \RuntimeException( 'boom' );
			}
		) )->of( 5 );

		$this->assertSame( array( 7 ), $usage['not_editable'] );
	}
}
