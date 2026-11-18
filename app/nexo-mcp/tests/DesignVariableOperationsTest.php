<?php
/**
 * Variables globales de Elementor: crear, modificar y borrar con validación previa.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DesignSystemStore;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;
use NexoDev\NexoMCP\Builders\DesignVariableOperations;
use NexoDev\NexoMCP\Builders\DesignVariableStore;

/**
 * Almacén de Elementor mínimo para el escritor: sólo cuenta restauraciones y limpiezas de caché.
 */
final class VariableDesignStoreDouble implements DesignSystemStore {

	public bool $atomic = true;

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public array $restored = array();

	public int $cleared = 0;

	/** @var array<string, mixed> */
	public array $snapshot = array( 'data' => array(), 'watermark' => 4 );

	public function available(): bool {
		return true;
	}

	public function atomic(): bool {
		return $this->atomic;
	}

	public function kit_id(): int {
		return 77;
	}

	public function snapshot( string $kind ): array {
		return $this->snapshot;
	}

	public function restore( string $kind, array $state ): void {
		$this->restored[] = array( $kind, $state );
	}

	public function clear_cache(): void {
		++$this->cleared;
	}
}

/**
 * Variables en memoria: registra cada escritura.
 */
final class VariableStoreDouble implements DesignVariableStore {

	/** @var array<string, array{type: string, label: string, value: string, deleted: bool}> */
	public array $records = array();

	/** @var array<string, array<int, array{id: int, title: string}>> */
	public array $used = array();

	/** @var array<int, string> */
	public array $writes = array();

	public int $next = 1;

	public ?\Throwable $fails = null;

	public function variables(): array {
		return $this->records;
	}

	public function create( string $type, string $label, string $value ): array {
		$this->guard();
		$id = 'e-gv-new' . $this->next++;

		$this->records[ $id ] = array(
			'type'    => $type,
			'label'   => $label,
			'value'   => $value,
			'deleted' => false,
		);
		$this->writes[]       = 'create:' . $id;

		return array(
			'id'    => $id,
			'type'  => $type,
			'label' => $label,
			'value' => $value,
		);
	}

	public function update( string $id, array $changes ): array {
		$this->guard();
		$this->records[ $id ] = array_merge( $this->records[ $id ], $changes );
		$this->writes[]       = 'update:' . $id . ':' . implode( ',', array_keys( $changes ) );

		return array(
			'id'    => $id,
			'type'  => $this->records[ $id ]['type'],
			'label' => $this->records[ $id ]['label'],
			'value' => $this->records[ $id ]['value'],
		);
	}

	public function delete( string $id ): void {
		$this->guard();
		$this->records[ $id ]['deleted'] = true;
		$this->writes[]                  = 'delete:' . $id;
	}

	public function used_in( string $id ): array {
		return $this->used[ $id ] ?? array();
	}

	private function guard(): void {
		if ( null !== $this->fails ) {
			throw $this->fails;
		}
	}
}

final class DesignVariableOperationsTest extends NexoTestCase {

	private VariableDesignStoreDouble $design;

	private VariableStoreDouble $vars;

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	protected function setUp(): void {
		parent::setUp();
		$this->design = new VariableDesignStoreDouble();
		$this->vars   = new VariableStoreDouble();
		$this->audit  = array();
		\WP_Test_State::$user_id = 5;

		$this->vars->records = array(
			'e-gv-red'  => array( 'type' => 'global-color-variable', 'label' => 'Rojo', 'value' => '#ff0000', 'deleted' => false ),
			'e-gv-gone' => array( 'type' => 'global-color-variable', 'label' => 'Viejo', 'value' => '#000000', 'deleted' => true ),
			'e-gv-gap'  => array( 'type' => 'global-size-variable', 'label' => 'Gap', 'value' => '16px', 'deleted' => false ),
			'e-gv-fl'   => array( 'type' => 'global-custom-size-variable', 'label' => 'Fluida', 'value' => 'clamp(1rem, 2vw, 3rem)', 'deleted' => false ),
			'e-gv-ff'   => array( 'type' => 'global-font-variable', 'label' => 'Titulos', 'value' => 'Open Sans, sans-serif', 'deleted' => false ),
		);
	}

