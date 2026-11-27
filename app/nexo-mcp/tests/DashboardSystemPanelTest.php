<?php
/**
 * Pestaña Sistema: índice de secciones y tarjetas (04 «Mapa claro»).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\Settings;

final class DashboardSystemPanelTest extends NexoTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['settings-updated'] );
		parent::tearDown();
	}

	/**
	 * @param list<array<string, mixed>> $connections Conexiones OAuth.
	 * @param list<object>               $audit       Entradas de auditoría.
	 */
	private function render( bool $api = true, bool $offline = false, bool $update_available = false ): string {
		$settings            = Settings::defaults();
		$endpoint            = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https            = true;
		$is_local_http       = false;
		$is_server_ready     = true;
		$is_woocommerce      = false;
		$adapter_version     = '1';
		$audit_entries       = array();
		$registration        = ( new \NexoDev\NexoMCP\Registration\RegistrationState() )->all();
		$license_offline     = $offline;
		$license_grace_days  = 0;
		$license_plan_label  = 'Licencia activa';
		$license_sites_label = '';
		$report_builders     = array();
		$report_max_length   = 2000;
		$report_min_length   = 10;
		$oauth_connections   = array();
		$oauth_site_url      = 'https://example.test';
		$report_notice       = null;
		$update_available    = $update_available;
		$api_configured      = $api;
		$update_changelog    = $update_available ? array( 'Cambio de prueba' ) : array();
		$updates_url         = 'https://example.test/wp-admin/plugins.php';
		$update              = array(
			'version'    => $update_available ? '9.9.9' : '',
			'published'  => '',
			'checked_at' => '',
		);

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';

		return (string) ob_get_clean();
	}

	private function system( string $html ): string {
		$start = (int) strpos( $html, 'data-nexo-panel="sistema"' );

		return substr( $html, $start );
	}

	public function test_section_index_links_follow_the_section_order(): void {
		$panel = $this->system( $this->render() );

		$this->assertMatchesRegularExpression( '/<nav[^>]*class="nexo-mcp-sysnav"[^>]*aria-label="[^"]+"/', $panel );

		$slugs = array( 'actividad', 'licencia', 'actualizaciones', 'endpoint', 'soporte' );
		$last  = -1;

		foreach ( $slugs as $slug ) {
			$link = strpos( $panel, 'href="#nexo-mcp-sec-' . $slug . '"' );
			$this->assertNotFalse( $link, "Falta el enlace a $slug" );
			$this->assertGreaterThan( $last, $link );
			$last = (int) $link;
			// El índice va antes que la sección que enlaza.
			$this->assertLessThan( (int) strpos( $panel, 'id="nexo-mcp-sec-' . $slug . '"' ), $link );
		}

		foreach ( array( 'Actividad', 'Licencia y sitio', 'Actualizaciones', 'Endpoint MCP', 'Soporte' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '<', substr( $panel, 0, (int) strpos( $panel, 'id="nexo-mcp-sec-actividad"' ) ) );
		}
	}

	public function test_hooks_used_by_admin_js_survive(): void {
		$panel = $this->system( $this->render() );

		foreach ( array(
			'data-nexo-license-action="recheck_license"',
			'data-nexo-license-action="release_license"',
			'data-nexo-check-updates',
			'nexo-mcp-registration__message',
			'nexo-mcp-updates__message',
			'id="nexo-mcp-endpoint-url"',
			'nexo-mcp-copy-endpoint',
			'data-target="nexo-mcp-endpoint-url"',
			'nexo-mcp-discovery__state',
			'nexo-mcp-discovery__hint',
			'data-resource-metadata=',
			'data-authorization-metadata=',
			'id="nexo-mcp-reportar"',
		) as $hook ) {
			$this->assertStringContainsString( $hook, $panel, $hook );
		}
	}

	public function test_updates_card_keeps_head_followed_by_its_siblings(): void {
		$panel = $this->system( $this->render() );
		$start = (int) strpos( $panel, 'class="nexo-mcp-card nexo-mcp-updates"' );
		$card  = substr( $panel, $start, (int) strpos( $panel, 'id="nexo-mcp-sec-endpoint"' ) - $start );

		$head   = (int) strpos( $card, 'nexo-mcp-card__head' );
		$box    = (int) strpos( $card, 'nexo-mcp-box' );
		$action = (int) strpos( $card, 'data-nexo-check-updates' );
		$msg    = (int) strpos( $card, 'nexo-mcp-updates__message' );

		$this->assertGreaterThan( $head, $box );
		$this->assertGreaterThan( $box, $action );
		$this->assertGreaterThan( $action, $msg );
		$this->assertSame( 1, substr_count( $card, 'nexo-mcp-card__head' ) );
	}

	public function test_updates_card_when_a_version_is_available(): void {
		$panel = $this->system( $this->render( true, false, true ) );

		$this->assertStringContainsString( 'Ir a actualizar', $panel );
		$this->assertStringNotContainsString( 'data-nexo-check-updates', $panel );
	}

	public function test_offline_notice_renders_inside_license_card(): void {
		$panel = $this->system( $this->render( true, true ) );

		$this->assertStringContainsString( 'nexo-mcp-license-offline', $panel );
		$this->assertStringContainsString( 'Sin confirmar', $panel );
	}

	public function test_no_api_variants_render_cards_and_no_forms(): void {
		$panel = $this->system( $this->render( false ) );

		$this->assertSame( 3, substr_count( $panel, 'nexo-mcp-api-missing' ) );
		$this->assertStringNotContainsString( 'data-nexo-license-action', $panel );
		$this->assertStringNotContainsString( 'data-nexo-check-updates', $panel );
		$this->assertStringNotContainsString( 'id="nexo-mcp-reportar"', $panel );
		$this->assertStringContainsString( 'NEXO_MCP_API_URL', $panel );
		// Las secciones siguen existiendo para que el índice no apunte al vacío.
		$this->assertStringContainsString( 'id="nexo-mcp-sec-soporte"', $panel );
	}

	public function test_obsolete_markup_classes_are_gone_from_the_stylesheet(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.css' );

		$this->assertStringNotContainsString( '.nexo-mcp-summary', $css );
		$this->assertStringNotContainsString( '.nexo-mcp-meta', $css );
		$this->assertStringNotContainsString( '.nexo-mcp-status-grid', $css );
		$this->assertStringContainsString( '.nexo-mcp-sysnav', $css );
	}
}
