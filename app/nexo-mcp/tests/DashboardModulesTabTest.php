<?php
/**
 * Pestaña Módulos del panel: filas agrupadas con interruptor y estado en texto.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Admin\ConnectionMap;
use NexoDev\NexoMCP\Support\Settings;

final class DashboardModulesTabTest extends NexoTestCase {

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['settings-updated'] );
		parent::tearDown();
	}

	/** Ajustes con una mezcla de módulos encendidos, de riesgo y no. */
	private function fixture(): array {
		$settings = Settings::defaults();

		foreach ( array_keys( $settings ) as $key ) {
			if ( str_ends_with( (string) $key, '_enabled' ) ) {
				$settings[ $key ] = false;
			}
		}

		foreach ( array( 'server_enabled', 'content_tools_enabled', 'divi_library_tools_enabled', 'destructive_enabled', 'cache_purge_enabled', 'woocommerce_tools_enabled' ) as $key ) {
			$settings[ $key ] = true;
		}

		$settings['read_only']      = true;
		$settings['drafts_only']    = false;
		$settings['proxy_resource'] = 'https://tunel.example/mcp';

		return $settings;
	}

	private function render( array $settings, bool $is_woocommerce = false ): string {
		$endpoint          = 'https://example.test/wp-json/nexo-mcp/v1/mcp';
		$is_https          = true;
		$is_local_http     = false;
		$is_server_ready   = true;
		$adapter_version   = '1';
		$audit_entries     = array();
		$registration      = ( new \NexoDev\NexoMCP\Registration\RegistrationState() )->all();
		$license_offline     = false;
		$license_grace_days  = 0;
		$license_plan_label  = 'Licencia activa';
		$license_sites_label = '';
		$report_builders     = array();
		$report_max_length   = 2000;
		$report_min_length   = 10;
		$oauth_connections = array();
		$oauth_site_url    = 'https://example.test';
		$report_notice     = null;
		$update_available  = false;
		$api_configured    = true;
		$update            = array(
			'version'    => '',
			'published'  => '',
			'checked_at' => '',
		);

		ob_start();
		require dirname( __DIR__ ) . '/src/Admin/views/dashboard.php';

		return (string) ob_get_clean();
	}

	private function panel( string $html ): string {
		$start = $this->pos( $html, 'data-nexo-panel="modulos"' );
		$next  = strpos( $html, 'data-nexo-panel="', $start + 10 );

		return substr( $html, $start, ( false === $next ? strlen( $html ) : $next ) - $start );
	}

	/**
	 * Filas (`<label class="nexo-mcp-toggle…">…</label>`) por nombre de ajuste.
	 *
	 * @return array<string, string>
	 */
	private function rows( string $panel ): array {
		preg_match_all( '/<label class="nexo-mcp-toggle.*?<\/label>/s', $panel, $matches );
		$rows = array();

		foreach ( $matches[0] as $row ) {
			if ( 1 === preg_match( '/name="nexo_mcp_settings\[(\w+)\]"/', $row, $name ) ) {
				$rows[ $name[1] ] = $row;
			}
		}

		return $rows;
	}

	public function test_cada_ajuste_aparece_una_sola_vez_con_el_mismo_valor_y_estado(): void {
		$settings = $this->fixture();
		$panel    = $this->panel( $this->render( $settings, true ) );

		preg_match_all( '/<input\b[^>]*name="nexo_mcp_settings\[(\w+)\]"[^>]*>/s', $panel, $inputs, PREG_SET_ORDER );
		$seen = array();

		foreach ( $inputs as $input ) {
			$seen[ $input[1] ][] = $input[0];
		}

		$expected = array( 'server_enabled', 'read_only', 'drafts_only', 'working_copy_default', 'enforce_connection_scopes', 'cache_purge_enabled', 'proxy_enabled', 'proxy_resource' );
		foreach ( ConnectionMap::module_groups( true ) as $modules ) {
			foreach ( $modules as $module ) {
				$expected[] = $module['key'];
			}
		}

		$this->assertEqualsCanonicalizing( $expected, array_keys( $seen ), 'mismo conjunto de nombres' );

		foreach ( $seen as $name => $tags ) {
			$this->assertCount( 1, $tags, $name . ' una sola vez' );
			$tag = $tags[0];

			if ( 'proxy_resource' === $name ) {
				$this->assertStringContainsString( 'type="url"', $tag );
				$this->assertStringContainsString( 'value="https://tunel.example/mcp"', $tag );
				continue;
			}

			$this->assertStringContainsString( 'type="checkbox"', $tag, $name );
			$this->assertStringContainsString( 'value="1"', $tag, $name );
			$on = 'proxy_enabled' === $name ? true : ! empty( $settings[ $name ] );
			$this->assertSame( $on, str_contains( $tag, "checked='checked'" ), $name . ' checked' );
		}
	}

	public function test_conserva_formulario_accion_y_grupo_de_opciones(): void {
		$panel = $this->panel( $this->render( $this->fixture() ) );

		$this->assertStringContainsString( '<form action="options.php" method="post" class="nexo-mcp-settings-form">', $panel );
		$this->assertSame( 1, substr_count( $panel, '<form' ) );
	}

	public function test_los_grupos_salen_en_orden_y_luego_los_bloques_extra(): void {
		$panel = $this->panel( $this->render( $this->fixture() ) );
		$last  = -1;

		foreach ( array( 'Sitio', 'Elementor', 'Divi 5', 'Riesgo alto', 'Servidor y política', 'Túnel o proxy' ) as $heading ) {
			$pos = strpos( $panel, '<h3>' . $heading . '</h3>' );
			$this->assertNotFalse( $pos, $heading );
			$this->assertGreaterThan( $last, $pos, $heading );
			$last = (int) $pos;
		}
	}

	public function test_cada_modulo_cae_en_el_grupo_de_connection_map(): void {
		$panel = $this->panel( $this->render( $this->fixture(), true ) );

		foreach ( ConnectionMap::module_groups( true ) as $label => $modules ) {
			$from  = $this->pos( $panel, '<h3>' . $label . '</h3>' );
			$to    = strpos( $panel, '</section>', $from );
			$this->assertNotFalse( $to, $label );
			$chunk = substr( $panel, $from, (int) $to - $from );

			foreach ( $modules as $module ) {
				$this->assertStringContainsString( 'name="nexo_mcp_settings[' . $module['key'] . ']"', $chunk, $module['key'] . ' en ' . $label );
			}
		}
	}

	public function test_woocommerce_solo_si_esta_activo(): void {
		$this->assertStringNotContainsString( 'woocommerce_tools_enabled', $this->panel( $this->render( $this->fixture(), false ) ) );
		$this->assertStringContainsString( 'woocommerce_tools_enabled', $this->panel( $this->render( $this->fixture(), true ) ) );
	}

	public function test_los_modulos_de_riesgo_llevan_etiqueta_de_advertencia_y_conservan_data_nexo(): void {
		$rows = $this->rows( $this->panel( $this->render( $this->fixture(), true ) ) );

		foreach ( ConnectionMap::module_groups( true ) as $modules ) {
			foreach ( $modules as $module ) {
				$row = $rows[ $module['key'] ];

				if ( $module['risk'] ) {
					$this->assertStringContainsString( 'nexo-mcp-tag--sensitive', $row, $module['key'] );
					$this->assertMatchesRegularExpression( '/nexo-mcp-tag--sensitive">[^<]*Riesgo alto</', $row, $module['key'] );
					$this->assertStringContainsString( 'data-nexo-risk="on"', $row, $module['key'] );
					$this->assertStringContainsString( 'data-nexo-confirm-word=', $row, $module['key'] );
					$this->assertStringContainsString( 'data-nexo-confirm-title=', $row, $module['key'] );
					$this->assertStringContainsString( 'data-nexo-confirm-text=', $row, $module['key'] );
				} else {
					$this->assertStringNotContainsString( 'nexo-mcp-tag--sensitive', $row, $module['key'] );
					$this->assertStringNotContainsString( 'data-nexo-risk', $row, $module['key'] );
				}
			}
		}

		foreach ( array( 'read_only', 'drafts_only', 'enforce_connection_scopes', 'working_copy_default' ) as $risky ) {
			$this->assertStringContainsString( 'data-nexo-risk="off"', $rows[ $risky ], $risky );
			$this->assertStringContainsString( 'nexo-mcp-tag--sensitive', $rows[ $risky ], $risky );
		}
		$this->assertStringNotContainsString( 'data-nexo-risk', $rows['server_enabled'] );
		$this->assertStringNotContainsString( 'data-nexo-risk', $rows['cache_purge_enabled'] );
	}

	public function test_cada_fila_dice_nombre_scope_en_mono_y_estado_en_texto(): void {
		$rows = $this->rows( $this->panel( $this->render( $this->fixture(), true ) ) );

		foreach ( ConnectionMap::module_groups( true ) as $modules ) {
			foreach ( $modules as $module ) {
				$row = $rows[ $module['key'] ];
				$this->assertStringContainsString( '<strong>' . $module['label'], $row, $module['key'] );

				foreach ( $module['scopes'] as $scope ) {
					$this->assertStringContainsString( '<code>' . $scope . '</code>', $row, $module['key'] );
				}
			}
		}

		foreach ( $rows as $name => $row ) {
			if ( 'proxy_resource' === $name ) {
				continue;
			}
			$this->assertStringContainsString( 'nexo-mcp-toggle__state', $row, $name );
			$this->assertStringContainsString( 'Activo', $row, $name );
			$this->assertStringContainsString( 'Apagado', $row, $name );
			$this->assertStringContainsString( '<i aria-hidden="true"></i>', $row, $name );
		}
	}
}
