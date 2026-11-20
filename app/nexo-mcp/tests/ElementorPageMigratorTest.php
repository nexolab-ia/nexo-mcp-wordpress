<?php
/**
 * Migración de una página entera a la copia de trabajo: destino, rechazos sin escribir y respuesta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\ElementorAdapter;
use NexoDev\NexoMCP\Builders\ElementorPageMigrator;
use NexoDev\NexoMCP\Builders\ElementorV3Migrator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\Tests\Fakes\FakeV3StyleMapper;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ElementorPageMigratorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private Settings $cfg;

	private FakeSchemaGuard $guard;

	/**
	 * Árbol migrado que devuelve el migrador falso, o null para usar el real.
	 *
	 * @var callable|null
	 */
	private $tamper = null;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg                = $this->settings( array( 'read_only' => false ) );
		$this->guard              = new FakeSchemaGuard();
		$this->guard->style_props = array( 'flex-direction', 'width', 'gap', 'color', 'justify-content', 'align-items', 'flex-wrap' );
		$this->put_page( self::ORIGINAL, 'publish', $this->v3_page() );
	}

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function v3_page(): array {
		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							ElementorSectionFixtures::v3_widget( 'hd00001', 'heading', array( 'title' => 'Hola' ) ),
							ElementorSectionFixtures::v3_widget( 'sp00001', 'spacer', array( 'space' => array( 'size' => 30 ) ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Documento.
	 */
	private function put_page( int $id, string $status, array $tree ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_type' => 'page', 'post_status' => $status, 'post_title' => 'Inicio' ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', (string) wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function data( int $id ): array {
		$decoded = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}

	private function migrator( ?callable $lock = null ): ElementorPageMigrator {
		return new ElementorPageMigrator(
			$this->cfg,
			new ElementorAdapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$this->guard,
			$lock ?? static fn() => true,
			function ( array $tree ): array {
				$n      = 0;
				$result = ( new ElementorV3Migrator(
					new FakeV3StyleMapper(),
					static function () use ( &$n ): string {
						return sprintf( 'n%06d', ++$n );
					}
				) )->migrate( $tree );

				if ( null !== $this->tamper ) {
					$result['tree'] = ( $this->tamper )( $result['tree'] );
				}

				return $result;
			}
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_migration( int $id = self::ORIGINAL, array $input = array(), ?callable $lock = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->migrator( $lock )->migrate( $post, $input );
	}

	private function snapshot(): string {
		return serialize( array( array_keys( \WP_Test_State::$posts ), \WP_Test_State::$meta ) );
	}

	/**
	 * @return list<int>
	 */
	private function live_copies(): array {
		$ids = array();

		foreach ( \WP_Test_State::$posts as $id => $post ) {
			if ( 'draft' === $post->post_status && WorkingCopy::original_of( $post ) > 0 ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	public function test_migra_la_pagina_entera_a_la_copia_y_no_toca_el_original(): void {
		$before = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$result = $this->run_migration();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$copy = WorkingCopy::copy_of( \WP_Test_State::$posts[ self::ORIGINAL ] );
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ), 'el original no cambia' );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );

		$tree = $this->data( $copy );
		$this->assertSame( 'e-flexbox', $tree[0]['elType'] );
		$this->assertSame( 'e-div-block', $tree[0]['elements'][0]['elType'] );
		$this->assertSame( 'e-heading', $tree[0]['elements'][0]['elements'][0]['widgetType'] );
		$this->assertSame( ElementorSectionFixtures::v3_widget( 'sp00001', 'spacer', array( 'space' => array( 'size' => 30 ) ) ), $tree[0]['elements'][0]['elements'][1] );
		$this->assertGreaterThan( 0, $this->guard->sanitized );
	}

	public function test_la_respuesta_trae_la_forma_acordada(): void {
		$result = $this->run_migration();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'migrated', 'kept_v3', 'unmapped' ), array_keys( $result ) );
		$this->assertSame( 3, $result['migrated'] );
		$this->assertCount( 1, $result['kept_v3'] );
		$this->assertSame( array( 'id', 'type', 'reason' ), array_keys( $result['kept_v3'][0] ) );
		$this->assertSame( 'sp00001', $result['kept_v3'][0]['id'] );
		$this->assertSame( array(), $result['unmapped'] );
		$this->assertIsString( $result['preview_url'] );
	}

	public function test_un_borrador_se_migra_en_su_sitio_sin_copia(): void {
		$this->put_page( 105, 'draft', $this->v3_page() );

		$result = $this->run_migration( 105 );

		$this->assertIsArray( $result );
		$this->assertSame( 105, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( 'e-flexbox', $this->data( 105 )[0]['elType'] );
		$this->assertSame( array(), $this->live_copies() );
	}

	public function test_una_pagina_sin_nodos_v3_responde_nothing_to_migrate_y_no_escribe(): void {
		$this->put_page( 106, 'publish', ElementorSectionFixtures::atomic() );
		$before = $this->snapshot();

		$result = $this->run_migration( 106 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_nothing_to_migrate', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_la_segunda_llamada_trabaja_sobre_la_copia_y_ya_no_tiene_nada_que_migrar(): void {
		$first = $this->run_migration();
		$this->assertIsArray( $first );
		$before = $this->snapshot();

		$second = $this->run_migration();

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'nexo_mcp_nothing_to_migrate', $second->get_error_code() );
		$this->assertSame( array( $first['id'] ), $this->live_copies() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_sin_editor_v4_pide_el_editor_y_no_escribe(): void {
		$this->guard->v4 = false;
		$before          = $this->snapshot();

		$result = $this->run_migration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_v3_conservado_alterado_se_rechaza_y_no_escribe_ni_crea_copia(): void {
		$this->tamper = static function ( array $tree ): array {
			$tree[0]['elements'][0]['elements'][1]['settings']['space']['size'] = 9999;

			return $tree;
		};
		$before = $this->snapshot();

		$result = $this->run_migration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v3_not_identical', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->live_copies() );
	}

	public function test_un_v3_agregado_se_rechaza_y_no_escribe_ni_crea_copia(): void {
		$this->tamper = static function ( array $tree ): array {
			$tree[0]['elements'][0]['elements'][] = ElementorSectionFixtures::v3_widget( 'ev00001', 'html', array( 'html' => '<script>x</script>' ) );

			return $tree;
		};
		$before = $this->snapshot();

		$result = $this->run_migration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v3_not_identical', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_el_tope_de_nodos_es_el_mayor_entre_200_y_los_actuales(): void {
		$column = array( 'id' => 'col0001', 'elType' => 'column', 'settings' => array(), 'elements' => array() );

		for ( $i = 0; $i < 197; $i++ ) {
			$column['elements'][] = ElementorSectionFixtures::v3_widget( sprintf( 'h%06d', $i ), 'heading', array( 'title' => 'T' ) );
		}

		// 199 nodos; un text-editor con 3 párrafos sube a 201 (> 200).
		$column['elements'][] = ElementorSectionFixtures::v3_widget( 'tx00001', 'text-editor', array( 'editor' => '<p>a</p><p>b</p><p>c</p>' ) );
		$this->put_page( 107, 'publish', array( array( 'id' => 'sec0001', 'elType' => 'section', 'settings' => array(), 'elements' => array( $column ) ) ) );
		$before = $this->snapshot();

		$result = $this->run_migration( 107 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		// Una página v3 que ya pasa de 200 admite la migración si no crece.
		array_pop( $column['elements'] );

		for ( $i = 0; $i < 3; $i++ ) {
			$column['elements'][] = ElementorSectionFixtures::v3_widget( sprintf( 'x%06d', $i ), 'heading', array( 'title' => 'T' ) );
		}

		$this->put_page( 108, 'publish', array( array( 'id' => 'sec0001', 'elType' => 'section', 'settings' => array(), 'elements' => array( $column ) ) ) );

		$ok = $this->run_migration( 108 );

		$this->assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_message() : '' );
		$this->assertSame( 202, $ok['migrated'] );
	}

	public function test_una_pagina_que_no_es_de_elementor_se_rechaza(): void {
		\WP_Test_State::$posts[110] = new \WP_Post( array( 'ID' => 110, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$before                     = $this->snapshot();

		$result = $this->run_migration( 110 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_not_elementor', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_bloqueo_de_otro_usuario_frena_la_migracion_y_no_escribe(): void {
		$before = $this->snapshot();

		$result = $this->run_migration( self::ORIGINAL, array(), static fn() => new \WP_Error( 'nexo_mcp_builder_locked', 'Otro usuario tiene este contenido abierto en el editor.' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_locked', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function failures_after_the_copy(): array {
		return array(
			'falla el respaldo' => array( 'capture', 'nexo_mcp_builder_backup_failed' ),
			'falla save_tree'   => array( 'save_tree', 'nexo_mcp_builder_too_large' ),
		);
	}

	/**
	 * @dataProvider failures_after_the_copy
	 */
	public function test_si_falla_el_respaldo_o_el_guardado_no_queda_copia_ni_cambia_el_original( string $fails_in, string $code ): void {
		$before                       = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );
		\WP_Test_State::$json_fail_in = $fails_in;

		$result = $this->run_migration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $this->live_copies() );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ) );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}

	public function test_solo_borradores_no_frena_la_copia(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'drafts_only' => true ) );

		$result = $this->run_migration();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
	}

	public function test_con_la_copia_por_defecto_apagada_draft_true_migra_a_la_copia(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) );
		$before    = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$result = $this->run_migration( self::ORIGINAL, array( 'draft' => true ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $this->live_copies(), array( $result['id'] ) );
		$this->assertSame( 'e-flexbox', $this->data( $result['id'] )[0]['elType'] );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ), 'el original no cambia' );
	}

	public function test_con_la_copia_por_defecto_apagada_y_sin_draft_migra_en_vivo(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) );

		$result = $this->run_migration();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( self::ORIGINAL, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( array(), $this->live_copies() );
		$this->assertSame( 'e-flexbox', $this->data( self::ORIGINAL )[0]['elType'] );
	}
}
