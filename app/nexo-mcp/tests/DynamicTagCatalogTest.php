<?php
/**
 * Catálogo cerrado de datos dinámicos de Elementor (clase pura, sin Elementor).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\DynamicTagCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cada rechazo se comprueba con su código; la prop normalizada, con su forma exacta.
 */
final class DynamicTagCatalogTest extends TestCase {

	/**
	 * Catálogo con todos los tags registrados y las taxonomías `category` y `post_tag` públicas.
	 *
	 * @param array<int, string>|null $registered Tags registrados; null = todos los del catálogo.
	 */
	private function catalog( ?array $registered = null ): DynamicTagCatalog {
		return new DynamicTagCatalog(
			static fn(): array => $registered ?? DynamicTagCatalog::names(),
			static fn( string $name ): bool => in_array( $name, array( 'category', 'post_tag' ), true )
		);
	}

	/**
	 * @param array<string, mixed>|null $settings Ajustes; null = sin la clave.
	 * @return array<string, mixed>
	 */
	private function dyn( string $name, string $group, ?array $settings = array() ): array {
		$value = array(
			'name'  => $name,
			'group' => $group,
		);

		if ( null !== $settings ) {
			$value['settings'] = $settings;
		}

		return array(
			'$$type' => 'dynamic',
			'value'  => $value,
		);
	}

