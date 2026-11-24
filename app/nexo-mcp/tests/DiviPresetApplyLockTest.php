<?php
/**
 * Aplicar un preset toma el bloqueo de presets (el del borrado) al validar y guardar la página.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5PageCreator;
use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DiviPresetApplyLockTest extends NexoTestCase {

	private const TEXT = '0.0.0.0.0';

	private Settings $cfg;

	/**
	 * Catálogo «vivo» (lo que un borrado concurrente cambia).
	 *
	 * @var array<string, mixed>
	 */
	private array $live;

	/**
	 * Veces que se tomó el bloqueo de presets.
	 */
	private int $acquired = 0;

	/**
	 * Bloqueo de presets tomado ahora.
	 */
	private bool $held = false;

	/**
	 * Si el bloqueo responde «ocupado» sin ejecutar el trabajo.
	 */
	private bool $busy = false;

	/**
	 * Un borrado que gana el bloqueo antes de que entre el trabajo.
	 *
	 * @var callable|null
	 */
	private $before_work = null;

	/**
	 * Lo que pasó mientras se guardaba: {catalogo_leido_con_bloqueo, borrado_posible}.
	 *
	 * @var array<int, array{bool, bool}>
	 */
	private array $at_save = array();

	protected function setUp(): void {
		parent::setUp();

		$this->cfg                  = $this->settings( array( 'read_only' => false ) );
		$this->live                 = array(
			'module' => array(
				'divi/text' => array(
					'default' => '',
					'items'   => array( 'p-text-1' => array( 'id' => 'p-text-1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ) ),
				),
			),
			'group'  => array(),
		);
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5ModuleEditorTest::page() ) );
	}

	/**
	 * Bloqueo exclusivo de prueba: fn( callable ): mixed.
	 */
	private function lock(): callable {
		return function ( callable $work ) {
			if ( $this->busy ) {
				return new \WP_Error( 'nexo_mcp_busy', 'ocupado' );
			}

			++$this->acquired;

			if ( null !== $this->before_work ) {
				( $this->before_work )();
			}

			$this->held = true;

			try {
				return $work();
			} finally {
				$this->held = false;
			}
		};
	}

	/**
	 * Un borrado de preset: necesita el bloqueo, así que no entra mientras otro lo tiene.
	 */
	private function try_delete( string $id ): bool {
		if ( $this->held ) {
			return false;
		}

		unset( $this->live['module']['divi/text']['items'][ $id ] );

		return true;
	}

	private function validator(): DiviTreeValidator {
		return new DiviTreeValidator(
			null,
			null,
			function (): array {
				$this->at_save[] = array( $this->held, false );

				return $this->live;
			},
			static fn( string $module ): array => array()
		);
	}

	private function saver(): callable {
		return function ( int $id, string $content ) {
			$this->at_save[] = array( $this->held, $this->try_delete( 'p-text-1' ) );

			return true;
		};
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param array<string, mixed> $input Parámetros.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_module( array $input ) {
		$post   = get_post( 100 );
		$editor = new Divi5ModuleEditor( $this->cfg, new Divi5Adapter(), new BuilderBackup(), new WorkingCopy( $this->cfg ), $this->validator(), static fn() => true, static fn(): bool => true, $this->saver(), $this->lock() );
		$node   = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), self::TEXT );

		return $editor->edit( $post, $input + array( 'path' => self::TEXT, 'fingerprint' => $node['fingerprint'] ) );
	}

	/**
	 * @param array<string, mixed> $extra Claves del nodo de texto.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( array $extra = array() ): array {
		$sections = Divi5SectionEditorTestFixtures::sections( 'Hola' );
		$sections[0]['children'][0]['children'][0]['children'][0] = array_merge( $sections[0]['children'][0]['children'][0]['children'][0], $extra );

		return $sections;
	}

	/**
	 * @param array<string, mixed> $sections Nodos.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_section( array $sections ) {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
		$post                       = get_post( 100 );
		$editor                     = new Divi5SectionEditor( $this->cfg, new Divi5Adapter(), new BuilderBackup(), new WorkingCopy( $this->cfg ), $this->validator(), static fn() => true, static fn(): bool => true, $this->saver(), static fn(): string => '5.19.1', $this->lock() );
		$node                       = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), '0.0' );

		return $editor->edit( $post, array( 'operation' => 'insert', 'position' => 'after', 'path' => '0.0', 'fingerprint' => $node['fingerprint'], 'sections' => $sections ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $sections Nodos.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_page( array $sections ) {
		$creator = new Divi5PageCreator(
			$this->validator(),
			static fn(): bool => true,
			static fn(): string => '5.19.1',
			function ( array $data ) {
				$this->at_save[] = array( $this->held, $this->try_delete( 'p-text-1' ) );

				return wp_insert_post( $data, true );
			},
			null,
			null,
			$this->lock()
		);

		return $creator->create( array( 'title' => 'Nueva', 'sections' => $sections ) );
	}

	// --- Con preset: bloqueo tomado, validación y guardado dentro, un borrado no se cuela ----------

	public function test_edit_divi_module_valida_y_guarda_dentro_del_bloqueo_y_un_borrado_no_entra_entre_medias(): void {
		$result = $this->edit_module( array( 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->acquired );
		$this->assertNotSame( array(), $this->at_save );

		foreach ( $this->at_save as $step ) {
			$this->assertTrue( $step[0], 'validar y guardar ocurren con el bloqueo de presets tomado' );
			$this->assertFalse( $step[1], 'un borrado no puede entrar mientras se valida y se guarda' );
		}

		$this->assertArrayHasKey( 'p-text-1', $this->live['module']['divi/text']['items'] );
		$this->assertTrue( $this->try_delete( 'p-text-1' ), 'terminada la escritura el borrado ya puede entrar' );
	}

	public function test_edit_divi_module_con_un_borrado_que_gana_el_bloqueo_primero_no_deja_una_pagina_con_el_preset_borrado(): void {
		$this->before_work = fn() => $this->try_delete( 'p-text-1' );
		$before            = $this->snapshot();
		$result            = $this->edit_module( array( 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot(), 'sin escribir ni crear copia' );
	}

	public function test_edit_divi_module_con_el_bloqueo_ocupado_no_escribe_y_devuelve_el_error_de_ocupado(): void {
		$this->busy = true;
		$before     = $this->snapshot();
		$result     = $this->edit_module( array( 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->at_save );
	}

	public function test_edit_divi_section_con_preset_toma_el_bloqueo_valida_y_guarda_dentro(): void {
		$result = $this->edit_section( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->acquired );

		foreach ( $this->at_save as $step ) {
			$this->assertTrue( $step[0] );
			$this->assertFalse( $step[1] );
		}
	}

	public function test_edit_divi_section_con_un_borrado_primero_o_el_bloqueo_ocupado_no_escribe(): void {
		$this->before_work = fn() => $this->try_delete( 'p-text-1' );
		$result            = $this->edit_section( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );

		$this->before_work = null;
		$this->busy        = true;
		$busy              = $this->edit_section( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 'nexo_mcp_busy', $busy->get_error_code() );
		$this->assertSame( Divi5SectionEditorTest::page(), get_post( 100 )->post_content );
		$this->assertCount( 0, array_filter( \WP_Test_State::$posts, static fn( $p ): bool => (int) $p->ID !== 100 ) );
	}

	public function test_create_divi_page_con_preset_toma_el_bloqueo_valida_y_guarda_dentro(): void {
		$result = $this->create_page( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->acquired );

		foreach ( $this->at_save as $step ) {
			$this->assertTrue( $step[0] );
			$this->assertFalse( $step[1] );
		}
	}

	public function test_create_divi_page_con_un_borrado_primero_o_el_bloqueo_ocupado_no_crea_nada(): void {
		$before            = $this->snapshot();
		$this->before_work = fn() => $this->try_delete( 'p-text-1' );
		$gone              = $this->create_page( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $gone );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $gone->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		$this->before_work = null;
		$this->busy        = true;
		$busy              = $this->create_page( $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 'nexo_mcp_busy', $busy->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	// --- Sin preset: no se toma el bloqueo, nada cambia --------------------------------------------

	public function test_edit_divi_module_sin_preset_no_toma_el_bloqueo_aunque_este_ocupado(): void {
		$this->busy = true;

		$this->assertIsArray( $this->edit_module( array( 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ) ) ) );
		$this->assertSame( 0, $this->acquired );
	}

	public function test_edit_divi_section_sin_preset_no_toma_el_bloqueo_aunque_este_ocupado(): void {
		$this->busy = true;

		$this->assertIsArray( $this->edit_section( $this->sections() ) );
		$this->assertSame( 0, $this->acquired );
	}

	public function test_create_divi_page_sin_preset_no_toma_el_bloqueo_aunque_este_ocupado(): void {
		$this->busy = true;

		$this->assertIsArray( $this->create_page( $this->sections() ) );
		$this->assertSame( 0, $this->acquired );
	}

	public function test_un_preset_null_tampoco_toma_el_bloqueo(): void {
		$this->busy = true;

		$this->assertIsArray( $this->edit_module( array( 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ), 'preset' => null ) ) );
		$this->assertSame( 0, $this->acquired );
	}

	public function test_un_nodo_con_preset_null_en_seccion_o_pagina_no_toma_el_bloqueo(): void {
		$this->busy = true;

		// En un nodo nuevo `preset: null` no aplica nada y es entrada inválida: se rechaza por validación, no por ocupado.
		foreach ( array( $this->edit_section( $this->sections( array( 'preset' => null ) ) ), $this->create_page( $this->sections( array( 'preset' => null ) ) ) ) as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_divi_tree_invalid', $result->get_error_code() );
		}
		$this->assertSame( 0, $this->acquired );
		$this->assertSame( array(), $this->at_save );
	}
}