	private function ops(): DesignVariableOperations {
		$writer = new DesignSystemWriter(
			$this->design,
			function ( string $tool, string $status, $params, $result ): void {
				$this->audit[] = array( $tool, $status, $params, $result );
			}
		);

		return new DesignVariableOperations( $writer, $this->vars );
	}

	private function backup(): ?array {
		$value = \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ] ?? null;

		return is_array( $value ) ? $value : null;
	}

	private function assertRejected( $result, string $code, ?string $field = null ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		if ( null !== $field ) {
			$this->assertSame( array( 'field' => $field ), $result->get_error_data() );
		}
	}

	private function assertNothingWritten( array $records_before ): void {
		$this->assertSame( $records_before, $this->vars->records );
		$this->assertSame( array(), $this->vars->writes );
		$this->assertNull( $this->backup() );
		$this->assertSame( array(), $this->audit );
		$this->assertSame( 0, $this->design->cleared );
		$this->assertSame( array(), $this->design->restored );
	}

	// ------------------------------------------------------------------ Crear

	/** @return array<string, array{0: string, 1: string}> */
	public static function valid_values(): array {
		return array(
			'color hex'          => array( 'global-color-variable', '#ff0000' ),
			'color hex corto'    => array( 'global-color-variable', '#f00' ),
			'color hex con alfa' => array( 'global-color-variable', '#ff000080' ),
			'color rgb'          => array( 'global-color-variable', 'rgb(255, 0, 0)' ),
			'color rgba'         => array( 'global-color-variable', 'rgba(255,0,0,0.5)' ),
			'color rgb moderno'  => array( 'global-color-variable', 'rgb(255 0 0 / 50%)' ),
			'color hsl'          => array( 'global-color-variable', 'hsl(120, 50%, 50%)' ),
			'color hsla'         => array( 'global-color-variable', 'hsla(120deg, 50%, 50%, .5)' ),
			'fuente'             => array( 'global-font-variable', 'Open Sans' ),
			'fuente con familia' => array( 'global-font-variable', '"Playfair Display", Georgia, serif' ),
			'tamaño px'          => array( 'global-size-variable', '16px' ),
			'tamaño decimal'     => array( 'global-size-variable', '1.5rem' ),
			'tamaño sin entero'  => array( 'global-size-variable', '.5em' ),
			'tamaño negativo'    => array( 'global-size-variable', '-10px' ),
			'tamaño porcentaje'  => array( 'global-size-variable', '50%' ),
			'tamaño cero'        => array( 'global-size-variable', '0px' ),
			'custom clamp'       => array( 'global-custom-size-variable', 'clamp(1rem, 2vw + 1rem, 3rem)' ),
			'custom calc'        => array( 'global-custom-size-variable', 'calc(100% - 2 * 10px)' ),
		);
	}

	/**
	 * @dataProvider valid_values
	 */
	public function test_crear_acepta_valores_validos_de_cada_tipo( string $type, string $value ): void {
		$result = $this->ops()->create( array( 'type' => $type, 'label' => 'nueva', 'value' => $value ) );

		$this->assertSame(
			array(
				'id'             => 'e-gv-new1',
				'type'           => $type,
				'label'          => 'nueva',
				'value'          => $value,
				'undo_available' => true,
			),
			$result
		);
		$this->assertSame( array( 'create:e-gv-new1' ), $this->vars->writes );
		$this->assertSame( array( 'type' => $type, 'label' => 'nueva', 'value' => $value, 'deleted' => false ), $this->vars->records['e-gv-new1'] );
	}

	public function test_crear_respalda_registra_en_actividad_y_limpia_la_cache(): void {
		$this->ops()->create( array( 'type' => 'global-font-variable', 'label' => 'cuerpo', 'value' => 'Lato' ) );

		$backup = $this->backup();
		$this->assertNotNull( $backup );
		$this->assertSame( 'variables', $backup['kind'] );
		$this->assertSame( 'create-variable', $backup['operation'] );
		$this->assertSame( $this->design->snapshot, $backup['state'] );
		$this->assertSame( 1, $this->design->cleared );
		$this->assertCount( 1, $this->audit );
		$this->assertSame( 'design-system-create-variable', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );
		$this->assertSame( array( 'type' => 'global-font-variable', 'label' => 'cuerpo', 'value' => 'Lato' ), $this->audit[0][2] );
	}

	public function test_crear_recorta_los_espacios_del_valor(): void {
		$result = $this->ops()->create( array( 'type' => 'global-color-variable', 'label' => 'azul', 'value' => "  #0000ff \n" ) );

		$this->assertSame( '#0000ff', $result['value'] );
		$this->assertSame( '#0000ff', $this->vars->records['e-gv-new1']['value'] );
	}

	public function test_crear_acepta_label_de_50_y_valor_de_512(): void {
		$label = str_repeat( 'a', 50 );
		$value = str_repeat( 'a', 512 );

		$result = $this->ops()->create( array( 'type' => 'global-font-variable', 'label' => $label, 'value' => $value ) );

		$this->assertIsArray( $result );
		$this->assertSame( $label, $result['label'] );
		$this->assertSame( $value, $result['value'] );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public static function invalid_creations(): array {
		$ok = array( 'type' => 'global-color-variable', 'label' => 'nueva', 'value' => '#fff' );

		return array(
			'sin tipo'                  => array( array( 'label' => 'x', 'value' => '#fff' ), 'type' ),
			'tipo desconocido'          => array( array_merge( $ok, array( 'type' => 'global-number-variable' ) ), 'type' ),
			'tipo no es texto'          => array( array_merge( $ok, array( 'type' => 5 ) ), 'type' ),
			'sin label'                 => array( array( 'type' => 'global-color-variable', 'value' => '#fff' ), 'label' ),
			'label vacío'               => array( array_merge( $ok, array( 'label' => '' ) ), 'label' ),
			'label con espacio'         => array( array_merge( $ok, array( 'label' => 'mi color' ) ), 'label' ),
			'label con espacio al borde' => array( array_merge( $ok, array( 'label' => 'color ' ) ), 'label' ),
			'label de 51'               => array( array_merge( $ok, array( 'label' => str_repeat( 'a', 51 ) ) ), 'label' ),
			'label no es texto'         => array( array_merge( $ok, array( 'label' => 7 ) ), 'label' ),
			'sin valor'                 => array( array( 'type' => 'global-color-variable', 'label' => 'x' ), 'value' ),
			'valor vacío'               => array( array_merge( $ok, array( 'value' => '' ) ), 'value' ),
			'valor en blanco'           => array( array_merge( $ok, array( 'value' => '   ' ) ), 'value' ),
			'valor no es texto'         => array( array_merge( $ok, array( 'value' => array( '#fff' ) ) ), 'value' ),
			'valor de 513'              => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => str_repeat( 'a', 513 ) ), 'value' ),
			'color con nombre'          => array( array_merge( $ok, array( 'value' => 'red' ) ), 'value' ),
			'color hex de 5'            => array( array_merge( $ok, array( 'value' => '#12345' ) ), 'value' ),
			'color con ;'               => array( array_merge( $ok, array( 'value' => 'rgb(0,0,0);' ) ), 'value' ),
			'color con llaves'          => array( array_merge( $ok, array( 'value' => '#fff{}' ) ), 'value' ),
			'color con url'             => array( array_merge( $ok, array( 'value' => 'url(x)' ) ), 'value' ),
			'color con var'             => array( array_merge( $ok, array( 'value' => 'var(--x)' ) ), 'value' ),
			'color con expression'      => array( array_merge( $ok, array( 'value' => 'expression(alert(1))' ) ), 'value' ),
			'color rgb con var'         => array( array_merge( $ok, array( 'value' => 'rgb(var(--r), 0, 0)' ) ), 'value' ),
			'color rgb con letras'      => array( array_merge( $ok, array( 'value' => 'rgb(a, b, c)' ) ), 'value' ),
			'color rgb sin cerrar'      => array( array_merge( $ok, array( 'value' => 'rgb(1, 2, 3' ) ), 'value' ),
			'color con @'               => array( array_merge( $ok, array( 'value' => '@import' ) ), 'value' ),
			'fuente con ;'              => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => 'Arial; color: red' ), 'value' ),
			'fuente con llaves'         => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => 'Arial}' ), 'value' ),
			'fuente con <'              => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => '<b>Arial' ), 'value' ),
			'fuente con url'            => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => 'url(http://x/f.woff)' ), 'value' ),
			'fuente con var'            => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => 'var(--f)' ), 'value' ),
			'fuente con barra'          => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => 'Arial\\' ), 'value' ),
			'fuente con comilla suelta' => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => '"Arial' ), 'value' ),
			'fuente sin nombre'         => array( array( 'type' => 'global-font-variable', 'label' => 'x', 'value' => ' , ' ), 'value' ),
			'tamaño sin unidad'         => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10' ), 'value' ),
			'tamaño con espacio'        => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10 px' ), 'value' ),
			'tamaño auto'               => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => 'auto' ), 'value' ),
			'tamaño punto final'        => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '5.px' ), 'value' ),
			'tamaño unidad inválida'    => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10foo' ), 'value' ),
			'tamaño unidad custom'      => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10custom' ), 'value' ),
			'tamaño en mayúsculas'      => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10PX' ), 'value' ),
			'tamaño con calc'           => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => 'calc(1px + 2px)' ), 'value' ),
			'tamaño con ;'              => array( array( 'type' => 'global-size-variable', 'label' => 'x', 'value' => '10px;' ), 'value' ),
			'custom con ;'              => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '1px; color: red' ), 'value' ),
			'custom con llaves'         => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '1px}' ), 'value' ),
			'custom con url'            => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => 'url(x)' ), 'value' ),
			'custom con var'            => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => 'calc(var(--a) * 2)' ), 'value' ),
			'custom con @'              => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '@x' ), 'value' ),
			'custom con !important'     => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '1px !important' ), 'value' ),
			'custom con comentario'     => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '1px /* x */' ), 'value' ),
			'custom con salto de línea' => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => "1px\n2px" ), 'value' ),
			'custom con paréntesis'     => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => 'calc(1px + 2px' ), 'value' ),
			'custom con comilla suelta' => array( array( 'type' => 'global-custom-size-variable', 'label' => 'x', 'value' => '"1px' ), 'value' ),
		);
	}

	/**
	 * @dataProvider invalid_creations
	 *
	 * @param array<string, mixed> $params Parámetros.
	 */
	public function test_crear_rechaza_lo_invalido_sin_escribir_nada( array $params, string $field ): void {
		$before = $this->vars->records;

		$result = $this->ops()->create( $params );

		$this->assertRejected( $result, 'nexo_mcp_design_variable_invalid', $field );
		$this->assertNothingWritten( $before );
	}

	public function test_crear_con_label_repetido_sin_distinguir_mayusculas(): void {
		$before = $this->vars->records;

		$result = $this->ops()->create( array( 'type' => 'global-color-variable', 'label' => 'ROJO', 'value' => '#fff' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_label_taken', 'label' );
		$this->assertSame( 'Ya existe una variable con la etiqueta «ROJO». Elegí otra.', $result->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_crear_puede_reusar_el_label_de_una_variable_borrada(): void {
		$result = $this->ops()->create( array( 'type' => 'global-color-variable', 'label' => 'viejo', 'value' => '#fff' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'viejo', $result['label'] );
		$this->assertSame( array( 'create:e-gv-new1' ), $this->vars->writes );
	}

	public function test_crear_se_rechaza_con_1000_activas_pero_las_borradas_no_cuentan(): void {
		$this->vars->records = array();

		for ( $i = 1; $i <= 1000; $i++ ) {
			$this->vars->records[ 'e-gv-' . $i ] = array( 'type' => 'global-color-variable', 'label' => 'v' . $i, 'value' => '#fff', 'deleted' => $i <= 3 );
		}

		// 997 activas + 3 borradas: caben tres más.
		$params = array( 'type' => 'global-color-variable', 'label' => 'extra', 'value' => '#fff' );
		$this->assertIsArray( $this->ops()->create( $params ) );
		$this->assertIsArray( $this->ops()->create( array_merge( $params, array( 'label' => 'extra2' ) ) ) );
		$this->assertIsArray( $this->ops()->create( array_merge( $params, array( 'label' => 'extra3' ) ) ) );

		$before        = $this->vars->records;
		$writes        = $this->vars->writes;
		$audit         = $this->audit;
		$backup_before = $this->backup();

		$result = $this->ops()->create( array_merge( $params, array( 'label' => 'extra4' ) ) );

		$this->assertRejected( $result, 'nexo_mcp_design_limit_reached' );
		$this->assertSame( 'Ya hay 1000 variables activas, que es el máximo de Elementor. Borrá alguna antes de crear otra.', $result->get_error_message() );
		$this->assertSame( $before, $this->vars->records );
		$this->assertSame( $writes, $this->vars->writes );
		$this->assertSame( $audit, $this->audit );
		$this->assertSame( $backup_before, $this->backup() );
	}

	public function test_si_escribir_falla_se_restaura_y_no_queda_respaldo(): void {
		$this->vars->fails = new \RuntimeException( 'Elementor no pudo guardar' );
		$before            = $this->vars->records;

		$result = $this->ops()->create( array( 'type' => 'global-color-variable', 'label' => 'nueva', 'value' => '#fff' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_write_failed' );
		$this->assertSame( 'Elementor no pudo guardar', $result->get_error_message() );
		$this->assertSame( $before, $this->vars->records );
		$this->assertSame( array( array( 'variables', $this->design->snapshot ) ), $this->design->restored );
		$this->assertNull( $this->backup() );
		$this->assertSame( 'error', $this->audit[0][1] );
	}

	public function test_sin_editor_v4_no_se_escribe_nada(): void {
		$this->design->atomic = false;
		$before               = $this->vars->records;

		$result = $this->ops()->create( array( 'type' => 'global-color-variable', 'label' => 'nueva', 'value' => '#fff' ) );

		$this->assertRejected( $result, 'nexo_mcp_elementor_v4_required' );
		$this->assertNothingWritten( $before );
	}

	// -------------------------------------------------------------- Modificar

	public function test_modificar_label_y_valor(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'Rojo-fuerte', 'value' => '#cc0000' ) );

		$this->assertSame(
			array(
				'id'             => 'e-gv-red',
				'type'           => 'global-color-variable',
				'label'          => 'Rojo-fuerte',
				'value'          => '#cc0000',
				'undo_available' => true,
			),
			$result
		);
		$this->assertSame( array( 'update:e-gv-red:label,value' ), $this->vars->writes );
		$this->assertSame( 'update-variable', $this->backup()['operation'] ?? null );
		$this->assertSame( 'design-system-update-variable', $this->audit[0][0] );
		$this->assertSame( 1, $this->design->cleared );
	}

	public function test_modificar_solo_envia_lo_que_cambia(): void {
		$this->ops()->update( array( 'id' => 'e-gv-red', 'value' => '#cc0000' ) );

		$this->assertSame( array( 'update:e-gv-red:value' ), $this->vars->writes );
		$this->assertSame( 'Rojo', $this->vars->records['e-gv-red']['label'] );
	}

	public function test_modificar_acepta_conservar_su_propio_label_o_cambiar_mayusculas(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'rojo' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'rojo', $result['label'] );
	}

	public function test_modificar_con_label_de_otra_variable_se_rechaza(): void {
		$before = $this->vars->records;

		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'GAP' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_label_taken', 'label' );
		$this->assertSame( 'Ya existe una variable con la etiqueta «GAP». Elegí otra.', $result->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_modificar_puede_tomar_el_label_de_una_borrada(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'viejo' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'viejo', $result['label'] );
	}

	public function test_modificar_tamano_a_custom_con_nuevo_valor(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-gap', 'type' => 'global-custom-size-variable', 'value' => 'calc(1rem + 2px)' ) );

		$this->assertSame( 'global-custom-size-variable', $result['type'] );
		$this->assertSame( 'calc(1rem + 2px)', $result['value'] );
		$this->assertSame( array( 'update:e-gv-gap:type,value' ), $this->vars->writes );
	}

	public function test_modificar_tamano_a_custom_conservando_un_valor_que_sigue_siendo_valido(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-gap', 'type' => 'global-custom-size-variable' ) );

		$this->assertSame( 'global-custom-size-variable', $result['type'] );
		$this->assertSame( '16px', $result['value'] );
		$this->assertSame( array( 'update:e-gv-gap:type' ), $this->vars->writes );
	}

	public function test_modificar_custom_a_tamano_con_valor_valido(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-fl', 'type' => 'global-size-variable', 'value' => '2rem' ) );

		$this->assertSame( 'global-size-variable', $result['type'] );
		$this->assertSame( '2rem', $result['value'] );
	}

	public function test_modificar_custom_a_tamano_sin_valor_nuevo_falla_si_el_actual_no_es_un_tamano(): void {
		$before = $this->vars->records;

		$result = $this->ops()->update( array( 'id' => 'e-gv-fl', 'type' => 'global-size-variable' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertNothingWritten( $before );
	}

	public function test_modificar_el_tipo_a_otro_que_no_es_de_tamano_se_rechaza(): void {
		$before = $this->vars->records;

		foreach (
			array(
				array( 'e-gv-red', 'global-font-variable', 'Lato' ),
				array( 'e-gv-red', 'global-size-variable', '10px' ),
				array( 'e-gv-gap', 'global-color-variable', '#fff' ),
				array( 'e-gv-ff', 'global-custom-size-variable', '1px' ),
			) as list( $id, $type, $value )
		) {
			$result = $this->ops()->update( array( 'id' => $id, 'type' => $type, 'value' => $value ) );

			$this->assertRejected( $result, 'nexo_mcp_design_variable_invalid', 'type' );
		}

		$this->assertNothingWritten( $before );
	}

	public function test_modificar_con_el_mismo_tipo_es_valido(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'type' => 'global-color-variable', 'value' => '#111' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'update:e-gv-red:value' ), $this->vars->writes );
	}

	public function test_modificar_con_los_mismos_valores_no_toca_elementor(): void {
		$result = $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'Rojo', 'value' => '#ff0000', 'type' => 'global-color-variable' ) );

		$this->assertSame(
			array(
				'id'             => 'e-gv-red',
				'type'           => 'global-color-variable',
				'label'          => 'Rojo',
				'value'          => '#ff0000',
				'undo_available' => true,
			),
			$result
		);
		$this->assertSame( array(), $this->vars->writes );
	}

	public function test_modificar_valida_el_valor_con_el_tipo_de_la_variable(): void {
		$before = $this->vars->records;

		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'value' => '10px' ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-gap', 'value' => 'calc(1px + 1px)' ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-ff', 'value' => 'a;b' ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-fl', 'value' => 'url(x)' ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertNothingWritten( $before );
	}

	public function test_modificar_valida_label_y_formato_de_los_campos(): void {
		$before = $this->vars->records;

		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => 'dos palabras' ) ), 'nexo_mcp_design_variable_invalid', 'label' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'label' => str_repeat( 'a', 51 ) ) ), 'nexo_mcp_design_variable_invalid', 'label' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'value' => '' ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'value' => str_repeat( '1', 513 ) ) ), 'nexo_mcp_design_variable_invalid', 'value' );
		$this->assertRejected( $this->ops()->update( array( 'id' => 'e-gv-red', 'type' => 'desconocido' ) ), 'nexo_mcp_design_variable_invalid', 'type' );
		$this->assertNothingWritten( $before );
	}

	public function test_modificar_sin_ningun_cambio_se_rechaza(): void {
		$before = $this->vars->records;

		$result = $this->ops()->update( array( 'id' => 'e-gv-red' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_variable_invalid', 'id' );
		$this->assertSame( 'Indicá qué cambiar: label, value o type.', $result->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_modificar_o_borrar_un_id_inexistente_o_borrado_da_not_found(): void {
		$before = $this->vars->records;

		foreach ( array( 'e-gv-nope', 'e-gv-gone', '' ) as $id ) {
			$update = $this->ops()->update( array( 'id' => $id, 'label' => 'otro' ) );
			$delete = $this->ops()->delete( array( 'id' => $id ) );

			foreach ( array( $update, $delete ) as $result ) {
				$this->assertRejected( $result, 'nexo_mcp_design_not_found' );
				$this->assertSame( array( 'id' => $id ), $result->get_error_data() );
			}
		}

		$this->assertSame( 'No existe una variable con el id «e-gv-nope» (o ya está borrada).', $this->ops()->delete( array( 'id' => 'e-gv-nope' ) )->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_id_que_no_es_texto_da_not_found(): void {
		$before = $this->vars->records;

		$this->assertRejected( $this->ops()->update( array( 'id' => 5, 'label' => 'otro' ) ), 'nexo_mcp_design_not_found' );
		$this->assertRejected( $this->ops()->delete( array() ), 'nexo_mcp_design_not_found' );
		$this->assertNothingWritten( $before );
	}

	// ----------------------------------------------------------------- Borrar

	public function test_borrar_una_variable_sin_uso(): void {
		$result = $this->ops()->delete( array( 'id' => 'e-gv-red' ) );

		$this->assertSame(
			array(
				'deleted'        => 'e-gv-red',
				'pages_affected' => 0,
				'undo_available' => true,
			),
			$result
		);
		$this->assertSame( array( 'delete:e-gv-red' ), $this->vars->writes );
		$this->assertTrue( $this->vars->records['e-gv-red']['deleted'] );
		$this->assertSame( 'delete-variable', $this->backup()['operation'] ?? null );
		$this->assertSame( '', $this->backup()['note'] ?? null );
		$this->assertSame( 'design-system-delete-variable', $this->audit[0][0] );
		$this->assertSame( 1, $this->design->cleared );
	}

	public function test_borrar_en_uso_sin_force_se_rechaza_con_la_lista_y_no_escribe(): void {
		$this->vars->used['e-gv-red'] = array(
			array( 'id' => 12, 'title' => 'Inicio' ),
			array( 'id' => 9, 'title' => 'Kit predeterminado' ),
		);
		$before                       = $this->vars->records;

		$result = $this->ops()->delete( array( 'id' => 'e-gv-red' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_in_use' );
		$this->assertSame( array( 'used_in' => $this->vars->used['e-gv-red'] ), $result->get_error_data() );
		$this->assertSame( 'No se borró la variable «Rojo»: se usa en 2 contenidos. Pasá force: true para borrarla igual.', $result->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_borrar_en_uso_con_force_false_tambien_se_rechaza(): void {
		$this->vars->used['e-gv-red'] = array( array( 'id' => 12, 'title' => 'Inicio' ) );
		$before                       = $this->vars->records;

		$result = $this->ops()->delete( array( 'id' => 'e-gv-red', 'force' => false ) );

		$this->assertRejected( $result, 'nexo_mcp_design_in_use' );
		$this->assertSame( 'No se borró la variable «Rojo»: se usa en 1 contenido. Pasá force: true para borrarla igual.', $result->get_error_message() );
		$this->assertNothingWritten( $before );
	}

	public function test_borrar_en_uso_con_force_borra_y_deja_la_nota_en_el_respaldo(): void {
		$this->vars->used['e-gv-red'] = array(
			array( 'id' => 12, 'title' => 'Inicio' ),
			array( 'id' => 9, 'title' => 'Kit predeterminado' ),
		);

		$result = $this->ops()->delete( array( 'id' => 'e-gv-red', 'force' => true ) );

		$this->assertSame(
			array(
				'deleted'        => 'e-gv-red',
				'pages_affected' => 2,
				'undo_available' => true,
			),
			$result
		);
		$this->assertTrue( $this->vars->records['e-gv-red']['deleted'] );
		$this->assertSame(
			'Las páginas que usaban la variable conservan una referencia a una variable borrada hasta que se deshaga el cambio: deshacer la restaura.',
			$this->backup()['note'] ?? null
		);
		$this->assertSame( array( 'delete:e-gv-red' ), $this->vars->writes );
	}

	public function test_borrar_con_force_que_no_es_booleano_se_rechaza(): void {
		$before = $this->vars->records;

		$result = $this->ops()->delete( array( 'id' => 'e-gv-red', 'force' => 'si' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_variable_invalid', 'force' );
		$this->assertNothingWritten( $before );
	}

	public function test_borrar_falla_a_mitad_restaura_y_no_deja_respaldo(): void {
		$this->vars->fails = new \RuntimeException( 'Elementor no pudo borrar' );
		$before            = $this->vars->records;

		$result = $this->ops()->delete( array( 'id' => 'e-gv-red' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_write_failed' );
		$this->assertSame( $before, $this->vars->records );
		$this->assertSame( array( array( 'variables', $this->design->snapshot ) ), $this->design->restored );
		$this->assertNull( $this->backup() );
	}
}
