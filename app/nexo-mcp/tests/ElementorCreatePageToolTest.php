<?php
/**
 * Herramienta create-elementor-page: registro, permisos y rechazos previos a Elementor.
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

final class ElementorCreatePageToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/create-elementor-page';

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

	/**
	 * @return array<string, mixed>
	 */
	private function valid_input(): array {
		return array(
			'title'    => 'Landing',
			'elements' => array(
				array(
					'type'     => 'e-heading',
					'settings' => array( 'title' => array( '$$type' => 'escaped-html', 'value' => 'Hola' ) ),
				),
			),
		);
	}

	public function test_es_una_herramienta_de_escritura_registrada_en_la_allowlist(): void {
		$ability = $this->ability();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $ability['meta']['annotations']['readonly'] );
		$this->assertFalse( $ability['meta']['annotations']['destructive'] );
		$this->assertSame( array( 'title', 'elements' ), $ability['input_schema']['required'] );
		$this->assertFalse( $ability['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'title', 'elements', 'slug' ), array_keys( $ability['input_schema']['properties'] ) );
	}

	public function test_la_descripcion_enseña_los_tipos_permitidos_y_que_nunca_publica(): void {
		$ability = $this->ability();
		$text    = $ability['description'] . json_encode( $ability['input_schema'], JSON_UNESCAPED_UNICODE );

		foreach ( array( 'e-flexbox', 'e-div-block', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-divider', 'borrador', '$$type', 'escaped-html', 'image-attachment-id' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
		$this->assertStringNotContainsString( '"e-svg"', $text );
	}

	public function test_el_permiso_sigue_al_modulo_de_constructores_y_al_modo_solo_revisar(): void {
		$this->assertTrue( $this->ability()['permission_callback']( $this->valid_input() ) );

		\WP_Test_State::$abilities = array();
		$this->assertNotTrue( $this->ability( array( 'read_only' => true ) )['permission_callback']( $this->valid_input() ) );

		\WP_Test_State::$abilities = array();
		$this->assertNotTrue( $this->ability( array( 'builders_tools_enabled' => false ) )['permission_callback']( $this->valid_input() ) );

		\WP_Test_State::$abilities                      = array();
		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( $this->valid_input() ) );

		\WP_Test_State::$abilities               = array();
		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = true;
		\WP_Test_State::$caps['edit_pages']      = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( $this->valid_input() ) );
	}

	public function test_sin_titulo_responde_error_y_no_crea_nada(): void {
		$input          = $this->valid_input();
		$input['title'] = '   ';

		$result = $this->ability()['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_title_required', $result->get_error_code() );
		$this->assertSame( 'Indica el título de la página.', $result->get_error_message() );
		$this->assertSame( array(), \WP_Test_State::$posts );
	}

	public function test_sin_elementor_pide_activar_el_editor_v4_y_no_crea_nada(): void {
		$result = $this->ability()['execute_callback']( $this->valid_input() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertStringContainsString( 'Elementor no está activo', $result->get_error_message() );
		$this->assertSame( array(), \WP_Test_State::$posts );
	}

	public function test_la_creacion_pasa_por_el_registro_de_auditoria_como_escritura(): void {
		$this->ability()['execute_callback']( $this->valid_input() );

		$this->assertNotEmpty( \WP_Test_State::$audit );
		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'create-elementor-page', $last['tool'] ?? null );
		$this->assertSame( 'write', $last['operation'] ?? null );
	}
}
