<?php
/**
 * Aplicar presets a módulos (`preset`): validación, edit-divi-module, create-divi-page, edit-divi-section y lectura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5PageCreator;
use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class DiviPresetApplyTest extends NexoTestCase {

	private const ORIGINAL = 100;
	private const TEXT     = '0.0.0.0.0';
	private const BG       = 'module.decoration.background';

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg                  = $this->settings( array( 'read_only' => false ) );
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5ModuleEditorTest::page() ) );
	}

	protected function tearDown(): void {
		\NexoDev\NexoMCP\Support\PostLock::$wait = 5.0;
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function catalog(): array {
		return array(
			'module' => array(
				'divi/text' => array(
					'default' => '',
					'items'   => array(
						'p-text-1' => array( 'id' => 'p-text-1', 'name' => 'Uno', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ),
						'p-text-2' => array( 'id' => 'p-text-2', 'name' => 'Dos', 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 2 ) ),
					),
				),
			),
			'group'  => array(
				'divi/background' => array(
					'default' => '',
					'items'   => array(
						'g-bg-1'    => array( 'id' => 'g-bg-1', 'name' => 'Fondo', 'groupName' => 'divi/background', 'groupId' => self::BG, 'moduleName' => 'divi/text', 'attrs' => array( 'x' => 1 ) ),
						'g-bg-btn1' => array( 'id' => 'g-bg-btn1', 'name' => 'Botón', 'groupName' => 'divi/background', 'groupId' => 'button.decoration.background', 'moduleName' => 'divi/button', 'attrs' => array( 'x' => 1 ) ),
					),
				),
			),
		);
	}

	private function validator( ?array $catalog = null ): DiviTreeValidator {
		$catalog ??= $this->catalog();

		return new DiviTreeValidator(
			static fn( string $name ): bool => 'gallery' === $name,
			static fn( int $id ) => false,
			static fn(): array => $catalog,
			static fn( string $module ): array => 'divi/text' === $module
				? array( self::BG => 'divi/background', 'content.decoration.bodyFont' => 'divi/font-body' )
				: ( 'divi/button' === $module ? array( 'button.decoration.background' => 'divi/background' ) : array() )
		);
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function node( string $content, string $path ): array {
		$node = Divi5Tree::locate( Divi5Tree::build( $content ), $path );
		$this->assertIsArray( $node );

		return $node;
	}

	/**
	 * Atributos del bloque en la ruta.
	 *
	 * @return array<string, mixed>
	 */
	private function attrs( string $content, string $path ): array {
		$block = Divi5Tree::locate_block( parse_blocks( $content ), $path );
		$this->assertIsArray( $block );

		return $block['attrs'];
	}

	/**
	 * @param array<string, mixed> $input Parámetros.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_module( array $input, int $id = self::ORIGINAL, ?DiviTreeValidator $validator = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$path   = isset( $input['path'] ) ? (string) $input['path'] : self::TEXT;
		$editor = new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$validator ?? $this->validator(),
			static fn() => true,
			static fn(): bool => true
		);

		return $editor->edit(
			$post,
			array_merge(
				array(
					'path'        => $path,
					'fingerprint' => $this->node( $post->post_content, $path )['fingerprint'],
				),
				$input
			)
		);
	}

	// --- Validación de la referencia ---------------------------------------------------------

	public function test_valida_un_preset_de_modulo_y_de_grupo_y_arma_la_referencia_con_el_nombre_de_grupo_del_servidor(): void {
		$ref = $this->validator()->validate_preset_ref( 'divi/text', array( 'module' => 'p-text-1', 'groups' => array( self::BG => 'g-bg-1' ) ) );

		$this->assertSame(
			array(
				'module' => array( 'p-text-1' ),
				'groups' => array( self::BG => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ),
			),
			$ref
		);
	}

	public function test_null_quita_la_referencia_de_modulo_y_de_grupo(): void {
		$ref = $this->validator()->validate_preset_ref( 'divi/text', array( 'module' => null, 'groups' => array( self::BG => null ) ) );

		$this->assertSame( array( 'module' => null, 'groups' => array( self::BG => null ) ), $ref );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
	 */
	public static function bad_refs(): array {
		return array(
			'id de módulo inexistente'     => array( 'divi/text', array( 'module' => 'nope' ), 'nexo_mcp_divi_preset_unknown', 'preset.module' ),
			'preset de otro módulo'        => array( 'divi/button', array( 'module' => 'p-text-1' ), 'nexo_mcp_divi_preset_unknown', 'preset.module' ),
			'id de grupo inexistente'      => array( 'divi/text', array( 'groups' => array( self::BG => 'nope' ) ), 'nexo_mcp_divi_preset_unknown', 'preset.groups.' . self::BG ),
			'grupo que el módulo no tiene' => array( 'divi/text', array( 'groups' => array( 'nope.group' => 'g-bg-1' ) ), 'nexo_mcp_divi_preset_unknown', 'preset.groups.nope.group' ),
			'preset de otro groupId'       => array( 'divi/text', array( 'groups' => array( self::BG => 'g-bg-btn1' ) ), 'nexo_mcp_divi_preset_unknown', 'preset.groups.' . self::BG ),
			'módulo fuera del catálogo'    => array( 'divi/gallery', array( 'module' => 'p-text-1' ), 'nexo_mcp_divi_module_denied', 'type' ),
			'preset vacío'                 => array( 'divi/text', array(), 'nexo_mcp_divi_tree_invalid', 'preset' ),
			'clave desconocida'            => array( 'divi/text', array( 'module' => 'p-text-1', 'other' => 1 ), 'nexo_mcp_divi_attr_denied', 'preset.other' ),
			'module no es texto'           => array( 'divi/text', array( 'module' => array( 'p-text-1' ) ), 'nexo_mcp_divi_tree_invalid', 'preset.module' ),
			'module vacío'                 => array( 'divi/text', array( 'module' => '' ), 'nexo_mcp_divi_tree_invalid', 'preset.module' ),
			'groups es lista'              => array( 'divi/text', array( 'groups' => array( 'g-bg-1' ) ), 'nexo_mcp_divi_tree_invalid', 'preset.groups' ),
			'group id no es texto'         => array( 'divi/text', array( 'groups' => array( self::BG => 5 ) ), 'nexo_mcp_divi_tree_invalid', 'preset.groups.' . self::BG ),
		);
	}

	/**
	 * @dataProvider bad_refs
	 * @param array<string, mixed> $preset Referencia.
	 */
	public function test_una_referencia_invalida_da_su_codigo_y_data_path( string $type, array $preset, string $code, string $path ): void {
		$result = $this->validator()->validate_preset_ref( $type, $preset );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
	}

	public function test_un_preset_que_no_es_objeto_se_rechaza(): void {
		$result = $this->validator()->validate_preset_ref( 'divi/text', 'p-text-1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_tree_invalid', $result->get_error_code() );
	}

	// --- Árbol (create-divi-page / edit-divi-section) ---------------------------------------

	/**
	 * @param array<string, mixed> $extra Claves del nodo de texto.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( array $extra = array() ): array {
		$sections = Divi5SectionEditorTestFixtures::sections( 'Hola' );
		$sections[0]['children'][0]['children'][0]['children'][0] = array_merge( $sections[0]['children'][0]['children'][0]['children'][0], $extra );

		return $sections;
	}

	public function test_un_nodo_con_preset_escribe_modulepreset_y_grouppreset_en_sus_attrs(): void {
		$tree = $this->validator()->validate_tree( $this->sections( array( 'preset' => array( 'module' => 'p-text-1', 'groups' => array( self::BG => 'g-bg-1' ) ) ) ), 'divi/placeholder' );

		$this->assertIsArray( $tree );
		$attrs = $tree[0]['children'][0]['children'][0]['children'][0]['attrs'];
		$this->assertSame( array( 'p-text-1' ), $attrs['modulePreset'] );
		$this->assertSame( array( self::BG => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ), $attrs['groupPreset'] );
		$this->assertArrayHasKey( 'content', $attrs, 'los attrs propios siguen' );
		$this->assertArrayNotHasKey( 'preset', $tree[0]['children'][0]['children'][0]['children'][0] );
	}

	public function test_un_nodo_sin_attrs_con_preset_obtiene_attrs_y_con_null_no_obtiene_nada(): void {
		$node = array( array( 'type' => 'divi/section', 'preset' => array( 'module' => null ) ) );
		$tree = $this->validator()->validate_tree( $node, 'divi/placeholder' );

		$this->assertSame( array( array( 'type' => 'divi/section' ) ), $tree );
	}

	public function test_un_nodo_con_preset_inexistente_se_rechaza_con_su_ruta(): void {
		$result = $this->validator()->validate_tree( $this->sections( array( 'preset' => array( 'module' => 'nope' ) ) ), 'divi/placeholder' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'nodes[0].children[0].children[0].children[0].preset.module' ), $result->get_error_data() );
	}

	public function test_modulepreset_y_grouppreset_crudos_en_attrs_siguen_denegados(): void {
		foreach ( array( 'modulePreset' => array( 'p-text-1' ), 'groupPreset' => array( self::BG => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ) ) as $key => $value ) {
			$result = $this->validator()->validate_tree( $this->sections( array( 'attrs' => array( $key => $value ) ) ), 'divi/placeholder' );

			$this->assertInstanceOf( \WP_Error::class, $result, $key );
			$this->assertSame( 'nexo_mcp_divi_attr_denied', $result->get_error_code(), $key );
		}
	}

	public function test_un_nodo_sin_preset_queda_igual_que_antes(): void {
		$tree = $this->validator()->validate_tree( $this->sections(), 'divi/placeholder' );

		$this->assertIsArray( $tree );
		$this->assertSame( array( 'content' ), array_keys( $tree[0]['children'][0]['children'][0]['children'][0]['attrs'] ) );
	}

	public function test_create_divi_page_guarda_el_preset_y_la_lectura_lo_expone(): void {
		$creator = new Divi5PageCreator( $this->validator(), static fn(): bool => true, static fn(): string => '5.19.1' );
		$result  = $creator->create( array( 'title' => 'Nueva', 'sections' => $this->sections( array( 'preset' => array( 'module' => 'p-text-2', 'groups' => array( self::BG => 'g-bg-1' ) ) ) ) ) );

		$this->assertIsArray( $result );
		$content = $this->content( (int) $result['id'] );
		$this->assertStringContainsString( '"modulePreset":["p-text-2"]', $content );
		$this->assertSame( array( self::BG => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ), $this->attrs( $content, self::TEXT )['groupPreset'] );
		$this->assertSame(
			array( 'module' => array( 'p-text-2' ), 'groups' => array( self::BG => array( 'g-bg-1' ) ) ),
			$this->node( $content, '0.0.0.0.0' )['preset']
		);
		$this->assertArrayNotHasKey( 'preset', $this->node( $content, '0.0' ) );
	}

	public function test_create_divi_page_con_preset_inexistente_no_crea_nada(): void {
		$before  = $this->snapshot();
		$creator = new Divi5PageCreator( $this->validator(), static fn(): bool => true, static fn(): string => '5.19.1' );
		$result  = $creator->create( array( 'title' => 'Nueva', 'sections' => $this->sections( array( 'preset' => array( 'groups' => array( self::BG => 'nope' ) ) ) ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_edit_divi_section_inserta_un_nodo_con_preset_y_rechaza_uno_inexistente_sin_escribir(): void {
		\WP_Test_State::$posts[100] = new \WP_Post( array( 'ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_content' => Divi5SectionEditorTest::page() ) );
		$editor                     = new Divi5SectionEditor( $this->cfg, new Divi5Adapter(), new BuilderBackup(), new WorkingCopy( $this->cfg ), $this->validator(), static fn() => true, static fn(): bool => true, null, static fn(): string => '5.19.1' );
		$post                       = get_post( 100 );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$input = array(
			'operation'   => 'insert',
			'position'    => 'after',
			'path'        => '0.0',
			'fingerprint' => $this->node( $post->post_content, '0.0' )['fingerprint'],
		);

		$before = $this->snapshot();
		$bad    = $editor->edit( $post, $input + array( 'sections' => $this->sections( array( 'preset' => array( 'module' => 'nope' ) ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $bad->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		$ok = $editor->edit( $post, $input + array( 'sections' => $this->sections( array( 'preset' => array( 'module' => 'p-text-1' ) ) ) ) );
		$this->assertIsArray( $ok );
		$this->assertSame( array( 'p-text-1' ), $this->attrs( $this->content( (int) $ok['id'] ), '0.1.0.0.0' )['modulePreset'] );
	}

	// --- edit-divi-module --------------------------------------------------------------------

	public function test_edit_divi_module_aplica_un_preset_de_modulo_y_de_grupo_sin_tocar_lo_demas(): void {
		$before = Divi5ModuleEditorTest::page();
		$result = $this->edit_module( array( 'preset' => array( 'module' => 'p-text-1', 'groups' => array( self::BG => 'g-bg-1' ) ) ) );

		$this->assertIsArray( $result );
		$copy = (int) $result['id'];
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $before, $this->content( self::ORIGINAL ), 'el original no cambia' );

		$attrs = $this->attrs( $this->content( $copy ), self::TEXT );
		$this->assertSame( array( 'p-text-1' ), $attrs['modulePreset'] );
		$this->assertSame( array( self::BG => array( 'presetId' => array( 'g-bg-1' ), 'groupName' => 'divi/background' ) ), $attrs['groupPreset'] );
		$original_attrs = $this->attrs( $before, self::TEXT );
		unset( $attrs['modulePreset'], $attrs['groupPreset'] );
		$this->assertSame( $original_attrs, $attrs, 'contenido, tablet, hover y diseño intactos' );
		$this->assertSame( $this->node( $this->content( $copy ), self::TEXT )['fingerprint'], $result['fingerprint'] );
		$this->assertSame( $this->node( $before, '0.0.0.0.1' ), $this->node( $this->content( $copy ), '0.0.0.0.1' ), 'otros módulos intactos' );
	}

	public function test_edit_divi_module_con_preset_y_contenido_a_la_vez(): void {
		$result = $this->edit_module(
			array(
				'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Nuevo</p>' ) ) ) ),
				'preset'  => array( 'module' => 'p-text-2' ),
			)
		);

		$this->assertIsArray( $result );
		$content = $this->content( (int) $result['id'] );
		$this->assertSame( 'Nuevo', $this->node( $content, self::TEXT )['text'] );
		$this->assertSame( array( 'p-text-2' ), $this->attrs( $content, self::TEXT )['modulePreset'] );
	}

	public function test_null_quita_modulepreset_y_el_grupo_pedido_y_deja_el_resto(): void {
		$raw     = str_replace(
			'<!-- wp:divi/text {"builderVersion":"5.19.1",',
			'<!-- wp:divi/text {"builderVersion":"5.19.1","modulePreset":["p-text-1"],"groupPreset":{"module.decoration.background":{"presetId":["g-bg-1"],"groupName":"divi/background"},"content.decoration.bodyFont":{"presetId":["f"],"groupName":"divi/font-body"}},',
			Divi5ModuleEditorTest::page()
		);
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'B', 'post_content' => $raw ) );

		$first = $this->edit_module( array( 'preset' => array( 'module' => null, 'groups' => array( self::BG => null ) ) ), 101 );
		$this->assertIsArray( $first );
		$attrs = $this->attrs( $this->content( 101 ), self::TEXT );
		$this->assertArrayNotHasKey( 'modulePreset', $attrs );
		$this->assertSame( array( 'content.decoration.bodyFont' ), array_keys( $attrs['groupPreset'] ), 'sólo se quita el grupo pedido' );

		$second = $this->edit_module( array( 'preset' => array( 'groups' => array( 'content.decoration.bodyFont' => null ) ) ), 101 );
		$this->assertIsArray( $second );
		$this->assertArrayNotHasKey( 'groupPreset', $this->attrs( $this->content( 101 ), self::TEXT ), 'sin grupos no queda groupPreset vacío' );
	}

	public function test_un_preset_de_grupo_se_suma_al_groupPreset_existente(): void {
		$raw = str_replace(
			'<!-- wp:divi/text {"builderVersion":"5.19.1",',
			'<!-- wp:divi/text {"builderVersion":"5.19.1","groupPreset":{"content.decoration.bodyFont":{"presetId":["f"],"groupName":"divi/font-body"}},',
			Divi5ModuleEditorTest::page()
		);
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'B', 'post_content' => $raw ) );

		$this->assertIsArray( $this->edit_module( array( 'preset' => array( 'groups' => array( self::BG => 'g-bg-1' ) ) ), 101 ) );
		$this->assertSame( array( 'content.decoration.bodyFont', self::BG ), array_keys( $this->attrs( $this->content( 101 ), self::TEXT )['groupPreset'] ) );
	}

	public function test_un_preset_inexistente_se_rechaza_sin_escribir_ni_crear_copia(): void {
		$before = $this->snapshot();
		$result = $this->edit_module( array( 'preset' => array( 'module' => 'nope' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_preset_unknown', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'preset.module' ), $result->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_un_preset_en_un_modulo_que_no_es_de_contenido_se_rechaza(): void {
		$before = $this->snapshot();
		$result = $this->edit_module( array( 'path' => '0.0', 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_module_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_una_huella_vieja_con_preset_no_escribe(): void {
		$before = $this->snapshot();
		$result = $this->edit_module( array( 'fingerprint' => 'viejo', 'preset' => array( 'module' => 'p-text-1' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_node_changed', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_sin_preset_ni_contenido_sigue_siendo_un_parche_vacio_y_un_modulepreset_existente_sobrevive_a_una_edicion(): void {
		$before = $this->snapshot();
		$empty  = $this->edit_module( array() );
		$this->assertInstanceOf( \WP_Error::class, $empty );
		$this->assertSame( 'nexo_mcp_divi_tree_invalid', $empty->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );

		$raw = str_replace( '<!-- wp:divi/text {"builderVersion":"5.19.1",', '<!-- wp:divi/text {"builderVersion":"5.19.1","modulePreset":["p-text-1"],', Divi5ModuleEditorTest::page() );
		\WP_Test_State::$posts[101] = new \WP_Post( array( 'ID' => 101, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'B', 'post_content' => $raw ) );
		$result                     = $this->edit_module( array( 'content' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>X</p>' ) ) ) ) ), 101 );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'p-text-1' ), $this->attrs( $this->content( 101 ), self::TEXT )['modulePreset'] );
	}

	public function test_modulepreset_crudo_en_content_o_design_sigue_denegado(): void {
		$before = $this->snapshot();

		foreach ( array( 'content', 'design' ) as $slot ) {
			$result = $this->edit_module( array( $slot => array( 'modulePreset' => array( 'p-text-1' ) ) ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $slot );
			$this->assertSame( 'nexo_mcp_divi_attr_denied', $result->get_error_code(), $slot );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	// --- Lectura -------------------------------------------------------------------------------

	public function test_la_lectura_expone_las_referencias_de_cada_modulo_y_normaliza_la_cadena(): void {
		$raw = str_replace(
			'<!-- wp:divi/text {"builderVersion":"5.19.1",',
			'<!-- wp:divi/text {"builderVersion":"5.19.1","modulePreset":"p-text-1","groupPreset":{"module.decoration.background":{"presetId":["g1","g2"],"groupName":"divi/background"}},',
			Divi5ModuleEditorTest::page()
		);
		$post = new \WP_Post( array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $raw ) );

		$tree = ( new Divi5Adapter() )->read_tree( $post );
		$text = $this->node( $raw, self::TEXT );
		$this->assertSame( array( 'module' => array( 'p-text-1' ), 'groups' => array( self::BG => array( 'g1', 'g2' ) ) ), $text['preset'] );
		$this->assertArrayNotHasKey( 'preset', $this->node( $raw, '0.0.0.0.1' ) );
		$this->assertNotEmpty( $tree );

		$flat = array_values( array_filter( ( new Divi5Adapter() )->read_nodes( $post ), static fn( $n ): bool => self::TEXT === $n->id ) );
		$this->assertSame( $text['preset'], $flat[0]->attributes['preset'] );
	}

	public function test_la_huella_no_cambia_por_exponer_el_preset(): void {
		$raw  = Divi5ModuleEditorTest::page();
		$node = $this->node( $raw, self::TEXT );

		$this->assertSame( Divi5Tree::fingerprint( (array) Divi5Tree::locate_block( parse_blocks( $raw ), self::TEXT ) ), $node['fingerprint'] );
	}

	// --- Herramientas --------------------------------------------------------------------------

	public function test_edit_divi_module_declara_preset_en_su_esquema_y_su_descripcion(): void {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array( 'read_only' => false ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		) )->register_abilities();

		$tool   = \WP_Test_State::$abilities['nexo-mcp/edit-divi-module'];
		$schema = $tool['input_schema'];
		$this->assertArrayHasKey( 'preset', $schema['properties'] );
		$this->assertSame( 'object', $schema['properties']['preset']['type'] );
		$this->assertFalse( $schema['properties']['preset']['additionalProperties'] );
		$this->assertSame( array( 'module', 'groups' ), array_keys( $schema['properties']['preset']['properties'] ) );
		$this->assertContains( 'id', $schema['required'] );
		$this->assertNotContains( 'preset', $schema['required'] );
		$this->assertStringContainsString( 'nexo_mcp_divi_preset_unknown', $tool['description'] );

		foreach ( array( 'nexo-mcp/create-divi-page', 'nexo-mcp/edit-divi-section' ) as $name ) {
			$this->assertStringContainsString( 'preset', \WP_Test_State::$abilities[ $name ]['description'], $name );
		}
	}
}
