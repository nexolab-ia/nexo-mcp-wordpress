<?php
/**
 * DiviDesignOperations: validación y escritura de colores, variables y tipografía (Divi inyectado).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviDesignOperations;
use NexoDev\NexoMCP\Builders\DiviDesignStore;

final class DiviDesignOperationsTest extends NexoTestCase {

	private bool $active = true;

	/** @var array<string, array<string, mixed>> */
	private array $colors = array();

	/** @var array<string, array<string, array<string, mixed>>> */
	private array $variables = array();

	/** @var array<string, mixed> */
	private array $options = array();

	private int $flushes = 0;

	/** @var array<int, string> */
	private array $scanned = array();

	/** @var array<string, array<int, int>> */
	private array $usage = array();

	/** @var array<int, string> */
	private array $suffixes = array();

	private bool $locked = false;

	/** @var array<int, bool> Si cada búsqueda de uso corrió dentro del bloqueo. */
	private array $scan_locked = array();

	protected function setUp(): void {
		parent::setUp();
		$this->active    = true;
		$this->colors    = array(
			'gcid-aaaaaaaaaa' => array( 'color' => '#111111', 'label' => 'Base', 'folder' => '', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => array() ),
		);
		$this->variables = array(
			'numbers' => array( 'gvid-n1' => array( 'label' => 'Radio', 'value' => '12px', 'order' => 1, 'status' => 'active' ) ),
			'fonts'   => array( 'gvid-f1' => array( 'label' => 'Titulo', 'value' => 'Roboto', 'order' => 1, 'status' => 'active' ) ),
		);
		$this->options   = array( 'accent_color' => '#2ea3f2', 'body_font_size' => '14', 'unrelated_setting' => 'x' );
		$this->flushes   = 0;
		$this->scanned   = array();
		$this->usage     = array();
		$this->suffixes  = array( 'abcde12345', 'zzzzz99999' );
		$this->locked    = false;
		$this->scan_locked = array();
	}

	private function ops(): DiviDesignOperations {
		$store = new DiviDesignStore(
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
				if ( null === $value ) {
					unset( $this->options[ $key ] );
				} else {
					$this->options[ $key ] = $value;
				}
			},
			function (): void {
				++$this->flushes;
			},
			function ( string $tool, string $status, $params, $result ): void {},
			function ( callable $work ) {
				$this->locked = true;

				try {
					return $work();
				} finally {
					$this->locked = false;
				}
			}
		);

		return new DiviDesignOperations(
			$store,
			function ( string $id ): array {
				$this->scanned[]     = $id;
				$this->scan_locked[] = $this->locked;

				return $this->usage[ $id ] ?? array();
			},
			static fn( int $id ): ?string => 55 === $id ? 'https://example.test/wp-content/uploads/logo.png' : null,
			function (): string {
				return (string) array_shift( $this->suffixes );
			}
		);
	}

	private function history(): array {
		$value = get_option( DiviDesignStore::HISTORY_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array{0: array, 1: array, 2: array}
	 */
	private function snapshot(): array {
		return array( $this->colors, $this->variables, $this->options );
	}

	private function assert_rejected( $result, string $code, array $before, ?string $path = null ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'no cambia nada' );
		$this->assertSame( array(), $this->history(), 'sin historial' );
		$this->assertSame( 0, $this->flushes, 'sin flush' );

		if ( null !== $path ) {
			$this->assertSame( $path, $result->get_error_data()['path'] ?? null );
			$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
		}

		return $result;
	}

	// --- Colores -------------------------------------------------------------------------------

	public function test_crear_un_color_genera_id_gcid_con_10_caracteres_y_respalda(): void {
		$before = $this->colors;
		$result = $this->ops()->set_color( array( 'label' => 'Marca', 'color' => '#FF0000' ) );

		$this->assertSame( 'gcid-abcde12345', $result['id'] );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 'Marca', $this->colors['gcid-abcde12345']['label'] );
		$this->assertSame( '#FF0000', $this->colors['gcid-abcde12345']['color'] );
		$this->assertSame( 'active', $this->colors['gcid-abcde12345']['status'] );
		$this->assertSame( array(), $this->colors['gcid-abcde12345']['usedInPosts'] );
		$this->assertMatchesRegularExpression( '/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $this->colors['gcid-abcde12345']['lastUpdated'] );
		$this->assertSame( $before['gcid-aaaaaaaaaa'], $this->colors['gcid-aaaaaaaaaa'] );
		$this->assertSame( 1, $this->flushes );
		$this->assertSame( $before, $this->history()[0]['previous'] );
		$this->assertSame( 'set-divi-global-color', $this->history()[0]['tool'] );
	}

	public function test_el_id_generado_no_choca_con_uno_existente_y_tiene_el_formato(): void {
		$this->suffixes = array( 'aaaaaaaaaa', 'bbbbbbbbbb' );
		$result         = $this->ops()->set_color( array( 'label' => 'Otro', 'color' => '#fff' ) );

		$this->assertSame( 'gcid-bbbbbbbbbb', $result['id'] );

		$real = new DiviDesignOperations( new DiviDesignStore( fn(): bool => true ) );
		$this->assertMatchesRegularExpression( '/\A[a-z0-9]{10}\z/', $real->new_suffix() );
	}

	public function test_actualizar_un_color_conserva_estado_y_cambia_etiqueta_color_y_fecha(): void {
		$result = $this->ops()->set_color( array( 'id' => 'gcid-aaaaaaaaaa', 'label' => 'Nueva', 'color' => 'rgba(1, 2, 3, 0.5)' ) );

		$this->assertFalse( $result['created'] );
		$this->assertSame( 'Nueva', $this->colors['gcid-aaaaaaaaaa']['label'] );
		$this->assertSame( 'rgba(1, 2, 3, 0.5)', $this->colors['gcid-aaaaaaaaaa']['color'] );
		$this->assertSame( 'active', $this->colors['gcid-aaaaaaaaaa']['status'] );
		$this->assertNotSame( '2026-01-01T00:00:00.000Z', $this->colors['gcid-aaaaaaaaaa']['lastUpdated'] );
		$this->assertCount( 1, $this->colors );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function colors(): array {
		return array(
			'hex3'              => array( '#abc', true ),
			'hex4'              => array( '#abcd', true ),
			'hex6'              => array( '#aabbcc', true ),
			'hex8'              => array( '#aabbccdd', true ),
			'hex5'              => array( '#abcde', false ),
			'hex7'              => array( '#aabbccd', false ),
			'sin almohadilla'   => array( 'aabbcc', false ),
			'hex no hex'        => array( '#ggg', false ),
			'rgb'               => array( 'rgb(255, 0, 10)', true ),
			'rgba'              => array( 'rgba(255,0,10,0.4)', true ),
			'rgb porcentajes'   => array( 'rgb(10%, 20%, 30%)', true ),
			'rgb corto'         => array( 'rgb(1, 2)', false ),
			'hsl'               => array( 'hsl(120, 50%, 50%)', true ),
			'hsla'              => array( 'hsla(120, 50%, 50%, 0.3)', true ),
			'hsl sin porcentaje' => array( 'hsl(120, 50, 50)', false ),
			'nombre'            => array( 'red', false ),
			'var'               => array( 'var(--x)', false ),
			'inyeccion css'     => array( '#fff; background: url(x)', false ),
			'salto final'       => array( "#fff\n", false ),
			'vacio'             => array( '', false ),
		);
	}

	/**
	 * @dataProvider colors
	 */
	public function test_validacion_del_color( string $color, bool $valid ): void {
		$before = $this->snapshot();
		$result = $this->ops()->set_color( array( 'label' => 'X', 'color' => $color ) );

		if ( $valid ) {
			$this->assertIsArray( $result );
			$this->assertSame( $color, $result['color'] );

			return;
		}

		$this->assert_rejected( $result, 'nexo_mcp_divi_design_invalid', $before, 'color' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_labels(): array {
		return array(
			'vacia'         => array( '' ),
			'solo espacios' => array( '   ' ),
			'101'           => array( str_repeat( 'a', 101 ) ),
			'etiquetas'     => array( '<b>x</b>' ),
			'salto'         => array( "a\nb" ),
			'numero'        => array( 5 ),
			'array'         => array( array( 'a' ) ),
			'ausente'       => array( null ),
		);
	}

	/**
	 * @dataProvider bad_labels
	 */
	public function test_etiqueta_invalida_se_rechaza_en_colores_y_variables( $label ): void {
		$before = $this->snapshot();
		$input  = array( 'color' => '#fff' );

		if ( null !== $label ) {
			$input['label'] = $label;
		}

		$this->assert_rejected( $this->ops()->set_color( $input ), 'nexo_mcp_divi_design_invalid', $before, 'label' );

		$input = array( 'type' => 'numbers', 'value' => '1px' );

		if ( null !== $label ) {
			$input['label'] = $label;
		}

		$this->assert_rejected( $this->ops()->set_variable( $input ), 'nexo_mcp_divi_design_invalid', $before, 'label' );
	}

	public function test_la_etiqueta_de_100_caracteres_pasa_y_se_recorta_el_espacio(): void {
		$result = $this->ops()->set_color( array( 'label' => '  ' . str_repeat( 'ñ', 100 ) . '  ', 'color' => '#fff' ) );

		$this->assertSame( str_repeat( 'ñ', 100 ), $this->colors[ $result['id'] ]['label'] );
	}

	public function test_color_id_desconocido_y_ids_del_customizer(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_color( array( 'id' => 'gcid-nope', 'label' => 'X', 'color' => '#fff' ) ), 'nexo_mcp_divi_design_not_found', $before );
		$this->assert_rejected( $this->ops()->set_color( array( 'id' => 'otra-cosa', 'label' => 'X', 'color' => '#fff' ) ), 'nexo_mcp_divi_design_invalid', $before, 'id' );
		$this->assert_rejected( $this->ops()->set_color( array( 'id' => "gcid-aaaaaaaaaa\n", 'label' => 'X', 'color' => '#fff' ) ), 'nexo_mcp_divi_design_invalid', $before, 'id' );

		foreach ( array( 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color' ) as $id ) {
			$error = $this->assert_rejected( $this->ops()->set_color( array( 'id' => $id, 'label' => 'X', 'color' => '#fff' ) ), 'nexo_mcp_divi_design_customizer_color', $before, 'id' );
			$this->assertStringContainsString( 'set-divi-typography', $error->get_error_message() );
			$this->assert_rejected( $this->ops()->delete_color( array( 'id' => $id ) ), 'nexo_mcp_divi_design_customizer_color', $before );
		}

		$this->assertSame( array(), $this->scanned );
	}

	public function test_borrar_un_color_sin_uso_lo_quita_y_respalda(): void {
		$result = $this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa' ) );

		$this->assertSame( 'gcid-aaaaaaaaaa', $result['deleted'] );
		$this->assertSame( array(), $result['posts_affected'] );
		$this->assertSame( array(), $this->colors );
		$this->assertSame( array( 'gcid-aaaaaaaaaa' ), array_keys( $this->history()[0]['previous'] ) );
		$this->assertSame( array( 'gcid-aaaaaaaaaa' ), $this->scanned );
		$this->assertSame( 1, $this->flushes );
	}

	public function test_borrar_un_color_en_uso_sin_force_lista_las_entradas_y_no_escribe(): void {
		$this->usage['gcid-aaaaaaaaaa'] = array( 12, 30 );
		$before                         = $this->snapshot();

		$error = $this->assert_rejected( $this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa' ) ), 'nexo_mcp_divi_color_in_use', $before );

		$this->assertSame( array( 12, 30 ), $error->get_error_data()['post_ids'] );
		$this->assertStringContainsString( '12', $error->get_error_message() );
		$this->assertStringContainsString( 'force', $error->get_error_message() );

		$this->assert_rejected( $this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa', 'force' => false ) ), 'nexo_mcp_divi_color_in_use', $before );
	}

	public function test_borrar_un_color_en_uso_con_force_lo_borra_y_avisa_de_las_entradas(): void {
		$this->usage['gcid-aaaaaaaaaa'] = array( 12 );
		$result                         = $this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa', 'force' => true ) );

		$this->assertSame( array( 12 ), $result['posts_affected'] );
		$this->assertSame( array(), $this->colors );
		$this->assertCount( 1, $this->history() );
	}

	public function test_la_busqueda_de_uso_corre_dentro_del_bloqueo_al_borrar(): void {
		$this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa' ) );

		$this->assertSame( array( true ), $this->scan_locked );
		$this->assertSame( array(), $this->colors );
	}

	public function test_el_uso_hallado_dentro_del_bloqueo_sigue_protegiendo_sin_escribir(): void {
		$this->usage['gcid-aaaaaaaaaa'] = array( 7 );
		$before                         = $this->snapshot();

		$this->assert_rejected( $this->ops()->delete_color( array( 'id' => 'gcid-aaaaaaaaaa' ) ), 'nexo_mcp_divi_color_in_use', $before );
		$this->assertSame( array( true ), $this->scan_locked );
	}

	public function test_el_escaneo_real_no_pierde_un_uso_por_ids_con_el_mismo_prefijo(): void {
		$posts = array();

		// 1500 entradas con ids de color que sólo comparten prefijo, y una al final con el uso real.
		for ( $i = 1; $i <= 1500; $i++ ) {
			$posts[ $i ] = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:divi/section --> var(--gcid-aaaaaaaaaa' . $i . ')' );
		}

		$posts[2000] = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:divi/section --> var(--gcid-aaaaaaaaaa)' );
		$posts[2001] = array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_content' => '<!-- wp:divi/section --> var(--gcid-aaaaaaaaaa)' );
		$posts[2002] = array( 'post_type' => 'page', 'post_status' => 'trash', 'post_content' => '<!-- wp:divi/section --> var(--gcid-aaaaaaaaaa)' );
		$posts[2003] = array( 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => '<!-- wp:divi/section --> {"name":"gcid-aaaaaaaaaa","settings":{}}' );
		$posts[2004] = array( 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'sin divi gcid-aaaaaaaaaa' );

		$saved             = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']   = new class( $posts ) extends \WPDB_Test_Double {
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

					if ( count( $out ) >= (int) ( $limit[1] ?? 1000 ) ) {
						break;
					}
				}

				return $out;
			}
		};

		try {
			$found = DiviDesignOperations::scan_color_usage( 'gcid-aaaaaaaaaa' );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}

		$this->assertSame( array( 2000, 2003 ), $found );
	}

	public function test_borrar_un_color_desconocido_no_escanea_ni_escribe(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->delete_color( array( 'id' => 'gcid-nope' ) ), 'nexo_mcp_divi_design_not_found', $before );
		$this->assert_rejected( $this->ops()->delete_color( array() ), 'nexo_mcp_divi_design_invalid', $before, 'id' );
		$this->assertSame( array(), $this->scanned );
	}

	// --- Variables -----------------------------------------------------------------------------

	public function test_crear_una_variable_asigna_gvid_orden_y_estado_y_respalda_solo_ese_tipo(): void {
		$result = $this->ops()->set_variable( array( 'type' => 'numbers', 'label' => 'Espacio', 'value' => '1.5rem' ) );

		$this->assertSame( 'gvid-abcde12345', $result['id'] );
		$this->assertTrue( $result['created'] );
		$this->assertSame(
			array( 'label' => 'Espacio', 'value' => '1.5rem', 'order' => 2, 'status' => 'active' ),
			$this->variables['numbers']['gvid-abcde12345']
		);
		$this->assertSame( 'Roboto', $this->variables['fonts']['gvid-f1']['value'] );
		$this->assertSame( 'variables:numbers', $this->history()[0]['area'] );
		$this->assertSame( 1, $this->flushes );
	}

	public function test_actualizar_una_variable_conserva_orden_y_estado(): void {
		$this->variables['numbers']['gvid-n1']['status'] = 'archived';
		$result = $this->ops()->set_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1', 'label' => 'Radio 2', 'value' => '50%' ) );

		$this->assertFalse( $result['created'] );
		$this->assertSame( array( 'label' => 'Radio 2', 'value' => '50%', 'order' => 1, 'status' => 'archived' ), $this->variables['numbers']['gvid-n1'] );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function values(): array {
		return array(
			'num px'            => array( 'numbers', '12px', true ),
			'num em'            => array( 'numbers', '1.5em', true ),
			'num rem'           => array( 'numbers', '.5rem', true ),
			'num %'             => array( 'numbers', '50%', true ),
			'num vw'            => array( 'numbers', '10vw', true ),
			'num vh'            => array( 'numbers', '10vh', true ),
			'num sin unidad'    => array( 'numbers', '16', true ),
			'num entero json'   => array( 'numbers', 16, true ),
			'num decimal json'  => array( 'numbers', 1.5, true ),
			'num pt'            => array( 'numbers', '12pt', false ),
			'num texto'         => array( 'numbers', 'grande', false ),
			'num calc'          => array( 'numbers', 'calc(1px + 2px)', false ),
			'num salto'         => array( 'numbers', "12px\n", false ),
			'num vacio'         => array( 'numbers', '', false ),
			'num array'         => array( 'numbers', array( 1 ), false ),
			'str ok'            => array( 'strings', 'Hola mundo', true ),
			'str 200'           => array( 'strings', str_repeat( 'a', 200 ), true ),
			'str 201'           => array( 'strings', str_repeat( 'a', 201 ), false ),
			'str etiquetas'     => array( 'strings', '<script>x</script>', false ),
			'str vacio'         => array( 'strings', '', false ),
			'img url'           => array( 'images', 'https://example.test/a.png', true ),
			'img http'          => array( 'images', 'http://example.test/a.png', true ),
			'img id'            => array( 'images', 55, true ),
			'img id texto'      => array( 'images', '55', true ),
			'img id inexistente' => array( 'images', 999, false ),
			'img javascript'    => array( 'images', 'javascript:alert(1)', false ),
			'img ftp'           => array( 'images', 'ftp://example.test/a.png', false ),
			'img relativa'      => array( 'images', '/a.png', false ),
			'img espacio'       => array( 'images', 'https://example.test/a b.png', false ),
			'img comillas'      => array( 'images', 'https://example.test/a".png', false ),
			'link https'        => array( 'links', 'https://example.test/x?y=1#z', true ),
			'link mailto'       => array( 'links', 'mailto:a@b.test', true ),
			'link tel'          => array( 'links', 'tel:+5491100000000', true ),
			'link relativo'     => array( 'links', '/contacto', true ),
			'link relativo sin barra' => array( 'links', 'contacto', true ),
			'link ancla'        => array( 'links', '#arriba', true ),
			'link javascript'   => array( 'links', 'javascript:alert(1)', false ),
			'link data'         => array( 'links', 'data:text/html,x', false ),
			'link protocolo'    => array( 'links', '//evil.test/x', false ),
			'link vacio'        => array( 'links', '', false ),
			'link espacios'     => array( 'links', '/a b', false ),
			'font ok'           => array( 'fonts', "Open Sans_Bold-2 'x'", true ),
			'font 100'          => array( 'fonts', str_repeat( 'a', 100 ), true ),
			'font 101'          => array( 'fonts', str_repeat( 'a', 101 ), false ),
			'font coma'         => array( 'fonts', 'Roboto, sans-serif', false ),
			'font punto y coma' => array( 'fonts', 'Roboto;', false ),
			'font salto'        => array( 'fonts', "Roboto\n", false ),
		);
	}

	/**
	 * @dataProvider values
	 * @param mixed $value Valor.
	 */
	public function test_validacion_del_valor_por_tipo( string $type, $value, bool $valid ): void {
		$before = $this->snapshot();
		$result = $this->ops()->set_variable( array( 'type' => $type, 'label' => 'V', 'value' => $value ) );

		if ( ! $valid ) {
			$this->assert_rejected( $result, 'nexo_mcp_divi_design_invalid', $before, 'value' );

			return;
		}

		$this->assertIsArray( $result );

		if ( 'images' === $type && is_numeric( $value ) ) {
			$this->assertSame( 'https://example.test/wp-content/uploads/logo.png', $this->variables['images'][ $result['id'] ]['value'] );
		} else {
			$this->assertSame( (string) $value, $this->variables[ $type ][ $result['id'] ]['value'] );
		}
	}

	public function test_variable_tipo_id_y_campos_invalidos(): void {
		$before = $this->snapshot();

		foreach ( array( 'gradients', 'colors', 'otro', '' ) as $type ) {
			$this->assert_rejected( $this->ops()->set_variable( array( 'type' => $type, 'label' => 'V', 'value' => '1' ) ), 'nexo_mcp_divi_design_type_invalid', $before );
		}

		$this->assert_rejected( $this->ops()->set_variable( array( 'label' => 'V', 'value' => '1' ) ), 'nexo_mcp_divi_design_type_invalid', $before );
		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => 'numbers', 'id' => 'gvid-nope', 'label' => 'V', 'value' => '1' ) ), 'nexo_mcp_divi_design_not_found', $before );
		// El id existe pero en otro tipo.
		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => 'numbers', 'id' => 'gvid-f1', 'label' => 'V', 'value' => '1' ) ), 'nexo_mcp_divi_design_not_found', $before );
		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => 'numbers', 'id' => 'xx', 'label' => 'V', 'value' => '1' ) ), 'nexo_mcp_divi_design_invalid', $before, 'id' );
		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => 'numbers', 'label' => 'V' ) ), 'nexo_mcp_divi_design_invalid', $before, 'value' );
	}

	public function test_archivar_y_reactivar_una_variable_no_la_borra(): void {
		$ops    = $this->ops();
		$result = $ops->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => true ) );

		$this->assertSame( 'archived', $result['status'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'archived', $this->variables['numbers']['gvid-n1']['status'] );
		$this->assertSame( '12px', $this->variables['numbers']['gvid-n1']['value'] );
		$this->assertSame( 'active', $this->history()[0]['previous']['gvid-n1']['status'] );

		$result = $ops->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => false ) );
		$this->assertSame( 'active', $this->variables['numbers']['gvid-n1']['status'] );
		$this->assertCount( 2, $this->history() );
	}

	public function test_archivar_lo_que_ya_esta_asi_no_escribe(): void {
		$before = $this->snapshot();
		$result = $this->ops()->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => false ) );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->history() );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_archivar_con_datos_invalidos_no_escribe(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-nope', 'archived' => true ) ), 'nexo_mcp_divi_design_not_found', $before );
		$this->assert_rejected( $this->ops()->archive_variable( array( 'type' => 'gradients', 'id' => 'gvid-n1', 'archived' => true ) ), 'nexo_mcp_divi_design_type_invalid', $before );
		$this->assert_rejected( $this->ops()->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1' ) ), 'nexo_mcp_divi_design_invalid', $before, 'archived' );
		$this->assert_rejected( $this->ops()->archive_variable( array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => 'yes' ) ), 'nexo_mcp_divi_design_invalid', $before, 'archived' );
		$this->assert_rejected( $this->ops()->archive_variable( array( 'type' => 'numbers', 'archived' => true ) ), 'nexo_mcp_divi_design_invalid', $before, 'id' );
	}

	// --- Tipografía ----------------------------------------------------------------------------

	public function test_tipografia_escribe_las_claves_de_et_divi_de_una_vez_y_respalda(): void {
		$result = $this->ops()->set_typography(
			array(
				'heading_font'        => 'Roboto Slab',
				'body_font'           => 'Lato',
				'heading_font_weight' => 700,
				'body_font_weight'    => 'normal',
				'body_font_size'      => '18px',
				'body_line_height'    => 1.6,
				'colors'              => array( 'accent' => '#ff0000', 'link' => 'rgb(0, 0, 255)' ),
			)
		);

		$this->assertSame( 'Roboto Slab', $this->options['heading_font'] );
		$this->assertSame( 'Lato', $this->options['body_font'] );
		$this->assertSame( '700', $this->options['heading_font_weight'] );
		$this->assertSame( 'normal', $this->options['body_font_weight'] );
		$this->assertSame( '18', $this->options['body_font_size'] );
		$this->assertSame( '1.6', $this->options['body_font_height'] );
		$this->assertSame( '#ff0000', $this->options['accent_color'] );
		$this->assertSame( 'rgb(0, 0, 255)', $this->options['link_color'] );
		$this->assertSame( 'x', $this->options['unrelated_setting'] );
		$this->assertSame( 'theme_options', $this->history()[0]['area'] );
		$this->assertSame( 'set-divi-typography', $this->history()[0]['tool'] );
		$this->assertSame( 1, $this->flushes );
		$this->assertCount( 8, $result['changed'] );
	}

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function bad_typography(): array {
		return array(
			'fuente con coma'      => array( array( 'heading_font' => 'A, B' ), 'heading_font' ),
			'fuente 101'           => array( array( 'body_font' => str_repeat( 'a', 101 ) ), 'body_font' ),
			'fuente salto'         => array( array( 'body_font' => "Lato\n" ), 'body_font' ),
			'peso 50'              => array( array( 'heading_font_weight' => 50 ), 'heading_font_weight' ),
			'peso 1000'            => array( array( 'body_font_weight' => '1000' ), 'body_font_weight' ),
			'peso 450'             => array( array( 'body_font_weight' => 450 ), 'body_font_weight' ),
			'peso texto'           => array( array( 'body_font_weight' => 'heavy' ), 'body_font_weight' ),
			'peso salto'           => array( array( 'body_font_weight' => "bold\n" ), 'body_font_weight' ),
			'tamaño 7'             => array( array( 'body_font_size' => 7 ), 'body_font_size' ),
			'tamaño 73'            => array( array( 'body_font_size' => '73px' ), 'body_font_size' ),
			'tamaño em'            => array( array( 'body_font_size' => '2em' ), 'body_font_size' ),
			'tamaño texto'         => array( array( 'body_font_size' => 'grande' ), 'body_font_size' ),
			'altura 0.9'           => array( array( 'body_line_height' => 0.9 ), 'body_line_height' ),
			'altura 3.1'           => array( array( 'body_line_height' => 3.1 ), 'body_line_height' ),
			'altura texto'         => array( array( 'body_line_height' => 'alta' ), 'body_line_height' ),
			'color invalido'       => array( array( 'colors' => array( 'accent' => 'red' ) ), 'colors.accent' ),
			'color desconocido'    => array( array( 'colors' => array( 'primary' => '#fff' ) ), 'colors.primary' ),
			'colors no objeto'     => array( array( 'colors' => '#fff' ), 'colors' ),
			'clave desconocida'    => array( array( 'heading_font' => 'Lato', 'siteurl' => 'x' ), 'siteurl' ),
		);
	}

	/**
	 * @dataProvider bad_typography
	 */
	public function test_tipografia_invalida_se_rechaza_entera_sin_escribir( array $input, string $path ): void {
		$before = $this->snapshot();

		// Un campo válido junto al inválido tampoco se escribe.
		$input += array( 'body_font' => 'Lato' );

		$this->assert_rejected( $this->ops()->set_typography( $input ), 'nexo_mcp_divi_design_invalid', $before, $path );
	}

	public function test_tipografia_sin_nada_que_cambiar_y_limites_validos(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_typography( array() ), 'nexo_mcp_divi_design_nothing_to_change', $before );
		$this->assert_rejected( $this->ops()->set_typography( array( 'colors' => array() ) ), 'nexo_mcp_divi_design_nothing_to_change', $before );

		$this->assertIsArray( $this->ops()->set_typography( array( 'body_font_size' => 8, 'body_line_height' => 1, 'heading_font_weight' => 100, 'body_font_weight' => 'bold' ) ) );
		$this->assertIsArray( $this->ops()->set_typography( array( 'body_font_size' => '72px', 'body_line_height' => 3, 'heading_font_weight' => '900' ) ) );
		$this->assertSame( '72', $this->options['body_font_size'] );
	}

	// --- Sin Divi ------------------------------------------------------------------------------

	public function test_sin_divi_5_ninguna_escribe_ni_escanea(): void {
		$this->active = false;
		$before       = $this->snapshot();
		$ops          = $this->ops();
		$inputs       = array(
			'set_color'       => array( 'label' => 'X', 'color' => '#fff' ),
			'delete_color'    => array( 'id' => 'gcid-aaaaaaaaaa' ),
			'set_variable'    => array( 'type' => 'numbers', 'label' => 'V', 'value' => '1' ),
			'archive_variable' => array( 'type' => 'numbers', 'id' => 'gvid-n1', 'archived' => true ),
			'set_typography'  => array( 'body_font' => 'Lato' ),
		);

		foreach ( $inputs as $method => $input ) {
			$this->assert_rejected( $ops->$method( $input ), 'nexo_mcp_divi5_required', $before );
		}

		$this->assertSame( array(), $this->scanned );
	}

	public function test_deshacer_cada_cambio_en_orden_inverso_devuelve_el_original(): void {
		$before = $this->snapshot();
		$ops    = $this->ops();

		$ops->set_color( array( 'label' => 'Marca', 'color' => '#ff0000' ) );
		$ops->set_variable( array( 'type' => 'fonts', 'label' => 'F', 'value' => 'Lato' ) );
		$ops->set_typography( array( 'body_font_size' => 20 ) );

		$store = new DiviDesignStore(
			fn(): bool => true,
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
				if ( null === $value ) {
					unset( $this->options[ $key ] );
				} else {
					$this->options[ $key ] = $value;
				}
			},
			function (): void {},
			function ( string $tool, string $status, $params, $result ): void {},
			fn( callable $work ) => $work()
		);

		$this->assertIsArray( $store->undo() );
		$this->assertSame( $before[2], $this->options );
		$this->assertIsArray( $store->undo() );
		$this->assertIsArray( $store->undo() );
		$this->assertSame( $before[0], $this->colors );
		$this->assertSame( 'archived', $this->variables['fonts']['gvid-zzzzz99999']['status'], 'lo creado queda archivado, no se borra' );
	}

	// --- Parche 1.9.1: sin tokens dinámicos en valores globales ----------------------------------

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function dynamic_variable_values(): array {
		$token = '$variable({"type":"content","value":{"name":"custom_meta__edit_last","settings":{}}})$';

		return array(
			'strings'         => array( 'strings', $token ),
			'strings in text' => array( 'strings', 'Hola ' . $token ),
			'strings entity'  => array( 'strings', '&#36;variable(x)' ),
			'strings case'    => array( 'strings', '$VARIABLE(x)' ),
			'links'           => array( 'links', '/x?a=' . $token ),
			'links encoded'   => array( 'links', '/x?a=%24variable(' ),
			'images'          => array( 'images', 'https://ex.test/a.png?x=$variable(' ),
		);
	}

	/**
	 * @dataProvider dynamic_variable_values
	 * @param mixed $value Valor.
	 */
	public function test_una_variable_global_no_lleva_tokens_dinamicos_y_no_escribe(string $type, $value ): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => $type, 'label' => 'V', 'value' => $value ) ), 'nexo_mcp_divi_dynamic_denied', $before, 'value' );
	}

	public function test_una_etiqueta_con_token_dinamico_se_rechaza_en_variables_y_colores(): void {
		$before = $this->snapshot();

		$this->assert_rejected( $this->ops()->set_variable( array( 'type' => 'numbers', 'label' => '$variable(x)$', 'value' => '1' ) ), 'nexo_mcp_divi_dynamic_denied', $before, 'label' );
		$this->assert_rejected( $this->ops()->set_color( array( 'label' => '&#36;variable(x)', 'color' => '#123456' ) ), 'nexo_mcp_divi_dynamic_denied', $before, 'label' );
	}

	public function test_los_valores_normales_de_variables_siguen_igual(): void {
		$this->suffixes = array( 'aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc' );
		$this->assertIsArray( $this->ops()->set_variable( array( 'type' => 'strings', 'label' => 'Lema', 'value' => 'Precio $5 (variable)' ) ) );
		$this->assertIsArray( $this->ops()->set_variable( array( 'type' => 'links', 'label' => 'Tienda', 'value' => 'https://ex.test/tienda?a=1' ) ) );
		$this->assertIsArray( $this->ops()->set_variable( array( 'type' => 'images', 'label' => 'Logo', 'value' => 'https://ex.test/logo.png' ) ) );
		$this->assertSame( 'Precio $5 (variable)', $this->variables['strings']['gvid-aaaaaaaaaa']['value'] );
	}
}
