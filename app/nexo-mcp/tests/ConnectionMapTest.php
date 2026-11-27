<?php
/**
 * Conteos de la banda del mapa de conexión y su render en el panel.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Admin\ConnectionMap;
use NexoDev\NexoMCP\Support\Settings;

final class ConnectionMapTest extends NexoTestCase {

	private const MODULE_KEYS = array(
		'wordpress_tools_enabled',
		'content_tools_enabled',
		'seo_tools_enabled',
		'appearance_tools_enabled',
		'builders_tools_enabled',
		'design_system_tools_enabled',
		'builders_dynamic_enabled',
		'builders_components_enabled',
		'divi_design_tools_enabled',
		'divi_presets_tools_enabled',
		'divi_dynamic_tools_enabled',
		'divi_conditions_tools_enabled',
		'divi_theme_builder_tools_enabled',
		'divi_library_tools_enabled',
		'maintenance_tools_enabled',
		'extension_deletion_enabled',
		'publishing_enabled',
		'destructive_enabled',
	);

	private const RISK_KEYS = array(
		'design_system_tools_enabled',
		'builders_dynamic_enabled',
		'builders_components_enabled',
		'divi_design_tools_enabled',
		'divi_presets_tools_enabled',
		'divi_dynamic_tools_enabled',
		'divi_conditions_tools_enabled',
		'divi_theme_builder_tools_enabled',
		'divi_library_tools_enabled',
		'maintenance_tools_enabled',
		'extension_deletion_enabled',
		'publishing_enabled',
		'destructive_enabled',
	);

	/**
	 * @param list<string> $on Claves encendidas; el resto queda apagado.
	 * @return array<string, bool|int|string>
	 */
	private function only( array $on ): array {
		$settings = Settings::defaults();

		foreach ( array_merge( self::MODULE_KEYS, array( 'woocommerce_tools_enabled' ) ) as $key ) {
			$settings[ $key ] = false;
		}

		foreach ( $on as $key ) {
			$settings[ $key ] = true;
		}

		return $settings;
	}

	public function test_sin_conexiones_ni_modulos_todo_en_cero(): void {
		$summary = ConnectionMap::summarize( $this->only( array() ), array(), false );

		$this->assertSame( 0, $summary['connections'] );
		$this->assertSame( 0, $summary['modules_active'] );
		$this->assertSame( count( self::MODULE_KEYS ), $summary['modules_total'] );
		$this->assertSame( 0, $summary['risk_active'] );
	}

	public function test_cuenta_una_conexion_por_fila(): void {
		$summary = ConnectionMap::summarize( $this->only( array() ), array( array(), array(), array() ), false );

		$this->assertSame( 3, $summary['connections'] );
	}

	public function test_cuenta_modulos_activos_y_de_riesgo_alto(): void {
		$summary = ConnectionMap::summarize(
			$this->only( array( 'content_tools_enabled', 'seo_tools_enabled', 'publishing_enabled', 'destructive_enabled' ) ),
			array(),
			false
		);

		$this->assertSame( 4, $summary['modules_active'] );
		$this->assertSame( 2, $summary['risk_active'] );
	}

	public function test_todos_encendidos_activa_todos_y_los_de_riesgo(): void {
		$summary = ConnectionMap::summarize( $this->only( self::MODULE_KEYS ), array(), false );

		$this->assertSame( count( self::MODULE_KEYS ), $summary['modules_active'] );
		$this->assertSame( count( self::MODULE_KEYS ), $summary['modules_total'] );
		$this->assertSame( count( self::RISK_KEYS ), $summary['risk_active'] );
	}

	public function test_woocommerce_solo_cuenta_si_esta_instalado(): void {
		$settings = $this->only( array( 'woocommerce_tools_enabled' ) );

		$without = ConnectionMap::summarize( $settings, array(), false );
		$with    = ConnectionMap::summarize( $settings, array(), true );

		$this->assertSame( 0, $without['modules_active'] );
		$this->assertSame( count( self::MODULE_KEYS ), $without['modules_total'] );
		$this->assertSame( 1, $with['modules_active'] );
		$this->assertSame( count( self::MODULE_KEYS ) + 1, $with['modules_total'] );
		$this->assertSame( 0, $with['risk_active'] );
	}

	public function test_una_clave_ausente_cuenta_como_apagada(): void {
		$summary = ConnectionMap::summarize( array(), array(), false );

		$this->assertSame( 0, $summary['modules_active'] );
	}

	/** @param array<string, mixed> $over */
	private function render( array $over = array() ): string {
		$vars = array_merge(
			array(
				'settings'          => $this->only( array( 'content_tools_enabled', 'seo_tools_enabled', 'publishing_enabled' ) ),
				'is_https'          => true,
				'is_local_http'     => false,
				'is_server_ready'   => true,
				'is_woocommerce'    => false,
				'oauth_connections' => array(),
			),
			$over
		);

		$settings          = $vars['settings'];
		$endpoint          = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https          = $vars['is_https'];
		$is_local_http     = $vars['is_local_http'];
		$is_server_ready   = $vars['is_server_ready'];
		$is_woocommerce    = $vars['is_woocommerce'];
		$adapter_version   = '1';
		$audit_entries     = array();
		$registration      = array();
		$oauth_connections = $vars['oauth_connections'];
		$oauth_site_url    = 'https://example.test';
		$report_notice     = null;
		$update_available  = false;
		$api_configured    = false;

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';
		$html = (string) ob_get_clean();

		$start = $this->pos( $html, '<header class="nexo-mcp-map"' );
		$end   = $this->pos( $html, '</header>', $start );

		return substr( $html, $start, $end - $start + 9 );
	}

	/** @return list<array<string, mixed>> */
	private function connections( int $n ): array {
		return array_fill( 0, $n, array( 'client_id' => 'c', 'user_id' => 1, 'client_name' => 'Cliente', 'scope' => 'mcp:connect', 'last_used_at' => '' ) );
	}

	public function test_la_banda_nombra_los_cuatro_nodos_en_una_lista_ordenada(): void {
		$band = $this->render();

		$this->assertStringContainsString( '<ol class="nexo-mcp-map__diagram"', $band );
		$this->assertSame( 4, substr_count( $band, 'class="nexo-mcp-map__node' ) );
		foreach ( array( 'Tu IA', 'OAuth 2.1 · PKCE', 'WordPress', 'Módulos' ) as $label ) {
			$this->assertStringContainsString( $label, $band );
		}
		$this->assertSame( 3, substr_count( $band, 'class="nexo-mcp-map__link" aria-hidden="true"' ), 'las líneas son decorativas' );
	}

	public function test_sin_conexiones_dice_ninguna_ia_conectada(): void {
		$band = $this->render();

		$this->assertStringContainsString( 'Ninguna IA conectada', $band );
		$this->assertStringNotContainsString( 'clientes autorizados', $band );
	}

	public function test_con_tres_conexiones_dice_tres_clientes_autorizados(): void {
		$band = $this->render( array( 'oauth_connections' => $this->connections( 3 ) ) );

		$this->assertStringContainsString( '3 clientes autorizados', $band );
		$this->assertStringNotContainsString( 'Ninguna IA conectada', $band );
	}

	public function test_con_una_conexion_usa_el_singular(): void {
		$band = $this->render( array( 'oauth_connections' => $this->connections( 1 ) ) );

		$this->assertStringContainsString( '1 cliente autorizado', $band );
	}

	public function test_sin_https_el_nodo_oauth_avisa_en_texto_y_no_espera_al_descubrimiento(): void {
		$band = $this->render( array( 'is_https' => false, 'is_server_ready' => false ) );

		$this->assertStringContainsString( 'Sin HTTPS', $band );
		$this->assertStringContainsString( 'data-nexo-https="0"', $band );
		$this->assertStringContainsString( 'Conector inactivo', $band );
	}

	public function test_con_https_el_nodo_oauth_espera_la_comprobacion_del_navegador(): void {
		$band = $this->render();

		$this->assertStringContainsString( 'data-nexo-https="1"', $band );
		$this->assertStringContainsString( 'Comprobando', $band );
		$this->assertStringContainsString( 'Conector respondiendo', $band );
		$this->assertStringContainsString( 'nexo-mcp-map__discovery', $band );
	}

	public function test_http_local_se_dice_como_tal(): void {
		$band = $this->render( array( 'is_https' => true, 'is_local_http' => true ) );

		$this->assertStringContainsString( 'HTTP local permitido', $band );
	}

	public function test_el_nodo_de_modulos_muestra_activos_sobre_total_y_riesgo(): void {
		$band = $this->render();

		$this->assertStringContainsString( 'Módulos activos 3/18', $band );
		$this->assertStringContainsString( '1 de riesgo alto activo', $band );
	}

	public function test_sin_modulos_de_riesgo_lo_dice(): void {
		$band = $this->render( array( 'settings' => $this->only( array( 'content_tools_enabled' ) ) ) );

		$this->assertStringContainsString( 'Módulos activos 1/18', $band );
		$this->assertStringContainsString( 'Ninguno de riesgo alto', $band );
	}

	public function test_con_woocommerce_el_total_sube_en_uno(): void {
		$band = $this->render( array( 'is_woocommerce' => true ) );

		$this->assertStringContainsString( '/19', $band );
	}

	public function test_las_acciones_usan_los_ganchos_existentes_sin_repetir_ids(): void {
		$band = $this->render();

		$this->assertStringContainsString( 'data-nexo-tab="conectar"', $band );
		$this->assertStringContainsString( 'Conectar una IA', $band );
		$this->assertStringContainsString( 'Probar OAuth', $band );
		$this->assertStringNotContainsString( 'id="', str_replace( 'id="nexo-mcp-map-title"', '', $band ) );
		$this->assertStringNotContainsString( 'logo-nexo.svg', $band, 'el logo de texto blanco ya no se usa sobre la banda clara' );
	}

	public function test_muestra_el_host_y_las_versiones(): void {
		$band = $this->render();

		$this->assertStringContainsString( 'example.test', $band );
		$this->assertStringContainsString( '1.0.0-test', $band );
	}
}
