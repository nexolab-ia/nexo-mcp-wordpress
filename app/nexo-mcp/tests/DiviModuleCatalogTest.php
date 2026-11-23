<?php
/**
 * Catálogo cerrado de módulos de Divi 5 (datos y comprobaciones puras).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviModuleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Los nombres y las rutas se confirmaron en Divi 5.19.1 (`module.json` y
 * `_all_modules_conversion_outline.php`); aquí se fija la lista cerrada.
 */
final class DiviModuleCatalogTest extends TestCase {

	public function test_the_catalog_is_exactly_the_closed_list(): void {
		$this->assertSame( array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column' ), DiviModuleCatalog::STRUCTURE );
		$this->assertSame(
			array( 'divi/text', 'divi/heading', 'divi/button', 'divi/image', 'divi/divider', 'divi/blurb', 'divi/icon', 'divi/video', 'divi/icon-list', 'divi/icon-list-item' ),
			DiviModuleCatalog::CONTENT_MODULES
		);
		$this->assertSame( array_merge( DiviModuleCatalog::STRUCTURE, DiviModuleCatalog::CONTENT_MODULES ), DiviModuleCatalog::modules() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function denied_modules(): array {
		return array(
			'code'           => array( 'divi/code' ),
			'fullwidth code' => array( 'divi/fullwidth-code' ),
			'shortcode'      => array( 'divi/shortcode-module' ),
			'layout'         => array( 'divi/layout' ),
			'accordion'      => array( 'divi/accordion' ),
			'row inner'      => array( 'divi/row-inner' ),
			'core'           => array( 'core/html' ),
			''               => array( '' ),
		);
	}

	/**
	 * @dataProvider denied_modules
	 */
	public function test_modules_outside_the_list_are_unknown( string $type ): void {
		$this->assertFalse( DiviModuleCatalog::has( $type ) );
		$this->assertSame( array(), DiviModuleCatalog::content_paths( $type ) );
		$this->assertSame( array(), DiviModuleCatalog::design_paths( $type ) );
		$this->assertSame( array(), DiviModuleCatalog::children( $type ) );
	}

	public function test_nesting_is_placeholder_section_row_column_content(): void {
		$this->assertSame( array( 'divi/placeholder', 'divi/section' ), DiviModuleCatalog::children( DiviModuleCatalog::ROOT ) );
		$this->assertSame( array( 'divi/section' ), DiviModuleCatalog::children( 'divi/placeholder' ) );
		$this->assertSame( array( 'divi/row' ), DiviModuleCatalog::children( 'divi/section' ) );
		$this->assertSame( array( 'divi/column' ), DiviModuleCatalog::children( 'divi/row' ) );
		$this->assertSame(
			array( 'divi/text', 'divi/heading', 'divi/button', 'divi/image', 'divi/divider', 'divi/blurb', 'divi/icon', 'divi/video', 'divi/icon-list' ),
			DiviModuleCatalog::children( 'divi/column' )
		);
		$this->assertSame( array( 'divi/icon-list-item' ), DiviModuleCatalog::children( 'divi/icon-list' ) );
		$this->assertSame( array(), DiviModuleCatalog::children( 'divi/text' ) );
		$this->assertSame( array(), DiviModuleCatalog::children( 'divi/icon-list-item' ) );
	}

	public function test_confirmed_attribute_paths(): void {
		$this->assertArrayHasKey( 'content.innerContent', DiviModuleCatalog::content_paths( 'divi/text' ) );
		$this->assertArrayHasKey( 'title.innerContent', DiviModuleCatalog::content_paths( 'divi/heading' ) );
		$this->assertArrayHasKey( 'button.innerContent', DiviModuleCatalog::content_paths( 'divi/button' ) );
		$this->assertArrayHasKey( 'image.innerContent', DiviModuleCatalog::content_paths( 'divi/image' ) );
		$this->assertArrayHasKey( 'icon.innerContent', DiviModuleCatalog::content_paths( 'divi/icon' ) );
		$this->assertArrayHasKey( 'video.innerContent', DiviModuleCatalog::content_paths( 'divi/video' ) );
		$this->assertArrayHasKey( 'module.advanced.type', DiviModuleCatalog::content_paths( 'divi/column' ) );
		$this->assertArrayHasKey( 'module.meta.adminLabel', DiviModuleCatalog::content_paths( 'divi/section' ) );
		$this->assertArrayHasKey( 'title.decoration.font.font', DiviModuleCatalog::design_paths( 'divi/heading' ) );
		$this->assertArrayHasKey( 'content.decoration.bodyFont.body.font', DiviModuleCatalog::design_paths( 'divi/text' ) );
		$this->assertArrayHasKey( 'button.decoration.font.font', DiviModuleCatalog::design_paths( 'divi/button' ) );
		$this->assertArrayHasKey( 'module.decoration.spacing', DiviModuleCatalog::design_paths( 'divi/section' ) );
		$this->assertArrayHasKey( 'module.decoration.background', DiviModuleCatalog::design_paths( 'divi/row' ) );
		$this->assertArrayHasKey( 'divider.advanced.line', DiviModuleCatalog::design_paths( 'divi/divider' ) );
	}

	public function test_content_and_design_paths_never_overlap_and_never_allow_code(): void {
		foreach ( DiviModuleCatalog::modules() as $type ) {
			$content = array_keys( DiviModuleCatalog::content_paths( $type ) );
			$design  = array_keys( DiviModuleCatalog::design_paths( $type ) );

			$this->assertSame( array(), array_intersect( $content, $design ), $type );

			foreach ( array_merge( $content, $design ) as $path ) {
				$this->assertDoesNotMatchRegularExpression( '/html|css|shortcode|script|custom/i', $path, $type . ' ' . $path );
			}
		}
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function colors(): array {
		return array(
			'hex 3'              => array( '#fff', true ),
			'hex 6'              => array( '#112233', true ),
			'hex 8'              => array( '#11223344', true ),
			'rgba'               => array( 'rgba(0,0,0,0.5)', true ),
			'rgb spaced'         => array( 'rgb(10, 20, 30)', true ),
			'hsl'                => array( 'hsl(120, 50%, 50%)', true ),
			'gcid'               => array( 'gcid-primary-color', true ),
			'var gcid'           => array( 'var(--gcid-primary-color)', true ),
			'variable'           => array( '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$', true ),
			'name'               => array( 'red', false ),
			'hex bad'            => array( '#12', false ),
			'hex non hex'        => array( '#gggggg', false ),
			'url'                => array( 'url(https://a.b/x.png)', false ),
			'expression'         => array( 'rgb(0,0,0);color:red', false ),
			'var other'          => array( 'var(--evil)', false ),
			'variable other'     => array( '$variable({"type":"content","value":{"name":"x"}})$', false ),
			'empty'              => array( '', false ),
			'html'               => array( '#fff"><script>', false ),
		);
	}

	/**
	 * @dataProvider colors
	 */
	public function test_color_values( string $value, bool $valid ): void {
		$this->assertSame( $valid, DiviModuleCatalog::is_color( $value ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function sizes(): array {
		return array(
			'px'        => array( '16px', true ),
			'em'        => array( '1.5em', true ),
			'rem'       => array( '2rem', true ),
			'percent'   => array( '50%', true ),
			'vw'        => array( '100vw', true ),
			'vh'        => array( '80vh', true ),
			'negative'  => array( '-10px', true ),
			'zero'      => array( '0', true ),
			'no unit'   => array( '10', false ),
			'pt'        => array( '10pt', false ),
			'calc'      => array( 'calc(1px + 2px)', false ),
			'two'       => array( '1px 2px', false ),
			'important' => array( '1px!important', false ),
			'empty'     => array( '', false ),
		);
	}

	/**
	 * @dataProvider sizes
	 */
	public function test_size_values( string $value, bool $valid ): void {
		$this->assertSame( $valid, DiviModuleCatalog::is_size( $value ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function urls(): array {
		return array(
			'https'        => array( 'https://a.b/x?y=1', true ),
			'http'         => array( 'http://a.b', true ),
			'mailto'       => array( 'mailto:a@b.c', true ),
			'tel'          => array( 'tel:+34123', true ),
			'relative'     => array( '/contacto', true ),
			'bare relative' => array( 'contacto/ok', false ),
			'anchor'       => array( '#top', true ),
			'query'        => array( '?a=1', true ),
			'javascript'   => array( 'javascript:alert(1)', false ),
			'mixed case'   => array( 'JaVaScRiPt:alert(1)', false ),
			'tab'          => array( "java\tscript:alert(1)", false ),
			'entity'       => array( '&#106;avascript:alert(1)', false ),
			'data'         => array( 'data:text/html;base64,AAAA', false ),
			'vbscript'     => array( 'vbscript:x', false ),
			'ftp'          => array( 'ftp://a.b', false ),
			'file'         => array( 'file:///etc/passwd', false ),
			'protocol'     => array( '//evil.test/x', false ),
			'empty'        => array( '', false ),
		);
	}

	/**
	 * @dataProvider urls
	 */
	public function test_url_values( string $value, bool $valid ): void {
		$this->assertSame( $valid, DiviModuleCatalog::is_url( $value ) );
	}
}
