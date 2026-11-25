<?php
/**
 * set-divi-template (T6): registro, permiso del Theme Builder, creación deshabilitada y enlazada al Theme Builder en vivo,
 * reemplazo de una plantilla propia o ajena, reglas de sitio entero y de cuerpo (S6), una sola plantilla por defecto,
 * metas escritas sin las trampas de `et_theme_builder_store_template`, respaldo y su restauración por el almacén, bloqueo y
 * borrado de CSS y cachés.
 *
 * Corre en un proceso aparte porque define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviTemplateStore;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviSetTemplateToolTest extends NexoTestCase {

	use DiviTemplateFixtures;

	private const SET = 'set-divi-template';

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_divi_templates();
	}

	/**
	 * @param array<string, mixed> $input    Entrada.
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return mixed
	 */
	private function set( array $input, array $settings = array() ) {
		return $this->tool( self::SET, $settings )['execute_callback']( $input );
	}

	/**
	 * Id de la única plantilla nueva que hay tras `$known` (las que ya estaban).
	 *
	 * @param array<int, int> $known Ids anteriores.
	 */
	private function created_id( array $known = array( 100 ) ): int {
		$ids = array_values(
			array_filter(
				array_keys( \WP_Test_State::$posts ),
				static fn( int $id ): bool => 'et_template' === \WP_Test_State::$posts[ $id ]->post_type && ! in_array( $id, $known, true )
			)
		);

		$this->assertCount( 1, $ids, 'una sola plantilla nueva' );

		return $ids[0];
	}

	private function assertError( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	public function test_it_is_registered_as_a_write_tool_with_a_closed_schema_and_warns_about_the_whole_site(): void {
		$tool = $this->tool( self::SET );

		$this->assertContains( 'nexo-mcp/' . self::SET, ExtendedAbilityRegistrar::ABILITIES );
		$this->assertFalse( $tool['meta']['annotations']['readonly'] );
		$this->assertFalse( $tool['input_schema']['additionalProperties'] );
		$this->assertSame( array( 'id', 'title', 'enabled', 'default', 'use_on', 'exclude_from', 'header', 'body', 'footer', 'confirm_site_wide', 'replace_foreign', 'allow_without_post_content' ), array_keys( $tool['input_schema']['properties'] ) );
		$this->assertSame( array( 'title', 'enabled' ), $tool['input_schema']['required'] );
		$this->assertSame( 100, $tool['input_schema']['properties']['title']['maxLength'] );
		$this->assertSame( 20, $tool['input_schema']['properties']['use_on']['maxItems'] );
		$this->assertSame( 20, $tool['input_schema']['properties']['exclude_from']['maxItems'] );

		foreach ( array( 'header', 'body', 'footer' ) as $slot ) {
			$this->assertSame( array( 'layout_id', 'enabled' ), array_keys( $tool['input_schema']['properties'][ $slot ]['properties'] ) );
			$this->assertFalse( $tool['input_schema']['properties'][ $slot ]['additionalProperties'] );
		}

		foreach ( array( 'mcp:divi_theme_builder', 'todo el sitio', 'UNA sola plantilla', 'enabled: false', 'segunda llamada', 'confirm_site_wide', 'replace_foreign', 'allow_without_post_content', 'divi/post-content', 'REEMPLAZA', 'publish', 'respaldo', 'previous', '_nexo_mcp_template_backup', 'edit_posts', 'nexo-mcp-list-divi-theme-builder', 'nexo-mcp-create-divi-layout', '7 días', 'nexo_mcp_divi_template_invalid', 'nexo_mcp_divi_template_site_wide', 'nexo_mcp_divi_template_body_replaces_content', 'nexo_mcp_divi_template_foreign', 'nexo_mcp_divi_template_not_found', 'nexo_mcp_divi_template_default_exists', 'nexo_mcp_divi_template_too_many', 'nexo_mcp_divi_template_denied', 'nexo_mcp_busy' ) as $needle ) {
			$this->assertStringContainsString( $needle, $tool['description'], $needle );
		}
	}

	public function test_the_permission_is_the_theme_builder_one_without_the_builders_module(): void {
		$this->assertTrue( $this->tool( self::SET, array( 'builders_tools_enabled' => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( self::SWITCH => false ) )['permission_callback']( array() ) );
		$this->assertNotTrue( $this->tool( self::SET, array( 'read_only' => true ) )['permission_callback']( array() ) );

		foreach ( array( 'edit_theme_options', 'edit_others_posts', 'nexo_mcp_divi_theme_builder' ) as $cap ) {
			$this->setUp();
			\WP_Test_State::$caps[ $cap ] = false;
			$this->assertNotTrue( $this->tool( self::SET )['permission_callback']( array() ), $cap );
		}
	}

	public function test_without_the_permission_nothing_is_written_and_the_input_is_not_even_looked_at(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Plantilla' );
		$before = $this->snapshot();

		foreach ( array( $this->new_template(), array( 'basura' => true ) ) as $input ) {
			$result = $this->set( $input, array( self::SWITCH => false ) );

			$this->assertError( $result, 'nexo_mcp_divi_template_denied' );
			$this->assertSame( $before, $this->snapshot() );
		}

		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_a_new_template_creates_the_live_builder_is_disabled_whatever_was_asked_and_is_linked_with_one_row(): void {
		$result = $this->set( $this->new_template( array( 'enabled' => true ) ) );

		$this->assertIsArray( $result );
		$id = $this->created_id();
		$tb = \WP_Test_State::$posts[ $result['live_theme_builder'] ];

		$this->assertSame( 'et_theme_builder', $tb->post_type );
		$this->assertSame( 'publish', $tb->post_status );
		$this->assertSame( array( true ), \WP_Template_Stub_State::$tb_calls, 'se crea con la función de Divi' );
		$this->assertSame( array( (string) $id ), \WP_Test_State::$multi_meta[ $tb->ID ]['_et_template'] );

		$post = \WP_Test_State::$posts[ $id ];
		$this->assertSame( 'et_template', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Inicio', $post->post_title );

		$this->assertEquals(
			array(
				'_et_default'               => '0',
				'_et_enabled'               => '0',
				'_et_autogenerated_title'   => '0',
				'_et_use_on'                => array( 'homepage' ),
				'_et_header_layout_id'      => '0',
				'_et_header_layout_enabled' => '1',
				'_et_body_layout_id'        => (string) self::BODY,
				'_et_body_layout_enabled'   => '1',
				'_et_footer_layout_id'      => '0',
				'_et_footer_layout_enabled' => '1',
			),
			$this->metas_of( $id )
		);

		$this->assertSame( $id, $result['id'] );
		$this->assertTrue( $result['created'] );
		$this->assertFalse( $result['enabled'] );
		$this->assertTrue( $result['needs_enable'] );
		$this->assertTrue( $result['ours'] );
		$this->assertFalse( $result['default'] );
		$this->assertFalse( $result['site_wide'] );
		$this->assertNull( $result['effect'] );
		$this->assertSame( array( 'homepage' ), $result['use_on'] );
		$this->assertSame( array(), $result['exclude_from'] );
		$this->assertSame( array( 'layout_id' => self::BODY, 'enabled' => true ), $result['body'] );
		$this->assertSame( array( 'layout_id' => null, 'enabled' => true ), $result['header'] );
		$this->assertStringContainsString( 'segunda llamada', implode( ' ', $result['warnings'] ) );
		$this->assertStringContainsString( 'id: ' . $id, implode( ' ', $result['warnings'] ) );
		$this->assertSame( 'create', $result['backup']['operation'] );
		$this->assertFalse( $result['backup']['existed'] );
		$this->assertSame( 'create', ( new DiviTemplateStore() )->backup( $id )['operation'] );
		$this->assertSame( array( 'css' => true, 'page_cache' => true ), $result['cache'] );
	}

	public function test_it_clears_the_css_and_the_page_caches_like_the_live_save_of_divi(): void {
		$this->put_live_tb();

		$this->assertIsArray( $this->set( $this->new_template() ) );

		$this->assertSame( array( array( 'all', 'all' ) ), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array( 'all' ), \WP_Layout_Stub_State::$cache_calls );
	}

	public function test_a_new_template_in_an_existing_builder_adds_one_row_and_keeps_the_others(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra', array( '_et_use_on' => array( 'search' ) ) );
		$other = $this->template_state( 950 );

		$result = $this->set( $this->new_template() );

		$this->assertIsArray( $result );
		$id = $this->created_id( array( 100, 950 ) );
		$this->assertSame( array( '950', (string) $id ), $this->mapping() );
		$this->assertSame( $other['metas'], $this->metas_of( 950 ) );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls, 'no se crea otro Theme Builder' );
		$this->assertSame( self::TB, $result['live_theme_builder'] );
	}

	public function test_enabling_in_a_second_call_replaces_the_same_template_and_backs_up_the_previous_state(): void {
		$this->put_live_tb();
		$created = $this->set( $this->new_template() );
		$id      = (int) $created['id'];
		$first   = $this->template_state( $id );

		$result = $this->set( $this->new_template( array( 'id' => $id, 'enabled' => true ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertFalse( $result['created'] );
		$this->assertTrue( $result['enabled'] );
		$this->assertArrayNotHasKey( 'needs_enable', $result );
		$this->assertSame( '1', $this->metas_of( $id )['_et_enabled'] );
		$this->assertSame( array( (string) $id ), $this->mapping() );
		$this->assertCount( 1, array_filter( \WP_Test_State::$posts, static fn( $post ): bool => 'et_template' === $post->post_type ) );

		$backup = ( new DiviTemplateStore() )->backup( $id );
		$this->assertSame( 'replace', $backup['operation'] );
		$this->assertTrue( $backup['existed'] );
		$this->assertSame( $first['metas'], $backup['metas'], 'el respaldo guarda lo que había antes' );
		$this->assertSame( 'replace', $result['backup']['operation'] );
		$this->assertTrue( $result['backup']['existed'] );
	}

	public function test_replacing_replaces_every_row_of_the_lists_and_the_slots_and_the_title(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'homepage', '404' ), '_et_exclude_from' => array( 'search' ), '_et_header_layout_id' => (string) self::HEADER ) );

		$result = $this->set(
			array(
				'id'           => 950,
				'title'        => 'Nueva',
				'enabled'      => true,
				'use_on'       => array( 'archive:date:all' ),
				'exclude_from' => array( 'homepage' ),
				'header'       => array( 'layout_id' => null, 'enabled' => false ),
				'footer'       => array( 'layout_id' => self::FOOTER ),
				'confirm_site_wide' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Nueva', \WP_Test_State::$posts[950]->post_title );
		$this->assertEquals(
			array(
				'_et_default'               => '0',
				'_et_enabled'               => '1',
				'_et_autogenerated_title'   => '0',
				'_et_use_on'                => array( 'archive:date:all' ),
				'_et_exclude_from'          => array( 'homepage' ),
				'_et_header_layout_id'      => '0',
				'_et_header_layout_enabled' => '0',
				'_et_body_layout_id'        => '0',
				'_et_body_layout_enabled'   => '1',
				'_et_footer_layout_id'      => (string) self::FOOTER,
				'_et_footer_layout_enabled' => '1',
			),
			$this->metas_of( 950 )
		);
		$this->assertSame( array( '950' ), $this->mapping() );
	}

	public function test_the_metas_are_the_exact_strings_divi_compares_and_never_booleans(): void {
		$this->put_live_tb();

		$this->assertIsArray( $this->set( $this->new_template( array( 'default' => true, 'confirm_site_wide' => true, 'enabled' => false ) ) ) );

		foreach ( $this->metas_of( $this->created_id() ) as $key => $value ) {
			foreach ( (array) $value as $row ) {
				$this->assertIsString( $row, $key );
			}
		}

		$this->assertSame( '1', $this->metas_of( $this->created_id() )['_et_default'], 'sólo con confirm_site_wide se guarda por defecto' );
		$this->assertSame( '0', $this->metas_of( $this->created_id() )['_et_enabled'] );
	}

	public function test_a_template_that_is_not_the_default_never_becomes_it_for_lack_of_a_key(): void {
		$this->put_live_tb();

		$this->assertIsArray( $this->set( $this->new_template() ) );

		$this->assertSame( '0', $this->metas_of( $this->created_id() )['_et_default'] );
	}

	public function test_site_wide_templates_need_confirm_site_wide_and_write_nothing_without_it(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$before = $this->snapshot();

		foreach ( array( array( 'default' => true ), array( 'use_on' => array( 'archive:all' ) ), array( 'use_on' => array( '404' ) ), array( 'use_on' => array( 'singular:post_type:page:all' ) ) ) as $extra ) {
			$result = $this->set( $this->new_template( $extra ) );

			$error = $this->assertError( $result, 'nexo_mcp_divi_template_site_wide' );
			$this->assertSame( array( 'path' => 'confirm_site_wide' ), $error->get_error_data() );
			$this->assertSame( $before, $this->snapshot() );
		}

		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	public function test_a_confirmed_site_wide_template_says_what_it_does_and_which_templates_cover_it(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Inicio propio', array( '_et_use_on' => array( 'singular:post_type:page:id:100' ) ) );

		$result = $this->set( $this->new_template( array( 'title' => 'Páginas', 'use_on' => array( 'singular:post_type:page:all' ), 'confirm_site_wide' => true ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['site_wide'] );
		$this->assertStringContainsString( 'todas las páginas y entradas', $result['effect'] );
		$this->assertStringContainsString( 'deshabilitada', $result['effect'] );

		// Se habilita y entonces la plantilla más específica la tapa.
		$enabled = $this->set( $this->new_template( array( 'id' => $result['id'], 'title' => 'Páginas', 'use_on' => array( 'singular:post_type:page:all' ), 'confirm_site_wide' => true ) ) );

		$this->assertIsArray( $enabled );
		$this->assertStringNotContainsString( 'deshabilitada', (string) $enabled['effect'] );
		$this->assertSame( array( 950 ), array_column( $enabled['shadowed_by'], 'covered_by' ) );
		$this->assertSame( 'singular:post_type:page:id:100', $enabled['shadowed_by'][0]['by_context'] );
		$this->assertSame( array(), $enabled['shadows'] );
	}

	public function test_only_one_default_template_ever(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Sitio', array( '_et_default' => '1' ) );
		$before = $this->snapshot();

		$result = $this->set( $this->new_template( array( 'default' => true, 'confirm_site_wide' => true ) ) );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_default_exists' );
		$this->assertSame( array( 'id' => 950 ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertIsArray( $this->set( $this->new_template( array( 'id' => 950, 'title' => 'Sitio', 'default' => true, 'confirm_site_wide' => true, 'use_on' => array() ) ) ), 'la misma plantilla sí puede seguir siendo la por defecto' );
	}

	public function test_a_trashed_default_does_not_count_and_taking_the_default_away_needs_confirmation(): void {
		$this->put_live_tb();
		$this->put_template( 951, 'Vieja', array( '_et_default' => '1' ), true, 'trash' );

		$this->assertIsArray( $this->set( $this->new_template( array( 'default' => true, 'confirm_site_wide' => true ) ) ) );

		$this->put_template( 952, 'Sitio', array( '_et_default' => '1' ) );
		$before = $this->snapshot();

		$result = $this->set( array( 'id' => 952, 'title' => 'Sitio', 'enabled' => true ) );

		$this->assertError( $result, 'nexo_mcp_divi_template_site_wide' );
		$this->assertSame( $before, $this->snapshot(), 'quitarle lo de sitio entero también cambia todo el sitio' );
	}

	public function test_a_body_without_post_content_is_rejected_unless_allowed(): void {
		$this->put_live_tb();
		$before = $this->snapshot();

		$result = $this->set( $this->new_template( array( 'body' => array( 'layout_id' => self::BODY_BARE ) ) ) );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_body_replaces_content' );
		$this->assertSame( array( 'path' => 'body.layout_id' ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		$ok = $this->set( $this->new_template( array( 'body' => array( 'layout_id' => self::BODY_BARE ), 'allow_without_post_content' => true ) ) );

		$this->assertIsArray( $ok );
		$this->assertSame( (string) self::BODY_BARE, $this->metas_of( $ok['id'] )['_et_body_layout_id'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'use_on no es una lista'     => array( array( 'use_on' => 'homepage' ), 'use_on' ),
			'use_on es un objeto'        => array( array( 'use_on' => array( 'a' => 'homepage' ) ), 'use_on' ),
			'contexto desconocido'       => array( array( 'use_on' => array( 'bogus:thing' ) ), 'use_on.0' ),
			'contexto de rol de autor'   => array( array( 'use_on' => array( 'archive:user:role:editor' ) ), 'use_on.0' ),
			'contexto con id inexistente' => array( array( 'use_on' => array( 'singular:post_type:page:id:99999' ) ), 'use_on.0' ),
			'21 contextos'               => array( array( 'use_on' => array_map( static fn( int $n ): string => 'singular:post_type:page:id:' . $n, range( 1, 21 ) ) ), 'use_on' ),
			'exclude_from repetido'      => array( array( 'use_on' => array( 'homepage' ), 'exclude_from' => array( 'homepage' ) ), 'exclude_from.0' ),
			'título vacío'               => array( array( 'title' => '' ), 'title' ),
			'título de 101 caracteres'   => array( array( 'title' => str_repeat( 'a', 101 ) ), 'title' ),
			'clave desconocida'          => array( array( 'colores' => true ), 'colores' ),
			'enabled no es booleano'     => array( array( 'enabled' => '1' ), 'enabled' ),
			'default no es booleano'     => array( array( 'default' => 'yes' ), 'default' ),
			'cuerpo de otro tipo'        => array( array( 'header' => array( 'layout_id' => self::BODY ) ), 'header.layout_id' ),
			'layout en borrador'         => array( array( 'header' => array( 'layout_id' => self::HEADER_DRAFT ) ), 'header.layout_id' ),
			'layout inexistente'         => array( array( 'footer' => array( 'layout_id' => 4040 ) ), 'footer.layout_id' ),
			'layout_id como texto'       => array( array( 'footer' => array( 'layout_id' => '913' ) ), 'footer.layout_id' ),
			'ranura con otra clave'      => array( array( 'body' => array( 'layout_id' => self::BODY, 'global' => true ) ), 'body.global' ),
			'id como texto'              => array( array( 'id' => '950' ), 'id' ),
			'id cero'                    => array( array( 'id' => 0 ), 'id' ),
			'replace_foreign no booleano' => array( array( 'replace_foreign' => 'true' ), 'replace_foreign' ),
			'sin enabled'                => array( array( 'enabled' => null ), 'enabled' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 *
	 * @param array<string, mixed> $extra Parámetros que cambian.
	 */
	public function test_invalid_input_writes_nothing_and_names_the_path( array $extra, string $path ): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$before = $this->snapshot();
		$input  = array_merge( $this->new_template(), $extra );

		if ( array_key_exists( 'enabled', $extra ) && null === $extra['enabled'] ) {
			unset( $input['enabled'] );
		}

		$result = $this->set( $input );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_invalid' );
		$this->assertSame( array( 'path' => $path ), $error->get_error_data() );
		$this->assertStringContainsString( $path, $error->get_error_message() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function missing_targets(): array {
		return array(
			'no existe'                     => array( 4040 ),
			'es una página'                 => array( 100 ),
			'es un layout'                  => array( self::BODY ),
			'es un Theme Builder'           => array( self::TB ),
			'no está en el Theme Builder en vivo' => array( 960 ),
			'está en la papelera'           => array( 961 ),
		);
	}

	/**
	 * @dataProvider missing_targets
	 */
	public function test_an_id_that_is_not_a_live_template_is_not_found_and_never_creates_another( int $id ): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$this->put_template( 960, 'Suelta', array(), false );
		$this->put_template( 961, 'Papelera', array(), true, 'trash' );
		$before = $this->snapshot();

		$result = $this->set( $this->new_template( array( 'id' => $id ) ) );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_not_found' );
		$this->assertSame( array( 'id' => $id ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_foreign_template_is_summarised_and_not_replaced_without_replace_foreign(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Del Visual Builder', array( '_et_use_on' => array( 'archive:user:role:editor' ), '_et_library_item_id' => '9' ) );
		$before = $this->snapshot();

		$result = $this->set( $this->new_template( array( 'id' => 950 ) ) );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_foreign' );
		$data  = $error->get_error_data();
		$this->assertSame( 950, $data['id'] );
		$this->assertFalse( $data['summary']['ours'] );
		$this->assertSame( 'Del Visual Builder', $data['summary']['title'] );
		$this->assertSame( array( 'id' => 'archive:user:role:editor', 'supported' => false, 'priority' => null ), $data['summary']['use_on'][0] );
		$this->assertStringContainsString( 'replace_foreign', $error->get_error_message() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_replacing_a_foreign_template_with_confirmation_keeps_a_backup_and_drops_what_the_schema_does_not_know(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Del Visual Builder', array( '_et_use_on' => array( 'archive:user:role:editor' ), '_et_library_item_id' => '9' ) );
		$old = $this->metas_of( 950 );

		$result = $this->set( $this->new_template( array( 'id' => 950, 'replace_foreign' => true ) ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['replaced']['ours'] );
		$this->assertSame( 'archive:user:role:editor', $result['replaced']['use_on'][0]['id'] );
		$this->assertTrue( $result['ours'] );
		$this->assertArrayNotHasKey( '_et_library_item_id', $this->metas_of( 950 ) );
		$this->assertSame( array( 'homepage' ), $this->metas_of( 950 )['_et_use_on'] );
		$this->assertSame( $old, ( new DiviTemplateStore() )->backup( 950 )['metas'], 'lo ajeno queda en el respaldo' );
	}

	public function test_an_own_template_is_replaced_without_replace_foreign_and_a_template_of_the_visual_builder_the_connector_can_represent_counts_as_own(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Hecha en el VB', array( '_et_use_on' => array( 'homepage' ), '_et_onboarding_created' => '1' ) );

		$result = $this->set( $this->new_template( array( 'id' => 950 ) ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'replaced', $result );
		$this->assertSame( '1', $this->metas_of( 950 )['_et_onboarding_created'], 'lo que el conector no toca se conserva' );
	}

	public function test_at_most_50_templates_in_the_live_builder(): void {
		$this->put_live_tb();

		foreach ( range( 1000, 1049 ) as $id ) {
			$this->put_template( $id, 'T' . $id );
		}

		$before = $this->snapshot();

		$result = $this->set( $this->new_template() );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_too_many' );
		$this->assertSame( array( 'max' => 50 ), $error->get_error_data() );
		$this->assertSame( $before, $this->snapshot() );

		$this->assertIsArray( $this->set( $this->new_template( array( 'id' => 1000 ) ) ), 'editar una existente sí' );
	}

	public function test_a_busy_lock_writes_nothing_and_says_so(): void {
		$this->put_live_tb();
		$before = $this->snapshot();

		\WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] = 'otro|' . ( time() + 100 );
		PostLock::$wait                                                         = 0.0;
		$result = $this->set( $this->new_template() );

		$this->assertError( $result, 'nexo_mcp_busy' );

		unset( \WP_Test_State::$options[ 'nexo_mcp_lock_' . DiviTemplateStore::LOCK_ID ] );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_lock_lost_before_writing_writes_nothing(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$before = $this->template_state( 950 );

		$this->lose_lock_after_acquiring( DiviTemplateStore::LOCK_ID );
		$result = $this->set( $this->new_template( array( 'id' => 950 ) ) );

		$this->assertError( $result, 'nexo_mcp_busy' );
		$this->assertSame( $before, $this->template_state( 950 ) );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_failed_insert_leaves_the_builder_as_it_was(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$before = $this->snapshot();

		\WP_Test_State::$fail_insert_post = true;
		$result                           = $this->set( $this->new_template() );

		$this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_failed_title_update_restores_the_previous_state(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$before = $this->template_state( 950 );

		\WP_Test_State::$fail_update_post = true;
		$result                           = $this->set( $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) );

		$this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		\WP_Test_State::$fail_update_post = false;
		$this->assertSame( $before, $this->template_state( 950 ), 'ni título ni metas ni mapeo cambian' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_when_what_was_saved_does_not_read_back_as_asked_the_template_goes_back_to_its_previous_state(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$before = $this->template_state( 950 );

		\WP_Test_State::$fail_add_meta['_et_use_on'] = true;
		$result                                      = $this->set( $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) );

		$error = $this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		$this->assertStringContainsString( 'volvió a su estado anterior', $error->get_error_message() );
		$this->assertSame( $before, $this->template_state( 950 ), 'ni título ni metas ni mapeo cambian' );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_a_creation_that_does_not_read_back_as_asked_leaves_nothing_behind(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Otra' );
		$posts = array_keys( \WP_Test_State::$posts );
		$map   = $this->mapping();

		\WP_Test_State::$fail_add_meta['_et_use_on'] = true;
		$result                                      = $this->set( $this->new_template() );

		$this->assertError( $result, 'nexo_mcp_divi_template_save_failed' );
		$this->assertSame( $posts, array_keys( \WP_Test_State::$posts ), 'la plantilla a medias se elimina' );
		$this->assertSame( $map, $this->mapping() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
	}

	public function test_the_answer_carries_the_previous_state_so_the_change_can_be_undone_by_calling_again(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ), '_et_footer_layout_id' => (string) self::FOOTER ) );
		$before = $this->template_state( 950 );

		$result = $this->set( $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['previous']['ours'] );
		$this->assertSame( 'Vieja', $result['previous']['title'] );
		$this->assertSame( array( array( 'id' => 'search', 'supported' => true, 'priority' => 1 ) ), $result['previous']['use_on'] );
		$this->assertSame( array( 'layout_id' => self::FOOTER, 'enabled' => true ), $result['previous']['footer'] );

		$undone = $this->set( array( 'id' => 950, 'title' => 'Vieja', 'enabled' => true, 'use_on' => array( 'search' ), 'footer' => array( 'layout_id' => self::FOOTER ) ) );

		$this->assertIsArray( $undone );
		$this->assertEquals( $before['metas'], $this->metas_of( 950 ) );
		$this->assertSame( 'Vieja', \WP_Test_State::$posts[950]->post_title );
		$this->assertNull( $this->set( $this->new_template() )['previous'], 'al crear no había nada' );
	}

	public function test_the_stored_backup_restores_the_template_through_the_store_and_restore_builder_backup_does_not_take_templates(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'Vieja', array( '_et_use_on' => array( 'search' ) ) );
		$before = $this->template_state( 950 );

		$this->assertIsArray( $this->set( $this->new_template( array( 'id' => 950, 'title' => 'Nueva' ) ) ) );
		$this->assertNotSame( $before, $this->template_state( 950 ) );
		$this->assertTrue( ( new DiviTemplateStore() )->restore( 950, self::TB ) );
		$this->assertEquals( $before, $this->template_state( 950 ) );

		$tool = $this->tool( 'restore-builder-backup' );
		$this->assertError( $tool['execute_callback']( array( 'id' => 950 ) ), 'nexo_mcp_divi_post_type_denied' );
		$this->assertEquals( $before, $this->template_state( 950 ) );
	}

	public function test_restore_builder_backup_keeps_denying_other_internal_types_and_layouts_without_the_permission(): void {
		$this->put_live_tb();
		$this->put_template( 950, 'T' );

		$off = $this->tool( 'restore-builder-backup', array( self::SWITCH => false ) );

		$this->assertNotTrue( $off['permission_callback']( array( 'id' => self::BODY ) ), 'un layout sigue pidiendo el permiso del Theme Builder' );
		$this->assertError( $this->tool( 'restore-builder-backup' )['execute_callback']( array( 'id' => self::TB ) ), 'nexo_mcp_divi_post_type_denied' );
		$this->assertTrue( $this->tool( 'restore-builder-backup', array( self::SWITCH => false ) )['permission_callback']( array( 'id' => 100 ) ), 'una página sigue con su permiso de siempre' );
	}
}
