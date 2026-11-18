<?php
/**
 * Operaciones sobre clases globales de Elementor (crear, modificar, borrar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DesignClassBackend;
use NexoDev\NexoMCP\Builders\DesignClassOperations;
use NexoDev\NexoMCP\Builders\DesignSystemStore;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;

/**
 * Mundo en memoria: hace de almacén del escritor y de acceso a Elementor a la vez.
 */
final class FakeClassWorld implements DesignSystemStore, DesignClassBackend {

	/** @var array<string, array<string, mixed>> */
	public array $items = array();

	/** @var array<int, string> */
	public array $order = array();

	/** @var array<string, array<int, array{id: int, title: string}>> */
	public array $usage = array();

	/** @var array<int, array{0: array, 1: array, 2: array}> */
	public array $writes = array();

	public ?\Throwable $write_fails = null;

	public int $cleared = 0;

	public function available(): bool {
		return true;
	}

	public function atomic(): bool {
		return true;
	}

	public function kit_id(): int {
		return 77;
	}

	public function snapshot( string $kind ): array {
		return array(
			'items' => $this->items,
			'order' => $this->order,
		);
	}

	public function restore( string $kind, array $state ): void {
		$this->items = $state['items'];
		$this->order = $state['order'];
	}

	public function clear_cache(): void {
		++$this->cleared;
	}

	public function style_props(): array {
		return array( 'color', 'gap', 'padding' );
	}

	/**
	 * Parser de Elementor simulado: rechaza `gap` con valor «malo» y devuelve el estilo tal cual.
	 */
	public function parse_style( array $style ) {
		foreach ( $style['variants'] as $index => $variant ) {
			if ( 'malo' === ( $variant['props']['gap']['value'] ?? null ) ) {
				return new \WP_Error(
					'nexo_mcp_elementor_style_rejected',
					'variants[' . $index . '].props.gap: Elementor rechazó el valor (invalid_value).',
					array( 'path' => 'variants[' . $index . '].props.gap' )
				);
			}
		}

		$style['parsed'] = true;

		return $style;
	}

	public function read(): array {
		return array(
			'items' => $this->items,
			'order' => $this->order,
		);
	}

	public function used_in( string $id ): array {
		return $this->usage[ $id ] ?? array();
	}

	public function write( array $touched, array $changes, array $order ): void {
		if ( null !== $this->write_fails ) {
			throw $this->write_fails;
		}

		$this->writes[] = array( $touched, $changes, $order );

		foreach ( $touched as $id => $item ) {
			$this->items[ $id ] = $item;
		}

		foreach ( $changes['deleted'] ?? array() as $id ) {
			unset( $this->items[ $id ] );
		}

		$this->order = $order;
	}
}

final class DesignClassOperationsTest extends NexoTestCase {

