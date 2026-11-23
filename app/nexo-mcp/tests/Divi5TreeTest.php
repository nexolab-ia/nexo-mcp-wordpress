<?php
/**
 * Árbol de nodos de Divi 5 (ayudante puro).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\Divi5Tree;
use PHPUnit\Framework\TestCase;

final class Divi5TreeTest extends TestCase {

	/**
	 * Página con sección, fila, dos columnas y hojas.
	 */
	public static function page(): string {
		return '<!-- wp:divi/placeholder -->' . "\n"
			. '<!-- wp:divi/section {"builderVersion":"5.19.1","module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} -->' . "\n"
			. '<!-- wp:divi/row {"builderVersion":"5.19.1"} -->' . "\n"
			. '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"2_3"}}}}} -->' . "\n"
			. '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hola <strong>mundo</strong></p>"}}}} /-->' . "\n"
			. '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Comprar","linkUrl":"https://ex.test/tienda"}}}}} /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- wp:divi/column -->' . "\n"
			. '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"https://ex.test/a.jpg","alt":"Una foto"}}}}} /-->' . "\n"
			. '<!-- /wp:divi/column -->' . "\n"
			. '<!-- /wp:divi/row -->' . "\n"
			. '<!-- /wp:divi/section -->' . "\n"
			. '<!-- /wp:divi/placeholder -->';
	}

	public function test_builds_nested_tree_with_paths(): void {
		$tree = Divi5Tree::build( self::page() );

		$this->assertCount( 1, $tree );
		$this->assertSame( '0', $tree[0]['path'] );
		$this->assertSame( 'divi/placeholder', $tree[0]['type'] );
		$section = $tree[0]['children'][0];
		$this->assertSame( '0.0', $section['path'] );
		$this->assertSame( 'divi/section', $section['type'] );
		$this->assertSame( 'Hero', $section['label'] );
		$row = $section['children'][0];
		$this->assertSame( '0.0.0', $row['path'] );
		$this->assertSame( '0.0.0.1.0', $row['children'][1]['children'][0]['path'] );
		$this->assertSame( 'divi/image', $row['children'][1]['children'][0]['type'] );
	}

	public function test_leaf_text_link_and_image(): void {
		$col  = Divi5Tree::locate( Divi5Tree::build( self::page() ), '0.0.0.0' );
		$text = $col['children'][0];
		$btn  = $col['children'][1];
		$img  = Divi5Tree::locate( Divi5Tree::build( self::page() ), '0.0.0.1.0' );

		$this->assertSame( 'Hola mundo', $text['text'] );
		$this->assertArrayNotHasKey( 'children', $text );
		$this->assertSame( 'Comprar', $btn['text'] );
		$this->assertSame( 'https://ex.test/tienda', $btn['link'] );
		$this->assertSame( array( 'src' => 'https://ex.test/a.jpg', 'alt' => 'Una foto' ), $img['image'] );
		$this->assertArrayNotHasKey( 'link', $text );
		$this->assertArrayNotHasKey( 'label', $text );
	}

	public function test_title_text(): void {
		$tree = Divi5Tree::build( '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"<b>Título</b>"}}}} /-->' );

		$this->assertSame( 'Título', $tree[0]['text'] );
	}

	public function test_whitespace_freeform_blocks_are_skipped_in_paths(): void {
		$content = "\n\n" . '<!-- wp:divi/section /-->' . "\n\n" . '<!-- wp:divi/section /-->' . "\n";
		$tree    = Divi5Tree::build( $content );

		$this->assertSame( array( '0', '1' ), array_column( $tree, 'path' ) );
		$this->assertNotNull( Divi5Tree::locate( $tree, '1' ) );
		$this->assertSame( 'divi/section', Divi5Tree::locate_block( parse_blocks( $content ), '1' )['blockName'] );
	}

	public function test_fingerprint_is_sha256_of_serialized_node_and_changes_with_it(): void {
		$blocks = parse_blocks( self::page() );
		$tree   = Divi5Tree::build( self::page() );
		$leaf   = Divi5Tree::locate_block( $blocks, '0.0.0.0.0' );

		$this->assertSame( hash( 'sha256', serialize_block( $leaf ) ), Divi5Tree::locate( $tree, '0.0.0.0.0' )['fingerprint'] );
		$this->assertSame( 64, strlen( $tree[0]['fingerprint'] ) );

		$changed = str_replace( 'Hola', 'Adiós', self::page() );
		$other   = Divi5Tree::build( $changed );

		$this->assertNotSame( Divi5Tree::locate( $tree, '0.0.0.0.0' )['fingerprint'], Divi5Tree::locate( $other, '0.0.0.0.0' )['fingerprint'] );
		$this->assertNotSame( $tree[0]['fingerprint'], $other[0]['fingerprint'] );
		$this->assertSame(
			Divi5Tree::locate( $tree, '0.0.0.1' )['fingerprint'],
			Divi5Tree::locate( $other, '0.0.0.1' )['fingerprint']
		);
	}

	public function test_locate_misses_return_null(): void {
		$tree = Divi5Tree::build( self::page() );

		foreach ( array( '', '9', '0.5', '0.0.x', '0.0.0.0.0.0', '-1' ) as $path ) {
			$this->assertNull( Divi5Tree::locate( $tree, $path ), $path );
		}
	}

	public function test_check_fingerprint(): void {
		$tree = Divi5Tree::build( self::page() );
		$fp   = Divi5Tree::locate( $tree, '0.0.0.0.1' )['fingerprint'];

		$this->assertTrue( Divi5Tree::fingerprint_matches( $tree, '0.0.0.0.1', $fp ) );
		$this->assertFalse( Divi5Tree::fingerprint_matches( $tree, '0.0.0.0.1', str_repeat( '0', 64 ) ) );
		$this->assertFalse( Divi5Tree::fingerprint_matches( $tree, '7.7', $fp ) );
		$this->assertFalse( Divi5Tree::fingerprint_matches( $tree, '0.0.0.0.1', '' ) );
	}

	public function test_empty_and_non_divi_content(): void {
		$this->assertSame( array(), Divi5Tree::build( '' ) );
		$this->assertTrue( Divi5Tree::is_divi5( self::page() ) );
		$this->assertFalse( Divi5Tree::is_divi5( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$this->assertFalse( Divi5Tree::is_divi5( '[et_pb_section][/et_pb_section]' ) );
		$this->assertFalse( Divi5Tree::is_divi5( 'texto <!-- wp:paragraph {"x":"wp:divi/"} /-->' ) );
	}
}
