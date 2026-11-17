<?php
/**
 * Base común de las pruebas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Authorization\Capabilities;
use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Reinicia el WordPress simulado y ofrece atajos para preparar escenarios.
 */
abstract class NexoTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\WP_Test_State::reset();
		BearerTokenGuard::assume_context( false );
		$this->grant_admin();
	}

	protected function tearDown(): void {
		BearerTokenGuard::assume_context( false );
		parent::tearDown();
	}

	/**
	 * Da al usuario actual todas las capacidades de un administrador.
	 */
	protected function grant_admin(): void {
		$caps = array(
			Capabilities::CONNECT,
			Capabilities::READ,
			Capabilities::WRITE,
			Capabilities::MANAGE_CREDENTIALS,
			Capabilities::CONTENT_ADVANCED,
			Capabilities::SEO,
			Capabilities::APPEARANCE,
			Capabilities::MAINTENANCE,
			Capabilities::COMMERCE,
			Capabilities::BUILDERS,
			Capabilities::BUILDER_ATTRS,
			Capabilities::PUBLISH,
			Capabilities::DESTRUCTIVE,
			'read',
			'edit_posts',
			'edit_pages',
			'edit_post',
			'publish_posts',
			'publish_pages',
			'delete_post',
			'manage_options',
			'edit_theme_options',
			'edit_products',
			'update_plugins',
			'activate_plugins',
			'install_plugins',
			'upload_files',
			'manage_categories',
		);

		foreach ( $caps as $cap ) {
			\WP_Test_State::$caps[ $cap ] = true;
		}
	}

	/**
	 * Guarda ajustes por encima de los valores por defecto.
	 *
	 * @param array<string, mixed> $overrides Ajustes a cambiar.
	 */
	protected function settings( array $overrides = array() ): Settings {
		update_option( Settings::OPTION_NAME, array_merge( Settings::defaults(), $overrides ) );

		return new Settings();
	}

	/**
	 * Simula una conexión OAuth con los scopes dados.
	 *
	 * @param array<int, string> $scopes Scopes concedidos.
	 */
	protected function connect_with_scopes( array $scopes ): void {
		BearerTokenGuard::assume_context( true, $scopes );
	}
}
