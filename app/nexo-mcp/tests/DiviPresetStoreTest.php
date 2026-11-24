<?php
/**
 * DiviPresetStore: lectura, escritura con respaldo en el historial de D3, deshacer del área `presets` (Divi inyectado).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\Builders\DiviPresetStore;

final class DiviPresetStoreTest extends NexoTestCase {

	private bool $active = true;

	/** @var array<string, mixed>|null Opción de presets (null = no existe). */
	private ?array $presets = null;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	/** @var array<int, string> Orden de lo ocurrido. */
	private array $log = array();

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	private int $flushes = 0;

	private ?\Throwable $save_throw = null;

	/** Si es true, save_data agrega un `default` vacío que Divi normaliza. */
	private bool $save_normalize = false;

	protected function setUp(): void {
		parent::setUp();
		$this->active         = true;
		$this->presets        = $this->sample();
		$this->colors         = array( 'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ) );
		$this->log            = array();
		$this->audit          = array();
		$this->flushes        = 0;
		$this->save_throw     = null;
		$this->save_normalize = false;
		\WP_Test_State::$user_id = 7;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample(): array {
		return array(
			'module' => array(
				'divi/text'   => array(
					'default' => 'p-text-1',
					'items'   => array(
						'p-text-1' => array(
							'type'       => 'module',
							'id'         => 'p-text-1',
							'name'       => 'Texto base',
							'moduleName' => 'divi/text',
							'version'    => '5.19.1',
							'created'    => 1700000000,
							'updated'    => 1700000100,
							'authorId'   => 1,
							'attrs'      => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#ff0000' ) ) ) ) ) ),
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
							'attrs'      => array( 'content' => array( 'decoration' => array( 'bodyFont' => array( 'desktop' => array( 'value' => array( 'size' => '18px' ) ) ) ) ) ),
						),
					),
				),
				'divi/button' => array( 'default' => '', 'items' => array() ),
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
							'attrs'      => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#0000ff' ) ) ) ) ) ),
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
			fn(): array => $this->colors,
			function ( array $all ): void {
				$this->log[]  = 'set_colors';
				$this->colors = $all;
			},
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

	private function store( ?DiviDesignStore $design = null ): DiviPresetStore {
		return new DiviPresetStore(
			$design ?? $this->design(),
			function (): ?array {
				$this->log[] = 'get_presets';

				return $this->presets;
			},
			function ( array $data ): array {
				$this->log[] = 'save_data';

				if ( null !== $this->save_throw ) {
					throw $this->save_throw;
				}

				$this->presets = $data;

				if ( $this->save_normalize ) {
					$this->presets['module']['divi/button']['default'] = 'normalizado';
				}

				return $this->presets;
			},
			function (): void {
				$this->log[]   = 'clear_data';
				$this->presets = null;
			},
			static fn( string $module ): array => 'divi/text' === $module
				? array( 'module.decoration.background' => 'divi/background', 'content.decoration.bodyFont' => 'divi/font-body' )
				: ( 'divi/button' === $module ? array( 'button.decoration.background' => 'divi/background' ) : array() )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function history(): array {
		$value = get_option( DiviDesignStore::HISTORY_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	private function assert_error( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	private function change_color( string $color ): callable {
		return function ( array $current ) use ( $color ): array {
			$current['module']['divi/text']['items']['p-text-1']['attrs']['module']['decoration']['background']['desktop']['value']['color'] = $color;

			return $current;
		};
	}

	public function test_sin_divi_5_leer_y_escribir_responden_el_error_y_no_tocan_nada(): void {
		$this->active = false;
		$before       = $this->presets;
		$store        = $this->store();

		$this->assert_error( $store->read(), 'nexo_mcp_divi5_required' );
		$this->assert_error( $store->update( fn( array $c ) => $c, 'set-divi-preset' ), 'nexo_mcp_divi5_required' );

		$this->assertSame( $before, $this->presets );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( array(), $this->log );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_leer_devuelve_modulos_grupos_predeterminados_y_grupos_disponibles_sin_atributos(): void {
		$read = $this->store()->read();

		$this->assertIsArray( $read );
		$this->assertSame( array( 'modules', 'groups', 'available_groups', 'history_count' ), array_keys( $read ) );
		$this->assertSame( 0, $read['history_count'] );

		$this->assertSame( array( 'divi/text', 'divi/button' ), array_column( $read['modules'], 'module' ) );
		$text = $read['modules'][0];
		$this->assertSame( 'p-text-1', $text['default'] );
		$this->assertTrue( $text['in_catalog'] );
		$this->assertSame( array( 'p-text-1', 'p-text-2' ), array_column( $text['presets'], 'id' ) );
		$this->assertSame( 'Texto base', $text['presets'][0]['name'] );
		$this->assertSame( 1700000000, $text['presets'][0]['created'] );
		$this->assertSame( 1700000100, $text['presets'][0]['updated'] );
		$this->assertArrayNotHasKey( 'attrs', $text['presets'][0], 'sin filtro no se devuelven los atributos' );
		$this->assertNull( $read['modules'][1]['default'], 'default vacío = sin predeterminado' );
		$this->assertSame( array(), $read['modules'][1]['presets'] );

		$this->assertSame( array( 'divi/background' ), array_column( $read['groups'], 'group' ) );
		$this->assertNull( $read['groups'][0]['default'] );
		$this->assertSame( array( 'g-bg-1', 'g-bg-btn1' ), array_column( $read['groups'][0]['presets'], 'id' ) );
		$this->assertSame( 'divi/text', $read['groups'][0]['presets'][0]['module'] );
		$this->assertSame( 'module.decoration.background', $read['groups'][0]['presets'][0]['group_id'] );

		$available = array_column( $read['available_groups'], 'groups', 'module' );
		$this->assertSame( \NexoDev\NexoMCP\Builders\DiviModuleCatalog::modules(), array_keys( $available ), 'una entrada por módulo del catálogo de D2' );
		$this->assertContains( array( 'id' => 'module.decoration.background', 'name' => 'divi/background' ), $available['divi/text'] );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 0, $this->flushes );
		$this->assertNotContains( 'save_data', $this->log, 'leer no escribe' );
	}

	public function test_leer_con_modulo_filtra_incluye_los_atributos_y_sus_grupos(): void {
		$read = $this->store()->read( 'divi/text' );

		$this->assertIsArray( $read );
		$this->assertSame( array( 'divi/text' ), array_column( $read['modules'], 'module' ) );
		$this->assertSame( array( 'p-text-1', 'p-text-2' ), array_column( $read['modules'][0]['presets'], 'id' ) );
		$this->assertSame( '#ff0000', $read['modules'][0]['presets'][0]['attrs']['module']['decoration']['background']['desktop']['value']['color'] );
		$this->assertSame( array( 'g-bg-1' ), array_column( $read['groups'][0]['presets'], 'id' ), 'sólo los grupos de ese módulo' );
		$this->assertArrayHasKey( 'attrs', $read['groups'][0]['presets'][0] );
		$this->assertSame( array( 'divi/text' ), array_column( $read['available_groups'], 'module' ) );
		$this->assertSame( 2, count( $read['available_groups'][0]['groups'] ) );
	}

	public function test_leer_un_modulo_sin_presets_responde_vacio_y_uno_fuera_del_catalogo_o_mal_formado_es_error(): void {
		$read = $this->store()->read( 'divi/heading' );

		$this->assertIsArray( $read );
		$this->assertSame( array(), $read['modules'] );
		$this->assertSame( array(), $read['groups'] );

		$this->assert_error( $this->store()->read( 'divi/gallery' ), 'nexo_mcp_divi_preset_module_unknown' );
		$this->assert_error( $this->store()->read( 'divi/section/../x' ), 'nexo_mcp_divi_preset_module_unknown' );
		$this->assert_error( $this->store()->read( '' ), 'nexo_mcp_divi_preset_module_unknown' );
	}

	public function test_leer_sin_la_opcion_o_con_basura_responde_listas_vacias(): void {
		$this->presets = null;
		$read          = $this->store()->read();

		$this->assertIsArray( $read );
		$this->assertSame( array(), $read['modules'] );
		$this->assertSame( array(), $read['groups'] );
		$this->assertNotSame( array(), $read['available_groups'] );

		$this->presets = array( 'module' => 'roto', 'group' => array( 'divi/background' => 'roto', 'divi/border' => array( 'default' => 5, 'items' => array( 'x' => 'roto', 'y' => array( 'name' => 'Sin id' ) ) ) ) );
		$read          = $this->store()->read();

		$this->assertIsArray( $read );
		$this->assertSame( array(), $read['modules'] );
		$this->assertSame( array( 'divi/border' ), array_column( $read['groups'], 'group' ) );
		$this->assertNull( $read['groups'][0]['default'] );
		$this->assertSame( array( 'y' ), array_column( $read['groups'][0]['presets'], 'id' ), 'sin id propio se usa la clave' );
	}

	public function test_un_modulo_que_no_es_del_catalogo_se_lee_marcado_sin_ofrecer_grupos(): void {
		$this->presets['module']['divi/gallery'] = array( 'default' => '', 'items' => array() );
		$read                                    = $this->store()->read();

		$entries = array_column( $read['modules'], 'in_catalog', 'module' );
		$this->assertFalse( $entries['divi/gallery'] );
		$this->assertTrue( $entries['divi/text'] );
		$this->assertNotContains( 'divi/gallery', array_column( $read['available_groups'], 'module' ) );
	}

	public function test_update_escribe_con_save_data_bajo_el_bloqueo_respalda_en_el_historial_vacia_y_audita(): void {
		$before = $this->presets;

		$result = $this->store()->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );

		$this->assertSame( array( 'area' => 'presets', 'undo_available' => true ), $result );
		$this->assertSame( array( 'lock', 'get_presets', 'save_data', 'flush', 'get_presets' ), $this->log, 'bloqueo, leer, escribir con Divi, vaciar, comprobar lo guardado' );
		$this->assertSame( '#abcdef', $this->presets['module']['divi/text']['items']['p-text-1']['attrs']['module']['decoration']['background']['desktop']['value']['color'] );
		$this->assertSame( 1, $this->flushes );

		$history = $this->history();
		$this->assertCount( 1, $history );
		$this->assertSame( 'presets', $history[0]['area'] );
		$this->assertSame( $before, $history[0]['previous'] );
		$this->assertSame( $this->presets, $history[0]['written'] );
		$this->assertSame( 'set-divi-preset', $history[0]['tool'] );
		$this->assertSame( 7, $history[0]['user'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history[0]['date'] );

		$this->assertCount( 1, $this->audit );
		$this->assertSame( array( 'set-divi-preset', 'success', array( 'area' => 'presets' ) ), array_slice( $this->audit[0], 0, 3 ) );
		$this->assertSame( $result, $this->audit[0][3] );
		$this->assertFalse( get_option( 'et_divi_builder_global_presets_d5', false ), 'nunca update_option crudo sobre la opción de Divi' );
	}

	public function test_update_recibe_la_opcion_completa_y_si_no_existe_un_arreglo_vacio_y_respalda_null(): void {
		$this->presets = null;
		$seen          = null;

		$result = $this->store()->update(
			function ( array $current ) use ( &$seen ): array {
				$seen = $current;

				return array( 'module' => array( 'divi/text' => array( 'default' => '', 'items' => array() ) ) );
			},
			'set-divi-preset'
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $seen );
		$history = $this->history();
		$this->assertNull( $history[0]['previous'] );
		$this->assertArrayHasKey( 'previous', $history[0] );
	}

	public function test_update_con_error_del_cambio_no_escribe_ni_respalda_ni_vacia(): void {
		$before = $this->presets;
		$error  = new \WP_Error( 'nexo_mcp_divi_preset_invalid', 'no' );

		$result = $this->store()->update( fn( array $c ) => $error, 'set-divi-preset' );

		$this->assertSame( $error, $result );
		$this->assertSame( $before, $this->presets );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 0, $this->flushes );
		$this->assertNotContains( 'save_data', $this->log );
		$this->assertSame( array(), $this->audit );
	}

	public function test_si_el_cambio_lanza_una_excepcion_es_un_error_auditado_sin_escribir(): void {
		$before = $this->presets;
		$result = $this->store()->update(
			function ( array $c ) {
				throw new \RuntimeException( 'cambio roto' );
			},
			'set-divi-preset'
		);

		$error = $this->assert_error( $result, 'nexo_mcp_divi_design_write_failed' );
		$this->assertSame( 'cambio roto', $error->get_error_message() );
		$this->assertSame( $before, $this->presets );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( array( 'set-divi-preset', 'error' ), array_slice( $this->audit[0], 0, 2 ) );
	}

	public function test_si_save_data_falla_se_quita_el_respaldo_y_se_informa(): void {
		$this->save_throw = new \RuntimeException( 'Divi no pudo' );
		$before           = $this->presets;

		$result = $this->store()->update( $this->change_color( '#123456' ), 'set-divi-preset' );

		$error = $this->assert_error( $result, 'nexo_mcp_divi_design_write_failed' );
		$this->assertSame( 'Divi no pudo', $error->get_error_message() );
		$this->assertSame( $before, $this->presets );
		$this->assertSame( array(), $this->history(), 'sin escritura no queda respaldo' );
		$this->assertSame( array( 'set-divi-preset', 'error' ), array_slice( $this->audit[0], 0, 2 ) );
		$this->assertSame( array( 'error' => 'nexo_mcp_divi_design_write_failed' ), $this->audit[0][3] );
	}

	public function test_si_divi_normaliza_lo_escrito_el_historial_recuerda_lo_que_quedo_guardado(): void {
		$this->save_normalize = true;

		$this->assertIsArray( $this->store()->update( $this->change_color( '#abcdef' ), 'set-divi-preset' ) );

		$this->assertSame( 'normalizado', $this->history()[0]['written']['module']['divi/button']['default'] );
		$this->assertSame( $this->presets, $this->history()[0]['written'] );
		// Y deshacer no se niega: lo guardado coincide con lo registrado.
		$this->assertIsArray( $this->design_with_presets()->undo() );
	}

	private function design_with_presets(): DiviDesignStore {
		$design = $this->design();
		$this->store( $design );

		return $design;
	}

	public function test_deshacer_restaura_con_save_data_lo_anterior_vacia_el_css_y_saca_la_entrada(): void {
		$before = $this->presets;
		$design = $this->design();
		$store  = $this->store( $design );
		$store->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );
		$this->log     = array();
		$this->flushes = 0;
		$this->audit   = array();

		$result = $design->undo();

		$this->assertSame( array( 'area' => 'presets', 'tool' => 'set-divi-preset', 'changed_at' => $this->audit[0][3]['changed_at'], 'remaining' => 0 ), $result );
		$this->assertSame( $before, $this->presets );
		$this->assertSame( array( 'lock', 'get_presets', 'save_data', 'flush' ), $this->log );
		$this->assertSame( 1, $this->flushes );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( array( 'undo-divi-design-change', 'success', array( 'area' => 'presets' ) ), array_slice( $this->audit[0], 0, 3 ) );
	}

	public function test_deshacer_lo_que_creo_la_opcion_la_borra_en_vez_de_guardar_vacio(): void {
		$this->presets = null;
		$design        = $this->design();
		$store         = $this->store( $design );
		$store->update( fn( array $c ) => array( 'module' => array( 'divi/text' => array( 'default' => '', 'items' => array() ) ) ), 'set-divi-preset' );
		$this->assertNotNull( $this->presets );
		$this->log = array();

		$this->assertIsArray( $design->undo() );

		$this->assertNull( $this->presets );
		$this->assertContains( 'clear_data', $this->log );
		$this->assertNotContains( 'save_data', $this->log );
		$this->assertSame( array(), $this->history() );
	}

	public function test_deshacer_se_niega_si_los_presets_cambiaron_despues_y_no_toca_nada(): void {
		$design = $this->design();
		$store  = $this->store( $design );
		$store->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );
		$this->presets['module']['divi/text']['default'] = 'p-text-2'; // Cambio ajeno (panel de Divi).
		$foreign                                         = $this->presets;
		$history                                         = $this->history();
		$this->log                                       = array();
		$this->flushes                                   = 0;

		$error = $this->assert_error( $design->undo(), 'nexo_mcp_divi_design_changed_since' );

		$this->assertStringContainsString( 'force', $error->get_error_message() );
		$this->assertSame( $foreign, $this->presets );
		$this->assertSame( $history, $this->history() );
		$this->assertNotContains( 'save_data', $this->log );
		$this->assertSame( 0, $this->flushes );
		$this->assertSame( array( 'undo-divi-design-change', 'error' ), array_slice( end( $this->audit ), 0, 2 ) );
	}

	public function test_deshacer_con_force_pisa_el_cambio_ajeno_y_restaura_lo_anterior(): void {
		$before = $this->presets;
		$design = $this->design();
		$store  = $this->store( $design );
		$store->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );
		$this->presets['module']['divi/text']['default'] = 'p-text-2';

		$this->assertIsArray( $design->undo( true ) );

		$this->assertSame( $before, $this->presets );
		$this->assertSame( array(), $this->history() );
	}

	public function test_deshacer_con_presets_en_el_historial_pero_sin_el_almacen_registrado_falla_sin_tocar_nada(): void {
		$design = $this->design();
		$this->store( $design )->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );
		$written = $this->presets;
		$history = $this->history();

		// Un almacén de diseño nuevo que nunca conoció el de presets: no sabe restaurar el área.
		$error = $this->assert_error( $this->design()->undo( true ), 'nexo_mcp_divi_design_undo_failed' );

		$this->assertSame( $written, $this->presets );
		$this->assertSame( $history, $this->history(), 'el respaldo se conserva' );
		$this->assertStringContainsString( 'respaldo', $error->get_error_message() );
	}

	public function test_deshacer_con_el_permiso_del_area_negado_no_restaura_ni_saca_la_entrada(): void {
		$design = $this->design();
		$this->store( $design )->update( $this->change_color( '#abcdef' ), 'set-divi-preset' );
		$written = $this->presets;
		$history = $this->history();
		$asked   = array();

		$error = $this->assert_error(
			$design->undo(
				true,
				function ( string $area ) use ( &$asked ): bool {
					$asked[] = $area;

					return false;
				}
			),
			'nexo_mcp_divi_design_area_forbidden'
		);

		$this->assertSame( array( 'presets' ), $asked );
		$this->assertStringContainsString( 'mcp:divi_presets', $error->get_error_message() );
		$this->assertSame( $written, $this->presets );
		$this->assertSame( $history, $this->history() );
		$this->assertSame( array( 'undo-divi-design-change', 'error' ), array_slice( end( $this->audit ), 0, 2 ) );
	}

	public function test_el_historial_es_uno_solo_con_las_demas_areas_y_deshacer_va_en_orden_inverso(): void {
		$colors_before = $this->colors;
		$presets_before = $this->presets;
		$design        = $this->design();
		$store         = $this->store( $design );

		$new                                = $this->colors;
		$new['gcid-aaaaaaaaaa']['color']    = '#ff0000';
		$this->assertIsArray( $design->write_colors( $new, 'set-divi-global-color' ) );
		$this->assertIsArray( $store->update( $this->change_color( '#abcdef' ), 'set-divi-preset' ) );

		$this->assertSame( array( 'colors', 'presets' ), array_column( $this->history(), 'area' ) );
		$this->assertSame( 2, $design->read()['history_count'] );

		$first = $design->undo();
		$this->assertSame( 'presets', $first['area'] );
		$this->assertSame( $presets_before, $this->presets );
		$this->assertSame( '#ff0000', $this->colors['gcid-aaaaaaaaaa']['color'], 'los colores siguen como estaban' );

		$second = $design->undo();
		$this->assertSame( 'colors', $second['area'] );
		$this->assertSame( $colors_before, $this->colors );
		$this->assertSame( array(), $this->history() );
	}

	public function test_el_historial_conserva_los_ultimos_20_cambios_al_mezclar_areas(): void {
		$design = $this->design();
		$store  = $this->store( $design );

		for ( $i = 0; $i < 22; $i++ ) {
			$this->assertIsArray( $store->update( $this->change_color( sprintf( '#%06x', $i + 1 ) ), 'set-divi-preset' ) );
		}

		$this->assertCount( DiviDesignStore::HISTORY_LIMIT, $this->history() );
	}
}
