<?php
/**
 * Herramientas de escritura del sistema de diseño: registro, esquemas, permisos, confirmación y ejecución.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\Authorization\IrreversibleActions;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\DesignClassBackend;
use NexoDev\NexoMCP\Builders\DesignClassOperations;
use NexoDev\NexoMCP\Builders\DesignKitOperations;
use NexoDev\NexoMCP\Builders\DesignKitStore;
use NexoDev\NexoMCP\Builders\DesignSystemStore;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;
use NexoDev\NexoMCP\Builders\DesignVariableOperations;
use NexoDev\NexoMCP\Builders\DesignVariableStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

/**
 * Mundo en memoria para las herramientas: almacén del escritor, clases, variables y Kit.
 */
final class ToolsWorld implements DesignSystemStore, DesignClassBackend, DesignVariableStore, DesignKitStore {

	/** @var array<string, array<string, mixed>> */
	public array $items = array( 'g-aaaaaaa' => array( 'id' => 'g-aaaaaaa', 'type' => 'class', 'label' => 'base', 'variants' => array() ) );

	/** @var array<int, string> */
	public array $order = array( 'g-aaaaaaa' );

	/** @var array<string, array<int, array{id: int, title: string}>> */
	public array $usage = array();

	/** @var array<string, array{type: string, label: string, value: string, deleted: bool}> */
	public array $records = array( 'e-gv-red' => array( 'type' => 'global-color-variable', 'label' => 'Rojo', 'value' => '#ff0000', 'deleted' => false ) );

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $lists = array(
		'system_colors'     => array( array( '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ) ),
		'custom_colors'     => array( array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ) ),
		'system_typography' => array(),
		'custom_typography' => array(),
	);

	public int $kit = 77;

	public function available(): bool {
		return true;
	}

	public function atomic(): bool {
		return true;
	}

	public function kit_id(): int {
		return $this->kit;
	}

	public function snapshot( string $kind ): array {
		if ( 'classes' === $kind ) {
			return array( 'items' => $this->items, 'order' => $this->order );
		}

		return 'variables' === $kind ? array( 'data' => $this->records, 'watermark' => 1 ) : $this->lists;
	}

	public function restore( string $kind, array $state ): void {
		if ( 'classes' === $kind ) {
			$this->items = $state['items'];
			$this->order = $state['order'];
		} elseif ( 'variables' === $kind ) {
			$this->records = $state['data'];
		} else {
			$this->lists = $state;
		}
	}

	public function clear_cache(): void {}

	public function style_props(): array {
		return array( 'color', 'gap' );
	}

	public function parse_style( array $style ) {
		return $style;
	}

	public function read(): array {
		return array( 'items' => $this->items, 'order' => $this->order );
	}

	public function used_in( string $id ): array {
		return $this->usage[ $id ] ?? array();
	}

	public function write( array $touched, array $changes, array $order ): void {
		foreach ( $touched as $id => $item ) {
			$this->items[ $id ] = $item;
		}

		foreach ( $changes['deleted'] ?? array() as $id ) {
			unset( $this->items[ $id ] );
		}

		$this->order = $order;
	}

	public function variables(): array {
		return $this->records;
	}

	public function create( string $type, string $label, string $value ): array {
		$id                   = 'e-gv-new1';
		$this->records[ $id ] = array( 'type' => $type, 'label' => $label, 'value' => $value, 'deleted' => false );

		return array( 'id' => $id, 'type' => $type, 'label' => $label, 'value' => $value );
	}

	public function update( string $id, array $changes ): array {
		$this->records[ $id ] = array_merge( $this->records[ $id ], $changes );

		return array(
			'id'    => $id,
			'type'  => $this->records[ $id ]['type'],
			'label' => $this->records[ $id ]['label'],
			'value' => $this->records[ $id ]['value'],
		);
	}

	public function delete( string $id ): void {
		$this->records[ $id ]['deleted'] = true;
	}

	public function lists(): array {
		return $this->lists;
	}

	public function save( array $lists ): void {
		foreach ( $lists as $key => $list ) {
			$this->lists[ $key ] = $list;
		}
	}
}

final class DesignSystemToolsTest extends NexoTestCase {

