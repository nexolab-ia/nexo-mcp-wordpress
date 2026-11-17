<?php
/**
 * Bloqueo por original: atómico, con vencimiento, con dueño y con espera acotada.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

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
}
