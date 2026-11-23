<?php
/**
 * Instancias e-component en ElementorTreeValidator: rechazo por omisión y opt-in con forma exacta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorNesting;
use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use PHPUnit\Framework\TestCase;

final class ElementorTreeValidatorComponentTest extends TestCase {

	private function validator(): ElementorTreeValidator {
		return ( new FakeSchemaGuard() )->validator();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function instance( $id = 7, ?array $overrides = null ): array {
		$value = array( 'component_id' => array( '$$type' => 'number', 'value' => $id ) );

		if ( null !== $overrides ) {
			$value['overrides'] = $overrides;
		}

		return array(
			'$$type' => 'component-instance',
			'value'  => $value,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function node( array $instance = array() ): array {
		return array(
			array(
				'type'     => 'e-component',
				'settings' => array( 'component_instance' => array() === $instance ? self::instance() : $instance ),
			),
		);
	}

	private function opted( ?callable $exists = null ): ElementorTreeValidator {
		return $this->validator()->accepting_components( $exists ?? static fn( int $id ): bool => 7 === $id );
	}

	private function check( ElementorTreeValidator $v, array $elements ) {
		return $v->validate( $elements, ElementorNesting::CONTAINER );
	}

	public function test_por_omision_e_component_se_rechaza_como_tipo_no_permitido(): void {
		$result = $this->check( $this->validator(), self::node() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_type_denied', $result->get_error_code() );
		$this->assertSame( array( 'path' => 'elements[0].type' ), $result->get_error_data() );
	}

	public function test_el_opt_in_no_cambia_el_validador_original(): void {
		$v = $this->validator();
		$v->accepting_components( static fn(): bool => true );

		$this->assertSame( 'nexo_mcp_elementor_type_denied', $this->check( $v, self::node() )->get_error_code() );
	}

	public function test_con_el_opt_in_acepta_la_instancia_sin_overrides_o_con_overrides_vacios(): void {
		$this->assertTrue( $this->check( $this->opted(), self::node() ) );
		$this->assertTrue( $this->check( $this->opted(), self::node( self::instance( 7, array( '$$type' => 'overrides', 'value' => array() ) ) ) ) );
	}

	public function test_con_el_opt_in_el_resto_de_tipos_sigue_igual(): void {
		$bad = array( array( 'type' => 'e-svg' ) );

		$this->assertSame( 'nexo_mcp_elementor_type_denied', $this->check( $this->opted(), $bad )->get_error_code() );
		$this->assertTrue( $this->check( $this->opted(), array( array( 'type' => 'e-flexbox', 'elements' => array( array( 'type' => 'e-component', 'settings' => array( 'component_instance' => self::instance() ) ) ) ) ) ) );
	}

	/**
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: string}>
	 */
	public static function rejected(): array {
		$other = array_merge( self::instance(), array( 'extra' => 1 ) );

		return array(
			'otra clave en settings'                  => array( array( array( 'type' => 'e-component', 'settings' => array( 'component_instance' => self::instance(), 'classes' => array( '$$type' => 'classes', 'value' => array() ) ) ) ), 'nexo_mcp_elementor_setting_unknown' ),
			'sin settings'                            => array( array( array( 'type' => 'e-component' ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'settings vacíos'                         => array( array( array( 'type' => 'e-component', 'settings' => array() ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'otro $$type'                             => array( self::node( array( '$$type' => 'string', 'value' => 'x' ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'clave extra en la instancia'             => array( self::node( $other ), 'nexo_mcp_elementor_setting_invalid' ),
			'clave extra en value'                    => array( self::node( array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => 7 ), 'uid' => 'x' ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'sin component_id'                        => array( self::node( array( '$$type' => 'component-instance', 'value' => array() ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'overrides con un ítem'                   => array( self::node( self::instance( 7, array( '$$type' => 'overrides', 'value' => array( array( '$$type' => 'override', 'value' => array() ) ) ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'overrides de otro tipo'                  => array( self::node( self::instance( 7, array( '$$type' => 'string', 'value' => array() ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'overrides vacío sin forma'               => array( self::node( self::instance( 7, array() ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'overrides con clave extra'               => array( self::node( self::instance( 7, array( '$$type' => 'overrides', 'value' => array(), 'x' => 1 ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id como cadena'                          => array( self::node( self::instance( '7' ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id decimal'                              => array( self::node( self::instance( 7.0 ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id booleano'                             => array( self::node( self::instance( true ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id cero'                                 => array( self::node( self::instance( 0 ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id negativo'                             => array( self::node( self::instance( -7 ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id con otro $$type'                      => array( self::node( array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'string', 'value' => 7 ) ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'id con clave extra'                      => array( self::node( array( '$$type' => 'component-instance', 'value' => array( 'component_id' => array( '$$type' => 'number', 'value' => 7, 'x' => 1 ) ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
			'componente inexistente'                  => array( self::node( self::instance( 99 ) ), 'nexo_mcp_component_not_found' ),
			'con hijos'                               => array( array( array( 'type' => 'e-component', 'settings' => array( 'component_instance' => self::instance() ), 'elements' => array( array( 'type' => 'e-heading' ) ) ) ), 'nexo_mcp_elementor_children_denied' ),
			'con estilos'                             => array( array( array( 'type' => 'e-component', 'settings' => array( 'component_instance' => self::instance() ), 'styles' => array( 'a' => array( 'variants' => array() ) ) ) ), 'nexo_mcp_elementor_setting_invalid' ),
		);
	}

	/**
	 * @dataProvider rejected
	 *
	 * @param array<int, array<string, mixed>> $elements Árbol.
	 */
	public function test_rechaza_cualquier_otra_forma_con_su_codigo(array $elements, string $code): void {
		$result = $this->check( $this->opted(), $elements );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringStartsWith( 'elements[0]', $result->get_error_message() );
	}

	public function test_en_la_raiz_de_una_pagina_no_cabe(): void {
		$result = $this->opted()->validate( self::node(), ElementorNesting::ROOT );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $result->get_error_code() );
	}
}