	private FakeClassWorld $world;

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	/** @var array<int, string> */
	private array $ids = array();

	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::$user_id = 5;
		$this->audit             = array();
		$this->ids               = array();
		$this->world             = new FakeClassWorld();
		$this->world->items      = array(
			'g-hero' => $this->item( 'g-hero', 'Hero' ),
			'g-card' => $this->item( 'g-card', 'card_box' ),
		);
		$this->world->order      = array( 'g-card', 'g-hero' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function variant( string $color = '#ff0000', string $breakpoint = 'desktop', ?string $state = null ): array {
		return array(
			'meta'  => array(
				'breakpoint' => $breakpoint,
				'state'      => $state,
			),
			'props' => array(
				'color' => array(
					'$$type' => 'color',
					'value'  => $color,
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function item( string $id, string $label ): array {
		return array(
			'id'       => $id,
			'type'     => 'class',
			'label'    => $label,
			'variants' => array( $this->variant( '#000000' ) ),
		);
	}

	private function ops(): DesignClassOperations {
		$writer = new DesignSystemWriter(
			$this->world,
			function ( string $tool, string $status, $params, $result ): void {
				$this->audit[] = array( $tool, $status, $params, $result );
			}
		);

		return new DesignClassOperations(
			$writer,
			$this->world,
			function (): string {
				return array() === $this->ids ? 'g-abc1234' : array_shift( $this->ids );
			}
		);
	}

	private function backup(): ?array {
		$value = \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ] ?? null;

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Respaldo previo que una escritura rechazada no debe tocar.
	 *
	 * @return array<string, mixed>
	 */
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

	/**
	 * Comprueba un rechazo: error exacto, nada escrito, respaldo intacto.
	 *
	 * @param mixed                     $result   Resultado de la operación.
	 * @param array<string, mixed>|null $data     Datos exactos del error (null = no se comprueban).
	 * @param array<string, mixed>      $previous Respaldo previo.
	 */
	private function assertRejected( $result, string $code, ?string $message, ?array $data, array $previous ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		if ( null !== $message ) {
			$this->assertSame( $message, $result->get_error_message() );
		}

		if ( null !== $data ) {
			$this->assertSame( $data, $result->get_error_data() );
		}

		$this->assertSame( array(), $this->world->writes, 'no se escribió nada' );
		$this->assertSame( $previous, $this->backup(), 'el respaldo no cambia' );
		$this->assertSame( 0, $this->world->cleared );
		$this->assertSame( array(), $this->audit );
	}

	// ------------------------------------------------------------ Crear

	public function test_crear_escribe_solo_la_clase_nueva_al_final_del_orden(): void {
		$before = $this->world->snapshot( 'classes' );

		$result = $this->ops()->create(
			array(
				'label'    => 'boton-primario',
				'variants' => array( $this->variant( '#00ff00' ) ),
			)
		);

		$this->assertSame(
			array(
				'id'             => 'g-abc1234',
				'label'          => 'boton-primario',
				'undo_available' => true,
			),
			$result
		);

		$this->assertCount( 1, $this->world->writes );
		list( $touched, $changes, $order ) = $this->world->writes[0];
		$this->assertSame( array( 'g-abc1234' ), array_keys( $touched ) );
		$this->assertSame( 'g-abc1234', $touched['g-abc1234']['id'] );
		$this->assertSame( 'class', $touched['g-abc1234']['type'] );
		$this->assertSame( 'boton-primario', $touched['g-abc1234']['label'] );
		$this->assertSame( array( $this->variant( '#00ff00' ) ), $touched['g-abc1234']['variants'] );
		$this->assertTrue( $touched['g-abc1234']['parsed'], 'lo escrito es lo que devolvió el parser de Elementor' );
		$this->assertSame(
			array(
				'added'    => array( 'g-abc1234' ),
				'deleted'  => array(),
				'modified' => array(),
				'order'    => true,
			),
			$changes
		);
		$this->assertSame( array( 'g-card', 'g-hero', 'g-abc1234' ), $order );

		$backup = $this->backup();
		$this->assertNotNull( $backup );
		$this->assertSame( 'classes', $backup['kind'] );
		$this->assertSame( 'create-class', $backup['operation'] );
		$this->assertSame( $before, $backup['state'] );
		$this->assertSame( 1, $this->world->cleared );
		$this->assertSame( 'design-system-create-class', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );
	}

	public function test_el_id_generado_nunca_choca_con_uno_existente(): void {
		$this->ids = array( 'g-hero', 'g-card', 'g-nuevo99' );

		$result = $this->ops()->create( array( 'label' => 'otra', 'variants' => array() ) );

		$this->assertSame( 'g-nuevo99', $result['id'] );
		$this->assertSame( array( 'g-nuevo99' ), array_keys( $this->world->writes[0][0] ) );
	}

	public function test_el_id_generado_por_omision_tiene_el_formato_de_elementor(): void {
		$writer = new DesignSystemWriter( $this->world, static function (): void {} );
		$result = ( new DesignClassOperations( $writer, $this->world ) )->create( array( 'label' => 'otra', 'variants' => array() ) );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^g-[0-9a-f]{7}$/', $result['id'] );
		$this->assertSame( $result['id'], end( $this->world->order ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function labels_invalidos(): array {
		return array(
			'vacío'            => array( '' ),
			'empieza con dígito' => array( '1caja' ),
			'con espacio'      => array( 'mi clase' ),
			'con punto'        => array( 'mi.clase' ),
			'con acento'       => array( 'caja-ñ' ),
			'51 caracteres'    => array( str_repeat( 'a', 51 ) ),
			'no es texto'      => array( 12 ),
			'nulo'             => array( null ),
		);
	}

	/**
	 * @dataProvider labels_invalidos
	 * @param mixed $label Label recibido.
	 */
	public function test_crear_rechaza_un_label_con_formato_invalido( $label ): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->create( array( 'label' => $label, 'variants' => array() ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_label_invalid',
			'label: debe empezar con una letra, usar sólo letras, números, guiones y guiones bajos, y tener como máximo 50 caracteres.',
			array( 'path' => 'label' ),
			$previous
		);
	}

	public function test_un_label_de_50_caracteres_y_con_guiones_se_acepta(): void {
		$label  = 'a' . str_repeat( '-_9Z', 12 ) . 'b';
		$result = $this->ops()->create( array( 'label' => $label, 'variants' => array() ) );

		$this->assertSame( 50, strlen( $label ) );
		$this->assertSame( $label, $result['label'] );
	}

	public function test_crear_rechaza_un_label_repetido_sin_distinguir_mayusculas(): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->create( array( 'label' => 'HERO', 'variants' => array() ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_label_taken',
			'label: ya existe una clase llamada «Hero» (g-hero). Elegí otro nombre: no se renombra solo.',
			array( 'path' => 'label', 'id' => 'g-hero' ),
			$previous
		);
	}

	public function test_crear_rechaza_la_clase_numero_1001(): void {
		$this->world->items = array();
		$this->world->order = array();

		for ( $i = 0; $i < 1000; $i++ ) {
			$this->world->items[ 'g-' . $i ] = $this->item( 'g-' . $i, 'c' . $i );
			$this->world->order[]            = 'g-' . $i;
		}

		$previous = $this->previous_backup();
		$result   = $this->ops()->create( array( 'label' => 'una-mas', 'variants' => array() ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_limit_reached',
			'Ya hay 1000 clases globales, el máximo. Borrá alguna antes de crear otra.',
			array( 'max' => 1000 ),
			$previous
		);
	}

	public function test_crear_acepta_exactamente_la_clase_numero_1000(): void {
		$this->world->items = array();
		$this->world->order = array();

		for ( $i = 0; $i < 999; $i++ ) {
			$this->world->items[ 'g-' . $i ] = $this->item( 'g-' . $i, 'c' . $i );
			$this->world->order[]            = 'g-' . $i;
		}

		$this->assertIsArray( $this->ops()->create( array( 'label' => 'ultima', 'variants' => array() ) ) );
		$this->assertCount( 1000, $this->world->order );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
	 */
	public static function parametros_invalidos(): array {
		return array(
			'sin variants'      => array( array( 'label' => 'x' ), 'nexo_mcp_design_params_invalid', 'variants: indicá la lista de variantes (puede ir vacía).', 'variants' ),
			'variants objeto'   => array( array( 'label' => 'x', 'variants' => array( 'a' => 1 ) ), 'nexo_mcp_design_params_invalid', 'variants: debe ser una lista de variantes.', 'variants' ),
			'clave desconocida' => array( array( 'label' => 'x', 'variants' => array(), 'color' => 'rojo' ), 'nexo_mcp_design_params_invalid', 'color: parámetro desconocido. Admitidos: label, variants.', 'color' ),
		);
	}

	/**
	 * @dataProvider parametros_invalidos
	 * @param array<string, mixed> $params  Parámetros.
	 * @param string               $code    Código esperado.
	 * @param string               $message Mensaje esperado.
	 * @param string               $path    Ruta esperada.
	 */
	public function test_crear_rechaza_parametros_mal_formados( array $params, string $code, string $message, string $path ): void {
		$previous = $this->previous_backup();

		$this->assertRejected( $this->ops()->create( $params ), $code, $message, array( 'path' => $path ), $previous );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
	 */
	public static function variantes_invalidas(): array {
		$base = array(
			'meta'  => array( 'breakpoint' => 'desktop', 'state' => null ),
			'props' => array( 'color' => array( '$$type' => 'color', 'value' => '#fff' ) ),
		);

		$con = static function ( array $cambio ) use ( $base ): array {
			return array_replace_recursive( $base, $cambio );
		};

		return array(
			'breakpoint desconocido' => array(
				$con( array( 'meta' => array( 'breakpoint' => 'gigante' ) ) ),
				'nexo_mcp_elementor_style_invalid',
				'variants[0].meta.breakpoint',
				'no es un breakpoint válido de Elementor 4.4.1. Admitidos: desktop, widescreen, laptop, tablet_extra, tablet, mobile_extra, mobile.',
			),
			'estado desconocido'     => array(
				$con( array( 'meta' => array( 'state' => 'volando' ) ) ),
				'nexo_mcp_elementor_style_invalid',
				'variants[0].meta.state',
				'no es un estado válido de Elementor 4.4.1. Admitidos: hover, active, focus, focus-visible, checked, e--selected, e--disabled, e--playing, e--paused, null.',
			),
			'propiedad desconocida'  => array(
				$con( array( 'props' => array( 'magia' => array( '$$type' => 'string', 'value' => 'x' ) ) ) ),
				'nexo_mcp_elementor_style_prop_unknown',
				'variants[0].props.magia',
				'la propiedad de estilo «magia» no existe en el esquema de estilos de Elementor.',
			),
			'css con url()'          => array(
				$con( array( 'props' => array( 'color' => array( 'value' => 'url(http://x)' ) ) ) ),
				'nexo_mcp_elementor_style_value_denied',
				'variants[0].props.color.value',
				'el valor contiene caracteres o funciones que no se admiten en un valor de estilo (; { } < > \\ @, saltos de línea, /*, url(), image-set(), var(), !important…).',
			),
			'css personalizado'      => array(
				$con( array( 'custom_css' => array( 'raw' => 'YQ==' ) ) ),
				'nexo_mcp_elementor_style_css_denied',
				'variants[0].custom_css',
				'el CSS personalizado no se admite.',
			),
			'clave de variante'      => array(
				$con( array( 'extra' => 1 ) ),
				'nexo_mcp_elementor_style_invalid',
				'variants[0].extra',
				'la clave «extra» no se admite en una variante. Admitidas: meta, props, custom_css.',
			),
			'rechazo de Elementor'   => array(
				$con( array( 'props' => array( 'gap' => array( '$$type' => 'size', 'value' => 'malo' ) ) ) ),
				'nexo_mcp_elementor_style_rejected',
				'variants[0].props.gap',
				'Elementor rechazó el valor (invalid_value).',
			),
		);
	}

	/**
	 * @dataProvider variantes_invalidas
	 * @param array<string, mixed> $variant Variante inválida.
	 * @param string               $code    Código esperado.
	 * @param string               $path    Ruta esperada.
	 * @param string               $reason  Motivo esperado.
	 */
	public function test_crear_rechaza_variantes_invalidas_con_la_ruta_de_la_variante( array $variant, string $code, string $path, string $reason ): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->create( array( 'label' => 'nueva', 'variants' => array( $variant ) ) );

		$this->assertRejected( $result, $code, $path . ': ' . $reason, array( 'path' => $path ), $previous );
	}

	public function test_la_ruta_de_una_variante_posterior_lleva_su_indice(): void {
		$previous = $this->previous_backup();
		$bad      = $this->variant();
		$bad['meta']['breakpoint'] = 'gigante';

		$result = $this->ops()->create( array( 'label' => 'nueva', 'variants' => array( $this->variant(), $bad ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'path' => 'variants[1].meta.breakpoint' ), $result->get_error_data() );
		$this->assertSame( array(), $this->world->writes );
		$this->assertSame( $previous, $this->backup() );
	}

	public function test_una_variante_sin_estado_equivale_al_estado_base(): void {
		$variant = $this->variant();
		unset( $variant['meta']['state'] );

		$result = $this->ops()->create( array( 'label' => 'nueva', 'variants' => array( $variant ) ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'state', $this->world->writes[0][0]['g-abc1234']['variants'][0]['meta'] );
		$this->assertNull( $this->world->writes[0][0]['g-abc1234']['variants'][0]['meta']['state'] );
	}

	public function test_si_elementor_falla_al_escribir_se_restaura_y_se_responde_el_error(): void {
		$before                   = $this->world->snapshot( 'classes' );
		$this->world->write_fails = new \RuntimeException( 'disco lleno' );

		$result = $this->ops()->create( array( 'label' => 'nueva', 'variants' => array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_write_failed', $result->get_error_code() );
		$this->assertSame( 'disco lleno', $result->get_error_message() );
		$this->assertSame( $before, $this->world->snapshot( 'classes' ) );
		$this->assertNull( $this->backup() );
	}

	// ------------------------------------------------------------ Modificar

	public function test_modificar_el_label_toca_solo_esa_clase_y_no_cambia_el_orden(): void {
		$result = $this->ops()->update( array( 'id' => 'g-hero', 'label' => 'Heroe' ) );

		$this->assertSame( array( 'id' => 'g-hero', 'label' => 'Heroe', 'undo_available' => true ), $result );

		list( $touched, $changes, $order ) = $this->world->writes[0];
		$this->assertSame( array( 'g-hero' ), array_keys( $touched ) );
		$this->assertSame( 'Heroe', $touched['g-hero']['label'] );
		$this->assertSame( array( $this->variant( '#000000' ) ), $touched['g-hero']['variants'], 'las variantes quedan como estaban' );
		$this->assertSame(
			array(
				'added'    => array(),
				'deleted'  => array(),
				'modified' => array( 'g-hero' ),
				'order'    => false,
			),
			$changes
		);
		$this->assertSame( array( 'g-card', 'g-hero' ), $order );
		$this->assertSame( 'update-class', $this->backup()['operation'] );
		$this->assertSame( $this->item( 'g-hero', 'Hero' ), $this->backup()['state']['items']['g-hero'] );
		$this->assertSame( 1, $this->world->cleared );
	}

	public function test_modificar_las_variantes_las_reemplaza_y_conserva_el_label(): void {
		$result = $this->ops()->update(
			array(
				'id'       => 'g-card',
				'variants' => array( $this->variant( '#123456', 'tablet', 'hover' ) ),
			)
		);

		$this->assertSame( array( 'id' => 'g-card', 'label' => 'card_box', 'undo_available' => true ), $result );
		$this->assertSame( array( $this->variant( '#123456', 'tablet', 'hover' ) ), $this->world->writes[0][0]['g-card']['variants'] );
		$this->assertSame( 'card_box', $this->world->writes[0][0]['g-card']['label'] );
	}

	public function test_una_clase_puede_cambiar_solo_mayusculas_de_su_propio_label(): void {
		$result = $this->ops()->update( array( 'id' => 'g-hero', 'label' => 'HERO' ) );

		$this->assertSame( 'HERO', $result['label'] );
	}

	public function test_modificar_rechaza_el_label_de_otra_clase(): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->update( array( 'id' => 'g-hero', 'label' => 'CARD_BOX' ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_label_taken',
			'label: ya existe una clase llamada «card_box» (g-card). Elegí otro nombre: no se renombra solo.',
			array( 'path' => 'label', 'id' => 'g-card' ),
			$previous
		);
	}

	public function test_modificar_rechaza_un_label_con_formato_invalido(): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->update( array( 'id' => 'g-hero', 'label' => '9x' ) );

		$this->assertRejected( $result, 'nexo_mcp_design_label_invalid', null, array( 'path' => 'label' ), $previous );
	}

	public function test_modificar_una_clase_que_no_existe_responde_no_encontrada(): void {
		$previous = $this->previous_backup();

		$result = $this->ops()->update( array( 'id' => 'g-nada', 'label' => 'x' ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_not_found',
			'No existe ninguna clase global con el id «g-nada».',
			array( 'id' => 'g-nada' ),
			$previous
		);
	}

	public function test_modificar_sin_id_o_sin_cambios_se_rechaza(): void {
		$previous = $this->previous_backup();

		$this->assertRejected(
			$this->ops()->update( array( 'label' => 'x' ) ),
			'nexo_mcp_design_params_invalid',
			'id: indicá el id de la clase (g-…).',
			array( 'path' => 'id' ),
			$previous
		);

		$this->assertRejected(
			$this->ops()->update( array( 'id' => 'g-hero' ) ),
			'nexo_mcp_design_params_invalid',
			'indicá label, variants o ambos: no hay nada que modificar.',
			array( 'path' => 'label' ),
			$previous
		);
	}

	public function test_modificar_rechaza_variantes_invalidas_con_su_ruta(): void {
		$previous = $this->previous_backup();
		$bad      = $this->variant();
		$bad['props']['color']['value'] = 'a; b';

		$result = $this->ops()->update( array( 'id' => 'g-hero', 'variants' => array( $bad ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_style_value_denied', $result->get_error_code() );
		$this->assertStringStartsWith( 'variants[0].props.color.value: ', $result->get_error_message() );
		$this->assertSame( array( 'path' => 'variants[0].props.color.value' ), $result->get_error_data() );
		$this->assertSame( array(), $this->world->writes );
		$this->assertSame( $previous, $this->backup() );
	}

	// ------------------------------------------------------------ Borrar

	public function test_borrar_una_clase_sin_uso_la_quita_del_orden(): void {
		$result = $this->ops()->delete( array( 'id' => 'g-hero' ) );

		$this->assertSame( array( 'deleted' => 'g-hero', 'pages_affected' => array(), 'undo_available' => true ), $result );

		list( $touched, $changes, $order ) = $this->world->writes[0];
		$this->assertSame( array(), $touched );
		$this->assertSame(
			array(
				'added'    => array(),
				'deleted'  => array( 'g-hero' ),
				'modified' => array(),
				'order'    => true,
			),
			$changes
		);
		$this->assertSame( array( 'g-card' ), $order );
		$this->assertArrayNotHasKey( 'g-hero', $this->world->items );
		$this->assertSame( 'delete-class', $this->backup()['operation'] );
		$this->assertSame( '', $this->backup()['note'] );
		$this->assertArrayHasKey( 'g-hero', $this->backup()['state']['items'] );
	}

	public function test_borrar_una_clase_en_uso_sin_force_se_rechaza_con_la_lista_de_paginas(): void {
		$used                           = array(
			array( 'id' => 12, 'title' => 'Inicio' ),
			array( 'id' => 40, 'title' => 'Contacto' ),
		);
		$this->world->usage['g-hero'] = $used;
		$previous                     = $this->previous_backup();

		$result = $this->ops()->delete( array( 'id' => 'g-hero' ) );

		$this->assertRejected(
			$result,
			'nexo_mcp_design_in_use',
			'La clase g-hero está en uso en 2 páginas (Inicio, Contacto). No se borró: si querés borrarla igual, repetí con force.',
			array( 'id' => 'g-hero', 'used_in' => $used ),
			$previous
		);
		$this->assertArrayHasKey( 'g-hero', $this->world->items );
	}

	public function test_borrar_con_force_una_clase_en_uso_avisa_que_deshacer_no_la_devuelve_a_las_paginas(): void {
		$used                           = array( array( 'id' => 12, 'title' => 'Inicio' ) );
		$this->world->usage['g-hero'] = $used;

		$result = $this->ops()->delete( array( 'id' => 'g-hero', 'force' => true ) );

		$this->assertSame( array( 'deleted' => 'g-hero', 'pages_affected' => $used, 'undo_available' => true ), $result );
		$this->assertSame( array( 'g-hero' ), $this->world->writes[0][1]['deleted'] );
		$this->assertArrayNotHasKey( 'g-hero', $this->world->items );
		$this->assertSame(
			'La clase g-hero se quitó de 1 página (Inicio). Deshacer la devuelve a la lista de clases, pero no vuelve a ponerla en esas páginas.',
			$this->backup()['note']
		);
	}

	public function test_force_en_una_clase_sin_uso_no_agrega_nota(): void {
		$result = $this->ops()->delete( array( 'id' => 'g-hero', 'force' => true ) );

		$this->assertSame( array(), $result['pages_affected'] );
		$this->assertSame( '', $this->backup()['note'] );
	}

	public function test_borrar_una_clase_que_no_existe_responde_no_encontrada(): void {
		$previous = $this->previous_backup();

		$this->assertRejected(
			$this->ops()->delete( array( 'id' => 'g-nada' ) ),
			'nexo_mcp_design_not_found',
			'No existe ninguna clase global con el id «g-nada».',
			array( 'id' => 'g-nada' ),
			$previous
		);
	}

	public function test_borrar_rechaza_parametros_mal_formados(): void {
		$previous = $this->previous_backup();

		$this->assertRejected(
			$this->ops()->delete( array( 'id' => 'g-hero', 'force' => 'si' ) ),
			'nexo_mcp_design_params_invalid',
			'force: debe ser verdadero o falso.',
			array( 'path' => 'force' ),
			$previous
		);

		$this->assertRejected(
			$this->ops()->delete( array() ),
			'nexo_mcp_design_params_invalid',
			'id: indicá el id de la clase (g-…).',
			array( 'path' => 'id' ),
			$previous
		);
	}
}
