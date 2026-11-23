<?php
/**
 * Insertar una instancia e-component por el camino de edit-elementor-section: copia, reglas y rechazos sin escribir.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\ElementorAdapter;
use NexoDev\NexoMCP\Builders\ElementorSectionEditor;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ElementorSectionEditorComponentTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private FakeSchemaGuard $guard;

	/**
	 * Ids de componente que existen.
	 *
	 * @var array<int, int>
	 */
	private array $components = array( 7 );

	protected function setUp(): void {
		parent::setUp();

		$this->settings( array( 'read_only' => false ) );
		$this->guard                          = new FakeSchemaGuard();
		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post( array( 'ID' => self::ORIGINAL, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );
		update_post_meta( self::ORIGINAL, '_elementor_edit_mode', 'builder' );
		update_post_meta( self::ORIGINAL, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::atomic() ) );
	}

	private function editor( bool $with_components = true ): ElementorSectionEditor {
		return new ElementorSectionEditor(
			$this->settings( array( 'read_only' => false ) ),
			new ElementorAdapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->settings( array( 'read_only' => false ) ) ),
			$this->guard,
			static fn() => true,
			null,
			$with_components ? fn( int $id ): bool => in_array( $id, $this->components, true ) : null
		);
	}

	/**
	 * @param array<string, mixed> $extra Campos que cambian.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function insert( array $extra = array(), bool $with_components = true ) {
		$post = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->editor( $with_components )->edit(
			$post,
			array_merge(
				array(
					'operation'    => 'insert',
					'node_id'      => 'eh00001',
					'position'     => 'after',
					'component_id' => 7,
				),
				$extra
			)
		);
	}

	private function snapshot(): string {
		return serialize( array( array_keys( \WP_Test_State::$posts ), \WP_Test_State::$meta ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function data( int $id ): array {
		$decoded = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}

	public function test_inserta_la_instancia_en_la_copia_y_no_toca_el_original(): void {
		$original = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$result = $this->insert();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertCount( 1, $result['node_ids'] );
		$this->assertSame( $original, (string) get_post_meta( self::ORIGINAL, '_elementor_data', true ) );

		$children = $this->data( (int) $result['id'] )[0]['elements'];
		$this->assertSame( array( 'eh00001', $result['node_ids'][0], 'ep00001' ), array_column( $children, 'id' ) );
		$this->assertSame(
			array(
				'id'         => $result['node_ids'][0],
				'elType'     => 'widget',
				'widgetType' => 'e-component',
				'settings'   => array(
					'component_instance' => array(
						'$$type' => 'component-instance',
						'value'  => array( 'component_id' => array( '$$type' => 'number', 'value' => 7 ) ),
					),
				),
				'styles'     => array(),
				'elements'   => array(),
			),
			$children[1]
		);
		$this->assertSame( 0, $this->guard->sanitized, 'la instancia exacta no pasa por el saneado de Elementor' );
	}

	public function test_inserta_antes_y_dentro_de_un_contenedor(): void {
		$inside = $this->insert( array( 'node_id' => 'fx00001', 'position' => 'inside' ) );
		$this->assertIsArray( $inside );
		$this->assertSame( $inside['node_ids'][0], array_column( $this->data( (int) $inside['id'] )[0]['elements'], 'id' )[2] );

		$before = $this->insert( array( 'position' => 'before' ) );
		$this->assertIsArray( $before );
		$this->assertSame( $before['node_ids'][0], array_column( $this->data( (int) $before['id'] )[0]['elements'], 'id' )[0] );
	}

	public function test_un_componente_inexistente_no_escribe_nada(): void {
		$before = $this->snapshot();

		$result = $this->insert( array( 'component_id' => 99 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_component_not_found', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_sin_el_comprobador_de_componentes_no_escribe_nada(): void {
		$before = $this->snapshot();

		$result = $this->insert( array(), false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_components_unavailable', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function bad_requests(): array {
		return array(
			'id como cadena'     => array( array( 'component_id' => '7' ), 'nexo_mcp_elementor_setting_invalid' ),
			'id cero'            => array( array( 'component_id' => 0 ), 'nexo_mcp_elementor_setting_invalid' ),
			'id negativo'        => array( array( 'component_id' => -3 ), 'nexo_mcp_elementor_setting_invalid' ),
			'con elements'       => array( array( 'elements' => array( array( 'type' => 'e-heading' ) ) ), 'nexo_mcp_elementor_elements_forbidden' ),
			'operación replace'  => array( array( 'operation' => 'replace', 'position' => '' ), 'nexo_mcp_elementor_tree_invalid' ),
			'operación delete'   => array( array( 'operation' => 'delete', 'position' => '' ), 'nexo_mcp_elementor_tree_invalid' ),
			'en la raíz'         => array( array( 'node_id' => 'fx00001', 'position' => 'before' ), 'nexo_mcp_elementor_nesting_denied' ),
			'nodo inexistente'   => array( array( 'node_id' => 'zz99999' ), 'nexo_mcp_builder_node_missing' ),
		);
	}

	/**
	 * @dataProvider bad_requests
	 *
	 * @param array<string, mixed> $extra Campos que cambian.
	 */
	public function test_los_pedidos_mal_formados_se_rechazan_sin_escribir( array $extra, string $code ): void {
		$before = $this->snapshot();

		$result = $this->insert( $extra );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_con_el_editor_v4_apagado_no_escribe_nada(): void {
		$this->guard->v4 = false;
		$before          = $this->snapshot();

		$result = $this->insert();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_sin_component_id_edit_section_sigue_rechazando_e_component(): void {
		foreach ( array( 'insert', 'replace' ) as $operation ) {
			$before = $this->snapshot();

			$result = $this->editor()->edit(
				get_post( self::ORIGINAL ),
				array(
					'operation' => $operation,
					'node_id'   => 'insert' === $operation ? 'fx00001' : 'eh00001',
					'position'  => 'insert' === $operation ? 'inside' : '',
					'elements'  => array( array( 'type' => 'e-component', 'settings' => array( 'component_instance' => array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => 7 ) ) ) ) ) ),
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result, $operation );
			$this->assertSame( 'nexo_mcp_elementor_type_denied', $result->get_error_code(), $operation );
			$this->assertSame( $before, $this->snapshot(), $operation );
		}
	}
}
