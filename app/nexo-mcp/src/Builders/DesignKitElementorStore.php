<?php
/**
 * Listas del Kit sobre Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee las listas de `_elementor_page_settings` y las guarda con `Kit::save()`.
 *
 * Elementor fusiona por índice las listas que llegan por `update_settings()`
 * (`array_replace_recursive`): quitar un item dejaría el último de la lista vieja. Por eso se
 * guarda con `save( array( 'settings' => ... ) )` y los ajustes completos: `save_settings()`
 * reemplaza el meta entero, así que cada lista queda exactamente como se pidió.
 */
final class DesignKitElementorStore implements DesignKitStore {

	private const PAGE_SETTINGS_META = '_elementor_page_settings';

	/**
	 * Listas del Kit.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	/**
	 * Lectura del Kit.
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
	 *
	 * @throws \RuntimeException Si no hay Kit activo.
	 */
	public function lists(): array {
		$kit      = $this->kit();
		$settings = $this->raw_settings( $kit );
		$out      = array(
			'system_colors'     => array(),
			'custom_colors'     => array(),
			'system_typography' => array(),
			'custom_typography' => array(),
		);

		foreach ( self::KEYS as $key ) {
			// Lo guardado manda; si nunca se guardó, valen los valores de fábrica que da Elementor.
			$list = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : $kit->get_settings( $key );

			$out[ $key ] = is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists Listas a guardar.
	 *
	 * @throws \RuntimeException Si Elementor no guarda las listas tal como se pidieron.
	 */
	public function save( array $lists ): void {
		$kit      = $this->kit();
		$settings = $this->raw_settings( $kit );

		foreach ( self::KEYS as $key ) {
			if ( isset( $lists[ $key ] ) ) {
				$settings[ $key ] = array_values( $lists[ $key ] );
			}
		}

		if ( ! method_exists( $kit, 'save' ) || true !== $kit->save( array( 'settings' => $settings ) ) ) {
			throw new \RuntimeException( 'Elementor no pudo guardar los ajustes del Kit.' );
		}

		$saved = $this->raw_settings( $kit );

		foreach ( self::KEYS as $key ) {
			if ( isset( $lists[ $key ] ) && ( $saved[ $key ] ?? null ) !== array_values( $lists[ $key ] ) ) {
				throw new \RuntimeException( esc_html( 'Elementor no guardó la lista «' . $key . '» del Kit tal como se pidió.' ) );
			}
		}
	}

	/**
	 * Kit activo.
	 *
	 * @throws \RuntimeException Si no hay Kit activo.
	 */
	private function kit(): object {
		$kit = $this->system->kit();

		if ( null === $kit ) {
			throw new \RuntimeException( 'No hay un Kit activo de Elementor.' );
		}

		return $kit;
	}

	/**
	 * Ajustes crudos del Kit (`_elementor_page_settings`).
	 *
	 * @param object $kit Kit activo.
	 *
	 * @return array<string, mixed>
	 */
	private function raw_settings( object $kit ): array {
		$id       = method_exists( $kit, 'get_main_id' ) ? (int) $kit->get_main_id() : 0;
		$settings = get_post_meta( $id, self::PAGE_SETTINGS_META, true );

		return is_array( $settings ) ? $settings : array();
	}
}