	private const NAMES = array(
		'create-global-class',
		'update-global-class',
		'delete-global-class',
		'create-design-variable',
		'update-design-variable',
		'delete-design-variable',
		'update-kit-globals',
	);

	private ToolsWorld $world;

	protected function setUp(): void {
		parent::setUp();
		$this->world             = new ToolsWorld();
		\WP_Test_State::$user_id = 5;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes de la prueba.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function abilities( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'design_system_tools_enabled' => true, 'destructive_enabled' => true ), $settings ) );
		$writer                    = new DesignSystemWriter( $this->world, static function (): void {} );
		$ids                       = array( 'g-bbbbbbb' );
		$gateway                   = new BuilderGateway(
			null,
			null,
			$settings,
			$writer,
			new DesignClassOperations( $writer, $this->world, static function () use ( &$ids ) { return array_shift( $ids ) ?? 'g-ccccccc'; } ),
			new DesignVariableOperations( $writer, $this->world ),
			new DesignKitOperations( $writer, $this->world, static fn() => 'abcdef0' )
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
			$gateway
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes de la prueba.
	 *
	 * @return array<string, mixed>
	 */
	private function ability( string $name, array $settings = array() ): array {
		return $this->abilities( $settings )[ 'nexo-mcp/' . $name ];
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function tool_names(): array {
		return array_combine( self::NAMES, array_map( static fn( string $name ): array => array( $name ), self::NAMES ) );
	}

	/**
	 * Entrada válida mínima de cada herramienta, para la prueba de permisos.
	 *
	 * @return array<string, mixed>
	 */
	private function input( string $name ): array {
		return array(
			'create-global-class'    => array( 'label' => 'nueva', 'variants' => array() ),
			'update-global-class'    => array( 'id' => 'g-aaaaaaa', 'label' => 'otra' ),
			'delete-global-class'    => array( 'id' => 'g-aaaaaaa', 'confirmation' => 'DELETE' ),
			'create-design-variable' => array( 'type' => 'global-color-variable', 'label' => 'azul', 'value' => '#0000ff' ),
			'update-design-variable' => array( 'id' => 'e-gv-red', 'value' => '#cc0000' ),
			'delete-design-variable' => array( 'id' => 'e-gv-red', 'confirmation' => 'DELETE' ),
			'update-kit-globals'     => array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#000000' ) ) ),
		)[ $name ];
	}

	// ------------------------------------------------------- Registro y esquemas

	public function test_las_siete_herramientas_estan_en_la_lista_y_registradas(): void {
		$abilities = $this->abilities();

		foreach ( self::NAMES as $name ) {
			$this->assertContains( 'nexo-mcp/' . $name, ExtendedAbilityRegistrar::ABILITIES, $name );
			$this->assertArrayHasKey( 'nexo-mcp/' . $name, $abilities, $name );
		}
	}

	/**
	 * @dataProvider tool_names
	 */
	public function test_cada_esquema_cierra_las_propiedades_y_la_descripcion_avisa(string $name ): void {
		$ability = $this->ability( $name );

		$this->assertSame( 'object', $ability['input_schema']['type'] );
		$this->assertFalse( $ability['input_schema']['additionalProperties'] );
		$this->assertFalse( $ability['meta']['annotations']['readonly'] );

		foreach ( array( 'TODO EL SITIO EN VIVO', 'nexo-mcp-get-design-system', 'nexo-mcp-undo-design-system-change', 'nexo_mcp_design_invalid' ) as $needle ) {
			if ( 'nexo_mcp_design_invalid' === $needle && str_starts_with( $name, 'delete-' ) ) {
				continue;
			}

			$this->assertStringContainsString( $needle, $ability['description'], $name . ': ' . $needle );
		}
	}

	public function test_el_esquema_de_crear_y_modificar_clases(): void {
		$create = $this->ability( 'create-global-class' )['input_schema'];
		$update = $this->ability( 'update-global-class' )['input_schema'];

		$this->assertSame( array( 'label', 'variants' ), $create['required'] );
		$this->assertSame( 50, $create['properties']['label']['maxLength'] );
		$this->assertSame( 'array', $create['properties']['variants']['type'] );
		$this->assertFalse( $create['properties']['variants']['items']['additionalProperties'] );
		$this->assertContains( 'tablet', $create['properties']['variants']['items']['properties']['meta']['properties']['breakpoint']['enum'] );
		$this->assertContains( 'hover', $create['properties']['variants']['items']['properties']['meta']['properties']['state']['enum'] );
		$this->assertSame( array( 'id' ), $update['required'] );
		$this->assertSame( array( 'id', 'label', 'variants' ), array_keys( $update['properties'] ) );
		$this->assertStringContainsString( 'nexo_mcp_design_label_taken', $this->ability( 'create-global-class' )['description'] );
		$this->assertStringContainsString( 'REEMPLAZA', $this->ability( 'update-global-class' )['description'] );
	}

	public function test_el_esquema_de_variables(): void {
		$create = $this->ability( 'create-design-variable' )['input_schema'];
		$update = $this->ability( 'update-design-variable' )['input_schema'];

		$this->assertSame( array( 'type', 'label', 'value' ), $create['required'] );
		$this->assertSame(
			array( 'global-color-variable', 'global-font-variable', 'global-size-variable', 'global-custom-size-variable' ),
			$create['properties']['type']['enum']
		);
		$this->assertSame( 512, $create['properties']['value']['maxLength'] );
		$this->assertSame( array( 'id' ), $update['required'] );
		$this->assertSame( array( 'id', 'type', 'label', 'value' ), array_keys( $update['properties'] ) );
	}

	public function test_el_esquema_de_los_borrados_pide_confirmacion_y_describe_force(): void {
		foreach ( array( 'delete-global-class', 'delete-design-variable' ) as $name ) {
			$ability = $this->ability( $name );
			$schema  = $ability['input_schema'];

			$this->assertTrue( $ability['meta']['annotations']['destructive'], $name );
			$this->assertSame( array( 'id', 'confirmation' ), $schema['required'], $name );
			$this->assertSame( array( 'DELETE' ), $schema['properties']['confirmation']['enum'], $name );
			$this->assertSame( 'boolean', $schema['properties']['force']['type'], $name );
			$this->assertStringContainsString( 'nexo_mcp_design_in_use', $ability['description'], $name );
			$this->assertStringContainsString( 'force: true', $ability['description'], $name );
			$this->assertStringContainsString( 'pages_affected', $ability['description'], $name );
			$this->assertStringContainsString( 'permiso destructivo', $ability['description'], $name );
		}
	}

	public function test_el_esquema_del_kit_y_su_anotacion(): void {
		$ability = $this->ability( 'update-kit-globals' );
		$schema  = $ability['input_schema'];

		$this->assertTrue( $ability['meta']['annotations']['destructive'] );
		$this->assertArrayNotHasKey( 'required', $schema );
		$this->assertSame( array( 'colors', 'typography', 'delete', 'confirmation' ), array_keys( $schema['properties'] ) );
		$this->assertFalse( $schema['properties']['colors']['items']['additionalProperties'] );
		$this->assertSame( array( 'id', 'title', 'color' ), array_keys( $schema['properties']['colors']['items']['properties'] ) );

		$typography = $schema['properties']['typography']['items']['properties'];
		$this->assertArrayHasKey( 'typography_font_family', $typography );
		$this->assertArrayHasKey( 'typography_font_size_mobile', $typography );
		$this->assertSame( array( 'px', 'em', 'rem', 'vw', 'custom' ), $typography['typography_font_size']['properties']['unit']['enum'] );
		$this->assertContains( 'bold', $typography['typography_font_weight']['enum'] );
		$this->assertStringContainsString( 'nexo_mcp_design_system_item', $ability['description'] );
		$this->assertStringContainsString( '"confirmation": "DELETE"', $ability['description'] );
	}

	public function test_crear_y_modificar_no_son_destructivas(): void {
		foreach ( array( 'create-global-class', 'update-global-class', 'create-design-variable', 'update-design-variable' ) as $name ) {
			$this->assertFalse( $this->ability( $name )['meta']['annotations']['destructive'], $name );
		}
	}

	// ------------------------------------------------------------- Permisos

	/**
	 * @dataProvider tool_names
	 */
	public function test_con_todo_concedido_se_permite(string $name): void {
		$this->assertTrue( $this->ability( $name )['permission_callback']( $this->input( $name ) ) );
	}

	/**
	 * @dataProvider tool_names
	 */
	public function test_sin_el_interruptor_del_sistema_de_diseno_se_deniega(string $name): void {
		$ability = $this->ability( $name, array( 'design_system_tools_enabled' => false ) );

		$this->assertNotTrue( $ability['permission_callback']( $this->input( $name ) ) );
	}

	/**
	 * @dataProvider tool_names
	 */
	public function test_en_solo_revisar_se_deniega(string $name): void {
		$ability = $this->ability( $name, array( 'read_only' => true ) );

		$this->assertNotTrue( $ability['permission_callback']( $this->input( $name ) ) );
	}

	/**
	 * @dataProvider tool_names
	 */
	public function test_sin_la_capacidad_del_sistema_de_diseno_o_manage_options_se_deniega(string $name): void {
		$input = $this->input( $name );

		\WP_Test_State::$caps[ Capabilities::DESIGN_SYSTEM ] = false;
		$this->assertNotTrue( $this->ability( $name )['permission_callback']( $input ) );

		\WP_Test_State::$caps[ Capabilities::DESIGN_SYSTEM ] = true;
		\WP_Test_State::$caps['manage_options']              = false;
		$this->assertNotTrue( $this->ability( $name )['permission_callback']( $input ) );
	}

	public function test_los_borrados_exigen_ademas_el_permiso_destructivo(): void {
		foreach ( array( 'delete-global-class', 'delete-design-variable' ) as $name ) {
			$input = $this->input( $name );

			$this->assertNotTrue( $this->ability( $name, array( 'destructive_enabled' => false ) )['permission_callback']( $input ), $name );

			\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
			$this->assertNotTrue( $this->ability( $name )['permission_callback']( $input ), $name );

			\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = true;
			$this->assertTrue( $this->ability( $name )['permission_callback']( $input ), $name );
		}
	}

	public function test_crear_y_modificar_no_piden_el_permiso_destructivo(): void {
		foreach ( array( 'create-global-class', 'update-global-class', 'create-design-variable', 'update-design-variable' ) as $name ) {
			$this->assertTrue( $this->ability( $name, array( 'destructive_enabled' => false ) )['permission_callback']( $this->input( $name ) ), $name );
		}
	}

	public function test_el_kit_pide_el_permiso_destructivo_solo_si_borra(): void {
		$without = array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#000000' ) ) );
		$empty   = array( 'colors' => array(), 'delete' => array() );
		$with    = array( 'delete' => array( 'a1b2c3d' ) );
		$ability = $this->ability( 'update-kit-globals', array( 'destructive_enabled' => false ) );

		$this->assertTrue( $ability['permission_callback']( $without ) );
		$this->assertTrue( $ability['permission_callback']( $empty ) );
		$this->assertNotTrue( $ability['permission_callback']( $with ) );

		\WP_Test_State::$caps[ Capabilities::DESTRUCTIVE ] = false;
		$this->assertNotTrue( $this->ability( 'update-kit-globals' )['permission_callback']( $with ) );
		$this->assertTrue( $this->ability( 'update-kit-globals' )['permission_callback']( $without ) );
	}

	// -------------------------------------------------------- Confirmación

	public function test_borrar_sin_la_palabra_no_ejecuta_y_no_deja_respaldo(): void {
		foreach ( array( 'delete-global-class', 'delete-design-variable' ) as $name ) {
			$input = $this->input( $name );
			unset( $input['confirmation'] );

			$result = $this->ability( $name )['execute_callback']( $input );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( IrreversibleActions::CODE, $result->get_error_code(), $name );
		}

		$this->assertArrayHasKey( 'g-aaaaaaa', $this->world->items );
		$this->assertFalse( $this->world->records['e-gv-red']['deleted'] );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
	}

	public function test_el_kit_pide_la_palabra_solo_si_borra(): void {
		$ability = $this->ability( 'update-kit-globals' );

		$denied = $ability['execute_callback']( array( 'delete' => array( 'a1b2c3d' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( IrreversibleActions::CODE, $denied->get_error_code() );
		$this->assertCount( 1, $this->world->lists['custom_colors'] );

		$this->assertIsArray( $ability['execute_callback']( array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#000000' ) ) ) ) );
		$this->assertIsArray( $ability['execute_callback']( array( 'delete' => array( 'a1b2c3d' ), 'confirmation' => 'DELETE' ) ) );
		$this->assertSame( array(), $this->world->lists['custom_colors'] );
	}

	// ------------------------------------------------------------ Ejecución

	public function test_clases_crear_modificar_y_borrar_con_su_respaldo(): void {
		$create = $this->ability( 'create-global-class' )['execute_callback']( array( 'label' => 'nueva', 'variants' => array() ) );

		$this->assertSame( array( 'id' => 'g-bbbbbbb', 'label' => 'nueva', 'undo_available' => true ), $create );
		$this->assertSame( array( 'g-aaaaaaa', 'g-bbbbbbb' ), $this->world->order );
		$this->assertSame( 'create-class', \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ]['operation'] );

		$update = $this->ability( 'update-global-class' )['execute_callback']( array( 'id' => 'g-bbbbbbb', 'label' => 'renombrada' ) );

		$this->assertSame( 'renombrada', $update['label'] );
		$this->assertSame( 'renombrada', $this->world->items['g-bbbbbbb']['label'] );

		$delete = $this->ability( 'delete-global-class' )['execute_callback']( array( 'id' => 'g-bbbbbbb', 'confirmation' => 'DELETE' ) );

		$this->assertSame( array( 'deleted' => 'g-bbbbbbb', 'pages_affected' => array(), 'undo_available' => true ), $delete );
		$this->assertArrayNotHasKey( 'g-bbbbbbb', $this->world->items );
	}

	public function test_borrar_una_clase_en_uso_se_rechaza_sin_force_y_con_force_lista_las_paginas(): void {
		$this->world->usage['g-aaaaaaa'] = array( array( 'id' => 12, 'title' => 'Inicio' ) );
		$delete                          = $this->ability( 'delete-global-class' )['execute_callback'];

		$refused = $delete( array( 'id' => 'g-aaaaaaa', 'confirmation' => 'DELETE' ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'nexo_mcp_design_in_use', $refused->get_error_code() );
		$this->assertSame( array( 'id' => 'g-aaaaaaa', 'used_in' => array( array( 'id' => 12, 'title' => 'Inicio' ) ) ), $refused->get_error_data() );
		$this->assertArrayHasKey( 'g-aaaaaaa', $this->world->items );

		$forced = $delete( array( 'id' => 'g-aaaaaaa', 'force' => true, 'confirmation' => 'DELETE' ) );

		$this->assertSame( array( array( 'id' => 12, 'title' => 'Inicio' ) ), $forced['pages_affected'] );
		$this->assertArrayNotHasKey( 'g-aaaaaaa', $this->world->items );
	}

	public function test_variables_crear_modificar_y_borrar(): void {
		$create = $this->ability( 'create-design-variable' )['execute_callback']( $this->input( 'create-design-variable' ) );

		$this->assertSame(
			array( 'id' => 'e-gv-new1', 'type' => 'global-color-variable', 'label' => 'azul', 'value' => '#0000ff', 'undo_available' => true ),
			$create
		);

		$update = $this->ability( 'update-design-variable' )['execute_callback']( array( 'id' => 'e-gv-new1', 'value' => '#00f' ) );

		$this->assertSame( '#00f', $update['value'] );

		$this->world->usage['e-gv-new1'] = array();
		$delete                          = $this->ability( 'delete-design-variable' )['execute_callback']( array( 'id' => 'e-gv-new1', 'confirmation' => 'DELETE' ) );

		$this->assertSame( array( 'deleted' => 'e-gv-new1', 'pages_affected' => array(), 'undo_available' => true ), $delete );
		$this->assertTrue( $this->world->records['e-gv-new1']['deleted'] );
	}

	public function test_una_validacion_fallida_responde_el_codigo_unico_y_no_escribe(): void {
		$cases = array(
			array( 'create-global-class', array( 'label' => '1mala', 'variants' => array() ), 'label' ),
			array( 'create-design-variable', array( 'type' => 'global-color-variable', 'label' => 'x', 'value' => 'rojo' ), 'value' ),
			array( 'update-kit-globals', array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => 'rojo' ) ) ), 'colors[0].color' ),
		);

		foreach ( $cases as [ $name, $input, $path ] ) {
			$result = $this->ability( $name )['execute_callback']( $input );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'nexo_mcp_design_invalid', $result->get_error_code(), $name );
			$this->assertSame( array( 'path' => $path ), $result->get_error_data(), $name );
			$this->assertStringStartsWith( $path . ': ', $result->get_error_message(), $name );
		}

		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
	}

	public function test_el_kit_modifica_y_deshacer_devuelve_el_estado_exacto(): void {
		$before = $this->world->lists;

		$result = $this->ability( 'update-kit-globals' )['execute_callback']( array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#000000' ), array( 'title' => 'Nuevo', 'color' => '#ffffff' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['undo_available'] );
		$this->assertNotSame( $before, $this->world->lists );

		$undo = $this->ability( 'undo-design-system-change' )['execute_callback']( array( 'confirmation' => 'UNDO' ) );

		$this->assertIsArray( $undo );
		$this->assertSame( 'kit', $undo['kind'] );
		$this->assertSame( $before, $this->world->lists );
	}

	// ------------------------------------------------ Verificación independiente (T6)

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function injection_labels(): array {
		return array(
			'cierra la regla' => array( 'x:red;}body{display:none;--y' ),
			'llaves'          => array( 'a{}' ),
			'punto y coma'    => array( 'x;y' ),
			'angulares'       => array( 'a<b>' ),
			'guion doble'     => array( '--x' ),
			'dos puntos'      => array( 'a:b' ),
			'no ascii'        => array( 'ñandú' ),
		);
	}

	/**
	 * @dataProvider injection_labels
	 */
	public function test_variable_label_no_inyecta_css( string $label ): void {
		$before = $this->world->records;

		$create = $this->ability( 'create-design-variable' )['execute_callback']( array( 'type' => 'global-color-variable', 'label' => $label, 'value' => '#fff' ) );
		$update = $this->ability( 'update-design-variable' )['execute_callback']( array( 'id' => 'e-gv-red', 'label' => $label ) );

		foreach ( array( $create, $update ) as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_design_invalid', $result->get_error_code() );
			$this->assertSame( array( 'path' => 'label' ), $result->get_error_data() );
			$this->assertStringStartsWith( 'label: ', $result->get_error_message() );
		}

		$this->assertSame( $before, $this->world->records );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
	}

	public function test_variable_label_seguro_se_acepta(): void {
		foreach ( array( 'verde', 'Verde-1', 'a_b', '9x', str_repeat( 'a', 50 ) ) as $label ) {
			$result = $this->ability( 'create-design-variable' )['execute_callback']( array( 'type' => 'global-color-variable', 'label' => $label, 'value' => '#fff' ) );

			$this->assertIsArray( $result, $label );
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function bad_custom_sizes(): array {
		return array(
			'comentario abierto' => array( 'calc(1rem /* x' ),
			'parentesis'         => array( 'calc(1px' ),
			'comilla'            => array( "a'b" ),
			'url'                => array( 'url(http://e/x)' ),
			'important'          => array( '1px !important' ),
			'var'                => array( 'var(--x)' ),
		);
	}

	/**
	 * @dataProvider bad_custom_sizes
	 */
	public function test_kit_custom_size_usa_la_regla_css( string $size ): void {
		$before = $this->world->lists;

		$result = $this->ability( 'update-kit-globals' )['execute_callback']( array( 'typography' => array( array( 'title' => 'Libre', 'typography_font_size' => array( 'unit' => 'custom', 'size' => $size ) ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_invalid', $result->get_error_code() );
		$this->assertSame( $before, $this->world->lists );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options );
	}

	public function test_kit_custom_size_valido_y_familia_con_css_se_distinguen(): void {
		$ok = $this->ability( 'update-kit-globals' )['execute_callback']( array( 'typography' => array( array( 'title' => 'Libre', 'typography_font_size' => array( 'unit' => 'custom', 'size' => 'clamp(1rem, 2vw, 3rem)' ) ) ) ) );
		$this->assertIsArray( $ok );

		$bad = $this->ability( 'update-kit-globals' )['execute_callback']( array( 'typography' => array( array( 'title' => 'Otra', 'typography_font_family' => 'Lora /* x' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_design_invalid', $bad->get_error_code() );
	}

	public function test_kit_delete_cero_pide_destructivo(): void {
		$ability = $this->ability( 'update-kit-globals', array( 'destructive_enabled' => false ) );

		foreach ( array( '0', 0, array( '0' ) ) as $delete ) {
			$this->assertNotTrue( $ability['permission_callback']( array( 'delete' => $delete ) ), var_export( $delete, true ) );
		}

		$this->assertTrue( $ability['permission_callback']( array( 'delete' => array() ) ) );
		$this->assertTrue( $ability['permission_callback']( array( 'delete' => '' ) ) );
	}

	/**
	 * Cada propiedad de los esquemas nuevos declara `type` (WordPress avisa y no valida si falta).
	 */
	public function test_todo_el_esquema_declara_type(): void {
		$missing = array();
		$walk    = function ( array $schema, string $path ) use ( &$walk, &$missing ): void {
			if ( ! isset( $schema['type'] ) ) {
				$missing[] = $path;
			}

			foreach ( $schema['properties'] ?? array() as $name => $child ) {
				$walk( $child, $path . '.' . $name );
			}

			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				$walk( $schema['items'], $path . '[]' );
			}
		};

		foreach ( self::NAMES as $name ) {
			$schema = $this->ability( $name )['input_schema'];
			$walk( $schema, $name );
		}

		$walk( $this->ability( 'undo-design-system-change' )['input_schema'], 'undo-design-system-change' );

		$this->assertSame( array(), $missing );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function all_inputs(): array {
		return array(
			'create-global-class'       => $this->input( 'create-global-class' ),
			'update-global-class'       => $this->input( 'update-global-class' ),
			'delete-global-class'       => $this->input( 'delete-global-class' ),
			'create-design-variable'    => $this->input( 'create-design-variable' ),
			'update-design-variable'    => $this->input( 'update-design-variable' ),
			'delete-design-variable'    => $this->input( 'delete-design-variable' ),
			'update-kit-globals'        => $this->input( 'update-kit-globals' ),
			'undo-design-system-change' => array( 'confirmation' => 'UNDO' ),
		);
	}

	public function test_oauth_sin_el_scope_del_sistema_de_diseno_deniega_las_ocho(): void {
		$this->bearer_context( true, array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:destructive' ) );

		foreach ( $this->all_inputs() as $name => $input ) {
			$this->assertNotTrue( $this->ability( $name )['permission_callback']( $input ), $name );
		}
	}

	public function test_oauth_sin_el_scope_destructivo_solo_deniega_los_borrados(): void {
		$this->bearer_context( true, array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:design_system' ) );
		$denied = array( 'delete-global-class', 'delete-design-variable' );

		foreach ( $this->all_inputs() as $name => $input ) {
			$result = $this->ability( $name )['permission_callback']( $input );

			if ( in_array( $name, $denied, true ) ) {
				$this->assertNotTrue( $result, $name );
			} else {
				$this->assertTrue( $result, $name );
			}
		}

		$kit = $this->ability( 'update-kit-globals' )['permission_callback'];
		$this->assertNotTrue( $kit( array( 'delete' => array( 'a1b2c3d' ) ) ) );
		$this->assertTrue( $kit( array( 'colors' => array() ) ) );
	}

	public function test_oauth_con_todos_los_scopes_permite_las_ocho(): void {
		$this->bearer_context( true, array( 'mcp:connect', 'mcp:read', 'mcp:write', 'mcp:design_system', 'mcp:destructive' ) );

		foreach ( $this->all_inputs() as $name => $input ) {
			$this->assertTrue( $this->ability( $name )['permission_callback']( $input ), $name );
		}
	}
}
