<?php
/**
 * Adaptador de Divi 5 y su lugar en el registro.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\BuilderRegistry;
use NexoDev\NexoMCP\Builders\Divi5Adapter;

final class Divi5AdapterTest extends NexoTestCase {

	private function post( string $content, int $id = 10 ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_content' => $content ) );
	}

	public function test_identity_and_flags(): void {
		$adapter = new Divi5Adapter();

		$this->assertSame( 'divi5', $adapter->slug() );
		$this->assertSame( 'Divi 5', $adapter->label() );
		$this->assertFalse( $adapter->accepts_full_content_write() );
	}

	public function test_handles_only_divi_blocks(): void {
		$adapter = new Divi5Adapter();

		$this->assertTrue( $adapter->handles( $this->post( Divi5TreeTest::page() ) ) );
		$this->assertFalse( $adapter->handles( $this->post( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) ) );
		$this->assertFalse( $adapter->handles( $this->post( '[et_pb_section][/et_pb_section]' ) ) );
		$this->assertFalse( $adapter->handles( $this->post( '' ) ) );
	}

	public function test_read_nodes_expose_path_fingerprint_and_content(): void {
		$nodes = ( new Divi5Adapter() )->read_nodes( $this->post( Divi5TreeTest::page() ) );
		$byid  = array();
		foreach ( $nodes as $node ) {
			$byid[ $node->id ] = $node;
		}

		$this->assertSame( array( '0', '0.0', '0.0.0', '0.0.0.0', '0.0.0.0.0', '0.0.0.0.1', '0.0.0.1', '0.0.0.1.0' ), array_map( 'strval', array_keys( $byid ) ) );
		$text = $byid['0.0.0.0.0'];
		$this->assertSame( 'divi/text', $text->type );
		$this->assertSame( 'Hola mundo', $text->text );
		$this->assertFalse( $text->editable );
		$this->assertSame( 64, strlen( $text->attributes['fingerprint'] ) );
		$this->assertSame( 'https://ex.test/tienda', $byid['0.0.0.0.1']->attributes['link'] );
		$this->assertSame( 'Una foto', $byid['0.0.0.1.0']->attributes['image']['alt'] );
		$this->assertSame( 'Hero', $byid['0.0']->attributes['label'] );
		$this->assertNull( $byid['0.0']->text );
		$this->assertArrayHasKey( 'fingerprint', $byid['0.0']->to_array()['attributes'] );
	}

	public function test_write_methods_do_not_pretend_success(): void {
		$adapter = new Divi5Adapter();
		$post    = $this->post( Divi5TreeTest::page() );

		$this->assertInstanceOf( \WP_Error::class, $adapter->update_node_text( $post, '0.0.0.0.0', 'x' ) );
		$this->assertInstanceOf( \WP_Error::class, $adapter->update_node_attribute( $post, '0.0.0.0.0', 'link', 'x' ) );
	}

	public function test_registry_routes_divi5_before_gutenberg(): void {
		$registry = new BuilderRegistry();

		$this->assertSame( 'divi5', $registry->resolve( $this->post( Divi5TreeTest::page() ) )->slug() );
		$this->assertContains( 'divi5', $registry->supported() );
		$this->assertSame( 'divi5', $registry->by_slug( 'divi5' )->slug() );
		$this->assertLessThan( array_search( 'gutenberg', $registry->supported(), true ), array_search( 'divi5', $registry->supported(), true ) );
	}

	public function test_registry_keeps_divi4_and_gutenberg(): void {
		$registry = new BuilderRegistry();

		$this->assertSame( 'divi', $registry->resolve( $this->post( '[et_pb_section][et_pb_row][et_pb_text]Hola[/et_pb_text][/et_pb_row][/et_pb_section]' ) )->slug() );
		$this->assertSame( 'gutenberg', $registry->resolve( $this->post( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) )->slug() );
	}
}
