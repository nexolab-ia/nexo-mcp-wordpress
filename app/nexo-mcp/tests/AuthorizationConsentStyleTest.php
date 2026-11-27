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

	public function test_reduced_motion_switches_off_the_only_transition(): void {
		$css = $this->styles();

		// El botón sí tiene transición: la regla de movimiento reducido es real, no decorativa.
		$this->assertStringContainsString( 'transition:background', $css );
		$this->assertSame( 1, substr_count( $css, 'animation:' ), 'sólo queda la anulación dentro de prefers-reduced-motion' );
		$this->assertMatchesRegularExpression( '/@media \(prefers-reduced-motion:reduce\)\{\s*\*\{animation:none!important;transition:none!important\}\s*\}/', $css );
	}

	public function test_colors_come_from_tokens_and_dark_mode_only_overrides_tokens(): void {
		$css = $this->styles();

		// Fuera de las dos declaraciones de tokens no queda ningún color de paleta repetido.
		$rules = (string) preg_replace( '/:root\{[^}]*\}/', '', $css );
		foreach ( array( '#a5b4c8', '#5eead4', '#334155', '#e2e8f0' ) as $literal ) {
			$this->assertStringNotContainsString( $literal, $rules, $literal );
		}

		$this->assertSame( 1, substr_count( $css, 'prefers-color-scheme:dark' ), 'un solo bloque oscuro' );
		$this->assertDoesNotMatchRegularExpression( '/--muted:\s*#475569/', $css, '--muted no repite el literal de --fg-soft' );
	}

	/**
	 * Ejecuta render_consent() de verdad en un PHP aparte (la pantalla termina con exit).
	 */
	private function render_consent_output( string $login ): string {
		$code = sprintf(
			'require %s; WP_Test_State::$user_login = %s; $c = ( new ReflectionClass( %s ) )->newInstanceWithoutConstructor(); $m = new ReflectionMethod( $c, "render_consent" ); $m->setAccessible( true ); $m->invoke( $c, array( "client_id" => "client-1", "scope" => "mcp:read" ), array( "client_name" => "Claude" ), array( "mcp:read" ) );',
			var_export( __DIR__ . '/bootstrap.php', true ),
			var_export( $login, true ),
			var_export( AuthorizationController::class, true )
		);

		$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );

		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$this->assertSame( '', $err, 'render_consent() no emite errores ni avisos' );

		return $out;
	}

	public function test_render_consent_puts_the_nonce_and_the_user_in_the_form(): void {
		$html = $this->render_consent_output( 'maria-admin' );

		$this->assertStringContainsString( '<form method="post" class="decide">', $html );
		$this->assertStringContainsString( 'name="nexo_mcp_consent_nonce"', $html );
		$this->assertStringContainsString( 'value="nonce-for-nexo_mcp_oauth_consent"', $html );
		$this->assertStringContainsString( 'maria-admin', $html );
		$this->assertLessThan( $this->pos( $html, '</form>' ), $this->pos( $html, 'name="nexo_mcp_consent_nonce"' ), 'el nonce va dentro del formulario' );
	}

	public function test_consent_markup_is_still_fed_by_render_consent(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/OAuth/AuthorizationController.php' );
		$start  = $this->pos( $source, 'private function render_consent(' );
		$body   = substr( $source, $start, $this->pos( $source, 'private function consent_markup(' ) - $start );

		$this->assertStringContainsString( '$this->consent_markup(', $body );
	}
}
