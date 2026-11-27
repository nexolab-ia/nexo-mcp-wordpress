<?php
/**
 * Pestañas reducidas del panel y vista de Resumen (04 «Mapa claro»).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Admin\ConnectionMap;
use NexoDev\NexoMCP\Support\Settings;

final class DashboardTabsTest extends NexoTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['settings-updated'] );
		parent::tearDown();
	}

	/**
	 * @param list<string> $on Claves encendidas; el resto de módulos queda apagado.
	 * @return array<string, mixed>
	 */
	private function only( array $on ): array {
		$settings = Settings::defaults();

		foreach ( array_keys( $settings ) as $key ) {
			if ( str_ends_with( (string) $key, '_enabled' ) ) {
				$settings[ $key ] = false;
			}
		}

		foreach ( $on as $key ) {
			$settings[ $key ] = true;
		}

		return $settings;
	}

	/** @param array<string, mixed> $over */
	private function render( array $over = array() ): string {
		$vars = array_merge(
			array(
				'settings'          => $this->only( array( 'content_tools_enabled', 'divi_library_tools_enabled', 'destructive_enabled' ) ),
				'is_woocommerce'    => false,
				'audit_entries'     => array(),
				'oauth_connections' => array(),
				'api_configured'    => true,
				'report_notice'     => null,
			),
			$over
		);

		$settings          = $vars['settings'];
		$endpoint          = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https          = true;
		$is_local_http     = false;
		$is_server_ready   = true;
		$is_woocommerce    = $vars['is_woocommerce'];
		$adapter_version   = '1';
		$audit_entries     = $vars['audit_entries'];
		$registration      = ( new \NexoDev\NexoMCP\Registration\RegistrationState() )->all();
		$license_offline     = false;
		$license_grace_days  = 0;
		$license_plan_label  = 'Licencia activa';
		$license_sites_label = '';
		$report_builders     = array();
		$report_max_length   = 2000;
		$report_min_length   = 10;
		$oauth_connections = $vars['oauth_connections'];
		$oauth_site_url    = 'https://example.test';
		$report_notice     = $vars['report_notice'];
		$update_available  = false;
		$api_configured    = $vars['api_configured'];
		$update            = array(
			'version'    => '',
			'published'  => '',
			'checked_at' => '',
		);

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';

		return (string) ob_get_clean();
	}

	private function panel( string $html, string $id ): string {
		$start = (int) strpos( $html, 'data-nexo-panel="' . $id . '"' );
		$next  = strpos( $html, 'data-nexo-panel="', $start + 10 );
		$end   = false === $next ? strlen( $html ) : $next;

		return substr( $html, $start, $end - $start );
	}

	/** Etiqueta de apertura del panel `$id` (atributos incluidos). */
	private function panel_tag( string $html, string $id ): string {
		$start = (int) strpos( $html, 'id="nexo-mcp-panel-' . $id . '"' );
		$from  = (int) strrpos( substr( $html, 0, $start ), '<section' );

		return substr( $html, $from, (int) strpos( $html, '>', $start ) - $from );
	}

	/** @return list<array<string, mixed>> */
	private function connections( int $n ): array {
		$rows = array();

		for ( $i = 1; $i <= $n; ++$i ) {
			$rows[] = array(
				'client_id'    => 'c' . $i,
				'user_id'      => 1,
				'client_name'  => 'Cliente' . $i,
				'scope'        => 'mcp:connect mcp:read',
				'last_used_at' => '',
			);
		}

		return $rows;
	}

	/** @return list<object> */
	private function audit( int $n ): array {
		$rows = array();

		for ( $i = 1; $i <= $n; ++$i ) {
			$rows[] = (object) array(
				'occurred_at' => '2026-11-27 10:0' . $i . ':00',
				'user_id'     => 1,
				'tool'        => 'herramienta-' . $i,
				'status'      => 'success',
			);
		}

		return $rows;
	}

	public function test_hay_exactamente_cinco_pestanas_en_orden(): void {
		$html = $this->render();
		$nav  = substr( $html, (int) strpos( $html, '<nav class="nexo-mcp-tabs"' ) );
		$nav  = substr( $nav, 0, (int) strpos( $nav, '</nav>' ) );

		preg_match_all( '/class="nexo-mcp-tab"[^>]*data-nexo-tab="([a-z]+)"/s', $nav, $m );

		$this->assertSame( array( 'resumen', 'conectar', 'accesos', 'modulos', 'sistema' ), $m[1] );
		$this->assertSame( 5, preg_match_all( '/data-nexo-panel="/', $html ) );
		foreach ( array( 'resumen', 'conectar', 'accesos', 'modulos', 'sistema' ) as $id ) {
			$this->assertStringContainsString( 'id="nexo-mcp-panel-' . $id . '"', $html );
			$this->assertStringContainsString( 'aria-labelledby="nexo-mcp-tablink-' . $id . '"', $html );
			$this->assertStringContainsString( 'id="nexo-mcp-tablink-' . $id . '"', $html );
		}
		foreach ( array( 'Resumen', 'Conectar', 'Accesos', 'Módulos', 'Sistema' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '</a>', $nav );
		}
	}

	public function test_por_defecto_solo_resumen_esta_visible(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'hidden', $this->panel_tag( $html, 'resumen' ) );
		$this->assertStringContainsString( 'hidden', $this->panel_tag( $html, 'sistema' ) );
		$this->assertSame( 1, substr_count( substr( $html, (int) strpos( $html, '<nav class="nexo-mcp-tabs"' ), 1500 ), 'aria-current="page"' ) );
	}

	public function test_sistema_agrupa_sus_cinco_secciones_en_orden_con_el_ancla_de_soporte(): void {
		$sistema = $this->panel( $this->render(), 'sistema' );
		$last    = -1;

		foreach ( array( 'actividad', 'licencia', 'actualizaciones', 'endpoint', 'soporte' ) as $section ) {
			$pos = strpos( $sistema, 'id="nexo-mcp-sec-' . $section . '"' );
			$this->assertNotFalse( $pos, $section );
			$this->assertGreaterThan( $last, $pos, 'orden de ' . $section );
			$last = (int) $pos;
		}

		$this->assertGreaterThan( $last, (int) strpos( $sistema, 'id="nexo-mcp-reportar"' ), 'el ancla de reporte vive dentro de Soporte' );
		$this->assertStringContainsString( 'id="nexo-mcp-endpoint-url"', $sistema );
		$this->assertStringContainsString( 'nexo-mcp-discovery"', $sistema, 'la tarjeta de descubrimiento vive en Endpoint' );
		$this->assertStringContainsString( 'Aún no hay actividad registrada.', $sistema );
	}

	public function test_la_tarjeta_de_descubrimiento_ya_no_esta_en_el_resumen(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'nexo-mcp-discovery"', $this->panel( $html, 'resumen' ) );
		$this->assertSame( 1, substr_count( $html, 'nexo-mcp-status-card nexo-mcp-discovery"' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function legacy_tabs(): array {
		return array(
			'licencia'        => array( 'licencia' ),
			'actualizaciones' => array( 'actualizaciones' ),
			'endpoint'        => array( 'endpoint' ),
			'actividad'       => array( 'actividad' ),
			'soporte'         => array( 'soporte' ),
		);
	}

	/** @dataProvider legacy_tabs */
	public function test_los_slugs_viejos_abren_sistema_en_su_seccion( string $slug ): void {
		$_GET['tab'] = $slug;
		$html        = $this->render();

		$this->assertStringNotContainsString( 'hidden', $this->panel_tag( $html, 'sistema' ) );
		$this->assertStringContainsString( 'hidden', $this->panel_tag( $html, 'resumen' ) );
		$this->assertStringContainsString( 'data-nexo-section="nexo-mcp-sec-' . $slug . '"', $this->panel_tag( $html, 'sistema' ) );
		$this->assertMatchesRegularExpression( '/id="nexo-mcp-tablink-sistema"[^>]*aria-current="page"/s', $html );
	}

	public function test_un_slug_nuevo_se_respeta_y_uno_desconocido_vuelve_a_resumen(): void {
		$_GET['tab'] = 'accesos';
		$this->assertStringNotContainsString( 'hidden', $this->panel_tag( $this->render(), 'accesos' ) );

		$_GET['tab'] = 'no-existe';
		$this->assertStringNotContainsString( 'hidden', $this->panel_tag( $this->render(), 'resumen' ) );
	}

	public function test_la_vuelta_del_reporte_abre_sistema_en_soporte(): void {
		$html = $this->render( array( 'report_notice' => array( 'type' => 'success', 'message' => 'Enviado' ) ) );

		$this->assertStringNotContainsString( 'hidden', $this->panel_tag( $html, 'sistema' ) );
		$this->assertStringContainsString( 'Enviado', $this->panel( $html, 'sistema' ) );
	}

	public function test_resumen_agrupa_los_modulos_en_cuatro_grupos_en_orden(): void {
		$resumen = $this->panel( $this->render(), 'resumen' );
		$last    = -1;

		foreach ( array( 'Sitio', 'Elementor', 'Divi 5', 'Riesgo alto' ) as $group ) {
			$pos = strpos( $resumen, '<h3>' . $group . '</h3>' );
			$this->assertNotFalse( $pos, $group );
			$this->assertGreaterThan( $last, $pos );
			$last = (int) $pos;
		}

		$this->assertSame( 18, substr_count( $resumen, '<li class="nexo-mcp-modrow' ), '18 módulos sin WooCommerce' );
		$this->assertStringNotContainsString( 'nexo-mcp-status-grid', $resumen );
		$this->assertStringNotContainsString( 'Entorno', $resumen );
		$this->assertStringNotContainsString( '<form', $resumen, 'sólo lectura: no duplica el formulario de ajustes' );
		$this->assertStringNotContainsString( 'name="nexo_mcp_settings', $resumen );
	}

	public function test_cada_fila_dice_nombre_scope_estado_y_enlaza_a_modulos(): void {
		$resumen = $this->panel( $this->render(), 'resumen' );

		$this->assertMatchesRegularExpression( '/Contenido avanzado.*?<code>mcp:content<\/code>.*?Activo/s', $resumen );
		$this->assertMatchesRegularExpression( '/Biblioteca de Divi.*?<code>mcp:divi_library<\/code>.*?Activo/s', $resumen );
		$this->assertMatchesRegularExpression( '/SEO técnico.*?<code>mcp:seo<\/code>.*?Apagado/s', $resumen );
		$this->assertMatchesRegularExpression( '/Acciones destructivas.*?<code>mcp:destructive<\/code>.*?Activo/s', $resumen );
		$this->assertSame( 3, substr_count( $resumen, 'nexo-mcp-modstate--on' ) );
		$this->assertSame( 15, substr_count( $resumen, 'nexo-mcp-modstate--off' ) );
		$this->assertStringContainsString( 'Cambiar en Módulos', $resumen );
		$this->assertStringContainsString( 'data-nexo-tab="modulos"', $resumen );
	}

	public function test_woocommerce_solo_aparece_si_esta_activo(): void {
		$this->assertStringNotContainsString( 'mcp:commerce', $this->panel( $this->render(), 'resumen' ) );

		$resumen = $this->panel( $this->render( array( 'is_woocommerce' => true ) ), 'resumen' );
		$this->assertStringContainsString( 'mcp:commerce', $resumen );
		$this->assertSame( 19, substr_count( $resumen, '<li class="nexo-mcp-modrow' ) );
	}

	public function test_los_modulos_de_riesgo_llevan_etiqueta_en_texto(): void {
		$resumen = $this->panel( $this->render(), 'resumen' );

		$this->assertStringContainsString( 'Riesgo alto</span>', $resumen );
	}

	public function test_el_mapa_de_modulos_es_la_unica_fuente_de_etiquetas_y_scopes(): void {
		$groups = ConnectionMap::module_groups( false );

		$this->assertSame( array( 'Sitio', 'Elementor', 'Divi 5', 'Riesgo alto' ), array_keys( $groups ) );
		$total = 0;
		foreach ( $groups as $rows ) {
			foreach ( $rows as $row ) {
				++$total;
				$this->assertNotSame( '', $row['label'] );
				$this->assertNotEmpty( $row['scopes'] );
			}
		}
		$summary = ConnectionMap::summarize( $this->only( array() ), array(), false );
		$this->assertSame( $summary['modules_total'], $total );
	}

	public function test_aside_sin_accesos_ni_actividad_muestra_estados_vacios(): void {
		$resumen = $this->panel( $this->render(), 'resumen' );

		$this->assertStringContainsString( 'Todavía no hay accesos vigentes.', $resumen );
		$this->assertStringContainsString( 'Aún no hay actividad registrada.', $resumen );
		$this->assertStringNotContainsString( 'Ver todos', $resumen );
		$this->assertStringNotContainsString( 'Ver toda', $resumen );
	}

	public function test_aside_muestra_como_mucho_tres_accesos_y_enlaza_a_todos(): void {
		$resumen = $this->panel( $this->render( array( 'oauth_connections' => $this->connections( 5 ) ) ), 'resumen' );

		$this->assertSame( 3, substr_count( $resumen, 'class="nexo-mcp-asidecard"' ) );
		$this->assertStringContainsString( 'Cliente1', $resumen );
		$this->assertStringContainsString( 'Cliente3', $resumen );
		$this->assertStringNotContainsString( 'Cliente4', $resumen );
		$this->assertStringContainsString( '<code>mcp:read</code>', $resumen );
		$this->assertStringContainsString( 'Ver todos', $resumen );
		$this->assertStringContainsString( 'data-nexo-tab="accesos"', $resumen );
		$this->assertStringNotContainsString( 'nexo-mcp-revoke-oauth', $resumen, 'revocar sigue sólo en Accesos' );
	}

	public function test_con_dos_accesos_los_muestra_todos(): void {
		$resumen = $this->panel( $this->render( array( 'oauth_connections' => $this->connections( 2 ) ) ), 'resumen' );

		$this->assertSame( 2, substr_count( $resumen, 'class="nexo-mcp-asidecard"' ) );
	}

	public function test_aside_muestra_las_cinco_ultimas_entradas_y_enlaza_a_sistema_actividad(): void {
		$resumen = $this->panel( $this->render( array( 'audit_entries' => $this->audit( 8 ) ) ), 'resumen' );

		$this->assertSame( 5, substr_count( $resumen, 'class="nexo-mcp-timeline__item' ) );
		$this->assertStringContainsString( 'herramienta-1', $resumen );
		$this->assertStringContainsString( 'herramienta-5', $resumen );
		$this->assertStringNotContainsString( 'herramienta-6', $resumen );
		$this->assertStringContainsString( 'Ver toda', $resumen );
		$this->assertStringContainsString( '#nexo-mcp-sec-actividad', $resumen );
		$this->assertStringContainsString( 'data-nexo-tab="sistema"', $resumen );
	}

	public function test_sistema_conserva_la_tabla_completa_de_actividad(): void {
		$sistema = $this->panel( $this->render( array( 'audit_entries' => $this->audit( 8 ) ) ), 'sistema' );

		$this->assertSame( 8, substr_count( $sistema, 'class="nexo-mcp-table__date"' ) );
	}

	public function test_los_ganchos_de_admin_js_siguen_presentes(): void {
		$html = $this->render();

		foreach ( array( 'nexo-mcp-secret-dialog', 'nexo-mcp-confirm', 'nexo-mcp-endpoint-url', 'nexo-mcp-profile-status' ) as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $html, $id );
		}
		$this->assertStringContainsString( 'nexo-mcp-discovery-alert', $html );
		$this->assertStringContainsString( 'nexo-mcp-copy-endpoint', $html );
	}
}
