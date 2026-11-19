<?php
/**
 * Validación del árbol migrado: nodos atómicos con la validación del plugin y v3 conservados sólo si son idénticos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Builders\ElementorMigrationChecker;
use NexoDev\NexoMCP\Builders\ElementorV3Migrator;
use NexoDev\NexoMCP\Tests\Fakes\FakeV3StyleMapper;

final class ElementorMigrationCheckerTest extends NexoTestCase {

	private FakeSchemaGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->guard              = new FakeSchemaGuard();
		$this->guard->style_props = array( 'flex-direction', 'width', 'gap', 'color', 'justify-content', 'align-items', 'flex-wrap' );
	}

	/**
	 * Página v3: sección > columna > heading, spacer y text-editor con negrita.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function original(): array {
		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							ElementorSectionFixtures::v3_widget( 'hd00001', 'heading', array( 'title' => 'Hola' ) ),
							ElementorSectionFixtures::v3_widget( 'sp00001', 'spacer', array( 'space' => array( 'size' => 30 ) ) ),
							ElementorSectionFixtures::v3_widget( 'tx00001', 'text-editor', array( 'editor' => '<p><strong>Negrita</strong></p>' ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Árbol v3.
	 * @return array<string, mixed>
	 */
	private function migrate( array $tree ): array {
		$n = 0;

		return ( new ElementorV3Migrator( new FakeV3StyleMapper(), static function () use ( &$n ): string {
				return sprintf( 'n%06d', ++$n );
			} ) )->migrate( $tree );
	}

	private function checker(): ElementorMigrationChecker {
		return new ElementorMigrationChecker( $this->guard );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree Árbol migrado.
	 * @param array<int, array<string, mixed>> $from Árbol original.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function check( array $tree, array $from, int $max = 200 ) {
		return $this->checker()->check( $tree, $from, $max );
	}

	/**
	 * Primer nodo de un tipo, a cualquier profundidad.
	 *
	 * @param array<int, array<string, mixed>> $tree Árbol.
	 * @return array<string, mixed>|null
	 */
	private function find( array $tree, string $id ): ?array {
		foreach ( $tree as $node ) {
			if ( $id === $node['id'] ) {
				return $node;
			}

			$found = $this->find( $node['elements'] ?? array(), $id );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Cambia un nodo del árbol por id.
	 *
	 * @param array<int, array<string, mixed>> $tree Árbol.
	 * @param callable                         $edit fn( array $node ): array.
	 * @return array<int, array<string, mixed>>
	 */
	private function tamper( array $tree, string $id, callable $edit ): array {
		foreach ( $tree as $i => $node ) {
			if ( $id === $node['id'] ) {
				$tree[ $i ] = $edit( $node );
			} elseif ( ! empty( $node['elements'] ) ) {
				$tree[ $i ]['elements'] = $this->tamper( $node['elements'], $id, $edit );
			}
		}

		return $tree;
	}

	public function test_acepta_la_salida_del_migrador_y_la_devuelve_saneada(): void {
		$migrated = $this->migrate( $this->original() );

		$result = $this->check( $migrated['tree'], $this->original() );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'e-flexbox', $result[0]['elType'] );
		$this->assertSame( 'e-div-block', $result[0]['elements'][0]['elType'] );
		$this->assertSame( $this->find( $this->original(), 'sp00001' ), $this->find( $result, 'sp00001' ), 'el spacer v3 queda tal cual' );
		$this->assertSame( $this->find( $this->original(), 'tx00001' ), $this->find( $result, 'tx00001' ) );
		$this->assertGreaterThan( 0, $this->guard->sanitized, 'los atómicos nuevos pasan por el saneado de Elementor' );
		$this->assertGreaterThan( 0, $this->guard->parsed, 'los atómicos nuevos pasan por la validación de Elementor' );
	}

	public function test_un_nodo_nuevo_se_guarda_solo_con_las_claves_de_elementor(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $this->tamper(
			$migrated['tree'],
			$migrated['tree'][0]['id'],
			static function ( array $node ): array {
				$node['htmlCache']  = '<script>x</script>';
				$node['widgetType'] = 'html';

				return $node;
			}
		);

		$result = $this->check( $tree, $this->original() );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( 'id', 'elType', 'settings', 'styles', 'elements' ), array_keys( $result[0] ) );

		$heading = $result[0]['elements'][0]['elements'][0];

		$this->assertSame( array( 'id', 'elType', 'widgetType', 'settings', 'styles', 'elements' ), array_keys( $heading ) );
	}

	public function test_lo_que_se_guarda_es_lo_saneado_y_no_lo_que_trajo_el_migrador(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $this->tamper(
			$migrated['tree'],
			$migrated['tree'][0]['elements'][0]['elements'][0]['id'],
			static function ( array $node ): array {
				$node['settings']['title']['value'] = '<b>Hola</b>';

				return $node;
			}
		);

		$result = $this->check( $tree, $this->original() );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hola', $result[0]['elements'][0]['elements'][0]['settings']['title']['value'] );
	}

	/**
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public static function tampered_v3(): array {
		return array(
			'ajustes distintos'      => array(
				static function ( array $node ): array {
					$node['settings']['space']['size'] = 9999;

					return $node;
				},
				'sp00001',
			),
			'widgetType distinto'    => array(
				static function ( array $node ): array {
					$node['widgetType'] = 'html';

					return $node;
				},
				'sp00001',
			),
			'ajuste agregado'        => array(
				static function ( array $node ): array {
					$node['settings']['custom_css'] = 'a{}';

					return $node;
				},
				'sp00001',
			),
			'estilos colados'        => array(
				static function ( array $node ): array {
					$node['styles'] = array( 'x' => array( 'id' => 'x' ) );

					return $node;
				},
				'sp00001',
			),
			'claves reordenadas'     => array(
				static function ( array $node ): array {
					return array_reverse( $node, true );
				},
				'sp00001',
			),
			'tipo escalar distinto'  => array(
				static function ( array $node ): array {
					$node['settings']['space']['size'] = '30';

					return $node;
				},
				'sp00001',
			),
			'clave de nodo agregada' => array(
				static function ( array $node ): array {
					$node['htmlCache'] = '<script>x</script>';

					return $node;
				},
				'sp00001',
			),
			'editor con formato distinto' => array(
				static function ( array $node ): array {
					$node['settings']['editor'] = '<script>alert(1)</script>';

					return $node;
				},
				'tx00001',
			),
		);
	}

	/**
	 * @dataProvider tampered_v3
	 */
	public function test_un_v3_conservado_distinto_del_original_se_rechaza( callable $edit, string $id ): void {
		$migrated = $this->migrate( $this->original() );

		$result = $this->check( $this->tamper( $migrated['tree'], $id, $edit ), $this->original() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v3_not_identical', $result->get_error_code() );
		$this->assertStringContainsString( $id, $result->get_error_message() );
	}

	public function test_un_widget_v3_agregado_que_no_estaba_en_el_original_se_rechaza(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $this->tamper(
			$migrated['tree'],
			$migrated['tree'][0]['elements'][0]['id'],
			static function ( array $node ): array {
				$node['elements'][] = ElementorSectionFixtures::v3_widget( 'ev00001', 'html', array( 'html' => '<script>x</script>' ) );

				return $node;
			}
		);

		$result = $this->check( $tree, $this->original() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_v3_not_identical', $result->get_error_code() );
	}

	public function test_un_v3_con_id_de_otro_nodo_original_pero_otro_tipo_se_rechaza(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $this->tamper(
			$migrated['tree'],
			'sp00001',
			static function ( array $node ): array {
				$node['id'] = 'hd00001';

				return $node;
			}
		);

		$result = $this->check( $tree, $this->original() );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_un_contenedor_v3_conservado_se_compara_sin_sus_hijos(): void {
		$grid = array(
			'id'       => 'grid001',
			'elType'   => 'container',
			'settings' => array( 'container_type' => 'grid' ),
			'elements' => array( ElementorSectionFixtures::v3_widget( 'hd00009', 'heading', array( 'title' => 'En grid' ) ) ),
		);
		$migrated = $this->migrate( array( $grid ) );

		$ok = $this->check( $migrated['tree'], array( $grid ) );

		$this->assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_message() : '' );
		$this->assertSame( 'container', $ok[0]['elType'] );
		$this->assertSame( 'e-heading', $ok[0]['elements'][0]['widgetType'] );

		$bad = $this->check( $this->tamper( $migrated['tree'], 'grid001', static function ( array $node ): array {
			$node['settings']['container_type'] = 'flex';

			return $node;
		} ), array( $grid ) );

		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'nexo_mcp_elementor_v3_not_identical', $bad->get_error_code() );
	}

	public function test_un_atomico_nuevo_invalido_se_rechaza_con_el_validador_del_plugin(): void {
		$migrated = $this->migrate( $this->original() );
		$bad_id   = $migrated['tree'][0]['elements'][0]['elements'][0]['id'];

		foreach (
			array(
				'tipo no permitido'     => static function ( array $node ): array {
					$node['widgetType'] = 'e-svg';

					return $node;
				},
				'ajuste desconocido'    => static function ( array $node ): array {
					$node['settings']['onclick'] = array( '$$type' => 'string', 'value' => 'x' );

					return $node;
				},
				'estilo desconocido'    => static function ( array $node ): array {
					$node['styles']['e-x-y'] = array( 'id' => 'e-x-y', 'type' => 'class', 'label' => 'local', 'variants' => array( array( 'meta' => array( 'breakpoint' => 'desktop', 'state' => null ), 'props' => array( 'behavior' => array( '$$type' => 'string', 'value' => 'x' ) ) ) ) );

					return $node;
				},
				'hijos en un widget'    => static function ( array $node ): array {
					$node['elements'] = array( ElementorSectionFixtures::v3_widget( 'xx00001', 'spacer', array() ) );

					return $node;
				},
			) as $label => $edit
		) {
			$result = $this->check( $this->tamper( $migrated['tree'], $bad_id, $edit ), $this->original() );

			$this->assertInstanceOf( \WP_Error::class, $result, $label );
		}
	}

	public function test_un_widget_atomico_nuevo_en_la_raiz_o_en_una_columna_v3_se_rechaza(): void {
		$migrated = $this->migrate( $this->original() );
		$heading  = $migrated['tree'][0]['elements'][0]['elements'][0];

		$root = $this->check( array( $heading ), $this->original() );
		$this->assertInstanceOf( \WP_Error::class, $root );
		$this->assertSame( 'nexo_mcp_elementor_nesting_denied', $root->get_error_code() );
	}

	public function test_un_nodo_nuevo_con_el_id_de_uno_original_se_rechaza(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $this->tamper(
			$migrated['tree'],
			$migrated['tree'][0]['elements'][0]['elements'][0]['id'],
			static function ( array $node ): array {
				$node['id'] = 'hd00001';

				return $node;
			}
		);

		$result = $this->check( $tree, $this->original() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_migration_id_clash', $result->get_error_code() );
	}

	public function test_los_ids_repetidos_se_rechazan(): void {
		$migrated = $this->migrate( $this->original() );
		$tree     = $migrated['tree'];
		$tree[]   = $tree[0];

		$result = $this->check( $tree, $this->original() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_migration_id_clash', $result->get_error_code() );
	}

	public function test_un_nodo_atomico_que_ya_estaba_no_se_vuelve_a_validar_ni_cambia(): void {
		$original                               = ElementorSectionFixtures::atomic();
		$original[0]['elements'][0]['version']  = '0.0';
		$original[0]['elements'][1]['widgetType'] = 'e-svg';

		$result = $this->check( $original, $original );

		$this->assertSame( $original, $result );
	}

	public function test_respeta_el_tope_de_nodos(): void {
		$migrated = $this->migrate( $this->original() );
		$count    = 5;

		$this->assertIsArray( $this->check( $migrated['tree'], $this->original(), $count ) );

		$result = $this->check( $migrated['tree'], $this->original(), $count - 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_many_nodes', $result->get_error_code() );
	}

	public function test_respeta_el_tope_de_profundidad(): void {
		$leaf = ElementorSectionFixtures::atomic()[0];
		$node = $leaf;

		for ( $i = 0; $i < 11; $i++ ) {
			$node = array( 'id' => 'wr' . $i, 'elType' => 'e-flexbox', 'settings' => array(), 'styles' => array(), 'elements' => array( $node ) );
		}

		$result = $this->check( array( $node ), array() , 500 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nexo_mcp_elementor_too_deep', $result->get_error_code() );
	}
}
