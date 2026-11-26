<?php
/**
 * Las herramientas de plantillas del Theme Builder sin Divi 5 activo: `nexo_mcp_divi5_required` y no cambian nada. El permiso
 * se comprueba antes (sin él, `nexo_mcp_divi_template_denied`).
 *
 * Corre en un proceso aparte y NO define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DiviTemplateStore;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviTemplateDivi5RequiredTest extends NexoTestCase {

	use DiviTemplateFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_divi_templates( false );
		$this->put_live_tb();
		$this->put_template( 950, 'Plantilla', array( '_et_use_on' => array( 'search' ) ) );
		$store = new DiviTemplateStore();
		$store->save_backup( 950, $store->snapshot( 950, self::TB, 'replace', true ) );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function tools_with_input(): array {
		return array(
			'list'    => array( 'list-divi-theme-builder', array() ),
			'set'     => array( 'set-divi-template', array( 'id' => 950, 'title' => 'Nueva', 'enabled' => true, 'use_on' => array( 'homepage' ), 'body' => array( 'layout_id' => self::BODY ) ) ),
			'create'  => array( 'set-divi-template', array( 'title' => 'Nueva', 'enabled' => true, 'use_on' => array( 'homepage' ) ) ),
			'delete'  => array( 'delete-divi-template', array( 'id' => 950 ) ),
			'restore' => array( 'restore-divi-template', array( 'id' => 950 ) ),
		);
	}

	/**
	 * @dataProvider tools_with_input
	 *
	 * @param array<string, mixed> $input Entrada válida.
	 */
	public function test_without_divi_5_the_tool_answers_divi5_required_and_changes_nothing( string $name, array $input ): void {
		$this->assertFalse( defined( 'ET_BUILDER_5_DIR' ) );
		$before = $this->snapshot();

		$result = $this->tool( $name )['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi5_required', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Layout_Stub_State::$css_calls );
		$this->assertSame( array(), \WP_Layout_Stub_State::$cache_calls );
		$this->assertSame( array(), \WP_Template_Stub_State::$tb_calls );
	}

	/**
	 * @dataProvider tools_with_input
	 *
	 * @param array<string, mixed> $input Entrada válida.
	 */
	public function test_the_theme_builder_permission_is_checked_before_divi_5( string $name, array $input ): void {
		$before = $this->snapshot();

		$result = $this->tool( $name, array( self::SWITCH => false ) )['execute_callback']( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_divi_template_denied', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot() );
	}
}
