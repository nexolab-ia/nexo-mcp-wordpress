<?php
/**
 * set-divi-loop (T6 de D6), parte del editor: activar o quitar el bucle de consulta de un módulo en la copia de
 * trabajo, y los tokens `loop_*` sólo dentro de un módulo con un bucle del conector.
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
use NexoDev\NexoMCP\Builders\DiviLoopSchema;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

final class Divi5LoopEditorTest extends NexoTestCase {

	private const ORIGINAL = 100;

	private const PLACEHOLDER = '0';
	private const SECTION     = '0.0';
	private const ROW         = '0.0.0';
	private const COLUMN      = '0.0.0.0';
	private const HEADING     = '0.0.0.0.0';
	private const TEXT        = '0.0.0.0.1';
	private const ICON        = '0.0.0.0.2';
	private const SIBLING_COL = '0.0.0.1';
	private const SIBLING_TXT = '0.0.0.1.0';

	private const TITLE_TOKEN   = '$variable({"type":"content","value":{"name":"loop_post_title","settings":{"enable_html":"off"}}})$';
	private const PRICE_TOKEN   = '$variable({"type":"content","value":{"name":"loop_product_price_current","settings":{"enable_html":"off"}}})$';
	private const FOREIGN_TOKEN = '$variable({"type":"content","value":{"name":"loop_user_email","settings":{}}})$';
	private const OLD_TOKEN     = '$variable({"type":"content","value":{"name":"site_title","settings":{"enable_html":"off"}}})$';

	private Settings $cfg;

	protected function setUp(): void {
		parent::setUp();

		$this->cfg = $this->settings( array( 'read_only' => false ) );
	}

	private function schema(): DiviLoopSchema {
		return new DiviLoopSchema(
			static fn( string $type ): ?array => array(
				'post'       => array( 'public' => true, 'label' => 'Entradas' ),
				'product'    => array( 'public' => true, 'label' => 'Productos' ),
				'attachment' => array( 'public' => true, 'label' => 'Medios' ),
			)[ $type ] ?? null,
			static fn( string $type ): array => array( 'post' => array( 'category' ), 'product' => array( 'product_cat' ) )[ $type ] ?? array(),
			static fn( int $id, string $taxonomy ): ?string => 'category' === $taxonomy && 3 === $id ? 'Noticias' : null,
			static fn(): string => 'loop-nxnewid001'
		);
	}

	/**
	 * Valor guardado de un bucle del conector sobre entradas.
	 *
	 * @param array<string, mixed> $extra Claves que cambian al armar los parámetros.
	 * @return array<string, mixed>
	 */
	private function mcp_loop( string $id = 'loop-nxabcd1234', string $type = 'post', array $extra = array() ): array {
		$schema = $this->schema();
		$params = $schema->validate( array_merge( array( 'post_type' => $type, 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc' ), $extra ) );
		$this->assertIsArray( $params );

		return $schema->stored( $params, $id );
	}

	/**
	 * @param array<string, mixed> $value Valor del bucle.
	 * @return array<string, mixed>
	 */
	private static function loop_attrs( array $value ): array {
		return array( 'module' => array( 'advanced' => array( 'loop' => array( 'desktop' => array( 'value' => $value ) ) ) ) );
	}

	/**
	 * @param array<string, mixed>      $attrs   Atributos.
	 * @param array<int, string>|string $inner   Hijos ya serializados.
	 */
	private static function block( string $name, array $attrs, $inner = '' ): string {
		$json = array() === $attrs ? '' : ' ' . wp_json_encode( $attrs );

		return '' === $inner || array() === $inner
			? '<!-- wp:divi/' . $name . $json . ' /-->' . "\n"
			: '<!-- wp:divi/' . $name . $json . ' -->' . "\n" . ( is_array( $inner ) ? implode( '', $inner ) : $inner ) . '<!-- /wp:divi/' . $name . ' -->' . "\n";
	}

	/**
	 * @param array<string, mixed> $title_attrs Atributos del título.
	 */
	private static function title( string $text, array $title_attrs = array() ): array {
		return array_merge( array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => $text ), 'tablet' => array( 'value' => 'tablet' ) ) ) ), $title_attrs );
	}

	/**
	 * Página: placeholder > section > row > (column [heading, text, icon], column [text]).
	 *
	 * @param array<string, array<string, mixed>> $loops  Atributos extra por nombre de nodo (section, row, column, heading, text, icon, sibling).
	 */
	private static function page( array $loops = array(), string $heading_text = 'Hola', string $text_text = '<p>Texto</p>' ): string {
		$extra = static fn( string $key ): array => $loops[ $key ] ?? array();
		$raw   = self::block(
			'placeholder',
			array(),
			self::block(
				'section',
				array_merge( array( 'builderVersion' => '5.19.1' ), $extra( 'section' ) ),
				self::block(
					'row',
					$extra( 'row' ),
					array(
						self::block(
							'column',
							$extra( 'column' ),
							array(
								self::block( 'heading', array_merge( self::title( $heading_text ), $extra( 'heading' ) ) ),
								self::block( 'text', array_merge( array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => $text_text ) ) ) ), $extra( 'text' ) ) ),
								self::block( 'icon', $extra( 'icon' ) ),
							)
						),
						self::block(
							'column',
							$extra( 'sibling' ),
							self::block( 'text', array_merge( array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>Otro</p>' ) ) ) ), $extra( 'sibling_text' ) ) )
						),
					)
				)
			)
		);

		return serialize_blocks( parse_blocks( $raw ) );
	}

	private function put_page( string $content ): void {
		\WP_Test_State::$posts = array();
		\WP_Test_State::$meta  = array();

		\WP_Test_State::$posts[ self::ORIGINAL ] = new \WP_Post(
			array(
				'ID'           => self::ORIGINAL,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Inicio',
				'post_content' => $content,
			)
		);
	}

	private function catalog(): DiviDynamicCatalog {
		return new DiviDynamicCatalog(
			static fn(): bool => true,
			static function (): array {
				$names = array( 'post_title', 'site_title', 'loop_post_title', 'loop_post_link', 'loop_post_featured_image', 'loop_post_terms', 'loop_product_price_current', 'loop_product_sku', 'loop_user_email' );
				$types = array( 'loop_post_link' => 'url', 'loop_post_featured_image' => 'image' );

				return array_combine( $names, array_map( static fn( string $n ): array => array( 'type' => $types[ $n ] ?? 'text' ), $names ) );
			},
			static fn(): array => array(),
			static fn(): string => 'page',
			static fn( string $type ): array => array( 'post' => array( 'category', 'post_tag' ), 'product' => array( 'product_cat', 'product_tag' ) )[ $type ] ?? array()
		);
	}

	private function editor( bool $dynamic = false ): Divi5ModuleEditor {
		$validator = new DiviTreeValidator( static fn(): bool => false, static fn() => false, static fn(): array => array(), static fn(): array => array() );

		return new Divi5ModuleEditor(
			$this->cfg,
			new Divi5Adapter(),
			new BuilderBackup(),
			new WorkingCopy( $this->cfg ),
			$dynamic ? $validator->with_dynamic( $this->catalog(), self::ORIGINAL ) : $validator,
			static fn() => true,
			static fn(): bool => true,
			null,
			null,
			$this->schema()
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
	 * @param mixed $loop Bucle {post_type, per_page…} o null.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set_loop( string $path, $loop, int $id = self::ORIGINAL, ?string $fingerprint = null ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path );

		return $this->editor()->edit(
			$post,
			array(
				'path'        => $path,
				'fingerprint' => $fingerprint ?? (string) ( $node['fingerprint'] ?? '' ),
				'loop'        => $loop,
			)
		);
	}

	/**
	 * @param mixed $source Fuente.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function assign( string $path, string $field, $source, int $id = self::ORIGINAL ) {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$node = Divi5Tree::locate( Divi5Tree::build( $post->post_content ), $path );

		return $this->editor( true )->edit(
			$post,
			array(
				'path'        => $path,
				'fingerprint' => (string) ( $node['fingerprint'] ?? '' ),
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

	private static function params(): array {
		return array( 'post_type' => 'post', 'per_page' => 3, 'order_by' => 'date', 'order' => 'desc' );
	}

	// -- activar -----------------------------------------------------------------------------------------------------

	public function test_enabling_writes_divis_exact_shape_in_the_working_copy_and_leaves_the_original(): void {
		$this->put_page( self::page() );
		$original = $this->content( self::ORIGINAL );

		$result = $this->set_loop( self::COLUMN, self::params() );

		$this->assertIsArray( $result );
		$copy = (int) $result['id'];
		$this->assertNotSame( self::ORIGINAL, $copy );
		$this->assertSame( self::ORIGINAL, $result['draft_of'] );
		$this->assertSame( $original, $this->content( self::ORIGINAL ), 'el original no cambia' );
		$this->assertTrue( ( new BuilderBackup() )->exists( $copy ) );

		$stored = $this->attrs( $this->content( $copy ), self::COLUMN )['module']['advanced']['loop'];
		$this->assertSame( array( 'desktop' ), array_keys( $stored ) );
		$this->assertSame( array( 'value' ), array_keys( $stored['desktop'] ) );
		$this->assertSame(
			array(
				'enable'             => 'on',
				'queryType'          => 'post_types',
				'subTypes'           => array( array( 'value' => 'post', 'label' => 'Entradas' ) ),
				'orderBy'            => 'date',
				'order'              => 'descending',
				'postPerPage'        => '3',
				'postOffset'         => '0',
				'excludeCurrentPost' => 'off',
				'ignoreStickysPost'  => 'on',
				'loopId'             => 'loop-nxnewid001',
			),
			$stored['desktop']['value']
		);

		$this->assertSame( self::COLUMN, $result['path'] );
		$this->assertSame( Divi5Tree::locate( Divi5Tree::build( $this->content( $copy ) ), self::COLUMN )['fingerprint'], $result['fingerprint'] ); // @phpstan-ignore-line
		$this->assertSame( 'post', $result['loop']['post_type'] );
		$this->assertSame( 3, $result['loop']['per_page'] );
		$this->assertSame( 'loop-nxnewid001', $result['loop']['loop_id'] );
	}

	public function test_every_supported_module_type_takes_a_loop_and_the_others_are_denied(): void {
		$this->put_page( self::page() );

		foreach ( array( self::SECTION, self::ROW, self::COLUMN, self::HEADING, self::TEXT ) as $path ) {
			$result = $this->set_loop( $path, self::params() );
			$this->assertIsArray( $result, $path );
			\WP_Test_State::$posts = array( self::ORIGINAL => \WP_Test_State::$posts[ self::ORIGINAL ] );
			\WP_Test_State::$meta  = array();
		}

		$before = $this->snapshot();
		$this->assertRejected( 'nexo_mcp_divi_module_denied', $this->set_loop( self::PLACEHOLDER, self::params() ), $before );
		$this->assertRejected( 'nexo_mcp_divi_module_denied', $this->set_loop( self::ICON, self::params() ), $before );
	}

	public function test_the_whole_module_matrix_of_the_catalog_accepts_a_loop_except_icon_and_video(): void {
		$raw = self::block(
			'placeholder',
			array(),
			self::block(
				'section',
				array(),
				self::block(
					'row',
					array(),
					self::block(
						'column',
						array(),
						array(
							self::block( 'button', array() ),
							self::block( 'image', array() ),
							self::block( 'divider', array() ),
							self::block( 'blurb', array() ),
							self::block( 'icon-list', array(), self::block( 'icon-list-item', array() ) ),
							self::block( 'video', array() ),
							self::block( 'icon', array() ),
						)
					)
				)
			)
		);
		$this->put_page( serialize_blocks( parse_blocks( $raw ) ) );

		$accept = array(
			'button'         => '0.0.0.0.0',
			'image'          => '0.0.0.0.1',
			'divider'        => '0.0.0.0.2',
			'blurb'          => '0.0.0.0.3',
			'icon-list'      => '0.0.0.0.4',
			'icon-list-item' => '0.0.0.0.4.0',
		);

		foreach ( $accept as $module => $path ) {
			$result = $this->set_loop( $path, self::params() );
			$this->assertIsArray( $result, $module . ': ' . ( $result instanceof \WP_Error ? $result->get_error_message() : '' ) );
			$this->assertSame( $path, $result['path'], $module );
			$this->assertSame( 'loop-nxnewid001', $result['loop']['loop_id'], $module );
			\WP_Test_State::$posts = array( self::ORIGINAL => \WP_Test_State::$posts[ self::ORIGINAL ] );
			\WP_Test_State::$meta  = array();
		}

		$before = $this->snapshot();

		foreach ( array(
			'video' => '0.0.0.0.5',
			'icon'  => '0.0.0.0.6',
		) as $module => $path ) {
			$error = $this->set_loop( $path, self::params() );
			$this->assertRejected( 'nexo_mcp_divi_module_denied', $error, $before );
			$this->assertStringContainsString( 'divi/' . $module, $error->get_error_message() );
		}
	}

	public function test_an_invalid_loop_is_rejected_with_its_path_and_nothing_is_written(): void {
		$this->put_page( self::page() );
		$before = $this->snapshot();

		foreach ( array( 'any', 'attachment', '' ) as $type ) {
			$error = $this->assertRejected( 'nexo_mcp_divi_loop_invalid', $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'post_type' => $type ) ) ), $before );
			$this->assertSame( array( 'path' => 'loop.post_type' ), $error->get_error_data() );
		}

		$error = $this->assertRejected( 'nexo_mcp_divi_loop_invalid', $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'per_page' => 25 ) ) ), $before );
		$this->assertSame( array( 'path' => 'loop.per_page' ), $error->get_error_data() );
		$this->assertRejected( 'nexo_mcp_divi_loop_invalid', $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'meta_query' => array() ) ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_loop_invalid', $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'search' => 'x' ) ) ), $before );
		$this->assertRejected( 'nexo_mcp_divi_loop_invalid', $this->set_loop( self::COLUMN, 'post' ), $before );
	}

	public function test_a_stale_fingerprint_and_a_missing_path_write_nothing(): void {
		$this->put_page( self::page() );
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_node_changed', $this->set_loop( self::COLUMN, self::params(), self::ORIGINAL, 'vieja' ), $before );
		$this->assertRejected( 'nexo_mcp_builder_node_missing', $this->set_loop( '0.0.0.9', self::params() ), $before );
	}

	public function test_loops_do_not_nest_an_ancestor_or_a_descendant_with_a_loop_blocks_it(): void {
		$mine = self::loop_attrs( $this->mcp_loop() );
		$vb   = self::loop_attrs( array( 'enable' => 'on', 'queryType' => 'user_roles', 'loopId' => 'loop-abcdefghij' ) );

		// El ancestro (la sección) ya tiene un bucle del conector.
		$this->put_page( self::page( array( 'section' => $mine ) ) );
		$before = $this->snapshot();
		$this->assertRejected( 'nexo_mcp_divi_loop_nested', $this->set_loop( self::COLUMN, self::params() ), $before );
		$this->assertRejected( 'nexo_mcp_divi_loop_nested', $this->set_loop( self::HEADING, self::params() ), $before );

		// El descendiente (el texto) tiene un bucle hecho en el Visual Builder.
		$this->put_page( self::page( array( 'text' => $vb ) ) );
		$before = $this->snapshot();
		$error  = $this->assertRejected( 'nexo_mcp_divi_loop_nested', $this->set_loop( self::SECTION, self::params() ), $before );
		$this->assertStringContainsString( self::TEXT, $error->get_error_message() );
		$this->assertRejected( 'nexo_mcp_divi_loop_nested', $this->set_loop( self::COLUMN, self::params() ), $before );

		// Un hermano con bucle no impide: no es ancestro ni descendiente.
		$this->put_page( self::page( array( 'sibling' => $mine ) ) );
		$this->assertIsArray( $this->set_loop( self::COLUMN, self::params() ) );
	}

	public function test_a_loop_that_is_off_does_not_count_as_nesting(): void {
		$this->put_page( self::page( array( 'section' => self::loop_attrs( array( 'enable' => 'off' ) ) ) ) );

		$this->assertIsArray( $this->set_loop( self::COLUMN, self::params() ) );
	}

	public function test_updating_the_nodes_own_loop_replaces_it_whole_and_keeps_the_loop_id(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop( 'loop-nxabcd1234' ) ) ) ) );

		$result = $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'per_page' => 8, 'order_by' => 'title', 'order' => 'asc', 'exclude_current' => true, 'terms' => array( 'category' => array( 3 ) ) ) ) );

		$this->assertIsArray( $result );
		$value = $this->attrs( $this->content( (int) $result['id'] ), self::COLUMN )['module']['advanced']['loop']['desktop']['value'];
		$this->assertSame( 'loop-nxabcd1234', $value['loopId'] );
		$this->assertSame( '8', $value['postPerPage'] );
		$this->assertSame( 'title', $value['orderBy'] );
		$this->assertSame( 'on', $value['excludeCurrentPost'] );
		$this->assertSame( 3, $value['includePostWithSpecificTerms'][0]['selectedOptions'][0]['value'] );
	}

	public function test_a_foreign_loop_on_the_same_node_is_replaced_by_ours_without_keeping_its_keys(): void {
		$vb = array( 'enable' => 'on', 'queryType' => 'user_roles', 'loopId' => 'loop-abcdefghij', 'metaQuery' => array( array( 'key' => '_x', 'value' => '^a', 'compare' => 'REGEXP' ) ), 'search' => 'x', 'postPerPage' => '500' );
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $vb ) ) ) );

		$result = $this->set_loop( self::COLUMN, self::params() );

		$this->assertIsArray( $result );
		$value = $this->attrs( $this->content( (int) $result['id'] ), self::COLUMN )['module']['advanced']['loop']['desktop']['value'];
		$this->assertSame( 'loop-nxnewid001', $value['loopId'] );
		$this->assertSame( 'post_types', $value['queryType'] );
		$this->assertArrayNotHasKey( 'metaQuery', $value );
		$this->assertArrayNotHasKey( 'search', $value );
	}

	public function test_the_rest_of_the_block_and_the_other_breakpoints_and_tokens_are_kept(): void {
		$column = array(
			'module' => array(
				'advanced'   => array( 'text' => array( 'text' => array( 'desktop' => array( 'value' => array( 'orientation' => 'center' ) ) ) ) ),
				'decoration' => array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '4px' ) ) ) ) ),
			),
		);
		$this->put_page( self::page( array( 'column' => $column, 'heading' => self::title( self::OLD_TOKEN ) ) ) );

		$result = $this->set_loop( self::COLUMN, self::params() );

		$this->assertIsArray( $result );
		$attrs = $this->attrs( $this->content( (int) $result['id'] ), self::COLUMN );
		$this->assertSame( $column['module']['decoration'], $attrs['module']['decoration'] );
		$this->assertSame( $column['module']['advanced']['text'], $attrs['module']['advanced']['text'] );
		$this->assertArrayHasKey( 'loop', $attrs['module']['advanced'] );
		$this->assertSame( self::OLD_TOKEN, $this->attrs( $this->content( (int) $result['id'] ), self::HEADING )['title']['innerContent']['desktop']['value'], 'los tokens de otros módulos no se tocan' );
	}

	public function test_a_foreign_token_inside_the_new_loop_is_refused_and_a_product_one_needs_a_product_loop(): void {
		$this->put_page( self::page( array( 'heading' => self::title( self::FOREIGN_TOKEN ) ) ) );
		$before = $this->snapshot();
		$this->assertRejected( 'nexo_mcp_divi_loop_in_use', $this->set_loop( self::COLUMN, self::params() ), $before );

		$this->put_page( self::page( array( 'heading' => self::title( self::PRICE_TOKEN ) ) ) );
		$before = $this->snapshot();
		$error  = $this->assertRejected( 'nexo_mcp_divi_loop_in_use', $this->set_loop( self::COLUMN, self::params() ), $before );
		$this->assertStringContainsString( 'loop_product_price_current', $error->get_error_message() );
		$this->assertIsArray( $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'post_type' => 'product' ) ) ) );
	}

	public function test_a_post_loop_token_is_fine_when_the_loop_is_enabled_or_updated(): void {
		$this->put_page( self::page( array( 'heading' => self::title( self::TITLE_TOKEN ) ) ) );

		$this->assertIsArray( $this->set_loop( self::COLUMN, self::params() ) );
	}

	// -- quitar ------------------------------------------------------------------------------------------------------

	public function test_null_removes_the_loop_key_and_prunes_what_it_leaves_empty(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ) ) ) );

		$result = $this->set_loop( self::COLUMN, null );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertNull( $result['loop'] );
		$this->assertSame( array(), $this->attrs( $this->content( (int) $result['id'] ), self::COLUMN ), 'no queda module/advanced vacío' );
		$this->assertArrayHasKey( 'module', $this->attrs( $this->content( self::ORIGINAL ), self::COLUMN ), 'el original conserva su bucle' );
	}

	public function test_removing_keeps_the_other_module_options(): void {
		$attrs = self::loop_attrs( $this->mcp_loop() );
		$attrs['module']['advanced']['text'] = array( 'text' => array( 'desktop' => array( 'value' => array( 'orientation' => 'center' ) ) ) );
		$attrs['module']['decoration']       = array( 'spacing' => array( 'desktop' => array( 'value' => array( 'margin' => array( 'top' => '4px' ) ) ) ) );
		$this->put_page( self::page( array( 'column' => $attrs ) ) );

		$result = $this->set_loop( self::COLUMN, null );

		$this->assertIsArray( $result );
		$kept = $this->attrs( $this->content( (int) $result['id'] ), self::COLUMN );
		$this->assertSame( array( 'advanced' => array( 'text' => $attrs['module']['advanced']['text'] ), 'decoration' => $attrs['module']['decoration'] ), $kept['module'] );
	}

	public function test_removing_a_loop_that_is_not_there_is_an_error_and_writes_nothing(): void {
		$this->put_page( self::page() );

		$this->assertRejected( 'nexo_mcp_divi_loop_not_set', $this->set_loop( self::COLUMN, null ), $this->snapshot() );
	}

	public function test_removing_a_foreign_loop_is_allowed(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( array( 'enable' => 'on', 'queryType' => 'user_roles', 'loopId' => 'loop-abcdefghij' ) ) ) ) );

		$this->assertIsArray( $this->set_loop( self::COLUMN, null ) );
	}

	public function test_a_loop_cannot_be_removed_while_loop_tokens_remain_inside(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ), 'heading' => self::title( self::TITLE_TOKEN ) ) ) );
		$before = $this->snapshot();

		$error = $this->assertRejected( 'nexo_mcp_divi_loop_in_use', $this->set_loop( self::COLUMN, null ), $before );
		$this->assertStringContainsString( self::HEADING, $error->get_error_message() );
	}

	public function test_changing_the_type_away_from_product_is_refused_while_a_product_token_is_inside(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop( 'loop-nxabcd1234', 'product' ) ), 'heading' => self::title( self::PRICE_TOKEN ) ) ) );
		$before = $this->snapshot();

		$this->assertRejected( 'nexo_mcp_divi_loop_in_use', $this->set_loop( self::COLUMN, self::params() ), $before );
		$this->assertIsArray( $this->set_loop( self::COLUMN, array_merge( self::params(), array( 'post_type' => 'product', 'per_page' => 5 ) ) ) );
	}

	// -- loop_* en set-divi-dynamic ------------------------------------------------------------------------------------

	public function test_a_loop_token_goes_inside_a_module_with_a_valid_loop_on_an_ancestor(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ) ) ) );

		$result = $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::TITLE_TOKEN, $result['token'] );
		$this->assertSame( self::TITLE_TOKEN, $this->attrs( $this->content( (int) $result['id'] ), self::HEADING )['title']['innerContent']['desktop']['value'] );
	}

	public function test_a_loop_token_also_goes_in_the_module_that_carries_the_loop_itself(): void {
		$this->put_page( self::page( array( 'heading' => array_merge( self::title( 'Hola' ), self::loop_attrs( $this->mcp_loop() ) ) ) ) );

		$this->assertIsArray( $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ) );
	}

	public function test_a_loop_token_outside_any_loop_is_denied_and_nothing_is_written(): void {
		$this->put_page( self::page( array( 'sibling' => self::loop_attrs( $this->mcp_loop() ) ) ) );
		$before = $this->snapshot();

		$error = $this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ), $before );
		$this->assertStringContainsString( 'bucle', $error->get_error_message() );
		$this->assertIsArray( $this->assign( self::SIBLING_TXT, 'content.innerContent', array( 'name' => 'loop_post_title' ) ), 'el hermano con bucle sí' );
	}

	public function test_a_loop_made_elsewhere_or_with_extra_keys_does_not_open_the_loop_sources(): void {
		$vb     = array( 'enable' => 'on', 'queryType' => 'user_roles', 'loopId' => 'loop-abcdefghij' );
		$meta   = array_merge( $this->mcp_loop(), array( 'metaQuery' => array( array( 'key' => '_p', 'value' => '^a', 'compare' => 'REGEXP' ) ) ) );
		$any    = array_merge( $this->mcp_loop(), array( 'subTypes' => array() ) );
		$big    = array_merge( $this->mcp_loop(), array( 'postPerPage' => '500' ) );
		$vbpost = array_merge( $this->mcp_loop(), array( 'loopId' => 'loop-abcdefghij' ) );

		foreach ( array( 'user loop' => $vb, 'metaQuery' => $meta, 'any type' => $any, 'per page' => $big, 'foreign id' => $vbpost ) as $why => $value ) {
			$this->put_page( self::page( array( 'column' => self::loop_attrs( $value ) ) ) );
			$before = $this->snapshot();
			$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ), $before );
			unset( $why );
		}
	}

	public function test_the_context_is_the_innermost_loop_of_the_chain(): void {
		$foreign = self::loop_attrs( array( 'enable' => 'on', 'queryType' => 'user_roles', 'loopId' => 'loop-abcdefghij' ) );
		$this->put_page( self::page( array( 'section' => self::loop_attrs( $this->mcp_loop() ), 'column' => $foreign ) ) );

		$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ), $this->snapshot() );
	}

	public function test_product_sources_need_a_product_loop_and_post_ones_work_in_both(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ) ) ) );
		$before = $this->snapshot();
		$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_product_price_current' ) ), $before );

		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop( 'loop-nxabcd1234', 'product' ) ) ) ) );
		$this->assertIsArray( $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_product_price_current' ) ) );

		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop( 'loop-nxabcd1234', 'product' ) ) ) ) );
		$this->assertIsArray( $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ) );
	}

	public function test_user_term_and_meta_loop_sources_are_never_accepted_even_inside_a_valid_loop(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ) ) ) );
		$before = $this->snapshot();

		foreach ( array( 'loop_user_email', 'loop_user_name', 'loop_term_name', 'loop_post_meta_key_manual_custom_field', 'loop_post_author_bio', 'loop_menu_text' ) as $name ) {
			$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => $name ) ), $before );
		}
	}

	public function test_loop_tokens_are_evaluated_against_the_current_tree_not_a_remembered_one(): void {
		$this->put_page( self::page( array( 'column' => self::loop_attrs( $this->mcp_loop() ) ) ) );
		$this->assertIsArray( $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ) );

		// El bucle se quita a mano de la página (otro editor): el siguiente token ya no tiene contexto.
		$this->put_page( self::page() );
		$this->assertRejected( 'nexo_mcp_divi_dynamic_denied', $this->assign( self::HEADING, 'title.innerContent', array( 'name' => 'loop_post_title' ) ), $this->snapshot() );
	}
}
