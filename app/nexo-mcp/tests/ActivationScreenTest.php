<?php
/**
 * Pantalla de activación: identidad Nexo (04 «Mapa claro») sin cambiar el formulario.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

final class ActivationScreenTest extends NexoTestCase {

	private function render( bool $grace_expired = false, bool $custom_endpoint = false ): string {
		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/activacion.php';

		return (string) ob_get_clean();
	}

	public function test_brand_has_monogram_and_dark_readable_wordmark(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<div class="nexo-mcp-onboarding__brand"[^>]*>\s*<svg/', $html );
		$this->assertStringContainsString( '<b>nexo</b>', $html );
		$this->assertStringContainsString( 'MCP', $html );
		// El logo-nexo.svg tiene texto blanco: sobre superficie clara sería ilegible.
		$this->assertStringNotContainsString( 'logo-nexo.svg', $html );
	}

	public function test_brand_is_labelled_for_assistive_tech(): void {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<svg[^>]*aria-hidden="true"/', $html );
	}

	public function test_license_form_hooks_and_fields_are_unchanged(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<form id="nexo-mcp-license-form" class="nexo-mcp-registration__form">', $html );
		$this->assertStringContainsString( 'for="nexo-mcp-license-key"', $html );
		$this->assertMatchesRegularExpression( '/<input\s[^>]*id="nexo-mcp-license-key"[^>]*name="license_key"[^>]*required/s', $html );
		$this->assertStringContainsString( 'maxlength="40"', $html );
		$this->assertStringContainsString( 'placeholder="PMCP-XXXXX-XXXXX-XXXXX-XXXXX"', $html );
		$this->assertStringContainsString( 'button button-primary button-hero', $html );
		$this->assertStringContainsString( 'class="nexo-mcp-registration__message" role="status" aria-live="polite"', $html );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
	}

	public function test_steps_and_copy_are_preserved(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'class="is-current"', $html );
		$this->assertStringContainsString( '<span>03</span>', $html );
		$this->assertStringContainsString( 'Paso 01 de 03', $html );
		$this->assertStringContainsString( '<h1>Activa tu licencia</h1>', $html );
	}

	public function test_grace_expired_and_custom_endpoint_variants(): void {
		$this->assertStringContainsString( 'Hay que volver a confirmar tu licencia', $this->render( true ) );
		$this->assertStringContainsString( 'Ojo:', $this->render( false, true ) );
		$this->assertStringNotContainsString( 'Ojo:', $this->render() );
	}

	public function test_no_reference_to_deleted_font(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'inter-latin', $html );
		$this->assertStringNotContainsString( 'NexoInter', $html );

		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.css' );
		$this->assertStringNotContainsString( 'inter-latin', $css );
		$this->assertStringNotContainsString( 'NexoInter', $css );
	}
}
