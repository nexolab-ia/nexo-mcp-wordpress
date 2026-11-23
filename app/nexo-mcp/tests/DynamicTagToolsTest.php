<?php
/**
 * Herramientas list-dynamic-tags y set-dynamic-tag: registro, scope, permisos, auditoría y cableado.
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
use NexoDev\NexoMCP\Builders\DynamicTagCatalog;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DynamicTagToolsTest extends NexoTestCase {

	private const SET   = 'nexo-mcp/set-dynamic-tag';
	private const LIST  = 'nexo-mcp/list-dynamic-tags';
	private const SCOPE = 'mcp:builders:dynamic';

	/**
	 * @param array<string, mixed>       $settings   Ajustes que cambian (el interruptor de datos dinámicos va encendido).
	 * @param array<int, string>|null    $registered Tags registrados en el sitio; null = el de producción (sin Elementor).
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array(), ?array $registered = null ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'builders_dynamic_enabled' => true ), $settings ) );
		$gateway                   = null === $registered
			? null
			: new BuilderGateway( null, null, $settings, null, null, null, null, new DynamicTagCatalog( static fn(): array => $registered, null, static fn( string $name ): string => 'post-title' === $name ? 'Título de la entrada' : '' ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			$gateway
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	private function set_tool( array $settings = array() ): array {
		return $this->abilities( $settings )[ self::SET ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input( int $id = 100 ): array {
		return array(
			'id'      => $id,
			'node_id' => 'eh00001',
			'prop'    => 'title',
			'tag'     => array( 'name' => 'post-title' ),
		);
	}

	private function put_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		update_post_meta( 100, '_elementor_edit_mode', 'builder' );
		update_post_meta( 100, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::atomic() ) );
	}

	private function put_copy_of_page(): void {
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Inicio' ) );
		update_post_meta( 101, WorkingCopy::META_DRAFT_OF, 100 );
		update_post_meta( 100, WorkingCopy::META_DRAFT, 101 );
		update_post_meta( 101, '_elementor_edit_mode', 'builder' );
		update_post_meta( 101, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::atomic() ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_builders_dynamic', Capabilities::BUILDERS_DYNAMIC );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::BUILDERS_DYNAMIC, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertNotSame( self::SCOPE, ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::BUILDERS_DYNAMIC, 'write' ) );
		$this->assertContains( self::SCOPE, ScopeMap::parse( 'mcp:connect ' . self::SCOPE ) );
	}

	public function test_el_interruptor_viene_apagado_y_el_perfil_anuncia_el_scope_sólo_con_el_encendido(): void {
		$this->assertFalse( Settings::defaults()['builders_dynamic_enabled'] );

		update_option( Settings::OPTION_NAME, array( 'read_only' => false, 'builders_tools_enabled' => true ) );
		$this->assertFalse( ( new Settings() )->get( 'builders_dynamic_enabled' ), 'ajustes guardados sin la clave' );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', new Settings() ) );

		$on = $this->settings( array( 'builders_dynamic_enabled' => true, 'read_only' => false ) );
		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $on ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'read', $on ) );

		$off = $this->settings( array( 'builders_dynamic_enabled' => false, 'read_only' => false ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $off ) );
	}

	public function test_son_herramientas_en_la_allowlist_con_sus_esquemas(): void {
		$all = $this->abilities();

		$this->assertContains( self::LIST, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertContains( self::SET, ExtendedAbilityRegistrar::ABILITIES );

		$set = $all[ self::SET ];
		$this->assertFalse( $set['meta']['annotations']['readonly'] );
		$this->assertFalse( $set['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id', 'node_id', 'prop', 'tag' ), $set['input_schema']['required'] );
		$this->assertFalse( $set['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'node_id', 'prop', 'tag', 'draft' ), array_keys( $set['input_schema']['properties'] ) );
		$this->assertSame( array( 'name' ), $set['input_schema']['properties']['tag']['required'] );
		$this->assertSame( array( 'name', 'settings' ), array_keys( $set['input_schema']['properties']['tag']['properties'] ) );

		$list = $all[ self::LIST ];
		$this->assertTrue( $list['meta']['annotations']['readonly'] );
		$this->assertFalse( $list['input_schema']['additionalProperties'] );
		$this->assertSame( 'array', $list['output_schema']['properties']['result']['type'] );
	}

	public function test_las_descripciones_enseñan_lo_que_hace_falta(): void {
		$all = $this->abilities();

		foreach ( array( 'copia de trabajo', 'Nunca publica', 'mcp:builders:dynamic', 'nexo_mcp_elementor_v4_required', 'e-heading.title', 'e-image.image', 'list-dynamic-tags', 'nexo_mcp_dynamic_tag_unavailable', 'draft_of', 'Elementor Pro' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::SET ]['description'], $needle );
		}

		foreach ( array( 'Elementor Pro', 'lista vacía', 'set-dynamic-tag', 'targets', 'shortcode' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::LIST ]['description'], $needle );
		}
	}

	public function test_asignar_sin_el_interruptor_de_datos_dinamicos_se_deniega(): void {
		$this->assertTrue( $this->set_tool()['permission_callback']( $this->input() ) );
		$this->assertNotTrue( $this->set_tool( array( 'builders_dynamic_enabled' => false ) )['permission_callback']( $this->input() ) );
	}

	public function test_asignar_sin_la_capacidad_de_datos_dinamicos_se_deniega(): void {
		\WP_Test_State::$caps[ Capabilities::BUILDERS_DYNAMIC ] = false;

		$this->assertNotTrue( $this->set_tool()['permission_callback']( $this->input() ) );
	}

	public function test_asignar_sin_el_modulo_ni_la_capacidad_de_constructores_se_deniega_aunque_haya_dinamicos(): void {
		$this->assertNotTrue( $this->set_tool( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->set_tool()['permission_callback']( $this->input() ) );
	}

	public function test_asignar_en_solo_revisar_o_sin_edit_post_se_deniega(): void {
		$this->assertNotTrue( $this->set_tool( array( 'read_only' => true ) )['permission_callback']( $this->input() ) );

		\WP_Test_State::$caps['edit_post'] = false;
		$this->assertNotTrue( $this->set_tool()['permission_callback']( $this->input() ) );
	}

	public function test_un_token_necesita_el_scope_dinamico_y_el_de_constructores(): void {
		$tool = $this->set_tool();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sin mcp:builders:dynamic' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertNotTrue( $tool['permission_callback']( $this->input() ), 'sin mcp:builders' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE ) );
		$this->assertTrue( $tool['permission_callback']( $this->input() ) );
	}

	public function test_el_scope_dinamico_no_salta_el_interruptor_apagado(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', self::SCOPE ) );

		$this->assertNotTrue( $this->set_tool( array( 'builders_dynamic_enabled' => false ) )['permission_callback']( $this->input() ) );
	}

	public function test_el_scope_dinamico_no_da_permiso_a_las_otras_herramientas_de_constructores(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$all = $this->abilities();

		$this->assertNotTrue( $all['nexo-mcp/edit-elementor-section']['permission_callback']( array( 'id' => 100, 'operation' => 'delete', 'node_id' => 'x' ) ) );
		$this->assertNotTrue( $all['nexo-mcp/migrate-elementor-page']['permission_callback']( array( 'id' => 100 ) ) );
	}

	public function test_con_el_id_de_la_copia_el_permiso_se_pide_sobre_el_original(): void {
		$this->put_page();
		$this->put_copy_of_page();
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$tool                              = $this->set_tool();

		$this->assertNotTrue( $tool['permission_callback']( $this->input( 101 ) ) );

		$result = $tool['execute_callback']( $this->input( 101 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
	}

	public function test_con_el_id_de_la_copia_no_importa_el_permiso_sobre_la_copia(): void {
		$this->put_page();
		$this->put_copy_of_page();
		\WP_Test_State::$denied_edit_posts = array( 101 );
		$tool                              = $this->set_tool();

		$this->assertTrue( $tool['permission_callback']( $this->input( 101 ) ) );

		$result = $tool['execute_callback']( $this->input( 101 ) );

		$this->assertFalse( $result instanceof \WP_Error && 'nexo_mcp_builder_forbidden' === $result->get_error_code(), 'edita' );
	}

	public function test_un_borrador_que_el_original_no_reconoce_como_copia_pide_permiso_sobre_si_mismo(): void {
		$this->put_page();
		$this->put_copy_of_page();
		delete_post_meta( 100, WorkingCopy::META_DRAFT );
		\WP_Test_State::$denied_edit_posts = array( 101 );
		$before                            = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->set_tool()['execute_callback']( $this->input( 101 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}

	public function test_sin_permiso_sobre_el_original_no_se_asigna(): void {
		$this->put_page();
		\WP_Test_State::$caps['edit_post'] = false;

		$result = $this->set_tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
	}

	public function test_un_id_inexistente_responde_error(): void {
		$result = $this->set_tool()['execute_callback']( $this->input( 999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_not_found', $result->get_error_code() );
	}

	public function test_sin_elementor_pide_el_editor_v4_pasa_por_la_auditoria_y_no_escribe(): void {
		$this->put_page();
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->set_tool()['execute_callback']( $this->input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'set-dynamic-tag', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}

	public function test_listar_exige_el_modulo_y_la_capacidad_de_constructores_y_no_el_interruptor_dinamico(): void {
		$this->assertTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'builders_dynamic_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'read_only' => true ) )[ self::LIST ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'builders_tools_enabled' => false ) )[ self::LIST ]['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
	}

	public function test_listar_con_un_token_de_solo_lectura_con_constructores_y_sin_ellos(): void {
		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read' ) );
		$this->assertTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect' ) );
		$this->assertNotTrue( $this->abilities()[ self::LIST ]['permission_callback']( array() ) );
	}

	public function test_sin_tags_registrados_listar_devuelve_una_lista_vacia_no_un_error(): void {
		$result = $this->abilities( array(), array() )[ self::LIST ]['execute_callback']( array() );

		$this->assertSame( array( 'result' => array() ), $result );

		$sin_elementor = $this->abilities()[ self::LIST ]['execute_callback']( array() );
		$this->assertSame( array( 'result' => array() ), $sin_elementor );
	}

	public function test_listar_devuelve_los_tags_del_catalogo_registrados_con_su_forma(): void {
		$result = $this->abilities( array(), array( 'post-title', 'site-url', 'shortcode', 'post-featured-image' ) )[ self::LIST ]['execute_callback']( array() );

		$this->assertIsArray( $result );
		$tags = array_column( $result['result'], null, 'name' );

		$this->assertSame( array( 'post-title', 'site-url', 'post-featured-image' ), array_keys( $tags ), 'sólo lo del catálogo, en su orden, y registrado' );
		$this->assertSame( array( 'name', 'group', 'label', 'categories', 'settings', 'targets' ), array_keys( $tags['post-title'] ) );
		$this->assertSame( 'post', $tags['post-title']['group'] );
		$this->assertSame( 'Título de la entrada', $tags['post-title']['label'], 'el título del tag registrado' );
		$this->assertSame( 'site-url', $tags['site-url']['label'], 'sin título registrado, el nombre' );
		$this->assertSame( array( 'text' ), $tags['post-title']['categories'] );
		$this->assertSame( array( 'before', 'after', 'fallback' ), array_keys( $tags['post-title']['settings'] ) );
		$this->assertSame( array( 'type' => 'text' ), $tags['post-title']['settings']['before'] );
		$this->assertSame( array( 'e-heading.title', 'e-paragraph.paragraph', 'e-button.text' ), $tags['post-title']['targets'] );
		$this->assertSame( array( 'image' ), $tags['post-featured-image']['categories'] );
		$this->assertSame( array( 'e-image.image' ), $tags['post-featured-image']['targets'] );
		$this->assertSame( array(), $tags['site-url']['settings'] );
		$this->assertSame( array(), $tags['site-url']['targets'], 'link (url) no es destino en F6' );

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'list-dynamic-tags', $last['tool'] ?? null );
		$this->assertSame( 'read', $last['operation'] ?? null );
	}
}
