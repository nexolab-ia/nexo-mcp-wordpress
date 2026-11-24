<?php
/**
 * BuilderGateway: `preset` de edit-divi-module, create-divi-page y edit-divi-section lee el catálogo del almacén de presets.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviDesignStore;
use NexoDev\NexoMCP\Builders\DiviPresetStore;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviPresetApplyGatewayTest extends NexoTestCase {

	/** @var array<string, mixed>|null */
	private ?array $presets = null;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		$this->presets              = array(
			'module' => array( 'divi/text' => array( 'default' => '', 'items' => array( 'p-1' => array( 'id' => 'p-1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ) ) ) ),
			'group'  => array( 'divi/background' => array( 'default' => '', 'items' => array( 'g-1' => array( 'id' => 'g-1', 'name' => 'Fondo', 'groupName' => 'divi/background', 'groupId' => 'module.decoration.background', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ) ) ) ),
		);
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5ModuleEditorTest::page() ) );
	}

	private function gateway(): BuilderGateway {
		$design = new DiviDesignStore( fn(): bool => true, fn(): array => array(), function ( array $all ): void {}, fn(): array => array(), function ( string $key, $value ): void {}, function (): void {}, function (): void {}, function ( callable $work ) {
			return $work();
		} );
		$store  = new DiviPresetStore(
			$design,
			fn(): ?array => $this->presets,
			function ( array $data ): array {
				$this->presets = $data;

				return $data;
			},
			function (): void {},
			static fn( string $module ): array => 'divi/text' === $module ? array( 'module.decoration.background' => 'divi/background' ) : array()
		);

		return new BuilderGateway( null, null, $this->settings( array( 'read_only' => false ) ), null, null, null, null, null, null, null, null, $store );
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 * @return array<string, mixed>
	 */
	private function edit_input( array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => 100,
				'path'        => '0.0.0.0.0',
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5ModuleEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
			),
			$extra
		);
	}

	public function test_edit_divi_module_aplica_el_preset_del_catalogo_en_la_copia_de_trabajo(): void {
		$result = $this->gateway()->edit_divi_module( $this->edit_input( array( 'preset' => array( 'module' => 'p-1', 'groups' => array( 'module.decoration.background' => 'g-1' ) ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['draft_of'] );
		$block = Divi5Tree::locate_block( parse_blocks( $this->content( (int) $result['id'] ) ), '0.0.0.0.0' );
		$this->assertSame( array( 'p-1' ), $block['attrs']['modulePreset'] ?? null ); // @phpstan-ignore-line
		$this->assertSame( 'divi/background', $block['attrs']['groupPreset']['module.decoration.background']['groupName'] ?? null ); // @phpstan-ignore-line
		$this->assertSame( Divi5ModuleEditorTest::page(), $this->content( 100 ), 'el original no cambia' );
	}

	public function test_edit_divi_module_con_un_preset_inexistente_no_escribe(): void {
		$before = serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
		$result = $this->gateway()->edit_divi_module( $this->edit_input( array( 'preset' => array( 'module' => 'nope' ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'preset.module' ), $result->get_error_data() );
		$this->assertSame( $before, serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) ) );
	}

	public function test_create_divi_page_y_edit_divi_section_validan_el_preset_de_los_nodos(): void {
		$sections = Divi5SectionEditorTestFixtures::sections( 'Hola' );
		$sections[0]['children'][0]['children'][0]['children'][0]['preset'] = array( 'module' => 'p-1' );

		$created = $this->gateway()->create_divi_page( array( 'title' => 'Nueva', 'sections' => $sections ) );
		$this->assertIsArray( $created );
		$this->assertSame( array( 'p-1' ), Divi5Tree::locate( Divi5Tree::build( $this->content( (int) $created['id'] ) ), '0.0.0.0.0' )['preset']['module'] ); // @phpstan-ignore-line

		$sections[0]['children'][0]['children'][0]['children'][0]['preset'] = array( 'module' => 'nope' );
		$bad = $this->gateway()->create_divi_page( array( 'title' => 'Mala', 'sections' => $sections ) );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $bad->get_error_code() );

		$edit = $this->gateway()->edit_divi_section(
			array(
				'id'          => 100,
				'operation'   => 'replace',
				'path'        => '0.0.0.0.0',
				'fingerprint' => (string) ( Divi5Tree::locate( Divi5Tree::build( Divi5ModuleEditorTest::page() ), '0.0.0.0.0' )['fingerprint'] ?? '' ),
				'sections'    => array( array( 'type' => 'divi/text', 'preset' => array( 'module' => 'nope' ) ) ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $edit );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $edit->get_error_code() );
		$this->assertSame( 'nodes[0].preset.module', $edit->get_error_data()['path'] ?? null ); // @phpstan-ignore-line
	}
}
