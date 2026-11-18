<?php
/**
 * Permiso del sistema de diseño: interruptor, capacidad y scope OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;

final class DesignSystemPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:design_system';
	private const SWITCH = 'design_system_tools_enabled';

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false ), $overrides ) ) );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertSame( Capabilities::DESIGN_SYSTEM, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertSame( 'nexo_mcp_design_system', Capabilities::DESIGN_SYSTEM );
		$this->assertSame(
			'Cambiar clases, variables, colores y tipografía globales de Elementor (afecta a todo el sitio)',
			ScopeMap::label_for( self::SCOPE )
		);
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DESIGN_SYSTEM, 'write' ) );
	}

	public function test_el_perfil_de_administracion_anuncia_el_scope_sólo_con_el_interruptor_encendido(): void {
		// `settings()` guarda en la opción compartida: cada estado se comprueba antes de cambiar al otro.
		$on = $this->settings( array( self::SWITCH => true, 'read_only' => false ) );
		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $on ) );
		$this->assertContains( self::SCOPE, ScopeMap::advertised( $on ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'read', $on ), 'el perfil de lectura nunca lo anuncia' );

		$off = $this->settings( array( self::SWITCH => false, 'read_only' => false ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $off ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::advertised( $off ) );
	}

	public function test_apagar_otro_modulo_no_cambia_el_anuncio_de_este(): void {
		$settings = $this->settings( array( self::SWITCH => true, 'builders_tools_enabled' => false ) );

		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $settings ) );
	}

	public function test_escribir_exige_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		$this->assertTrue( $this->policy( array( self::SWITCH => true ) )->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
		$this->assertFalse( $this->policy( array( self::SWITCH => false ) )->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
		$this->assertFalse( $this->policy( array( self::SWITCH => true, 'read_only' => true ) )->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );

		\WP_Test_State::$caps[ Capabilities::DESIGN_SYSTEM ] = false;
		$this->assertFalse( $this->policy( array( self::SWITCH => true ) )->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
	}

	public function test_un_token_sin_el_scope_no_puede_escribir_y_con_el_scope_si(): void {
		$policy = $this->policy( array( self::SWITCH => true ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders' ) );
		$this->assertFalse( $policy->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertTrue( $policy->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
	}

	public function test_el_scope_no_salta_el_interruptor_apagado(): void {
		$policy = $this->policy( array( self::SWITCH => false ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertFalse( $policy->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
	}

	public function test_el_scope_no_da_permiso_de_otros_modulos(): void {
		$policy = $this->policy( array( self::SWITCH => true ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertFalse( $policy->can_module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' ) );
	}

	public function test_ajustes_guardados_sin_la_clave_dejan_el_interruptor_apagado(): void {
		update_option( Settings::OPTION_NAME, array( 'read_only' => false, 'builders_tools_enabled' => true ) );
		$settings = new Settings();

		$this->assertFalse( $settings->get( self::SWITCH ) );
		$this->assertFalse( Settings::defaults()[ self::SWITCH ] );
		$this->assertFalse( ( new PermissionPolicy( $settings ) )->can_module_write( self::SWITCH, Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $settings ) );
	}
}
