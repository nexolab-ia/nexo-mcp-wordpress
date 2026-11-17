<?php
/**
 * El contexto de autenticación sólo se fija validando un token real.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\OAuth\BearerTokenGuard;

final class BearerTokenGuardTest extends NexoTestCase {

	/**
	 * Cualquier código cargado en WordPress puede llamar a un método público
	 * estático. Si alguno fijara el contexto, otro plugin podría declararse
	 * conectado con todos los scopes sin presentar un token.
	 */
	public function test_la_api_estatica_publica_solo_lee_el_contexto(): void {
		$public_static = array();

		foreach ( ( new \ReflectionClass( BearerTokenGuard::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() ) {
				$public_static[] = $method->getName();
			}
		}

		sort( $public_static );

		$this->assertSame( array( 'is_authenticated', 'scopes' ), $public_static );
	}

	public function test_sin_token_la_solicitud_no_esta_autenticada(): void {
		$this->assertFalse( BearerTokenGuard::is_authenticated() );
		$this->assertSame( array(), BearerTokenGuard::scopes() );
	}
}
