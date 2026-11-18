<?php
/**
 * Lectura del sistema de diseño de Elementor.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Entrega clases globales, variables, colores y tipografía del Kit activo.
 *
 * Sólo toca clases de Elementor si existen: lo que la versión instalada no trae
 * sale como lista vacía en vez de romper la herramienta. Distinto es que la clase
 * exista y la lectura falle (un `Throwable` del repositorio): eso se avisa en
 * `warnings` (`{source, message}`) para que una lista vacía no se tome por «no hay
 * nada», y lo ya leído se conserva.
 */
final class ElementorDesignSystem {

	/**
	 * Avisos de la lectura en curso.
	 *
	 * @var array<int, array{source: string, message: string}>
	 */
	private array $warnings = array();

	/**
	 * Lee el sistema de diseño.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_missing',
				__( 'Elementor no está activo en este sitio, así que no hay sistema de diseño que leer.', 'nexo-mcp' )
			);
		}

		$kit            = $this->kit();
		$this->warnings = array();

		$out = array(
			'atomic_enabled' => $this->atomic_enabled(),
			'kit_id'         => null !== $kit ? $this->kit_id( $kit ) : 0,
			'colors'         => $this->colors( $kit ),
			'typography'     => $this->typography( $kit ),
			'classes'        => $this->classes( $kit ),
			'variables'      => $this->variables( $kit ),
		);

		$warnings = $this->take_warnings();

		if ( array() !== $warnings ) {
			$out['warnings'] = $warnings;
		}

		return $out;
	}

	/**
	 * Instancia de Elementor, o null si no está cargado.
	 */
	public function plugin(): ?object {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::instance();

		return is_object( $plugin ) ? $plugin : null;
	}

	/**
	 * Indica si los elementos atómicos están activos.
	 */
	public function atomic_enabled(): bool {
		$plugin = $this->plugin();

		if ( null === $plugin || ! isset( $plugin->experiments ) || ! is_object( $plugin->experiments ) ) {
			return false;
		}

		return (bool) $plugin->experiments->is_feature_active( 'e_atomic_elements' );
	}

	/**
	 * Ids de las clases globales existentes.
	 *
	 * @return array<int, string>
	 */
	public function global_class_ids(): array {
		return array_map(
			static fn( array $entry ): string => $entry['id'],
			$this->classes( $this->kit() )
		);
	}

	/**
	 * Kit activo, o null si no hay.
	 */
	private function kit(): ?object {
		$plugin = $this->plugin();

		if ( null === $plugin || ! isset( $plugin->kits_manager ) || ! is_object( $plugin->kits_manager ) ) {
			return null;
		}

		$kit = $plugin->kits_manager->get_active_kit();

		return is_object( $kit ) && method_exists( $kit, 'get_settings' ) ? $kit : null;
	}

	/**
	 * Identificador del documento del Kit.
	 *
	 * @param object $kit Kit activo.
	 */
	private function kit_id( object $kit ): int {
		return method_exists( $kit, 'get_main_id' ) ? (int) $kit->get_main_id() : 0;
	}

	/**
	 * Colores del Kit (sistema y personalizados).
	 *
	 * @param object|null $kit Kit activo.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function colors( ?object $kit ): array {
		$out = array();

		foreach ( $this->kit_items( $kit, array( 'system_colors', 'custom_colors' ) ) as $item ) {
			$out[] = array(
				'id'    => (string) ( $item['_id'] ?? '' ),
				'title' => (string) ( $item['title'] ?? '' ),
				'color' => (string) ( $item['color'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Tipografía del Kit (sistema y personalizada).
	 *
	 * @param object|null $kit Kit activo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function typography( ?object $kit ): array {
		$out = array();

		foreach ( $this->kit_items( $kit, array( 'system_typography', 'custom_typography' ) ) as $item ) {
			$row = array(
				'id'    => (string) ( $item['_id'] ?? '' ),
				'title' => (string) ( $item['title'] ?? '' ),
			);

			if ( isset( $item['typography_font_family'] ) && is_string( $item['typography_font_family'] ) ) {
				$row['font_family'] = $item['typography_font_family'];
			}

			if ( isset( $item['typography_font_weight'] ) && is_scalar( $item['typography_font_weight'] ) ) {
				$row['font_weight'] = (string) $item['typography_font_weight'];
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Filas de las listas del Kit, ignorando lo que no tenga forma de lista.
	 *
	 * @param object|null        $kit  Kit activo.
	 * @param array<int, string> $keys Claves de ajuste.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function kit_items( ?object $kit, array $keys ): array {
		$rows = array();

		if ( null === $kit ) {
			return $rows;
		}

		foreach ( $keys as $key ) {
			$list = $kit->get_settings( $key );

			if ( ! is_array( $list ) ) {
				continue;
			}

			foreach ( $list as $item ) {
				if ( is_array( $item ) ) {
					$rows[] = $item;
				}
			}
		}

		return $rows;
	}

	/**
	 * Clases globales en su orden.
	 *
	 * @param object|null $kit Kit activo.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function classes( ?object $kit ): array {
		$repository = '\Elementor\Modules\GlobalClasses\Global_Classes_Repository';

		if ( null === $kit || ! class_exists( $repository ) ) {
			return array();
		}

		$out = array();

		try {
			$labels = $repository::make( $kit )->all_labels();
		} catch ( \Throwable $e ) {
			$this->warn( 'classes', $e );

			return array();
		}

		foreach ( $labels as $id => $label ) {
			$out[] = array(
				'id'    => (string) $id,
				'label' => (string) $label,
			);
		}

		return $out;
	}

	/**
	 * Variables del Kit, sin las borradas.
	 *
	 * @param object|null $kit Kit activo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function variables( ?object $kit ): array {
		$repository = '\Elementor\Modules\Variables\Storage\Variables_Repository';

		if ( null === $kit || ! class_exists( $repository ) ) {
			return array();
		}

		$out = array();

		try {
			$all = ( new $repository( $kit ) )->load()->all();
		} catch ( \Throwable $e ) {
			$this->warn( 'variables', $e );

			return array();
		}

		// Variable por variable: una que falla se avisa y no tira las ya leídas.
		foreach ( $all as $variable ) {
			try {
				if ( $variable->is_deleted() ) {
					continue;
				}

				$out[] = array(
					'id'    => (string) $variable->id(),
					'type'  => (string) $variable->type(),
					'label' => (string) $variable->label(),
					'value' => $variable->value(),
				);
			} catch ( \Throwable $e ) {
				$this->warn( 'variables', $e );
			}
		}

		return $out;
	}

	/**
	 * Entrega los avisos acumulados y los vacía.
	 *
	 * @return array<int, array{source: string, message: string}>
	 */
	private function take_warnings(): array {
		$warnings       = $this->warnings;
		$this->warnings = array();

		return $warnings;
	}

	/**
	 * Anota que una lectura falló.
	 *
	 * @param string     $source Bloque que falló (classes o variables).
	 * @param \Throwable $error  Lo que lanzó Elementor.
	 */
	private function warn( string $source, \Throwable $error ): void {
		$this->warnings[] = array(
			'source'  => $source,
			'message' => sprintf(
				/* translators: 1: bloque (classes o variables), 2: mensaje de Elementor. */
				__( 'No se pudo leer «%1$s» de Elementor; la lista puede estar incompleta: %2$s', 'nexo-mcp' ),
				$source,
				$error->getMessage()
			),
		);
	}
}
