<?php
/**
 * Referencias `divi/global-layout` (T10, S1/S7): el validador las admite sólo si se le pide, con la forma exacta que escribe
 * Divi 5.19.1 (`{"globalModule":"<id>","blockName":"<módulo>"}`, L2/L4) y sólo hacia un ítem global publicado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5BlockBuilder;
use NexoDev\NexoMCP\Builders\DiviModuleCatalog;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use PHPUnit\Framework\TestCase;

final class DiviGlobalRefValidatorTest extends TestCase {

	/**
	 * Ítems globales publicados: id => bloque raíz.
	 *
	 * @var array<int, string>
	 */
	private array $globals = array(
		900 => 'divi/text',
		901 => 'divi/row',
		902 => 'divi/section',
	);

	private function validator( bool $with_refs = true ): DiviTreeValidator {
		$validator = new DiviTreeValidator(
			static fn( string $name ): bool => false,
			static fn( int $id ) => false,
			null,
			null,
			null,
			fn( int $id ): ?string => $this->globals[ $id ] ?? null
		);

		return $with_refs ? $validator->with_global_refs() : $validator;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ref( $id = '900', string $block = 'divi/text' ): array {
		return array(
			'type'  => 'divi/global-layout',
			'attrs' => array(
				'globalModule' => $id,
				'blockName'    => $block,
			),
		);
	}

	public function test_a_reference_is_accepted_in_the_exact_shape_divi_writes_and_the_id_becomes_a_string(): void {
		$clean = $this->validator()->validate_tree( array( $this->ref( 900 ) ), 'divi/column' );

		$this->assertSame(
			array( array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => '900', 'blockName' => 'divi/text' ) ) ),
			$clean
		);
	}

	public function test_the_block_is_written_self_closing_without_builder_version(): void {
		$clean  = $this->validator()->validate_tree( array( $this->ref() ), 'divi/column' );
		$blocks = Divi5BlockBuilder::build( $clean, '5.19.1' );

		$this->assertSame(
			'<!-- wp:divi/global-layout {"globalModule":"900","blockName":"divi/text"} /-->',
			serialize_blocks( $blocks )
		);
	}

	public function test_without_asking_for_references_they_stay_denied_as_any_unknown_module(): void {
		$error = $this->validator( false )->validate_tree( array( $this->ref() ), 'divi/column' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'nexo_mcp_divi_module_denied', $error->get_error_code() );
		$this->assertFalse( DiviModuleCatalog::has( 'divi/global-layout' ) );
		$this->assertFalse( DiviModuleCatalog::has( 'divi/global-layout', true ) );
	}

	public function test_a_section_row_and_module_reference_fit_only_where_their_block_fits(): void {
		$validator = $this->validator();

		$this->assertIsArray( $validator->validate_tree( array( $this->ref( '902', 'divi/section' ) ), 'divi/placeholder' ) );
		$this->assertIsArray( $validator->validate_tree( array( $this->ref( '901', 'divi/row' ) ), 'divi/section' ) );
		$this->assertIsArray( $validator->validate_tree( array( $this->ref( '900', 'divi/text' ) ), 'divi/column' ) );

		foreach ( array( array( $this->ref( '900' ), 'divi/section' ), array( $this->ref( '901', 'divi/row' ), 'divi/column' ), array( $this->ref( '902', 'divi/section' ), 'divi/row' ) ) as $case ) {
			$error = $validator->validate_tree( array( $case[0] ), $case[1] );

			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'nexo_mcp_divi_nesting_invalid', $error->get_error_code() );
		}
	}

	public function test_an_item_that_is_not_a_published_global_or_has_another_block_is_rejected(): void {
		$validator = $this->validator();

		foreach ( array( $this->ref( '999' ), $this->ref( '900', 'divi/heading' ) ) as $ref ) {
			$error = $validator->validate_tree( array( $ref ), 'divi/column' );

			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'nexo_mcp_divi_global_ref_invalid', $error->get_error_code() );
			$this->assertSame( 'nodes[0].attrs', $error->get_error_data()['path'] );
		}
	}

	public function test_the_reference_is_closed_garbage_ids_extra_keys_children_and_presets_are_rejected(): void {
		$validator = $this->validator();
		$cases     = array(
			'text id'     => $this->ref( 'abc' ),
			'zero'        => $this->ref( '0' ),
			'negative'    => $this->ref( -3 ),
			'float'       => $this->ref( 1.5 ),
			'array'       => $this->ref( array( 900 ) ),
			'no id'       => array( 'type' => 'divi/global-layout', 'attrs' => array( 'blockName' => 'divi/text' ) ),
			'no block'    => array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => '900' ) ),
			'no attrs'    => array( 'type' => 'divi/global-layout' ),
			'local attrs' => array( 'type' => 'divi/global-layout', 'attrs' => array( 'globalModule' => '900', 'blockName' => 'divi/text', 'localAttrs' => array() ) ),
			'children'    => array_merge( $this->ref(), array( 'children' => array( $this->ref() ) ) ),
			'preset'      => array_merge( $this->ref(), array( 'preset' => 'x' ) ),
		);

		foreach ( $cases as $name => $node ) {
			$error = $validator->validate_tree( array( $node ), 'divi/column' );

			$this->assertInstanceOf( \WP_Error::class, $error, $name );
			$this->assertStringStartsWith( 'nexo_mcp_divi_', $error->get_error_code(), $name );
		}
	}

	public function test_a_lookup_that_throws_counts_as_no_such_item(): void {
		$validator = new DiviTreeValidator(
			null,
			null,
			null,
			null,
			null,
			static function ( int $id ): ?string {
				throw new \RuntimeException( 'boom' );
			}
		);

		$error = $validator->with_global_refs()->validate_tree( array( $this->ref() ), 'divi/column' );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'nexo_mcp_divi_global_ref_invalid', $error->get_error_code() );
	}

	public function test_a_regular_module_still_validates_the_same_with_references_on(): void {
		$text = array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Hola</p>' ) ) ) ) );

		$this->assertSame( $this->validator( false )->validate_tree( array( $text ), 'divi/column' ), $this->validator()->validate_tree( array( $text ), 'divi/column' ) );
		$this->assertSame(
			'<!-- wp:divi/text {"builderVersion":"5.19.1","content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eHola\\u003c/p\\u003e"}}}} /-->',
			trim( serialize_blocks( Divi5BlockBuilder::build( array( $text ), '5.19.1' ) ) )
		);
	}
}
