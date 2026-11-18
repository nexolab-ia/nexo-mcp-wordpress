<?php
/**
 * Herramientas de constructor viejas: destino (copia de trabajo o borrador), rechazos y bloqueo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\BuilderGateway;
use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class BuilderWorkingCopyTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private const DRAFT = 101;

	private BuilderGateway $gateway;

	protected function setUp(): void {
		parent::setUp();

		$cfg           = $this->settings( array( 'read_only' => false ) );
		$this->gateway = new BuilderGateway( null, null, $cfg );
		$this->put_page( self::ORIGINAL, 'publish' );
		$this->put_page( self::DRAFT, 'draft' );
	}

	protected function tearDown(): void {
		PostLock::$wait = 5.0;
		parent::tearDown();
	}

	private function put_page( int $id, string $status ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'page',
				'post_status' => $status,
				'post_title'  => 'Inicio',
			)
		);
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', (string) wp_json_encode( ElementorSectionFixtures::v3(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function raw( int $id ): string {
		return (string) get_post_meta( $id, '_elementor_data', true );
	}

	private function copy_id(): int {
		return WorkingCopy::copy_of( \WP_Test_State::$posts[ self::ORIGINAL ] );
	}

	private function snapshot(): string {
		return serialize( array( array_keys( \WP_Test_State::$posts ), \WP_Test_State::$meta ) );
	}

	/**
	 * Llama a la herramienta indicada.
	 *
	 * @param string               $tool  Nombre.
	 * @param array<string, mixed> $input Parámetros.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $tool, array $input ) {
		switch ( $tool ) {
			case 'text':
				return $this->gateway->update_node_text( $input + array( 'node_id' => 'hd00001', 'text' => 'Nuevo texto' ) );
			case 'attribute':
				return $this->gateway->update_node_attribute( $input + array( 'node_id' => 'bt00001', 'attribute' => 'link', 'value' => 'https://nuevo.example/' ) );
			case 'structure':
				return $this->gateway->edit_structure( $input + array( 'node_id' => 'hd00001' ) );
			default:
				return $this->gateway->restore_backup( $input );
		}
	}

	/**
	 * Deja un respaldo en el contenido, como el de una edición anterior, y cambia su árbol.
	 */
	private function seed_backup( int $id ): string {
		$adapter = ( new \NexoDev\NexoMCP\Builders\BuilderRegistry() )->resolve( \WP_Test_State::$posts[ $id ] );
		$this->assertTrue( ( new BuilderBackup() )->capture( \WP_Test_State::$posts[ $id ], $adapter ) );
		$old = $this->raw( $id );
		update_post_meta( $id, '_elementor_data', wp_slash( str_replace( 'Botón', 'Botón editado en vivo', $old ) ) );
		$this->assertNotSame( $old, $this->raw( $id ) );

		return $old;
	}

	/**
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function tools(): array {
		return array(
			'update-node-text'      => array( 'text', array( 'id', 'builder', 'node_id', 'updated', 'backup' ) ),
			'update-node-attribute' => array( 'attribute', array( 'id', 'builder', 'node_id', 'attribute', 'updated', 'backup' ) ),
			'edit-structure'        => array( 'structure', array( 'id', 'created', 'backup' ) ),
			'restore-builder-backup' => array( 'restore', array( 'id', 'restored' ) ),
		);
	}

	/**
	 * @dataProvider tools
	 * @param list<string> $keys Campos de la respuesta de siempre.
	 */
	public function test_un_borrador_se_edita_en_su_sitio_con_los_campos_de_siempre( string $tool, array $keys ): void {
		$old = 'restore' === $tool ? $this->seed_backup( self::DRAFT ) : $this->raw( self::DRAFT );

		$result = $this->call( $tool, array( 'id' => self::DRAFT ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $keys, array_keys( $result ) );
		$this->assertSame( self::DRAFT, $result['id'] );
		$this->assertCount( 2, \WP_Test_State::$posts );
		if ( 'restore' === $tool ) {
			$this->assertSame( $old, $this->raw( self::DRAFT ) );
		} else {
			$this->assertNotSame( $old, $this->raw( self::DRAFT ) );
		}
		$this->assertSame( 0, $this->copy_id() );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_una_pagina_publicada_se_edita_en_su_copia_y_el_original_no_cambia( string $tool ): void {
		$old  = 'restore' === $tool ? $this->seed_backup( self::ORIGINAL ) : '';
		$live = $this->raw( self::ORIGINAL );

		$result = $this->call( $tool, array( 'id' => self::ORIGINAL ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$copy = $this->copy_id();
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $live, $this->raw( self::ORIGINAL ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );
		if ( 'restore' === $tool ) {
			$this->assertSame( $old, $this->raw( $copy ) );
		} else {
			$this->assertNotSame( $live, $this->raw( $copy ) );
		}
		// Una edición respalda en la copia lo editado; restaurar no captura (la copia nueva es un clon).
		$this->assertSame( 'restore' === $tool, '' === (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_llamar_por_el_id_de_la_copia_sigue_en_la_copia( string $tool ): void {
		if ( 'restore' === $tool ) {
			$this->call( 'text', array( 'id' => self::ORIGINAL ) );
			$copy      = $this->copy_id();
			$edited    = $this->raw( $copy );
			$result    = $this->call( 'restore', array( 'id' => $copy ) );
			$this->assertIsArray( $result );
			$this->assertSame( $copy, $result['id'] );
			$this->assertNotSame( $edited, $this->raw( $copy ), 'restaurar por la copia deshace su última edición' );
			$this->assertStringContainsString( 'Título ñandú', $this->raw( $copy ) );
			$this->assertSame( self::ORIGINAL, $result['draft_of'] );

			return;
		}

		$this->call( $tool, array( 'id' => self::ORIGINAL ) );
		$copy = $this->copy_id();
		$live = $this->raw( self::ORIGINAL );
		$posts = count( \WP_Test_State::$posts );

		$result = $this->call( $tool, array( 'id' => $copy, 'node_id' => 'attribute' === $tool ? 'bt00001' : 'tx00001', 'value' => 'https://otro.example/' ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertCount( $posts, \WP_Test_State::$posts );
		$this->assertSame( $live, $this->raw( self::ORIGINAL ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function rejections(): array {
		return array(
			'texto: nodo inexistente'       => array( 'text', array( 'node_id' => 'nada' ), 'nexo_mcp_builder_node_missing' ),
			'texto: nodo vedado'            => array( 'text', array( 'node_id' => 'col0001' ), 'nexo_mcp_builder_type_forbidden' ),
			'atributo: nodo inexistente'    => array( 'attribute', array( 'node_id' => 'nada' ), 'nexo_mcp_builder_node_missing' ),
			'atributo: no permitido'        => array( 'attribute', array( 'attribute' => 'alt' ), 'nexo_mcp_builder_attr_denied' ),
			'atributo: valor inválido'      => array( 'attribute', array( 'value' => 'javascript:alert(1)' ), 'nexo_mcp_attr_link_denied' ),
			'estructura: nodo inexistente'  => array( 'structure', array( 'node_id' => 'nada' ), 'nexo_mcp_builder_node_missing' ),
			'estructura: destino que no cabe' => array( 'structure', array( 'node_id' => 'hd00001', 'target' => 'sec0002' ), 'nexo_mcp_builder_bad_position' ),
			'restaurar: sin respaldo'       => array( 'restore', array(), 'nexo_mcp_builder_no_backup' ),
		);
	}

	/**
	 * @dataProvider rejections
	 * @param array<string, mixed> $extra Parámetros que provocan el rechazo.
	 */
	public function test_un_rechazo_en_una_pagina_publicada_no_crea_copia_ni_escribe( string $tool, array $extra, string $code ): void {
		$before = $this->snapshot();

		$result = $this->call( $tool, array( 'id' => self::ORIGINAL ) + $extra );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( 0, $this->copy_id() );
	}

	public function test_sin_copia_por_defecto_se_edita_en_vivo_y_con_draft_va_a_la_copia(): void {
		$this->gateway = new BuilderGateway( null, null, $this->settings( array( 'read_only' => false, 'working_copy_default' => false ) ) );
		$old           = $this->raw( self::ORIGINAL );

		$live = $this->call( 'text', array( 'id' => self::ORIGINAL ) );

		$this->assertIsArray( $live );
		$this->assertSame( self::ORIGINAL, $live['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $live );
		$this->assertNotSame( $old, $this->raw( self::ORIGINAL ) );
		$this->assertSame( 0, $this->copy_id() );

		$staged = $this->call( 'text', array( 'id' => self::ORIGINAL, 'draft' => true, 'node_id' => 'tx00001' ) );

		$this->assertIsArray( $staged );
		$this->assertSame( self::ORIGINAL, $staged['draft_of'] );
	}

	public function test_solo_borradores_frena_la_edicion_en_vivo_pero_no_la_copia(): void {
		$this->gateway = new BuilderGateway( null, null, $this->settings( array( 'read_only' => false, 'drafts_only' => true, 'working_copy_default' => false ) ) );
		$before        = $this->snapshot();

		$result = $this->call( 'text', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_draft_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertIsArray( $this->call( 'text', array( 'id' => self::ORIGINAL, 'draft' => true ) ) );
	}

	public function test_restaurar_por_el_original_con_copia_deshace_la_ultima_edicion_de_la_copia(): void {
		$this->seed_backup( self::ORIGINAL );
		$live = $this->raw( self::ORIGINAL );
		$this->call( 'text', array( 'id' => self::ORIGINAL ) );
		$copy   = $this->copy_id();
		$backup = (string) get_post_meta( $copy, BuilderBackup::META_KEY, true );

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertIsArray( $result );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $live, $this->raw( $copy ), 'vuelve al estado previo a la edición de la copia' );
		$this->assertSame( $backup, (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ), 'no captura antes de escribir' );

		// Reintento: mismo estado, y el respaldo de la copia no se pierde.
		$state = $this->snapshot();
		$this->assertSame( $result, $this->call( 'restore', array( 'id' => self::ORIGINAL ) ) );
		$this->assertSame( $state, $this->snapshot() );
	}

	public function test_sin_copia_el_respaldo_del_original_se_restaura_en_una_copia_nueva(): void {
		$old      = $this->seed_backup( self::ORIGINAL );
		$live     = $this->raw( self::ORIGINAL );
		$orig_bak = (string) get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true );

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertIsArray( $result );
		$copy = $this->copy_id();
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $old, $this->raw( $copy ) );
		$this->assertSame( $live, $this->raw( self::ORIGINAL ) );
		$this->assertSame( $orig_bak, (string) get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true ) );
		$this->assertSame( '', (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ), 'la copia nueva no tenía ediciones que respaldar' );
	}

	public function test_sin_copia_ni_respaldo_no_se_crea_copia(): void {
		$state = $this->snapshot();

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_no_backup', $result->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_con_el_original_bloqueado_por_otro_no_se_escribe_ni_se_crea_copia( string $tool ): void {
		if ( 'restore' === $tool ) {
			$this->seed_backup( self::ORIGINAL );
		}
		PostLock::$wait = 0.1;
		\WP_Test_State::$options[ 'nexo_mcp_lock_' . self::ORIGINAL ] = 'otro|' . ( time() + 100 );
		$before = $this->snapshot();

		$result = $this->call( $tool, array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_busy', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( 0, $this->copy_id() );
	}

	/**
	 * @dataProvider tools
	 */
	public function test_el_bloqueo_se_libera_tras_escribir_y_tras_rechazar( string $tool ): void {
		if ( 'restore' === $tool ) {
			$this->seed_backup( self::ORIGINAL );
		}

		$ok = $this->call( $tool, array( 'id' => self::ORIGINAL ) );
		$this->assertIsArray( $ok );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );

		$this->call( $tool, array( 'id' => self::ORIGINAL, 'node_id' => 'nada' ) );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}
}
