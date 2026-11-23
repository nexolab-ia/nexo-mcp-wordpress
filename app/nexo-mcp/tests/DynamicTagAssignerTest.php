<?php
/**
 * set-dynamic-tag: destino (copia de trabajo), valor normalizado, errores sin escribir y respuesta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\DynamicTagAssigner;
use NexoDev\NexoMCP\Builders\DynamicTagCatalog;
use NexoDev\NexoMCP\Builders\ElementorAdapter;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DynamicTagAssignerTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private Settings $cfg;

	private FakeSchemaGuard $guard;

	/**
	 * Tags que el «sitio» tiene registrados.
	 *
	 * @var array<int, string>
	 */
	private array $registered = array( 'post-title', 'site-title', 'post-terms', 'post-featured-image', 'site-url' );

	protected function setUp(): void {
		parent::setUp();

		$this->cfg   = $this->settings( array( 'read_only' => false, 'builders_dynamic_enabled' => true ) );
		$this->guard = new FakeSchemaGuard();
		$this->put_page( self::ORIGINAL, 'publish', $this->page() );
	}

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	/**
	 * Contenedor con un heading, una imagen y un widget v3.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(): array {
		return array(
			array(
				'id'       => 'fx00001',
				'elType'   => 'e-flexbox',
				'settings' => array(),
				'styles'   => array(),
				'elements' => array(
					array(
						'id'         => 'eh00001',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => array( 'title' => ElementorSectionFixtures::prop( 'escaped-html', 'Hola' ) ),
						'styles'     => array(),
						'elements'   => array(),
					),
					array(
						'id'         => 'ei00001',
						'elType'     => 'widget',
						'widgetType' => 'e-image',
						'settings'   => array(),
						'styles'     => array(),
						'elements'   => array(),
					),
					ElementorSectionFixtures::v3_widget( 'hd00009', 'heading', array( 'title' => 'v3' ) ),
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

	private function assigner( ?callable $lock = null ): DynamicTagAssigner {
		return new DynamicTagAssigner(
			$this->cfg,
			new ElementorAdapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$this->guard,
			$lock ?? static fn() => true,
			new DynamicTagCatalog( fn(): array => $this->registered, static fn( string $t ): bool => 'category' === $t )
		);
	}

	/**
	 * @param array<string, mixed> $input Parámetros (por omisión, el título del heading).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_assign( array $input = array(), int $id = self::ORIGINAL, ?callable $lock = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $this->assigner( $lock )->assign(
			$post,
			array_merge(
				array(
					'node_id' => 'eh00001',
					'prop'    => 'title',
					'tag'     => array( 'name' => 'post-title' ),
				),
				$input
			)
		);
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

	public function test_asigna_en_la_copia_el_valor_normalizado_y_deja_el_original(): void {
		$before = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$result = $this->run_assign( array( 'tag' => array( 'name' => 'post-title', 'settings' => array( 'before' => '<b>Hola, </b>', 'after' => '  !' ) ) ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$copy = WorkingCopy::copy_of( \WP_Test_State::$posts[ self::ORIGINAL ] );
		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( $copy, $result['id'] );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( 'publish', \WP_Test_State::$posts[ self::ORIGINAL ]->post_status );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ), 'el original no cambia' );
		$this->assertSame( '', get_post_meta( self::ORIGINAL, BuilderBackup::META_KEY, true ) );
		$this->assertNotSame( '', get_post_meta( $copy, BuilderBackup::META_KEY, true ), 'se respalda la copia' );

		$expected = array(
			'$$type' => 'dynamic',
			'value'  => array(
				'name'     => 'post-title',
				'group'    => 'post',
				'settings' => array( 'before' => 'Hola,', 'after' => '!' ),
			),
		);
		$stored   = $this->data( $copy );

		$this->assertSame( $expected, $stored[0]['elements'][0]['settings']['title'], 'el valor normalizado del catálogo, no el del cliente' );

		// Lo demás queda idéntico.
		$orig = $this->page();
		unset( $stored[0]['elements'][0]['settings']['title'], $orig[0]['elements'][0]['settings']['title'] );
		$this->assertSame( json_encode( $orig ), json_encode( $stored ) );
	}

	public function test_la_respuesta_trae_la_forma_acordada(): void {
		$result = $this->run_assign( array( 'tag' => array( 'name' => 'post-title', 'settings' => array( 'before' => 'A' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'draft_of', 'preview_url', 'node_id', 'prop', 'tag' ), array_keys( $result ) );
		$this->assertStringContainsString( (string) $result['id'], $result['preview_url'] );
		$this->assertSame( 'eh00001', $result['node_id'] );
		$this->assertSame( 'title', $result['prop'] );
		$this->assertSame( array( 'name' => 'post-title', 'group' => 'post', 'settings' => array( 'before' => 'A' ) ), $result['tag'] );
	}

	public function test_la_imagen_destacada_va_a_e_image_image(): void {
		$result = $this->run_assign( array( 'node_id' => 'ei00001', 'prop' => 'image', 'tag' => array( 'name' => 'post-featured-image' ) ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'dynamic', $this->data( $result['id'] )[0]['elements'][1]['settings']['image']['$$type'] );
	}

	public function test_asignar_otra_vez_reemplaza_el_tag_y_reutiliza_la_copia(): void {
		$first  = $this->run_assign();
		$second = $this->run_assign( array( 'tag' => array( 'name' => 'site-title' ) ), self::ORIGINAL );

		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( $this->live_copies(), array( $first['id'] ) );
		$this->assertSame( 'site-title', $this->data( $second['id'] )[0]['elements'][0]['settings']['title']['value']['name'] );
		$this->assertSame( 'site', $this->data( $second['id'] )[0]['elements'][0]['settings']['title']['value']['group'] );
	}

	public function test_con_el_id_de_la_copia_escribe_en_la_copia(): void {
		$first = $this->run_assign();
		$again = $this->run_assign( array( 'tag' => array( 'name' => 'site-title' ) ), (int) $first['id'] );

		$this->assertIsArray( $again );
		$this->assertSame( $first['id'], $again['id'] );
		$this->assertSame( self::ORIGINAL, $again['draft_of'] );
		$this->assertSame( array( $first['id'] ), $this->live_copies() );
	}

	public function test_un_borrador_se_edita_en_su_sitio_sin_copia(): void {
		$this->put_page( 105, 'draft', $this->page() );

		$result = $this->run_assign( array(), 105 );

		$this->assertIsArray( $result );
		$this->assertSame( 105, $result['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $result );
		$this->assertSame( 'dynamic', $this->data( 105 )[0]['elements'][0]['settings']['title']['$$type'] );
		$this->assertSame( array(), $this->live_copies() );
		$this->assertNotSame( '', get_post_meta( 105, BuilderBackup::META_KEY, true ) );
	}

	public function test_con_la_copia_por_defecto_apagada_draft_true_va_a_la_copia_y_sin_draft_en_vivo(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'builders_dynamic_enabled' => true, 'working_copy_default' => false ) );
		$before    = (string) get_post_meta( self::ORIGINAL, '_elementor_data', true );

		$copy = $this->run_assign( array( 'draft' => true ) );

		$this->assertIsArray( $copy, $copy instanceof \WP_Error ? $copy->get_error_message() : '' );
		$this->assertSame( self::ORIGINAL, $copy['draft_of'] );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ), 'el original no cambia' );

		\WP_Test_State::reset();
		$this->grant_admin();
		$this->cfg = $this->settings( array( 'read_only' => false, 'builders_dynamic_enabled' => true, 'working_copy_default' => false ) );
		$this->put_page( self::ORIGINAL, 'publish', $this->page() );

		$live = $this->run_assign();

		$this->assertIsArray( $live );
		$this->assertSame( self::ORIGINAL, $live['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $live );
		$this->assertSame( 'dynamic', $this->data( self::ORIGINAL )[0]['elements'][0]['settings']['title']['$$type'] );
		$this->assertSame( array(), $this->live_copies() );
	}

	public function test_solo_borradores_frena_el_vivo_pero_no_la_copia(): void {
		$this->cfg = $this->settings( array( 'read_only' => false, 'builders_dynamic_enabled' => true, 'drafts_only' => true, 'working_copy_default' => false ) );
		$before    = $this->snapshot();

		$live = $this->run_assign();

		$this->assertInstanceOf( \WP_Error::class, $live );
		$this->assertSame( 'nexo_mcp_draft_required', $live->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		$copy = $this->run_assign( array( 'draft' => true ) );
		$this->assertIsArray( $copy );
		$this->assertSame( self::ORIGINAL, $copy['draft_of'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function rejections(): array {
		return array(
			'nodo inexistente'              => array( array( 'node_id' => 'nope000' ), 'nexo_mcp_builder_node_missing' ),
			'sin nodo'                      => array( array( 'node_id' => '' ), 'nexo_mcp_builder_node_missing' ),
			'sin prop'                      => array( array( 'prop' => '' ), 'nexo_mcp_dynamic_value_invalid' ),
			'contenedor no es destino'      => array( array( 'node_id' => 'fx00001', 'prop' => 'tag' ), 'nexo_mcp_dynamic_target_unsupported' ),
			'widget v3 no es destino'       => array( array( 'node_id' => 'hd00009', 'prop' => 'title' ), 'nexo_mcp_dynamic_target_unsupported' ),
			'prop que no es destino'        => array( array( 'prop' => 'tag' ), 'nexo_mcp_dynamic_target_unsupported' ),
			'prop de otro widget'           => array( array( 'node_id' => 'ei00001', 'prop' => 'title' ), 'nexo_mcp_dynamic_target_unsupported' ),
			'tag no es objeto'              => array( array( 'tag' => 'post-title' ), 'nexo_mcp_dynamic_value_invalid' ),
			'tag sin nombre'                => array( array( 'tag' => array( 'settings' => array() ) ), 'nexo_mcp_dynamic_value_invalid' ),
			'tag con clave de más'          => array( array( 'tag' => array( 'name' => 'post-title', 'group' => 'post' ) ), 'nexo_mcp_dynamic_value_invalid' ),
			'tag fuera del catálogo'        => array( array( 'tag' => array( 'name' => 'shortcode' ) ), 'nexo_mcp_dynamic_tag_unknown' ),
			'tag del catálogo sin registrar' => array( array( 'tag' => array( 'name' => 'post-excerpt' ) ), 'nexo_mcp_dynamic_tag_unavailable' ),
			'categoría distinta'            => array( array( 'tag' => array( 'name' => 'site-url' ) ), 'nexo_mcp_dynamic_category_mismatch' ),
			'ajuste desconocido'            => array( array( 'tag' => array( 'name' => 'post-title', 'settings' => array( 'custom' => 'x' ) ) ), 'nexo_mcp_dynamic_setting_unknown' ),
			'ajuste inválido'               => array( array( 'tag' => array( 'name' => 'post-title', 'settings' => array( 'before' => 5 ) ) ), 'nexo_mcp_dynamic_setting_invalid' ),
			'ajustes no es objeto'          => array( array( 'tag' => array( 'name' => 'post-title', 'settings' => 'x' ) ), 'nexo_mcp_dynamic_value_invalid' ),
		);
	}

	/**
	 * @dataProvider rejections
	 * @param array<string, mixed> $input Parámetros que cambian.
	 */
	public function test_cada_error_se_devuelve_tal_cual_y_no_escribe_ni_crea_copia( array $input, string $code ): void {
		$before = $this->snapshot();

		$result = $this->run_assign( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), $this->live_copies() );
	}

	public function test_el_error_del_catalogo_llega_con_su_mensaje(): void {
		$result = $this->run_assign( array( 'tag' => array( 'name' => 'site-url' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			( new DynamicTagCatalog( fn(): array => $this->registered ) )->validate(
				'e-heading',
				'title',
				array( '$$type' => 'dynamic', 'value' => array( 'name' => 'site-url', 'group' => 'site', 'settings' => array() ) )
			)->get_error_message(),
			$result->get_error_message()
		);
	}

	public function test_sin_editor_v4_pide_el_editor_y_no_escribe(): void {
		$this->guard->v4 = false;
		$before          = $this->snapshot();

		$result = $this->run_assign();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v4_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_id_repetido_en_el_documento_es_ambiguo(): void {
		$tree                              = $this->page();
		$tree[0]['elements'][1]['id']      = 'eh00001';
		$this->put_page( 106, 'publish', $tree );
		$before = $this->snapshot();

		$result = $this->run_assign( array(), 106 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_node_ambiguous', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_una_pagina_que_no_es_de_elementor_se_rechaza(): void {
		\WP_Test_State::$posts[110] = new \WP_Post( array( 'ID' => 110, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Plana' ) );
		$before                     = $this->snapshot();

		$result = $this->run_assign( array(), 110 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_not_elementor', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_bloqueo_de_otro_usuario_frena_y_no_escribe(): void {
		$before = $this->snapshot();

		$result = $this->run_assign( array(), self::ORIGINAL, static fn() => new \WP_Error( 'nexo_mcp_builder_locked', 'Otro usuario tiene este contenido abierto en el editor.' ) );

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

		$result = $this->run_assign();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $this->live_copies() );
		$this->assertSame( $before, get_post_meta( self::ORIGINAL, '_elementor_data', true ) );
		$this->assertArrayNotHasKey( 'nexo_mcp_lock_' . self::ORIGINAL, \WP_Test_State::$options );
	}
}
