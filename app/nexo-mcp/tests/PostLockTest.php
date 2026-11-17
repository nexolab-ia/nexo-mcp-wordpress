<?php
/**
 * Bloqueo por original: atómico, con vencimiento, con dueño y con espera acotada.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\WordPress\ReadGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;
use NexoDev\NexoMCP\WordPress\WriteGateway;

final class PostLockTest extends NexoTestCase {

	private const KEY = 'nexo_mcp_lock_100';

	protected function setUp(): void {
		parent::setUp();
		PostLock::$wait = 0.2;
	}

	protected function tearDown(): void {
		PostLock::$wait = 5.0;
		parent::tearDown();
	}

	private function foreign_lock( int $expires_in ): void {
		\WP_Test_State::$options[ self::KEY ] = 'otro-proceso|' . ( time() + $expires_in );
	}

	public function test_adquirir_y_un_segundo_intento_falla(): void {
		$token = PostLock::acquire( 100, 0.0 );

		$this->assertIsString( $token );
		$this->assertArrayHasKey( self::KEY, \WP_Test_State::$options );

		$second = PostLock::acquire( 100, 0.0 );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'nexo_mcp_busy', $second->get_error_code() );
		$this->assertStringStartsWith( $token . '|', \WP_Test_State::$options[ self::KEY ] );
	}

	public function test_sólo_el_dueño_libera(): void {
		$token = PostLock::acquire( 100, 0.0 );
		$this->assertIsString( $token );

		$this->assertFalse( PostLock::release( 100, 'token-ajeno' ) );
		$this->assertArrayHasKey( self::KEY, \WP_Test_State::$options );

		$this->assertTrue( PostLock::release( 100, $token ) );
		$this->assertArrayNotHasKey( self::KEY, \WP_Test_State::$options );
		$this->assertIsString( PostLock::acquire( 100, 0.0 ) );
	}

	public function test_un_bloqueo_vencido_se_toma_y_uno_vigente_no(): void {
		$this->foreign_lock( 100 );
		$this->assertInstanceOf( \WP_Error::class, PostLock::acquire( 100, 0.0 ) );

		$this->foreign_lock( -1 );
		$token = PostLock::acquire( 100, 0.0 );

		$this->assertIsString( $token );
		$this->assertStringStartsWith( $token . '|', \WP_Test_State::$options[ self::KEY ] );
	}

	public function test_la_toma_de_un_vencido_es_atomica(): void {
		// El otro competidor ya lo tomó entre la lectura y el UPDATE: la
		// comparación con el valor leído hace fallar al segundo.
		$this->foreign_lock( -1 );
		$stale = \WP_Test_State::$options[ self::KEY ];
		$this->assertTrue( PostLock::take_over( 100, $stale, 'ganador' ) );
		$this->assertFalse( PostLock::take_over( 100, $stale, 'perdedor' ) );
		$this->assertStringStartsWith( 'ganador|', \WP_Test_State::$options[ self::KEY ] );
	}

	public function test_la_espera_es_acotada_y_responde_ocupado(): void {
		$this->foreign_lock( 100 );
		$started = microtime( true );
		$called  = false;

		$result = PostLock::run( 100, static function () use ( &$called ) {
			$called = true;
			return 'x';
		} );

		$this->assertLessThan( 2.0, microtime( true ) - $started );
		$this->assertFalse( $called );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertSame( 'Otra edición de este contenido está en curso. Reintentá en unos segundos.', $result->get_error_message() );
	}

	public function test_run_libera_aunque_falle_y_es_reentrante(): void {
		try {
			PostLock::run( 100, static function () {
				throw new \RuntimeException( 'boom' );
			} );
			$this->fail( 'debía propagar' );
		} catch ( \RuntimeException $e ) {
			$this->assertArrayNotHasKey( self::KEY, \WP_Test_State::$options );
		}

		$inner = PostLock::run( 100, static fn() => PostLock::run( 100, static fn() => 'adentro' ) );

		$this->assertSame( 'adentro', $inner );
		$this->assertArrayNotHasKey( self::KEY, \WP_Test_State::$options );
	}

	public function test_obtain_con_bloqueo_ajeno_no_crea_copia(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->foreign_lock( 100 );

		$result = ( new WorkingCopy( $this->settings() ) )->obtain( \WP_Test_State::$posts[100] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertCount( 1, \WP_Test_State::$posts );
		$this->assertSame( '', get_post_meta( 100, WorkingCopy::META_DRAFT, true ) );
	}

	public function test_obtain_bajo_bloqueo_propio_crea_una_sola_copia_y_libera(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$copies = new WorkingCopy( $this->settings() );

		$first  = $copies->obtain( \WP_Test_State::$posts[100] );
		$second = $copies->obtain( \WP_Test_State::$posts[100] );

		$this->assertInstanceOf( \WP_Post::class, $first );
		$this->assertSame( $first->ID, $second->ID );
		$this->assertCount( 2, \WP_Test_State::$posts );
		$this->assertArrayNotHasKey( self::KEY, \WP_Test_State::$options );
	}

	public function test_la_copia_y_el_original_comparten_bloqueo(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$copy = ( new WorkingCopy( $this->settings() ) )->obtain( \WP_Test_State::$posts[100] );
		$this->assertInstanceOf( \WP_Post::class, $copy );

		$this->foreign_lock( 100 );

		$merge   = ( new WorkingCopy( $this->settings() ) )->merge( $copy );
		$discard = ( new WorkingCopy( $this->settings() ) )->discard( $copy );

		$this->assertSame( 'nexo_mcp_busy', $merge->get_error_code() );
		$this->assertSame( 'nexo_mcp_busy', $discard->get_error_code() );
		$this->assertSame( 'draft', \WP_Test_State::$posts[ $copy->ID ]->post_status );
	}

	public function test_tomar_un_bloqueo_vencido_queda_en_la_auditoria(): void {
		$this->foreign_lock( -40 );

		$token = PostLock::acquire( 100, 0.0 );

		$this->assertIsString( $token );
		$this->assertCount( 1, \WP_Test_State::$audit );
		$row = \WP_Test_State::$audit[0];
		$this->assertSame( 'lock-takeover', $row['tool'] );
		$this->assertSame( 'write', $row['operation'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertStringContainsString( '"post_id":100', $row['parameter_summary'] );
		$this->assertMatchesRegularExpression( '/"seconds_overdue":4[01]\b/', $row['result_summary'] );
	}

	public function test_adquirir_uno_libre_o_perder_la_carrera_no_registra_tomas(): void {
		$this->assertIsString( PostLock::acquire( 100, 0.0 ) );
		$this->assertSame( array(), \WP_Test_State::$audit );

		// Vigente de otro: no se toma, no se registra.
		PostLock::release( 100, (string) strstr( \WP_Test_State::$options[ self::KEY ], '|', true ) );
		$this->foreign_lock( 100 );
		$this->assertInstanceOf( \WP_Error::class, PostLock::acquire( 100, 0.0 ) );

		// Otro lo tomó antes (la comparación falla): el perdedor no registra.
		$this->foreign_lock( -1 );
		$stale = \WP_Test_State::$options[ self::KEY ];
		$this->assertTrue( PostLock::take_over( 100, $stale, 'ganador' ) );
		\WP_Test_State::$audit = array();
		$this->assertFalse( PostLock::take_over( 100, $stale, 'perdedor' ) );
		$this->assertSame( array(), \WP_Test_State::$audit );
	}

	public function test_una_falla_de_la_auditoria_no_impide_tomar_el_bloqueo(): void {
		$this->foreign_lock( -1 );
		$GLOBALS['wpdb'] = new class() extends \WPDB_Test_Double {
			public function insert( string $table, array $row, $format = null ) {
				throw new \RuntimeException( 'tabla de auditoría caída' );
			}
		};

		try {
			$this->assertIsString( PostLock::acquire( 100, 0.0 ) );
		} finally {
			$GLOBALS['wpdb'] = new \WPDB_Test_Double();
		}
	}

	public function test_verify_confirma_el_bloqueo_propio_y_lo_renueva(): void {
		$result = PostLock::run( 100, function () {
			$before = \WP_Test_State::$options[ self::KEY ];
			\WP_Test_State::$options[ self::KEY ] = explode( '|', $before )[0] . '|' . ( time() + 2 );

			return array( PostLock::verify( 100 ), (int) explode( '|', \WP_Test_State::$options[ self::KEY ] )[1] );
		} );

		$this->assertTrue( $result[0] );
		$this->assertGreaterThan( time() + 20, $result[1], 'la comprobación renueva el vencimiento' );
	}

	public function test_verify_sin_bloqueo_en_este_proceso_no_tiene_nada_que_comprobar(): void {
		$this->assertTrue( PostLock::verify( 100 ) );
	}

	public function test_verify_con_el_bloqueo_en_manos_de_otro_responde_ocupado(): void {
		$result = PostLock::run( 100, function () {
			$this->foreign_lock( 100 );

			return PostLock::verify( 100 );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertStringContainsString( 'No se escribió nada', $result->get_error_message() );
		// El bloqueo del otro no se toca ni se libera.
		$this->assertStringStartsWith( 'otro-proceso|', \WP_Test_State::$options[ self::KEY ] );
	}

	public function test_verify_con_el_bloqueo_borrado_responde_ocupado(): void {
		$result = PostLock::run( 100, function () {
			unset( \WP_Test_State::$options[ self::KEY ] );

			return PostLock::verify( 100 );
		} );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
	}

	private function published_page(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => 'viejo' ) );
	}

	public function test_si_el_bloqueo_se_pierde_update_no_escribe_ni_crea_copia(): void {
		$this->published_page();
		$gateway = new WriteGateway( $this->settings( array( 'read_only' => false ) ), new ReadGateway() );

		foreach ( array( false, true ) as $staged ) {
			$this->lose_lock_after_acquiring( 100 );
			$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

			$result = $gateway->update( 100, 'page', array( 'title' => 'Nuevo', 'draft' => $staged ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $staged ? 'con copia' : 'sin copia' );
			$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
			$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
			\WP_Test_State::$options = array();
		}
	}

	public function test_si_el_bloqueo_se_pierde_obtain_no_crea_la_copia(): void {
		$this->published_page();
		$this->lose_lock_after_acquiring( 100 );

		$result = ( new WorkingCopy( $this->settings() ) )->obtain( \WP_Test_State::$posts[100] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertCount( 1, \WP_Test_State::$posts );
		$this->assertSame( '', get_post_meta( 100, WorkingCopy::META_DRAFT, true ) );
	}

	public function test_si_el_bloqueo_se_pierde_ni_merge_ni_discard_escriben(): void {
		$this->published_page();
		$copy = ( new WorkingCopy( $this->settings() ) )->obtain( \WP_Test_State::$posts[100] );
		$this->assertInstanceOf( \WP_Post::class, $copy );
		\WP_Test_State::$posts[ $copy->ID ]->post_content = 'nuevo';
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		foreach ( array( 'merge', 'discard' ) as $action ) {
			$this->lose_lock_after_acquiring( 100 );

			$result = ( new WorkingCopy( $this->settings() ) )->$action( $copy );

			$this->assertInstanceOf( \WP_Error::class, $result, $action );
			$this->assertSame( 'nexo_mcp_busy', $result->get_error_code(), $action );
			$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ), $action );
			\WP_Test_State::$options = array();
		}
	}

	public function test_verify_con_el_bloqueo_propio_ya_vencido_y_no_tomado_lo_renueva(): void {
		$result = PostLock::run( 100, function () {
			$token = explode( '|', \WP_Test_State::$options[ self::KEY ] )[0];
			\WP_Test_State::$options[ self::KEY ] = $token . '|' . ( time() - 5 );

			return array( $token, PostLock::verify( 100 ), \WP_Test_State::$options[ self::KEY ] );
		} );

		$this->assertTrue( $result[1] );
		$parts = explode( '|', $result[2] );
		$this->assertSame( $result[0], $parts[0] );
		$this->assertGreaterThan( time() + 20, (int) $parts[1] );
	}

	public function test_ensure_lock_comprueba_la_clave_retenida_aunque_el_original_ya_no_exista(): void {
		$this->published_page();
		$copy = ( new WorkingCopy( $this->settings() ) )->obtain( \WP_Test_State::$posts[100] );
		$this->assertInstanceOf( \WP_Post::class, $copy );
		$this->lose_lock_after_acquiring( 100 );

		$result = WorkingCopy::with_lock(
			(int) $copy->ID,
			function () use ( $copy ) {
				// El original desaparece a mitad de la operación: la clave recalculada sería la de la copia.
				unset( \WP_Test_State::$posts[100] );

				return WorkingCopy::ensure_lock( (int) $copy->ID );
			},
			false
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
	}
}
