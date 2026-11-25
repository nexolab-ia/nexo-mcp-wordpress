<?php
/**
 * El gateway sin esquema ni catálogo inyectados (`?? new DiviConditionsSchema()` / `?? new DiviConditionsCatalog()`) contra los
 * stubs de WordPress: set-divi-conditions y list-divi-conditions con las fuentes de producción (aviso R3-001 de 590617e).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviConditionsGatewayFallbackTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/DiviConditionsWpStubs.php';

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		\WP_Conditions_Stub_State::$roles      = array( 'subscriber' => 'Suscriptor', 'editor' => 'Editor' );
		\WP_Conditions_Stub_State::$post_types = array(
			'post'   => array( 'public' => true, 'label' => 'Entradas' ),
			'secret' => array( 'public' => false, 'label' => 'Secreto' ),
		);

		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5DynamicEditorTest::page() ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function tools(): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array( 'read_only' => false, 'divi_conditions_tools_enabled' => true ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings ),
			new BuilderGateway( null, null, $settings )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<int, array<string, mixed>> $conditions Condiciones.
	 * @return array<string, mixed>
	 */
	private function input( array $conditions ): array {
		return array(
			'id'          => 100,
			'path'        => '0.0.0.0.0',
			'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5DynamicEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
			'match'       => 'all',
			'conditions'  => $conditions,
		);
	}

	public function test_set_uses_the_production_schema_when_none_is_injected(): void {
		$set = $this->tools()['nexo-mcp/set-divi-conditions']['execute_callback'];

		$ghost = $set( $this->input( array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'ghost' ) ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $ghost );
		$this->assertSame( 'nexo_mcp_divi_conditions_invalid', $ghost->get_error_code() );

		$secret = $set( $this->input( array( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'secret' ) ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $secret, 'sólo tipos públicos' );

		$ok = $set( $this->input( array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber' ) ) ) ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 'all', $ok['match'] );
		$this->assertSame( 'userRole', $ok['conditions'][0]['conditionName'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ok['conditions'][0]['id'] );
	}

	public function test_list_uses_the_production_catalog_when_none_is_injected(): void {
		$list = $this->tools()['nexo-mcp/list-divi-conditions']['execute_callback']( array() );

		$this->assertIsArray( $list );
		$this->assertSame( array( array( 'slug' => 'subscriber', 'label' => 'Suscriptor' ), array( 'slug' => 'editor', 'label' => 'Editor' ) ), $list['roles'] );
		$this->assertSame( array( array( 'name' => 'post', 'label' => 'Entradas' ) ), $list['post_types'] );
		$this->assertFalse( $list['woocommerce'] );
	}
}
