<?php
/**
 * Herramienta get-divi-presets y el deshacer del área `presets`: registro, permisos y respuestas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\Builders\DiviPresetStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class DiviPresetToolsTest extends NexoTestCase {

	private const GET  = 'nexo-mcp/get-divi-presets';
	private const UNDO = 'nexo-mcp/undo-divi-design-change';

	private bool $active = true;

	/** @var array<string, mixed>|null */
	private ?array $presets = null;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	private int $flushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->active  = true;
		$this->flushes = 0;
		$this->colors  = array( 'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ) );
		$this->presets = array(
			'module' => array(
				'divi/text' => array(
					'default' => 'p1',
					'items'   => array(
						'p1' => array( 'id' => 'p1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'created' => 1, 'updated' => 2, 'attrs' => array( 'a' => 1 ) ),
					),
				),
			),
			'group'  => array(),
		);
	}

	private function design(): DiviDesignStore {
		return new DiviDesignStore(
			fn(): bool => $this->active,
			fn(): array => $this->colors,
			function ( array $all ): void {
				$this->colors = $all;
			},
			fn(): array => array(),
			fn( array $all ) => null,
			fn(): array => array(),
			function ( string $key, $value ): void {},
			function (): void {
				++$this->flushes;
			},
			function ( string $tool, string $status, $params, $result ): void {},
			fn( callable $work ) => $work()
		);
	}

	private function presets( DiviDesignStore $design ): DiviPresetStore {
		return new DiviPresetStore(
			$design,
			fn(): ?array => $this->presets,
			function ( array $data ): array {
				$this->presets = $data;

				return $data;
			},
			function (): void {
				$this->presets = null;
			},
			static fn( string $module ): array => array()
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array(), ?DiviDesignStore $design = null, bool $with_presets = true ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_design_tools_enabled' => true, 'divi_presets_tools_enabled' => true, 'destructive_enabled' => true ), $settings ) );
		$design                  ??= $this->design();

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, $design, null, $with_presets ? $this->presets( $design ) : null )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * Deja una entrada de presets en el historial con las herramientas reales y devuelve el almacén de diseño.
	 */
	private function with_presets_change(): DiviDesignStore {
		$design = $this->design();
		$result = $this->presets( $design )->update(
			function ( array $current ): array {
				$current['module']['divi/text']['default'] = '';

				return $current;
			},
			'set-divi-preset'
		);
		$this->assertIsArray( $result );

		return $design;
	}

	private function with_colors_change(): DiviDesignStore {
		$design                          = $this->design();
		$new                             = $this->colors;
		$new['gcid-aaaaaaaaaa']['color'] = '#ff0000';
		$this->assertIsArray( $design->write_colors( $new, 'set-divi-global-color' ) );

		return $design;
	}

	public function test_get_divi_presets_esta_en_la_allowlist_con_su_esquema_y_su_documentacion(): void {
		$all = $this->abilities();

		$this->assertContains( self::GET, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertArrayHasKey( self::GET, $all );
		$schema = $all[ self::GET ]['input_schema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'module' ), array_keys( $schema['properties'] ) );
		$this->assertSame( 'string', $schema['properties']['module']['type'] );
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertTrue( $all[ self::GET ]['meta']['annotations']['readonly'] );

		foreach ( array( 'nexo_mcp_divi5_required', 'nexo_mcp_divi_preset_module_unknown', 'nexo-mcp-undo-divi-design-change', 'mcp:divi_presets' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::GET ]['description'], $needle );
		}
	}

	public function test_el_deshacer_documenta_el_area_presets_y_su_scope(): void {
		$description = $this->abilities()[ self::UNDO ]['description'];

		foreach ( array( 'presets', 'mcp:divi_presets', 'nexo_mcp_divi_design_area_forbidden', 'nexo_mcp_divi_design_changed_since' ) as $needle ) {
			$this->assertStringContainsString( $needle, $description, $needle );
		}
	}

	public function test_leer_usa_el_permiso_de_lectura_de_constructores_y_no_el_interruptor_de_presets(): void {
		$this->assertTrue( $this->abilities()[ self::GET ]['permission_callback']( array() ) );
		$this->assertTrue( $this->abilities( array( 'divi_presets_tools_enabled' => false ) )[ self::GET ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'builders_tools_enabled' => false ) )[ self::GET ]['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::BUILDERS ] = false;
		$this->assertNotTrue( $this->abilities()[ self::GET ]['permission_callback']( array() ) );
	}

	public function test_un_token_con_solo_el_scope_de_presets_no_lee_y_uno_con_builders_si(): void {
		$all = $this->abilities();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:divi_presets' ) );
		$this->assertNotTrue( $all[ self::GET ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:builders' ) );
		$this->assertTrue( $all[ self::GET ]['permission_callback']( array() ) );
	}

	public function test_leer_devuelve_los_presets_y_acepta_el_filtro_de_modulo(): void {
		$all = $this->abilities();

		$all_result = $all[ self::GET ]['execute_callback']( array() );
		$this->assertSame( 'divi/text', $all_result['modules'][0]['module'] );
		$this->assertSame( 'p1', $all_result['modules'][0]['default'] );

		$one = $all[ self::GET ]['execute_callback']( array( 'module' => 'divi/text' ) );
		$this->assertSame( array( 'a' => 1 ), $one['modules'][0]['presets'][0]['attrs'] );

		$error = $all[ self::GET ]['execute_callback']( array( 'module' => 'divi/gallery' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'nexo_mcp_divi_preset_module_unknown', $error->get_error_code() );
	}

	public function test_leer_con_entrada_null_responde_como_con_entrada_vacia(): void {
		$result = $this->abilities()[ self::GET ]['execute_callback']( null );

		$this->assertSame( 'divi/text', $result['modules'][0]['module'] );
	}

	public function test_sin_divi_5_leer_responde_el_error_y_no_escribe(): void {
		$this->active = false;
		$before       = $this->presets;

		$result = $this->abilities()[ self::GET ]['execute_callback']( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, $this->presets );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_deshacer_un_cambio_de_presets_con_el_permiso_de_presets_lo_restaura(): void {
		$before = $this->presets;
		$design = $this->with_presets_change();
		$all    = $this->abilities( array( 'divi_design_tools_enabled' => false ), $design );

		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$result = $all[ self::UNDO ]['execute_callback']( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'presets', $result['area'] );
		$this->assertSame( $before, $this->presets );
	}

	public function test_deshacer_un_cambio_de_presets_sin_el_permiso_de_presets_se_niega_aunque_tenga_el_de_diseno(): void {
		$design = $this->with_presets_change();
		$after  = $this->presets;
		$all    = $this->abilities( array( 'divi_presets_tools_enabled' => false ), $design );

		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ) );

		// Aunque el permiso se evaluara antes de que otro proceso agregara la entrada, la ejecución lo vuelve a comprobar.
		$result = $all[ self::UNDO ]['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_design_area_forbidden', $result->get_error_code() );
		$this->assertSame( $after, $this->presets );
		$this->assertCount( 1, get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}

	public function test_deshacer_presets_con_el_interruptor_o_la_capacidad_apagados_o_en_solo_revisar_se_niega(): void {
		$design = $this->with_presets_change();

		$this->assertNotTrue( $this->abilities( array( 'read_only' => true ), $design )[ self::UNDO ]['permission_callback']( array() ) );

		\WP_Test_State::$caps[ Capabilities::DIVI_PRESETS ] = false;
		$this->assertNotTrue( $this->abilities( array(), $design )[ self::UNDO ]['permission_callback']( array() ) );
	}

	public function test_deshacer_presets_con_force_exige_ademas_el_permiso_destructivo_y_sin_force_no(): void {
		$design = $this->with_presets_change();
		$force  = array( 'force' => true );

		$this->assertTrue( $this->abilities( array( 'destructive_enabled' => false ), $design )[ self::UNDO ]['permission_callback']( array( 'force' => false ) ) );
		$this->assertTrue( $this->abilities( array(), $design )[ self::UNDO ]['permission_callback']( $force ) );
		$this->assertNotTrue( $this->abilities( array( 'destructive_enabled' => false ), $design )[ self::UNDO ]['permission_callback']( $force ) );

		\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
		$this->assertNotTrue( $this->abilities( array(), $design )[ self::UNDO ]['permission_callback']( $force ) );
	}

	public function test_deshacer_presets_con_cambio_ajeno_se_niega_y_con_force_lo_pisa(): void {
		$before = $this->presets;
		$design = $this->with_presets_change();
		$this->presets['module']['divi/text']['default'] = 'otro';
		$all = $this->abilities( array(), $design );

		$denied = $all[ self::UNDO ]['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'nexo_mcp_divi_design_changed_since', $denied->get_error_code() );
		$this->assertSame( 'otro', $this->presets['module']['divi/text']['default'] );

		$this->assertIsArray( $all[ self::UNDO ]['execute_callback']( array( 'force' => true ) ) );
		$this->assertSame( $before, $this->presets );
	}

	public function test_un_token_solo_con_el_scope_de_presets_deshace_presets_pero_no_colores(): void {
		$design = $this->with_presets_change();
		$all    = $this->abilities( array( 'divi_design_tools_enabled' => false ), $design );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:divi_presets' ) );
		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array() ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:divi_design' ) );
		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ), 'el scope de diseño no cubre un cambio de presets' );
	}

	public function test_previo_d3_deshacer_colores_sigue_con_el_permiso_de_diseno_y_no_pide_el_de_presets(): void {
		$before = $this->colors;
		$design = $this->with_colors_change();
		$all    = $this->abilities( array( 'divi_presets_tools_enabled' => false ), $design );

		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$result = $all[ self::UNDO ]['execute_callback']( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'colors', $result['area'] );
		$this->assertSame( $before, $this->colors );
		$this->assertSame( array(), get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}

	public function test_previo_d3_un_cambio_de_colores_no_lo_deshace_quien_solo_tiene_presets(): void {
		$design = $this->with_colors_change();
		$after  = $this->colors;
		$all    = $this->abilities( array( 'divi_design_tools_enabled' => false ), $design );

		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$result = $all[ self::UNDO ]['execute_callback']( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_design_area_forbidden', $result->get_error_code() );
		$this->assertSame( $after, $this->colors );
		$this->assertCount( 1, get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}

	public function test_previo_d3_sin_historial_el_permiso_de_deshacer_es_el_de_diseno_y_no_escribe(): void {
		$all = $this->abilities();

		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$this->assertNotTrue( $this->abilities( array( 'divi_design_tools_enabled' => false ) )[ self::UNDO ]['permission_callback']( array() ) );

		$result = $all[ self::UNDO ]['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_undo', $result->get_error_code() );
		$this->assertSame( 0, $this->flushes );
	}
}
