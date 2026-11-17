<?php
/**
 * Un rechazo nunca borra el respaldo de deshacer.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderAdapter;
use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\BuilderNode;
use NexoDev\NexoMCP\Builders\BuilderRegistry;

/**
 * Adaptador falso: un constructor con un título editable y un bloque de código.
 */
final class FakeBuilderAdapter implements BuilderAdapter {

	public int $writes = 0;

	public function slug(): string {
		return 'gutenberg';
	}

	public function label(): string {
		return 'Editor de prueba';
	}

	public function handles( \WP_Post $post ): bool {
		return true;
	}

	public function accepts_full_content_write(): bool {
		return true;
	}

	public function read_nodes( \WP_Post $post ): array {
		return array(
			new BuilderNode( 'titulo', 'core/heading', 'Hola' ),
			BuilderNode::blocked( 'codigo', 'core/html' ),
		);
	}

	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		++$this->writes;
		wp_update_post( array( 'ID' => $post->ID, 'post_content' => '<h2>' . $text . '</h2>' ) );

		return true;
	}

	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		++$this->writes;

		return true;
	}

	public function flush( \WP_Post $post ): void {
	}
}

final class BuilderBackupTest extends NexoTestCase {

	private FakeBuilderAdapter $adapter;

	private BuilderGateway $gateway;

	private BuilderBackup $backup;

	protected function setUp(): void {
		parent::setUp();

		\WP_Test_State::$posts[30] = new \WP_Post(
			array(
				'ID'           => 30,
				'post_type'    => 'page',
				// Borrador: la edición en el sitio. Las páginas publicadas van a la copia de trabajo (BuilderWorkingCopyTest).
				'post_status'  => 'draft',
				'post_title'   => 'Título',
				'post_content' => '<h2>Hola</h2>',
			)
		);

		$this->adapter = new FakeBuilderAdapter();
		$this->backup  = new BuilderBackup();
		$this->gateway = new BuilderGateway( new BuilderRegistry( array( $this->adapter ) ), $this->backup );
	}

	/**
	 * Deja un respaldo «bueno» conocido, como el de una edición anterior.
	 */
	private function seed_backup(): string {
		$this->assertTrue( $this->backup->capture( \WP_Test_State::$posts[30], $this->adapter ) );

		return (string) get_post_meta( 30, BuilderBackup::META_KEY, true );
	}

	public function test_editar_un_elemento_inexistente_no_toca_el_respaldo(): void {
		$before = $this->seed_backup();
		\WP_Test_State::$posts[30]->post_content = '<h2>Estado posterior</h2>';

		$result = $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'no-existe', 'text' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, get_post_meta( 30, BuilderBackup::META_KEY, true ) );
		$this->assertSame( 0, $this->adapter->writes );
	}

	public function test_editar_un_elemento_vedado_no_toca_el_respaldo(): void {
		$before = $this->seed_backup();
		\WP_Test_State::$posts[30]->post_content = '<h2>Estado posterior</h2>';

		$result = $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'codigo', 'text' => '<script>x</script>' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_type_forbidden', $result->get_error_code() );
		$this->assertSame( $before, get_post_meta( 30, BuilderBackup::META_KEY, true ) );
	}

	public function test_un_atributo_no_permitido_no_toca_el_respaldo(): void {
		$before = $this->seed_backup();

		$result = $this->gateway->update_node_attribute(
			array( 'id' => 30, 'node_id' => 'titulo', 'attribute' => 'onclick', 'value' => 'alert(1)' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, get_post_meta( 30, BuilderBackup::META_KEY, true ) );
		$this->assertSame( 0, $this->adapter->writes );
	}

	public function test_un_texto_demasiado_largo_no_toca_el_respaldo(): void {
		$before = $this->seed_backup();

		$result = $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => str_repeat( 'a', 70000 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, get_post_meta( 30, BuilderBackup::META_KEY, true ) );
	}

	public function test_una_edicion_valida_respalda_antes_y_se_puede_deshacer(): void {
		$result = $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => 'Nuevo título' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( '<h2>Nuevo título</h2>', \WP_Test_State::$posts[30]->post_content );

		// El respaldo guarda el estado anterior y restaura al original.
		$this->assertTrue( $this->backup->restore( \WP_Test_State::$posts[30] ) );
		$this->assertSame( '<h2>Hola</h2>', \WP_Test_State::$posts[30]->post_content );
	}

	public function test_el_respaldo_sobrevive_a_un_rechazo_posterior_y_sigue_restaurando(): void {
		// Edición válida: queda respaldado «Hola».
		$this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => 'Paso 1' ) );
		// Edición rechazada: no debe pisar el respaldo con «Paso 1».
		$this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'codigo', 'text' => 'x' ) );

		$this->assertTrue( $this->backup->restore( \WP_Test_State::$posts[30] ) );
		$this->assertSame( '<h2>Hola</h2>', \WP_Test_State::$posts[30]->post_content );
	}

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	public function test_con_el_contenido_bloqueado_por_otro_no_se_escribe_nada(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 0.1;
		\WP_Test_State::$options['nexo_mcp_lock_30'] = 'otro|' . ( time() + 100 );
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );

		foreach ( array(
			$this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => 'x' ) ),
			$this->gateway->update_node_attribute( array( 'id' => 30, 'node_id' => 'titulo', 'attribute' => 'link', 'value' => 'https://e.example/' ) ),
			$this->gateway->restore_backup( array( 'id' => 30 ) ),
		) as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		}

		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
		$this->assertSame( 0, $this->adapter->writes );
	}

	public function test_el_bloqueo_se_libera_tras_escribir(): void {
		$this->assertIsArray( $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => 'x' ) ) );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_30', \WP_Test_State::$options );
		$this->assertTrue( $this->gateway->restore_backup( array( 'id' => 30 ) )['restored'] );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_30', \WP_Test_State::$options );
	}

	public function test_dentro_del_bloqueo_se_relee_y_lo_que_cambio_despues_de_leer_sobrevive(): void {
		\WP_Test_State::$cache_posts = true;
		get_post( 30 ); // La caché queda con el título viejo.
		\WP_Test_State::$posts[30]->post_title = 'Cambiado por otro';

		$this->assertIsArray( $this->gateway->update_node_text( array( 'id' => 30, 'node_id' => 'titulo', 'text' => 'Nuevo' ) ) );

		$this->assertSame( 'Cambiado por otro', \WP_Test_State::$posts[30]->post_title );
		$this->assertSame( '<h2>Nuevo</h2>', \WP_Test_State::$posts[30]->post_content );

		get_post( 30 );
		\WP_Test_State::$posts[30]->post_title = 'Cambiado otra vez';

		$this->assertTrue( $this->gateway->restore_backup( array( 'id' => 30 ) )['restored'] );

		$this->assertSame( 'Cambiado otra vez', \WP_Test_State::$posts[30]->post_title );
		$this->assertSame( '<h2>Hola</h2>', \WP_Test_State::$posts[30]->post_content );
	}
}
