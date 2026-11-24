<?php
/**
 * Escrituras de los presets de Divi: crear, editar, predeterminado y borrar, con validación de attrs (Divi inyectado).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\Builders\DiviPresetOperations;
use NexoDev\NexoMCP\Builders\DiviPresetStore;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;

final class DiviPresetOperationsTest extends NexoTestCase {

	private const NOW = 1800000000;

	private bool $active = true;

	/** @var array<string, mixed>|null */
	private ?array $presets = null;

	/** @var array<int, string> Orden de lo ocurrido. */
	private array $log = array();

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	/** @var array<string, array<int, int>> Uso simulado: "kind:id" => ids de entradas. */
	private array $usage = array();

	/** @var array<int, string> Ids que va dando el generador de uuid. */
	private array $uuids = array( 'uuid-1', 'uuid-2', 'uuid-3' );

	private int $flushes = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->active            = true;
		$this->log               = array();
		$this->audit             = array();
		$this->usage             = array();
		$this->uuids             = array( 'uuid-1', 'uuid-2', 'uuid-3' );
		$this->flushes           = 0;
		$this->presets           = $this->sample();
		\WP_Test_State::$user_id = 7;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function bg( string $color ): array {
		return array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => $color ) ) ) ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample(): array {
		return array(
			'module' => array(
				'divi/text' => array(
					'default' => 'p-text-1',
					'items'   => array(
						'p-text-1' => array(
							'type'         => 'module',
							'id'           => 'p-text-1',
							'name'         => 'Texto base',
							'moduleName'   => 'divi/text',
							'version'      => '5.19.1',
							'created'      => 1700000000,
							'updated'      => 1700000100,
							'authorId'     => 1,
							'attrs'        => $this->bg( '#ff0000' ),
							'renderAttrs'  => array( 'render' => 'keep' ),
							'styleAttrs'   => array( 'style' => 'keep' ),
							'groupPresets' => array( 'module.decoration.background' => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ),
							'priority'     => 20,
							'order'        => 3,
						),
						'p-text-2' => array(
							'type'       => 'module',
							'id'         => 'p-text-2',
							'name'       => 'Texto dos',
							'moduleName' => 'divi/text',
							'version'    => '5.19.1',
							'created'    => 1700000200,
							'updated'    => 1700000200,
							'authorId'   => 1,
							'attrs'      => $this->bg( '#00ff00' ),
						),
					),
				),
			),
			'group'  => array(
				'divi/background' => array(
					'default' => '',
					'items'   => array(
						'g-bg-1'    => array(
							'type'       => 'group',
							'id'         => 'g-bg-1',
							'name'       => 'Fondo azul',
							'groupName'  => 'divi/background',
							'groupId'    => 'module.decoration.background',
							'moduleName' => 'divi/text',
							'version'    => '5.19.1',
							'created'    => 1700000300,
							'updated'    => 1700000300,
							'authorId'   => 1,
							'attrs'      => $this->bg( '#0000ff' ),
							'priority'   => 5,
						),
						'g-bg-btn1' => array(
							'type'       => 'group',
							'id'         => 'g-bg-btn1',
							'name'       => 'Fondo botón',
							'groupName'  => 'divi/background',
							'groupId'    => 'button.decoration.background',
							'moduleName' => 'divi/button',
							'version'    => '5.19.1',
							'created'    => 1700000400,
							'updated'    => 1700000400,
							'authorId'   => 1,
							'attrs'      => array( 'button' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#00ff00' ) ) ) ) ) ),
						),
					),
				),
			),
		);
	}

	private function design(): DiviDesignStore {
		return new DiviDesignStore(
			fn(): bool => $this->active,
			fn(): array => array(),
			function ( array $all ): void {},
			fn(): array => array(),
			fn( array $all ) => null,
			fn(): array => array(),
			function ( string $key, $value ): void {},
			function (): void {
				$this->log[] = 'flush';
				++$this->flushes;
			},
			function ( string $tool, string $status, $params, $result ): void {
				$this->audit[] = array( $tool, $status, $params, $result );
			},
			function ( callable $work ) {
				$this->log[] = 'lock';

				return $work();
			}
		);
	}

	private function ops(): DiviPresetOperations {
		$store = new DiviPresetStore(
			$this->design(),
			fn(): ?array => $this->presets,
			function ( array $data ): array {
				$this->log[]   = 'save_data';
				$this->presets = $data;

				return $data;
			},
			function (): void {
				$this->presets = null;
			},
			static fn( string $module ): array => 'divi/text' === $module
				? array( 'module.decoration.background' => 'divi/background', 'content.decoration.bodyFont' => 'divi/font-body' )
				: ( 'divi/button' === $module ? array( 'button.decoration.background' => 'divi/background' ) : array() )
		);

		return new DiviPresetOperations(
			$store,
			function ( string $kind, string $id ): array {
				$this->log[] = 'scan:' . $kind . ':' . $id;

				return $this->usage[ $kind . ':' . $id ] ?? array();
			},
			function (): string {
				return (string) array_shift( $this->uuids );
			},
			static fn(): int => self::NOW,
			static fn(): string => '5.19.1',
			new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name, static fn( int $id ) => false )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function history(): array {
		$value = get_option( DiviDesignStore::HISTORY_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return array( 'presets' => $this->presets, 'history' => get_option( DiviDesignStore::HISTORY_OPTION, null ), 'flushes' => $this->flushes, 'audit' => $this->audit );
	}

	private function assert_rejected( $result, string $code, array $before, ?string $path = null, string $case = '' ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result, $case );
		$this->assertSame( $code, $result->get_error_code(), $case );

		if ( null !== $path ) {
			$this->assertSame( array( 'path' => $path ), array_intersect_key( (array) $result->get_error_data(), array( 'path' => 1 ) ), 'data.path ' . $case );
			$this->assertStringStartsWith( $path . ': ', $result->get_error_message(), $case );
		}

		$this->assertSame( $before, $this->snapshot(), 'un rechazo no escribe, no respalda, no vacía el CSS ni audita ' . $case );

		return $result;
	}

	// --- Crear módulo ---------------------------------------------------------------------------

	public function test_crear_un_preset_de_modulo_con_uuid_fechas_y_autor_y_respalda_la_opcion(): void {
		$before = $this->presets;
		$result = $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => ' Hero ', 'attrs' => $this->bg( '#abcdef' ) ) );

		$this->assertSame(
			array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'uuid-1', 'label' => 'Hero', 'created' => true, 'area' => 'presets', 'undo_available' => true ),
			$result
		);
		$this->assertSame(
			array(
				'type'       => 'module',
				'id'         => 'uuid-1',
				'name'       => 'Hero',
				'moduleName' => 'divi/text',
				'version'    => '5.19.1',
				'created'    => self::NOW,
				'updated'    => self::NOW,
				'authorId'   => 7,
				'attrs'      => $this->bg( '#abcdef' ),
			),
			$this->presets['module']['divi/text']['items']['uuid-1']
		);
		$this->assertSame( 'p-text-1', $this->presets['module']['divi/text']['default'], 'crear no cambia el predeterminado' );
		$this->assertSame( $before['module']['divi/text']['items']['p-text-1'], $this->presets['module']['divi/text']['items']['p-text-1'] );
		$this->assertSame( $before['group'], $this->presets['group'] );

		$history = $this->history();
		$this->assertCount( 1, $history );
		$this->assertSame( 'presets', $history[0]['area'] );
		$this->assertSame( $before, $history[0]['previous'] );
		$this->assertSame( $this->presets, $history[0]['written'] );
		$this->assertSame( 'set-divi-preset', $history[0]['tool'] );
		$this->assertSame( array( 'lock', 'save_data', 'flush' ), $this->log );
		$this->assertSame( 'set-divi-preset', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );
	}

	public function test_crear_en_un_modulo_sin_presets_crea_su_coleccion_sin_predeterminado(): void {
		$result = $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/heading', 'label' => 'Título', 'attrs' => $this->bg( '#111111' ) ) );

		$this->assertTrue( $result['created'] );
		$this->assertSame( '', $this->presets['module']['divi/heading']['default'] );
		$this->assertSame( 'divi/heading', $this->presets['module']['divi/heading']['items']['uuid-1']['moduleName'] );
	}

	public function test_crear_con_la_opcion_ausente_la_crea_y_el_respaldo_previo_es_null(): void {
		$this->presets = null;
		$result        = $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'Uno', 'attrs' => $this->bg( '#111111' ) ) );

		$this->assertIsArray( $result );
		$this->assertNull( $this->history()[0]['previous'] );
		$this->assertArrayHasKey( 'uuid-1', $this->presets['module']['divi/text']['items'] );
	}

	public function test_si_el_uuid_ya_existe_se_pide_otro(): void {
		$this->uuids = array( 'p-text-1', 'uuid-2' );
		$result      = $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'Uno', 'attrs' => $this->bg( '#111111' ) ) );

		$this->assertSame( 'uuid-2', $result['id'] );
		$this->assertSame( 'Texto base', $this->presets['module']['divi/text']['items']['p-text-1']['name'] );
	}

	// --- Actualizar -----------------------------------------------------------------------------

	public function test_actualizar_cambia_nombre_y_attrs_y_conserva_todo_lo_demas_del_item(): void {
		$before = $this->presets['module']['divi/text']['items']['p-text-1'];
		$result = $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1', 'label' => 'Renombrado', 'attrs' => $this->bg( '#123456' ) ) );

		$this->assertFalse( $result['created'] );
		$this->assertSame( 'p-text-1', $result['id'] );

		$item = $this->presets['module']['divi/text']['items']['p-text-1'];
		$this->assertSame( 'Renombrado', $item['name'] );
		$this->assertSame( $this->bg( '#123456' ), $item['attrs'] );
		$this->assertSame( self::NOW, $item['updated'] );
		$this->assertSame( 7, $item['authorId'] );

		foreach ( array( 'type', 'id', 'moduleName', 'version', 'created', 'renderAttrs', 'styleAttrs', 'groupPresets', 'priority', 'order' ) as $key ) {
			$this->assertSame( $before[ $key ], $item[ $key ], $key );
		}

		$this->assertSame( 'p-text-1', $this->presets['module']['divi/text']['default'] );
		$this->assertSame( $this->sample()['module']['divi/text']['items']['p-text-2'], $this->presets['module']['divi/text']['items']['p-text-2'] );
	}

	public function test_renombrar_sin_attrs_conserva_attrs_y_autor(): void {
		$before = $this->presets['module']['divi/text']['items']['p-text-1'];
		$this->assertIsArray( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1', 'label' => 'Sólo nombre' ) ) );

		$item = $this->presets['module']['divi/text']['items']['p-text-1'];
		$this->assertSame( 'Sólo nombre', $item['name'] );
		$this->assertSame( $before['attrs'], $item['attrs'] );
		$this->assertSame( 1, $item['authorId'] );
		$this->assertSame( self::NOW, $item['updated'] );
	}

	public function test_actualizar_un_id_inexistente_responde_not_found_y_no_escribe(): void {
		$before = $this->snapshot();
		$result = $this->assert_rejected( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'nope', 'label' => 'X' ) ), 'nexo_mcp_divi_preset_not_found', $before );

		$this->assertSame( array( 'id' => 'nope' ), $result->get_error_data() );
	}

	// --- Validación de la entrada ---------------------------------------------------------------

	public function test_la_entrada_invalida_se_rechaza_con_su_ruta_y_sin_escribir(): void {
		$ok    = array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'X', 'attrs' => $this->bg( '#111111' ) );
		$cases = array(
			'kind desconocido'      => array( array_merge( $ok, array( 'kind' => 'stack' ) ), 'kind' ),
			'kind ausente'          => array( array_diff_key( $ok, array( 'kind' => 1 ) ), 'kind' ),
			'name ausente'          => array( array_diff_key( $ok, array( 'name' => 1 ) ), 'name' ),
			'name no es texto'      => array( array_merge( $ok, array( 'name' => 5 ) ), 'name' ),
			'label ausente'         => array( array_diff_key( $ok, array( 'label' => 1 ) ), 'label' ),
			'label vacío'           => array( array_merge( $ok, array( 'label' => '  ' ) ), 'label' ),
			'label con etiquetas'   => array( array_merge( $ok, array( 'label' => '<b>x</b>' ) ), 'label' ),
			'label con shortcode'   => array( array_merge( $ok, array( 'label' => '[gallery ids="1"]' ) ), 'label' ),
			'label muy largo'       => array( array_merge( $ok, array( 'label' => str_repeat( 'a', 101 ) ) ), 'label' ),
			'id no es texto'        => array( array_merge( $ok, array( 'id' => 5 ) ), 'id' ),
			'id vacío'              => array( array_merge( $ok, array( 'id' => '' ) ), 'id' ),
			'crear sin attrs'       => array( array_diff_key( $ok, array( 'attrs' => 1 ) ), 'attrs' ),
			'crear con attrs vacío' => array( array_merge( $ok, array( 'attrs' => array() ) ), 'attrs' ),
			'attrs es una lista'    => array( array_merge( $ok, array( 'attrs' => array( 1, 2 ) ) ), 'attrs' ),
			'attrs es un texto'     => array( array_merge( $ok, array( 'attrs' => 'x' ) ), 'attrs' ),
			'actualizar con attrs vacío' => array( array_merge( $ok, array( 'id' => 'p-text-1', 'attrs' => array() ) ), 'attrs' ),
		);

		foreach ( $cases as $label => $case ) {
			$before = $this->snapshot();
			$this->assert_rejected( $this->ops()->set_preset( $case[0] ), 'nexo_mcp_divi_preset_invalid', $before, $case[1], $label );
		}
	}

	public function test_un_modulo_fuera_del_catalogo_se_rechaza(): void {
		$before = $this->snapshot();

		$result = $this->assert_rejected( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/gallery', 'label' => 'X', 'attrs' => $this->bg( '#111111' ) ) ), 'nexo_mcp_divi_preset_module_unknown', $before );
		$this->assertStringContainsString( 'divi/text', $result->get_error_message() );
	}

	public function test_los_attrs_peligrosos_o_fuera_del_catalogo_se_rechazan_con_ruta_aunque_el_usuario_tenga_unfiltered_html(): void {
		\WP_Test_State::$caps['unfiltered_html'] = true;
		$with                                    = static fn( array $value ): array => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => $value ) ) ) ) );
		$cases                                   = array(
			'manejador de evento'            => array( $with( array( 'color' => '#fff', 'onclick' => 'alert(1)' ) ), 'attrs.module.decoration.background.desktop.value.onclick' ),
			'attributes personalizados'      => array( array( 'attributes' => array( 'onclick' => 'alert(1)' ) ), 'attrs.attributes' ),
			'manejador en la raíz'           => array( array( 'onmouseover' => 'x()' ), 'attrs.onmouseover' ),
			'javascript: en un valor'        => array( $with( array( 'color' => 'javascript:alert(1)' ) ), 'attrs.module.decoration.background.desktop.value.color' ),
			'shortcode en un valor'          => array( $with( array( 'color' => '[gallery]' ) ), 'attrs.module.decoration.background.desktop.value.color' ),
			'css que se escapa del valor'    => array( $with( array( 'color' => 'red; } body{display:none' ) ), 'attrs.module.decoration.background.desktop.value.color' ),
			'ruta fuera del catálogo'        => array( array( 'zz' => array( 'a' => 1 ) ), 'attrs.zz' ),
			'tablet'                         => array( array( 'module' => array( 'decoration' => array( 'background' => array( 'tablet' => array( 'value' => array( 'color' => '#fff' ) ) ) ) ) ), 'attrs.module.decoration.background.tablet' ),
			'hover'                          => array( array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'hover' => 'x' ) ) ) ) ), 'attrs.module.decoration.background.desktop.hover' ),
			'contenido con shortcode'        => array( array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '[gallery ids="1"]' ) ) ) ), 'attrs.content.innerContent' ),
			'contenido con javascript:'      => array( array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<a href="javascript:alert(1)">x</a>' ) ) ) ), 'attrs.content.innerContent' ),
		);

		foreach ( $cases as $label => $case ) {
			$before = $this->snapshot();
			$result = $this->assert_rejected( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'X', 'attrs' => $case[0] ) ), 'nexo_mcp_divi_preset_invalid', $before, $case[1], $label );
			$this->assertNotSame( '', (string) ( $result->get_error_data()['rule'] ?? '' ), $label );
		}
	}

	public function test_los_attrs_se_guardan_saneados_por_el_validador_del_arbol(): void {
		$attrs = array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '10px', 'bottom' => '1rem' ) ) ) ) ) ) );
		$this->assertIsArray( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'Espacio', 'attrs' => $attrs ) ) );
		$this->assertSame( $attrs, $this->presets['module']['divi/text']['items']['uuid-1']['attrs'] );

		$bad = array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '10 parsecs' ) ) ) ) ) ) );
		$before = $this->snapshot();
		$this->assert_rejected( $this->ops()->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'Malo', 'attrs' => $bad ) ), 'nexo_mcp_divi_preset_invalid', $before, 'attrs.module.decoration.spacing.desktop.value.margin.top' );
	}

	// --- Grupos ---------------------------------------------------------------------------------

	public function test_crear_un_preset_de_grupo_guarda_grupo_modulo_y_group_id(): void {
		$result = $this->ops()->set_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'module' => 'divi/text', 'group_id' => 'module.decoration.background', 'label' => 'Fondo rojo', 'attrs' => $this->bg( '#ff0000' ) ) );

		$this->assertSame( 'group', $result['kind'] );
		$this->assertSame( 'uuid-1', $result['id'] );
		$this->assertSame(
			array(
				'type'       => 'group',
				'id'         => 'uuid-1',
				'name'       => 'Fondo rojo',
				'groupName'  => 'divi/background',
				'groupId'    => 'module.decoration.background',
				'moduleName' => 'divi/text',
				'version'    => '5.19.1',
				'created'    => self::NOW,
				'updated'    => self::NOW,
				'authorId'   => 7,
				'attrs'      => $this->bg( '#ff0000' ),
			),
			$this->presets['group']['divi/background']['items']['uuid-1']
		);
		$this->assertSame( '', $this->presets['group']['divi/background']['default'] );
		$this->assertSame( 'set-divi-preset', $this->history()[0]['tool'] );
	}

	public function test_crear_un_preset_de_grupo_valida_modulo_grupo_y_prefijo_de_los_attrs(): void {
		$ok    = array( 'kind' => 'group', 'name' => 'divi/background', 'module' => 'divi/text', 'group_id' => 'module.decoration.background', 'label' => 'X', 'attrs' => $this->bg( '#ff0000' ) );
		$cases = array(
			'sin module'                    => array( array_diff_key( $ok, array( 'module' => 1 ) ), 'nexo_mcp_divi_preset_invalid', 'module' ),
			'sin group_id'                  => array( array_diff_key( $ok, array( 'group_id' => 1 ) ), 'nexo_mcp_divi_preset_invalid', 'group_id' ),
			'módulo fuera del catálogo'     => array( array_merge( $ok, array( 'module' => 'divi/gallery' ) ), 'nexo_mcp_divi_preset_module_unknown', null ),
			'grupo que el módulo no tiene'  => array( array_merge( $ok, array( 'group_id' => 'module.decoration.border' ) ), 'nexo_mcp_divi_preset_invalid', 'group_id' ),
			'nombre que no es el del grupo' => array( array_merge( $ok, array( 'name' => 'divi/font-body' ) ), 'nexo_mcp_divi_preset_invalid', 'name' ),
			'attrs de otro grupo'           => array( array_merge( $ok, array( 'attrs' => array( 'module' => array( 'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '1px' ) ) ) ) ) ) ) ) ), 'nexo_mcp_divi_preset_invalid', 'attrs.module.decoration.spacing' ),
		);

		foreach ( $cases as $label => $case ) {
			$before = $this->snapshot();
			$this->assert_rejected( $this->ops()->set_preset( $case[0] ), $case[1], $before, $case[2], $label );
		}
	}

	public function test_actualizar_un_preset_de_grupo_toma_modulo_y_grupo_del_item_y_conserva_el_resto(): void {
		$before = $this->presets['group']['divi/background']['items']['g-bg-1'];
		$result = $this->ops()->set_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1', 'label' => 'Fondo verde', 'attrs' => $this->bg( '#00ff00' ) ) );

		$this->assertFalse( $result['created'] );
		$item = $this->presets['group']['divi/background']['items']['g-bg-1'];
		$this->assertSame( 'Fondo verde', $item['name'] );
		$this->assertSame( $this->bg( '#00ff00' ), $item['attrs'] );

		foreach ( array( 'type', 'id', 'groupName', 'groupId', 'moduleName', 'version', 'created', 'priority' ) as $key ) {
			$this->assertSame( $before[ $key ], $item[ $key ], $key );
		}
	}

	public function test_actualizar_un_preset_de_grupo_no_deja_cambiar_su_modulo_ni_su_grupo_y_los_attrs_siguen_su_prefijo(): void {
		$base   = array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1', 'label' => 'X' );
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_preset( array_merge( $base, array( 'module' => 'divi/button' ) ) ), 'nexo_mcp_divi_preset_invalid', $before, 'module' );
		$this->assert_rejected( $this->ops()->set_preset( array_merge( $base, array( 'group_id' => 'button.decoration.background' ) ) ), 'nexo_mcp_divi_preset_invalid', $before, 'group_id' );
		$this->assert_rejected(
			$this->ops()->set_preset( array_merge( $base, array( 'attrs' => array( 'button' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#fff' ) ) ) ) ) ) ) ) ),
			'nexo_mcp_divi_preset_invalid',
			$before,
			'attrs.button'
		);
	}

	public function test_un_preset_de_grupo_de_un_modulo_fuera_del_catalogo_no_se_edita(): void {
		$this->presets['group']['divi/background']['items']['g-x'] = array( 'type' => 'group', 'id' => 'g-x', 'name' => 'Raro', 'groupName' => 'divi/background', 'groupId' => 'module.decoration.background', 'moduleName' => 'divi/gallery', 'attrs' => $this->bg( '#fff' ) );
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-x', 'label' => 'X' ) ), 'nexo_mcp_divi_preset_module_unknown', $before );
	}

	// --- Predeterminado -------------------------------------------------------------------------

	public function test_fijar_el_predeterminado_de_un_modulo_y_de_un_grupo(): void {
		$result = $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-2' ) );

		$this->assertSame( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-2', 'changed' => true, 'area' => 'presets', 'undo_available' => true ), $result );
		$this->assertSame( 'p-text-2', $this->presets['module']['divi/text']['default'] );
		$this->assertSame( 'set-divi-default-preset', $this->history()[0]['tool'] );
		$this->assertSame( $this->sample()['module']['divi/text']['items'], $this->presets['module']['divi/text']['items'] );

		$group = $this->ops()->set_default_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1' ) );
		$this->assertTrue( $group['changed'] );
		$this->assertSame( 'g-bg-1', $this->presets['group']['divi/background']['default'] );
		$this->assertCount( 2, $this->history() );
	}

	public function test_quitar_el_predeterminado_con_null_lo_deja_vacio(): void {
		$result = $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => null ) );

		$this->assertNull( $result['id'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'] );
	}

	public function test_fijar_el_mismo_predeterminado_no_escribe_y_responde_changed_false(): void {
		$before = $this->snapshot();

		$same = $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1' ) );
		$this->assertFalse( $same['changed'] );
		$none = $this->ops()->set_default_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => null ) );
		$this->assertFalse( $none['changed'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_el_predeterminado_valida_kind_nombre_e_id(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'x', 'name' => 'divi/text', 'id' => 'p-text-1' ) ), 'nexo_mcp_divi_preset_invalid', $before, 'kind' );
		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text' ) ), 'nexo_mcp_divi_preset_invalid', $before, 'id' );
		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 7 ) ), 'nexo_mcp_divi_preset_invalid', $before, 'id' );
		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/gallery', 'id' => null ) ), 'nexo_mcp_divi_preset_module_unknown', $before );
		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'nope' ) ), 'nexo_mcp_divi_preset_not_found', $before );
		$this->assert_rejected( $this->ops()->set_default_preset( array( 'kind' => 'group', 'name' => 'divi/border', 'id' => 'g-bg-1' ) ), 'nexo_mcp_divi_preset_not_found', $before );
	}

	// --- Borrar ---------------------------------------------------------------------------------

	public function test_borrar_busca_el_uso_dentro_del_bloqueo_y_antes_de_escribir(): void {
		$result = $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-2' ) );

		$this->assertSame( array( 'lock', 'scan:module:p-text-2', 'save_data', 'flush' ), $this->log );
		$this->assertSame(
			array( 'deleted' => 'p-text-2', 'kind' => 'module', 'name' => 'divi/text', 'default_cleared' => false, 'posts_affected' => array(), 'area' => 'presets', 'undo_available' => true ),
			$result
		);
		$this->assertArrayNotHasKey( 'p-text-2', $this->presets['module']['divi/text']['items'] );
		$this->assertSame( 'p-text-1', $this->presets['module']['divi/text']['default'] );
		$this->assertSame( 'delete-divi-preset', $this->history()[0]['tool'] );
	}

	public function test_borrar_el_predeterminado_lo_desmarca(): void {
		$result = $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1' ) );

		$this->assertTrue( $result['default_cleared'] );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'] );
		$this->assertArrayHasKey( 'p-text-2', $this->presets['module']['divi/text']['items'] );
	}

	public function test_borrar_el_ultimo_preset_quita_la_coleccion_entera(): void {
		unset( $this->presets['module']['divi/text']['items']['p-text-1']['groupPresets'] );

		$this->assertIsArray( $this->ops()->delete_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1' ) ) );
		$this->assertIsArray( $this->ops()->delete_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-btn1' ) ) );

		$this->assertArrayNotHasKey( 'divi/background', $this->presets['group'] );
		$expected = $this->sample()['module'];
		unset( $expected['divi/text']['items']['p-text-1']['groupPresets'] );
		$this->assertSame( $expected, $this->presets['module'] );
	}

	public function test_borrar_en_uso_sin_force_responde_in_use_con_los_ids_y_no_escribe(): void {
		$this->usage['module:p-text-1'] = array( 12, 15 );
		$before                         = $this->snapshot();

		$result = $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_in_use', $result->get_error_code() );
		$this->assertSame( array( 'post_ids' => array( 12, 15 ) ), $result->get_error_data() );
		$this->assertStringContainsString( '12, 15', $result->get_error_message() );
		$this->assertStringContainsString( 'force', $result->get_error_message() );
		$this->assertSame( $before['presets'], $this->presets );
		$this->assertSame( $before['history'], get_option( DiviDesignStore::HISTORY_OPTION, null ) );
		$this->assertSame( 0, $this->flushes );
		$this->assertSame( array(), $this->audit );
	}

	public function test_borrar_en_uso_con_force_borra_y_devuelve_las_entradas_afectadas(): void {
		$this->usage['module:p-text-1'] = array( 12 );

		$result = $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1', 'force' => true ) );

		$this->assertSame( array( 12 ), $result['posts_affected'] );
		$this->assertArrayNotHasKey( 'p-text-1', $this->presets['module']['divi/text']['items'] );
		$this->assertSame( '', $this->presets['module']['divi/text']['default'] );
	}

	public function test_force_solo_cuenta_si_es_exactamente_true(): void {
		$this->usage['module:p-text-2'] = array( 3 );

		$result = $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-2', 'force' => 'yes' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_in_use', $result->get_error_code() );
	}

	public function test_un_preset_de_grupo_enlazado_desde_un_preset_de_modulo_cuenta_como_en_uso(): void {
		$before = $this->snapshot();

		$result = $this->ops()->delete_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_in_use', $result->get_error_code() );
		$this->assertSame( array( 'post_ids' => array(), 'preset_ids' => array( 'p-text-1' ) ), $result->get_error_data() );
		$this->assertSame( $before['presets'], $this->presets );

		$forced = $this->ops()->delete_preset( array( 'kind' => 'group', 'name' => 'divi/background', 'id' => 'g-bg-1', 'force' => true ) );
		$this->assertIsArray( $forced );
		$this->assertArrayNotHasKey( 'g-bg-1', $this->presets['group']['divi/background']['items'] );
	}

	public function test_borrar_valida_la_entrada_y_la_existencia_sin_escanear_ni_escribir(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text' ) ), 'nexo_mcp_divi_preset_invalid', $before, 'id' );
		$this->assert_rejected( $this->ops()->delete_preset( array( 'kind' => 'nope', 'name' => 'divi/text', 'id' => 'p-text-1' ) ), 'nexo_mcp_divi_preset_invalid', $before, 'kind' );
		$this->assert_rejected( $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/gallery', 'id' => 'p-text-1' ) ), 'nexo_mcp_divi_preset_module_unknown', $before );
		$this->assert_rejected( $this->ops()->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'nope' ) ), 'nexo_mcp_divi_preset_not_found', $before );
		$this->assertSame( array( 'lock' ), array_values( array_unique( $this->log ) ) );
	}

	// --- Sin Divi 5 -----------------------------------------------------------------------------

	public function test_sin_divi_5_ninguna_escritura_hace_nada(): void {
		$this->active = false;
		$before       = $this->snapshot();
		$ops          = $this->ops();

		$this->assert_rejected( $ops->set_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'label' => 'X', 'attrs' => $this->bg( '#111111' ) ) ), 'nexo_mcp_divi5_required', $before );
		$this->assert_rejected( $ops->set_default_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => null ) ), 'nexo_mcp_divi5_required', $before );
		$this->assert_rejected( $ops->delete_preset( array( 'kind' => 'module', 'name' => 'divi/text', 'id' => 'p-text-1' ) ), 'nexo_mcp_divi5_required', $before );
		$this->assertSame( array(), $this->log );
	}

	// --- Escaneo real ---------------------------------------------------------------------------

	/**
	 * @param array<int, array<string, string>> $posts Entradas simuladas.
	 * @return array<int, int>
	 */
	private function scan( string $kind, string $id, array $posts ): array {
		$saved           = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class( $posts ) extends \WPDB_Test_Double {
			public string $posts = 'wp_posts';

			/** @param array<int, array<string, string>> $rows */
			public function __construct( private array $rows ) {}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function get_results( $sql, $output = null ): array {
				preg_match( '/ID > (\d+)/', (string) $sql, $after );
				preg_match( '/LIMIT (\d+)/', (string) $sql, $limit );
				preg_match_all( "/LIKE '%(.*?)%'/", (string) $sql, $likes );
				$needles = array_map( static fn( string $n ): string => stripslashes( $n ), $likes[1] );
				$out     = array();

				foreach ( $this->rows as $id => $row ) {
					if ( $id <= (int) ( $after[1] ?? 0 ) || 'revision' === $row['post_type'] || in_array( $row['post_status'], array( 'trash', 'auto-draft' ), true ) ) {
						continue;
					}

					foreach ( $needles as $needle ) {
						if ( ! str_contains( $row['post_content'], $needle ) ) {
							continue 2;
						}
					}

					$out[] = array( 'ID' => (string) $id, 'post_content' => $row['post_content'] );

					if ( count( $out ) >= (int) ( $limit[1] ?? 0 ) ) {
						break;
					}
				}

				return $out;
			}
		};

		try {
			return DiviPresetOperations::scan_preset_usage( $kind, $id );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}
	}

	public function test_el_escaneo_de_modulo_exige_el_id_exacto_en_modulepreset_de_entradas_de_divi_5_no_borradas(): void {
		$page = static fn( string $status, string $attrs, string $type = 'page' ): array => array( 'post_type' => $type, 'post_status' => $status, 'post_content' => '<!-- wp:divi/text ' . $attrs . ' /-->' );
		$posts = array();

		// 1500 entradas cuyos ids sólo comparten prefijo, y los usos reales al final.
		for ( $i = 1; $i <= 1500; $i++ ) {
			$posts[ $i ] = $page( 'publish', '{"modulePreset":["abc-' . $i . '"]}' );
		}

		$posts[2000] = $page( 'publish', '{"modulePreset":["abc"]}' );
		$posts[2001] = $page( 'draft', '{"modulePreset":"abc"}' );
		$posts[2002] = $page( 'publish', '{"modulePreset":["otro","abc"]}' );
		$posts[2003] = $page( 'publish', '{"modulePreset":["abc"]}', 'revision' );
		$posts[2004] = $page( 'trash', '{"modulePreset":["abc"]}' );
		$posts[2005] = $page( 'publish', '{"title":"abc"}' );
		$posts[2006] = $page( 'publish', '{"groupPreset":{"module.decoration.background":{"presetId":["abc"],"groupName":"divi/background"}}}' );
		$posts[2007] = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'sin divi {"modulePreset":["abc"]}' );

		$this->assertSame( array( 2000, 2001, 2002 ), $this->scan( 'module', 'abc', $posts ) );
	}

	public function test_el_escaneo_de_grupo_busca_el_id_en_presetid_de_grouppreset(): void {
		$page  = static fn( string $content ): array => array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:divi/text ' . $content . ' /-->' );
		$posts = array(
			1 => $page( '{"groupPreset":{"module.decoration.background":{"presetId":["g1"],"groupName":"divi/background"}}}' ),
			2 => $page( '{"groupPreset":{"module.decoration.background":{"presetId":["g10"],"groupName":"divi/background"}}}' ),
			3 => $page( '{"groupPreset":{"module.decoration.background":{"presetId":["x","g1"],"groupName":"divi/background"}}}' ),
			4 => $page( '{"modulePreset":["g1"]}' ),
		);

		$this->assertSame( array( 1, 3 ), $this->scan( 'group', 'g1', $posts ) );
	}
}
