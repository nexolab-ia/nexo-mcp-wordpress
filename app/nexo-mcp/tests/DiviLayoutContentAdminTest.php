<?php
/**
 * Las herramientas de contenido que alcanzan un contenido por id y NO son de constructor (get-working-copy-diff,
 * restore-revision, set-content-status) y los layouts del Theme Builder (T5): un layout o su copia de trabajo sólo se
 * leen, restauran, publican o descartan con el permiso del Theme Builder; los demás tipos `et_*` nunca; y las páginas no cambian.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLayoutContentAdminTest extends NexoTestCase {

	private const SWITCH = 'divi_theme_builder_tools_enabled';

	/**
	 * Revisiones de WordPress simuladas: id de revisión => contenido que restaura.
	 *
	 * @var array<int, string>
	 */
	public static array $restored = array();

	protected function setUp(): void {
		parent::setUp();
		self::$restored = array();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		require_once __DIR__ . '/DiviLayoutWpStubs.php';

		$content = Divi5SectionEditorTest::page();

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => $content ) );
		\WP_Test_State::$posts[200] = new \WP_Post( array( 'ID' => 200, 'post_type' => 'et_body_layout', 'post_status' => 'publish', 'post_title' => 'Cuerpo', 'post_content' => $content ) );
		\WP_Test_State::$posts[300] = new \WP_Post( array( 'ID' => 300, 'post_type' => 'et_template', 'post_status' => 'publish', 'post_title' => 'Plantilla', 'post_content' => '' ) );

		// Revisiones: 500 de la página, 501 del layout, 502 de una plantilla.
		foreach ( array( 500 => 100, 501 => 200, 502 => 300 ) as $revision => $parent ) {
			\WP_Test_State::$posts[ $revision ] = new \WP_Post( array( 'ID' => $revision, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_content' => 'revision-' . $revision ) );
		}
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	private function tools( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, 'publishing_enabled' => true, 'content_tools_enabled' => true, 'working_copy_default' => true ), $settings ) );

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

		$short = array();

		foreach ( \WP_Test_State::$abilities as $name => $ability ) {
			$short[ substr( $name, strlen( 'nexo-mcp/' ) ) ] = $ability;
		}

		return $short;
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta, self::$restored ) );
	}

	/**
	 * Edita el layout con el permiso del Theme Builder y devuelve el id de su copia de trabajo.
	 */
	private function layout_copy( int $original = 200 ): int {
		$fingerprint = (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), '0.0' )['fingerprint'] ?? '' );
		$result      = $this->tools( array( self::SWITCH => true ) )['edit-divi-section']['execute_callback'](
			array( 'id' => $original, 'operation' => 'replace', 'path' => '0.0', 'fingerprint' => $fingerprint, 'sections' => Divi5SectionEditorTestFixtures::sections( 'Cambiado' ) )
		);

		$this->assertIsArray( $result );

		return (int) $result['id'];
	}

	public function test_the_working_copy_diff_of_a_layout_needs_the_theme_builder_permission(): void {
		$copy  = $this->layout_copy();
		$state = $this->snapshot();

		foreach ( array( 200, $copy ) as $id ) {
			$tools = $this->tools( array( self::SWITCH => false ) );

			$this->assertNotTrue( $tools['get-working-copy-diff']['permission_callback']( array( 'id' => $id ) ), "permiso $id" );

			$result = $tools['get-working-copy-diff']['execute_callback']( array( 'id' => $id ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code() );
			$this->assertSame( $state, $this->snapshot() );
		}

		$tools = $this->tools( array( self::SWITCH => true, 'content_tools_enabled' => false ) );

		$this->assertTrue( $tools['get-working-copy-diff']['permission_callback']( array( 'id' => $copy ) ) );

		$diff = $tools['get-working-copy-diff']['execute_callback']( array( 'id' => $copy ) );

		$this->assertIsArray( $diff );
		$this->assertSame( 200, $diff['original_id'] );
		$this->assertSame( $copy, $diff['copy_id'] );
		$this->assertContains( 'content', $diff['changed'] );
	}

	public function test_the_working_copy_diff_rejects_the_other_et_types(): void {
		$state = $this->snapshot();
		$tools = $this->tools( array( self::SWITCH => true ) );

		$result = $tools['get-working-copy-diff']['execute_callback']( array( 'id' => 300 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_post_type_denied', $result->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
	}

	public function test_restore_revision_of_a_layout_needs_the_theme_builder_permission_and_writes_nothing_without_it(): void {
		$state = $this->snapshot();
		$tools = $this->tools( array( self::SWITCH => false ) );

		$this->assertNotTrue( $tools['restore-revision']['permission_callback']( array( 'id' => 501 ) ) );

		$result = $tools['restore-revision']['execute_callback']( array( 'id' => 501, 'confirmation' => 'RESTORE' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_layout_denied', $result->get_error_code() );
		$this->assertSame( $state, $this->snapshot(), 'no se restauró nada' );
		$this->assertSame( array(), self::$restored );

		$tools = $this->tools( array( self::SWITCH => true ) );

		$this->assertTrue( $tools['restore-revision']['permission_callback']( array( 'id' => 501 ) ) );

		$restored = $tools['restore-revision']['execute_callback']( array( 'id' => 501, 'confirmation' => 'RESTORE' ) );

		$this->assertIsArray( $restored );
		$this->assertSame( 200, $restored['id'] );
		$this->assertSame( array( 501 ), array_keys( self::$restored ) );
	}

	public function test_restore_revision_of_another_et_type_is_rejected_even_with_the_theme_builder_permission(): void {
		$state  = $this->snapshot();
		$result = $this->tools( array( self::SWITCH => true ) )['restore-revision']['execute_callback']( array( 'id' => 502, 'confirmation' => 'RESTORE' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_post_type_denied', $result->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
		$this->assertSame( array(), self::$restored );
	}

	public function test_preserve_restore_revision_of_a_page_is_unchanged(): void {
		$tools = $this->tools( array( self::SWITCH => false ) );

		$this->assertTrue( $tools['restore-revision']['permission_callback']( array( 'id' => 500 ) ) );

		$restored = $tools['restore-revision']['execute_callback']( array( 'id' => 500, 'confirmation' => 'RESTORE' ) );

		$this->assertIsArray( $restored );
		$this->assertSame( 100, $restored['id'] );
		$this->assertSame( 500, $restored['restored_revision'] );
		$this->assertSame( array( 500 ), array_keys( self::$restored ) );

		$missing = $tools['restore-revision']['execute_callback']( array( 'id' => 9999, 'confirmation' => 'RESTORE' ) );

		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'nexo_mcp_revision_not_found', $missing->get_error_code() );
	}

	public function test_the_layout_itself_still_cannot_change_status_through_set_content_status(): void {
		$state = $this->snapshot();
		$tools = $this->tools( array( self::SWITCH => true ) );

		foreach ( array( 'trash', 'draft', 'publish', 'private' ) as $status ) {
			$result = $tools['set-content-status']['execute_callback']( array( 'id' => 200, 'status' => $status ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $status );
			$this->assertSame( 'nexo_mcp_content_not_found', $result->get_error_code(), $status );
		}

		$template = $tools['set-content-status']['execute_callback']( array( 'id' => 300, 'status' => 'trash' ) );

		$this->assertInstanceOf( \WP_Error::class, $template );
		$this->assertSame( 'nexo_mcp_content_not_found', $template->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
	}

	public function test_a_batch_applies_the_same_permission_to_each_layout_copy(): void {
		$copy = $this->layout_copy();

		$denied = $this->tools( array( self::SWITCH => false ) )['set-content-status']['execute_callback']( array( 'ids' => array( $copy ), 'status' => 'trash' ) );

		$this->assertIsArray( $denied );
		$this->assertSame( array(), $denied['updated'] ?? array() );
		$this->assertSame( $copy, $denied['failed'][0]['id'] ?? null );
		$this->assertArrayHasKey( $copy, \WP_Test_State::$posts );
		$this->assertSame( 'draft', \WP_Test_State::$posts[ $copy ]->post_status );
	}

	public function test_preserve_a_page_copy_is_still_published_with_edit_post_and_no_theme_builder(): void {
		$tools = $this->tools( array( self::SWITCH => false ) );
		$edit  = $tools['edit-divi-section']['execute_callback'](
			array(
				'id'          => 100,
				'operation'   => 'replace',
				'path'        => '0.0',
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5SectionEditorTest::page() ), '0.0' )['fingerprint'] ?? '' ),
				'sections'    => Divi5SectionEditorTestFixtures::sections( 'Pagina' ),
			)
		);
		$this->assertIsArray( $edit );
		$copy = (int) $edit['id'];

		$this->assertTrue( $tools['set-content-status']['permission_callback']( array( 'id' => $copy, 'status' => 'publish' ) ) );

		$result = $tools['set-content-status']['execute_callback']( array( 'id' => $copy, 'status' => 'publish' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( $copy, $result['merged_from'] );
	}
}
