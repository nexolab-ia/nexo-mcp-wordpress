<?php
/**
 * Acciones irreversibles: sólo con confirmación explícita.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\IrreversibleActions;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class IrreversibleActionsTest extends NexoTestCase {

	public function test_borrar_producto_sin_confirmacion_se_rechaza(): void {
		$result = IrreversibleActions::check( 'delete-product', array( 'id' => 5 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( IrreversibleActions::CODE, $result->get_error_code() );
	}

	public function test_la_confirmacion_debe_ser_exacta(): void {
		$this->assertInstanceOf( \WP_Error::class, IrreversibleActions::check( 'delete-product', array( 'confirmation' => 'delete' ) ) );
		$this->assertInstanceOf( \WP_Error::class, IrreversibleActions::check( 'delete-product', array( 'confirmation' => 'RESTORE' ) ) );
		$this->assertTrue( IrreversibleActions::check( 'delete-product', array( 'confirmation' => 'DELETE' ) ) );
	}

	public function test_restaurar_revision_exige_restore(): void {
		$this->assertInstanceOf( \WP_Error::class, IrreversibleActions::check( 'restore-revision', array( 'id' => 9 ) ) );
		$this->assertTrue( IrreversibleActions::check( 'restore-revision', array( 'id' => 9, 'confirmation' => 'RESTORE' ) ) );
	}

	public function test_herramientas_reversibles_no_piden_confirmacion(): void {
		$this->assertTrue( IrreversibleActions::check( 'update-post-seo', array() ) );
		$this->assertNull( IrreversibleActions::word_for( 'list-media' ) );
	}

	/**
	 * La regla se aplica en el servidor, en la ejecución de la herramienta,
	 * aunque el cliente se salte el esquema JSON.
	 */
	public function test_el_servidor_rechaza_y_audita_sin_ejecutar(): void {
		$registry = $this->registered_abilities();

		$execute = $registry['nexo-mcp/delete-product']['execute_callback'];
		$result  = $execute( array( 'id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( IrreversibleActions::CODE, $result->get_error_code() );

		$last = end( \WP_Test_State::$audit );
		$this->assertSame( 'delete-product', $last['tool'] );
		$this->assertSame( 'denied', $last['status'] );
	}

	public function test_con_confirmacion_la_herramienta_llega_a_ejecutarse(): void {
		$registry = $this->registered_abilities();

		$execute = $registry['nexo-mcp/delete-product']['execute_callback'];
		$result  = $execute( array( 'id' => 7, 'confirmation' => 'DELETE' ) );

		// Sin WooCommerce el gateway responde su propio error: lo importante es
		// que ya no es el de confirmación.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( IrreversibleActions::CODE, $result->get_error_code() );
	}

	public function test_los_esquemas_declaran_la_confirmacion_obligatoria(): void {
		$registry = $this->registered_abilities();

		foreach ( array( 'nexo-mcp/delete-product' => 'DELETE', 'nexo-mcp/restore-revision' => 'RESTORE' ) as $tool => $word ) {
			$schema = $registry[ $tool ]['input_schema'];

			$this->assertContains( 'confirmation', $schema['required'], $tool );
			$this->assertSame( array( $word ), $schema['properties']['confirmation']['enum'], $tool );
			$this->assertTrue( $registry[ $tool ]['meta']['annotations']['destructive'], $tool );
		}
	}

	/**
	 * Registra las herramientas extendidas y devuelve lo que se registró.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_abilities(): array {
		$settings = $this->settings( array( 'read_only' => false, 'destructive_enabled' => true ) );

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

		return \WP_Test_State::$abilities;
	}
}
