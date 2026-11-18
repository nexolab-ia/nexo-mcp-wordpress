<?php
/**
 * Almacén del sistema de diseño sobre Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee y restaura el estado crudo de clases globales, variables y ajustes del Kit.
 *
 * - Clases: items (por id) y orden, con el repositorio de Elementor (`put` conserva los ids).
 * - Variables: el registro crudo del meta del Kit, borradas incluidas. Se escribe tal cual,
 *   sin pasar otra vez por la conversión de tipos de propiedad.
 * - Kit: sólo las cuatro listas de colores y tipografía de `_elementor_page_settings`.
 */
final class ElementorDesignStore implements DesignSystemStore {

	private const VARIABLES_META = '_elementor_global_variables';

	private const PAGE_SETTINGS_META = '_elementor_page_settings';

	/**
	 * Listas del Kit que se respaldan y restauran completas.
	 *
	 * @var array<int, string>
	 */
	private const KIT_LISTS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	/**
	 * Lectura del Kit y de las banderas de Elementor.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $system;

	/**
	 * Construye el almacén.
	 *
	 * @param ElementorDesignSystem|null $system Lectura del sistema de diseño.
	 */
	public function __construct( ?ElementorDesignSystem $system = null ) {
		$this->system = $system ?? new ElementorDesignSystem();
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return null !== $this->system->plugin();
	}

	/**
	 * {@inheritDoc}
	 */
	public function atomic(): bool {
		return $this->system->atomic_enabled();
	}

