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
		// El respaldo vive en la copia (lo editado), no en el original. Restaurar el del
		// original en una copia nueva no captura: no hay ediciones de la copia que perder.
		if ( 'restore' === $tool ) {
			$this->assertSame( '', (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
		} else {
			$this->assertNotSame( '', (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
		}
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

	public function test_restaurar_por_el_id_del_original_con_copia_deshace_la_ultima_edicion_de_la_copia(): void {
		$live_backup = $this->seed_backup( self::ORIGINAL );
		$live        = $this->raw( self::ORIGINAL );
		$this->call( 'text', array( 'id' => self::ORIGINAL ) );
		$copy       = $this->copy_id();
		$edited     = $this->raw( $copy );
		$copy_pre   = (string) get_post_meta( $copy, BuilderBackup::META_KEY, true );
		$orig_pre   = (string) get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true );
		$this->assertNotSame( $live, $edited );

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( 'id', 'restored', 'draft_of' ), array_keys( $result ) );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		// Deshizo la edición de la copia (su estado previo era el del original en vivo), no restauró el respaldo del original.
		$this->assertSame( $live, $this->raw( $copy ) );
		$this->assertNotSame( $live_backup, $this->raw( $copy ) );
		// El original y su respaldo no se tocan; la copia conserva el suyo.
		$this->assertSame( $live, $this->raw( self::ORIGINAL ) );
		$this->assertSame( $orig_pre, (string) get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true ) );
		$this->assertSame( $copy_pre, (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
		$this->assertCount( 3, \WP_Test_State::$posts );
	}

	public function test_restaurar_por_el_original_y_por_la_copia_dan_el_mismo_resultado(): void {
		$this->seed_backup( self::ORIGINAL );
		$this->call( 'text', array( 'id' => self::ORIGINAL ) );
		$copy = $this->copy_id();

		$by_original = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );
		$state       = $this->snapshot();
		$by_copy     = $this->call( 'restore', array( 'id' => $copy ) );

		$this->assertSame( $by_original, $by_copy );
		$this->assertSame( $state, $this->snapshot() );
	}

	public function test_restaurar_dos_veces_seguidas_deja_el_mismo_estado_y_no_pierde_la_edicion_previa(): void {
		$this->seed_backup( self::ORIGINAL );
		$this->call( 'text', array( 'id' => self::ORIGINAL ) );
		$copy = $this->copy_id();

		$first = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );
		$state = $this->snapshot();
		$this->assertIsArray( $first );

		$retry = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertSame( $first, $retry );
		$this->assertSame( $state, $this->snapshot(), 'el reintento no cambia nada (no captura antes de escribir)' );
		$this->assertStringContainsString( 'Título ñandú', $this->raw( $copy ) );
		$this->assertStringNotContainsString( 'Nuevo texto', $this->raw( $copy ) );
	}

	public function test_restaurar_por_el_id_de_la_copia_tampoco_captura_antes_de_escribir(): void {
		$this->call( 'text', array( 'id' => self::ORIGINAL ) );
		$copy   = $this->copy_id();
		$backup = (string) get_post_meta( $copy, BuilderBackup::META_KEY, true );

		$this->assertIsArray( $this->call( 'restore', array( 'id' => $copy ) ) );
		$this->assertSame( $backup, (string) get_post_meta( $copy, BuilderBackup::META_KEY, true ) );
		$state = $this->snapshot();

		$this->assertIsArray( $this->call( 'restore', array( 'id' => $copy ) ) );
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

		// Reintento: ahora hay copia y no tiene respaldo propio; no pierde nada y no cambia nada.
		$state = $this->snapshot();
		$retry = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $retry );
		$this->assertSame( 'nexo_mcp_builder_no_backup', $retry->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
	}

	public function test_con_copia_sin_respaldo_propio_no_se_usa_el_respaldo_del_original(): void {
		$this->seed_backup( self::ORIGINAL );
		$copy = ( new \NexoDev\NexoMCP\WordPress\WorkingCopy( $this->settings( array( 'read_only' => false ) ) ) )->obtain( \WP_Test_State::$posts[ self::ORIGINAL ] );
		$this->assertInstanceOf( \WP_Post::class, $copy );
		$state = $this->snapshot();

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_no_backup', $result->get_error_code() );
		$this->assertSame( $state, $this->snapshot() );
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
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function failures_after_the_copy(): array {
		return array(
			'texto: falla el respaldo'       => array( 'text', 'capture', 'nexo_mcp_builder_backup_failed' ),
			'texto: falla el guardado'       => array( 'text', 'update_node_text', 'nexo_mcp_builder_too_large' ),
			'atributo: falla el respaldo'    => array( 'attribute', 'capture', 'nexo_mcp_builder_backup_failed' ),
			'atributo: falla el guardado'    => array( 'attribute', 'update_node_attribute', 'nexo_mcp_builder_too_large' ),
			'estructura: falla el respaldo'  => array( 'structure', 'capture', 'nexo_mcp_builder_backup_failed' ),
			'estructura: falla el guardado'  => array( 'structure', 'save_tree', 'nexo_mcp_builder_too_large' ),
		);
	}

	/**
	 * @dataProvider failures_after_the_copy
	 */
	public function test_si_falla_el_respaldo_o_el_guardado_tras_crear_la_copia_no_queda_copia_huerfana( string $tool, string $fails_in, string $code ): void {
		$live                          = $this->raw( self::ORIGINAL );
		\WP_Test_State::$json_fail_in = $fails_in;

		$result = $this->call( $tool, array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assert_no_orphan_copy( $live );
	}

	public function test_si_falla_el_guardado_de_restaurar_tras_crear_la_copia_no_queda_copia_huerfana(): void {
		$this->seed_backup( self::ORIGINAL );
		$live                             = $this->raw( self::ORIGINAL );
		\WP_Test_State::$fail_update_post = true;

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'db_update_error', $result->get_error_code() );
		$this->assert_no_orphan_copy( $live );
		$this->assertNotSame( '', (string) get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true ), 'el respaldo del original sigue ahí' );
	}

	/**
	 * La copia que creó la llamada fallida está en la papelera y el original quedó como estaba.
	 */
	private function assert_no_orphan_copy( string $live ): void {
		$this->assertSame( array(), $this->live_copies(), 'no queda ninguna copia viva' );
		$this->assertSame( 0, $this->copy_id(), 'el original ya no apunta a una copia' );
		$this->assertSame( '', (string) get_post_meta( self::ORIGINAL, WorkingCopy::META_DRAFT, true ) );
		$this->assertSame( 'trash', \WP_Test_State::$posts[ self::DRAFT + 1 ]->post_status, 'la copia creada se mandó a la papelera' );
		$this->assertSame( $live, $this->raw( self::ORIGINAL ) );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}

	/**
	 * @dataProvider failures_after_the_copy
	 */
	public function test_si_falla_con_una_copia_que_ya_existia_esa_copia_no_se_descarta( string $tool, string $fails_in, string $code ): void {
		$this->call( 'text', array( 'id' => self::ORIGINAL, 'node_id' => 'tx00001' ) );
		$copy                          = $this->copy_id();
		$before                        = $this->raw( $copy );
		\WP_Test_State::$json_fail_in = $fails_in;

		$result = $this->call( $tool, array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( $copy ), $this->live_copies(), 'la copia anterior sigue viva' );
		$this->assertSame( $copy, $this->copy_id() );
		$this->assertSame( $before, $this->raw( $copy ) );
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

	/**
	 * @dataProvider tools
	 */
	public function test_si_el_bloqueo_se_pierde_antes_de_escribir_no_se_escribe_ni_se_crea_copia( string $tool ): void {
		foreach ( array( self::ORIGINAL, self::DRAFT ) as $id ) {
			if ( 'restore' === $tool ) {
				$this->seed_backup( $id );
			}
			$this->lose_lock_after_acquiring( $id );
			$before = $this->snapshot();

			$result = $this->call( $tool, array( 'id' => $id ) );

			$this->assertInstanceOf( \WP_Error::class, $result, 'id ' . $id );
			$this->assertSame( 'nexo_mcp_busy', $result->get_error_code(), 'id ' . $id );
			$this->assertSame( $before, $this->snapshot(), 'id ' . $id );
			$this->assertSame( 0, $this->copy_id() );
			// El bloqueo del otro dueño no se borra.
			$this->assertStringStartsWith( 'otro-dueno|', \WP_Test_State::$options[ 'nexo_mcp_lock_' . $id ] );
			\WP_Test_State::$options = array();
		}
	}

	public function test_editar_justo_despues_de_restaurar_en_el_mismo_segundo_no_falla_el_respaldo(): void {
		foreach ( array( 'Uno', 'Dos' ) as $text ) {
			$this->assertIsArray( $this->call( 'text', array( 'id' => self::ORIGINAL, 'text' => $text ) ) );
		}
		$this->assertIsArray( $this->call( 'restore', array( 'id' => self::ORIGINAL ) ) );

		$result = $this->call( 'text', array( 'id' => self::ORIGINAL, 'text' => 'Tres' ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		$this->assertStringContainsString( 'Tres', $this->raw( $this->copy_id() ) );
	}

	public function test_capturar_dos_veces_el_mismo_estado_es_exito(): void {
		$adapter = ( new \NexoDev\NexoMCP\Builders\BuilderRegistry() )->resolve( \WP_Test_State::$posts[ self::DRAFT ] );
		$backup  = new BuilderBackup();

		$this->assertTrue( $backup->capture( \WP_Test_State::$posts[ self::DRAFT ], $adapter ) );
		$this->assertTrue( $backup->capture( \WP_Test_State::$posts[ self::DRAFT ], $adapter ) );
	}

	public function test_si_tampoco_se_puede_descartar_la_copia_el_error_lo_dice_y_conserva_el_original(): void {
		$this->seed_backup( self::ORIGINAL );
		\WP_Test_State::$fail_update_post = true;
		\WP_Test_State::$fail_trash       = true;

		$result = $this->call( 'restore', array( 'id' => self::ORIGINAL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_builder_copy_orphan', $result->get_error_code() );
		$this->assertStringContainsString( 'copia de trabajo', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertSame( 'db_update_error', $data['original_error'] );
		$this->assertSame( $this->copy_id(), $data['copy_id'] );
	}
}
