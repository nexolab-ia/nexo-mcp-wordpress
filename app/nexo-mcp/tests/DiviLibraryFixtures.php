<?php
/**
 * Escenario común de las pruebas de la biblioteca de Divi 5: ítems de cada tipo con sus términos y metas, y las herramientas
 * registradas.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\Builders\Divi5BlockBuilder;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;

/**
 * Para pruebas de proceso aparte (definen `ET_BUILDER_5_DIR`). La clase que lo usa implementa `DiviLibraryIds`.
 */
trait DiviLibraryFixtures {

	/**
	 * @param bool $divi5   Si se define `ET_BUILDER_5_DIR` (Divi 5 activo). Una constante no se puede quitar.
	 * @param bool $creator Si existe `et_pb_create_layout()` (Divi cargado).
	 */
	protected function prepare_library( bool $divi5 = true, bool $creator = true ): void {
		if ( $divi5 && ! defined( 'ET_BUILDER_5_DIR' ) ) {
			define( 'ET_BUILDER_5_DIR', '/tmp/divi5' );
		}
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			define( 'ET_BUILDER_VERSION', '5.19.1' );
		}

		require_once __DIR__ . '/DiviLayoutWpStubs.php';
		require_once __DIR__ . '/DiviTemplateWpStubs.php';
		require_once __DIR__ . '/DiviLibraryWpStubs.php';

		if ( $creator ) {
			require_once __DIR__ . '/DiviLibraryCreatorStub.php';
		}

		\WP_Library_Stub_State::$create_calls  = array();
		\WP_Library_Stub_State::$term_calls    = array();
		\WP_Library_Stub_State::$fail_terms_in = '';
		\WP_Library_Stub_State::$throw_after_insert = false;

		\WP_Test_State::$posts[ self::PAGE ] = new \WP_Post( array( 'ID' => self::PAGE, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio' ) );

		$this->put_item( self::SECTION, 'Sección azul', 'section', 'non_global', 'publish', null, array( 'Héroes' ) );
		$this->put_item( self::ROW, 'Fila', 'row' );
		$this->put_item( self::MODULE, 'Texto', 'module', 'non_global', 'publish', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );
		$this->put_item( self::GLOBAL, 'Módulo global', 'module', 'global', 'publish', null, array(), array( '_et_pb_module_type' => 'divi/text' ) );
		$this->put_item( self::TRASHED, 'En la papelera', 'section', 'non_global', 'trash' );
		$this->put_item( self::AUTO_DRAFT, 'Borrador automático', 'section', 'non_global', 'auto-draft' );
		$this->put_item(
			self::FOREIGN,
			'Con menú',
			'section',
			'non_global',
			'publish',
			'<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/fullwidth-menu {"builderVersion":"5.19.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
		);
	}

	/**
	 * Bloques de Divi 5 serializados bajo un `divi/placeholder`.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodos.
	 */
	protected function blocks_for( array $nodes ): string {
		return serialize_blocks( Divi5BlockBuilder::build( array( array( 'type' => 'divi/placeholder', 'children' => $nodes ) ), '5.19.1' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function text_node( string $text = 'Hola' ): array {
		return array( 'type' => 'divi/text', 'attrs' => array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>' . $text . '</p>' ) ) ) ) );
	}

	/**
	 * Una sección completa (sección > fila > columna > texto).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function section_nodes( string $text = 'Hola' ): array {
		return array( array( 'type' => 'divi/section', 'children' => array( array( 'type' => 'divi/row', 'children' => array( array( 'type' => 'divi/column', 'children' => array( $this->text_node( $text ) ) ) ) ) ) ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function row_nodes( string $text = 'Hola' ): array {
		return $this->section_nodes( $text )[0]['children'];
	}

	/**
	 * Crea un ítem de la biblioteca con los términos y metas con que lo guarda Divi.
	 *
	 * @param array<int, string>   $categories Categorías.
	 * @param array<string, mixed> $metas      Metas que se suman.
	 */
	protected function put_item( int $id, string $title, string $type = 'section', string $scope = 'non_global', string $status = 'publish', ?string $content = null, array $categories = array(), array $metas = array() ): void {
		$nodes = array(
			'layout'  => $this->section_nodes( $title ),
			'section' => $this->section_nodes( $title ),
			'row'     => $this->row_nodes( $title ),
			'module'  => array( $this->text_node( $title ) ),
		)[ $type ];

		\WP_Test_State::$posts[ $id ] = new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'et_pb_layout',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content ?? $this->blocks_for( $nodes ),
			)
		);

		\WP_Test_State::$terms[ $id ] = array(
			'layout_type'  => array( $type => 10 ),
			'scope'        => array( $scope => 20 ),
			'module_width' => array( 'regular' => 30 ),
		);

		if ( array() !== $categories ) {
			\WP_Test_State::$terms[ $id ]['layout_category'] = array_combine( $categories, range( 40, 39 + count( $categories ) ) );
		}

		foreach ( array_merge( array( '_et_pb_template_type' => $type, '_et_pb_use_divi_5' => 'on' ), $metas ) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, array<string, mixed>>
	 */
	protected function tools( array $settings = array() ): array {
		\WP_Test_State::$abilities = array();
		$settings                  = $this->settings( array_merge( array( 'read_only' => false, self::SWITCH => true ), $settings ) );

		( new ExtendedAbilityRegistrar(
			$settings,
			new PermissionPolicy( $settings ),
			new ToolExecutor( new AuditLogger( new AuditRepository() ) ),
			new ContentAdminGateway( $settings ),
			new SeoGateway(),
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $settings )
		) )->register_abilities();

		return \WP_Test_State::$abilities;
	}

	/**
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return array<string, mixed>
	 */
	protected function tool( string $name, array $settings = array() ): array {
		return $this->tools( $settings )[ 'nexo-mcp/' . $name ];
	}

	/**
	 * Ejecuta una herramienta.
	 *
	 * @param array<string, mixed> $input    Entrada.
	 * @param array<string, mixed> $settings Ajustes que cambian.
	 * @return mixed
	 */
	protected function run_tool( string $name, array $input = array(), array $settings = array() ) {
		return $this->tool( $name, $settings )['execute_callback']( $input );
	}

	protected function snapshot(): string {
		$options = \WP_Test_State::$options;

		unset( $options[ Settings::OPTION_NAME ] );

		return serialize( array( \WP_Test_State::$posts, \WP_Test_State::$meta, \WP_Test_State::$multi_meta, \WP_Test_State::$terms, $options ) );
	}

	protected function assertError( $result, string $code ): \WP_Error {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}
}
