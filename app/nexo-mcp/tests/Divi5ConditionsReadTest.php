<?php
/**
 * Lectura de nodos de Divi 5 con condiciones de visualización (T5 de D7, S7): resumen por módulo, nunca valores crudos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;

final class Divi5ConditionsReadTest extends NexoTestCase {

	public const ID_A = '11111111-1111-4111-8111-111111111111';

	public const SECRET = 'SECRETO-DEL-VB';

	/**
	 * Lista propia: un logged_in guardado por el esquema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function ours(): array {
		$schema = new DiviConditionsSchema( null, null, null, null, null, null, static fn(): string => self::ID_A );
		$params = $schema->validate( array( 'match' => 'all', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) ) );

		if ( $params instanceof \WP_Error ) {
			throw new \RuntimeException( $params->get_error_message() );
		}

		return $schema->stored( $params );
	}

	/**
	 * Lista hecha en el Visual Builder con un tipo que el conector no escribe y un valor libre.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function foreign(): array {
		return array(
			array(
				'id'                => 'abc',
				'conditionName'     => 'customField',
				'conditionSettings' => array( 'enableCondition' => 'on', 'displayRule' => 'is', 'customFieldName' => 'precio', 'customFieldValue' => self::SECRET ),
				'operator'          => 'OR',
			),
		);
	}

	/**
	 * Pone `module.decoration.conditions.desktop.value` en el bloque de esa ruta.
	 *
	 * @param array<int|string, mixed> $list Lista guardada.
	 */
	public static function with_conditions( string $content, string $path, array $list ): string {
		$blocks = parse_blocks( $content );
		$block  = Divi5Tree::locate_block( $blocks, $path );

		if ( null === $block ) {
			throw new \RuntimeException( 'ruta inexistente ' . $path );
		}

		$block['attrs']['module']['decoration']['conditions']['desktop']['value'] = $list;
		$replaced = Divi5Tree::replace_block( $blocks, $path, $block );

		return serialize_blocks( (array) $replaced );
	}

	/**
	 * Página de Divi5SectionEditorTest con condiciones propias en el texto de A (0.0.0.0.0) y ajenas en la columna de B (0.1.0.0).
	 */
	public static function page(): string {
		return self::with_conditions( self::with_conditions( Divi5SectionEditorTest::page(), '0.0.0.0.0', self::ours() ), '0.1.0.0', self::foreign() );
	}

	private function post( string $content ): \WP_Post {
		return new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $content ) );
	}

	/**
	 * @return array<string, \NexoDev\NexoMCP\Builders\BuilderNode>
	 */
	private function nodes( string $content ): array {
		$out = array();

		foreach ( ( new Divi5Adapter() )->read_nodes( $this->post( $content ) ) as $node ) {
			$out[ $node->id ] = $node;
		}

		return $out;
	}

	public function test_a_module_with_our_conditions_reads_back_a_summary(): void {
		$nodes = $this->nodes( self::page() );

		$summary = $nodes['0.0.0.0.0']->attributes['conditions'] ?? null;
		$this->assertIsArray( $summary );
		$this->assertTrue( $summary['ours'] );
		$this->assertSame( 'all', $summary['match'] );
		$this->assertSame( 1, $summary['total'] );
		$this->assertSame( 'loggedInStatus', $summary['conditions'][0]['type'] );
		$this->assertTrue( $summary['conditions'][0]['supported'] );
		$this->assertIsString( $summary['conditions'][0]['summary'] );
		$this->assertSame( ( new DiviConditionsSchema() )->summary( self::ours() ), $summary );
	}

	public function test_a_foreign_list_is_summarized_without_its_raw_values(): void {
		$summary = $this->nodes( self::page() )['0.1.0.0']->attributes['conditions'] ?? null;

		$this->assertIsArray( $summary );
		$this->assertFalse( $summary['ours'] );
		$this->assertSame( 'customField', $summary['conditions'][0]['type'] );
		$this->assertFalse( $summary['conditions'][0]['supported'] );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $summary ) );
		$this->assertStringNotContainsString( 'precio', (string) wp_json_encode( $summary ) );
	}

	public function test_nodes_without_conditions_read_exactly_as_before(): void {
		$plain = Divi5SectionEditorTest::page();

		$this->assertSame( Divi5Tree::build( $plain ), ( new Divi5Adapter() )->read_tree( $this->post( $plain ) ) );
		$this->assertSame( array( 'fingerprint' ), array_keys( $this->nodes( $plain )['0.0.0.0.0']->attributes ) );

		// En la página con condiciones, los demás nodos conservan sus atributos y su huella.
		$with = $this->nodes( self::page() );
		$base = $this->nodes( $plain );

		foreach ( array( '0.0.0.0', '0.1', '0.1.0', '0.1.0.0.0', '0.1.0.0.1' ) as $path ) {
			$this->assertArrayNotHasKey( 'conditions', $with[ $path ]->attributes, $path );
		}

		$this->assertSame( $base['0.1.0.0.0']->attributes, $with['0.1.0.0.0']->attributes );
	}

	public function test_an_empty_or_malformed_list_adds_no_summary(): void {
		$content = self::with_conditions( Divi5SectionEditorTest::page(), '0.0.0.0.0', array() );

		$this->assertArrayNotHasKey( 'conditions', $this->nodes( $content )['0.0.0.0.0']->attributes );
	}
}
