<?php
/**
 * Pestaña Conectar: los ganchos de admin.js y el orden de los pasos siguen en pie.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\Settings;

final class DashboardConnectTabTest extends NexoTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['settings-updated'] );
		parent::tearDown();
	}

	private function render( bool $https = true ): string {
		$settings            = Settings::defaults();
		$endpoint            = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https            = $https;
		$is_local_http       = false;
		$is_server_ready     = true;
		$is_woocommerce      = false;
		$adapter_version     = '1';
		$audit_entries       = array();
		$registration        = ( new \NexoDev\NexoMCP\Registration\RegistrationState() )->all();
		$license_offline     = false;
		$license_grace_days  = 0;
		$license_plan_label  = 'Licencia activa';
		$license_sites_label = '';
		$report_builders     = array();
		$report_max_length   = 2000;
		$report_min_length   = 10;
		$oauth_connections   = array();
		$oauth_site_url      = 'https://example.test';
		$report_notice       = null;
		$update_available    = false;
		$api_configured      = true;
		$update              = array(
			'version'    => '',
			'published'  => '',
			'checked_at' => '',
		);

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';

		return (string) ob_get_clean();
	}

	private function panel( bool $https = true ): string {
		$html  = $this->render( $https );
		$start = (int) strpos( $html, 'data-nexo-panel="conectar"' );
		$end   = (int) strpos( $html, 'data-nexo-panel="accesos"', $start );

		return substr( $html, $start, $end - $start );
	}

	public function test_los_pasos_van_en_orden(): void {
		$panel = $this->panel();
		$last  = -1;

		foreach ( array( '¿Con qué IA quieres conectar WordPress?', 'Elige qué podrá hacer la IA', 'Sigue el método recomendado', 'Autoriza en el navegador' ) as $title ) {
			$pos = (int) strpos( $panel, $title );
			$this->assertGreaterThan( $last, $pos, $title );
			$last = $pos;
		}
	}

	public function test_el_perfil_son_radios_con_nombre_valor_y_defecto(): void {
		$panel = $this->panel();

		$this->assertMatchesRegularExpression( '/name="nexo_mcp_connection_profile" value="read"\s+checked/', $panel );
		$this->assertMatchesRegularExpression( '/name="nexo_mcp_connection_profile" value="manage"/', $panel );
		$this->assertDoesNotMatchRegularExpression( '/value="manage"[^>]*checked=/', $panel );
		$this->assertStringContainsString( 'Solo revisar', $panel );
		$this->assertStringContainsString( 'Revisar y editar', $panel );
		$this->assertStringContainsString( 'nexo-mcp-tag--sensitive">Sensible', $panel );
		$this->assertMatchesRegularExpression( '/class="nexo-mcp-profile-grid"\s+data-read-scopes="[^"]+"\s+data-manage-scopes="[^"]+"/', $panel );
	}

	public function test_sobreviven_los_ganchos_de_admin_js(): void {
		$panel = $this->panel();

		foreach ( array( 'name="nexo_mcp_oauth_client"', 'nexo-mcp-oauth-recipe', 'data-client="codex"', 'data-client-name=', 'data-server-name=', 'data-bridge="true"', 'nexo-mcp-oauth-diagnostic', 'nexo-mcp-test-oauth', 'nexo-mcp-copy-instruction', 'nexo-mcp-hidden-prompt', 'data-codex-bridge-endpoint=', 'data-codex-native-endpoint=', 'id="nexo-mcp-codex-native-code"', 'id="nexo-mcp-profile-status"' ) as $hook ) {
			$this->assertStringContainsString( $hook, $panel, $hook );
		}
	}

	public function test_aviso_https_solo_sin_https(): void {
		$this->assertStringNotContainsString( 'nexo-mcp-oauth-warning', $this->panel( true ) );
		$this->assertStringContainsString( 'Este sitio no usa HTTPS.', $this->panel( false ) );
	}
}