	private function assertCode( string $code, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_el_catalogo_tiene_exactamente_los_19_tags_de_S8(): void {
		$this->assertSame(
			array(
				'site-title', 'site-tagline', 'page-title', 'post-title', 'post-excerpt', 'post-date', 'post-time', 'post-terms',
				'post-id', 'archive-title', 'author-name', 'current-date-time', 'comments-number',
				'site-url', 'post-url', 'archive-url', 'comments-url', 'site-logo', 'post-featured-image',
			),
			DynamicTagCatalog::names()
		);

		foreach ( array( 'shortcode', 'request-arg', 'user-info', 'author-meta', 'post-custom-field', 'acf-text' ) as $denied ) {
			$this->assertNotContains( $denied, DynamicTagCatalog::names() );
		}
	}

	public function test_normaliza_un_tag_de_texto_con_ajustes(): void {
		$result = $this->catalog()->validate(
			'e-heading',
			'title',
			$this->dyn( 'post-title', 'post', array( 'before' => ' ANTES- ', 'after' => '-DESPUES' ) )
		);

		$this->assertSame(
			array(
				'$$type' => 'dynamic',
				'value'  => array(
					'name'     => 'post-title',
					'group'    => 'post',
					'settings' => array( 'before' => 'ANTES-', 'after' => '-DESPUES' ),
				),
			),
			$result
		);
	}

	public function test_settings_ausente_equivale_a_vacio(): void {
		$result = $this->catalog()->validate( 'e-paragraph', 'paragraph', $this->dyn( 'site-title', 'site', null ) );

		$this->assertSame( array(), $result['value']['settings'] );
	}

	public function test_todos_los_destinos_aceptan_su_categoria(): void {
		$c = $this->catalog();

		$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'site-tagline', 'site' ) ) );
		$this->assertIsArray( $c->validate( 'e-paragraph', 'paragraph', $this->dyn( 'archive-title', 'archive' ) ) );
		$this->assertIsArray( $c->validate( 'e-button', 'text', $this->dyn( 'author-name', 'author' ) ) );
		$this->assertIsArray( $c->validate( 'e-image', 'image', $this->dyn( 'post-featured-image', 'post' ) ) );
		$this->assertIsArray( $c->validate( 'e-image', 'image', $this->dyn( 'site-logo', 'site' ) ) );
	}

	public function test_destino_no_admitido(): void {
		$c = $this->catalog();

		$this->assertCode( 'nexo_mcp_dynamic_target_unsupported', $c->validate( 'e-heading', 'tag', $this->dyn( 'post-title', 'post' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_target_unsupported', $c->validate( 'e-divider', 'title', $this->dyn( 'post-title', 'post' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_target_unsupported', $c->validate( 'e-image', 'title', $this->dyn( 'post-title', 'post' ) ) );
		// `link` (categoría url) queda fuera de T2.
		$this->assertCode( 'nexo_mcp_dynamic_target_unsupported', $c->validate( 'e-button', 'link', $this->dyn( 'post-url', 'post' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_target_unsupported', $c->validate( 'e-heading', 'link', $this->dyn( 'post-url', 'post' ) ) );
	}

	public function test_forma_invalida(): void {
		$c = $this->catalog();

		foreach (
			array(
				'texto'            => 'post-title',
				'otro tipo'        => array( '$$type' => 'string', 'value' => 'x' ),
				'sin value'        => array( '$$type' => 'dynamic' ),
				'value lista'      => array( '$$type' => 'dynamic', 'value' => array( 'post-title' ) ),
				'clave de más'     => array( '$$type' => 'dynamic', 'value' => array( 'name' => 'post-title', 'group' => 'post', 'x' => 1 ) ),
				'clave fuera'      => array( '$$type' => 'dynamic', 'extra' => 1, 'value' => array( 'name' => 'post-title', 'group' => 'post' ) ),
				'name no texto'    => array( '$$type' => 'dynamic', 'value' => array( 'name' => 5, 'group' => 'post' ) ),
				'sin group'        => array( '$$type' => 'dynamic', 'value' => array( 'name' => 'post-title' ) ),
				'settings texto'   => $this->dyn( 'post-title', 'post' ) + array(),
			) as $label => $value
		) {
			if ( 'settings texto' === $label ) {
				$value['value']['settings'] = 'x';
			}

			$this->assertCode( 'nexo_mcp_dynamic_value_invalid', $c->validate( 'e-heading', 'title', $value ) );
		}

		$list = $this->dyn( 'post-title', 'post', array( 'a' ) );
		$this->assertCode( 'nexo_mcp_dynamic_value_invalid', $c->validate( 'e-heading', 'title', $list ) );
	}

	public function test_tag_desconocido(): void {
		foreach ( array( 'shortcode', 'request-arg', 'user-info', 'author-meta', 'post-custom-field', 'acf-text', 'POST-TITLE', '' ) as $name ) {
			$this->assertCode( 'nexo_mcp_dynamic_tag_unknown', $this->catalog()->validate( 'e-heading', 'title', $this->dyn( $name, 'post' ) ) );
		}
	}

	public function test_grupo_que_no_corresponde(): void {
		$this->assertCode( 'nexo_mcp_dynamic_group_mismatch', $this->catalog()->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'site' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_group_mismatch', $this->catalog()->validate( 'e-heading', 'title', $this->dyn( 'site-title', '' ) ) );
	}

	public function test_categoria_que_no_corresponde_a_la_prop(): void {
		$c = $this->catalog();

		// url y imagen en una prop de texto; texto e url en la imagen.
		$this->assertCode( 'nexo_mcp_dynamic_category_mismatch', $c->validate( 'e-heading', 'title', $this->dyn( 'post-url', 'post' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_category_mismatch', $c->validate( 'e-button', 'text', $this->dyn( 'site-logo', 'site' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_category_mismatch', $c->validate( 'e-image', 'image', $this->dyn( 'post-title', 'post' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_category_mismatch', $c->validate( 'e-image', 'image', $this->dyn( 'site-url', 'site' ) ) );
	}

	public function test_tag_del_catalogo_no_registrado_en_el_sitio(): void {
		$c = $this->catalog( array( 'site-title' ) );

		$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'site-title', 'site' ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_tag_unavailable', $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post' ) ) );
		$this->assertSame( array( 'site-title' ), $c->available() );
		$this->assertSame( array(), $this->catalog( array() )->available() );
		// Un tag registrado pero fuera del catálogo no cuenta.
		$this->assertSame( array(), $this->catalog( array( 'shortcode' ) )->available() );
	}

	public function test_ajuste_desconocido(): void {
		$c = $this->catalog();

		$this->assertCode( 'nexo_mcp_dynamic_setting_unknown', $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'foo' => 'x' ) ) ) );
		// Los tags de url e imagen no tienen ajustes; `fallback` (media) de la imagen destacada tampoco.
		$this->assertCode( 'nexo_mcp_dynamic_setting_unknown', $c->validate( 'e-image', 'image', $this->dyn( 'post-featured-image', 'post', array( 'fallback' => array( 'url' => 'https://x.test/a.png' ) ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_unknown', $c->validate( 'e-image', 'image', $this->dyn( 'site-logo', 'site', array( 'before' => 'x' ) ) ) );
		// Ajuste de otro tag.
		$this->assertCode( 'nexo_mcp_dynamic_setting_unknown', $c->validate( 'e-heading', 'title', $this->dyn( 'site-title', 'site', array( 'max_length' => 5 ) ) ) );
	}

	public function test_textos_planos_acotados_y_sin_html(): void {
		$c = $this->catalog();

		$ok = $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'before' => '<b>Hola</b>' ) ) );
		$this->assertSame( 'Hola', $ok['value']['settings']['before'] );

		$max = str_repeat( 'a', DynamicTagCatalog::MAX_TEXT );
		$this->assertSame( $max, $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'fallback' => $max ) ) )['value']['settings']['fallback'] );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'fallback' => $max . 'a' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'after' => 5 ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-title', 'post', array( 'after' => array( 'x' ) ) ) ) );
	}

	public function test_selects_son_enumeraciones_cerradas(): void {
		$c = $this->catalog();

		foreach ( array( 'post_date_gmt', 'post_modified_gmt' ) as $type ) {
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'post-date', 'post', array( 'type' => $type ) ) ) );
		}

		foreach ( array( 'default', 'human', 'custom' ) as $format ) {
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'post-date', 'post', array( 'format' => $format ) ) ) );
		}

		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-date', 'post', array( 'type' => 'post_date' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-date', 'post', array( 'format' => 'weird' ) ) ) );
		// `human` sólo existe en la fecha, no en la hora.
		$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'post-time', 'post', array( 'format' => 'custom' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-time', 'post', array( 'format' => 'human' ) ) ) );

		foreach ( array( 'default', '', 'F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'custom' ) as $format ) {
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'current-date-time', 'site', array( 'date_format' => $format ) ) ) );
		}

		foreach ( array( 'default', '', 'g:i a', 'g:i A', 'H:i' ) as $format ) {
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'current-date-time', 'site', array( 'time_format' => $format ) ) ) );
		}

		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'current-date-time', 'site', array( 'date_format' => 'Y' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'current-date-time', 'site', array( 'time_format' => 'H' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'current-date-time', 'site', array( 'time_format' => null ) ) ) );

		$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'comments-number', 'comments', array( 'link_to' => '' ) ) ) );
		$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'comments-number', 'comments', array( 'link_to' => 'comments_link' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'comments-number', 'comments', array( 'link_to' => 'https://x.test' ) ) ) );
	}

	public function test_interruptores_yes_o_vacio(): void {
		$c = $this->catalog();

		foreach ( array( 'yes', '' ) as $v ) {
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'link' => $v ) ) ) );
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'archive-title', 'archive', array( 'include_context' => $v ) ) ) );
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'page-title', 'site', array( 'show_home_title' => $v ) ) ) );
			$this->assertIsArray( $c->validate( 'e-heading', 'title', $this->dyn( 'post-excerpt', 'post', array( 'apply_to_post_content' => $v ) ) ) );
		}

		foreach ( array( 'no', true, false, 1, 'YES' ) as $v ) {
			$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'link' => $v ) ) ) );
		}
	}

	public function test_numeros_con_limites(): void {
		$c = $this->catalog();

		foreach ( array( 0, 55, 10000 ) as $n ) {
			$this->assertSame( $n, $c->validate( 'e-heading', 'title', $this->dyn( 'post-excerpt', 'post', array( 'max_length' => $n ) ) )['value']['settings']['max_length'] );
		}

		foreach ( array( -1, 10001, '55', 1.5, null, true ) as $n ) {
			$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-excerpt', 'post', array( 'max_length' => $n ) ) ) );
		}
	}

	public function test_taxonomia_registrada_y_publica_por_funcion_inyectada(): void {
		$c = $this->catalog();

		$this->assertSame( 'category', $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'taxonomy' => 'category' ) ) )['value']['settings']['taxonomy'] );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'taxonomy' => 'nav_menu' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'taxonomy' => '' ) ) ) );
		$this->assertCode( 'nexo_mcp_dynamic_setting_invalid', $c->validate( 'e-heading', 'title', $this->dyn( 'post-terms', 'post', array( 'taxonomy' => array( 'category' ) ) ) ) );
	}

	public function test_el_error_de_ajuste_nombra_el_tag_y_el_ajuste(): void {
		$result = $this->catalog()->validate( 'e-heading', 'title', $this->dyn( 'post-excerpt', 'post', array( 'max_length' => -1 ) ) );

		$this->assertSame( 'settings.post-excerpt.max_length: debe ser un entero entre 0 y 10000.', $result->get_error_message() );
	}
}
