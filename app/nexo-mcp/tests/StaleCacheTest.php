<?php
/**
 * Dentro del bloqueo nada se usa tal como se leyó antes de esperarlo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\WordPress\ReadGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;
use NexoDev\NexoMCP\WordPress\WriteGateway;

final class StaleCacheTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private WorkingCopy $copies;

	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::$caps['read_post'] = true;
		$settings = $this->settings( array( 'read_only' => false ) );
		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post(
			array( 'ID' => self::ORIGINAL, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Original', 'post_content' => 'base' )
		);
		$this->copies = new WorkingCopy( $settings );
	}

	protected function tearDown(): void {
		PostLock::$wait = 5.0;
		parent::tearDown();
	}

	private function make_copy(): \WP_Post {
		$copy = $this->copies->obtain( \WP_Test_State::$posts[ self::ORIGINAL ] );
		$this->assertInstanceOf( \WP_Post::class, $copy );
		\WP_Test_State::$cache_posts = true;
		\WP_Test_State::$post_cache  = array();

		return $copy;
	}

	private function gateway(): WriteGateway {
		return new WriteGateway( $this->settings( array( 'read_only' => false ) ), new ReadGateway() );
	}

	public function test_update_no_pisa_con_valores_viejos_lo_que_otro_escribio_antes(): void {
		$copy = $this->make_copy();
		get_post( $copy->ID ); // La caché queda con el título viejo (lo lee, por ejemplo, original_id_for).
		\WP_Test_State::$posts[ $copy->ID ]->post_title = 'Titulo de otro proceso';

		$result = $this->gateway()->update( self::ORIGINAL, 'page', array( 'excerpt' => 'mio' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Titulo de otro proceso', \WP_Test_State::$posts[ $copy->ID ]->post_title );
		$this->assertSame( 'mio', \WP_Test_State::$posts[ $copy->ID ]->post_excerpt );
	}

	public function test_merge_publica_lo_que_hay_en_la_copia_ahora_no_lo_que_vio_quien_llamo(): void {
		$copy = $this->make_copy();
		\WP_Test_State::$posts[ $copy->ID ]->post_content = 'v1';
		$caller = get_post( $copy->ID ); // El llamador cargó la copia antes de esperar el bloqueo.
		update_post_meta( $copy->ID, WorkingCopy::META_BASE, (string) \WP_Test_State::$posts[ self::ORIGINAL ]->post_modified_gmt );
		\WP_Test_State::$posts[ $copy->ID ]->post_content = 'v2';

		$result = $this->copies->merge( $caller );

		$this->assertIsArray( $result );
		$this->assertSame( 'v2', \WP_Test_State::$posts[ self::ORIGINAL ]->post_content );
		$this->assertArrayNotHasKey( $copy->ID, \WP_Test_State::$posts );
	}

	public function test_merge_de_una_copia_que_ya_no_es_borrador_no_publica_ni_borra(): void {
		$copy   = $this->make_copy();
		$caller = get_post( $copy->ID );
		\WP_Test_State::$posts[ $copy->ID ]->post_content = 'descartada';
		\WP_Test_State::$posts[ $copy->ID ]->post_status  = 'trash';

		$result = $this->copies->merge( $caller );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_orphan', $result->get_error_code() );
		$this->assertSame( 'base', \WP_Test_State::$posts[ self::ORIGINAL ]->post_content );
		$this->assertArrayHasKey( $copy->ID, \WP_Test_State::$posts );
	}

	public function test_discard_de_una_copia_que_ya_no_es_copia_no_toca_el_original(): void {
		$copy   = $this->make_copy();
		$caller = get_post( $copy->ID );
		delete_post_meta( $copy->ID, WorkingCopy::META_DRAFT_OF );

		$result = $this->copies->discard( $caller );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_not_copy', $result->get_error_code() );
		$this->assertSame( 'draft', \WP_Test_State::$posts[ $copy->ID ]->post_status );
	}

	public function test_escribir_por_el_id_de_una_copia_descartada_se_rechaza(): void {
		$copy = $this->make_copy();
		get_post( $copy->ID );
		\WP_Test_State::$posts[ $copy->ID ]->post_status = 'trash';
		$antes = \WP_Test_State::$posts[ $copy->ID ]->post_excerpt;

		$result = $this->gateway()->update( $copy->ID, 'page', array( 'excerpt' => 'mio' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_orphan', $result->get_error_code() );
		$this->assertSame( $antes, \WP_Test_State::$posts[ $copy->ID ]->post_excerpt );
	}
}
