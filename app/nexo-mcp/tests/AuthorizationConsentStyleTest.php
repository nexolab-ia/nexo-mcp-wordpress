<?php
/**
 * Estilo de la pantalla de consentimiento OAuth (04 «Mapa claro») sin cambiar su formulario.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\OAuth\AuthorizationController;

final class AuthorizationConsentStyleTest extends NexoTestCase {

	private const NONCE_FIELD = '<input type="hidden" id="nexo_mcp_consent_nonce" name="nexo_mcp_consent_nonce" value="abc123" />';

	private function controller(): object {
		return ( new \ReflectionClass( AuthorizationController::class ) )->newInstanceWithoutConstructor();
	}

	private function call( string $method, mixed ...$args ): mixed {
		$m = new \ReflectionMethod( AuthorizationController::class, $method );
		$m->setAccessible( true );

		return $m->invoke( $this->controller(), ...$args );
	}

	private function styles(): string {
		return (string) $this->call( 'styles' );
	}

	private function markup(): string {
		$request = array(
			'client_id'             => 'client-1',
			'redirect_uri'          => 'https://client.test/cb',
			'response_type'         => 'code',
			'scope'                 => 'mcp:connect mcp:read',
			'state'                 => 'st',
			'resource'              => '',
			'code_challenge'        => 'chal',
			'code_challenge_method' => 'S256',
		);

		return (string) $this->call(
			'consent_markup',
			$request,
			array( 'client_name' => 'Claude' ),
			array( 'mcp:connect', 'mcp:read' ),
			'admin',
			self::NONCE_FIELD
		);
	}

	public function test_styles_drop_inter_and_prisma_look(): void {
		$css = $this->styles();

		$this->assertStringNotContainsString( 'NexoInter', $css );
		$this->assertStringNotContainsString( 'inter-latin', $css );
		$this->assertStringNotContainsString( '@font-face', $css );
		$this->assertStringNotContainsString( 'linear-gradient', $css );
		$this->assertStringNotContainsString( 'rgba(0,0,0,.08)', $css );
	}

	public function test_styles_use_nexo_tokens(): void {
		$css = $this->styles();

		$this->assertStringContainsString( '#0f766e', $css );
		$this->assertStringContainsString( '#115e59', $css );
		$this->assertStringContainsString( '#0f172a', $css );
		$this->assertStringContainsString( '#c7d2de', $css );
		$this->assertStringContainsString( '#4f46e5', $css );
		$this->assertStringContainsString( '-apple-system', $css );
		$this->assertStringContainsString( 'focus-visible', $css );
		$this->assertStringContainsString( 'prefers-reduced-motion', $css );
	}

	public function test_white_logo_sits_on_the_dark_slate_rail(): void {
		$css = $this->styles();

		$this->assertMatchesRegularExpression( '/\.rail\{[^}]*background:var\(--rail\)/', $css );
		$this->assertMatchesRegularExpression( '/--rail:#0f172a/', $css );
		$this->assertStringContainsString( 'class="logo"', $this->markup() );
		$this->assertStringContainsString( 'logo-nexo.svg', $this->markup() );
	}

	public function test_consent_form_is_unchanged(): void {
		$html = $this->markup();

		$this->assertStringContainsString( '<form method="post" class="decide">', $html );
		$this->assertStringContainsString( self::NONCE_FIELD, $html );

		foreach ( array(
			'client_id'             => 'client-1',
			'redirect_uri'          => 'https://client.test/cb',
			'response_type'         => 'code',
			'scope'                 => 'mcp:connect mcp:read',
			'state'                 => 'st',
			'resource'              => '',
			'code_challenge'        => 'chal',
			'code_challenge_method' => 'S256',
		) as $name => $value ) {
			$this->assertStringContainsString( sprintf( '<input type="hidden" name="%s" value="%s" />', $name, $value ), $html );
		}

		$this->assertStringContainsString( 'name="nexo_mcp_decision" value="deny" class="btn ghost">Rechazar<', $html );
		$this->assertStringContainsString( 'name="nexo_mcp_decision" value="allow" class="btn go">', $html );
		$this->assertStringContainsString( '<span class="key">mcp:read</span>', $html );
		$this->assertSame( 8, substr_count( $html, 'type="hidden" name=' ) );
	}

	public function test_nonce_action_and_field_name_are_untouched(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/OAuth/AuthorizationController.php' );

		$this->assertStringContainsString( "NONCE_ACTION = 'nexo_mcp_oauth_consent'", $source );
		$this->assertStringContainsString( "wp_nonce_field( self::NONCE_ACTION, 'nexo_mcp_consent_nonce', true, false )", $source );
	}
}
