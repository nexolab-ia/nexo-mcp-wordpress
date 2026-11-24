<?php
/**
 * Herramientas de escritura de presets de Divi y deshacer por área: registro, esquema, permisos y cableado.
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
use NexoDev\NexoMCP\Builders\DiviPresetOperations;
use NexoDev\NexoMCP\Builders\DiviPresetStore;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class DiviPresetWriteToolsTest extends NexoTestCase {

	private const SET     = 'nexo-mcp/set-divi-preset';
	private const DEFAULT = 'nexo-mcp/set-divi-default-preset';
	private const DELETE  = 'nexo-mcp/delete-divi-preset';
	private const UNDO    = 'nexo-mcp/undo-divi-design-change';

	private bool $active = true;

	/** @var array<string, mixed>|null */
	private ?array $presets = null;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $variables = array();

	/** @var array<string, array<int, int>> */
	private array $usage = array();

	private int $flushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->active  = true;
		$this->flushes = 0;
		$this->usage     = array();
		$this->variables = array();
		$this->options = array( 'accent_color' => '#2ea3f2' );
		$this->colors  = array( 'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ) );
		$this->presets = array(
			'module' => array(
				'divi/text' => array(
					'default' => 'p1',
					'items'   => array(
						'p1' => array( 'type' => 'module', 'id' => 'p1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'created' => 1, 'updated' => 2, 'attrs' => $this->bg( '#111111' ) ),
					),
				),
			),
			'group'  => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function bg( string $color ): array {
		return array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => $color ) ) ) ) ) );
	}

	private function design(): DiviDesignStore {
		return new DiviDesignStore(
			fn(): bool => $this->active,
			fn(): array => $this->colors,
			function ( array $all ): void {
				$this->colors = $all;
			},
			fn(): array => $this->variables,
			function ( array $all ) {
				$this->variables = $all;

				return null;
			},
			fn(): array => $this->options,
			function ( string $key, $value ): void {
				$this->options[ $key ] = $value;
			},
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
			static fn( string $module ): array => 'divi/text' === $module ? array( 'module.decoration.background' => 'divi/background' ) : array()
		);
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array(), ?DiviDesignStore $design = null ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_design_tools_enabled' => true, 'divi_presets_tools_enabled' => true, 'destructive_enabled' => true ), $settings ) );
		$design                  ??= $this->design();
		$store                     = $this->presets( $design );
		$ops                       = new DiviPresetOperations(
			$store,
			fn( string $kind, string $id ): array => array( 'post_ids' => $this->usage[ $kind . ':' . $id ] ?? array(), 'total' => count( $this->usage[ $kind . ':' . $id ] ?? array() ) ),
			static fn(): string => 'uuid-1',
			static fn(): int => 1800000000,
			static fn(): string => '5.19.1',
			new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name, static fn( int $id ) => false )
		);

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, $design, null, $store, $ops )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function inputs(): array {
		return array(
			self::SET     => array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'Nuevo', 'attrs' => $this->bg( '#abcdef' ) ),
			self::DEFAULT => array( 'kind' => 'module', 'name' => 'divi/text', 'id' => null ),
			self::DELETE  => array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p1' ),
		);
	}

	private function permission( string $name, array $settings = array(), ?array $input = null ) {
		return $this->abilities( $settings )[ $name ]['permission_callback']( $input ?? $this->inputs()[ $name ] );
	}

	private function history(): array {
		$value = get_option( DiviDesignStore::HISTORY_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	// --- Registro y esquema ---------------------------------------------------------------------

	public function test_estan_en_la_allowlist_con_esquema_cerrado_y_campos_requeridos(): void {
		$all      = $this->abilities();
		$required = array(
			self::SET     => array( 'kind', 'name', 'label' ),
			self::DEFAULT => array( 'kind', 'name', 'id' ),
			self::DELETE  => array( 'kind', 'name', 'id' ),
		);

		foreach ( $required as $name => $fields ) {
			$this->assertContains( $name, ExtendedAbilityRegistrar::ABILITIES, $name );
			$schema = $all[ $name ]['input_schema'];
			$this->assertFalse( $schema['additionalProperties'], $name );
			$this->assertSame( $fields, $schema['required'], $name );
			$this->assertSame( array( 'module', 'group' ), $schema['properties']['kind']['enum'], $name );
			$this->assertFalse( $all[ $name ]['meta']['annotations']['readonly'], $name );
			$this->assertStringContainsString( 'mcp:divi_presets', $all[ $name ]['description'], $name );
			$this->assertStringContainsString( 'nexo_mcp_divi5_required', $all[ $name ]['description'], $name );
			$this->assertStringContainsString( 'undo-divi-design-change', $all[ $name ]['description'], $name );
		}

		$this->assertSame( array( 'kind', 'name', 'id', 'label', 'attrs', 'module', 'group_id' ), array_keys( $all[ self::SET ]['input_schema']['properties'] ) );
		$this->assertSame( 'object', $all[ self::SET ]['input_schema']['properties']['attrs']['type'] );
		$this->assertSame( array( 'kind', 'name', 'id' ), array_keys( $all[ self::DEFAULT ]['input_schema']['properties'] ) );
		$this->assertSame( array( 'string', 'null' ), $all[ self::DEFAULT ]['input_schema']['properties']['id']['type'] );
		$this->assertSame( array( 'kind', 'name', 'id', 'force' ), array_keys( $all[ self::DELETE ]['input_schema']['properties'] ) );
		$this->assertSame( 'boolean', $all[ self::DELETE ]['input_schema']['properties']['force']['type'] );

		foreach ( array( 'nexo_mcp_divi_preset_invalid', 'nexo_mcp_divi_preset_not_found', 'nexo_mcp_divi_preset_module_unknown', 'catálogo' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::SET ]['description'], $needle );
		}

		foreach ( array( 'nexo_mcp_divi_preset_in_use', 'permiso destructivo', 'post_ids' ) as $needle ) {
			$this->assertStringContainsString( $needle, $all[ self::DELETE ]['description'], $needle );
		}

		$this->assertStringContainsString( '(hasta ' . DiviPresetOperations::MAX_POST_IDS . ';', $all[ self::DELETE ]['description'], 'el tope sale de la constante' );
		$this->assertStringContainsString( 'más de ' . DiviPresetOperations::MAX_POST_IDS . ')', $all[ self::DELETE ]['description'] );

		$this->assertTrue( $all[ self::DELETE ]['meta']['annotations']['destructive'] );
		$this->assertFalse( $all[ self::SET ]['meta']['annotations']['destructive'] );
		$this->assertFalse( $all[ self::DEFAULT ]['meta']['annotations']['destructive'] );
	}

	// --- Permisos -------------------------------------------------------------------------------

	public function test_escribir_exige_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		foreach ( array_keys( $this->inputs() ) as $name ) {
			$this->assertTrue( $this->permission( $name ), $name );
			$this->assertNotTrue( $this->permission( $name, array( 'divi_presets_tools_enabled' => false ) ), $name . ' interruptor apagado' );
			$this->assertNotTrue( $this->permission( $name, array( 'read_only' => true ) ), $name . ' sólo revisar' );
			$this->assertTrue( $this->permission( $name, array( 'divi_design_tools_enabled' => false ) ), $name . ': el interruptor de diseño no cuenta' );
		}

		\WP_Test_State::$caps[ Capabilities::DIVI_PRESETS ] = false;

		foreach ( array_keys( $this->inputs() ) as $name ) {
			$this->assertNotTrue( $this->permission( $name ), $name . ' sin la capacidad' );
		}
	}

	public function test_un_token_de_diseno_o_de_builders_no_escribe_presets_y_uno_de_presets_si(): void {
		$all = $this->abilities();

		foreach ( array_keys( $this->inputs() ) as $name ) {
			$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:builders', 'mcp:design_system', 'mcp:divi_design' ) );
			$this->assertNotTrue( $all[ $name ]['permission_callback']( $this->inputs()[ $name ] ), $name );

			$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:divi_presets' ) );
			$this->assertTrue( $all[ $name ]['permission_callback']( $this->inputs()[ $name ] ), $name );
		}
	}

	public function test_borrar_con_force_exige_ademas_el_permiso_destructivo_y_sin_force_no(): void {
		$force = array_merge( $this->inputs()[ self::DELETE ], array( 'force' => true ) );

		$this->assertTrue( $this->permission( self::DELETE, array( 'destructive_enabled' => false ), array_merge( $force, array( 'force' => false ) ) ) );
		$this->assertTrue( $this->permission( self::DELETE, array(), $force ) );
		$this->assertNotTrue( $this->permission( self::DELETE, array( 'destructive_enabled' => false ), $force ) );

		\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
		$this->assertNotTrue( $this->permission( self::DELETE, array(), $force ) );
		$this->assertTrue( $this->permission( self::SET, array(), $this->inputs()[ self::SET ] ), 'crear no pide el destructivo' );
	}

	// --- Ejecución ------------------------------------------------------------------------------

	public function test_cada_herramienta_escribe_con_su_operacion_y_deshacer_la_revierte(): void {
		$all = $this->abilities();
		$orig = $this->presets;

		$created = $all[ self::SET ]['execute_callback']( $this->inputs()[ self::SET ] );
		$this->assertSame( 'uuid-1', $created['id'] );
		$this->assertSame( '#abcdef', $this->presets['module']['divi/text']['items']['uuid-1']['attrs']['module']['decoration']['background']['desktop']['value']['color'] );

		$default = $all[ self::DEFAULT ]['execute_callback']( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'uuid-1' ) );
		$this->assertTrue( $default['changed'] );
		$this->assertSame( 'uuid-1', $this->presets['module']['divi/text']['default'] );

		$this->usage['module:uuid-1'] = array( 9 );
		$in_use                       = $all[ self::DELETE ]['execute_callback']( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'uuid-1' ) );
		$this->assertSame( 'nexo_mcp_divi_preset_in_use', $in_use->get_error_code() );
		$this->assertArrayHasKey( 'uuid-1', $this->presets['module']['divi/text']['items'] );

		$deleted = $all[ self::DELETE ]['execute_callback']( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'uuid-1', 'force' => true ) );
		$this->assertSame( array( 9 ), $deleted['posts_affected'] );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'] );

		foreach ( array( 'presets', 'presets', 'presets' ) as $area ) {
			$undone = $all[ self::UNDO ]['execute_callback']( array() );
			$this->assertSame( $area, $undone['area'] );
		}

		$this->assertSame( $orig, $this->presets );
		$this->assertSame( array(), $this->history() );
	}

	public function test_una_entrada_null_responde_como_vacia_y_valida(): void {
		$result = $this->abilities()[ self::SET ]['execute_callback']( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_invalid', $result->get_error_code() );
	}

	public function test_sin_divi_5_las_escrituras_responden_el_error_y_no_tocan_nada(): void {
		$this->active = false;
		$before       = $this->presets;
		$all          = $this->abilities();

		foreach ( array_keys( $this->inputs() ) as $name ) {
			$result = $all[ $name ]['execute_callback']( $this->inputs()[ $name ] );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code(), $name );
		}

		$this->assertSame( $before, $this->presets );
		$this->assertSame( 0, $this->flushes );
	}

	// --- Deshacer por área ----------------------------------------------------------------------

	/**
	 * Pila mixta: colores, presets, ajustes del tema (el más reciente arriba).
	 */
	private function mixed_stack(): DiviDesignStore {
		$design                          = $this->design();
		$new                             = $this->colors;
		$new['gcid-aaaaaaaaaa']['color'] = '#ff0000';
		$this->assertIsArray( $design->write_colors( $new, 'set-divi-global-color' ) );
		$this->assertIsArray(
			$this->presets( $design )->update(
				function ( array $current ): array {
					$current['module']['divi/text']['default'] = '';

					return $current;
				},
				'set-divi-default-preset'
			)
		);
		$this->assertIsArray( $design->write_theme_options( array( 'accent_color' => '#00ff00' ), 'set-divi-typography' ) );

		return $design;
	}

	private function areas(): array {
		return array_map( static fn( array $entry ): string => $entry['area'], $this->history() );
	}

	public function test_deshacer_acepta_area_opcional_con_su_lista_cerrada_y_lo_documenta(): void {
		$all    = $this->abilities();
		$schema = $all[ self::UNDO ]['input_schema'];

		$this->assertSame( array( 'force', 'area' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'colors', 'variables', 'theme_options', 'presets' ), $schema['properties']['area']['enum'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertStringContainsString( 'area', $all[ self::UNDO ]['description'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_design_area_invalid', $all[ self::UNDO ]['description'] );
	}

	public function test_con_area_presets_quien_solo_tiene_presets_deshace_el_cambio_de_presets_aunque_haya_otros_encima(): void {
		$design = $this->mixed_stack();
		$all    = $this->abilities( array( 'divi_design_tools_enabled' => false ), $design );

		// Sin área, el de arriba es de ajustes del tema (diseño): se niega, como antes.
		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array( 'area' => 'presets' ) ) );

		$result = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'presets' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'presets', $result['area'] );
		$this->assertSame( 2, $result['remaining'] );
		$this->assertSame( 'p1', $this->presets['module']['divi/text']['default'] );
		$this->assertSame( array( 'colors', 'theme_options' ), $this->areas() );
		$this->assertSame( '#ff0000', $this->colors['gcid-aaaaaaaaaa']['color'], 'no toca los colores' );
		$this->assertSame( '#00ff00', $this->options['accent_color'], 'no toca los ajustes' );
	}

	public function test_con_area_colors_quien_solo_tiene_diseno_deshace_los_colores_aunque_los_presets_esten_encima(): void {
		$design = $this->mixed_stack();
		$this->assertIsArray( $this->abilities( array(), $design )[ self::UNDO ]['execute_callback']( array() ), 'se quita lo de arriba (ajustes)' );
		$all = $this->abilities( array( 'divi_presets_tools_enabled' => false ), $design );

		// Ahora arriba está el cambio de presets: sin área, quien sólo tiene diseño se queda bloqueado.
		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array() ) );
		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array( 'area' => 'colors' ) ) );
		$this->assertNotTrue( $all[ self::UNDO ]['permission_callback']( array( 'area' => 'presets' ) ) );

		$result = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'colors' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'colors', $result['area'] );
		$this->assertSame( '#111111', $this->colors['gcid-aaaaaaaaaa']['color'] );
		$this->assertSame( array( 'presets' ), $this->areas() );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'], 'no toca los presets' );
	}

	public function test_con_area_ajena_al_permiso_se_niega_sin_restaurar_ni_sacar_nada(): void {
		$design = $this->mixed_stack();
		$all    = $this->abilities( array( 'divi_presets_tools_enabled' => false ), $design );
		$before = $this->history();

		$result = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'presets' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_design_area_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->history() );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'] );
	}

	public function test_con_area_deshace_la_entrada_mas_reciente_de_esa_area(): void {
		$design = $this->mixed_stack();
		$new    = $this->colors;
		$new['gcid-aaaaaaaaaa']['color'] = '#0000ff';
		$this->assertIsArray( $design->write_colors( $new, 'set-divi-global-color' ) );
		$all = $this->abilities( array(), $design );

		$result = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'colors' ) );

		$this->assertSame( 'colors', $result['area'] );
		$this->assertSame( '#ff0000', $this->colors['gcid-aaaaaaaaaa']['color'], 'sólo la última de colores' );
		$this->assertSame( array( 'colors', 'presets', 'theme_options' ), $this->areas() );
	}

	public function test_area_variables_cubre_cualquier_tipo_de_variable(): void {
		$design = $this->design();
		$this->assertIsArray( $design->write_variables( 'numbers', array( 'gvid-n1' => array( 'label' => 'R', 'value' => '1px', 'order' => 1, 'status' => 'active' ) ), 'set-divi-variable' ) );
		$this->assertIsArray( $this->presets( $design )->update( fn( array $c ): array => $c, 'set-divi-default-preset' ) );
		$all = $this->abilities( array(), $design );

		$result = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'variables' ) );

		$this->assertSame( 'variables:numbers', $result['area'] );
		$this->assertSame( array( 'presets' ), $this->areas() );
	}

	public function test_area_sin_cambios_responde_nothing_to_undo_y_area_desconocida_area_invalid_sin_tocar_nada(): void {
		$design = $this->mixed_stack();
		$all    = $this->abilities( array(), $design );
		$before = $this->history();

		$none = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'variables' ) );
		$this->assertInstanceOf( \WP_Error::class, $none );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_undo', $none->get_error_code() );

		$bad = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'bogus' ) );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_divi_design_area_invalid', $bad->get_error_code() );

		$this->assertSame( $before, $this->history() );
		$this->assertSame( '#ff0000', $this->colors['gcid-aaaaaaaaaa']['color'] );
	}

	public function test_con_area_el_cambio_ajeno_se_niega_y_force_exige_el_permiso_destructivo(): void {
		$design                                    = $this->mixed_stack();
		$this->presets['module']['divi/text']['default'] = 'otro';
		$all                                       = $this->abilities( array(), $design );

		$denied = $all[ self::UNDO ]['execute_callback']( array( 'area' => 'presets' ) );
		$this->assertSame( 'nexo_mcp_divi_design_changed_since', $denied->get_error_code() );
		$this->assertSame( 'otro', $this->presets['module']['divi/text']['default'] );

		$this->assertTrue( $all[ self::UNDO ]['permission_callback']( array( 'area' => 'presets', 'force' => true ) ) );
		$this->assertIsArray( $all[ self::UNDO ]['execute_callback']( array( 'area' => 'presets', 'force' => true ) ) );
		$this->assertSame( 'p1', $this->presets['module']['divi/text']['default'] );

		$this->assertNotTrue( $this->abilities( array( 'destructive_enabled' => false ), $this->mixed_stack() )[ self::UNDO ]['permission_callback']( array( 'area' => 'presets', 'force' => true ) ) );
	}

	public function test_previo_d3_sin_area_el_deshacer_sigue_sacando_el_ultimo_cambio(): void {
		$design = $this->mixed_stack();
		$all    = $this->abilities( array(), $design );

		$this->assertSame( 'theme_options', $all[ self::UNDO ]['execute_callback']( array() )['area'] );
		$this->assertSame( 'presets', $all[ self::UNDO ]['execute_callback']( array() )['area'] );
		$this->assertSame( 'colors', $all[ self::UNDO ]['execute_callback']( array() )['area'] );

		$none = $all[ self::UNDO ]['execute_callback']( array() );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_undo', $none->get_error_code() );
	}
}
