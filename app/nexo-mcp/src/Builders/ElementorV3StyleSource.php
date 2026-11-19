<?php
/**
 * Colores, tipografías y variables del Kit de Elementor para el traductor de estilos v3.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee el Kit activo con `ElementorDesignSystem::kit()` y las variables con `DesignVariableStore`
 * (por omisión `DesignVariableElementorStore`), como el resto del plugin. Lo que no se puede leer
 * (sin Kit, sin variables en esta versión de Elementor) se trata como inexistente.
 */
final class ElementorV3StyleSource implements V3StyleSource {

	/**
	 * Lectura del Kit.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $system;

	/**
	 * Variables globales.
	 *
	 * @var DesignVariableStore
	 */
	private DesignVariableStore $variables;

	/**
	 * Construye la fuente.
	 *
	 * @param ElementorDesignSystem|null $system    Lectura del sistema de diseño.
	 * @param DesignVariableStore|null   $variables Almacén de variables.
	 */
	public function __construct( ?ElementorDesignSystem $system = null, ?DesignVariableStore $variables = null ) {
		$this->system    = $system ?? new ElementorDesignSystem();
		$this->variables = $variables ?? new DesignVariableElementorStore( $this->system );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id del color global.
	 */
	public function kit_color( string $id ): ?array {
		foreach ( $this->kit_items( array( 'system_colors', 'custom_colors' ), $id ) as $item ) {
			return array(
				'title' => is_scalar( $item['title'] ?? null ) ? (string) $item['title'] : '',
				'color' => is_scalar( $item['color'] ?? null ) ? (string) $item['color'] : '',
			);
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id de la tipografía global.
	 */
	public function kit_typography( string $id ): ?array {
		foreach ( $this->kit_items( array( 'system_typography', 'custom_typography' ), $id ) as $item ) {
			return $item;
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function color_variables(): array {
		try {
			$all = $this->variables->variables();
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();

		foreach ( $all as $id => $variable ) {
			if ( DesignVariableValue::COLOR === $variable['type'] && ! $variable['deleted'] ) {
				$out[] = array(
					'id'    => (string) $id,
					'label' => $variable['label'],
				);
			}
		}

		return $out;
	}

	/**
	 * Filas de las listas del Kit cuyo `_id` es el pedido.
	 *
	 * @param array<int, string> $keys Listas del Kit.
	 * @param string             $id   Id buscado.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function kit_items( array $keys, string $id ): array {
		$kit  = $this->system->kit();
		$rows = array();

		if ( null === $kit || ! method_exists( $kit, 'get_settings' ) ) {
			return $rows;
		}

		foreach ( $keys as $key ) {
			$list = $kit->get_settings( $key );

			foreach ( is_array( $list ) ? $list : array() as $item ) {
				if ( is_array( $item ) && (string) ( $item['_id'] ?? '' ) === $id ) {
					$rows[] = $item;
				}
			}
		}

		return $rows;
	}
}
