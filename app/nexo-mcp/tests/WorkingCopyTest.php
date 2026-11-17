<?php
/**
 * Edición de contenido publicado: primero a una copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class WorkingCopyTest extends NexoTestCase {

	public function test_editar_publicado_va_a_copia_por_defecto(): void {
		$post = new \WP_Post( array( 'ID' => 10, 'post_status' => 'publish' ) );

		$this->assertTrue( WorkingCopy::should_stage( $post, array( 'id' => 10 ), $this->settings() ) );
	}

	public function test_el_cliente_no_puede_saltarse_la_copia_con_draft_false(): void {
		$post = new \WP_Post( array( 'ID' => 10, 'post_status' => 'publish' ) );

		$this->assertTrue( WorkingCopy::should_stage( $post, array( 'id' => 10, 'draft' => false ), $this->settings() ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function live_statuses(): array {
		return array(
			'publicado'  => array( 'publish' ),
			'programado' => array( 'future' ),
			'privado'    => array( 'private' ),
		);
	}

	/**
	 * @dataProvider live_statuses
	 */
	public function test_todo_lo_que_esta_a_la_vista_se_edita_en_copia( string $status ): void {
		$post = new \WP_Post( array( 'ID' => 11, 'post_status' => $status ) );

		$this->assertTrue( WorkingCopy::should_stage( $post, array(), $this->settings() ) );
	}

	public function test_un_borrador_se_edita_directamente(): void {
		$post = new \WP_Post( array( 'ID' => 12, 'post_status' => 'draft' ) );

		$this->assertFalse( WorkingCopy::should_stage( $post, array( 'draft' => true ), $this->settings() ) );
	}

	public function test_con_el_ajuste_apagado_la_copia_es_opcional(): void {
		$post     = new \WP_Post( array( 'ID' => 13, 'post_status' => 'publish' ) );
		$settings = $this->settings( array( 'working_copy_default' => false ) );

		$this->assertFalse( WorkingCopy::should_stage( $post, array(), $settings ) );
		$this->assertTrue( WorkingCopy::should_stage( $post, array( 'draft' => true ), $settings ) );
	}

	public function test_una_copia_reconoce_a_su_original(): void {
		$copy = new \WP_Post( array( 'ID' => 21, 'post_status' => 'draft' ) );
		update_post_meta( 21, WorkingCopy::META_DRAFT_OF, 20 );

		$this->assertSame( 20, WorkingCopy::original_of( $copy ) );

		// Una copia publicada a mano deja de ser copia.
		$copy->post_status = 'publish';
		$this->assertSame( 0, WorkingCopy::original_of( $copy ) );
	}
}
