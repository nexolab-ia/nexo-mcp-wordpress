<?php
/**
 * T5 de D7 (S7): `edit-divi-section` replace/delete avisa con `dropped_conditions` de las condiciones que se pierden, y
 * `edit-divi-module` (contenido y diseño) y los presets conservan las existentes byte a byte.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5ConditionsDroppedTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg = $this->settings( array( 'read_only' => false ) );
		$this->put( Divi5ConditionsReadTest::page() );
	}

	private function put( string $content ): void {
		\WP_Test_State::$posts                   = array();
		\WP_Test_State::$meta                    = array();
		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post( array( 'ID' => self::ORIGINAL, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $content ) );
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @return mixed
	 */
	private function conditions_at( string $content, string $path ) {
		$block = Divi5Tree::locate_block( parse_blocks( $content ), $path );
		$this->assertIsArray( $block );

		return $block['attrs']['module']['decoration']['conditions'] ?? null;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function section( string $operation, string $path, array $extra = array() ) {
		$post    = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$editor  = new Divi5SectionEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			new DiviTreeValidator( static fn(): bool => false ),
			static fn() => true,
			static fn(): bool => true,
			null,
			static fn(): string => '5.19.1'
		);
		$input   = array(
			'operation'   => $operation,
			'path'        => $path,
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path )['fingerprint'] ?? '' ),
		);

		if ( 'delete' !== $operation ) {
			$input['sections'] = Divi5SectionEditorTestFixtures::sections( 'Nuevo' );
		}

		return $editor->edit( $post, array_merge( $input, $extra ) );
	}

	public function test_replace_lists_every_condition_of_the_replaced_subtree(): void {
		$result = $this->section( 'replace', '0.0' );

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				array(
					'path'    => '0.0.0.0.0',
					'type'    => 'divi/text',
					'summary' => ( new DiviConditionsSchema() )->summary( Divi5ConditionsReadTest::ours() ),
				),
			),
			$result['dropped_conditions']
		);
		$this->assertStringNotContainsString( 'loggedInStatus', $this->content( (int) $result['id'] ), 'la escritura se hizo y la condición ya no está' );
		$this->assertStringContainsString( 'Nuevo', $this->content( (int) $result['id'] ) );
	}

	public function test_delete_lists_the_foreign_conditions_by_path_in_document_order_without_raw_values(): void {
		$result = $this->section( 'delete', '0.1' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['dropped_conditions'] );
		$this->assertSame( '0.1.0.0', $result['dropped_conditions'][0]['path'] );
		$this->assertSame( 'divi/column', $result['dropped_conditions'][0]['type'] );
		$this->assertFalse( $result['dropped_conditions'][0]['summary']['ours'] );
		$this->assertSame( 'customField', $result['dropped_conditions'][0]['summary']['conditions'][0]['type'] );
		$this->assertStringNotContainsString( Divi5ConditionsReadTest::SECRET, (string) wp_json_encode( $result ) );
		$this->assertSame( '0.1', $result['removed'] );
	}

	public function test_replacing_the_module_that_carries_the_conditions_lists_it_and_a_node_with_two_levels_lists_both_in_order(): void {
		$both = Divi5ConditionsReadTest::with_conditions( Divi5ConditionsReadTest::page(), '0.0.0', Divi5ConditionsReadTest::ours() );
		$this->put( $both );

		$result = $this->section( 'replace', '0.0' );

		$this->assertIsArray( $result );
		$this->assertSame( array( '0.0.0', '0.0.0.0.0' ), array_column( $result['dropped_conditions'], 'path' ) );
		$this->assertSame( array( 'divi/row', 'divi/text' ), array_column( $result['dropped_conditions'], 'type' ) );

		$this->put( Divi5ConditionsReadTest::page() );
		$leaf = $this->section( 'replace', '0.0.0.0', array( 'sections' => array( array( 'type' => 'divi/column', 'children' => array( array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>X</p>' ) ) ) ) ) ) ) ) ) );
		$this->assertIsArray( $leaf );
		$this->assertSame( array( '0.0.0.0.0' ), array_column( $leaf['dropped_conditions'], 'path' ), 'sólo las del subárbol de la columna: la fila 0.0.0 no se toca' );
	}

	public function test_without_conditions_in_the_subtree_there_is_no_dropped_conditions_key(): void {
		$this->put( Divi5SectionEditorTest::page() );

		foreach ( array( $this->section( 'replace', '0.1' ), $this->section( 'delete', '0.0' ) ) as $result ) {
			$this->assertIsArray( $result );
			$this->assertArrayNotHasKey( 'dropped_conditions', $result );
		}
	}

	public function test_conditions_outside_the_replaced_subtree_are_not_listed_and_stay_byte_for_byte(): void {
		$result = $this->section( 'replace', '0.1' );

		$this->assertIsArray( $result );
		$this->assertSame( array( '0.1.0.0' ), array_column( $result['dropped_conditions'], 'path' ) );

		$after = $this->content( (int) $result['id'] );
		$this->assertSame( $this->conditions_at( Divi5ConditionsReadTest::page(), '0.0.0.0.0' ), $this->conditions_at( $after, '0.0.0.0.0' ) );
	}

	public function test_insert_never_reports_dropped_conditions_and_keeps_the_neighbours(): void {
		$result = $this->section( 'insert', '0.0', array( 'position' => 'before' ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'dropped_conditions', $result );
		$this->assertSame( $this->conditions_at( Divi5ConditionsReadTest::page(), '0.0.0.0.0' ), $this->conditions_at( $this->content( (int) $result['id'] ), '0.1.0.0.0' ) );
	}

	public function test_a_rejected_replace_reports_nothing_and_writes_nothing(): void {
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
		$result = $this->section( 'replace', '0.0', array( 'fingerprint' => 'viejo' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}

	// --- PRESERVE: edit-divi-module y presets no tocan las condiciones ---------------------------------------------

	private function module_editor(): Divi5ModuleEditor {
		$catalog = array(
			'module' => array( 'divi/text' => array( 'default' => '', 'items' => array( 'p-text-1' => array( 'id' => 'p-text-1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ) ) ) ),
			'group'  => array(),
		);

		return new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			new DiviTreeValidator( static fn(): bool => false, static fn( int $id ) => false, static fn(): array => $catalog, static fn(): array => array() ),
			static fn() => true,
			static fn(): bool => true
		);
	}

	/**
	 * @param array<string, mixed> $input Parámetros.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_module( array $input ) {
		$post = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->module_editor()->edit(
			$post,
			array_merge(
				array(
					'path'        => '0.0.0.0.0',
					'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( $post->post_content ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
				),
				$input
			)
		);
	}

	public function test_edit_divi_module_content_keeps_the_conditions_byte_for_byte(): void {
		$result = $this->edit_module( array( 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Cambiado</p>' ) ) ) ) ) );

		$this->assertIsArray( $result );
		$after = $this->content( (int) $result['id'] );
		$this->assertStringContainsString( 'Cambiado', $after );
		$this->assertSame( wp_json_encode( Divi5ConditionsReadTest::ours() ), wp_json_encode( $this->conditions_at( $after, '0.0.0.0.0' )['desktop']['value'] ) );
		$this->assertSame( wp_json_encode( $this->conditions_at( Divi5ConditionsReadTest::page(), '0.1.0.0' ) ), wp_json_encode( $this->conditions_at( $after, '0.1.0.0' ) ), 'las de otros módulos tampoco' );
	}

	public function test_edit_divi_module_design_keeps_the_conditions_byte_for_byte(): void {
		$result = $this->edit_module( array( 'design' => array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '20px' ) ) ) ) ) ) ) ) );

		$this->assertIsArray( $result );
		$after = $this->content( (int) $result['id'] );
		$this->assertStringContainsString( '20px', $after );
		$this->assertSame( wp_json_encode( Divi5ConditionsReadTest::ours() ), wp_json_encode( $this->conditions_at( $after, '0.0.0.0.0' )['desktop']['value'] ) );
	}

	public function test_applying_a_preset_keeps_the_conditions_byte_for_byte(): void {
		$result = $this->edit_module( array( 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertIsArray( $result );
		$after = $this->content( (int) $result['id'] );
		$this->assertStringContainsString( 'p-text-1', $after );
		$this->assertSame( wp_json_encode( Divi5ConditionsReadTest::ours() ), wp_json_encode( $this->conditions_at( $after, '0.0.0.0.0' )['desktop']['value'] ) );
	}
}
