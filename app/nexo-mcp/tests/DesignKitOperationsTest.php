<?php
/**
 * Operaciones de colores y tipografía del Kit (kind `kit`).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DesignKitOperations;
use NexoDev\NexoMCP\Builders\DesignKitStore;
use NexoDev\NexoMCP\Builders\DesignSystemStore;
use NexoDev\NexoMCP\Builders\DesignSystemWriter;

/**
 * Listas del Kit en memoria: registra cada guardado.
 */
final class FakeKitLists implements DesignKitStore {

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $lists;

	/** @var array<int, array<string, array<int, array<string, mixed>>>> */
	public array $saves = array();

	public ?\Throwable $fails = null;

	public function __construct() {
		$this->lists = array(
			'system_colors'     => array(
				array( '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ),
				array( '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ),
			),
			'custom_colors'     => array(
				array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ),
				array( '_id' => 'e4f5a6b', 'title' => 'Fondo', 'color' => '#fff' ),
			),
			'system_typography' => array(
				array( '_id' => 'primary', 'title' => 'Primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '600' ),
				array( '_id' => 'text', 'title' => 'Text', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '400' ),
			),
			'custom_typography' => array(
				array( '_id' => '0c0ffee', 'title' => 'Título', 'typography_typography' => 'custom', 'typography_font_family' => 'Lora', 'typography_font_size' => array( 'unit' => 'px', 'size' => 32, 'sizes' => array() ) ),
			),
		);
	}

	public function lists(): array {
		return $this->lists;
	}

	public function save( array $lists ): void {
		if ( null !== $this->fails ) {
			throw $this->fails;
		}

		$this->saves[] = $lists;

		foreach ( $lists as $key => $list ) {
			$this->lists[ $key ] = $list;
		}
	}
}

/**
 * Almacén de Elementor para el escritor.
 */
final class FakeKitWriterStore implements DesignSystemStore {

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public array $restored = array();

	public function __construct( private FakeKitLists $lists ) {}

	public function available(): bool {
		return true;
	}

	public function atomic(): bool {
		return false;
	}

	public function kit_id(): int {
		return 77;
	}

	public function snapshot( string $kind ): array {
		return $this->lists->lists;
	}

	public function restore( string $kind, array $state ): void {
		$this->restored[]  = array( $kind, $state );
		$this->lists->lists = $state;
	}

	public function clear_cache(): void {}
}

final class DesignKitOperationsTest extends NexoTestCase {

	private FakeKitLists $lists;

	private FakeKitWriterStore $writer_store;

	/** @var array<int, string> */
	private array $ids;

	/** @var array<int, array{0: string, 1: string, 2: mixed, 3: mixed}> */
	private array $audit = array();

	protected function setUp(): void {
		parent::setUp();
		$this->lists        = new FakeKitLists();
		$this->writer_store = new FakeKitWriterStore( $this->lists );
		$this->ids          = array( 'abcdef0', '1234567', '89abcde' );
		$this->audit        = array();
	}

	private function ops(): DesignKitOperations {
		$writer = new DesignSystemWriter(
			$this->writer_store,
			function ( string $tool, string $status, $params, $result ): void {
				$this->audit[] = array( $tool, $status, $params, $result );
			}
		);

		return new DesignKitOperations(
			$writer,
			$this->lists,
			function (): string {
				return (string) array_shift( $this->ids );
			}
		);
	}

	private function assert_rejected( $result, string $code, string $message, $data = null ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assertSame( $data, $result->get_error_data() );
		$this->assertSame( array(), $this->lists->saves, 'No se guarda nada.' );
		$this->assertSame( array(), $this->writer_store->restored );
		$this->assertArrayNotHasKey( DesignSystemWriter::BACKUP_OPTION, \WP_Test_State::$options, 'Sin respaldo: no hubo escritura.' );
		$this->assertSame( array(), $this->audit );
	}

	public function test_modify_system_color_replaces_the_whole_list_and_answers_the_lists(): void {
		$result = $this->ops()->update( array( 'colors' => array( array( 'id' => 'primary', 'title' => 'Principal', 'color' => '#AABBCC' ) ) ) );

		$this->assertSame(
			array( 'system_colors' => array(
				array( '_id' => 'primary', 'title' => 'Principal', 'color' => '#AABBCC' ),
				array( '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ),
			) ),
			$this->lists->saves[0]
		);
		$this->assertCount( 1, $this->lists->saves );
		$this->assertSame(
			array(
				'colors'         => array(
					array( 'id' => 'primary', 'title' => 'Principal', 'color' => '#AABBCC' ),
					array( 'id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ),
					array( 'id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ),
					array( 'id' => 'e4f5a6b', 'title' => 'Fondo', 'color' => '#fff' ),
				),
				'typography'     => array(
					array( 'id' => 'primary', 'title' => 'Primary', 'font_family' => 'Roboto', 'font_weight' => '600' ),
					array( 'id' => 'text', 'title' => 'Text', 'font_family' => 'Roboto', 'font_weight' => '400' ),
					array( 'id' => '0c0ffee', 'title' => 'Título', 'font_family' => 'Lora' ),
				),
				'undo_available' => true,
			),
			$result
		);
		$this->assertSame( 'design-system-update', $this->audit[0][0] );
		$this->assertSame( 'success', $this->audit[0][1] );
	}

	public function test_modify_only_the_color_keeps_the_title(): void {
		$this->ops()->update( array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#abcdef' ) ) ) );

		$this->assertSame(
			array( 'custom_colors' => array(
				array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#abcdef' ),
				array( '_id' => 'e4f5a6b', 'title' => 'Fondo', 'color' => '#fff' ),
			) ),
			$this->lists->saves[0]
		);
	}

	public function test_create_color_appends_a_custom_item_with_a_new_hex_id(): void {
		$result = $this->ops()->update( array( 'colors' => array( array( 'title' => 'Acento', 'color' => '#12345678' ) ) ) );

		$this->assertSame(
			array( 'custom_colors' => array(
				array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ),
				array( '_id' => 'e4f5a6b', 'title' => 'Fondo', 'color' => '#fff' ),
				array( '_id' => 'abcdef0', 'title' => 'Acento', 'color' => '#12345678' ),
			) ),
			$this->lists->saves[0]
		);
		$this->assertSame( array( 'id' => 'abcdef0', 'title' => 'Acento', 'color' => '#12345678' ), $result['colors'][4] );
		$this->assertCount( 5, $result['colors'] );
	}

	public function test_a_generated_id_that_already_exists_is_skipped(): void {
		$this->ids = array( 'a1b2c3d', 'primary', '1234567' );

		$this->ops()->update( array( 'colors' => array( array( 'title' => 'Nuevo', 'color' => '#000' ) ) ) );

		$this->assertSame( '1234567', $this->lists->saves[0]['custom_colors'][2]['_id'] );
	}

	public function test_two_new_items_get_different_ids(): void {
		$this->ids = array( 'abcdef0', 'abcdef0', '1234567' );

		$this->ops()->update(
			array(
				'colors'     => array( array( 'title' => 'Uno', 'color' => '#111' ) ),
				'typography' => array( array( 'title' => 'Dos' ) ),
			)
		);

		$this->assertSame( 'abcdef0', $this->lists->saves[0]['custom_colors'][2]['_id'] );
		$this->assertSame( '1234567', $this->lists->saves[0]['custom_typography'][1]['_id'] );
	}

	public function test_create_typography_marks_it_custom_and_validates_values(): void {
		$result = $this->ops()->update(
			array(
				'typography' => array(
					array(
						'title'                         => 'Cuerpo',
						'typography_font_family'        => 'Open Sans',
						'typography_font_weight'        => 700,
						'typography_text_transform'     => 'uppercase',
						'typography_font_style'         => 'italic',
						'typography_text_decoration'    => 'underline',
						'typography_font_size'          => array( 'unit' => 'rem', 'size' => 1.5 ),
						'typography_font_size_tablet'   => array( 'unit' => 'custom', 'size' => 'calc(1rem + 2vw)' ),
						'typography_line_height'        => array( 'unit' => 'lh', 'size' => 1.2 ),
						'typography_letter_spacing'     => array( 'unit' => 'px', 'size' => -0.5 ),
						'typography_word_spacing_mobile' => array( 'unit' => 'em', 'size' => '2' ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'_id'                            => 'abcdef0',
				'title'                          => 'Cuerpo',
				'typography_font_family'         => 'Open Sans',
				'typography_font_weight'         => '700',
				'typography_text_transform'      => 'uppercase',
				'typography_font_style'          => 'italic',
				'typography_text_decoration'     => 'underline',
				'typography_font_size'           => array( 'unit' => 'rem', 'size' => 1.5, 'sizes' => array() ),
				'typography_font_size_tablet'    => array( 'unit' => 'custom', 'size' => 'calc(1rem + 2vw)', 'sizes' => array() ),
				'typography_line_height'         => array( 'unit' => 'lh', 'size' => 1.2, 'sizes' => array() ),
				'typography_letter_spacing'      => array( 'unit' => 'px', 'size' => -0.5, 'sizes' => array() ),
				'typography_word_spacing_mobile' => array( 'unit' => 'em', 'size' => '2', 'sizes' => array() ),
				'typography_typography'          => 'custom',
			),
			$this->lists->saves[0]['custom_typography'][1]
		);
		$this->assertSame( array( 'custom_typography' ), array_keys( $this->lists->saves[0] ) );
		$this->assertSame( array( 'id' => 'abcdef0', 'title' => 'Cuerpo', 'font_family' => 'Open Sans', 'font_weight' => '700' ), $result['typography'][3] );
	}

	public function test_modify_system_typography_merges_keys_and_null_removes_one(): void {
		$result = $this->ops()->update(
			array(
				'typography' => array(
					array( 'id' => 'primary', 'typography_font_family' => 'Inter', 'typography_font_weight' => null, 'typography_font_size' => array( 'unit' => 'px', 'size' => 18 ) ),
				),
			)
		);

		$this->assertSame(
			array( 'system_typography' => array(
				array( '_id' => 'primary', 'title' => 'Primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_size' => array( 'unit' => 'px', 'size' => 18, 'sizes' => array() ) ),
				array( '_id' => 'text', 'title' => 'Text', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto', 'typography_font_weight' => '400' ),
			) ),
			$this->lists->saves[0]
		);
		$this->assertSame( array( 'id' => 'primary', 'title' => 'Primary', 'font_family' => 'Inter' ), $result['typography'][0] );
	}

	public function test_modify_custom_typography_title_and_activates_custom_mode_when_missing(): void {
		$this->lists->lists['custom_typography'][0] = array( '_id' => '0c0ffee', 'title' => 'Título' );

		$this->ops()->update( array( 'typography' => array( array( 'id' => '0c0ffee', 'title' => 'Titular', 'typography_font_weight' => 'bold' ) ) ) );

		$this->assertSame(
			array( 'custom_typography' => array(
				array( '_id' => '0c0ffee', 'title' => 'Titular', 'typography_font_weight' => 'bold', 'typography_typography' => 'custom' ),
			) ),
			$this->lists->saves[0]
		);
	}

	public function test_delete_custom_items_of_both_lists(): void {
		$result = $this->ops()->update( array( 'delete' => array( 'a1b2c3d', '0c0ffee' ) ) );

		$this->assertSame(
			array(
				'custom_colors'     => array( array( '_id' => 'e4f5a6b', 'title' => 'Fondo', 'color' => '#fff' ) ),
				'custom_typography' => array(),
			),
			$this->lists->saves[0]
		);
		$this->assertCount( 3, $result['colors'] );
		$this->assertCount( 2, $result['typography'] );
		$this->assertTrue( $result['undo_available'] );
	}

	public function test_create_modify_and_delete_in_one_request_save_once(): void {
		$this->ops()->update(
			array(
				'colors' => array(
					array( 'id' => 'secondary', 'color' => '#000000' ),
					array( 'title' => 'Nuevo', 'color' => '#ffffff' ),
				),
				'delete' => array( 'e4f5a6b' ),
			)
		);

		$this->assertCount( 1, $this->lists->saves );
		$this->assertSame( array( 'system_colors', 'custom_colors' ), array_keys( $this->lists->saves[0] ) );
		$this->assertSame(
			array(
				array( '_id' => 'a1b2c3d', 'title' => 'Marca', 'color' => '#112233' ),
				array( '_id' => 'abcdef0', 'title' => 'Nuevo', 'color' => '#ffffff' ),
			),
			$this->lists->saves[0]['custom_colors']
		);
	}

	public function test_a_write_creates_the_kit_backup_with_the_previous_lists(): void {
		$before = $this->lists->lists;

		$this->ops()->update( array( 'delete' => array( 'a1b2c3d' ) ) );

		$backup = \WP_Test_State::$options[ DesignSystemWriter::BACKUP_OPTION ];
		$this->assertSame( 'kit', $backup['kind'] );
		$this->assertSame( 'update', $backup['operation'] );
		$this->assertSame( $before, $backup['state'] );
	}

	public function test_a_failing_save_is_rolled_back_by_the_writer(): void {
		$before             = $this->lists->lists;
		$this->lists->fails = new \RuntimeException( 'Elementor no pudo guardar los ajustes del Kit.' );

		$result = $this->ops()->update( array( 'delete' => array( 'a1b2c3d' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_design_write_failed', $result->get_error_code() );
		$this->assertSame( 'Elementor no pudo guardar los ajustes del Kit.', $result->get_error_message() );
		$this->assertSame( array( array( 'kit', $before ) ), $this->writer_store->restored );
		$this->assertSame( $before, $this->lists->lists );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function empty_requests(): array {
		return array(
			'sin nada'         => array( array() ),
			'listas vacías'    => array( array( 'colors' => array(), 'typography' => array(), 'delete' => array() ) ),
			'sólo null'        => array( array( 'colors' => null, 'delete' => null ) ),
		);
	}

	/**
	 * @dataProvider empty_requests
	 *
	 * @param array<string, mixed> $params Petición.
	 */
	public function test_an_empty_request_changes_nothing( array $params ): void {
		$this->assert_rejected(
			$this->ops()->update( $params ),
			'nexo_mcp_design_nothing_to_change',
			'No hay nada que cambiar: mandá colors, typography o delete con al menos un elemento.'
		);
	}

	public function test_delete_of_a_system_item_is_rejected(): void {
		$this->assert_rejected(
			$this->ops()->update( array( 'delete' => array( 'a1b2c3d', 'primary' ) ) ),
			'nexo_mcp_design_system_item',
			'«primary» es un item del sistema: se puede modificar pero no borrar.',
			array( 'id' => 'primary' )
		);
	}

	public function test_delete_of_an_unknown_id_is_not_found(): void {
		$this->assert_rejected(
			$this->ops()->update( array( 'delete' => array( 'zzzzzzz' ) ) ),
			'nexo_mcp_design_not_found',
			'No existe ningún item personalizado con id «zzzzzzz».',
			array( 'id' => 'zzzzzzz' )
		);
	}

	public function test_modify_of_an_unknown_color_id_is_not_found(): void {
		$this->assert_rejected(
			$this->ops()->update( array( 'colors' => array( array( 'id' => 'text', 'color' => '#000' ) ) ) ),
			'nexo_mcp_design_not_found',
			'No existe el color «text».',
			array( 'id' => 'text' )
		);
	}

	public function test_modify_of_an_unknown_typography_id_is_not_found(): void {
		$this->assert_rejected(
			$this->ops()->update( array( 'typography' => array( array( 'id' => 'a1b2c3d', 'title' => 'X' ) ) ) ),
			'nexo_mcp_design_not_found',
			'No existe la tipografía «a1b2c3d».',
			array( 'id' => 'a1b2c3d' )
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, string>}>
	 */
	public static function invalid_requests(): array {
		$bad_color = 'usá #rgb, #rrggbb o #rrggbbaa.';
		$bad_title = 'tiene que ser texto plano de hasta 100 caracteres, sin etiquetas.';
		$units     = 'tiene que ser {unit, size} con unit entre %s y size numérico (con unit custom, texto de hasta 100 caracteres sin ; { } < > \\ @).';
		$family    = 'el nombre de la fuente tiene que ser texto de hasta 100 caracteres, sin ; { } < > \\ @ ni comillas.';
		$typo_keys = 'no admite la clave «%s»; sólo id, title y las claves typography_* de Elementor.';
		$cases     = array(
			'clave suelta'              => array( array( 'colores' => array() ), 'update: no admite la clave «colores»; sólo colors, typography y delete.', 'update' ),
			'colors no es lista'        => array( array( 'colors' => 'rojo' ), 'colors: tiene que ser una lista.', 'colors' ),
			'delete no es lista'        => array( array( 'delete' => 'a1b2c3d' ), 'delete: tiene que ser una lista.', 'delete' ),
			'item no es objeto'         => array( array( 'colors' => array( '#fff' ) ), 'colors[0]: tiene que ser un objeto.', 'colors[0]' ),
			'delete no texto'           => array( array( 'delete' => array( 5 ) ), 'delete[0]: tiene que ser un id en texto.', 'delete[0]' ),
			'id vacío'                  => array( array( 'colors' => array( array( 'id' => '', 'color' => '#fff' ) ) ), 'colors[0].id: tiene que ser un id en texto.', 'colors[0].id' ),
			'id con símbolos'           => array( array( 'colors' => array( array( 'id' => 'a b;', 'color' => '#fff' ) ) ), 'colors[0].id: tiene que ser un id en texto.', 'colors[0].id' ),
			'color con clave de más'    => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => '#fff', 'typography_font_family' => 'X' ) ) ), 'colors[0]: no admite la clave «typography_font_family»; sólo id, title y color.', 'colors[0]' ),
			'sólo id'                   => array( array( 'colors' => array( array( 'id' => 'primary' ) ) ), 'colors[0]: no trae nada que cambiar.', 'colors[0]' ),
			'crear sin título'          => array( array( 'colors' => array( array( 'color' => '#fff' ) ) ), 'colors[0].title: es obligatorio para crear.', 'colors[0].title' ),
			'crear sin color'           => array( array( 'colors' => array( array( 'title' => 'X' ) ) ), 'colors[0].color: es obligatorio para crear.', 'colors[0].color' ),
			'crear tipografía sin título' => array( array( 'typography' => array( array( 'typography_font_family' => 'X' ) ) ), 'typography[0].title: es obligatorio para crear.', 'typography[0].title' ),
			'título con etiquetas'      => array( array( 'colors' => array( array( 'id' => 'primary', 'title' => '<b>x</b>' ) ) ), 'colors[0].title: ' . $bad_title, 'colors[0].title' ),
			'título vacío'              => array( array( 'colors' => array( array( 'id' => 'primary', 'title' => '  ' ) ) ), 'colors[0].title: ' . $bad_title, 'colors[0].title' ),
			'título largo'              => array( array( 'typography' => array( array( 'id' => 'primary', 'title' => str_repeat( 'a', 101 ) ) ) ), 'typography[0].title: ' . $bad_title, 'typography[0].title' ),
			'título no texto'           => array( array( 'colors' => array( array( 'id' => 'primary', 'title' => 5 ) ) ), 'colors[0].title: ' . $bad_title, 'colors[0].title' ),
			'color sin almohadilla'     => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => 'ffffff' ) ) ), 'colors[0].color: ' . $bad_color, 'colors[0].color' ),
			'color de 5 dígitos'        => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => '#12345' ) ) ), 'colors[0].color: ' . $bad_color, 'colors[0].color' ),
			'color con nombre'          => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => 'red' ) ) ), 'colors[0].color: ' . $bad_color, 'colors[0].color' ),
			'color con css'             => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => '#fff;}' ) ) ), 'colors[0].color: ' . $bad_color, 'colors[0].color' ),
			'color no texto'            => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => 255 ) ) ), 'colors[0].color: ' . $bad_color, 'colors[0].color' ),
			'clave typography ajena'    => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_color' => 'x' ) ) ), 'typography[0]: ' . sprintf( $typo_keys, 'typography_color' ), 'typography[0]' ),
			'clave no typography'       => array( array( 'typography' => array( array( 'id' => 'primary', 'color' => '#fff' ) ) ), 'typography[0]: ' . sprintf( $typo_keys, 'color' ), 'typography[0]' ),
			'familia con llaves'        => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_family' => 'A;}' ) ) ), 'typography[0].typography_font_family: ' . $family, 'typography[0].typography_font_family' ),
			'familia con comillas'      => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_family' => 'A"B' ) ) ), 'typography[0].typography_font_family: ' . $family, 'typography[0].typography_font_family' ),
			'familia con arroba'        => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_family' => '@import' ) ) ), 'typography[0].typography_font_family: ' . $family, 'typography[0].typography_font_family' ),
			'familia vacía'             => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_family' => '' ) ) ), 'typography[0].typography_font_family: ' . $family, 'typography[0].typography_font_family' ),
			'peso fuera de lista'       => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_weight' => '650' ) ) ), 'typography[0].typography_font_weight: valor no válido; opciones: 100, 200, 300, 400, 500, 600, 700, 800, 900, normal, bold.', 'typography[0].typography_font_weight' ),
			'transform inválido'        => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_text_transform' => 'x' ) ) ), 'typography[0].typography_text_transform: valor no válido; opciones: uppercase, lowercase, capitalize, none.', 'typography[0].typography_text_transform' ),
			'estilo inválido'           => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_style' => 'bold' ) ) ), 'typography[0].typography_font_style: valor no válido; opciones: normal, italic, oblique.', 'typography[0].typography_font_style' ),
			'decoración inválida'       => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_text_decoration' => 'blink' ) ) ), 'typography[0].typography_text_decoration: valor no válido; opciones: underline, overline, line-through, none.', 'typography[0].typography_text_decoration' ),
			'modo no custom'            => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_typography' => 'x' ) ) ), 'typography[0].typography_typography: valor no válido; opciones: custom.', 'typography[0].typography_typography' ),
			'tamaño no objeto'          => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_size' => 16 ) ) ), 'typography[0].typography_font_size: ' . sprintf( $units, 'px, em, rem, vw, custom' ), 'typography[0].typography_font_size' ),
			'unidad inválida'           => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_size' => array( 'unit' => '%', 'size' => 5 ) ) ) ), 'typography[0].typography_font_size: ' . sprintf( $units, 'px, em, rem, vw, custom' ), 'typography[0].typography_font_size' ),
			'unidad lh en tamaño'       => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_size_mobile' => array( 'unit' => 'lh', 'size' => 5 ) ) ) ), 'typography[0].typography_font_size_mobile: ' . sprintf( $units, 'px, em, rem, vw, custom' ), 'typography[0].typography_font_size_mobile' ),
			'unidad vw en espaciado'    => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_letter_spacing' => array( 'unit' => 'vw', 'size' => 5 ) ) ) ), 'typography[0].typography_letter_spacing: ' . sprintf( $units, 'px, em, rem, custom' ), 'typography[0].typography_letter_spacing' ),
			'tamaño no numérico'        => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_line_height' => array( 'unit' => 'em', 'size' => 'grande' ) ) ) ), 'typography[0].typography_line_height: ' . sprintf( $units, 'px, em, rem, lh, rlh, custom' ), 'typography[0].typography_line_height' ),
			'tamaño sin size'           => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_word_spacing' => array( 'unit' => 'em' ) ) ) ), 'typography[0].typography_word_spacing: ' . sprintf( $units, 'px, em, rem, custom' ), 'typography[0].typography_word_spacing' ),
			'tamaño con clave extra'    => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_size' => array( 'unit' => 'px', 'size' => 5, 'x' => 1 ) ) ) ), 'typography[0].typography_font_size: ' . sprintf( $units, 'px, em, rem, vw, custom' ), 'typography[0].typography_font_size' ),
			'custom con css'            => array( array( 'typography' => array( array( 'id' => 'primary', 'typography_font_size' => array( 'unit' => 'custom', 'size' => '1px;}' ) ) ) ), 'typography[0].typography_font_size: ' . sprintf( $units, 'px, em, rem, vw, custom' ), 'typography[0].typography_font_size' ),
			'id repetido'               => array( array( 'colors' => array( array( 'id' => 'primary', 'color' => '#fff' ), array( 'id' => 'primary', 'color' => '#000' ) ) ), 'colors[1].id: «primary» se repite en la petición.', 'colors[1].id' ),
			'modificar y borrar'        => array( array( 'colors' => array( array( 'id' => 'a1b2c3d', 'color' => '#000' ) ), 'delete' => array( 'a1b2c3d' ) ), 'delete[0]: «a1b2c3d» se repite en la petición.', 'delete[0]' ),
			'borrar repetido'           => array( array( 'delete' => array( 'a1b2c3d', 'a1b2c3d' ) ), 'delete[1]: «a1b2c3d» se repite en la petición.', 'delete[1]' ),
		);

		return $cases;
	}

	/**
	 * @dataProvider invalid_requests
	 *
	 * @param array<string, mixed> $params  Petición.
	 * @param string               $message Mensaje esperado.
	 * @param string               $path    Campo señalado.
	 */
	public function test_invalid_requests_are_rejected_without_saving( array $params, string $message, string $path ): void {
		$this->assert_rejected( $this->ops()->update( $params ), 'nexo_mcp_design_invalid', $message, array( 'path' => $path ) );
	}

	public function test_a_valid_item_next_to_an_invalid_one_saves_nothing(): void {
		$this->assert_rejected(
			$this->ops()->update(
				array(
					'colors' => array(
						array( 'id' => 'primary', 'color' => '#000000' ),
						array( 'id' => 'secondary', 'color' => 'rojo' ),
					),
				)
			),
			'nexo_mcp_design_invalid',
			'colors[1].color: usá #rgb, #rrggbb o #rrggbbaa.',
			array( 'path' => 'colors[1].color' )
		);
	}

	public function test_all_three_hex_lengths_are_accepted(): void {
		$result = $this->ops()->update(
			array( 'colors' => array(
				array( 'id' => 'primary', 'color' => '#abc' ),
				array( 'id' => 'secondary', 'color' => '#AABBCC' ),
				array( 'id' => 'a1b2c3d', 'color' => '#aabbcc80' ),
			) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( '#abc', $this->lists->saves[0]['system_colors'][0]['color'] );
		$this->assertSame( '#aabbcc80', $this->lists->saves[0]['custom_colors'][0]['color'] );
	}

	public function test_default_ids_are_seven_hex_characters(): void {
		$ops    = new DesignKitOperations( new DesignSystemWriter( $this->writer_store, function (): void {} ), $this->lists );
		$result = $ops->update( array( 'colors' => array( array( 'title' => 'Nuevo', 'color' => '#000' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $result['colors'][4]['id'] );
	}
}
