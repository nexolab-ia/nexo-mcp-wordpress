<?php
/**
 * Las allowlists del servidor MCP coinciden con lo que se registra.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\AbilityRegistrar;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\ReadGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WriteGateway;

/**
 * `ServerRegistrar` publica la unión de `AbilityRegistrar::ABILITIES` y
 * `ExtendedAbilityRegistrar::ABILITIES`. Una herramienta registrada que falte
 * en la lista no llega al cliente; un nombre de la lista sin registrar rompe
 * el catálogo. Esta prueba falla si cualquiera de las dos cosas pasa.
 */
final class AbilityCatalogTest extends NexoTestCase {

	public function test_lo_registrado_es_exactamente_la_allowlist_del_servidor(): void {
		$registered = $this->registered_names();
		$allowlist  = array_merge( AbilityRegistrar::ABILITIES, ExtendedAbilityRegistrar::ABILITIES );
		sort( $allowlist );

		$this->assertSame( $allowlist, $registered );
	}

	public function test_ninguna_herramienta_esta_en_las_dos_listas(): void {
		$this->assertSame(
			array(),
			array_values( array_intersect( AbilityRegistrar::ABILITIES, ExtendedAbilityRegistrar::ABILITIES ) )
		);
	}

	public function test_cada_registrador_registra_solo_su_propia_lista(): void {
		$this->basic_registrar()->register_abilities();
		$basic = array_keys( \WP_Test_State::$abilities );
		sort( $basic );
		$expected = AbilityRegistrar::ABILITIES;
		sort( $expected );
		$this->assertSame( $expected, $basic );

		\WP_Test_State::$abilities = array();
		$this->extended_registrar()->register_abilities();
		$extended = array_keys( \WP_Test_State::$abilities );
		sort( $extended );
		$expected = ExtendedAbilityRegistrar::ABILITIES;
		sort( $expected );
		$this->assertSame( $expected, $extended );
	}

	/**
	 * @return array<int, string>
	 */
	private function registered_names(): array {
		$this->basic_registrar()->register_abilities();
		$this->extended_registrar()->register_abilities();

		$names = array_keys( \WP_Test_State::$abilities );
		sort( $names );

		return $names;
	}

	private function basic_registrar(): AbilityRegistrar {
		$settings = $this->settings();
		$read     = new ReadGateway();

		return new AbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			$read,
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new WriteGateway( $settings, $read )
		);
	}

	private function extended_registrar(): ExtendedAbilityRegistrar {
		$settings = $this->settings();

		return new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		);
	}
}
