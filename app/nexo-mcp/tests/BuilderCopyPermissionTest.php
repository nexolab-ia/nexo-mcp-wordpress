<?php
/**
 * Herramientas de constructor viejas: con el id de la copia el permiso se pide sobre el original.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class BuilderCopyPermissionTest extends NexoTestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function tools(): array {
		return array(
			'update-node-text'      => array( 'update-node-text' ),
			'update-node-attribute' => array( 'update-node-attribute' ),
			'edit-structure'        => array( 'edit-structure' ),
			'restore-builder-backup' => array( 'restore-builder-backup' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ability( string $tool ): array {
		$settings = $this->settings( array( 'read_only' => false ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		) )->register_abilities();

		return \WP_Test_State::$abilities[ 'nexo-mcp/' . $tool ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input( string $tool, int $id ): array {
		switch ( $tool ) {
			case 'update-node-text':
				return array(
					'id'      => $id,
					'node_id' => 'hd00001',
					'text'    => 'Nuevo',
				);
			case 'update-node-attribute':
				return array(
					'id'        => $id,
					'node_id'   => 'hd00001',
					'attribute' => 'link',
					'value'     => 'https://example.com/',
				);
			case 'edit-structure':
				return array(
					'id'      => $id,
					'node_id' => 'tx00001',
				);
			default:
				return array( 'id' => $id );
		}
	}

	private function put_page( int $id, string $status ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => 'page', 'post_status' => $status, 'post_title' => 'Inicio' ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3() ) );
	}

	private function put_page_and_copy(): void {
		$this->put_page( 100, 'publish' );
		$this->put_page( 101, 'draft' );
		update_post_meta( 101, WorkingCopy::META_DRAFT_OF, 100 );
		update_post_meta( 100, WorkingCopy::META_DRAFT, 101 );
	}

	private function state(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_con_el_id_de_la_copia_el_permiso_se_pide_sobre_el_original( string $tool ): void {
		$this->put_page_and_copy();
		\WP_Test_State::$denied_edit_posts = array( 100 );
		$before                            = $this->state();
		$input                             = $this->input( $tool, 101 );

		$this->assertNotTrue( $this->ability( $tool )['permission_callback']( $input ) );

		$result = $this->ability( $tool )['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->state() );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_con_el_id_de_la_copia_no_importa_el_permiso_sobre_la_copia( string $tool ): void {
		$this->put_page_and_copy();
		$input = $this->input( $tool, 101 );

		if ( 'restore-builder-backup' === $tool ) {
			// Un respaldo propio de la copia, para que restaurar tenga qué deshacer.
			$edit = $this->ability( 'update-node-text' )['execute_callback']( $this->input( 'update-node-text', 101 ) );
			$this->assertNotInstanceOf( \WP_Error::class, $edit );
		}

		\WP_Test_State::$denied_edit_posts = array( 101 );

		$this->assertTrue( $this->ability( $tool )['permission_callback']( $input ) );

		$result = $this->ability( $tool )['execute_callback']( $input );

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'edita' );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_un_borrador_que_el_original_no_reconoce_como_copia_pide_permiso_sobre_si_mismo( string $tool ): void {
		$this->put_page_and_copy();
		delete_post_meta( 100, WorkingCopy::META_DRAFT );
		\WP_Test_State::$denied_edit_posts = array( 101 );
		$before                            = $this->state();

		$result = $this->ability( $tool )['execute_callback']( $this->input( $tool, 101 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->state() );
	}
}
