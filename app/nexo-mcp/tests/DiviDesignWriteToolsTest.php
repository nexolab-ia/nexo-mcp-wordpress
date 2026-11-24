<?php
/**
 * Herramientas de escritura del diseño global de Divi: registro, esquema, permisos y cableado.
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
use NexoDev\NexoMCP\Builders\DiviDesignOperations;
use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

final class DiviDesignWriteToolsTest extends NexoTestCase {

	private const NAMES = array(
		'set-divi-global-color',
		'delete-divi-global-color',
		'set-divi-variable',
		'archive-divi-variable',
		'set-divi-typography',
	);

	private bool $active = true;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	/** @var array<string, mixed> */
	private array $variables = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, array<int, int>> */
	private array $usage = array();

	protected function setUp(): void {
		parent::setUp();
		$this->active    = true;
		$this->colors    = array( 'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ) );
		$this->variables = array( 'numbers' => array( 'gvid-n1' => array( 'label' => 'Radio', 'value' => '12px', 'order' => 1, 'status' => 'active' ) ) );
		$this->options   = array( 'accent_color' => '#2ea3f2' );
		$this->usage     = array();
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'divi_design_tools_enabled' => true, 'destructive_enabled' => true ), $settings ) );
		$store                     = new DiviDesignStore(
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
			function (): void {},
			function ( string $tool, string $status, $params, $result ): void {},
			fn( callable $work ) => $work()
		);
		$ops                       = new DiviDesignOperations(
			$store,
			fn( string $id ): array => $this->usage[ $id ] ?? array(),
			static fn( int $id ): ?string => null,
			static fn(): string => 'abcde12345'
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
			new BuilderGateway( null, null, $settings, null, null, null, null, null, null, $store, $ops )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function inputs(): array {
		return array(
			'set-divi-global-color'    => array( 'label' => 'Marca', 'color' => '#ff0000' ),
			'delete-divi-global-color' => array( 'id' => 'gcid-aaaaaaaaaa' ),
			'set-divi-variable'        => array( 'type' => 'numbers', 'label' => 'Espacio', 'value' => '8px' ),
			'archive-divi-variable'    => array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => true ),
			'set-divi-typography'      => array( 'body_font' => 'Lato' ),
		);
	}

	private function permission( string $name, array $settings = array(), ?array $input = null ) {
		return $this->abilities( $settings )[ 'nexo-mcp/' . $name ]['permission_callback']( $input ?? $this->inputs()[ $name ] );
	}

	public function test_estan_en_la_allowlist_con_esquema_cerrado_y_campos_requeridos(): void {
		$all      = $this->abilities();
		$required = array(
			'set-divi-global-color'    => array( 'label', 'color' ),
			'delete-divi-global-color' => array( 'id' ),
			'set-divi-variable'        => array( 'type', 'label', 'value' ),
			'archive-divi-variable'    => array( 'type', 'id', 'archived' ),
			'set-divi-typography'      => array(),
		);

		foreach ( self::NAMES as $name ) {
			$this->assertContains( 'nexo-mcp/' . $name, ExtendedAbilityRegistrar::ABILITIES, $name );
			$schema = $all[ 'nexo-mcp/' . $name ]['input_schema'];
			$this->assertFalse( $schema['additionalProperties'], $name );
			$this->assertSame( $required[ $name ], $schema['required'] ?? array(), $name );
			$this->assertFalse( $all[ 'nexo-mcp/' . $name ]['meta']['annotations']['readonly'], $name );
			$this->assertStringContainsString( 'mcp:divi_design', $all[ 'nexo-mcp/' . $name ]['description'], $name );
			$this->assertStringContainsString( 'nexo_mcp_divi5_required', $all[ 'nexo-mcp/' . $name ]['description'], $name );
			$this->assertStringContainsString( 'nexo_mcp_divi_design_invalid', $all[ 'nexo-mcp/' . $name ]['description'], $name );
			$this->assertStringContainsString( 'undo-divi-design-change', $all[ 'nexo-mcp/' . $name ]['description'], $name );
		}

		$this->assertSame( array( 'id', 'label', 'color' ), array_keys( $all['nexo-mcp/set-divi-global-color']['input_schema']['properties'] ) );
		$this->assertSame( array( 'id', 'force' ), array_keys( $all['nexo-mcp/delete-divi-global-color']['input_schema']['properties'] ) );
		$this->assertSame( array( 'type', 'id', 'label', 'value' ), array_keys( $all['nexo-mcp/set-divi-variable']['input_schema']['properties'] ) );
		$this->assertSame( array( 'numbers', 'strings', 'images', 'links', 'fonts' ), $all['nexo-mcp/set-divi-variable']['input_schema']['properties']['type']['enum'] );
		$this->assertSame( array( 'numbers', 'strings', 'images', 'links', 'fonts' ), $all['nexo-mcp/archive-divi-variable']['input_schema']['properties']['type']['enum'] );
		$this->assertSame(
			array( 'heading_font', 'body_font', 'heading_font_weight', 'body_font_weight', 'body_font_size', 'body_line_height', 'colors' ),
			array_keys( $all['nexo-mcp/set-divi-typography']['input_schema']['properties'] )
		);
		$this->assertSame(
			array( 'accent', 'secondary_accent', 'header', 'font', 'link' ),
			array_keys( $all['nexo-mcp/set-divi-typography']['input_schema']['properties']['colors']['properties'] )
		);
		$this->assertFalse( $all['nexo-mcp/set-divi-typography']['input_schema']['properties']['colors']['additionalProperties'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_color_in_use', $all['nexo-mcp/delete-divi-global-color']['description'] );
		$this->assertStringContainsString( 'permiso destructivo', $all['nexo-mcp/delete-divi-global-color']['description'] );
		$this->assertTrue( $all['nexo-mcp/delete-divi-global-color']['meta']['annotations']['destructive'] );
		$this->assertFalse( $all['nexo-mcp/set-divi-global-color']['meta']['annotations']['destructive'] );
	}

	public function test_deshacer_acepta_force_opcional_y_lo_documenta(): void {
		$all    = $this->abilities();
		$schema = $all['nexo-mcp/undo-divi-design-change']['input_schema'];

		$this->assertSame( array( 'force' ), array_keys( $schema['properties'] ) );
		$this->assertSame( 'boolean', $schema['properties']['force']['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertStringContainsString( 'nexo_mcp_divi_design_changed_since', $all['nexo-mcp/undo-divi-design-change']['description'] );
	}

	public function test_deshacer_con_force_pisa_un_cambio_posterior_y_sin_force_se_niega(): void {
		$all = $this->abilities();
		$all['nexo-mcp/set-divi-global-color']['execute_callback']( array( 'id' => 'gcid-aaaaaaaaaa', 'label' => 'Base', 'color' => '#ff0000' ) );
		$this->colors['gcid-aaaaaaaaaa']['color'] = '#00ff00';

		$denied = $all['nexo-mcp/undo-divi-design-change']['execute_callback']( array() );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'nexo_mcp_divi_design_changed_since', $denied->get_error_code() );
		$this->assertSame( '#00ff00', $this->colors['gcid-aaaaaaaaaa']['color'] );

		$ok = $all['nexo-mcp/undo-divi-design-change']['execute_callback']( array( 'force' => true ) );
		$this->assertIsArray( $ok );
		$this->assertSame( '#111111', $this->colors['gcid-aaaaaaaaaa']['color'] );
	}

	public function test_cada_herramienta_escribe_con_su_operacion(): void {
		$all = $this->abilities();

		$color = $all['nexo-mcp/set-divi-global-color']['execute_callback']( $this->inputs()['set-divi-global-color'] );
		$this->assertSame( 'gcid-abcde12345', $color['id'] );
		$this->assertSame( '#ff0000', $this->colors['gcid-abcde12345']['color'] );

		$var = $all['nexo-mcp/set-divi-variable']['execute_callback']( $this->inputs()['set-divi-variable'] );
		$this->assertSame( 'gvid-abcde12345', $var['id'] );
		$this->assertSame( '8px', $this->variables['numbers']['gvid-abcde12345']['value'] );

		$archived = $all['nexo-mcp/archive-divi-variable']['execute_callback']( $this->inputs()['archive-divi-variable'] );
		$this->assertSame( 'archived', $archived['status'] );

		$typo = $all['nexo-mcp/set-divi-typography']['execute_callback']( $this->inputs()['set-divi-typography'] );
		$this->assertSame( 'Lato', $this->options['body_font'] );
		$this->assertArrayHasKey( 'changed', $typo );

		$this->usage['gcid-abcde12345'] = array( 9 );
		$in_use                         = $all['nexo-mcp/delete-divi-global-color']['execute_callback']( array( 'id' => 'gcid-abcde12345' ) );
		$this->assertSame( 'nexo_mcp_divi_color_in_use', $in_use->get_error_code() );
		$this->assertArrayHasKey( 'gcid-abcde12345', $this->colors );

		$forced = $all['nexo-mcp/delete-divi-global-color']['execute_callback']( array( 'id' => 'gcid-abcde12345', 'force' => true ) );
		$this->assertSame( array( 9 ), $forced['posts_affected'] );
		$this->assertArrayNotHasKey( 'gcid-abcde12345', $this->colors );

		$this->assertCount( 5, get_option( DiviDesignStore::HISTORY_OPTION ) );
	}

	public function test_escribir_pide_interruptor_capacidad_y_no_estar_en_solo_revisar(): void {
		foreach ( self::NAMES as $name ) {
			$this->assertTrue( $this->permission( $name ), $name );
			$this->assertNotTrue( $this->permission( $name, array( 'divi_design_tools_enabled' => false ) ), $name . ' interruptor' );
			$this->assertNotTrue( $this->permission( $name, array( 'read_only' => true ) ), $name . ' solo revisar' );

			\WP_Test_State::$caps[ Capabilities::DIVI_DESIGN ] = false;
			$this->assertNotTrue( $this->permission( $name ), $name . ' capacidad' );
			\WP_Test_State::$caps[ Capabilities::DIVI_DESIGN ] = true;

			\WP_Test_State::$caps['manage_options'] = false;
			$this->assertNotTrue( $this->permission( $name ), $name . ' manage_options' );
			\WP_Test_State::$caps['manage_options'] = true;
		}
	}

	public function test_borrar_con_force_exige_ademas_el_permiso_destructivo_y_sin_force_no(): void {
		$plain = array( 'id' => 'gcid-aaaaaaaaaa' );
		$force = array( 'id' => 'gcid-aaaaaaaaaa', 'force' => true );

		$this->assertTrue( $this->permission( 'delete-divi-global-color', array( 'destructive_enabled' => false ), $plain ) );
		$this->assertTrue( $this->permission( 'delete-divi-global-color', array(), $force ) );
		$this->assertNotTrue( $this->permission( 'delete-divi-global-color', array( 'destructive_enabled' => false ), $force ) );

		\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
		$this->assertNotTrue( $this->permission( 'delete-divi-global-color', array(), $force ) );
		$this->assertTrue( $this->permission( 'delete-divi-global-color', array(), $plain ) );
	}

	public function test_deshacer_con_force_exige_ademas_el_permiso_destructivo_y_sin_force_no(): void {
		$plain = array();
		$force = array( 'force' => true );

		$this->assertTrue( $this->permission( 'undo-divi-design-change', array( 'destructive_enabled' => false ), $plain ) );
		$this->assertTrue( $this->permission( 'undo-divi-design-change', array( 'destructive_enabled' => false ), array( 'force' => false ) ) );
		$this->assertTrue( $this->permission( 'undo-divi-design-change', array(), $force ) );
		$this->assertNotTrue( $this->permission( 'undo-divi-design-change', array( 'destructive_enabled' => false ), $force ) );
		$this->assertNotTrue( $this->permission( 'undo-divi-design-change', array( 'divi_design_tools_enabled' => false ), $force ) );

		\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
		$this->assertNotTrue( $this->permission( 'undo-divi-design-change', array(), $force ) );
		$this->assertTrue( $this->permission( 'undo-divi-design-change', array(), $plain ) );
		$this->assertStringContainsString( 'mcp:destructive', $this->abilities()['nexo-mcp/undo-divi-design-change']['description'] );
	}

	public function test_deshacer_y_tipografia_aceptan_una_entrada_ausente_sin_error_de_tipo(): void {
		$all = $this->abilities();

		foreach ( array( 'undo-divi-design-change', 'set-divi-typography' ) as $name ) {
			$this->assertTrue( $all[ 'nexo-mcp/' . $name ]['permission_callback']( null ), $name . ' permiso' );
		}

		$undo = $all['nexo-mcp/undo-divi-design-change']['execute_callback']( null );
		$this->assertInstanceOf( \WP_Error::class, $undo );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_undo', $undo->get_error_code() );

		$typo = $all['nexo-mcp/set-divi-typography']['execute_callback']( null );
		$this->assertInstanceOf( \WP_Error::class, $typo );
		$this->assertSame( 'nexo_mcp_divi_design_nothing_to_change', $typo->get_error_code() );

		$this->assertSame( array( 'accent_color' => '#2ea3f2' ), $this->options );
		$this->assertSame( array(), get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}

	public function test_scopes_de_un_token(): void {
		$all = $this->abilities();

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:builders', 'mcp:design_system', 'mcp:destructive' ) );
		foreach ( self::NAMES as $name ) {
			$this->assertNotTrue( $all[ 'nexo-mcp/' . $name ]['permission_callback']( $this->inputs()[ $name ] ), $name . ' sin el scope' );
		}

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:divi_design' ) );
		foreach ( self::NAMES as $name ) {
			$this->assertTrue( $all[ 'nexo-mcp/' . $name ]['permission_callback']( $this->inputs()[ $name ] ), $name . ' con el scope' );
		}

		$this->assertNotTrue( $all['nexo-mcp/delete-divi-global-color']['permission_callback']( array( 'id' => 'gcid-aaaaaaaaaa', 'force' => true ) ), 'force sin mcp:destructive' );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:divi_design', 'mcp:destructive' ) );
		$this->assertTrue( $all['nexo-mcp/delete-divi-global-color']['permission_callback']( array( 'id' => 'gcid-aaaaaaaaaa', 'force' => true ) ) );

		$this->connect_with_scopes( array( 'mcp:connect', 'mcp:write', 'mcp:divi_design' ) );
		$this->assertNotTrue( $all['nexo-mcp/get-divi-design-system']['permission_callback']( array() ), 'el scope no concede lectura' );
	}

	public function test_sin_divi_5_responden_el_error_y_no_escriben(): void {
		$this->active = false;
		$all          = $this->abilities();
		$before       = array( $this->colors, $this->variables, $this->options );

		foreach ( self::NAMES as $name ) {
			$result = $all[ 'nexo-mcp/' . $name ]['execute_callback']( $this->inputs()[ $name ] );
			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code(), $name );
		}

		$this->assertSame( $before, array( $this->colors, $this->variables, $this->options ) );
		$this->assertSame( array(), get_option( DiviDesignStore::HISTORY_OPTION, array() ) );
	}
}
