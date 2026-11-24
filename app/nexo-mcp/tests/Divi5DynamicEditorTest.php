<?php
/**
 * set-divi-dynamic (T5 de D5), parte del editor: asignar o quitar un token en un campo de un módulo, en la copia de
 * trabajo, con ruta, huella, bloqueo y parche validado por el catálogo dinámico.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\Divi5Adapter;
use NexoDev\NexoMCP\Builders\Divi5ModuleEditor;
use NexoDev\NexoMCP\Builders\Divi5Tree;
use NexoDev\NexoMCP\Builders\DiviDynamicCatalog;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5DynamicEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private const HEADING = '0.0.0.0.0';
	private const BUTTON  = '0.0.0.0.1';
	private const IMAGE   = '0.0.0.0.2';
	private const TEXT    = '0.0.0.0.3';

	private const LINK_TOKEN = '$variable({"type":"content","value":{"name":"post_link_url","settings":{}}})$';
	private const OLD_TOKEN  = '$variable({"type":"content","value":{"name":"site_title","settings":{"enable_html":"off"}}})$';

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg = $this->settings( array( 'read_only' => false ) );
		$this->put_page( self::ORIGINAL, 'publish' );
	}

	/**
	 * Página con un título con tablet, un botón con enlace dinámico ya puesto, una imagen y un texto con un token ya puesto.
	 */
	public static function page(): string {
		$block = static fn( string $name, array $attrs ): string => '<!-- wp:divi/' . $name . ' ' . wp_json_encode( $attrs ) . ' /-->' . "\n";
		$raw   = '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/row -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. $block( 'heading', array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Hola' ), 'tablet' => array( 'value' => 'Hola tablet' ) ) ) ) )
			. $block( 'button', array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Comprar', 'linkUrl' => self::LINK_TOKEN ) ) ) ) ) )
			. $block( 'image', array( 'image' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'src' => 'https://ex.test/a.jpg', 'alt' => 'Foto' ) ) ) ) ) )
			. $block( 'text', array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . self::OLD_TOKEN . '</p>' ) ) ) ) )
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	private function put_page( int $id, string $status ): void {
		\WP_Test_State::$posts[ $id ] = new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => 'Inicio',
				'post_content' => self::page(),
			)
		);
	}

	private function catalog(): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => true,
			static function (): array {
				$names = array( 'post_title', 'post_date', 'post_author', 'site_title', 'post_link_url', 'home_url', 'post_featured_image', 'site_logo', 'post_meta_key', 'loop_user_email' );
				$types = array( 'post_link_url' => 'url', 'home_url' => 'url', 'post_featured_image' => 'image', 'site_logo' => 'image' );

				return array_combine( $names, array_map( static fn( string $n ): array => array( 'type' => $types[ $n ] ?? 'text' ), $names ) );
			},
			static fn(): array => array( array( 'name' => 'nexo_prueba_texto', 'label' => 'T', 'type' => 'text', 'group' => 'G' ) ),
			static fn(): string => 'page',
			static fn(): array => array()
		);
	}

	private function validator( bool $dynamic ): DiviTreeValidator {
		$base = new DiviTreeValidator( static fn( string $name ): bool => 'gallery' === $name, static fn( int $id ) => 7 === $id ? 'https://ex.test/7.jpg' : false, static fn(): array => array(), static fn(): array => array() );

		return $dynamic ? $base->with_dynamic( $this->catalog(), self::ORIGINAL ) : $base;
	}

	private function editor( bool $dynamic = true, ?callable $lock = null ): Divi5ModuleEditor {
		return new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$this->validator( $dynamic ),
			$lock ?? static fn() => true,
			static fn(): bool => true
		);
	}

	private function content( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post->post_content;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function attrs( string $content, string $path ): array {
		$block = Divi5Tree::locate_block( parse_blocks( $content ), $path );
		$this->assertIsArray( $block );

		return $block['attrs'];
	}

	private function snapshot(): string {
		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta ) );
	}

	/**
	 * @param mixed $source Fuente: {name, settings?} o null.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function assign( string $path, string $field, $source, int $id = self::ORIGINAL, bool $dynamic = true, ?string $fingerprint = null, ?callable $lock = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path );

		return $this->editor( $dynamic, $lock )->edit(
			$post,
			array(
				'path'        => $path,
				'fingerprint' => $fingerprint ?? (string) ( $node['fingerprint'] ?? '' ),
				'dynamic'     => array( 'field' => $field, 'source' => $source ),
			)
		);
	}

	/**
	 * @param mixed $result Resultado.
	 */
	private function assertRejected( string $code, $result, string $before ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( $before, $this->snapshot(), 'no escribe nada ni crea copia' );

		return $result;
	}

	public function test_a_token_is_assigned_to_a_heading_in_the_working_copy_and_stored_canonical(): void {
		$result = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ) );

		$this->assertIsArray( $result );
		$copy = (int) $result['id'];
		$this->assertNotSame( self::ORIGINAL, $copy );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ), 'el original no cambia' );
		$this->assertSame( 'draft', get_post( $copy )->post_status ); // @phpstan-ignore-line

		$token = '$variable({"type":"content","value":{"name":"post_title","settings":{"enable_html":"off"}}})$';
		$attrs = $this->attrs( $this->content( $copy ), self::HEADING );
		$this->assertSame( $token, $attrs['title']['innerContent']['desktop']['value'] );
		$this->assertSame( array( 'value' => 'Hola tablet' ), $attrs['title']['innerContent']['tablet'], 'tablet intacto' );

		$this->assertSame( self::HEADING, $result['path'] );
		$this->assertSame( 'title.innerContent', $result['field'] );
		$this->assertSame( $token, $result['token'] );
		$this->assertSame( Divi5Tree::locate( Divi5Tree::build( $this->content( $copy ) ), self::HEADING )['fingerprint'], $result['fingerprint'] ); // @phpstan-ignore-line
		$this->assertTrue( ( new BuilderBackup() )->exists( $copy ) );
	}

	public function test_the_stored_token_is_the_catalogs_even_when_the_client_sends_settings_in_another_order(): void {
		$result = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_author', 'settings' => array( 'link' => 'off', 'name_format' => 'nickname', 'before' => 'Por ' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			'$variable({"type":"content","value":{"name":"post_author","settings":{"before":"Por ","name_format":"nickname","link":"off","enable_html":"off"}}})$',
			$result['token']
		);
	}

	public function test_a_button_text_keeps_its_sibling_keys_and_a_url_and_an_image_take_their_own_category(): void {
		$text = $this->assign( self::BUTTON, 'button.innerContent.text', array( 'name' => 'site_title' ) );
		$this->assertIsArray( $text );
		$value = $this->attrs( $this->content( (int) $text['id'] ), self::BUTTON )['button']['innerContent']['desktop']['value'];
		$this->assertSame( array( 'text', 'linkUrl' ), array_keys( $value ) );
		$this->assertSame( self::LINK_TOKEN, $value['linkUrl'], 'el token que ya tenía el enlace no se toca' );
		$this->assertStringContainsString( '"name":"site_title"', $value['text'] );

		$url = $this->assign( self::BUTTON, 'button.innerContent.linkUrl', array( 'name' => 'home_url' ), (int) $text['id'], true, $text['fingerprint'] );
		$this->assertIsArray( $url );
		$value = $this->attrs( $this->content( (int) $url['id'] ), self::BUTTON )['button']['innerContent']['desktop']['value'];
		$this->assertStringContainsString( '"name":"home_url"', $value['linkUrl'] );
		$this->assertStringContainsString( '"name":"site_title"', $value['text'] );

		$image = $this->assign( self::IMAGE, 'image.innerContent.src', array( 'name' => 'post_featured_image' ), (int) $url['id'] );
		$this->assertIsArray( $image );
		$value = $this->attrs( $this->content( (int) $image['id'] ), self::IMAGE )['image']['innerContent']['desktop']['value'];
		$this->assertSame( '$variable({"type":"content","value":{"name":"post_featured_image","settings":{}}})$', $value['src'] );
		$this->assertSame( 'Foto', $value['alt'] );
	}

	public function test_a_source_of_another_category_than_the_field_is_denied_and_writes_nothing(): void {
		$before = $this->snapshot();

		foreach (
			array(
				array( self::HEADING, 'title.innerContent', 'post_link_url' ),
				array( self::HEADING, 'title.innerContent', 'site_logo' ),
				array( self::BUTTON, 'button.innerContent.linkUrl', 'post_title' ),
				array( self::IMAGE, 'image.innerContent.src', 'post_link_url' ),
				array( self::IMAGE, 'image.innerContent.alt', 'post_featured_image' ),
			) as $case
		) {
			$error = $this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( $case[0], $case[1], array( 'name' => $case[2] ) ), $before );
			$this->assertStringContainsString( 'categoría', $error->get_error_message() );
		}
	}

	public function test_sources_outside_the_allowlist_are_denied_and_write_nothing(): void {
		$before = $this->snapshot();

		foreach ( array( 'post_meta_key', 'loop_user_email', 'gvid-aaaaaaaaaa', 'bogus', 'custom_meta__edit_last', 'post_author_url', '' ) as $name ) {
			$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => $name ) ), $before );
		}
	}

	public function test_bad_settings_and_a_malformed_source_are_invalid_and_write_nothing(): void {
		$before = $this->snapshot();

		foreach (
			array(
				array( 'name' => 'post_title', 'settings' => array( 'enable_html' => 'on' ) ),
				array( 'name' => 'post_title', 'settings' => array( 'before' => '[caption]x[/caption]' ) ),
				array( 'name' => 'post_author', 'settings' => array( 'name_format' => 'username' ) ),
				array( 'name' => 'post_title', 'settings' => 'x' ),
				array( 'name' => 'post_title', 'extra' => 1 ),
				array( 'name' => 5 ),
				array(),
				array( 'settings' => array() ),
				'post_title',
				array( 'post_title' ),
			) as $source
		) {
			$result = $this->assign( self::HEADING, 'title.innerContent', $source );
			$this->assertRejected( 'nexo_mcp_divi_dynamic_invalid', $result, $before );
		}
	}

	public function test_a_field_that_is_not_a_target_of_the_module_is_invalid_and_lists_the_valid_ones(): void {
		$before = $this->snapshot();

		foreach ( array( 'module.meta.adminLabel', 'button.innerContent.text', 'title.innerContent.desktop', '', 'title.innerContent.' ) as $field ) {
			$error = $this->assertRejected( 'nexo_mcp_divi_dynamic_invalid', $this->assign( self::HEADING, $field, array( 'name' => 'post_title' ) ), $before );
			$this->assertStringContainsString( 'title.innerContent', $error->get_error_message() );
		}

		$this->assertRejected( 'nexo_mcp_divi_dynamic_invalid', $this->assign( self::BUTTON, 'button.innerContent.linkTarget', array( 'name' => 'post_title' ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_module_denied', $this->assign( '0.0', 'title.innerContent', array( 'name' => 'post_title' ) ), $before );
	}

	public function test_a_stale_fingerprint_and_a_missing_node_write_nothing(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ), self::ORIGINAL, true, 'viejo' ), $before );
		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->assign( '0.0.0.0.9', 'title.innerContent', array( 'name' => 'post_title' ) ), $before );
	}

	public function test_without_the_dynamic_context_the_same_call_is_denied_exactly_like_edit_divi_module(): void {
		$before = $this->snapshot();
		$error  = $this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ), self::ORIGINAL, false ), $before );

		$this->assertStringContainsString( 'no está habilitado', $error->get_error_message() );
	}

	public function test_the_lock_is_checked_before_reading_and_a_locked_page_writes_nothing(): void {
		$before = $this->snapshot();
		$locked = static fn() => new \WP_Error( 'nexo_mcp_builder_locked', 'bloqueado' );

		$this->assertRejected( 'nexo_mcp_builder_locked', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ), self::ORIGINAL, true, null, $locked ), $before );
	}

	public function test_a_draft_is_edited_in_place_and_a_second_call_with_the_new_fingerprint_works(): void {
		$this->put_page( 101, 'draft' );

		$first = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ), 101 );
		$this->assertIsArray( $first );
		$this->assertSame( 101, $first['id'] );
		$this->assertArrayNotHasKey( 'draft_of', $first );

		$second = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'site_title' ), 101, true, $first['fingerprint'] );
		$this->assertIsArray( $second );
		$this->assertStringContainsString( '"name":"site_title"', $this->attrs( $this->content( 101 ), self::HEADING )['title']['innerContent']['desktop']['value'] );

		$before = $this->snapshot();
		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ), 101, true, $first['fingerprint'] ), $before );
	}

	public function test_existing_tokens_that_the_call_does_not_rewrite_are_preserved_byte_for_byte(): void {
		$result = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'post_title' ) );
		$this->assertIsArray( $result );
		$old = parse_blocks( self::page() );
		$new = parse_blocks( $this->content( (int) $result['id'] ) );

		foreach ( array( self::BUTTON, self::IMAGE, self::TEXT ) as $path ) {
			$this->assertSame( serialize_block( Divi5Tree::locate_block( $old, $path ) ?? array() ), serialize_block( Divi5Tree::locate_block( $new, $path ) ?? array() ), $path );
		}

		$this->assertSame( '<p>' . self::OLD_TOKEN . '</p>', $this->attrs( $this->content( (int) $result['id'] ), self::TEXT )['content']['innerContent']['desktop']['value'] );
	}

	public function test_a_null_source_clears_a_field_that_holds_a_token_to_an_empty_string_and_keeps_the_rest(): void {
		$result = $this->assign( self::BUTTON, 'button.innerContent.linkUrl', null, self::ORIGINAL, false );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertArrayNotHasKey( 'token', $result );
		$value = $this->attrs( $this->content( (int) $result['id'] ), self::BUTTON )['button']['innerContent']['desktop']['value'];
		$this->assertSame( array( 'text' => 'Comprar', 'linkUrl' => '' ), $value );
		$this->assertSame( self::page(), $this->content( self::ORIGINAL ) );

		$text = $this->assign( self::TEXT, 'content.innerContent', null, self::ORIGINAL, false );
		$this->assertIsArray( $text );
		$this->assertSame( '', $this->attrs( $this->content( (int) $text['id'] ), self::TEXT )['content']['innerContent']['desktop']['value'] );
	}

	public function test_removing_a_token_from_an_image_source_leaves_it_empty(): void {
		$set = $this->assign( self::IMAGE, 'image.innerContent.src', array( 'name' => 'site_logo' ) );
		$this->assertIsArray( $set );

		$result = $this->assign( self::IMAGE, 'image.innerContent.src', null, (int) $set['id'], false, $set['fingerprint'] );

		$this->assertIsArray( $result );
		$value = $this->attrs( $this->content( (int) $result['id'] ), self::IMAGE )['image']['innerContent']['desktop']['value'];
		$this->assertSame( array( 'src' => '', 'alt' => 'Foto' ), $value );
	}

	public function test_a_null_source_on_a_field_without_a_token_is_an_error_and_never_wipes_plain_content(): void {
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_dynamic_not_set', $this->assign( self::HEADING, 'title.innerContent', null, self::ORIGINAL, false ), $before );
		$this->assertRejected( 'nexo_mcp_divi_dynamic_not_set', $this->assign( self::BUTTON, 'button.innerContent.text', null, self::ORIGINAL, false ), $before );
	}

	public function test_edit_divi_module_style_calls_without_dynamic_input_are_unchanged(): void {
		$post = get_post( self::ORIGINAL );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), self::HEADING );

		$result = $this->editor( false )->edit(
			$post,
			array(
				'path'        => self::HEADING,
				'fingerprint' => $node['fingerprint'], // @phpstan-ignore-line
				'content'     => array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Nuevo' ) ) ) ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'field', $result );
		$this->assertArrayNotHasKey( 'token', $result );
		$this->assertSame( 'Nuevo', $this->attrs( $this->content( (int) $result['id'] ), self::HEADING )['title']['innerContent']['desktop']['value'] );
		$this->assertSame( self::LINK_TOKEN, $this->attrs( $this->content( (int) $result['id'] ), self::BUTTON )['button']['innerContent']['desktop']['value']['linkUrl'] );
	}
}
