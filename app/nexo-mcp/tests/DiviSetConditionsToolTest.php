<?php
/**
 * set-divi-conditions y list-divi-conditions de punta a punta (con Divi 5 activo): registro, esquema, permiso del scope
 * `mcp:divi_conditions` sobre el original, orden de las comprobaciones, el catálogo y la regla S9 (create-divi-page,
 * edit-divi-section y edit-divi-module siguen rechazando `module.decoration.conditions`).
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviConditionsCatalog;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviSetConditionsToolTest extends NexoTestCase {

	private const SET  = 'nexo-mcp/set-divi-conditions';
	private const LIST = 'nexo-mcp/list-divi-conditions';

	/**
	 * Veces que se consultó el registro de roles (el esquema sólo se usa tras el permiso).
	 *
	 * @var int
	 */
	private int $role_calls = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5DynamicEditorTest::page() ) );
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_conditions_tools_enabled' => true ), $settings ) );
		$schema                    = new DiviConditionsSchema(
			static fn( string $type ): ?array => array( 'post' => array( 'public' => true, 'label' => 'Entradas' ) )[ $type ] ?? null,
			function ( string $role ): ?string {
				++$this->role_calls;

				return array( 'subscriber' => 'Suscriptor' )[ $role ] ?? null;
			},
			static fn(): ?string => null,
			static fn(): ?array => null,
			static fn(): ?string => null,
			static fn(): bool => false,
			static fn(): string => '44444444-4444-4444-8444-444444444444'
		);
		$catalog                   = new DiviConditionsCatalog(
			static fn(): array => array( 'subscriber' => 'Suscriptor', 'editor' => 'Editor' ),
			static fn(): array => array( 'post' => 'Entradas', 'page' => 'Páginas' ),
			static fn(): bool => false
		);

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, null, null, null, null, null, null, $schema, $catalog )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function tool( string $name = self::SET, array $settings = array() ): array {
		return $this->tools( $settings )[ $name ];
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	private function fingerprint( string $content, string $path ): string {
		return (string) ( Divi5Tree::locate( Divi5Tree::build( $content ), $path )['fingerprint'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function input( array $extra = array(), string $path = '0.0.0.0.0' ): array {
		return array_merge(
			array(
				'id'          => 100,
				'path'        => $path,
				'fingerprint' => $this->fingerprint( Divi5DynamicEditorTest::page(), $path ),
				'match'       => 'all',
				'conditions'  => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ),
			),
			$extra
		);
	}

	public function test_set_is_registered_as_a_closed_write_tool_and_documents_the_contract(): void {
		$tool = $this->tool();

		$this->assertContains( self::SET, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'match', 'conditions' ), $tool['input_schema']['required'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'path', 'fingerprint', 'match', 'conditions', 'replace_foreign', 'draft' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'all', 'any' ), $tool['input_schema']['properties']['match']['enum'] );

		$conditions = $tool['input_schema']['properties']['conditions'];
		$this->assertSame( 'array', $conditions['type'] );
		$this->assertSame( 0, $conditions['minItems'] ?? 0 );
		$this->assertSame( DiviConditionsSchema::MAX_CONDITIONS, $conditions['maxItems'] );
		$this->assertFalse( $conditions['items']['additionalProperties'] );
		$this->assertSame( array( 'type' ), $conditions['items']['required'] );
		$this->assertSame( array_keys( DiviConditionsSchema::TYPES ), $conditions['items']['properties']['type']['enum'] );
		$this->assertEqualsCanonicalizing( array_unique( array_merge( array( 'type' ), ...array_values( DiviConditionsSchema::definition()['keys'] ) ) ), array_keys( $conditions['items']['properties'] ) );
		$this->assertSame( 'boolean', $tool['input_schema']['properties']['replace_foreign']['type'] );

		foreach ( array( 'nexo_mcp_divi_conditions_invalid', 'nexo_mcp_divi_conditions_foreign', 'nexo_mcp_divi_module_denied', 'nexo_mcp_divi_node_changed', 'nunca publica', 'mcp:divi_conditions', 'replace_foreign', 'no es control de acceso', 'buscable', 'list-divi-conditions', 'copia de trabajo', 'divi/placeholder', 'match', 'caché', 'Visual Builder' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'], $needle );
		}
	}

	public function test_list_is_registered_as_a_read_tool_with_an_optional_post_id(): void {
		$tool = $this->tool( self::LIST );

		$this->assertContains( self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertTrue( $tool['meta']['annotations']['readonly'] );
		$this->assertSame( array( 'post_id' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertArrayNotHasKey( 'required', $tool['input_schema'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );

		foreach ( array( 'set-divi-conditions', 'mcp:divi_conditions', 'no es control de acceso', 'woocommerce', 'roles', 'post_types' ) as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_other_tools_point_at_it(): void {
		$tools = $this->tools();

		$this->assertStringContainsString( 'set-divi-conditions', $tools['nexo-mcp/edit-divi-module']['description'] );
		$this->assertStringContainsString( 'set-divi-conditions', $tools['nexo-mcp/create-divi-page']['description'] );
		$this->assertStringContainsString( 'set-divi-conditions', $tools['nexo-mcp/edit-divi-section']['description'] );
	}

	public function test_set_permission_is_the_conditions_scope_on_the_original(): void {
		$this->assertTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( 'divi_conditions_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( 'read_only' => true ) )['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( 'divi_dynamic_tools_enabled' => true, 'divi_conditions_tools_enabled' => false ) )['permission_callback']( $this->input() ), 'el scope dinámico no habilita las condiciones' );

		\WP_Test_State::$caps[ Capabilities::DIVI_CONDITIONS ] = false;
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
		\WP_Test_State::$caps[ Capabilities::DIVI_CONDITIONS ] = true;

		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\WP_Test_State::$meta[101]  = array( WorkingCopy::META_DRAFT_OF => 100 );
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->tool()['permission_callback']( $this->input( array( 'id' => 101 ) ) ), 'la copia se comprueba contra su original' );
	}

	public function test_execute_rechecks_the_permission_before_the_schema_is_ever_consulted(): void {
		$before = $this->snapshot();
		$input  = $this->input( array( 'conditions' => array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber' ) ) ) ) );

		$result = $this->tool( self::SET, array( 'divi_conditions_tools_enabled' => false ) )['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->role_calls );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertIsArray( $this->tool()['execute_callback']( $input ) );
		$this->assertGreaterThan( 0, $this->role_calls );
	}

	public function test_a_missing_conditions_key_is_invalid_and_an_unknown_id_is_not_found(): void {
		$input = $this->input();
		unset( $input['conditions'] );
		$before = $this->snapshot();

		$result = $this->tool()['execute_callback']( $input );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'conditions' ), $result->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertSame( 'nexo_mcp_builder_not_found', $this->tool()['execute_callback']( $this->input( array( 'id' => 999 ) ) )->get_error_code() );
	}

	public function test_it_sets_the_conditions_in_the_working_copy_and_the_original_stays_untouched(): void {
		$result = $this->tool()['execute_callback']( $this->input() );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$this->assertArrayHasKey( 'preview_url', $result );
		$this->assertSame( '0.0.0.0.0', $result['path'] );
		$this->assertSame( 'all', $result['match'] );
		$this->assertSame( '44444444-4444-4444-8444-444444444444', $result['conditions'][0]['id'] );
		$this->assertSame( Divi5DynamicEditorTest::page(), $this->content( 100 ) );

		$block = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), '0.0.0.0.0' );
		$this->assertSame( $result['conditions'], $block['attrs']['module']['decoration']['conditions']['desktop']['value'] ); // @phpstan-ignore-line
		$this->assertSame( $this->fingerprint( $this->content( (int) $result['id'] ), '0.0.0.0.0' ), $result['fingerprint'] );

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'set-divi-conditions', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}

	public function test_rejections_come_back_as_tool_errors_and_write_nothing(): void {
		$before = $this->snapshot();
		$tool   = $this->tool();

		$bad = array(
			'customField' => array( 'type' => 'customField', 'rule' => 'is' ),
			'role'        => array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'ghost' ) ),
			'woo off'     => array( 'type' => 'cart_contents', 'rule' => 'is_empty' ),
		);

		foreach ( $bad as $label => $condition ) {
			$result = $tool['execute_callback']( $this->input( array( 'conditions' => array( $condition ) ) ) );
			$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $result->get_error_code(), $label );
		}

		$this->assertSame( 'nexo_mcp_divi_module_denied', $tool['execute_callback']( $this->input( array(), '0' ) )->get_error_code() );
		$this->assertSame( 'nexo_mcp_divi_node_changed', $tool['execute_callback']( $this->input( array( 'fingerprint' => 'viejo' ) ) )->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_a_foreign_list_needs_replace_foreign_through_the_registered_tool(): void {
		$tools = $this->tools();
		$set   = $tools[ self::SET ]['execute_callback'];
		$page  = Divi5DynamicEditorTest::page();
		$raw   = parse_blocks( $page );
		$block = Divi5Tree::locate_block( $raw, '0.0.0.0.0' );
		$this->assertIsArray( $block );
		$block['attrs']['module']['decoration']['conditions']['desktop']['value'] = array( array( 'id' => 'a', 'conditionName' => 'customField', 'conditionSettings' => array( 'enableCondition' => 'on' ), 'operator' => 'OR' ) );
		$foreign = serialize_blocks( (array) Divi5Tree::replace_block( $raw, '0.0.0.0.0', $block ) );
		\WP_Test_State::$posts[100]->post_content = $foreign;
		$before = $this->snapshot();

		$input  = $this->input( array( 'fingerprint' => $this->fingerprint( $foreign, '0.0.0.0.0' ) ) );
		$result = $set( $input );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_conditions_foreign', $result->get_error_code() );
		$this->assertSame( 'customField', $result->get_error_data()['summary']['conditions'][0]['type'] ); // @phpstan-ignore-line
		$this->assertSame( $before, $this->snapshot() );

		$done = $set( array_merge( $input, array( 'replace_foreign' => true ) ) );
		$this->assertIsArray( $done );
		$this->assertSame( 'customField', $done['replaced']['conditions'][0]['type'] );
	}

	public function test_an_empty_list_removes_through_the_tool(): void {
		$tools = $this->tools();
		$first = $tools[ self::SET ]['execute_callback']( $this->input() );
		$this->assertIsArray( $first );
		$copy = (int) $first['id'];

		$result = $tools[ self::SET ]['execute_callback']( array( 'id' => $copy, 'path' => '0.0.0.0.0', 'fingerprint' => $first['fingerprint'], 'match' => 'all', 'conditions' => array() ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertSame( array(), $result['conditions'] );
		$block = Divi5Tree::locate_block( parse_blocks( $this->content( $copy ) ), '0.0.0.0.0' );
		$this->assertArrayNotHasKey( 'module', (array) ( $block['attrs'] ?? array() ) );
	}

	public function test_list_returns_the_catalog_with_the_live_lists_and_reads_with_the_builders_permission_only(): void {
		$tool = $this->tool( self::LIST, array( 'divi_conditions_tools_enabled' => false ) );

		$this->assertTrue( $tool['permission_callback']( array() ) );
		$this->assertTrue( $tool['permission_callback']( array( 'post_id' => 100 ) ) );
		$this->assertNotTrue( $this->tool( self::LIST, array( 'builders_tools_enabled' => false ) )['permission_callback']( array() ) );
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$this->assertNotTrue( $tool['permission_callback']( array( 'post_id' => 100 ) ) );
		\WP_Test_State::$denied_edit_posts = array();

		$list = $tool['execute_callback']( array() );
		$this->assertIsArray( $list );
		$this->assertSame( array( 'all', 'any' ), $list['match'] );
		$this->assertSame( 5, $list['max_conditions'] );
		$this->assertFalse( $list['woocommerce'] );
		$this->assertSame( array( array( 'slug' => 'subscriber', 'label' => 'Suscriptor' ), array( 'slug' => 'editor', 'label' => 'Editor' ) ), $list['roles'] );
		$this->assertSame( array( array( 'name' => 'post', 'label' => 'Entradas' ), array( 'name' => 'page', 'label' => 'Páginas' ) ), $list['post_types'] );
		$this->assertSame( DiviConditionsSchema::MODULES, $list['modules'] );
		$this->assertArrayNotHasKey( 'post_id', $list );

		$types = array_column( $list['types'], null, 'type' );
		$this->assertSame( array_keys( DiviConditionsSchema::TYPES ), array_keys( $types ) );
		$this->assertSame( array( 'logged_in', 'logged_out' ), $types['logged_in']['rules'] );
		$this->assertSame( array( 'before', 'after', 'on_date', 'on_days' ), $types['date_time']['rules'] );
		$this->assertSame( array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ), $types['date_time']['settings']['weekdays']['values'] );
		$this->assertContains( 'safari', $types['browser']['settings']['browsers']['values'] );
		$this->assertContains( 'macos', $types['operating_system']['settings']['systems']['values'] );
		$this->assertSame( array( 'name', 'value' ), array_keys( $types['url_parameter']['settings'] ) );
		$this->assertSame( 'woocommerce', $types['cart_contents']['requires'] );
		$this->assertFalse( $types['cart_contents']['available'] );
		$this->assertTrue( $types['logged_in']['available'] );
		$this->assertSame( '', $types['logged_in']['requires'] );
		$this->assertArrayNotHasKey( 'customField', $types );
		$this->assertArrayNotHasKey( 'numberOfViews', $types );

		$with = $tool['execute_callback']( array( 'post_id' => 101 ) );
		$this->assertInstanceOf( \WP_Error::class, $with );
		$this->assertSame( 'nexo_mcp_builder_not_found', $with->get_error_code() );

		$with = $tool['execute_callback']( array( 'post_id' => 100 ) );
		$this->assertSame( 100, $with['post_id'] );
		$this->assertSame( 'page', $with['post_type'] );
	}

	public function test_every_catalog_rule_and_key_is_accepted_by_the_schema(): void {
		$list  = $this->tool( self::LIST )['execute_callback']( array() );
		$keys  = DiviConditionsSchema::definition()['keys'];
		$rules = DiviConditionsSchema::definition()['rules'];

		foreach ( $list['types'] as $type ) {
			$this->assertSame( array_keys( $rules[ $type['type'] ] ), $type['rules'], $type['type'] );
			$this->assertSame( array_values( array_diff( $keys[ $type['type'] ], array( 'rule' ) ) ), array_keys( $type['settings'] ), $type['type'] );
		}
	}

	// T5 (S7): lectura resumida, dropped_conditions y avisos de la revisión de T4.

	public function test_get_page_structure_summarizes_the_conditions_and_edit_divi_section_reports_dropped_ones(): void {
		$page = Divi5ConditionsReadTest::with_conditions( Divi5DynamicEditorTest::page(), '0.0.0.0.0', Divi5ConditionsReadTest::foreign() );
		\WP_Test_State::$posts[100]->post_content = $page;
		$tools = $this->tools();

		$read = $tools['nexo-mcp/get-page-structure']['execute_callback']( array( 'id' => 100 ) );
		$this->assertIsArray( $read );
		$by = array_column( $read['nodes'], null, 'id' );
		$this->assertFalse( $by['0.0.0.0.0']['attributes']['conditions']['ours'] );
		$this->assertSame( 'customField', $by['0.0.0.0.0']['attributes']['conditions']['conditions'][0]['type'] );
		$this->assertArrayNotHasKey( 'conditions', $by['0.0.0.0.1']['attributes'] );
		$this->assertStringNotContainsString( Divi5ConditionsReadTest::SECRET, (string) wp_json_encode( $read ) );

		$tree   = array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>X</p>' ) ) ) ) );
		$result = $tools['nexo-mcp/edit-divi-section']['execute_callback']( array( 'id' => 100, 'operation' => 'replace', 'path' => '0.0.0.0.0', 'fingerprint' => $this->fingerprint( $page, '0.0.0.0.0' ), 'sections' => array( $tree ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( '0.0.0.0.0' ), array_column( $result['dropped_conditions'], 'path' ) );
		$this->assertStringNotContainsString( Divi5ConditionsReadTest::SECRET, (string) wp_json_encode( $result ) );
	}

	public function test_descriptions_document_the_summary_and_dropped_conditions(): void {
		$tools = $this->tools();

		$this->assertStringContainsString( 'attributes.conditions', $tools['nexo-mcp/get-page-structure']['description'] );
		$this->assertStringContainsString( 'dropped_conditions', $tools['nexo-mcp/edit-divi-section']['description'] );
		$this->assertStringContainsString( 'dropped_conditions', $tools[ self::SET ]['description'] );
		$this->assertStringContainsString( 'match es obligatorio', $tools[ self::SET ]['description'] );
	}

	public function test_several_conditions_with_match_any_are_stored_with_one_or_operator(): void {
		$result = $this->tool()['execute_callback'](
			$this->input(
				array(
					'match'      => 'any',
					'conditions' => array(
						array( 'type' => 'logged_in', 'rule' => 'logged_out' ),
						array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber' ) ),
						array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'post' ) ),
					),
				)
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'any', $result['match'] );
		$this->assertCount( 3, $result['conditions'] );
		$this->assertSame( array( 'OR', 'OR', 'OR' ), array_column( $result['conditions'], 'operator' ) );
		$this->assertCount( 3, array_unique( array_column( $result['conditions'], 'id' ) ), 'ids únicos aunque el generador repita' );
	}

	public function test_two_conditions_of_the_same_type_with_any_are_rejected_and_write_nothing(): void {
		$before = $this->snapshot();
		$result = $this->tool()['execute_callback'](
			$this->input( array( 'match' => 'any', 'conditions' => array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber' ) ), array( 'type' => 'user_role', 'rule' => 'is_not', 'roles' => array( 'subscriber' ) ) ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'conditions.1.type' ), $result->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_match_omitted_is_rejected_like_the_json_schema_requires_even_with_an_empty_list(): void {
		foreach ( array( array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ), array() ) as $conditions ) {
			$input = $this->input( array( 'conditions' => $conditions ) );
			unset( $input['match'] );
			$before = $this->snapshot();

			$result = $this->tool()['execute_callback']( $input );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $result->get_error_code() );
			$this->assertSame( array( 'path' => 'match' ), $result->get_error_data() );
			$this->assertSame( $before, $this->snapshot() );
		}
	}

	public function test_the_json_schema_limits_come_from_the_schema_definitions(): void {
		$props = $this->tool()['input_schema']['properties']['conditions']['items']['properties'];
		$def   = DiviConditionsSchema::definition();

		$this->assertSame( DiviConditionsSchema::MAX_POST_TYPES, $props['post_types']['maxItems'] );
		$this->assertSame( DiviConditionsSchema::MAX_ROLES, $props['roles']['maxItems'] );
		$this->assertSame( count( $def['browsers'] ), $props['browsers']['maxItems'] );
		$this->assertSame( count( $def['systems'] ), $props['systems']['maxItems'] );
		$this->assertSame( count( $def['weekdays'] ), $props['weekdays']['maxItems'] );

		foreach ( array( 'browsers' => 'browsers', 'systems' => 'systems', 'weekdays' => 'weekdays' ) as $key => $list ) {
			foreach ( $def[ $list ] as $value ) {
				$this->assertStringContainsString( $value, $props[ $key ]['description'], $key );
			}
		}
	}

	// S9: sólo set-divi-conditions escribe condiciones (con el scope ENCENDIDO los demás siguen rechazándolas).

	public function test_s9_edit_divi_module_rejects_the_conditions_attribute_with_the_scope_on(): void {
		$tool   = $this->tool( 'nexo-mcp/edit-divi-module' );
		$before = $this->snapshot();
		$value  = array( array( 'id' => '44444444-4444-4444-8444-444444444444', 'conditionName' => 'loggedInStatus', 'conditionSettings' => array( 'enableCondition' => 'on', 'displayRule' => 'loggedIn' ), 'operator' => 'AND' ) );

		foreach ( array( 'design', 'content' ) as $slot ) {
			$result = $tool['execute_callback']( array_merge( $this->input(), array( $slot => array( 'module' => array( 'decoration' => array( 'conditions' => array( 'desktop' => array( 'value' => $value ) ) ) ) ) ) ) );
			$this->assertInstanceOf( \WP_Error::class, $result, $slot );
			$this->assertSame( 'nexo_mcp_divi_attr_denied', $result->get_error_code(), $slot );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_s9_edit_divi_module_never_passes_a_conditions_input_to_the_editor(): void {
		$tool   = $this->tool( 'nexo-mcp/edit-divi-module' );
		$result = $tool['execute_callback'](
			array_merge(
				$this->input(),
				array( 'content' => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Nuevo' ) ) ) ) )
			)
		);
		$this->assertIsArray( $result );

		$smuggled = $tool['execute_callback'](
			array_merge(
				$this->input( array( 'id' => (int) $result['id'], 'fingerprint' => (string) $result['fingerprint'] ) ),
				array( 'content' => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Otro' ) ) ) ) ),
				array( 'conditions' => array( array( 'type' => 'logged_in', 'rule' => 'logged_in' ) ), 'replace_foreign' => true )
			)
		);

		$this->assertIsArray( $smuggled );
		$block = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $smuggled['id'] ) ), '0.0.0.0.0' );
		$this->assertSame( 'Otro', $block['attrs']['title']['innerContent']['desktop']['value'] ); // @phpstan-ignore-line
		$this->assertArrayNotHasKey( 'module', $block['attrs'] ); // @phpstan-ignore-line
		$this->assertArrayNotHasKey( 'conditions', $smuggled );
	}

	public function test_s9_create_divi_page_and_edit_divi_section_reject_the_conditions_attribute_with_the_scope_on(): void {
		$tools  = $this->tools();
		$attrs  = array( 'module' => array( 'decoration' => array( 'conditions' => array( 'desktop' => array( 'value' => array( array( 'conditionName' => 'loggedInStatus' ) ) ) ) ) ) );
		$text   = array( 'type' => 'divi/text', 'attrs' => $attrs );
		$tree   = array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $text ) ) ) ) ) ) );
		$before = $this->snapshot();

		$created = $tools['nexo-mcp/create-divi-page']['execute_callback']( array( 'title' => 'Nueva', 'sections' => $tree ) );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertSame( 'nexo_mcp_divi_attr_denied', $created->get_error_code() );

		$edited = $tools['nexo-mcp/edit-divi-section']['execute_callback'](
			array(
				'id'          => 100,
				'operation'   => 'replace',
				'path'        => '0.0.0.0.0',
				'fingerprint' => $this->fingerprint( Divi5DynamicEditorTest::page(), '0.0.0.0.0' ),
				'sections'    => array( $text ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $edited );
		$this->assertSame( 'nexo_mcp_divi_attr_denied', $edited->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}
}
