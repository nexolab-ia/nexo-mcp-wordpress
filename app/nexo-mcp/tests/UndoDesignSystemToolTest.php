<?php
/**
 * Herramienta undo-design-system-change: registro, permiso y respuesta sin respaldo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class UndoDesignSystemToolTest extends NexoTestCase {

	private const TOOL = 'nexo-mcp/undo-design-system-change';

	/**
	 * @param array<string, mixed> $settings Ajustes de la prueba.
	 *
	 * @return array<string, mixed>
	 */
	private function ability( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'design_system_tools_enabled' => true ), $settings ) );

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

	public function test_es_una_herramienta_de_escritura_no_destructiva_sin_entrada(): void {
		$ability = $this->ability();

		$this->assertContains( self::TOOL, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $ability['meta']['annotations']['readonly'] );
		$this->assertFalse( $ability['meta']['annotations']['destructive'] );
		$this->assertInstanceOf( \stdClass::class, $ability['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'required', $ability['input_schema'] );

		foreach ( array( 'nexo_mcp_design_no_backup', 'un nivel', 'mismos ids', 'colección completa', 'desde el editor de Elementor también se pierde' ) as $needle ) {
			$this->assertStringContainsString( $needle, $ability['description'], $needle );
		}
	}

	public function test_el_interruptor_nuevo_arranca_apagado(): void {
		$this->assertFalse( \NexoDev\NexoMCP\Support\Settings::defaults()['design_system_tools_enabled'] );
	}

	public function test_el_permiso_pide_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		$this->assertTrue( $this->ability()['permission_callback']( array() ) );

		$this->assertNotTrue( $this->ability( array( 'design_system_tools_enabled' => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->ability( array( 'read_only' => true ) )['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::DESIGN_SYSTEM ] = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::DESIGN_SYSTEM ] = true;
		\WP_Test_State::$caps['manage_options']              = false;
		$this->assertNotTrue( $this->ability()['permission_callback']( array() ) );
	}

	public function test_sin_respaldo_responde_el_error_y_no_cambia_nada(): void {
		$options = \WP_Test_State::$options;

		$result = $this->ability()['execute_callback']( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_no_backup', $result->get_error_code() );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
		$this->assertSame( $options, array_intersect_key( \WP_Test_State::$options, $options ) );
	}

	public function test_un_respaldo_malformado_tambien_responde_sin_respaldo(): void {
		update_option( DesignSystemWriter::BACKUP_OPTION, array( 'kind' => 'pages' ), false );

		$result = $this->ability()['execute_callback']( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_no_backup', $result->get_error_code() );
		$this->assertSame( array( 'kind' => 'pages' ), \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ] );
	}
}
