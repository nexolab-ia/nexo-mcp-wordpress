<?php
/**
 * Herramienta migrate-elementor-page: registro, permisos, auditoría y cableado con Settings.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ElementorMigrateToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/migrate-elementor-page';

	/**
	 * @return array<string, mixed>
	 */
	private function ability( array $settings = array() ): array {
		$settings = $this->settings( array_merge( array( 'read_only' => false ), $settings ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		) )->register_abilities();

		return \WP_Test_State::$abilities[ self::TOOL ];
	}

	private function put_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		update_post_meta( 100, '_elementor_edit_mode', 'builder' );
		update_post_meta( 100, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3() ) );
	}

	private function put_copy_of_page(): void {
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Inicio' ) );
		update_post_meta( 101, WorkingCopy::META_DRAFT_OF, 100 );
		update_post_meta( 101, '_elementor_edit_mode', 'builder' );
		update_post_meta( 101, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3() ) );
	}

	public function test_es_una_herramienta_de_escritura_en_la_allowlist_con_su_esquema(): void {
		$ability = $this->ability();
		$schema  = $ability['input_schema'];

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $ability['meta']['annotations']['readonly'] );
		$this->assertFalse( $ability['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'id' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'id', 'draft' ), array_keys( $schema['properties'] ) );
		$this->assertSame( 'integer', $schema['properties']['id']['type'] );
	}

	public function test_la_descripcion_dice_que_se_migra_que_se_conserva_y_que_escribe_en_la_copia(): void {
		$text = $this->ability()['description'];

		foreach ( array( 'heading', 'e-heading', 'text-editor', 'e-paragraph', 'button', 'image', 'divider', 'section', 'column', 'container', 'spacer', 'v3', 'kept_v3', 'unmapped', 'copia de trabajo', 'Nunca publica', 'nexo_mcp_nothing_to_migrate', 'get-working-copy-diff' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
	}

	public function test_el_permiso_es_el_de_edit_elementor_section(): void {
		$input = array( 'id' => 100 );

		$this->assertTrue( $this->ability()['permission_callback']( $input ) );

		\WP_Test_State::$abilities = array();
		$this->assertNotTrue( $this->ability( array( 'read_only' => true ) )['permission_callback']( $input ) );

		\WP_Test_State::$abilities = array();
		$this->assertNotTrue( $this->ability( array( 'builders_tools_enabled' => false ) )['permission_callback']( $input ) );

		\WP_Test_State::$abilities                      = array();
		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( $input ) );

		\WP_Test_State::$abilities                      = array();
		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = true;
		\WP_Test_State::$caps['edit_post']              = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( $input ) );
	}

	public function test_sin_elementor_pide_el_editor_v4_pasa_por_la_auditoria_y_no_escribe(): void {
		$this->put_page();
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		$result = $this->ability()['execute_callback']( array( 'id' => 100 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'migrate-elementor-page', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}

	public function test_un_id_inexistente_responde_error(): void {
		$result = $this->ability()['execute_callback']( array( 'id' => 999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_not_found', $result->get_error_code() );
	}

	public function test_sin_permiso_sobre_el_original_no_se_migra(): void {
		$this->put_page();
		\WP_Test_State::$caps['edit_post'] = false;

		$result = $this->ability()['execute_callback']( array( 'id' => 100 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
	}

	public function test_con_el_id_de_la_copia_el_permiso_se_pide_sobre_el_original(): void {
		$this->put_page();
		$this->put_copy_of_page();
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$input = array( 'id' => 101 );

		$this->assertNotTrue( $this->ability()['permission_callback']( $input ) );

		$result = $this->ability()['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
	}

	public function test_con_el_id_de_la_copia_no_importa_el_permiso_sobre_la_copia(): void {
		$this->put_page();
		$this->put_copy_of_page();
		\WP_Test_State::$denied_edit_posts = array( 101 );
		$input = array( 'id' => 101 );

		$this->assertTrue( $this->ability()['permission_callback']( $input ) );

		$result = $this->ability()['execute_callback']( $input );

		$this->assertFalse( $result instanceof \WP_Error && 'nexo_mcp_builder_forbidden' === $result->get_error_code(), 'migra' );
	}
}