	/**
	 * {@inheritDoc}
	 */
	public function kit_id(): int {
		$kit = $this->system->kit();

		return null !== $kit && method_exists( $kit, 'get_main_id' ) ? (int) $kit->get_main_id() : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $kind Tipo.
	 *
	 * @throws \InvalidArgumentException Si el tipo no existe (y \RuntimeException si falta el Kit).
	 */
	public function snapshot( string $kind ): array {
		$kit = $this->require_kit();

		switch ( $kind ) {
			case 'classes':
				// `get()` entrega arreglos: `get_items()` y `get_order()` devuelven colecciones.
				$all = $this->classes( $kit )->all( true )->get();

				return array(
					'items' => (array) $all['items'],
					'order' => array_values( (array) $all['order'] ),
				);

			case 'variables':
				$record = $kit->get_json_meta( $this->variables_key() );

				return is_array( $record ) ? $record : array();

			case 'kit':
				$settings = $this->kit_settings( $kit );
				$lists    = array();

				foreach ( self::KIT_LISTS as $key ) {
					if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
						$lists[ $key ] = $settings[ $key ];
					}
				}

				return $lists;
		}

		throw new \InvalidArgumentException( 'Tipo de sistema de diseño desconocido.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $kind  Tipo.
	 * @param array<string, mixed> $state Estado previo.
	 *
	 * @throws \InvalidArgumentException Si el tipo o el respaldo no tienen la forma esperada (y \RuntimeException si Elementor no guarda lo restaurado).
	 */
	public function restore( string $kind, array $state ): void {
		$kit = $this->require_kit();

		switch ( $kind ) {
			case 'classes':
				if ( ! isset( $state['items'], $state['order'] ) || ! is_array( $state['items'] ) || ! is_array( $state['order'] ) ) {
					throw new \InvalidArgumentException( 'El respaldo de clases no tiene la forma esperada.' );
				}

				$this->restore_classes( $kit, $state['items'], $state['order'] );
				return;

			case 'variables':
				$this->restore_variables( $kit, $state );
				return;

			case 'kit':
				$this->restore_kit( $kit, $state );
				return;
		}

		throw new \InvalidArgumentException( 'Tipo de sistema de diseño desconocido.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear_cache(): void {
		$plugin = $this->system->plugin();

		if ( null !== $plugin && isset( $plugin->files_manager ) && is_object( $plugin->files_manager ) ) {
			$plugin->files_manager->clear_cache();
		}
	}

	/**
	 * Kit activo o error: el escritor ya lo comprobó, pero un almacén no asume.
	 *
	 * @throws \RuntimeException Si no hay Kit activo.
	 */
	private function require_kit(): object {
		$kit = $this->system->kit();

		if ( null === $kit ) {
			throw new \RuntimeException( 'No hay un Kit activo de Elementor.' );
		}

		return $kit;
	}

	/**
	 * Repositorio de clases globales del Kit.
	 *
	 * @param object $kit Kit activo.
	 *
	 * @throws \RuntimeException Si Elementor no trae clases globales.
	 */
	private function classes( object $kit ): object {
		$repository = '\Elementor\Modules\GlobalClasses\Global_Classes_Repository';

		if ( ! class_exists( $repository ) ) {
			throw new \RuntimeException( 'Esta versión de Elementor no trae clases globales.' );
		}

		return $repository::make( $kit );
	}

	/**
	 * Devuelve las clases a su estado respaldado tocando sólo lo que cambió.
	 *
	 * Va por `apply_changes` y no por `put`: `put` pasa todas las clases por la actualización
	 * (descarta las ediciones sin publicar del editor y reinicia sus fechas) y no propaga el
	 * orden a la vista previa, así que el editor no vería una clase devuelta. `apply_changes`
	 * recibe sólo los items tocados, los cambios (`added`, `deleted`, `modified`, `order`) y el
	 * orden final. Sin diferencias no toca nada.
	 *
	 * @param object               $kit   Kit activo.
	 * @param array<string, array> $items Items respaldados, por id.
	 * @param array<int, string>   $order Orden respaldado.
	 *
	 * @throws \RuntimeException Si Elementor no deja las clases como estaban.
	 */
	private function restore_classes( object $kit, array $items, array $order ): void {
		$repository = $this->classes( $kit );
		$current    = $repository->all( true )->get();
		$now_items  = (array) $current['items'];
		$now_order  = array_values( (array) $current['order'] );

		$added    = array_values( array_diff( array_keys( $items ), array_keys( $now_items ) ) );
		$deleted  = array_values( array_diff( array_keys( $now_items ), array_keys( $items ) ) );
		$modified = array();

		foreach ( $items as $id => $item ) {
			if ( isset( $now_items[ $id ] ) && $item != $now_items[ $id ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- comparación estructural de arreglos.
				$modified[] = $id;
			}
		}

		$order_changed = array_values( $order ) !== $now_order;

		if ( array() === $added && array() === $deleted && array() === $modified && ! $order_changed ) {
			return;
		}

		$touched = array_intersect_key( $items, array_flip( array_merge( $added, $modified ) ) );

		$repository->apply_changes(
			$touched,
			array(
				'added'    => $added,
				'deleted'  => $deleted,
				'modified' => $modified,
				'order'    => $order_changed,
			),
			array_values( $order )
		);

		$after = array_keys( (array) $repository->all( true )->get()['items'] );

		if ( array() !== array_diff( array_keys( $items ), $after ) || array() !== array_diff( $after, array_keys( $items ) ) ) {
			throw new \RuntimeException( 'Elementor no devolvió todas las clases respaldadas.' );
		}
	}

	/**
	 * Clave del meta de variables, la que usa Elementor.
	 */
	private function variables_key(): string {
		$constants = '\Elementor\Modules\Variables\Storage\Constants';

		return class_exists( $constants ) && defined( $constants . '::VARIABLES_META_KEY' )
			? (string) constant( $constants . '::VARIABLES_META_KEY' )
			: self::VARIABLES_META;
	}

	/**
	 * Escribe de vuelta el registro crudo de variables.
	 *
	 * La marca de agua nueva es mayor que la actual y que la del respaldo, para que los
	 * clientes del editor que sincronizan por marca vean el cambio. Un respaldo vacío
	 * (el Kit no tenía variables) borra el meta.
	 *
	 * @param object               $kit   Kit activo.
	 * @param array<string, mixed> $state Registro crudo respaldado.
	 *
	 * @throws \RuntimeException Si lo guardado no coincide con el respaldo.
	 */
	private function restore_variables( object $kit, array $state ): void {
		$key = $this->variables_key();

		if ( array() === $state ) {
			$kit->delete_meta( $key );

			if ( array() !== $kit->get_json_meta( $key ) ) {
				throw new \RuntimeException( 'No se pudo borrar el registro de variables.' );
			}

			return;
		}

		$current = $kit->get_json_meta( $key );
		$current = is_array( $current ) ? (int) ( $current['watermark'] ?? 0 ) : 0;

		$record              = $state;
		$record['watermark'] = max( $current, (int) ( $state['watermark'] ?? 0 ) ) + 1;

		$kit->update_json_meta( $key, $record );

		// `update_json_meta` devuelve false también cuando nada cambia: se comprueba lo guardado.
		$saved = $kit->get_json_meta( $key );

		if ( ! is_array( $saved ) || ( $saved['data'] ?? null ) !== ( $state['data'] ?? null ) ) {
			throw new \RuntimeException( 'No se pudo escribir el registro de variables.' );
		}
	}

	/**
	 * Devuelve las cuatro listas del Kit, enteras, sin tocar el resto de ajustes.
	 *
	 * @param object               $kit   Kit activo.
	 * @param array<string, mixed> $state Listas respaldadas.
	 *
	 * @throws \RuntimeException Si lo guardado no coincide con el respaldo.
	 */
	private function restore_kit( object $kit, array $state ): void {
		$id       = (int) $kit->get_main_id();
		$settings = $this->kit_settings( $kit );

		foreach ( self::KIT_LISTS as $key ) {
			if ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) ) {
				$settings[ $key ] = $state[ $key ];
			} else {
				unset( $settings[ $key ] );
			}
		}

		// Igual que Elementor: `update_metadata` quita una barra de más, y `wp_slash` la compensa.
		if ( array() !== $settings ) {
			update_metadata( 'post', $id, self::PAGE_SETTINGS_META, wp_slash( $settings ) );
		} else {
			delete_metadata( 'post', $id, self::PAGE_SETTINGS_META );
		}

		$saved = $this->kit_settings( $kit );

		foreach ( self::KIT_LISTS as $key ) {
			if ( ( $saved[ $key ] ?? null ) !== ( $settings[ $key ] ?? null ) ) {
				throw new \RuntimeException( esc_html( 'No se pudo escribir la lista «' . $key . '» del Kit.' ) );
			}
		}
	}

	/**
	 * Ajustes crudos del Kit (`_elementor_page_settings`).
	 *
	 * @param object $kit Kit activo.
	 *
	 * @return array<string, mixed>
	 */
	private function kit_settings( object $kit ): array {
		$settings = get_post_meta( (int) $kit->get_main_id(), self::PAGE_SETTINGS_META, true );

		return is_array( $settings ) ? $settings : array();
	}
}
