<?php
/**
 * Pestaña Accesos y tabla de Actividad (04 «Mapa claro»).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Admin\ConnectionMap;
use NexoDev\NexoMCP\Support\Settings;

final class DashboardAccessTest extends NexoTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['settings-updated'] );
		parent::tearDown();
	}

	/**
	 * @param list<array<string, mixed>> $connections Conexiones OAuth.
	 * @param list<object>               $audit       Entradas de auditoría.
	 */
	private function render( array $connections = array(), array $audit = array() ): string {
		$settings            = Settings::defaults();
		$endpoint            = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https            = true;
		$is_local_http       = false;
		$is_server_ready     = true;
		$is_woocommerce      = false;
		$adapter_version     = '1';
		$audit_entries       = $audit;
		$registration        = ( new \NexoDev\NexoMCP\Registration\RegistrationState() )->all();
		$license_offline     = false;
		$license_grace_days  = 0;
		$license_plan_label  = 'Licencia activa';
		$license_sites_label = '';
		$report_builders     = array();
		$report_max_length   = 2000;
		$report_min_length   = 10;
		$oauth_connections   = $connections;
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

	private function panel( string $html, string $id ): string {
		$start = $this->pos( $html, 'data-nexo-panel="' . $id . '"' );
		$next  = strpos( $html, 'data-nexo-panel="', $start + 10 );

		return substr( $html, $start, ( false === $next ? strlen( $html ) : $next ) - $start );
	}

	private function activity( string $html ): string {
		$start = $this->pos( $html, 'id="nexo-mcp-sec-actividad"' );
		$end   = $this->pos( $html, 'id="nexo-mcp-sec-licencia"', $start );

		return substr( $html, $start, $end - $start );
	}

	/** @return list<array<string, mixed>> */
	private function connection( string $scope, string $last = '' ): array {
		return array(
			array(
				'client_id'    => 'client-x',
				'user_id'      => 7,
				'client_name'  => 'Claude Desktop',
				'scope'        => $scope,
				'last_used_at' => $last,
			),
		);
	}

	private function entry( string $status, string $operation = 'read', string $summary = '' ): object {
		return (object) array(
			'occurred_at'    => '2026-11-27 10:00:00',
			'user_id'        => 3,
			'tool'           => 'nexo/lista',
			'operation'      => $operation,
			'status'         => $status,
			'result_summary' => $summary,
		);
	}

	public function test_accesos_muestra_cliente_alcances_y_ultimo_uso(): void {
		$panel = $this->panel( $this->render( $this->connection( 'mcp:connect mcp:read', '2026-11-27 09:00:00' ) ), 'accesos' );

		$this->assertStringContainsString( 'Claude Desktop', $panel );
		$this->assertStringContainsString( 'class="nexo-mcp-scope"><code>mcp:read<', $panel );
		$this->assertStringContainsString( 'Último uso: 2026-11-27 09:00:00 UTC', $panel );
		$this->assertStringContainsString( 'Editar alcances', $panel );
		$this->assertStringContainsString( 'name="scopes[]"', $panel );
	}

	public function test_alcance_riesgoso_lleva_marca_con_texto(): void {
		$panel = $this->panel( $this->render( $this->connection( 'mcp:connect mcp:read mcp:destructive' ) ), 'accesos' );

		$this->assertMatchesRegularExpression( '/nexo-mcp-scope nexo-mcp-scope--risky[^>]*>.*?mcp:destructive.*?Riesgo alto/s', $panel );
		$this->assertSame( 1, substr_count( $panel, 'nexo-mcp-scope--risky"' ) );
		$this->assertStringContainsString( 'class="nexo-mcp-scope"><code>mcp:read<', $panel );
	}

	public function test_alcance_normal_no_lleva_marca_de_riesgo(): void {
		$panel = $this->panel( $this->render( $this->connection( 'mcp:connect mcp:read' ) ), 'accesos' );

		$this->assertStringNotContainsString( 'nexo-mcp-scope--risky"', $panel );
		$this->assertStringNotContainsString( 'Riesgo alto</span>', $panel );
	}

	public function test_revocar_conserva_clase_y_data_attributes(): void {
		$panel = $this->panel( $this->render( $this->connection( 'mcp:read' ) ), 'accesos' );

		$this->assertMatchesRegularExpression( '/class="[^"]*nexo-mcp-revoke-oauth[^"]*"\s+data-client="client-x"\s+data-user="7"/', $panel );
		$this->assertStringContainsString( 'Revocar', $panel );
		$this->assertStringContainsString( 'aria-label="Revocar acceso de Claude Desktop"', $panel );
	}

	public function test_accesos_vacio_conserva_el_mensaje(): void {
		$panel = $this->panel( $this->render(), 'accesos' );

		$this->assertStringContainsString( 'nexo-mcp-empty-connections', $panel );
		$this->assertStringContainsString( 'Todavía no hay clientes autorizados.', $panel );
		$this->assertStringNotContainsString( 'nexo-mcp-revoke-oauth', $panel );
	}

	public function test_riesgo_de_alcance_sale_de_connection_map(): void {
		$this->assertTrue( ConnectionMap::is_risky_scope( 'mcp:destructive' ) );
		$this->assertTrue( ConnectionMap::is_risky_scope( 'mcp:publish' ) );
		$this->assertFalse( ConnectionMap::is_risky_scope( 'mcp:read' ) );
		$this->assertFalse( ConnectionMap::is_risky_scope( 'mcp:inexistente' ) );
	}

	public function test_actividad_exito_dice_correcto_en_texto(): void {
		$sec = $this->activity( $this->render( array(), array( $this->entry( 'success' ) ) ) );

		$this->assertStringContainsString( 'nexo-mcp-event--success', $sec );
		$this->assertStringContainsString( 'Correcto', $sec );
		$this->assertStringContainsString( 'class="nexo-mcp-table__date"', $sec );
		$this->assertStringContainsString( 'nexo/lista', $sec );
	}

	public function test_actividad_reciente_del_resumen_dice_el_resultado_en_texto(): void {
		$panel = $this->panel( $this->render( array(), array( $this->entry( 'success' ), $this->entry( 'denied' ) ) ), 'resumen' );

		$this->assertMatchesRegularExpression( '/nexo-mcp-event--success">.*?Correcto</s', $panel );
		$this->assertMatchesRegularExpression( '/nexo-mcp-event--denied">.*?Denegado</s', $panel );
		$this->assertStringNotContainsString( '>success<', $panel );
		$this->assertStringNotContainsString( '>denied<', $panel );
	}

	public function test_actividad_error_y_denegado_dicen_su_estado_en_texto(): void {
		$sec = $this->activity( $this->render( array(), array( $this->entry( 'error', 'write', 'Falló' ), $this->entry( 'denied' ) ) ) );

		$this->assertStringContainsString( 'nexo-mcp-event--error', $sec );
		$this->assertMatchesRegularExpression( '/nexo-mcp-event--error.*?Error/s', $sec );
		$this->assertStringContainsString( 'Denegado', $sec );
		$this->assertStringNotContainsString( 'Correcto', $sec );
		$this->assertStringContainsString( 'Falló', $sec );
	}

	public function test_actividad_vacia_conserva_la_fila_vacia(): void {
		$sec = $this->activity( $this->render() );

		$this->assertStringContainsString( 'nexo-mcp-table__empty', $sec );
		$this->assertStringContainsString( 'Aún no hay actividad registrada.', $sec );
	}

	public function test_actividad_conserva_el_ancla(): void {
		$this->assertStringContainsString( 'id="nexo-mcp-sec-actividad"', $this->render() );
	}
}
