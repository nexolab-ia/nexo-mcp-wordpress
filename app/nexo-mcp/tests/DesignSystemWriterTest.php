<?php
/**
 * Escritura del sistema de diseño: bloqueo, respaldo, vuelta atrás, deshacer y Actividad.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DesignSystemStore;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * Almacén en memoria: registra cada lectura, restauración y limpieza de caché.
 */
final class FakeDesignStore implements DesignSystemStore {

	public bool $available = true;

	public bool $atomic = true;

	public int $kit = 77;

	/** @var array<string, array<string, mixed>> */
	public array $states = array(
		'classes'   => array( 'items' => array( 'g-a' => array( 'label' => 'a' ) ), 'order' => array( 'g-a' ) ),
		'variables' => array( 'data' => array( 'e-gv-1' => array( 'label' => 'rojo' ) ), 'watermark' => 3 ),
		'kit'       => array( 'custom_colors' => array( array( '_id' => 'c1', 'color' => '#000000' ) ) ),
	);

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public array $restored = array();

	public int $cleared = 0;

	public ?\Throwable $restore_fails = null;

	public function available(): bool {
		return $this->available;
	}

	public function atomic(): bool {
		return $this->atomic;
	}

	public function kit_id(): int {
		return $this->kit;
	}

	public function snapshot( string $kind ): array {
		return $this->states[ $kind ];
	}

	public function restore( string $kind, array $state ): void {
		if ( null !== $this->restore_fails ) {
			throw $this->restore_fails;
		}

		$this->restored[]       = array( $kind, $state );
		$this->states[ $kind ] = $state;
	}

	public function clear_cache(): void {
		++$this->cleared;
	}
}

final class DesignSystemWriterTest extends NexoTestCase {

	private FakeDesignStore $store;

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	/** @var array<int, string> */
	private array $calls = array();

