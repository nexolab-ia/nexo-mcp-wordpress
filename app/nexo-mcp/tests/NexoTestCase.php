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
		$this->bearer_context( false );
		$this->grant_admin();
	}

	protected function tearDown(): void {
		$this->bearer_context( false );
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
			Capabilities::BUILDERS_DYNAMIC,
			Capabilities::BUILDERS_COMPONENTS,
			Capabilities::PUBLISH,
			Capabilities::DESTRUCTIVE,
			Capabilities::DESIGN_SYSTEM,
			Capabilities::DIVI_DESIGN,
			Capabilities::DIVI_PRESETS,
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
	 * Hace que el bloqueo del contenido pase a otro dueño justo después de que esta llamada lo obtenga.
	 *
	 * Equivale a que venza y otro proceso lo tome mientras el trabajo corre, antes de escribir.
	 *
	 * @param int $id ID del contenido original (la clave del bloqueo).
	 */
	protected function lose_lock_after_acquiring( int $id ): void {
		\WP_Test_State::$on_forget = static function () use ( $id ): void {
			\WP_Test_State::$options[ 'nexo_mcp_lock_' . $id ] = 'otro-dueno|' . ( time() + 100 );
		};
	}

	/**
	 * Simula una conexión OAuth con los scopes dados.
	 *
	 * @param array<int, string> $scopes Scopes concedidos.
	 */
	protected function connect_with_scopes( array $scopes ): void {
		$this->bearer_context( true, $scopes );
	}

	/**
	 * Fija el contexto de autenticación del guardia sin un token real.
	 *
	 * Se hace por reflexión a propósito: el plugin no expone ninguna API para
	 * escribir este contexto (ver BearerTokenGuardTest).
	 *
	 * @param bool               $authenticated Si la solicitud llegó con token.
	 * @param array<int, string> $scopes        Scopes concedidos.
	 */
	protected function bearer_context( bool $authenticated, array $scopes = array() ): void {
		$values = array(
			'authenticated' => $authenticated,
			'scopes'        => $scopes,
			'failure'       => '',
		);

		foreach ( $values as $property => $value ) {
			( new \ReflectionProperty( BearerTokenGuard::class, $property ) )->setValue( null, $value );
		}
	}
}
