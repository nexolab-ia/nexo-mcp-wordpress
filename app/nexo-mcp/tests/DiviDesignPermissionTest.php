<?php
/**
 * Permiso del diseño global de Divi: interruptor, capacidad y scope OAuth.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Support\Settings;

final class DiviDesignPermissionTest extends NexoTestCase {

	private const SCOPE  = 'mcp:divi_design';
	private const SWITCH = 'divi_design_tools_enabled';

	private function policy( array $overrides = array() ): PermissionPolicy {
		return new PermissionPolicy( $this->settings( array_merge( array( 'read_only' => false ), $overrides ) ) );
	}

	private function can_write( PermissionPolicy $policy ): bool {
		return $policy->can_module_write( self::SWITCH, Capabilities::DIVI_DESIGN, 'manage_options' );
	}

	public function test_el_scope_apunta_a_la_capacidad_y_tiene_su_texto_de_consentimiento(): void {
		$this->assertSame( 'nexo_mcp_divi_design', Capabilities::DIVI_DESIGN );
		$this->assertTrue( ScopeMap::is_known( self::SCOPE ) );
		$this->assertContains( self::SCOPE, ScopeMap::all() );
		$this->assertSame( Capabilities::DIVI_DESIGN, ScopeMap::capability_for( self::SCOPE ) );
		$this->assertNotSame( self::SCOPE, ScopeMap::label_for( self::SCOPE ) );
		$this->assertStringContainsString( 'Divi', ScopeMap::label_for( self::SCOPE ) );
		$this->assertSame( array( self::SCOPE ), ScopeMap::scopes_for_capability( Capabilities::DIVI_DESIGN, 'write' ) );
		$this->assertContains( self::SCOPE, ScopeMap::parse( 'mcp:connect ' . self::SCOPE ) );
	}

	public function test_el_interruptor_viene_apagado_y_el_perfil_anuncia_el_scope_sólo_con_el_encendido(): void {
		$this->assertFalse( Settings::defaults()[ self::SWITCH ] );

		update_option( Settings::OPTION_NAME, array( 'read_only' => false, 'builders_tools_enabled' => true ) );
		$this->assertFalse( ( new Settings() )->get( self::SWITCH ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', new Settings() ) );
		$this->assertFalse( $this->can_write( new PermissionPolicy( new Settings() ) ) );

		$on = $this->settings( array( self::SWITCH => true, 'read_only' => false ) );
		$this->assertContains( self::SCOPE, ScopeMap::for_profile( 'manage', $on ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'read', $on ) );

		$off = $this->settings( array( self::SWITCH => false, 'read_only' => false ) );
		$this->assertNotContains( self::SCOPE, ScopeMap::for_profile( 'manage', $off ) );
	}

	public function test_escribir_exige_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		$this->assertTrue( $this->can_write( $this->policy( array( self::SWITCH => true ) ) ) );
		$this->assertFalse( $this->can_write( $this->policy( array( self::SWITCH => false ) ) ), 'interruptor apagado' );
		$this->assertFalse( $this->can_write( $this->policy( array( self::SWITCH => true, 'read_only' => true ) ) ) );

		\WP_Test_State::$caps[ Capabilities::DIVI_DESIGN ] = false;
		$this->assertFalse( $this->can_write( $this->policy( array( self::SWITCH => true ) ) ), 'sin la capacidad' );
	}

	public function test_un_token_sin_el_scope_no_escribe_y_con_el_scope_si(): void {
		$policy = $this->policy( array( self::SWITCH => true ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:design_system' ) );
		$this->assertFalse( $this->can_write( $policy ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertTrue( $this->can_write( $policy ) );
	}

	public function test_el_scope_no_salta_el_interruptor_apagado(): void {
		$policy = $this->policy( array( self::SWITCH => false ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', self::SCOPE ) );
		$this->assertFalse( $this->can_write( $policy ) );
	}

	public function test_el_scope_solo_no_da_permiso_de_ninguna_otra_herramienta(): void {
		$policy = $this->policy( array( self::SWITCH => true ) );

		$this->connect_with_scopes( array( 'mcp:connect', self::SCOPE ) );
		$this->assertFalse( $policy->can_module_write( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' ) );
		$this->assertFalse( $policy->can_module_read( 'builders_tools_enabled', Capabilities::BUILDERS, 'edit_pages' ) );
		$this->assertFalse( $policy->can_module_write( 'design_system_tools_enabled', Capabilities::DESIGN_SYSTEM, 'manage_options' ) );
		$this->assertFalse( $policy->can_module_write( 'builders_components_enabled', Capabilities::BUILDERS_COMPONENTS, 'edit_posts' ) );
		$this->assertFalse( $policy->can_module_write( 'content_tools_enabled', Capabilities::CONTENT_ADVANCED, 'edit_posts' ) );
	}

	public function test_otros_scopes_no_dan_el_permiso_de_divi(): void {
		$policy = $this->policy( array( self::SWITCH => true, 'design_system_tools_enabled' => true, 'builders_components_enabled' => true ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders:components', 'mcp:design_system', 'mcp:builders' ) );
		$this->assertFalse( $this->can_write( $policy ) );
	}
}