	private float $wait;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeDesignStore();
		$this->audit = array();
		$this->calls = array();
		$this->wait  = PostLock::$wait;
		\WP_Test_State::$user_id = 5;
	}

	protected function tearDown(): void {
		PostLock::$wait = $this->wait;
		parent::tearDown();
	}

	private function writer(): DesignSystemWriter {
		return new DesignSystemWriter(
			$this->store,
			function ( string $tool, string $status, $params, $result ): void {
				$this->audit[] = array( $tool, $status, $params, $result );
			}
		);
	}

	/**
	 * Escritura de clases que cambia el estado guardado del almacén.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write_class( ?callable $validate = null, ?callable $apply = null ) {
		return $this->writer()->write(
			'classes',
			'create-class',
			$validate ?? function () {
				$this->calls[] = 'validate';

				return array( 'id' => 'g-b' );
			},
			$apply ?? function ( array $plan ) {
				$this->calls[] = 'apply';
				$this->store->states['classes']['items'][ $plan['id'] ] = array( 'label' => 'b' );

				return array( 'id' => $plan['id'] );
			},
			array( 'label' => 'b' )
		);
	}

	private function backup(): ?array {
		$value = \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ] ?? null;

		return is_array( $value ) ? $value : null;
	}

	private function previous_backup(): array {
		$previous = array(
			'kind'      => 'kit',
			'operation' => 'update-kit',
			'state'     => array( 'custom_colors' => array() ),
			'at'        => '2026-11-17 10:00:00',
			'user'      => 2,
			'note'      => '',
		);
		update_option( DesignSystemWriter::BACKUP_OPTION, $previous, false );

		return $previous;
	}

	// ------------------------------------------------------------ Requisitos

	public function test_sin_elementor_responde_error_y_no_hace_nada(): void {
		$this->store->available = false;

		$result = $this->write_class();

		$this->assertWPError( $result, 'nexo_mcp_elementor_missing' );
		$this->assertSame( array(), $this->calls );
		$this->assertNull( $this->backup() );
		$this->assertSame( 0, $this->store->cleared );
		$this->assertSame( array(), $this->audit );
	}

	public function test_sin_kit_activo_responde_error_y_no_hace_nada(): void {
		$this->store->kit = 0;

		$this->assertWPError( $this->write_class(), 'nexo_mcp_elementor_kit_missing' );
		$this->assertSame( array(), $this->calls );
		$this->assertNull( $this->backup() );
	}

	public function test_clases_y_variables_exigen_editor_v4_pero_el_kit_no(): void {
		$this->store->atomic = false;

		$this->assertWPError( $this->write_class(), 'nexo_mcp_elementor_v4_required' );

		$variables = $this->writer()->write( 'variables', 'create-variable', fn() => array(), fn() => array( 'ok' => true ) );
		$this->assertWPError( $variables, 'nexo_mcp_elementor_v4_required' );
		$this->assertSame( array(), $this->store->restored );
		$this->assertNull( $this->backup() );

		$kit = $this->writer()->write( 'kit', 'update-kit', fn() => array(), fn() => array( 'ok' => true ) );
		$this->assertIsArray( $kit );
		$this->assertTrue( $kit['ok'] );
	}

	public function test_un_tipo_desconocido_se_rechaza(): void {
		$this->assertWPError(
			$this->writer()->write( 'pages', 'x', fn() => array(), fn() => array() ),
			'nexo_mcp_design_kind_invalid'
		);
	}

	// ------------------------------------------------------------ Validación

	public function test_si_la_validacion_falla_no_se_escribe_ni_se_pisa_el_respaldo_anterior(): void {
		$previous = $this->previous_backup();

		$result = $this->write_class(
			function () {
				$this->calls[] = 'validate';

				return new \WP_Error( 'nexo_mcp_design_label_taken', 'Ya existe.' );
			}
		);

		$this->assertWPError( $result, 'nexo_mcp_design_label_taken' );
		$this->assertSame( array( 'validate' ), $this->calls );
		$this->assertSame( $previous, $this->backup() );
		$this->assertSame( 0, $this->store->cleared );
		$this->assertSame( array(), $this->audit );
		$this->assertArrayNotHasKey( 'g-b', $this->store->states['classes']['items'] );
	}

	public function test_la_validacion_corre_dentro_del_bloqueo_del_kit(): void {
		$held = null;

		$this->write_class(
			function () use ( &$held ) {
				$held = \WP_Test_State::$options['nexo_mcp_lock_77'] ?? null;

				return array( 'id' => 'g-b' );
			}
		);

		$this->assertIsString( $held );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_77', \WP_Test_State::$options, 'el bloqueo se libera' );
	}

	// ------------------------------------------------------------ Éxito

	public function test_una_escritura_guarda_el_estado_previo_limpia_la_cache_y_queda_en_actividad(): void {
		$before = $this->store->states['classes'];

		$result = $this->write_class();

		$this->assertSame( array( 'id' => 'g-b', 'undo_available' => true ), $result );
		$this->assertSame( array( 'validate', 'apply' ), $this->calls );

		$backup = $this->backup();
		$this->assertNotNull( $backup );
		$this->assertSame( 'classes', $backup['kind'] );
		$this->assertSame( 'create-class', $backup['operation'] );
		$this->assertSame( $before, $backup['state'] );
		$this->assertSame( 5, $backup['user'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $backup['at'] );
		$this->assertFalse( \WP_Test_State::$option_autoload[ DesignSystemWriter::BACKUP_OPTION ] ?? true, 'el respaldo no se autocarga' );

		$this->assertSame( 1, $this->store->cleared );
		$this->assertSame( array(), $this->store->restored );
		$this->assertCount( 1, $this->audit );
		$this->assertSame( 'design-system-create-class', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );
		$this->assertSame( array( 'label' => 'b' ), $this->audit[0][2] );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_77', \WP_Test_State::$options );
	}

	public function test_la_nota_del_respaldo_viaja_con_el_resultado_de_apply(): void {
		$this->writer()->write(
			'classes',
			'delete-class',
			fn() => array(),
			fn() => array(
				'deleted'   => 'g-a',
				'undo_note' => 'Deshacer no devuelve la clase a las páginas.',
			)
		);

		$this->assertSame( 'Deshacer no devuelve la clase a las páginas.', $this->backup()['note'] );
	}

	// ------------------------------------------------------------ Vuelta atrás

	public function test_si_apply_lanza_se_restaura_el_estado_y_el_respaldo_anterior(): void {
		$previous = $this->previous_backup();
		$before   = $this->store->states['classes'];

		$result = $this->write_class(
			null,
			function () {
				$this->store->states['classes']['items']['g-b'] = array( 'label' => 'b' );

				throw new \RuntimeException( 'falló a mitad' );
			}
		);

		$this->assertWPError( $result, 'nexo_mcp_design_write_failed' );
		$this->assertStringContainsString( 'falló a mitad', $result->get_error_message() );
		$this->assertSame( array( array( 'classes', $before ) ), $this->store->restored );
		$this->assertSame( $before, $this->store->states['classes'] );
		$this->assertSame( $previous, $this->backup() );
		$this->assertSame( 1, $this->store->cleared );
		$this->assertSame( 'error', $this->audit[0][1] );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_77', \WP_Test_State::$options );
	}

	public function test_si_apply_devuelve_error_tambien_se_restaura(): void {
		$before = $this->store->states['classes'];

		$result = $this->write_class(
			null,
			function () {
				$this->store->states['classes']['order'][] = 'g-b';

				return new \WP_Error( 'nexo_mcp_design_save_failed', 'Elementor no guardó.' );
			}
		);

		$this->assertWPError( $result, 'nexo_mcp_design_save_failed' );
		$this->assertSame( $before, $this->store->states['classes'] );
		$this->assertNull( $this->backup() );
	}

	public function test_si_tampoco_se_puede_restaurar_el_error_lo_dice(): void {
		$this->store->restore_fails = new \RuntimeException( 'sin base' );

		$result = $this->write_class(
			null,
			function () {
				throw new \RuntimeException( 'falló a mitad' );
			}
		);

		$this->assertWPError( $result, 'nexo_mcp_design_rollback_failed' );
		$data = $result->get_error_data();
		$this->assertSame( 'nexo_mcp_design_write_failed', $data['original_error'] );
		$this->assertSame( 'classes', $this->backup()['kind'], 'el respaldo del estado previo queda para deshacer a mano' );
	}

	// ------------------------------------------------------------ Bloqueo

	public function test_con_el_kit_bloqueado_por_otro_responde_ocupado_sin_escribir(): void {
		PostLock::$wait = 0.0;
		\WP_Test_State::$options['nexo_mcp_lock_77'] = 'otro|' . ( time() + 100 );

		$this->assertWPError( $this->write_class(), 'nexo_mcp_busy' );
		$this->assertSame( array(), $this->calls );
		$this->assertNull( $this->backup() );
	}

	public function test_si_el_bloqueo_se_pierde_antes_de_escribir_no_se_escribe(): void {
		$this->lose_lock_after_acquiring( 77 );

		$this->assertWPError( $this->write_class(), 'nexo_mcp_busy' );
		$this->assertNotContains( 'apply', $this->calls );
		$this->assertNull( $this->backup() );
		$this->assertSame( 0, $this->store->cleared );
	}

	// ------------------------------------------------------------ Deshacer

	public function test_deshacer_sin_respaldo_no_cambia_nada(): void {
		$result = $this->writer()->undo();

		$this->assertWPError( $result, 'nexo_mcp_design_no_backup' );
		$this->assertSame( array(), $this->store->restored );
		$this->assertSame( 0, $this->store->cleared );
		$this->assertSame( array(), $this->audit );
	}

	public function test_deshacer_restaura_el_estado_previo_y_una_segunda_vez_no_cambia_mas(): void {
		$before = $this->store->states['classes'];
		$this->write_class();
		$this->audit = array();

		$result = $this->writer()->undo();

		$this->assertIsArray( $result );
		$this->assertSame( 'classes', $result['kind'] );
		$this->assertSame( 'create-class', $result['operation'] );
		$this->assertArrayHasKey( 'changed_at', $result );
		$this->assertSame( $before, $this->store->states['classes'] );
		$this->assertSame( array( array( 'classes', $before ) ), $this->store->restored );
		$this->assertNull( $this->backup() );
		$this->assertSame( 2, $this->store->cleared );
		$this->assertSame( 'design-system-undo', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );

		$this->assertWPError( $this->writer()->undo(), 'nexo_mcp_design_no_backup' );
		$this->assertCount( 1, $this->store->restored );
	}

	public function test_deshacer_devuelve_la_nota_guardada(): void {
		$this->writer()->write( 'classes', 'delete-class', fn() => array(), fn() => array( 'undo_note' => 'Aviso.' ) );

		$this->assertSame( array( 'Aviso.' ), $this->writer()->undo()['warnings'] );
	}

	public function test_deshacer_clases_sin_editor_v4_no_toca_el_respaldo(): void {
		$this->write_class();
		$this->store->atomic = false;

		$this->assertWPError( $this->writer()->undo(), 'nexo_mcp_elementor_v4_required' );
		$this->assertNotNull( $this->backup() );
	}

	public function test_deshacer_con_el_kit_ocupado_no_toca_nada(): void {
		$this->write_class();
		PostLock::$wait = 0.0;
		\WP_Test_State::$options['nexo_mcp_lock_77'] = 'otro|' . ( time() + 100 );

		$this->assertWPError( $this->writer()->undo(), 'nexo_mcp_busy' );
		$this->assertNotNull( $this->backup() );
		$this->assertSame( array(), $this->store->restored );
	}

	public function test_si_la_restauracion_falla_el_respaldo_se_conserva(): void {
		$this->write_class();
		$this->store->restore_fails = new \RuntimeException( 'sin base' );

		$result = $this->writer()->undo();

		$this->assertWPError( $result, 'nexo_mcp_design_undo_failed' );
		$this->assertNotNull( $this->backup() );
	}

	public function test_un_respaldo_malformado_se_rechaza_sin_restaurar(): void {
		update_option( DesignSystemWriter::BACKUP_OPTION, array( 'kind' => 'pages', 'state' => 'x' ), false );

		$this->assertWPError( $this->writer()->undo(), 'nexo_mcp_design_no_backup' );
		$this->assertSame( array(), $this->store->restored );
	}

	/**
	 * Estados que no tienen la forma que escribe `snapshot()`: nunca deben llegar a `restore()`.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function malformed_states(): array {
		return array(
			'clases sin orden'                 => array( 'classes', array( 'items' => array() ) ),
			'clases con item que no es mapa'   => array( 'classes', array( 'items' => array( 'g-a' => 'x' ), 'order' => array( 'g-a' ) ) ),
			'clases con orden de no cadenas'   => array( 'classes', array( 'items' => array( 'g-a' => array() ), 'order' => array( 5 ) ) ),
			'clases con id de orden ausente'   => array( 'classes', array( 'items' => array( 'g-a' => array() ), 'order' => array( 'g-a', 'g-z' ) ) ),
			'clases con orden que no es lista' => array( 'classes', array( 'items' => array( 'g-a' => array() ), 'order' => array( 'x' => 'g-a' ) ) ),
			'variables sin marca de agua'      => array( 'variables', array( 'data' => array() ) ),
			'variables con marca de texto'     => array( 'variables', array( 'data' => array(), 'watermark' => '3' ) ),
			'variables con entrada escalar'    => array( 'variables', array( 'data' => array( 'e-gv-1' => 'x' ), 'watermark' => 3 ) ),
			'variables con data escalar'       => array( 'variables', array( 'data' => 'x', 'watermark' => 3 ) ),
			'kit con clave ajena'              => array( 'kit', array( 'site_name' => array() ) ),
			'kit con lista escalar'            => array( 'kit', array( 'custom_colors' => 'x' ) ),
		);
	}

	/**
	 * @dataProvider malformed_states
	 *
	 * @param mixed $state Estado malformado.
	 */
	public function test_un_estado_malformado_se_rechaza_sin_restaurar( string $kind, $state ): void {
		$stored = array(
			'kind'      => $kind,
			'operation' => 'x',
			'state'     => $state,
			'at'        => '2026-11-17 10:00:00',
			'user'      => 2,
			'note'      => '',
		);
		update_option( DesignSystemWriter::BACKUP_OPTION, $stored, false );

		$this->assertWPError( $this->writer()->undo(), 'nexo_mcp_design_no_backup' );
		$this->assertSame( array(), $this->store->restored );
		$this->assertSame( 0, $this->store->cleared );
		$this->assertSame( $stored, $this->backup(), 'el respaldo se deja como está' );
	}

	public function test_los_estados_vacios_o_completos_de_cada_tipo_son_validos(): void {
		foreach (
			array(
				array( 'classes', array( 'items' => array(), 'order' => array() ) ),
				array( 'variables', array() ),
				array( 'variables', array( 'data' => array(), 'watermark' => 0, 'version' => 2 ) ),
				array( 'kit', array() ),
				array( 'kit', array( 'system_colors' => array(), 'custom_typography' => array( array( '_id' => 'a' ) ) ) ),
			) as [ $kind, $state ]
		) {
			update_option( DesignSystemWriter::BACKUP_OPTION, array( 'kind' => $kind, 'operation' => 'x', 'state' => $state, 'at' => 'z', 'user' => 1, 'note' => '' ), false );

			$this->assertIsArray( $this->writer()->undo(), $kind );
		}

		$this->assertCount( 5, $this->store->restored );
	}

	public function test_si_no_se_puede_guardar_el_respaldo_no_se_escribe(): void {
		\WP_Test_State::$fail_update_option = DesignSystemWriter::BACKUP_OPTION;

		$result = $this->write_class();

		$this->assertWPError( $result, 'nexo_mcp_design_backup_failed' );
		$this->assertSame( array( 'validate' ), $this->calls );
		$this->assertNull( $this->backup() );
		$this->assertSame( array(), $this->store->restored );
		$this->assertSame( 0, $this->store->cleared );
		$this->assertSame( array(), $this->audit );
		$this->assertArrayNotHasKey( 'g-b', $this->store->states['classes']['items'] );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_77', \WP_Test_State::$options );
	}

	public function test_si_el_respaldo_falla_el_anterior_queda_intacto(): void {
		$previous = $this->previous_backup();
		\WP_Test_State::$fail_update_option = DesignSystemWriter::BACKUP_OPTION;

		$this->assertWPError( $this->write_class(), 'nexo_mcp_design_backup_failed' );
		$this->assertSame( $previous, $this->backup() );
	}

	private function assertWPError( $value, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
