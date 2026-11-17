<?php
/**
 * Comparación de una copia de trabajo con su original.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class WorkingCopyDiffTest extends NexoTestCase {

	private const ORIGINAL = 10;
	private const COPY     = 11;

	private ContentAdminGateway $gateway;

	protected function setUp(): void {
		parent::setUp();
		$this->gateway = new ContentAdminGateway( $this->settings() );
		$this->link_pair();
	}

	/**
	 * Arma un original publicado y su copia idéntica, enlazados como lo deja WorkingCopy.
	 */
	private function link_pair(): void {
		$fields = array(
			'post_type'         => 'page',
			'post_title'        => 'Inicio',
			'post_content'      => "<p>Hola</p>\n<p>Precio: \$10</p>",
			'post_excerpt'      => 'Resumen',
			'post_modified_gmt' => '2026-02-01 10:00:00',
		);

		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post( array_merge( $fields, array( 'ID' => self::ORIGINAL, 'post_status' => 'publish' ) ) );
		\WP_Test_State::$posts[ self::COPY ]     = new \WP_Post( array_merge( $fields, array( 'ID' => self::COPY, 'post_status' => 'draft' ) ) );

		update_post_meta( self::COPY, WorkingCopy::META_DRAFT_OF, self::ORIGINAL );
		update_post_meta( self::COPY, WorkingCopy::META_BASE, '2026-02-01 10:00:00' );
		update_post_meta( self::ORIGINAL, WorkingCopy::META_DRAFT, self::COPY );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function diff( int $id ): array {
		$result = $this->gateway->get_working_copy_diff( $id );
		$this->assertIsArray( $result );

		return $result;
	}

	private function copy_post(): \WP_Post {
		return \WP_Test_State::$posts[ self::COPY ];
	}

	public function test_se_resuelve_por_el_id_del_original(): void {
		$result = $this->diff( self::ORIGINAL );

		$this->assertSame( self::ORIGINAL, $result['original_id'] );
		$this->assertSame( self::COPY, $result['copy_id'] );
		$this->assertSame( 'https://example.test/?p=11&preview=true', $result['preview_url'] );
	}

	public function test_se_resuelve_por_el_id_de_la_copia(): void {
		$result = $this->diff( self::COPY );

		$this->assertSame( self::ORIGINAL, $result['original_id'] );
		$this->assertSame( self::COPY, $result['copy_id'] );
	}

	public function test_original_sin_copia_da_error_claro(): void {
		delete_post_meta( self::ORIGINAL, WorkingCopy::META_DRAFT );

		$result = $this->gateway->get_working_copy_diff( self::ORIGINAL );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_none', $result->get_error_code() );
		$this->assertStringContainsString( 'no tiene copia de trabajo', $result->get_error_message() );
	}

	public function test_contenido_que_no_es_copia_ni_tiene_una_da_error(): void {
		\WP_Test_State::$posts[ 30 ] = new \WP_Post( array( 'ID' => 30, 'post_status' => 'publish', 'post_type' => 'page' ) );

		$result = $this->gateway->get_working_copy_diff( 30 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_none', $result->get_error_code() );
	}

	public function test_contenido_inexistente_da_error(): void {
		$result = $this->gateway->get_working_copy_diff( 999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_content_not_found', $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function orphan_cases(): array {
		return array(
			'original borrado'            => array( 'deleted' ),
			'original en la papelera'     => array( 'trash' ),
			'original enlazado a otra'    => array( 'other_copy' ),
		);
	}

	/**
	 * @dataProvider orphan_cases
	 */
	public function test_copia_huerfana_da_error_que_sugiere_descartarla( string $case ): void {
		if ( 'deleted' === $case ) {
			unset( \WP_Test_State::$posts[ self::ORIGINAL ] );
		} elseif ( 'trash' === $case ) {
			\WP_Test_State::$posts[ self::ORIGINAL ]->post_status = 'trash';
		} else {
			update_post_meta( self::ORIGINAL, WorkingCopy::META_DRAFT, 99 );
		}

		$result = $this->gateway->get_working_copy_diff( self::COPY );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_orphan', $result->get_error_code() );
		$this->assertStringContainsString( 'Descarta esta copia', $result->get_error_message() );
	}

	public function test_sin_cambios_solo_trae_ids_url_y_stale(): void {
		$this->assertSame(
			array(
				'original_id' => self::ORIGINAL,
				'copy_id'     => self::COPY,
				'preview_url' => 'https://example.test/?p=11&preview=true',
				'stale'       => false,
				'changed'     => array(),
			),
			$this->diff( self::ORIGINAL )
		);
	}

	public function test_titulo_y_extracto_traen_antes_y_despues(): void {
		$this->copy_post()->post_title   = 'Inicio 2027';
		$this->copy_post()->post_excerpt = 'Resumen nuevo';

		$result = $this->diff( self::ORIGINAL );

		$this->assertSame( array( 'title', 'excerpt' ), $result['changed'] );
		$this->assertSame( array( 'before' => 'Inicio', 'after' => 'Inicio 2027' ), $result['title'] );
		$this->assertSame( array( 'before' => 'Resumen', 'after' => 'Resumen nuevo' ), $result['excerpt'] );
		$this->assertArrayNotHasKey( 'content_diff', $result );
		$this->assertArrayNotHasKey( 'meta', $result );
		$this->assertArrayNotHasKey( 'terms', $result );
	}

	public function test_el_contenido_cambiado_trae_diff_unificado(): void {
		$this->copy_post()->post_content = "<p>Hola</p>\n<p>Precio: \$12</p>";

		$result = $this->diff( self::ORIGINAL );

		$this->assertSame( array( 'content' ), $result['changed'] );
		$this->assertSame( "@@ -1,2 +1,2 @@\n <p>Hola</p>\n-<p>Precio: \$10</p>\n+<p>Precio: \$12</p>", $result['content_diff'] );
		$this->assertArrayNotHasKey( 'content_diff_truncated', $result );
		$this->assertArrayNotHasKey( 'title', $result );
	}

	public function test_un_diff_demasiado_grande_se_corta_y_lo_avisa(): void {
		\WP_Test_State::$posts[ self::ORIGINAL ]->post_content = implode( "\n", array_map( static fn( $n ) => 'linea antigua ' . $n, range( 1, 3000 ) ) );
		$this->copy_post()->post_content                       = implode( "\n", array_map( static fn( $n ) => 'linea nueva ' . $n, range( 1, 3000 ) ) );

		$result = $this->diff( self::ORIGINAL );

		$this->assertTrue( $result['content_diff_truncated'] );
		$this->assertLessThanOrEqual( ContentAdminGateway::DIFF_MAX_BYTES, strlen( $result['content_diff'] ) );
		$this->assertStringStartsWith( '@@ -1,3000 +1,3000 @@', $result['content_diff'] );
	}

	public function test_meta_cambiada_agregada_y_quitada_con_valores_cortos(): void {
		update_post_meta( self::ORIGINAL, 'color', 'rojo' );
		update_post_meta( self::COPY, 'color', 'azul' );
		update_post_meta( self::COPY, 'nuevo', 7 );
		update_post_meta( self::ORIGINAL, 'viejo', 'x' );
		update_post_meta( self::ORIGINAL, 'igual', 'y' );
		update_post_meta( self::COPY, 'igual', 'y' );

		$result = $this->diff( self::ORIGINAL );

		$this->assertSame( array( 'meta' ), $result['changed'] );
		$this->assertSame(
			array(
				'changed' => array( array( 'key' => 'color', 'before' => 'rojo', 'after' => 'azul' ) ),
				'added'   => array( array( 'key' => 'nuevo', 'before' => null, 'after' => 7 ) ),
				// Existe en el original y falta en la copia: la fusión nunca borra
				// meta, así que al publicar el original la conserva tal cual.
				'kept_on_publish' => array( array( 'key' => 'viejo', 'before' => 'x', 'after' => 'x' ) ),
			),
			$result['meta']
		);
	}

	public function test_meta_larga_o_estructurada_solo_dice_cuanto_mide(): void {
		update_post_meta( self::ORIGINAL, '_elementor_data', str_repeat( 'a', 300 ) );
		update_post_meta( self::COPY, '_elementor_data', str_repeat( 'b', 450 ) );
		update_post_meta( self::ORIGINAL, 'lista', array( 'a' ) );
		update_post_meta( self::COPY, 'lista', array( 'a', 'b' ) );

		$meta = $this->diff( self::ORIGINAL )['meta'];

		$this->assertSame(
			array(
				array( 'key' => '_elementor_data', 'before_bytes' => 300, 'after_bytes' => 450 ),
				array( 'key' => 'lista', 'before_bytes' => strlen( (string) wp_json_encode( array( 'a' ) ) ), 'after_bytes' => strlen( (string) wp_json_encode( array( 'a', 'b' ) ) ) ),
			),
			$meta['changed']
		);
	}

	public function test_la_meta_que_la_fusion_no_copia_se_ignora(): void {
		update_post_meta( self::ORIGINAL, '_edit_lock', '1:1' );
		update_post_meta( self::COPY, '_edit_lock', '2:2' );
		update_post_meta( self::COPY, '_elementor_css', 'css' );
		update_post_meta( self::COPY, '_nexo_mcp_algo', 'x' );
		update_post_meta( self::COPY, '_wp_trash_meta_status', 'publish' );

		$this->assertSame( array(), $this->diff( self::ORIGINAL )['changed'] );
	}

	public function test_terminos_agregados_y_quitados_por_taxonomia(): void {
		\WP_Test_State::$object_taxonomies['page'] = array( 'category', 'post_tag', 'post_translations' );
		\WP_Test_State::$terms[ self::ORIGINAL ]   = array(
			'category'          => array( 'noticias' => 1, 'ofertas' => 2 ),
			'post_tag'          => array( 'verano' => 3 ),
			'post_translations' => array( 'grupo-a' => 9 ),
		);
		\WP_Test_State::$terms[ self::COPY ]       = array(
			'category'          => array( 'noticias' => 1, 'eventos' => 4 ),
			'post_tag'          => array( 'verano' => 3 ),
			'post_translations' => array( 'grupo-b' => 8 ),
		);

		$result = $this->diff( self::ORIGINAL );

		$this->assertSame( array( 'terms' ), $result['changed'] );
		$this->assertSame(
			array( 'category' => array( 'added' => array( 'eventos' ), 'removed' => array( 'ofertas' ) ) ),
			$result['terms']
		);
	}

	public function test_stale_es_falso_con_el_original_intacto_y_verdadero_si_cambio(): void {
		$this->assertFalse( $this->diff( self::ORIGINAL )['stale'] );

		\WP_Test_State::$posts[ self::ORIGINAL ]->post_modified_gmt = '2026-03-01 00:00:00';

		$this->assertTrue( $this->diff( self::ORIGINAL )['stale'] );
		$this->assertTrue( $this->diff( self::COPY )['stale'] );
	}

	public function test_merge_y_diff_comparten_la_regla_de_obsolescencia(): void {
		$copies   = new WorkingCopy( $this->settings() );
		$original = \WP_Test_State::$posts[ self::ORIGINAL ];

		$this->assertFalse( $copies->is_stale( $this->copy_post(), $original ) );

		$original->post_modified_gmt = '2026-03-01 00:00:00';
		$this->assertTrue( $copies->is_stale( $this->copy_post(), $original ) );

		// Sin base registrada, la copia no se puede fusionar: también es obsoleta.
		delete_post_meta( self::COPY, WorkingCopy::META_BASE );
		$original->post_modified_gmt = '2026-02-01 10:00:00';
		$this->assertTrue( $copies->is_stale( $this->copy_post(), $original ) );
	}

	public function test_sin_permiso_de_edicion_sobre_el_original_se_rechaza(): void {
		\WP_Test_State::$caps['edit_post'] = false;

		$result = $this->gateway->get_working_copy_diff( self::ORIGINAL );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_content_forbidden', $result->get_error_code() );
	}

	public function test_la_herramienta_resuelve_el_permiso_contra_el_original(): void {
		$this->assertSame( self::ORIGINAL, WorkingCopy::original_id_for( self::COPY ) );
		$this->assertSame( self::ORIGINAL, WorkingCopy::original_id_for( self::ORIGINAL ) );
		$this->assertSame( 999, WorkingCopy::original_id_for( 999 ) );
	}

	public function test_permiso_de_copia_huerfana_con_original_borrado_se_resuelve_contra_la_copia(): void {
		unset( \WP_Test_State::$posts[ self::ORIGINAL ] );

		// S4: un original borrado no debe convertirse en una denegación de
		// permiso; el gateway responde nexo_mcp_draft_orphan.
		$this->assertSame( self::COPY, WorkingCopy::original_id_for( self::COPY ) );
		$this->assertSame( 'nexo_mcp_draft_orphan', $this->gateway->get_working_copy_diff( self::COPY )->get_error_code() );
	}
}
