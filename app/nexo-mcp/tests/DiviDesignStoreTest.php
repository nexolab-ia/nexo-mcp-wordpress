<?php
/**
 * DiviDesignStore: lectura, respaldo, historial, deshacer, flush y bloqueo (Divi inyectado).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDesignStore;

final class DiviDesignStoreTest extends NexoTestCase {

	private bool $active = true;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $variables = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, string> Orden de lo ocurrido. */
	private array $log = array();

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	private int $flushes = 0;

	/** Si no es null, set_colors lanza esta excepción. */
	private ?\Throwable $colors_throw = null;

	/** Si no es null, set_variables devuelve este error. */
	private ?\WP_Error $variables_error = null;

	protected function setUp(): void {
		parent::setUp();
		$this->active          = true;
		$this->colors          = array(
			'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'status' => 'active' ),
			'gcid-bbbbbbbbbb' => array( 'color' => '#222222', 'label' => 'Extra', 'status' => 'inactive' ),
		);
		$this->variables       = array(
			'numbers'   => array( 'gvid-n1' => array( 'label' => 'Radio', 'value' => '12px', 'order' => 1, 'status' => 'active' ) ),
			'fonts'     => array( 'gvid-f1' => array( 'label' => 'Titulo', 'value' => 'Roboto', 'order' => 1, 'status' => 'archived' ) ),
			'gradients' => array( 'gvid-g1' => array( 'label' => 'Degradado', 'value' => 'linear-gradient(red, blue)', 'order' => 1, 'status' => 'active' ) ),
		);
		$this->options         = array(
			'accent_color'       => '#2ea3f2',
			'heading_font'       => 'Roboto',
			'body_font_size'     => '14',
			'unrelated_setting'  => 'x',
		);
		$this->log             = array();
		$this->audit           = array();
		$this->flushes         = 0;
		$this->colors_throw    = null;
		$this->variables_error = null;
		\WP_Test_State::$user_id = 7;
	}

	private function store(): DiviDesignStore {
		return new DiviDesignStore(
			fn(): bool => $this->active,
			function (): array {
				$this->log[] = 'get_colors';

				return $this->colors;
			},
			function ( array $all ): void {
				$this->log[] = 'set_colors';

				if ( null !== $this->colors_throw ) {
					throw $this->colors_throw;
				}

				$this->colors = $all;
			},
			function (): array {
				$this->log[] = 'get_variables';

				return $this->variables;
			},
			function ( array $all ) {
				$this->log[] = 'set_variables';

				if ( null !== $this->variables_error ) {
					return $this->variables_error;
				}

				$this->variables = $all;

				return null;
			},
			fn(): array => $this->options,
			function ( string $key, $value ): void {
				$this->log[] = 'set_option:' . $key;

				if ( null === $value ) {
					unset( $this->options[ $key ] );
				} else {
					$this->options[ $key ] = $value;
				}
			},
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

	private function history(): array {
		$value = get_option( DiviDesignStore::HISTORY_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	private function assert_nothing_changed( array $colors, array $variables, array $options ): void {
		$this->assertSame( $colors, $this->colors );
		$this->assertSame( $variables, $this->variables );
		$this->assertSame( $options, $this->options );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 0, $this->flushes );
		$this->assertNotContains( 'set_colors', $this->log );
		$this->assertNotContains( 'set_variables', $this->log );
	}

	private function assert_error( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	public function test_lee_colores_variables_clientes_y_tipografia(): void {
		$read = $this->store()->read();

		$this->assertSame(
			array(
				array( 'id' => 'gcid-aaaaaaaaaa', 'label' => 'Base', 'color' => '#111111', 'status' => 'active' ),
				array( 'id' => 'gcid-bbbbbbbbbb', 'label' => 'Extra', 'color' => '#222222', 'status' => 'inactive' ),
			),
			$read['colors']
		);
		$this->assertSame( array( 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ), array_keys( $read['variables'] ) );
		$this->assertSame( array( array( 'id' => 'gvid-n1', 'label' => 'Radio', 'value' => '12px', 'status' => 'active' ) ), $read['variables']['numbers'] );
		$this->assertSame( array(), $read['variables']['strings'] );
		$this->assertSame( 'archived', $read['variables']['fonts'][0]['status'] );
		$this->assertSame( 'gvid-g1', $read['variables']['gradients'][0]['id'] );
		$this->assertSame( array( 'accent', 'secondary_accent', 'header', 'font', 'link' ), array_keys( $read['customizer_colors'] ) );
		$this->assertSame( '#2ea3f2', $read['customizer_colors']['accent'] );
		$this->assertNull( $read['customizer_colors']['link'] );
		$this->assertSame( 'Roboto', $read['typography']['heading_font'] );
		$this->assertSame( '14', $read['typography']['body_font_size'] );
		$this->assertNull( $read['typography']['body_font_weight'] );
		$this->assertSame( array( 'heading_font', 'body_font', 'heading_font_weight', 'body_font_weight', 'body_font_size', 'body_line_height' ), array_keys( $read['typography'] ) );
		$this->assertSame( 0, $read['history_count'] );
		$this->assertSame( array(), $this->history(), 'leer no escribe' );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_write_colors_respalda_escribe_la_lista_completa_vacia_el_css_y_audita(): void {
		$before = $this->colors;
		$new    = $before;
		$new['gcid-aaaaaaaaaa']['color'] = '#ff0000';

		$result = $this->store()->write_colors( $new, 'set-divi-global-color' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['undo_available'] );
		$this->assertSame( $new, $this->colors );
		$this->assertSame( 1, $this->flushes );

		$history = $this->history();
		$this->assertCount( 1, $history );
		$this->assertSame( 'colors', $history[0]['area'] );
		$this->assertSame( $before, $history[0]['previous'] );
		$this->assertSame( 'set-divi-global-color', $history[0]['tool'] );
		$this->assertSame( 7, $history[0]['user'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history[0]['date'] );
		$this->assertFalse( \WP_Test_State::$option_autoload[ DiviDesignStore::HISTORY_OPTION ], 'opción no autocargada' );

		$this->assertCount( 1, $this->audit );
		$this->assertSame( 'set-divi-global-color', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );

		$order = $this->log;
		$this->assertLessThan( array_search( 'set_colors', $order, true ), array_search( 'lock', $order, true ), 'todo corre dentro del bloqueo' );
		$this->assertLessThan( array_search( 'flush', $order, true ), array_search( 'set_colors', $order, true ), 'el flush va después de escribir' );
	}

	public function test_write_colors_ignora_los_colores_del_customizer_que_no_respalda(): void {
		$new                       = $this->colors;
		$new['gcid-primary-color'] = array( 'color' => '#ff0000', 'label' => 'Primary', 'status' => 'active' );

		$this->assertIsArray( $this->store()->write_colors( $new, 't' ) );

		$this->assertArrayNotHasKey( 'gcid-primary-color', $this->colors );
		$this->assertSame( array( 'gcid-aaaaaaaaaa', 'gcid-bbbbbbbbbb' ), array_keys( $this->colors ) );
		$this->assertSame( '#2ea3f2', $this->options['accent_color'] );
	}

	public function test_el_historial_guarda_los_ultimos_20_cambios(): void {
		$store = $this->store();

		for ( $i = 1; $i <= 25; $i++ ) {
			$colors                    = $this->colors;
			$colors['gcid-aaaaaaaaaa']['color'] = '#0000' . sprintf( '%02d', $i );
			$this->assertIsArray( $store->write_colors( $colors, 't' . $i ) );
		}

		$history = $this->history();
		$this->assertCount( 20, $history );
		$this->assertSame( 't6', $history[0]['tool'], 'los 5 más viejos se descartan' );
		$this->assertSame( 't25', $history[19]['tool'] );
	}

	public function test_undo_restaura_el_ultimo_cambio_con_las_funciones_de_divi_vacia_el_css_y_lo_saca(): void {
		$original = $this->colors;
		$store    = $this->store();

		$one = $original;
		$one['gcid-aaaaaaaaaa']['color'] = '#ff0000';
		$store->write_colors( $one, 'primero' );
		$two = $one;
		$two['gcid-aaaaaaaaaa']['color'] = '#00ff00';
		$store->write_colors( $two, 'segundo' );
		$flushes = $this->flushes;
		$this->log = array();

		$result = $store->undo();

		$this->assertSame( $one, $this->colors );
		$this->assertSame( 'colors', $result['area'] );
		$this->assertSame( 'segundo', $result['tool'] );
		$this->assertSame( 1, $result['remaining'] );
		$this->assertCount( 1, $this->history() );
		$this->assertSame( $flushes + 1, $this->flushes );
		$this->assertContains( 'set_colors', $this->log );
		$this->assertSame( 'lock', $this->log[0] );
		$this->assertSame( 'undo-divi-design-change', $this->audit[2][0] );
		$this->assertSame( 'success', $this->audit[2][1] );

		$store->undo();
		$this->assertSame( $original, $this->colors );
		$this->assertSame( array(), $this->history() );
	}

	public function test_undo_sin_historial_responde_nada_que_deshacer_y_no_escribe(): void {
		$colors = $this->colors;
		$vars   = $this->variables;
		$opts   = $this->options;

		$this->assert_error( $this->store()->undo(), 'nexo_mcp_divi_design_nothing_to_undo' );
		$this->assert_nothing_changed( $colors, $vars, $opts );
	}

	public function test_un_historial_malformado_cuenta_como_vacio(): void {
		update_option( DiviDesignStore::HISTORY_OPTION, 'basura', false );
		$colors = $this->colors;

		$this->assert_error( $this->store()->undo(), 'nexo_mcp_divi_design_nothing_to_undo' );
		$this->assertSame( $colors, $this->colors );
		$this->assertSame( 0, $this->flushes );

		update_option( DiviDesignStore::HISTORY_OPTION, array( array( 'area' => 'colors' ), 5 ), false );
		$this->assert_error( $this->store()->undo(), 'nexo_mcp_divi_design_nothing_to_undo' );
		$this->assertSame( $colors, $this->colors );
	}

	public function test_write_variables_cambia_sólo_ese_tipo_y_undo_lo_devuelve(): void {
		$before = $this->variables;
		$new    = $before['numbers'];
		$new['gvid-n2'] = array( 'label' => 'Espacio', 'value' => '8px', 'order' => 2, 'status' => 'active' );

		$result = $this->store()->write_variables( 'numbers', $new, 'set-divi-variable' );

		$this->assertIsArray( $result );
		$this->assertSame( $new, $this->variables['numbers'] );
		$this->assertSame( $before['fonts'], $this->variables['fonts'] );
		$this->assertSame( $before['gradients'], $this->variables['gradients'] );
		$this->assertSame( 1, $this->flushes );
		$this->assertSame( 'variables:numbers', $this->history()[0]['area'] );
		$this->assertSame( $before['numbers'], $this->history()[0]['previous'] );

		$undo = $this->store()->undo();

		$this->assertIsArray( $undo );
		// Divi rechaza una lista a la que le falta un id guardado: lo creado se archiva, no se borra.
		$this->assertSame( $before['numbers']['gvid-n1'], $this->variables['numbers']['gvid-n1'] );
		$this->assertSame( 'archived', $this->variables['numbers']['gvid-n2']['status'] );
		$this->assertSame( array(), $this->history() );
	}

	public function test_write_variables_rechaza_tipos_que_no_se_escriben(): void {
		$colors = $this->colors;
		$vars   = $this->variables;
		$opts   = $this->options;

		foreach ( array( 'gradients', 'colors', 'otro', '' ) as $type ) {
			$error = $this->assert_error( $this->store()->write_variables( $type, array(), 't' ), 'nexo_mcp_divi_design_type_invalid' );
			$this->assertNotSame( '', $error->get_error_message() );
		}

		$this->assert_nothing_changed( $colors, $vars, $opts );
		$this->assertSame( array(), $this->audit );
	}

	public function test_write_theme_options_respalda_lo_anterior_incluso_lo_ausente_y_undo_lo_devuelve(): void {
		$result = $this->store()->write_theme_options(
			array(
				'accent_color' => '#ff0000',
				'body_font'    => 'Lato',
			),
			'set-divi-typography'
		);

		$this->assertIsArray( $result );
		$this->assertSame( '#ff0000', $this->options['accent_color'] );
		$this->assertSame( 'Lato', $this->options['body_font'] );
		$this->assertSame( 'x', $this->options['unrelated_setting'] );
		$this->assertSame( 1, $this->flushes );

		$entry = $this->history()[0];
		$this->assertSame( 'theme_options', $entry['area'] );
		$this->assertSame( array( 'accent_color' => '#2ea3f2', 'body_font' => null ), $entry['previous'] );

		$this->assertIsArray( $this->store()->undo() );
		$this->assertSame( '#2ea3f2', $this->options['accent_color'] );
		$this->assertArrayNotHasKey( 'body_font', $this->options, 'lo que no existía vuelve a no existir' );
		$this->assertSame( 'x', $this->options['unrelated_setting'] );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 2, $this->flushes );
	}

	public function test_write_theme_options_rechaza_claves_ajenas_o_vacias_sin_escribir(): void {
		$colors = $this->colors;
		$vars   = $this->variables;
		$opts   = $this->options;

		$this->assert_error( $this->store()->write_theme_options( array( 'accent_color' => '#fff', 'siteurl' => 'http://x' ), 't' ), 'nexo_mcp_divi_design_option_invalid' );
		$this->assert_error( $this->store()->write_theme_options( array(), 't' ), 'nexo_mcp_divi_design_option_invalid' );

		$this->assert_nothing_changed( $colors, $vars, $opts );
	}

	public function test_sin_divi_5_nada_lee_ni_escribe(): void {
		$this->active = false;
		$colors       = $this->colors;
		$vars         = $this->variables;
		$opts         = $this->options;
		$store        = $this->store();

		$this->assert_error( $store->read(), 'nexo_mcp_divi5_required' );
		$this->assert_error( $store->write_colors( array(), 't' ), 'nexo_mcp_divi5_required' );
		$this->assert_error( $store->write_variables( 'numbers', array(), 't' ), 'nexo_mcp_divi5_required' );
		$this->assert_error( $store->write_theme_options( array( 'accent_color' => '#fff' ), 't' ), 'nexo_mcp_divi5_required' );
		$this->assert_error( $store->undo(), 'nexo_mcp_divi5_required' );

		$this->assert_nothing_changed( $colors, $vars, $opts );
		$this->assertSame( array(), $this->audit );
	}

	public function test_si_divi_falla_al_escribir_no_queda_respaldo_ni_cambio_y_se_audita(): void {
		$this->colors_throw = new \RuntimeException( 'divi roto' );
		$colors             = $this->colors;

		$error = $this->assert_error( $this->store()->write_colors( array(), 'set-divi-global-color' ), 'nexo_mcp_divi_design_write_failed' );

		$this->assertStringContainsString( 'divi roto', $error->get_error_message() );
		$this->assertSame( $colors, $this->colors );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 'error', $this->audit[0][1] );

		$this->variables_error = new \WP_Error( 'global_variable_ids_missing', 'faltan ids' );
		$vars                  = $this->variables;

		$error = $this->assert_error( $this->store()->write_variables( 'numbers', array(), 't' ), 'nexo_mcp_divi_design_write_failed' );
		$this->assertStringContainsString( 'faltan ids', $error->get_error_message() );
		$this->assertSame( $vars, $this->variables );
		$this->assertSame( array(), $this->history() );
	}

	public function test_si_no_se_puede_guardar_el_respaldo_no_se_escribe_nada(): void {
		\WP_Test_State::$fail_update_option = DiviDesignStore::HISTORY_OPTION;
		$colors                             = $this->colors;
		$vars                               = $this->variables;
		$opts                               = $this->options;

		$this->assert_error( $this->store()->write_colors( array(), 't' ), 'nexo_mcp_divi_design_backup_failed' );
		$this->assert_nothing_changed( $colors, $vars, $opts );
	}

	public function test_si_deshacer_falla_el_cambio_sigue_en_el_historial(): void {
		$store = $this->store();
		$new   = $this->colors;
		$new['gcid-aaaaaaaaaa']['color'] = '#ff0000';
		$store->write_colors( $new, 't' );

		$this->colors_throw = new \RuntimeException( 'no restaura' );
		$error              = $this->assert_error( $store->undo(), 'nexo_mcp_divi_design_undo_failed' );

		$this->assertStringContainsString( 'no restaura', $error->get_error_message() );
		$this->assertSame( $new, $this->colors );
		$this->assertCount( 1, $this->history() );
		$this->assertSame( 'error', $this->audit[1][1] );
	}
}
