<?php
/**
 * Las fuentes de producción de DiviConditionsSchema y DiviConditionsCatalog (roles, tipos, términos, publicaciones,
 * productos, WooCommerce) contra los stubs de WordPress, con el constructor por omisión: un método o una firma que no
 * existen en WordPress hacen fallar estas pruebas (aviso R3-001 de la revisión de T2).
 *
 * Corre en un proceso aparte porque define clases y funciones globales de WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviConditionsCatalog;
use NexoDev\NexoMCP\Builders\DiviConditionsSchema;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviConditionsResolversTest extends NexoTestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/DiviConditionsWpStubs.php';

		\WP_Conditions_Stub_State::$roles      = array( 'subscriber' => 'Suscriptor', 'editor' => 'Editor' );
		\WP_Conditions_Stub_State::$post_types = array(
			'post'   => array( 'public' => true, 'label' => 'Entradas' ),
			'page'   => array( 'public' => true, 'label' => 'Páginas' ),
			'secret' => array( 'public' => false, 'label' => 'Secreto' ),
		);
		\WP_Conditions_Stub_State::$terms      = array( 'category' => array( 5 => 'Noticias' ), 'post_tag' => array( 9 => 'Oferta' ) );
		\WP_Conditions_Stub_State::$products   = array( 7 => 'Camiseta' );

		\WP_Test_State::$posts[300] = new \WP_Post( array( 'ID' => 300, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tienda' ) );
		\WP_Test_State::$posts[301] = new \WP_Post( array( 'ID' => 301, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Borrador' ) );
	}

	/**
	 * @param array<string, mixed> $condition Condición.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( array $condition ) {
		return ( new DiviConditionsSchema() )->validate( array( 'match' => 'all', 'conditions' => array( $condition ) ) );
	}

	public function test_roles_resolve_against_wp_roles(): void {
		$this->assertIsArray( $this->validate( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'subscriber', 'editor' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'ghost' ) ) ) );
	}

	public function test_post_types_resolve_against_the_registry_and_only_public_ones_pass(): void {
		$this->assertIsArray( $this->validate( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'page' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'secret' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'post_type', 'rule' => 'is', 'post_types' => array( 'nope' ) ) ) );
	}

	public function test_terms_resolve_per_taxonomy(): void {
		$this->assertIsArray( $this->validate( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 5 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'categories', 'rule' => 'is', 'terms' => array( 9 ) ) ), 'una etiqueta no es categoría' );
		$this->assertIsArray( $this->validate( array( 'type' => 'tags', 'rule' => 'is_not', 'terms' => array( 9 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'tags', 'rule' => 'is', 'terms' => array( 404 ) ) ) );
	}

	public function test_posts_resolve_only_published_content_of_that_type(): void {
		$this->assertIsArray( $this->validate( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 300 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'page', 'posts' => array( 301 ) ) ), 'borrador' );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array( 'type' => 'posts', 'rule' => 'is', 'post_type' => 'post', 'posts' => array( 300 ) ) ), 'de otro tipo' );
	}

	public function test_woocommerce_types_need_the_plugin_and_products_resolve_with_wc_get_product(): void {
		$cart = array( 'type' => 'cart_contents', 'rule' => 'has_product', 'products' => array( 7 ) );

		$off = $this->validate( $cart );
		$this->assertInstanceOf( \WP_Error::class, $off );
		$this->assertStringContainsString( 'WooCommerce', $off->get_error_message() );

		eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce «activo» sólo en este proceso.

		$this->assertIsArray( $this->validate( $cart ) );
		$this->assertInstanceOf( \WP_Error::class, $this->validate( array_merge( $cart, array( 'products' => array( 8 ) ) ) ) );
	}

	public function test_the_default_id_generator_makes_valid_uuids_and_stored_keeps_them_valid(): void {
		$schema = new DiviConditionsSchema();
		$params = $schema->validate( array( 'match' => 'any', 'conditions' => array( array( 'type' => 'user_role', 'rule' => 'is', 'roles' => array( 'editor' ) ) ) ) );
		$this->assertIsArray( $params );

		$stored = $schema->stored( $params );
		$this->assertMatchesRegularExpression( DiviConditionsSchema::ID_PATTERN, $stored[0]['id'] );
		$this->assertNotNull( $schema->valid_stored( $stored ), 'lo que escribe es reconocido como propio con los resolvers de producción' );
	}

	public function test_the_catalog_lists_the_live_roles_and_public_post_types_with_its_defaults(): void {
		$list = ( new DiviConditionsCatalog() )->listing();

		$this->assertSame( array( array( 'slug' => 'subscriber', 'label' => 'Suscriptor' ), array( 'slug' => 'editor', 'label' => 'Editor' ) ), $list['roles'] );
		$this->assertSame( array( array( 'name' => 'post', 'label' => 'Entradas' ), array( 'name' => 'page', 'label' => 'Páginas' ) ), $list['post_types'] );
		$this->assertFalse( $list['woocommerce'] );

		eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce «activo» sólo en este proceso.
		$this->assertTrue( ( new DiviConditionsCatalog() )->listing()['woocommerce'] );
	}
}
