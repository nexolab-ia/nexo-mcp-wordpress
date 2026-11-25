<?php
/**
 * set-divi-conditions (T4 de D7, S6), parte del editor: escribe `module.decoration.conditions.desktop.value` con el
 * esquema cerrado, en la copia de trabajo, y nada más del bloque.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5ConditionsEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private const PLACEHOLDER = '0';
	private const SECTION     = '0.0';
	private const COLUMN      = '0.0.0.0';
	private const HEADING     = '0.0.0.0.0';
	private const TEXT        = '0.0.0.0.1';
	private const CODE        = '0.0.0.0.2';

	private const ID_A = '11111111-1111-4111-8111-111111111111';
	private const ID_B = '22222222-2222-4222-8222-222222222222';
	private const ID_N = '33333333-3333-4333-8333-333333333333';

	private const TOKEN = '$variable({"type":"content","value":{"name":"site_title","settings":{"enable_html":"off"}}})$';

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg = $this->settings( array( 'read_only' => false ) );
		$this->put_page( self::page() );
	}

	private function schema(): DiviConditionsSchema {
		return new DiviConditionsSchema(
			static fn( string $type ): ?array => array( 'post' => array( 'public' => true, 'label' => 'Entradas' ), 'page' => array( 'public' => true, 'label' => 'Páginas' ) )[ $type ] ?? null,
			static fn( string $role ): ?string => array( 'subscriber' => 'Suscriptor', 'editor' => 'Editor' )[ $role ] ?? null,
			static fn( int $id, string $taxonomy ): ?string => null,
			static fn( int $id ): ?array => null,
			static fn( int $id ): ?string => null,
			static fn(): bool => false,
			static fn(): string => self::ID_N
		);
	}

	/**
	 * @param array<string, mixed> $extra Atributos extra del título.
	 */
	private static function page( array $extra = array(), string $text_extra = '' ): string {
		$block = static fn( string $name, array $attrs ): string => '<!-- wp:divi/' . $name . ( array() === $attrs ? '' : ' ' . wp_json_encode( $attrs ) ) . ' /-->' . "\n";
		$raw   = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. $block( 'heading', array_merge( array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Hola' ), 'tablet' => array( 'value' => 'Hola tablet' ) ) ) ), $extra ) )
			. $block( 'text', array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . $text_extra . '</p>' ) ) ) ) )
			. $block( 'code', array() )
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	private function put_page( string $content ): void {
		\WP_Test_State::$posts = array();
		\WP_Test_State::$meta  = array();

		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post(
			array(
				'ID'           => self::ORIGINAL,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Inicio',
				'post_content' => $content,
			)
		);
	}

	private function editor(): Divi5ModuleEditor {
		$validator = new DiviTreeValidator( static fn(): bool => false, static fn() => false, static fn(): array => array(), static fn(): array => array() );

		return new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$validator,
			static fn() => true,
			static fn(): bool => true,
			null,
			null,
			null,
			$this->schema()
		);
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function attrs( string $content, string $path ): array {
		$block = Divi5Tree::locate_block( parse_blocks( $content ), $path );
		$this->assertIsArray( $block );

		return $block['attrs'];
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param mixed $conditions {match, conditions}.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set( string $path, $conditions, array $extra = array(), int $id = self::ORIGINAL ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path );

		return $this->editor()->edit(
			$post,
			array_merge(
				array(
					'path'        => $path,
					'fingerprint' => (string) ( $node['fingerprint'] ?? '' ),
					'conditions'  => $conditions,
				),
				$extra
			)
		);
	}

	/**
	 * @param mixed $result Resultado.
	 */
	private function assertRejected( string $code, $result, string $before ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( $before, $this->snapshot(), 'no escribe nada ni crea copia' );

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function logged_in(): array {
		return array( 'match' => 'all', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function foreign_list(): array {
		return array( array( 'id' => self::ID_A, 'conditionName' => 'customField', 'conditionSettings' => array( 'enableCondition' => 'on', 'displayRule' => 'is', 'customFieldName' => 'precio' ), 'operator' => 'OR' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $list Lista guardada.
	 * @return array<string, mixed>
	 */
	private static function stored_attrs( array $list ): array {
		return array( 'module' => array( 'decoration' => array( 'conditions' => array( 'desktop' => array( 'value' => $list ) ) ) ) );
	}

	public function test_it_writes_only_the_conditions_value_in_the_working_copy(): void {
		$result = $this->set( self::HEADING, array( 'match' => 'any', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_out' ), array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$this->assertSame( self::page(), $this->content( 100 ), 'el original no cambia' );
		$this->assertSame( 'any', $result['match'] );
		$this->assertArrayNotHasKey( 'replaced', $result );
		$this->assertArrayNotHasKey( 'removed', $result );

		$attrs = $this->attrs( $this->content( (int) $result['id'] ), self::HEADING );
		$value = $attrs['module']['decoration']['conditions']['desktop']['value'];
		$this->assertSame( $value, $result['conditions'] );
		$this->assertCount( 2, $value );
		$this->assertSame( 'loggedInStatus', $value[0]['conditionName'] );
		$this->assertEquals( array( 'enableCondition' => 'on', 'displayRule' => 'loggedOut' ), $value[0]['conditionSettings'] );
		$this->assertSame( 'OR', $value[0]['operator'] );
		$this->assertSame( 'OR', $value[1]['operator'] );
		$this->assertSame( self::ID_N, $value[0]['id'] );

		// Lo demás del bloque queda igual (también tablet).
		unset( $attrs['module'] );
		$this->assertSame( $this->attrs( self::page(), self::HEADING ), $attrs );
		$this->assertSame( (string) Divi5Tree::locate( Divi5Tree::build( $this->content( (int) $result['id'] ) ), self::HEADING )['fingerprint'], $result['fingerprint'] );
	}

	public function test_match_all_writes_and_uniform_and_operator(): void {
		$result = $this->set( self::TEXT, array( 'match' => 'all', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ), array( 'type' => 'url_parameter', 'rule' => 'exists', 'name' => 'promo' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'AND', 'AND' ), array_column( $result['conditions'], 'operator' ) );
	}

	public function test_the_section_and_the_other_catalog_modules_admit_conditions_but_the_placeholder_does_not(): void {
		$this->assertIsArray( $this->set( self::SECTION, self::logged_in() ) );

		$this->put_page( self::page() );
		$before = $this->snapshot();
		$error  = $this->assertRejected( 'nexo_mcp_divi_module_denied', $this->set( self::PLACEHOLDER, self::logged_in() ), $before );
		$this->assertStringContainsString( 'divi/placeholder', $error->get_error_message() );

		$this->assertRejected( 'nexo_mcp_divi_module_denied', $this->set( self::CODE, self::logged_in() ), $before );
	}

	public function test_an_invalid_set_is_rejected_with_the_path_and_writes_nothing(): void {
		$before = $this->snapshot();

		$bad = array(
			'unknown type' => array( 'match' => 'all', 'conditions' => array( array( 'type' => 'customField', 'rule' => 'is' ) ) ),
			'bad role'     => array( 'match' => 'all', 'conditions' => array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'nope' ) ) ) ),
			'bad date'     => array( 'match' => 'all', 'conditions' => array( array( 'type' => 'date_time', 'rule' => 'after', 'date' => '2026-02-31' ) ) ),
			'no match'     => array( 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) ),
			'six'          => array( 'match' => 'all', 'conditions' => array_fill( 0, 6, array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ) ),
		);

		foreach ( $bad as $label => $conditions ) {
			$error = $this->assertRejected( DiviConditionsSchema::CODE, $this->set( self::HEADING, $conditions ), $before );
			$this->assertArrayHasKey( 'path', (array) $error->get_error_data(), $label );
		}
	}

	public function test_it_keeps_the_ids_of_conditions_that_did_not_change(): void {
		$first = $this->set( self::HEADING, self::logged_in() );
		$this->assertIsArray( $first );
		$copy = (int) $first['id'];

		$again = $this->set(
			self::HEADING,
			array( 'match' => 'all', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ), array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ) ) ),
			array(),
			$copy
		);

		$this->assertIsArray( $again );
		$this->assertSame( self::ID_N, $again['conditions'][0]['id'], 'la misma condición conserva su id' );
		$this->assertNotSame( self::ID_N, $again['conditions'][1]['id'] );
	}

	public function test_an_empty_list_removes_the_key_and_prunes_the_empty_parents(): void {
		$first = $this->set( self::HEADING, self::logged_in() );
		$this->assertIsArray( $first );
		$copy = (int) $first['id'];

		$result = $this->set( self::HEADING, array( 'match' => 'all', 'conditions' => array() ), array(), $copy );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertSame( array(), $result['conditions'] );
		$this->assertArrayNotHasKey( 'replaced', $result );
		$this->assertSame( $this->attrs( self::page(), self::HEADING ), $this->attrs( $this->content( $copy ), self::HEADING ), 'sin module ni decoration vacíos' );
	}

	public function test_removing_keeps_the_sibling_decoration_and_the_other_breakpoints(): void {
		$attrs = array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '10px' ) ) ) ), 'conditions' => array( 'desktop' => array( 'value' => array() ), 'tablet' => array( 'value' => array( 'x' => 1 ) ) ) ) ) );
		$this->put_page( self::page( $attrs ) );
		$first = $this->set( self::HEADING, self::logged_in() );
		$this->assertIsArray( $first );

		$result = $this->set( self::HEADING, array( 'match' => 'all', 'conditions' => array() ), array(), (int) $first['id'] );

		$this->assertIsArray( $result );
		$after = $this->attrs( $this->content( (int) $result['id'] ), self::HEADING );
		$this->assertSame( array( 'margin' => array( 'top' => '10px' ) ), $after['module']['decoration']['spacing']['desktop']['value'] );
		$this->assertSame( array( 'tablet' => array( 'value' => array( 'x' => 1 ) ) ), $after['module']['decoration']['conditions'] );
	}

	public function test_a_list_that_is_not_ours_needs_replace_foreign_and_returns_its_summary(): void {
		$this->put_page( self::page( self::stored_attrs( self::foreign_list() ) ) );
		$before = $this->snapshot();

		$error = $this->assertRejected( 'nexo_mcp_divi_conditions_foreign', $this->set( self::HEADING, self::logged_in() ), $before );
		$data  = (array) $error->get_error_data();
		$this->assertFalse( $data['summary']['ours'] );
		$this->assertSame( 1, $data['summary']['total'] );
		$this->assertSame( 'customField', $data['summary']['conditions'][0]['type'] );
		$this->assertStringNotContainsString( 'precio', (string) wp_json_encode( $data ), 'el resumen no trae textos libres' );

		$this->assertRejected( 'nexo_mcp_divi_conditions_foreign', $this->set( self::HEADING, self::logged_in(), array( 'replace_foreign' => false ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_conditions_foreign', $this->set( self::HEADING, array( 'match' => 'all', 'conditions' => array() ) ), $before );
	}

	public function test_replace_foreign_true_replaces_it_and_reports_the_previous_summary(): void {
		$this->put_page( self::page( self::stored_attrs( self::foreign_list() ) ) );

		$result = $this->set( self::HEADING, self::logged_in(), array( 'replace_foreign' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'customField', $result['replaced']['conditions'][0]['type'] );
		$this->assertFalse( $result['replaced']['ours'] );
		$this->assertSame( 'loggedInStatus', $this->attrs( $this->content( (int) $result['id'] ), self::HEADING )['module']['decoration']['conditions']['desktop']['value'][0]['conditionName'] );
	}

	public function test_replace_foreign_can_also_remove_a_foreign_list(): void {
		$this->put_page( self::page( self::stored_attrs( self::foreign_list() ) ) );

		$result = $this->set( self::HEADING, array( 'match' => 'all', 'conditions' => array() ), array( 'replace_foreign' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertSame( 'customField', $result['replaced']['conditions'][0]['type'] );
		$this->assertArrayNotHasKey( 'module', $this->attrs( $this->content( (int) $result['id'] ), self::HEADING ) );
	}

	public function test_our_own_list_is_replaced_without_replace_foreign_and_without_replaced(): void {
		$first = $this->set( self::HEADING, self::logged_in() );
		$this->assertIsArray( $first );

		$result = $this->set( self::HEADING, array( 'match' => 'any', 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_out' ) ) ), array(), (int) $first['id'] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'replaced', $result );
	}

	public function test_a_stale_fingerprint_or_a_missing_path_writes_nothing(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->editor()->edit( get_post( 100 ), array( 'path' => self::HEADING, 'fingerprint' => 'viejo', 'conditions' => self::logged_in() ) ), $before ); // @phpstan-ignore-line
		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->editor()->edit( get_post( 100 ), array( 'path' => '0.9', 'fingerprint' => 'x', 'conditions' => self::logged_in() ) ), $before ); // @phpstan-ignore-line
	}

	public function test_tokens_already_in_the_block_are_kept_and_the_final_rescan_stays(): void {
		$this->put_page( self::page( array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => self::TOKEN ) ) ) ) ) );

		$result = $this->set( self::HEADING, self::logged_in() );

		$this->assertIsArray( $result );
		$this->assertSame( self::TOKEN, $this->attrs( $this->content( (int) $result['id'] ), self::HEADING )['title']['innerContent']['desktop']['value'] );
	}

	public function test_a_token_smuggled_through_a_condition_value_never_reaches_the_block(): void {
		$before = $this->snapshot();

		$this->assertRejected(
			DiviConditionsSchema::CODE,
			$this->set( self::HEADING, array( 'match' => 'all', 'conditions' => array( array( 'type' => 'url_parameter', 'rule' => 'equals', 'name' => 'a', 'value' => self::TOKEN ) ) ) ),
			$before
		);
	}

	public function test_without_conditions_the_editor_does_not_touch_them(): void {
		$list = $this->schema()->stored( (array) $this->schema()->validate( self::logged_in() ) );
		$this->put_page( self::page( self::stored_attrs( $list ) ) );
		$post = get_post( 100 );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), self::HEADING );

		$result = $this->editor()->edit(
			$post,
			array(
				'path'        => self::HEADING,
				'fingerprint' => (string) $node['fingerprint'],
				'content'     => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Adiós' ) ) ) ),
			)
		);

		$this->assertIsArray( $result );
		$attrs = $this->attrs( $this->content( (int) $result['id'] ), self::HEADING );
		$this->assertSame( $list, $attrs['module']['decoration']['conditions']['desktop']['value'] );
		$this->assertSame( 'Adiós', $attrs['title']['innerContent']['desktop']['value'] );
	}
}
