<?php
/**
 * T6 de D7 (R3-001 de T5): `summary()` nunca lanza con lo que guarde el Visual Builder o un tercero, ni por la lectura de
 * nodos ni por `dropped_conditions` de `edit-divi-section`.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5ConditionsJunkTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private const TEXT = '0.0.0.0.0';

	/**
	 * Valores guardados raros para `module.decoration.conditions.desktop.value` (no vacíos: los vacíos se ignoran antes).
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function junk(): array {
		$entry = static fn( array $set, $name = 'userRole', $op = 'OR' ): array => array(
			'id'                => 'x',
			'conditionName'     => $name,
			'conditionSettings' => $set,
			'operator'          => $op,
		);
		$deep  = 'fin';

		for ( $i = 0; $i < 200; $i++ ) {
			$deep = array( $deep );
		}

		return array(
			'string'                  => array( 'loggedIn' ),
			'int'                     => array( 7 ),
			'true'                    => array( true ),
			'float'                   => array( 1.5 ),
			'assoc, no list'          => array( array( 'a' => 1, 'conditionName' => 'userRole' ) ),
			'list of scalars'         => array( array( 1, 'a', null, true, 2.5 ) ),
			'list of lists'           => array( array( array( 1, 2 ), array( array() ), array( array( 'x' ) ) ) ),
			'empty entry'             => array( array( array() ) ),
			'missing keys'            => array( array( array( 'id' => 'a' ), array( 'operator' => 'AND' ) ) ),
			'name is not a string'    => array( array( $entry( array(), array( 'x' ) ), $entry( array(), 12 ), $entry( array(), null ) ) ),
			'settings not an array'   => array( array( array( 'conditionName' => 'dateTime', 'conditionSettings' => 'x', 'operator' => 'OR' ) ) ),
			'operator of wrong type'  => array( array( $entry( array(), 'userRole', array( 'AND' ) ), $entry( array(), 'userRole', 5 ) ) ),
			'role list of junk'       => array( array( $entry( array( 'userRoles' => 'admin' ) ), $entry( array( 'userRoles' => array( 1, null, array( array() ), array( 'value' => array( 'x' ) ) ) ) ) ) ),
			'dynamic posts of junk'   => array( array( $entry( array( 'dynamicPosts' => array( 'a', array( 'value' => array() ), null ), 'displayRule' => array() ), 'page' ) ) ),
			'dynamic posts not list'  => array( array( $entry( array( 'dynamicPosts' => 'x' ), 'page' ), $entry( array( 'dynamicPosts' => 5 ), 'post' ) ) ),
			'browsers of junk'        => array( array( $entry( array( 'browsers' => array( array( 'x' ), 1, null ) ), 'browser' ), $entry( array( 'operatingSystems' => 'windows' ), 'operatingSystem' ) ) ),
			'url parameter junk'      => array( array( $entry( array( 'urlParameterName' => array( 'a' ), 'urlParameterValue' => array() ), 'urlParameter' ), $entry( array( 'urlParameterName' => 5 ), 'urlParameter' ) ) ),
			'date junk'               => array(
				array(
					$entry( array( 'displayRule' => 'isAfter', 'date' => array( 'x' ), 'time' => 5, 'allDay' => array() ), 'dateTime' ),
					$entry( array( 'displayRule' => array( 'isAfter' ), 'date' => '2020-02-31', 'fromTime' => '99:99', 'untilTime' => null, 'weekdays' => 'monday' ), 'dateTime' ),
					$entry( array( 'displayRule' => 'isOnSpecificDays', 'weekdays' => array( array( 'x' ), 5, null ), 'date' => str_repeat( '9', 5000 ) ), 'dateTime' ),
					$entry( array( 'displayRule' => 'isBefore', 'date' => "2020-01-01\n", 'time' => "10:00\0" ), 'dateTime' ),
				),
			),
			'terms and products junk' => array( array( $entry( array( 'categories' => array( 'a', array( 'value' => 'x' ), array( array() ) ) ), 'categories' ), $entry( array( 'tags' => 7 ), 'tags' ), $entry( array( 'products' => array( null, array( 'value' => array() ) ) ), 'cartContents' ), $entry( array( 'products' => 'x' ), 'productStock' ) ) ),
			'weird strings'           => array( array( $entry( array( 'userRoles' => array( array( 'value' => "\xff\xfe<script>" ), array( 'value' => str_repeat( 'a', 100000 ) ), array( 'value' => "a\0b" ) ) ), "role\xff" ), $entry( array(), str_repeat( 'n', 100000 ) ), $entry( array(), "bad name\n" ) ) ),
			'huge list'               => array( array_fill( 0, 5000, $entry( array( 'userRoles' => array( array( 'value' => 'administrator' ) ) ) ) ) ),
			'huge nested settings'    => array( array( $entry( array( 'userRoles' => array_fill( 0, 5000, array( 'value' => 'administrator', 'label' => 'A' ) ) ) ) ) ),
			'deeply nested'           => array( array( $deep, $entry( array( 'userRoles' => $deep, 'dynamicPosts' => $deep, 'categories' => $deep ), 'page' ) ) ),
			'enable condition junk'   => array( array( $entry( array( 'enableCondition' => array( 'off' ) ) ), $entry( array( 'enableCondition' => null ) ) ) ),
			'nan and inf'             => array( array( $entry( array( 'userRoles' => array( array( 'value' => NAN ), array( 'value' => INF ) ) ) ) ) ),
		);
	}

	/**
	 * Lo que sobrevive a guardarse como bloque (JSON): una lista no vacía; los escalares no llegan a resumirse.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function storable(): array {
		return array_filter(
			self::junk(),
			static fn( array $case ): bool => is_array( $case[0] ) && false !== wp_json_encode( $case[0] )
		);
	}

	/**
	 * Un esquema cuyas fuentes de WordPress lanzan (un filtro de terceros, WooCommerce roto…).
	 */
	private static function throwing_schema(): DiviConditionsSchema {
		$boom = static function (): never {
			throw new \RuntimeException( 'fuente rota' );
		};

		return new DiviConditionsSchema( $boom, $boom, $boom, $boom, $boom, static fn(): bool => true );
	}

	/**
	 * Lista que `valid_stored()` intenta reconocer con las fuentes (roles, tipos, términos, entradas, productos).
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>}>
	 */
	public static function looks_ours(): array {
		$id = static fn( int $n ): string => '11111111-1111-4111-8111-11111111111' . $n;
		$of = static fn( string $name, array $set ): array => array(
			array(
				'id'                => $id( 1 ),
				'conditionName'     => $name,
				'conditionSettings' => $set + array( 'enableCondition' => 'on' ),
				'operator'          => 'AND',
			),
		);

		return array(
			'role'      => array( $of( 'userRole', array( 'displayRule' => 'is', 'userRoles' => array( array( 'value' => 'administrator', 'label' => 'A' ) ), 'userIds' => '' ) ) ),
			'post type' => array( $of( 'postType', array( 'displayRule' => 'is', 'postTypes' => array( array( 'value' => 'page', 'label' => 'P' ) ) ) ) ),
			'category'  => array( $of( 'categories', array( 'displayRule' => 'is', 'categories' => array( array( 'value' => 5, 'label' => 'C', 'groupSlug' => 'category' ) ) ) ) ),
			'post'      => array( $of( 'page', array( 'displayRule' => 'is', 'dynamicPosts' => array( array( 'value' => 5, 'label' => 'P' ) ), 'postTypeLabel' => 'page' ) ) ),
			'product'   => array( $of( 'productStock', array( 'displayRule' => 'isInStock', 'products' => array( array( 'value' => 7, 'label' => 'X' ) ) ) ) ),
		);
	}

	private function with_raw( $value ): string {
		$blocks = parse_blocks( Divi5SectionEditorTest::page() );
		$block  = Divi5Tree::locate_block( $blocks, self::TEXT );
		$this->assertIsArray( $block );
		$block['attrs']['module']['decoration']['conditions']['desktop']['value'] = $value;

		return serialize_blocks( (array) Divi5Tree::replace_block( $blocks, self::TEXT, $block ) );
	}

	/**
	 * @dataProvider junk
	 *
	 * @param mixed $value Valor guardado.
	 */
	public function test_summary_never_throws_and_keeps_its_shape( $value ): void {
		$summary = ( new DiviConditionsSchema() )->summary( $value );

		$this->assertFalse( $summary['ours'] );
		$this->assertIsInt( $summary['total'] );
		$this->assertLessThanOrEqual( 50, count( $summary['conditions'] ) );
		$this->assertNotFalse( wp_json_encode( $summary ), 'el resumen se puede serializar' );

		foreach ( $summary['conditions'] as $row ) {
			$this->assertIsString( $row['type'] );
			$this->assertIsBool( $row['enabled'] );
			$this->assertIsBool( $row['supported'] );
			$this->assertIsString( $row['summary'] );
			$this->assertLessThanOrEqual( 400, strlen( $row['summary'] ), 'el resumen no crece con el valor guardado' );
		}
	}

	/**
	 * @dataProvider looks_ours
	 *
	 * @param array<int, array<string, mixed>> $value Lista guardada.
	 */
	public function test_summary_survives_failing_sources_and_says_it_is_not_ours( array $value ): void {
		$summary = self::throwing_schema()->summary( $value );

		$this->assertFalse( $summary['ours'] );
		$this->assertSame( 1, $summary['total'] );
		$this->assertSame( 'all', $summary['match'] );
		$this->assertCount( 1, $summary['conditions'] );
	}

	/**
	 * @dataProvider looks_ours
	 *
	 * @param array<int, array<string, mixed>> $value Lista guardada.
	 */
	public function test_get_page_structure_read_survives_failing_sources( array $value ): void {
		$post  = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $this->with_raw( $value ) ) );
		$nodes = array();

		foreach ( ( new Divi5Adapter( self::throwing_schema() ) )->read_nodes( $post ) as $node ) {
			$nodes[ $node->id ] = $node;
		}

		$this->assertFalse( $nodes[ self::TEXT ]->attributes['conditions']['ours'] );
	}

	/**
	 * @dataProvider looks_ours
	 *
	 * @param array<int, array<string, mixed>> $value Lista guardada.
	 */
	public function test_replace_and_delete_survive_failing_sources( array $value ): void {
		foreach ( array( 'replace', 'delete' ) as $operation ) {
			$result = $this->section( $this->with_raw( $value ), $operation, '0.0', self::throwing_schema() );

			$this->assertIsArray( $result, $operation );
			$this->assertFalse( $result['dropped_conditions'][0]['summary']['ours'], $operation );
		}
	}

	/**
	 * @dataProvider storable
	 *
	 * @param mixed $value Valor guardado.
	 */
	public function test_get_page_structure_read_survives_it( $value ): void {
		$post  = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $this->with_raw( $value ) ) );
		$nodes = array();

		foreach ( ( new Divi5Adapter() )->read_nodes( $post ) as $node ) {
			$nodes[ $node->id ] = $node;
		}

		$this->assertArrayHasKey( self::TEXT, $nodes );
		$this->assertIsArray( $nodes[ self::TEXT ]->attributes['conditions'] ?? null );
		$this->assertNotFalse( wp_json_encode( $nodes[ self::TEXT ]->attributes ) );
		$this->assertNotEmpty( ( new Divi5Adapter() )->read_tree( $post ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function section( string $content, string $operation, string $path, ?DiviConditionsSchema $schema = null ) {
		$cfg = $this->settings( array( 'read_only' => false ) );
		\WP_Test_State::$posts                   = array();
		\WP_Test_State::$meta                    = array();
		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post( array( 'ID' => self::ORIGINAL, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $content ) );
		$post   = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$editor = new Divi5SectionEditor(
			$cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $cfg ),
			new DiviTreeValidator( static fn(): bool => false ),
			static fn() => true,
			static fn(): bool => true,
			null,
			static fn(): string => '5.19.1',
			null,
			$schema
		);
		$input  = array(
			'operation'   => $operation,
			'path'        => $path,
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path )['fingerprint'] ?? '' ),
		);

		if ( 'delete' !== $operation ) {
			$input['sections'] = Divi5SectionEditorTestFixtures::sections( 'Nuevo' );
		}

		return $editor->edit( $post, $input );
	}

	/**
	 * @dataProvider storable
	 *
	 * @param mixed $value Valor guardado.
	 */
	public function test_replace_and_delete_report_dropped_conditions_with_it( $value ): void {
		$content = $this->with_raw( $value );

		foreach ( array( 'replace', 'delete' ) as $operation ) {
			$result = $this->section( $content, $operation, '0.0' );

			$this->assertIsArray( $result, $operation );
			$this->assertCount( 1, $result['dropped_conditions'], $operation );
			$this->assertSame( self::TEXT, $result['dropped_conditions'][0]['path'] );
			$this->assertFalse( $result['dropped_conditions'][0]['summary']['ours'] );
		}
	}
}
