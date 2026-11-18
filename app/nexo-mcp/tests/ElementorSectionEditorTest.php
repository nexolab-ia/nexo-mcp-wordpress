<?php
/**
 * Edición de secciones: destino (copia de trabajo), reglas, límites y que un error no escribe nada.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\ElementorAdapter;
use NexoDev\NexoMCP\Builders\ElementorSectionEditor;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class ElementorSectionEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private Settings $cfg;

	private FakeSchemaGuard $guard;

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->cfg   = $this->settings( array( 'read_only' => false ) );
		$this->guard = new FakeSchemaGuard();
		$this->put_page( self::ORIGINAL, 'publish', ElementorSectionFixtures::v3() );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Documento.
	 */
	private function put_page( int $id, string $status, array $tree ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'page',
				'post_status' => $status,
				'post_title'  => 'Inicio',
			)
		);
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', $this->encode( $tree ) );
	}

	/**
	 * @param array<int, mixed> $tree Documento.
	 */
	private function encode( array $tree ): string {
		return (string) wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function data( int $id ): array {
		$decoded = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}

	private function editor( ?callable $ids = null, ?callable $lock = null ): ElementorSectionEditor {
		return new ElementorSectionEditor(
			$this->cfg,
			new ElementorAdapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$this->guard,
			$lock ?? static fn() => true,
			$ids
		);
	}

	/**
	 * @param array<string, mixed> $input Parámetros.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function exec_edit( array $input, ?callable $ids = null, ?callable $lock = null ) {
		$post = get_post( (int) $input['id'] );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->editor( $ids, $lock )->edit( $post, $input );
	}

	/**
	 * Foto de todo lo escrito: entradas y metadatos.
	 */
	private function snapshot(): string {
		return serialize( array( array_keys( \WP_Test_State::$posts ), \WP_Test_State::$meta ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Documento.
	 * @return array<string, array<string, mixed>>
	 */
	private function by_id( array $tree ): array {
		$out = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $node ) {
				$out[ (string) $node['id'] ] = $node;
				$walk( $node['elements'] ?? array() );
			}
		};
		$walk( $tree );

		return $out;
	}

	private function find_copy(): int {
		return WorkingCopy::copy_of( \WP_Test_State::$posts[ self::ORIGINAL ] );
	}

	public function test_reemplazar_una_seccion_publicada_edita_su_copia_y_deja_el_original(): void {
		$before = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$result = $this->exec_edit(
			array(
				'id'        => self::ORIGINAL,
				'operation' => 'replace',
				'node_id'   => 'sec0001',
				'elements'  => ElementorSectionFixtures::new_box( 'Reconstruido' ),
			)
		);

		$this->assertIsArray( $result );
		$copy = $this->find_copy();
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( 'replace', $result['operation'] );
		$this->assertCount( 2, $result['node_ids'] );
		$this->assertSame( array( 'sec0001', 'col0001', 'hd00001', 'tx00001' ), $result['removed'] );
		$this->assertStringContainsString( (string) $copy, $result['preview_url'] );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'operation', 'node_ids', 'removed' ), array_keys( $result ) );

		// El original no cambia: ni datos, ni estado, ni respaldo.
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );
		$this->assertSame( '', get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true ) );

		// La copia: borrador enlazado, con el nodo nuevo y lo demás igual.
		$this->assertSame( 'draft', \WP_Test_State::$posts[ $copy ]->post_status );
		$this->assertSame( self::ORIGINAL, WorkingCopy::original_of( \WP_Test_State::$posts[ $copy ] ) );
		$data = $this->data( $copy );
		$orig = ElementorSectionFixtures::v3();
		$this->assertSame( array( $result['node_ids'][0], 'sec0002', 'cnt0001' ), array_column( $data, 'id' ) );
		$this->assertSame( 'e-flexbox', $data[0]['elType'] );
		$this->assertSame( 'e-heading', $data[0]['elements'][0]['widgetType'] );
		$this->assertSame( 'Reconstruido', $data[0]['elements'][0]['settings']['title']['value'] );
		$this->assertSame( json_encode( $orig[1] ), json_encode( $data[1] ) );
		$this->assertSame( json_encode( $orig[2] ), json_encode( $data[2] ) );
		$this->assertNotSame( '', get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
		$this->assertSame( 'builder', get_post_meta( $copy, '_elementor_edit_mode', true ) );
		$this->assertSame( 1, $this->guard->parsed );
	}

	public function test_insertar_un_widget_atomico_en_una_columna_v3_antes_despues_y_dentro(): void {
		foreach ( array( 'before' => array( 'hd00001', 'tx00001' ), 'after' => array( 'hd00001', 'tx00001' ) ) as $position => $unused ) {
			\WP_Test_State::reset();
			$this->grant_admin();
			$this->cfg = $this->settings( array( 'read_only' => false ) );
			$this->put_page( self::ORIGINAL, 'publish', ElementorSectionFixtures::v3() );

			$result = $this->exec_edit(
				array(
					'id'        => self::ORIGINAL,
					'operation' => 'insert',
					'node_id'   => 'hd00001',
					'position'  => $position,
					'elements'  => array( ElementorSectionFixtures::new_heading( 'Nuevo' ) ),
				)
			);

			$this->assertIsArray( $result, $position );
			$column = $this->by_id( $this->data( (int) $result['id'] ) )['col0001']['elements'];
			$new    = $result['node_ids'][0];
			$expect = 'before' === $position ? array( $new, 'hd00001', 'tx00001' ) : array( 'hd00001', $new, 'tx00001' );
			$this->assertSame( $expect, array_column( $column, 'id' ), $position );
			$this->assertArrayNotHasKey( 'removed', $result );
		}

		\WP_Test_State::reset();
		$this->grant_admin();
		$this->cfg = $this->settings( array( 'read_only' => false ) );
		$this->put_page( self::ORIGINAL, 'publish', ElementorSectionFixtures::v3() );
		$result = $this->exec_edit(
			array(
				'id'        => self::ORIGINAL,
				'operation' => 'insert',
				'node_id'   => 'col0001',
				'position'  => 'inside',
				'elements'  => array( ElementorSectionFixtures::new_heading() ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( array( 'hd00001', 'tx00001', $result['node_ids'][0] ), array_column( $this->by_id( $this->data( (int) $result['id'] ) )['col0001']['elements'], 'id' ) );
	}

	public function test_borrar_quita_el_nodo_en_la_copia_y_devuelve_los_ids_quitados(): void {
		$before = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );
		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'col0001' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'operation', 'removed' ), array_keys( $result ) );
		$this->assertSame( array( 'col0001', 'hd00001', 'tx00001' ), $result['removed'] );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ) );
		$this->assertArrayNotHasKey( 'col0001', $this->by_id( $this->data( (int) $result['id'] ) ) );
		$this->assertSame( 0, $this->guard->parsed );
	}

	public function test_la_segunda_edicion_reutiliza_la_copia_y_trabaja_sobre_su_arbol(): void {
		$first = $this->exec_edit(
			array( 'id' => self::ORIGINAL, 'operation' => 'insert', 'node_id' => 'col0001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading( 'Uno' ) ) )
		);
		$added = $first['node_ids'][0];

		// El nodo sólo existe en la copia: pedirlo por el id del original igual lo encuentra.
		$second = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => $added ) );
		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'] );

		// Y se puede pasar el id de la copia directamente.
		$third = $this->exec_edit( array( 'id' => $first['id'], 'operation' => 'delete', 'node_id' => 'tx00001' ) );
		$this->assertIsArray( $third );
		$this->assertSame( $first['id'], $third['id'] );
		$this->assertSame( self::ORIGINAL, $third['draft_of'] );

		$copies = array_filter( \WP_Test_State::$posts, static fn( $p ) => 'draft' === $p->post_status );
		$this->assertCount( 1, $copies );
		$this->assertArrayHasKey( 'tx00001', $this->by_id( ElementorSectionFixtures::v3() ) );
		$this->assertArrayNotHasKey( 'tx00001', $this->by_id( $this->data( $first['id'] ) ) );
	}

	public function test_un_borrador_se_edita_directamente_sin_copia(): void {
		$this->put_page( 101, 'draft', ElementorSectionFixtures::atomic() );

		$result = $this->exec_edit( array( 'id' => 101, 'operation' => 'insert', 'node_id' => 'fx00001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 101, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertCount( 3, $this->data( 101 )[0]['elements'] );
		$this->assertCount( 2, \WP_Test_State::$posts );
		$this->assertNotSame( '', get_post_meta( 101, BuilderBackup::META_KEY, true ) );
	}

	public function test_sin_copia_por_defecto_un_publicado_se_edita_en_vivo_y_con_draft_va_a_la_copia(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) );

		$live = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0002' ) );
		$this->assertIsArray( $live );
		$this->assertSame( self::ORIGINAL, $live['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $live );
		$this->assertArrayNotHasKey( 'sec0002', $this->by_id( $this->data( self::ORIGINAL ) ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );

		$staged = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0001', 'draft' => true ) );
		$this->assertIsArray( $staged );
		$this->assertSame( self::ORIGINAL, $staged['draft_of'] );
		$this->assertArrayHasKey( 'sec0001', $this->by_id( $this->data( self::ORIGINAL ) ) );
	}

	/**
	 * Referencia, operación, posición, tipo nuevo y si se admite.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
	 */
	public static function nesting(): array {
		return array(
			'reemplazar sección por contenedor atómico'      => array( 'sec0001', 'replace', '', 'e-flexbox', true ),
			'reemplazar sección por widget (raíz)'           => array( 'sec0001', 'replace', '', 'e-heading', false ),
			'insertar contenedor antes de una sección'       => array( 'sec0002', 'insert', 'before', 'e-div-block', true ),
			'insertar widget antes de una sección (raíz)'    => array( 'sec0002', 'insert', 'before', 'e-heading', false ),
			'insertar widget después de un contenedor v3 (raíz)' => array( 'cnt0001', 'insert', 'after', 'e-heading', false ),
			'widget dentro de una columna v3'                => array( 'col0001', 'insert', 'inside', 'e-heading', true ),
			'contenedor atómico dentro de una columna v3'    => array( 'col0001', 'insert', 'inside', 'e-flexbox', false ),
			'contenedor junto a un widget de columna v3'     => array( 'hd00001', 'insert', 'after', 'e-flexbox', false ),
			'widget junto a un widget de columna v3'         => array( 'hd00001', 'insert', 'after', 'e-button', true ),
			'reemplazar widget de columna por contenedor'    => array( 'hd00001', 'replace', '', 'e-div-block', false ),
			'cualquier cosa dentro de una sección v3'        => array( 'sec0001', 'insert', 'inside', 'e-heading', false ),
			'contenedor dentro de una sección v3'            => array( 'sec0001', 'insert', 'inside', 'e-flexbox', false ),
			'widget dentro de un contenedor v3'              => array( 'cnt0001', 'insert', 'inside', 'e-heading', true ),
			'contenedor dentro de un contenedor v3'          => array( 'cnt0001', 'insert', 'inside', 'e-flexbox', true ),
			'dentro de un widget'                            => array( 'hd00002', 'insert', 'inside', 'e-heading', false ),
			'dentro de un widget (contenedor)'               => array( 'hd00002', 'insert', 'inside', 'e-flexbox', false ),
		);
	}

	/**
	 * @dataProvider nesting
	 */
	public function test_las_reglas_de_anidado_de_extremo_a_extremo( string $node, string $operation, string $position, string $type, bool $allowed ): void {
		$new   = 'e-flexbox' === $type || 'e-div-block' === $type
			? array( array( 'type' => $type ) )
			: array( array( 'type' => $type, 'settings' => array() ) );
		$input = array( 'id' => self::ORIGINAL, 'operation' => $operation, 'node_id' => $node, 'elements' => $new );

		if ( '' !== $position ) {
			$input['position'] = $position;
		}

		$snapshot = $this->snapshot();
		$result   = $this->exec_edit( $input );

		if ( $allowed ) {
			$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
			return;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $result->get_error_code() );
		$this->assertStringStartsWith( 'elements[0].type: ', $result->get_error_message() );
		$this->assertSame( $snapshot, $this->snapshot(), 'un rechazo no escribe nada ni crea copia' );
	}

	public function test_un_contenedor_atomico_admite_cualquier_tipo(): void {
		$this->put_page( 101, 'draft', ElementorSectionFixtures::atomic() );

		foreach ( array( 'e-flexbox', 'e-heading', 'e-divider' ) as $type ) {
			$this->assertIsArray(
				$this->exec_edit( array( 'id' => 101, 'operation' => 'insert', 'node_id' => 'fx00001', 'position' => 'inside', 'elements' => array( array( 'type' => $type ) ) ) ),
				$type
			);
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function rejections(): array {
		$new = ElementorSectionFixtures::new_box();

		return array(
			'operación desconocida'       => array( array( 'operation' => 'mover', 'node_id' => 'sec0001', 'elements' => $new ), 'nexo_mcp_elementor_operation_invalid' ),
			'nodo inexistente'            => array( array( 'operation' => 'replace', 'node_id' => 'nada', 'elements' => $new ), 'nexo_mcp_builder_node_missing' ),
			'sin node_id'                 => array( array( 'operation' => 'delete' ), 'nexo_mcp_builder_node_missing' ),
			'insert sin position'         => array( array( 'operation' => 'insert', 'node_id' => 'sec0001', 'elements' => $new ), 'nexo_mcp_elementor_position_invalid' ),
			'replace sin elements'        => array( array( 'operation' => 'replace', 'node_id' => 'sec0001' ), 'nexo_mcp_elementor_tree_invalid' ),
			'insert con elements vacío'   => array( array( 'operation' => 'insert', 'node_id' => 'sec0001', 'position' => 'after', 'elements' => array() ), 'nexo_mcp_elementor_tree_invalid' ),
			'delete con elements'         => array( array( 'operation' => 'delete', 'node_id' => 'sec0001', 'elements' => $new ), 'nexo_mcp_elementor_elements_forbidden' ),
			'delete con elements vacío'   => array( array( 'operation' => 'delete', 'node_id' => 'sec0001', 'elements' => array() ), 'nexo_mcp_elementor_elements_forbidden' ),
			'tipo vedado (e-svg)'         => array( array( 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => array( array( 'type' => 'e-svg' ) ) ), 'nexo_mcp_elementor_type_denied' ),
			'widget v3 como nuevo'        => array( array( 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => array( array( 'type' => 'html' ) ) ), 'nexo_mcp_elementor_type_denied' ),
			'subárbol inválido (clave)'   => array( array( 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => array( array( 'type' => 'e-flexbox', 'elements' => array( array( 'type' => 'e-heading', 'settings' => array( 'titel' => array() ) ) ) ) ) ), 'nexo_mcp_elementor_setting_unknown' ),
			'borrar la última raíz'       => array( array( 'operation' => 'delete', 'node_id' => 'only' ), 'nexo_mcp_elementor_last_root' ),
		);
	}

	/**
	 * @dataProvider rejections
	 * @param array<string, mixed> $input Parámetros sin id.
	 */
	public function test_los_rechazos_no_escriben_nada_ni_crean_copia( array $input, string $code ): void {
		$this->put_page( 102, 'publish', array( array( 'id' => 'only', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array() ) ) );
		$input['id'] = 'only' === ( $input['node_id'] ?? '' ) ? 102 : self::ORIGINAL;

		$snapshot = $this->snapshot();
		$result   = $this->exec_edit( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_los_ids_nuevos_no_chocan_con_los_existentes(): void {
		$queue  = array( 'hd00001', 'sec0001', 'zz00001', 'zz00002' );
		$result = $this->exec_edit(
			array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0002', 'elements' => ElementorSectionFixtures::new_box() ),
			static function () use ( &$queue ): string {
				return (string) array_shift( $queue );
			}
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'zz00001', 'zz00002' ), $result['node_ids'] );
	}

	public function test_el_resultado_respeta_el_tope_de_nodos_del_documento_completo(): void {
		$items = array();

		for ( $i = 0; $i < 190; $i++ ) {
			$items[] = array( 'id' => sprintf( 'dv%05d', $i ), 'elType' => 'widget', 'widgetType' => 'e-divider', 'settings' => array(), 'elements' => array() );
		}

		$this->put_page( 103, 'draft', array( array( 'id' => 'big0001', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => $items ) ) );
		$big = array( array( 'type' => 'e-flexbox', 'elements' => array_fill( 0, 9, ElementorSectionFixtures::new_heading() ) ) );

		$ok = $this->exec_edit( array( 'id' => 103, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'after', 'elements' => ElementorSectionFixtures::new_box() ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 193, count( $this->by_id( $this->data( 103 ) ) ) );

		$snapshot = $this->snapshot();
		$result   = $this->exec_edit( array( 'id' => 103, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'after', 'elements' => $big ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	/**
	 * Página v3 con un contenedor raíz y `$dividers` divisores: `$dividers + 1` nodos.
	 */
	private function put_big_page( int $id, int $dividers ): void {
		$items = array();

		for ( $i = 0; $i < $dividers; $i++ ) {
			$items[] = array( 'id' => sprintf( 'dv%05d', $i ), 'elType' => 'widget', 'widgetType' => 'e-divider', 'settings' => array(), 'elements' => array() );
		}

		$this->put_page( $id, 'draft', array( array( 'id' => 'big0001', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => $items ) ) );
	}

	private function node_count( int $id ): int {
		return count( $this->by_id( $this->data( $id ) ) );
	}

	public function test_una_pagina_v3_de_mas_de_200_nodos_admite_reemplazos_que_no_la_agrandan(): void {
		$this->put_big_page( 107, 249 ); // 250 nodos: ya pasa del tope de 200.

		// Mismo tamaño: permitido.
		$same = $this->exec_edit( array( 'id' => 107, 'operation' => 'replace', 'node_id' => 'dv00000', 'elements' => array( ElementorSectionFixtures::new_heading( 'Igual' ) ) ) );
		$this->assertIsArray( $same, $same instanceof \WP_Error ? $same->get_error_message() : '' );
		$this->assertSame( 250, $this->node_count( 107 ) );

		// Más chico: permitido.
		$smaller = $this->exec_edit( array( 'id' => 107, 'operation' => 'replace', 'node_id' => 'big0001', 'elements' => ElementorSectionFixtures::new_box() ) );
		$this->assertIsArray( $smaller, $smaller instanceof \WP_Error ? $smaller->get_error_message() : '' );
		$this->assertSame( 2, $this->node_count( 107 ) );
	}

	public function test_una_pagina_v3_de_mas_de_200_nodos_rechaza_lo_que_la_agranda(): void {
		$this->put_big_page( 107, 249 );
		$snapshot = $this->snapshot();

		$grows = $this->exec_edit( array( 'id' => 107, 'operation' => 'replace', 'node_id' => 'dv00000', 'elements' => ElementorSectionFixtures::new_box() ) );
		$this->assertInstanceOf( \WP_Error::class, $grows );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $grows->get_error_code() );
		$this->assertStringContainsString( 'máximo de 250 elementos', $grows->get_error_message() );

		$insert = $this->exec_edit( array( 'id' => 107, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );
		$this->assertInstanceOf( \WP_Error::class, $insert );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $insert->get_error_code() );

		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_una_pagina_de_200_nodos_o_menos_sigue_con_el_tope_de_200(): void {
		$this->put_big_page( 108, 198 ); // 199 nodos.

		$fits = $this->exec_edit( array( 'id' => 108, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );
		$this->assertIsArray( $fits );
		$this->assertSame( 200, $this->node_count( 108 ) );

		$over = $this->exec_edit( array( 'id' => 108, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );
		$this->assertInstanceOf( \WP_Error::class, $over );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $over->get_error_code() );
		$this->assertStringContainsString( 'máximo de 200 elementos', $over->get_error_message() );
		$this->assertSame( 200, $this->node_count( 108 ) );
	}

	public function test_el_resultado_respeta_el_tope_de_profundidad(): void {
		$tree = array( array( 'id' => 'lv00009', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array() ) );

		for ( $level = 8; $level >= 1; $level-- ) {
			$tree = array( array( 'id' => 'lv0000' . $level, 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => $tree ) );
		}

		$this->put_page( 104, 'draft', $tree );

		$this->assertIsArray( $this->exec_edit( array( 'id' => 104, 'operation' => 'insert', 'node_id' => 'lv00009', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) ) );

		$this->put_page( 105, 'draft', $tree );
		$snapshot = $this->snapshot();
		$result   = $this->exec_edit( array( 'id' => 105, 'operation' => 'insert', 'node_id' => 'lv00009', 'position' => 'inside', 'elements' => ElementorSectionFixtures::new_box() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_deep', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_el_resultado_respeta_el_tope_de_bytes_del_documento_completo(): void {
		$this->put_page( 106, 'draft', array( array( 'id' => 'big0001', 'elType' => 'e-flexbox', 'settings' => array( 'pad' => str_repeat( 'a', 3000000 ) ), 'elements' => array() ) ) );
		$snapshot = $this->snapshot();

		$result = $this->exec_edit( array( 'id' => 106, 'operation' => 'insert', 'node_id' => 'big0001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading( str_repeat( 'b', 200000 ) ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_large', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_borrar_no_exige_el_editor_v4_pero_reemplazar_e_insertar_si(): void {
		$this->guard->v4 = false;
		$snapshot        = $this->snapshot();

		foreach ( array(
			array( 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box() ),
			array( 'operation' => 'insert', 'node_id' => 'sec0001', 'position' => 'after', 'elements' => ElementorSectionFixtures::new_box() ),
		) as $input ) {
			$result = $this->exec_edit( array( 'id' => self::ORIGINAL ) + $input );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		}

		$this->assertSame( $snapshot, $this->snapshot() );
		$this->assertIsArray( $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0001' ) ) );
	}

	public function test_solo_borradores_no_frena_la_copia_pero_si_la_edicion_en_vivo(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'drafts_only' => true ) );
		$this->assertIsArray( $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0002' ) ) );

		\WP_Test_State::reset();
		$this->grant_admin();
		$this->cfg = $this->settings( array( 'read_only' => false, 'drafts_only' => true, 'working_copy_default' => false ) );
		$this->put_page( self::ORIGINAL, 'publish', ElementorSectionFixtures::v3() );
		$snapshot = $this->snapshot();

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0002' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_required', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_un_bloqueo_de_otro_usuario_frena_la_edicion_y_no_escribe(): void {
		$snapshot = $this->snapshot();
		$result   = $this->exec_edit(
			array( 'id' => self::ORIGINAL, 'operation' => 'delete', 'node_id' => 'sec0002' ),
			null,
			static fn() => new \WP_Error( 'nexo_mcp_builder_locked', 'Otro usuario tiene este contenido abierto en el editor.' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_locked', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_una_pagina_que_no_es_de_elementor_se_rechaza(): void {
		\WP_Test_State::$posts[ 110 ] = new \WP_Post( array( 'ID' => 110, 'post_type' => 'page', 'post_status' => 'publish' ) );
		$snapshot                     = $this->snapshot();

		$result = $this->exec_edit( array( 'id' => 110, 'operation' => 'delete', 'node_id' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_not_elementor', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_los_nodos_no_tocados_quedan_identicos_en_forma_decodificada(): void {
		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'hd00001', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );

		// Un widget atómico no cabe en esa columna? Sí: los widgets caben en una columna.
		$this->assertIsArray( $result );
		$after  = $this->by_id( $this->data( $result['id'] ) );
		$before = $this->by_id( ElementorSectionFixtures::v3() );

		foreach ( array( 'sec0002', 'col0002', 'bt00001', 'cnt0001', 'hd00002', 'tx00001' ) as $id ) {
			$this->assertSame( json_encode( $before[ $id ] ), json_encode( $after[ $id ] ), $id );
		}

		// El ancestro cambia sólo en la lista de hijos.
		$column = $before['col0001'];
		$moved  = $after['col0001'];
		unset( $column['elements'], $moved['elements'] );
		$this->assertSame( json_encode( $column ), json_encode( $moved ) );
	}

	public function test_lo_que_se_guarda_es_lo_saneado_y_no_lo_que_mando_el_cliente(): void {
		$payload = array(
			'type'     => 'e-heading',
			'settings' => array(
				'title'  => ElementorSectionFixtures::prop( 'escaped-html', '<script>alert(1)</script><img src=x onerror=alert(2)>' ),
				'_cssid' => ElementorSectionFixtures::prop( 'string', 'a"><script>alert(1)</script>' ),
			),
		);

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'insert', 'node_id' => 'cnt0001', 'position' => 'inside', 'elements' => array( $payload ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $this->guard->sanitized );
		$stored = $this->by_id( $this->data( $result['id'] ) )[ $result['node_ids'][0] ]['settings'];
		$this->assertSame( 'alert(1)', $stored['title']['value'] );
		$this->assertSame( 'a>alert(1)', $stored['_cssid']['value'] );
		$this->assertStringNotContainsString( '<script', (string) get_post_meta( (int) $result['id'], '_elementor_data', true ) );
	}

	public function test_un_error_de_saneado_no_escribe_nada(): void {
		$guard = new class() extends FakeSchemaGuard {
			public function sanitize( array $elements ) {
				return new \WP_Error( 'nexo_mcp_elementor_props_invalid', 'no se pudo sanear' );
			}
		};
		$this->guard = $guard;
		$snapshot    = $this->snapshot();

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_props_invalid', $result->get_error_code() );
		$this->assertSame( $snapshot, $this->snapshot() );
	}

	public function test_insertar_dentro_de_un_contenedor_sin_clave_elements_funciona(): void {
		$tree = ElementorSectionFixtures::atomic();
		unset( $tree[0]['elements'] );
		$this->put_page( 107, 'draft', $tree );

		$result = $this->exec_edit( array( 'id' => 107, 'operation' => 'insert', 'node_id' => 'fx00001', 'position' => 'inside', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertCount( 1, $this->data( 107 )[0]['elements'] );
	}

	public function test_con_el_original_bloqueado_por_otro_proceso_no_se_escribe_ni_se_crea_copia(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 0.1;
		\WP_Test_State::$options[ 'nexo_mcp_lock_' . self::ORIGINAL ] = 'otro|' . ( time() + 100 );
		$before = $this->snapshot();

		$result = $this->exec_edit(
			array(
				'id'        => self::ORIGINAL,
				'operation' => 'replace',
				'node_id'   => 'sec0001',
				'elements'  => ElementorSectionFixtures::new_box( 'Reconstruido' ),
			)
		);

		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( 0, $this->find_copy() );
	}

	public function test_la_edicion_libera_el_bloqueo_al_terminar(): void {
		$this->exec_edit(
			array(
				'id'        => self::ORIGINAL,
				'operation' => 'replace',
				'node_id'   => 'sec0001',
				'elements'  => ElementorSectionFixtures::new_box( 'Reconstruido' ),
			)
		);

		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}

	public function test_editar_por_una_copia_descartada_mientras_se_esperaba_no_escribe(): void {
		$first = $this->exec_edit(
			array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box( 'Uno' ) )
		);
		$this->assertIsArray( $first );
		$copy_id = (int) $first['id'];

		\WP_Test_State::$cache_posts = true;
		$stale = get_post( $copy_id ); // El llamador cargó la copia antes de esperar el bloqueo.
		( new WorkingCopy( $this->cfg ) )->discard( $stale ); // Otro proceso la descartó entretanto.
		$before = $this->snapshot();

		$result = $this->editor()->edit(
			$stale,
			array( 'operation' => 'replace', 'node_id' => 'sec0002', 'elements' => ElementorSectionFixtures::new_box( 'Dos' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_orphan', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * Copias de trabajo vivas (borradores enlazados a un original).
	 *
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

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function failures_after_the_copy(): array {
		return array(
			'falla el respaldo'  => array( 'capture', 'nexo_mcp_builder_backup_failed' ),
			'falla save_tree'    => array( 'save_tree', 'nexo_mcp_builder_too_large' ),
		);
	}

	/**
	 * @dataProvider failures_after_the_copy
	 */
	public function test_si_falla_el_respaldo_o_el_guardado_tras_crear_la_copia_no_queda_copia( string $fails_in, string $code ): void {
		$before = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );
		\WP_Test_State::$json_fail_in = $fails_in;

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $this->live_copies(), 'no queda ninguna copia viva' );
		$this->assertSame( 0, $this->find_copy(), 'el original ya no apunta a una copia' );
		$this->assertSame( '', (string) get_post_meta( self::ORIGINAL, WorkingCopy::META_DRAFT, true ) );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::ORIGINAL + 1 ]->post_status, 'la copia creada se mandó a la papelera' );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}

	/**
	 * @dataProvider failures_after_the_copy
	 */
	public function test_si_falla_con_una_copia_que_ya_existia_esa_copia_no_se_descarta( string $fails_in, string $code ): void {
		$first = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box( 'Uno' ) ) );
		$this->assertIsArray( $first );
		$copy   = (int) $first['id'];
		$before = (string) get_post_meta( $copy, '_elementor_data', true );
		\WP_Test_State::$json_fail_in = $fails_in;

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0002', 'elements' => ElementorSectionFixtures::new_box( 'Dos' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( $copy ), $this->live_copies(), 'la copia anterior sigue viva' );
		$this->assertSame( $copy, $this->find_copy() );
		$this->assertSame( $before, get_post_meta( $copy, '_elementor_data', true ) );
	}

	public function test_si_el_bloqueo_se_pierde_antes_de_escribir_no_se_escribe_ni_se_crea_copia(): void {
		$this->put_page( 109, 'draft', ElementorSectionFixtures::v3() );

		foreach ( array( self::ORIGINAL, 109 ) as $id ) {
			$this->lose_lock_after_acquiring( $id );
			$before = $this->snapshot();

			$result = $this->exec_edit( array( 'id' => $id, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box() ) );

			$this->assertInstanceOf( \WP_Error::class, $result, 'id ' . $id );
			$this->assertSame( 'nexo_mcp_busy', $result->get_error_code(), 'id ' . $id );
			$this->assertSame( $before, $this->snapshot(), 'id ' . $id );
			$this->assertSame( 0, $this->find_copy() );
			$this->assertStringStartsWith( 'otro-dueno|', \WP_Test_State::$options[ 'nexo_mcp_lock_' . $id ] );
			\WP_Test_State::$options = array();
		}
	}

	public function test_si_falla_el_guardado_y_tampoco_se_puede_descartar_la_copia_el_error_lo_dice(): void {
		\WP_Test_State::$json_fail_in = 'save_tree';
		\WP_Test_State::$fail_trash    = true;

		$result = $this->exec_edit( array( 'id' => self::ORIGINAL, 'operation' => 'replace', 'node_id' => 'sec0001', 'elements' => ElementorSectionFixtures::new_box() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_copy_orphan', $result->get_error_code() );
		$this->assertSame( 'nexo_mcp_builder_too_large', $result->get_error_data()['original_error'] );
	}
}
