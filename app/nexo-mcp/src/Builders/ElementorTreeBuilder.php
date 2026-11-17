<?php
/**
 * Armado del árbol atómico que se entrega a Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Convierte el árbol ya validado del cliente en el que Elementor guarda.
 *
 * Los ids de elemento los pone el plugin: los que mande el cliente se ignoran.
 * Los estilos locales también se renombran (`e-<elemento>-<sufijo>`, como hace el
 * editor) para que dos nodos con la misma clave no choquen en el CSS de la
 * página, y las clases del nodo que los usan se actualizan. Las clases globales
 * no se tocan.
 */
final class ElementorTreeBuilder {

	/**
	 * Generador de ids: devuelve una cadena hexadecimal.
	 *
	 * @var callable
	 */
	private $generator;

	/**
	 * Ids ya entregados en esta construcción.
	 *
	 * @var array<string, true>
	 */
	private array $used = array();

	/**
	 * Construye el armador.
	 *
	 * @param callable|null $generator fn(): string. Por omisión, 7 dígitos hexadecimales aleatorios como los del editor.
	 */
	public function __construct( ?callable $generator = null ) {
		$this->generator = $generator ?? static fn(): string => substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Arma el árbol con ids propios.
	 *
	 * @param array<int, array<string, mixed>> $elements Árbol validado.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function build( array $elements ): array {
		$this->used = array();
		$out        = array();

		foreach ( $elements as $node ) {
			$out[] = $this->build_node( $node );
		}

		return $out;
	}

	/**
	 * Cuenta los nodos de un árbol armado.
	 *
	 * @param array<int, array<string, mixed>> $tree Árbol.
	 */
	public static function count_nodes( array $tree ): int {
		$count = 0;

		foreach ( $tree as $node ) {
			++$count;

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$count += self::count_nodes( $node['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Arma un nodo y sus hijos.
	 *
	 * @param array<string, mixed> $node Nodo del cliente.
	 *
	 * @return array<string, mixed>
	 */
	private function build_node( array $node ): array {
		$type     = (string) $node['type'];
		$id       = $this->next_id();
		$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
		$styles   = array();
		$renamed  = array();

		foreach ( is_array( $node['styles'] ?? null ) ? $node['styles'] : array() as $key => $style ) {
			$new_id          = 'e-' . $id . '-' . $this->next_id();
			$renamed[ $key ] = $new_id;
			// Se arma desde cero con las claves permitidas: nunca se copia el objeto
			// del cliente (una clave extra como `cssName` llega al CSS sin escapar).
			$styles[ $new_id ] = array(
				'id'       => $new_id,
				'type'     => 'class',
				'label'    => (string) ( $style['label'] ?? '' ),
				'variants' => $this->variants( $style['variants'] ?? array() ),
			);
		}

		if ( array() !== $renamed && isset( $settings['classes']['value'] ) && is_array( $settings['classes']['value'] ) ) {
			foreach ( $settings['classes']['value'] as $position => $name ) {
				if ( is_string( $name ) && isset( $renamed[ $name ] ) ) {
					$settings['classes']['value'][ $position ] = $renamed[ $name ];
				}
			}
		}

		$children = array();

		foreach ( is_array( $node['elements'] ?? null ) ? $node['elements'] : array() as $child ) {
			$children[] = $this->build_node( $child );
		}

		$built = array( 'id' => $id );

		if ( in_array( $type, ElementorTreeValidator::CONTAINERS, true ) ) {
			$built['elType'] = $type;
		} else {
			$built['elType']     = 'widget';
			$built['widgetType'] = $type;
		}

		$built['settings'] = $settings;
		$built['styles']   = $styles;
		$built['elements'] = $children;

		return $built;
	}

	/**
	 * Variantes con la forma mínima que Elementor espera y sólo las claves permitidas.
	 *
	 * @param mixed $variants Variantes del cliente.
	 *
	 * @return array<int, mixed>
	 */
	private function variants( $variants ): array {
		$out = array();

		foreach ( is_array( $variants ) ? $variants : array() as $variant ) {
			$variant = is_array( $variant ) ? $variant : array();
			$meta    = is_array( $variant['meta'] ?? null ) ? $variant['meta'] : array();
			$out[]   = array(
				'meta'       => array(
					'breakpoint' => $meta['breakpoint'] ?? null,
					'state'      => $meta['state'] ?? null,
				),
				'props'      => is_array( $variant['props'] ?? null ) ? $variant['props'] : array(),
				'custom_css' => null,
			);
		}

		return $out;
	}

	/**
	 * Siguiente id sin repetir.
	 */
	private function next_id(): string {
		do {
			$id = (string) ( $this->generator )();
		} while ( isset( $this->used[ $id ] ) );

		$this->used[ $id ] = true;

		return $id;
	}
}
