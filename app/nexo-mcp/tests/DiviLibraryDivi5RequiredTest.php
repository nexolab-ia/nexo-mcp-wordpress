<?php
/**
 * Las herramientas de la biblioteca sin Divi 5 activo: `nexo_mcp_divi5_required` y no cambian nada. El permiso se comprueba
 * antes (sin él, `nexo_mcp_divi_library_denied`).
 *
 * Corre en un proceso aparte y NO define `ET_BUILDER_5_DIR` (una constante no se puede quitar).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DiviLibraryDivi5RequiredTest extends NexoTestCase implements DiviLibraryIds {

	use DiviLibraryFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->prepare_library( false );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function tools_with_input(): array {
		return array(
			'list' => array( 'list-divi-library', array() ),
			'get'  => array( 'get-divi-library-item', array( 'id' => self::SECTION ) ),
			'create' => array( 'save-divi-library-item', array( 'title' => 'Nuevo', 'type' => 'section', 'nodes' => array( array( 'type' => 'divi/section' ) ) ) ),
			'delete'  => array( 'delete-divi-library-item', array( 'id' => self::SECTION ) ),
			'restore' => array( 'restore-divi-library-item', array( 'id' => self::SECTION ) ),
			'update' => array( 'save-divi-library-item', array( 'id' => self::SECTION, 'title' => 'Nuevo' ) ),
			'insert' => array( 'insert-divi-library-item', array( 'id' => self::SECTION, 'post_id' => self::PAGE, 'path' => '0', 'fingerprint' => 'x', 'position' => 'after' ) ),
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

		$this->assertError( $this->run_tool( $name, $input ), 'nexo_mcp_divi5_required' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( array(), \WP_Library_Stub_State::$create_calls );
	}

	/**
	 * @dataProvider tools_with_input
	 *
	 * @param array<string, mixed> $input Entrada válida.
	 */
	public function test_the_library_permission_is_checked_before_divi_5( string $name, array $input ): void {
		$before = $this->snapshot();

		$this->assertError( $this->run_tool( $name, $input, array( self::SWITCH => false ) ), 'nexo_mcp_divi_library_denied' );
		$this->assertSame( $before, $this->snapshot() );
	}
}
