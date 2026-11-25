<?php
/**
 * Módulos que sólo se admiten dentro de un layout del Theme Builder (T5, D9): `divi/post-content`, `divi/post-title` y
 * `divi/menu`, con las rutas verificadas en Divi 5.19.1 (L4 (e)) y `menu.advanced.menuId` validado contra los menús que existen.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviModuleCatalog;
use NexoDev\NexoMCP\Builders\DiviTreeValidator;
use PHPUnit\Framework\TestCase;

final class DiviLayoutModulesTest extends TestCase {

	private const MODULE_AT = 'nodes[0].children[0].children[0].children[0]';

	/**
	 * Menús que existen en el sitio de la prueba (id de término).
	 *
	 * @var array<int, string>
	 */
	private array $menus = array( 34 => 'Principal', 35 => 'Pie' );

	/**
	 * Veces que se consultó la lista de menús.
	 *
	 * @var int
	 */
	private int $menu_calls = 0;

	private function validator(): DiviTreeValidator {
		return new DiviTreeValidator(
			static fn( string $name ): bool => false,
			static fn( int $id ) => 7 === $id ? 'https://example.test/wp-content/uploads/a.jpg' : false,
			null,
			null,
			function ( int $id ): bool {
				++$this->menu_calls;

				return isset( $this->menus[ $id ] );
			}
		);
	}

	/**
	 * @param array<string, mixed> $module Módulo dentro de una columna.
	 * @return array<int, array<string, mixed>>
	 */
	private function page( array $module ): array {
		return array(
			array(
				'type'     => 'divi/section',
				'children' => array(
					array(
						'type'     => 'divi/row',
						'children' => array(
							array(
								'type'     => 'divi/column',
								'children' => array( $module ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>>|mixed $result Resultado.
	 */
	private function assertError( string $code, string $path, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringStartsWith( $path . ': ', $result->get_error_message() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data( $code ) );
	}

	/**
	 * @param mixed $value Valor de escritorio.
	 * @return array<string, mixed>
	 */
	private static function value( $value ): array {
		return array( 'desktop' => array( 'value' => $value ) );
	}

	public function test_the_layout_modules_are_exactly_the_three_verified_ones_and_the_page_catalog_does_not_change(): void {
		$this->assertSame( array( 'divi/post-content', 'divi/post-title', 'divi/menu' ), DiviModuleCatalog::LAYOUT_MODULES );
		$this->assertSame( array_merge( DiviModuleCatalog::STRUCTURE, DiviModuleCatalog::CONTENT_MODULES ), DiviModuleCatalog::modules() );
		$this->assertSame( array_merge( DiviModuleCatalog::modules(), DiviModuleCatalog::LAYOUT_MODULES ), DiviModuleCatalog::modules( true ) );

		foreach ( DiviModuleCatalog::LAYOUT_MODULES as $type ) {
			$this->assertFalse( DiviModuleCatalog::has( $type ), $type );
			$this->assertTrue( DiviModuleCatalog::has( $type, true ), $type );
			$this->assertSame( array(), DiviModuleCatalog::content_paths( $type ), $type );
			$this->assertSame( array(), DiviModuleCatalog::design_paths( $type ), $type );
			$this->assertNotContains( $type, DiviModuleCatalog::children( 'divi/column' ), $type );
			$this->assertContains( $type, DiviModuleCatalog::children( 'divi/column', true ), $type );
			$this->assertSame( array(), DiviModuleCatalog::children( $type, true ), "$type es una hoja" );
		}

		$this->assertSame( DiviModuleCatalog::children( 'divi/row' ), DiviModuleCatalog::children( 'divi/row', true ) );
		$this->assertSame( DiviModuleCatalog::content_paths( 'divi/text' ), DiviModuleCatalog::content_paths( 'divi/text', true ) );
	}

	public function test_the_verified_paths_of_each_layout_module(): void {
		$this->assertSame( array( 'module.meta.adminLabel' ), array_keys( DiviModuleCatalog::content_paths( 'divi/post-content', true ) ), 'post-content no tiene contenido que editar' );

		$title = DiviModuleCatalog::content_paths( 'divi/post-title', true );
		foreach ( array( 'title.advanced.showTitle', 'meta.advanced.showMeta', 'meta.advanced.showAuthor', 'meta.advanced.showDate', 'meta.advanced.showCategories', 'meta.advanced.showCommentsCount', 'meta.advanced.dateFormat', 'image.advanced.enabled', 'module.meta.adminLabel' ) as $path ) {
			$this->assertArrayHasKey( $path, $title, $path );
		}
		$this->assertSame( 'onoff', $title['meta.advanced.showMeta'] );
		$this->assertArrayHasKey( 'title.decoration.font.font', DiviModuleCatalog::design_paths( 'divi/post-title', true ) );
		$this->assertSame( array( 'object' => array( 'color' => array( 'enum' => array( 'light', 'dark' ) ) ) ), DiviModuleCatalog::design_paths( 'divi/post-title', true )['module.advanced.text.text'] );

		$menu = DiviModuleCatalog::content_paths( 'divi/menu', true );
		foreach ( array( 'menu.advanced.menuId', 'logo.innerContent', 'cartIcon.advanced.show', 'searchIcon.advanced.show', 'module.meta.adminLabel' ) as $path ) {
			$this->assertArrayHasKey( $path, $menu, $path );
		}
		$this->assertSame( array( 'src', 'alt', 'linkUrl', 'linkTarget' ), array_keys( $menu['logo.innerContent']['object'] ) );
		$this->assertSame( 'menu_id', $menu['menu.advanced.menuId'] );

		foreach ( DiviModuleCatalog::LAYOUT_MODULES as $type ) {
			$design = DiviModuleCatalog::design_paths( $type, true );
			$this->assertArrayHasKey( 'module.decoration.background', $design, $type );
			$this->assertArrayHasKey( 'module.decoration.spacing', $design, $type );
			$this->assertSame( array(), array_intersect( array_keys( DiviModuleCatalog::content_paths( $type, true ) ), array_keys( $design ) ), "$type: contenido y diseño no se cruzan" );

			foreach ( array_merge( array_keys( DiviModuleCatalog::content_paths( $type, true ) ), array_keys( $design ) ) as $path ) {
				$this->assertDoesNotMatchRegularExpression( '/html|css|shortcode|script|custom/i', $path, "$type $path" );
			}
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function layout_modules(): array {
		return array(
			'post-content' => array( 'divi/post-content' ),
			'post-title'   => array( 'divi/post-title' ),
			'menu'         => array( 'divi/menu' ),
		);
	}

	/**
	 * @dataProvider layout_modules
	 */
	public function test_pages_keep_rejecting_the_layout_modules_and_the_validator_is_not_changed_by_with_layout( string $type ): void {
		$validator = $this->validator();
		$layout    = $validator->with_layout();

		$this->assertError( 'nexo_mcp_divi_module_denied', self::MODULE_AT . '.type', $validator->validate_tree( $this->page( array( 'type' => $type ) ), 'divi/placeholder' ) );
		$this->assertError( 'nexo_mcp_divi_module_denied', 'type', $validator->validate_patch( $type, array( 'content' => array( 'module' => array( 'meta' => array( 'adminLabel' => self::value( 'x' ) ) ) ) ) ) );

		$clean = $layout->validate_tree( $this->page( array( 'type' => $type ) ), 'divi/placeholder' );
		$this->assertIsArray( $clean );
		$this->assertSame( $type, $clean[0]['children'][0]['children'][0]['children'][0]['type'] );

		$this->assertError( 'nexo_mcp_divi_module_denied', self::MODULE_AT . '.type', $validator->validate_tree( $this->page( array( 'type' => $type ) ), 'divi/placeholder' ), 'el original no cambió' );
	}

	public function test_a_layout_module_only_fits_in_a_column(): void {
		$layout = $this->validator()->with_layout();

		foreach ( array( 'divi/section', 'divi/row', 'divi/placeholder' ) as $parent ) {
			$result = $layout->validate_tree( array( array( 'type' => 'divi/post-content' ) ), $parent );
			$this->assertInstanceOf( \WP_Error::class, $result, $parent );
			$this->assertSame( 'nexo_mcp_divi_nesting_invalid', $result->get_error_code(), $parent );
		}

		$result = $layout->validate_tree(
			$this->page( array( 'type' => 'divi/post-content', 'children' => array( array( 'type' => 'divi/text' ) ) ) ),
			'divi/placeholder'
		);
		$this->assertError( 'nexo_mcp_divi_nesting_invalid', self::MODULE_AT . '.children[0].type', $result );
	}

	public function test_post_content_is_valid_bare_and_with_a_label_and_nothing_else(): void {
		$layout = $this->validator()->with_layout();

		$bare = $layout->validate_tree( $this->page( array( 'type' => 'divi/post-content' ) ), 'divi/placeholder' );
		$this->assertIsArray( $bare );
		$this->assertSame( array( 'type' => 'divi/post-content' ), $bare[0]['children'][0]['children'][0]['children'][0] );

		$labelled = $layout->validate_tree(
			$this->page( array( 'type' => 'divi/post-content', 'attrs' => array( 'module' => array( 'meta' => array( 'adminLabel' => self::value( 'Contenido' ) ) ) ) ) ),
			'divi/placeholder'
		);
		$this->assertIsArray( $labelled );

		$content = $layout->validate_tree(
			$this->page( array( 'type' => 'divi/post-content', 'attrs' => array( 'content' => array( 'innerContent' => self::value( '<p>x</p>' ) ) ) ) ),
			'divi/placeholder'
		);
		$this->assertError( 'nexo_mcp_divi_attr_denied', self::MODULE_AT . '.attrs.content', $content );
	}

	public function test_post_title_switches_and_date_format(): void {
		$layout = $this->validator()->with_layout();
		$attrs  = array(
			'title' => array( 'advanced' => array( 'showTitle' => self::value( 'off' ) ) ),
			'meta'  => array( 'advanced' => array( 'showMeta' => self::value( 'on' ), 'dateFormat' => self::value( 'M j, Y' ) ) ),
			'image' => array( 'advanced' => array( 'enabled' => self::value( 'off' ) ) ),
		);

		$ok = $layout->validate_tree( $this->page( array( 'type' => 'divi/post-title', 'attrs' => $attrs ) ), 'divi/placeholder' );
		$this->assertIsArray( $ok );
		$this->assertSame( $attrs, $ok[0]['children'][0]['children'][0]['children'][0]['attrs'] );

		$bad_switch = $attrs;
		$bad_switch['meta']['advanced']['showMeta'] = self::value( 'maybe' );
		$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.meta.advanced.showMeta.desktop.value', $layout->validate_tree( $this->page( array( 'type' => 'divi/post-title', 'attrs' => $bad_switch ) ), 'divi/placeholder' ) );

		foreach ( array( '<script>alert(1)</script>', str_repeat( 'Y', 31 ), '', 'Y-m-d"onload="x' ) as $format ) {
			$bad                                        = $attrs;
			$bad['meta']['advanced']['dateFormat'] = self::value( $format );
			$result                                     = $layout->validate_tree( $this->page( array( 'type' => 'divi/post-title', 'attrs' => $bad ) ), 'divi/placeholder' );

			$this->assertInstanceOf( \WP_Error::class, $result, $format );
			$this->assertSame( 'nexo_mcp_divi_value_invalid', $result->get_error_code(), $format );
		}

		$level = $layout->validate_tree(
			$this->page( array( 'type' => 'divi/post-title', 'attrs' => array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => self::value( array( 'headingLevel' => 'h2' ) ) ) ) ) ) ) ),
			'divi/placeholder'
		);
		$this->assertIsArray( $level );
	}

	public function test_the_menu_id_must_be_an_existing_menu_and_a_string(): void {
		$layout = $this->validator()->with_layout();
		$menu   = static fn( $id ): array => array( 'type' => 'divi/menu', 'attrs' => array( 'menu' => array( 'advanced' => array( 'menuId' => self::value( $id ) ) ) ) );
		$path   = self::MODULE_AT . '.attrs.menu.advanced.menuId.desktop.value';

		$ok = $layout->validate_tree( $this->page( $menu( '34' ) ), 'divi/placeholder' );
		$this->assertIsArray( $ok );
		$this->assertSame( '34', $ok[0]['children'][0]['children'][0]['children'][0]['attrs']['menu']['advanced']['menuId']['desktop']['value'] );
		$this->assertSame( 1, $this->menu_calls, 'una consulta' );

		foreach ( array( 'no existe' => '999', 'cero' => '0', 'texto' => 'principal', 'mezcla' => '34abc', 'vacío' => '', 'negativo' => '-34', 'decimal' => '34.0', 'espacio' => ' 34' ) as $label => $id ) {
			$result = $layout->validate_tree( $this->page( $menu( $id ) ), 'divi/placeholder' );

			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( 'nexo_mcp_divi_value_invalid', $result->get_error_code(), $label );
			$this->assertSame( array( 'path' => $path ), $result->get_error_data( 'nexo_mcp_divi_value_invalid' ), $label );
		}

		$this->assertError( 'nexo_mcp_divi_value_invalid', $path, $layout->validate_tree( $this->page( $menu( 34 ) ), 'divi/placeholder' ) );
		$this->assertError( 'nexo_mcp_divi_value_invalid', $path, $layout->validate_tree( $this->page( $menu( array( '34' ) ) ), 'divi/placeholder' ) );
	}

	public function test_the_menu_lookup_that_throws_counts_as_no_menu(): void {
		$layout = ( new DiviTreeValidator( null, null, null, null, static function ( int $id ): bool {
			throw new \RuntimeException( 'boom' );
		} ) )->with_layout();

		$result = $layout->validate_tree(
			$this->page( array( 'type' => 'divi/menu', 'attrs' => array( 'menu' => array( 'advanced' => array( 'menuId' => self::value( '34' ) ) ) ) ) ),
			'divi/placeholder'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_value_invalid', $result->get_error_code() );
	}

	public function test_menu_logo_icons_and_a_patch(): void {
		$layout = $this->validator()->with_layout();
		$attrs  = array(
			'logo'       => array( 'innerContent' => self::value( array( 'src' => 7, 'alt' => 'Logo', 'linkUrl' => '/', 'linkTarget' => 'off' ) ) ),
			'cartIcon'   => array( 'advanced' => array( 'show' => self::value( 'off' ) ) ),
			'searchIcon' => array( 'advanced' => array( 'show' => self::value( 'on' ) ) ),
		);

		$this->assertIsArray( $layout->validate_tree( $this->page( array( 'type' => 'divi/menu', 'attrs' => $attrs ) ), 'divi/placeholder' ) );

		$bad                                    = $attrs;
		$bad['logo']['innerContent']['desktop']['value']['linkUrl'] = 'javascript:alert(1)';
		$this->assertError( 'nexo_mcp_divi_value_invalid', self::MODULE_AT . '.attrs.logo.innerContent.desktop.value.linkUrl', $layout->validate_tree( $this->page( array( 'type' => 'divi/menu', 'attrs' => $bad ) ), 'divi/placeholder' ) );

		$patch = $layout->validate_patch( 'divi/menu', array( 'content' => array( 'menu' => array( 'advanced' => array( 'menuId' => self::value( '35' ) ) ) ) ) );
		$this->assertSame( array( 'content' => array( 'menu' => array( 'advanced' => array( 'menuId' => self::value( '35' ) ) ) ) ), $patch );

		$bad_patch = $layout->validate_patch( 'divi/menu', array( 'content' => array( 'menu' => array( 'advanced' => array( 'menuId' => self::value( '1' ) ) ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $bad_patch );
		$this->assertSame( 'nexo_mcp_divi_value_invalid', $bad_patch->get_error_code() );
	}

	public function test_preserve_page_modules_validate_the_same_with_and_without_layout_mode(): void {
		$text  = array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => self::value( '<p>Hola</p>' ) ) ) );
		$plain = $this->validator()->validate_tree( $this->page( $text ), 'divi/placeholder' );
		$other = $this->validator()->with_layout()->validate_tree( $this->page( $text ), 'divi/placeholder' );

		$this->assertIsArray( $plain );
		$this->assertSame( $plain, $other );

		$bad = array( 'type' => 'divi/code' );
		$this->assertError( 'nexo_mcp_divi_module_denied', self::MODULE_AT . '.type', $this->validator()->validate_tree( $this->page( $bad ), 'divi/placeholder' ) );
		$this->assertError( 'nexo_mcp_divi_module_denied', self::MODULE_AT . '.type', $this->validator()->with_layout()->validate_tree( $this->page( $bad ), 'divi/placeholder' ) );
	}
}
