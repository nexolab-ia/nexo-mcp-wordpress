<?php
/**
 * Datos dinámicos en ElementorTreeValidator: rechazo por omisión, identidad con el original y modo asignar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DynamicTagCatalog;
use NexoDev\NexoMCP\Builders\ElementorNesting;
use NexoDev\NexoMCP\Builders\ElementorTreeValidator;
use PHPUnit\Framework\TestCase;

final class ElementorTreeValidatorDynamicTest extends TestCase {

	private function validator(): ElementorTreeValidator {
		$box = array( 'classes', 'tag', 'link', 'attributes', '_cssid' );

		return new ElementorTreeValidator(
			array(
				'e-flexbox'   => $box,
				'e-heading'   => array( 'classes', 'tag', 'title', 'link', 'attributes', '_cssid' ),
				'e-paragraph' => array( 'classes', 'paragraph', 'tag', 'link', 'attributes', '_cssid' ),
				'e-image'     => array( 'classes', 'image', 'link', 'attributes', '_cssid' ),
			),
			array(),
			static fn( int $id ) => true,
			array( 'color' )
		);
	}

	private function catalog( array $registered = array( 'post-title', 'site-title', 'site-logo' ) ): DynamicTagCatalog {
		return new DynamicTagCatalog( static fn(): array => $registered, static fn( string $t ): bool => 'category' === $t );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dyn( string $name = 'post-title', string $group = 'post', array $settings = array() ): array {
		return array(
			'$$type' => 'dynamic',
			'value'  => array(
				'name'     => $name,
				'group'    => $group,
				'settings' => $settings,
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function client( array $prop, string $id = 'abc1234', string $type = 'e-heading', string $key = 'title' ): array {
		return array(
			array(
				'type'     => $type,
				'id'       => $id,
				'settings' => array( $key => $prop ),
			),
		);
	}

	/**
	 * Árbol guardado (formato de Elementor) con un heading dinámico dentro de un contenedor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored( array $prop ): array {
		return array(
			array(
				'id'       => 'box0001',
				'elType'   => 'e-flexbox',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'abc1234',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => array( 'title' => $prop ),
						'elements'   => array(),
					),
				),
			),
		);
	}

	private function check( ElementorTreeValidator $v, array $elements ) {
		return $v->validate( $elements, ElementorNesting::CONTAINER );
	}

	private function assertDenied( $result, string $code, string $path = 'elements[0].settings.title' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( 'path' => $path ), $result->get_error_data() );
	}

	public function test_por_omision_dynamic_se_rechaza_con_el_mensaje_de_siempre(): void {
		$result = $this->check( $this->validator(), $this->client( $this->dyn() ) );

		$this->assertDenied( $result, 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.title.$$type' );
		$this->assertSame( 'elements[0].settings.title.$$type: el tipo de propiedad «dynamic» no está permitido.', $result->get_error_message() );
	}

	public function test_el_opt_in_no_cambia_el_validador_original(): void {
		$v = $this->validator();
		$v->accepting_unchanged_dynamic( $this->stored( $this->dyn() ) );
		$v->assigning_dynamic( $this->catalog() );

		$this->assertDenied( $this->check( $v, $this->client( $this->dyn() ) ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.title.$$type' );
	}

	public function test_una_prop_identica_a_la_del_nodo_original_se_acepta(): void {
		$prop = $this->dyn( 'post-title', 'post', array( 'before' => 'A-' ) );
		$v    = $this->validator()->accepting_unchanged_dynamic( $this->stored( $prop ) );

		$this->assertTrue( $this->check( $v, $this->client( $prop ) ) );

		// Sin importar el orden de las claves.
		$reordered = array(
			'value'  => array(
				'settings' => array( 'before' => 'A-' ),
				'group'    => 'post',
				'name'     => 'post-title',
			),
			'$$type' => 'dynamic',
		);
		$this->assertTrue( $this->check( $v, $this->client( $reordered ) ) );
	}

	public function test_identica_acepta_aunque_el_tag_no_este_en_el_catalogo(): void {
		$legacy = $this->dyn( 'shortcode', 'site', array( 'shortcode' => '[x]' ) );
		$v      = $this->validator()->accepting_unchanged_dynamic( $this->stored( $legacy ) );

		$this->assertTrue( $this->check( $v, $this->client( $legacy ) ) );
	}

	public function test_una_prop_cambiada_se_rechaza_fuera_del_modo_asignar(): void {
		$v = $this->validator()->accepting_unchanged_dynamic( $this->stored( $this->dyn( 'post-title', 'post', array( 'before' => 'A-' ) ) ) );

		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'post-title', 'post', array( 'before' => 'B-' ) ) ) ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.title.$$type' );
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'site-title', 'site' ) ) ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.title.$$type' );
	}

	public function test_identidad_exige_el_mismo_id_widget_y_clave(): void {
		$prop = $this->dyn();
		$v    = $this->validator()->accepting_unchanged_dynamic( $this->stored( $prop ) );
		$deny = 'nexo_mcp_elementor_prop_type_denied';

		// Otro id, sin id, otro widget con el mismo id.
		$this->assertDenied( $this->check( $v, $this->client( $prop, 'zzz9999' ) ), $deny, 'elements[0].settings.title.$$type' );
		$sin_id = $this->client( $prop );
		unset( $sin_id[0]['id'] );
		$this->assertDenied( $this->check( $v, $sin_id ), $deny, 'elements[0].settings.title.$$type' );
		$this->assertDenied( $this->check( $v, $this->client( $prop, 'abc1234', 'e-paragraph', 'paragraph' ) ), $deny, 'elements[0].settings.paragraph.$$type' );
	}

	public function test_el_original_sin_dynamic_no_habilita_nada(): void {
		$v = $this->validator()->accepting_unchanged_dynamic( $this->stored( array( '$$type' => 'string', 'value' => 'x' ) ) );

		$this->assertDenied( $this->check( $v, $this->client( $this->dyn() ) ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.title.$$type' );
	}

	public function test_modo_asignar_acepta_una_prop_nueva_validada_por_el_catalogo(): void {
		$v = $this->validator()->assigning_dynamic( $this->catalog() );

		$this->assertTrue( $this->check( $v, $this->client( $this->dyn( 'post-title', 'post', array( 'before' => 'X' ) ) ) ) );
		$this->assertTrue( $this->check( $v, $this->client( $this->dyn( 'site-logo', 'site' ), 'n1', 'e-image', 'image' ) ) );
		// Sin id de nodo también.
		$sin_id = $this->client( $this->dyn() );
		unset( $sin_id[0]['id'] );
		$this->assertTrue( $this->check( $v, $sin_id ) );
	}

	public function test_modo_asignar_acepta_una_prop_cambiada_respecto_del_original(): void {
		$v = $this->validator()
			->accepting_unchanged_dynamic( $this->stored( $this->dyn( 'post-title', 'post', array( 'before' => 'A-' ) ) ) )
			->assigning_dynamic( $this->catalog() );

		$this->assertTrue( $this->check( $v, $this->client( $this->dyn( 'site-title', 'site' ) ) ) );
	}

	public function test_modo_asignar_rechaza_lo_que_el_catalogo_rechaza_con_su_codigo_y_la_ruta(): void {
		$v = $this->validator()->assigning_dynamic( $this->catalog() );

		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'shortcode', 'site' ) ) ), 'nexo_mcp_dynamic_tag_unknown' );
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'post-title', 'site' ) ) ), 'nexo_mcp_dynamic_group_mismatch' );
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'post-title', 'post', array( 'x' => 1 ) ) ) ), 'nexo_mcp_dynamic_setting_unknown' );
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'site-logo', 'site' ) ) ), 'nexo_mcp_dynamic_category_mismatch' );
		$this->assertDenied( $this->check( $this->validator()->assigning_dynamic( $this->catalog( array() ) ), $this->client( $this->dyn() ) ), 'nexo_mcp_dynamic_tag_unavailable' );

		$result = $this->check( $v, $this->client( $this->dyn( 'shortcode', 'site' ) ) );
		$this->assertStringStartsWith( 'elements[0].settings.title: el tag «shortcode» no está permitido.', $result->get_error_message() );
	}

	public function test_modo_asignar_no_abre_dynamic_anidado_ni_en_estilos_ni_en_props_no_destino(): void {
		$v = $this->validator()->assigning_dynamic( $this->catalog() );

		// Anidado dentro de otro valor.
		$nested = $this->client( array( '$$type' => 'link', 'value' => array( 'destination' => $this->dyn() ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->check( $v, $nested ) );

		// En un estilo local.
		$styled = $this->client( array( '$$type' => 'string', 'value' => 'x' ) );
		$styled[0]['styles'] = array(
			's1' => array(
				'id'       => 's1',
				'type'     => 'class',
				'label'    => 's1',
				'variants' => array(
					array(
						'meta'  => array( 'breakpoint' => 'desktop', 'state' => null ),
						'props' => array( 'color' => $this->dyn() ),
					),
				),
			),
		);
		$this->assertDenied( $this->check( $v, $styled ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].styles.s1.variants[0].props.color.$$type' );

		// Prop que no es destino de dynamic.
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn(), 'abc1234', 'e-heading', 'tag' ) ), 'nexo_mcp_dynamic_target_unsupported', 'elements[0].settings.tag' );
		// Link (url) queda fuera.
		$this->assertDenied( $this->check( $v, $this->client( $this->dyn( 'post-url', 'post' ), 'abc1234', 'e-heading', 'link' ) ), 'nexo_mcp_dynamic_target_unsupported', 'elements[0].settings.link' );
	}

	public function test_el_resto_de_props_sigue_validandose_con_el_opt_in(): void {
		$v = $this->validator()->assigning_dynamic( $this->catalog() );

		$tree                         = $this->client( $this->dyn() );
		$tree[0]['settings']['tag']   = array( '$$type' => 'html', 'value' => 'x' );

		$this->assertDenied( $this->check( $v, $tree ), 'nexo_mcp_elementor_prop_type_denied', 'elements[0].settings.tag.$$type' );
	}
}
