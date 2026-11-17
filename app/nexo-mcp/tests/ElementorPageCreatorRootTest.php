<?php
/**
 * create-elementor-page comparte la regla de raíz: un widget suelto en la raíz ya no se crea.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorPageCreator;

final class ElementorPageCreatorRootTest extends NexoTestCase {

	public function test_un_widget_en_la_raiz_se_rechaza_y_no_crea_nada(): void {
		$creator = new ElementorPageCreator( null, new FakeSchemaGuard() );

		$result = $creator->create( array( 'title' => 'Landing', 'elements' => array( ElementorSectionFixtures::new_heading() ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $result->get_error_code() );
		$this->assertStringStartsWith( 'elements[0].type: en la raíz de la página sólo se admiten contenedores atómicos', $result->get_error_message() );
		$this->assertSame( array(), \WP_Test_State::$posts );
	}

	public function test_un_contenedor_en_la_raiz_pasa_la_validacion_y_llega_a_guardar(): void {
		$creator = new ElementorPageCreator( null, new FakeSchemaGuard() );

		// Sin Elementor no hay documentos que guardar: el rechazo viene del guardado, no de la validación.
		$result = $creator->create( array( 'title' => 'Landing', 'elements' => ElementorSectionFixtures::new_box() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_save_failed', $result->get_error_code() );
		$this->assertSame( array(), \WP_Test_State::$posts, 'el borrador creado se deshizo' );
	}
}
