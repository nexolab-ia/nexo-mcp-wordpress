<?php
/**
 * Variables globales sobre `Variables_Service` de Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lee con `Variables_Repository` (borradas y variables de tamaño incluidas, aunque Elementor
 * sin Pro las oculte) y escribe con `Variables_Service` (`create`, `update` y `delete` lógico).
 *
 * `Prop_Type_Adapter::to_storage` no lanza con los valores que valida `DesignVariableValue`:
 * `generate()` sólo arma `{$$type, value}` y `parse_size_value` recibe siempre un texto
 * `número+unidad` (el mismo patrón que se exige al validar). Cualquier excepción de Elementor
 * (etiqueta repetida, límite, `FatalError` al guardar) sube a `DesignSystemWriter`, que restaura.
 */
final class DesignVariableElementorStore implements DesignVariableStore {

	private const NS = '\Elementor\Modules\Variables';

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
	 */
	public function variables(): array {
		$out = array();

		foreach ( $this->repository()->load()->all() as $id => $variable ) {
			$value = $variable->value();

			$out[ (string) $id ] = array(
				'type'    => (string) $variable->type(),
				'label'   => $variable->label(),
				'value'   => is_scalar( $value ) ? (string) $value : '',
				'deleted' => $variable->is_deleted(),
			);
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $type  Tipo.
	 * @param string $label Etiqueta.
	 * @param string $value Valor.
	 */
	public function create( string $type, string $label, string $value ): array {
		$result = $this->service()->create(
			array(
				'type'  => $type,
				'label' => $label,
				'value' => $value,
			)
		);

		return $this->summary( (array) $result['variable'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                $id      Id.
	 * @param array<string, string> $changes Cambios.
	 */
	public function update( string $id, array $changes ): array {
		$result = $this->service()->update( $id, $changes );

		return $this->summary( (array) $result['variable'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function delete( string $id ): void {
		$this->service()->delete( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function used_in( string $id ): array {
		global $wpdb;

		// Con las comillas del JSON el id no casa con otro que sólo lo empiece.
		$needle = '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Búsqueda puntual en el meta de Elementor, sin API de Core.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value LIKE %s AND p.post_type <> %s AND p.post_status <> %s ORDER BY p.ID ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas de Core.
				'_elementor_data',
				$needle,
				'revision',
				'auto-draft'
			)
		);

		$used = array();

		foreach ( (array) $rows as $row ) {
			$used[] = array(
				'id'    => (int) $row->ID,
				'title' => (string) $row->post_title,
			);
		}

		return $used;
	}

	/**
	 * Id, tipo, etiqueta y valor de lo que devuelve el servicio.
	 *
	 * @param array<string, mixed> $variable Variable.
	 *
	 * @return array{id: string, type: string, label: string, value: string}
	 */
	private function summary( array $variable ): array {
		$value = $variable['value'] ?? '';

		return array(
			'id'    => (string) ( $variable['id'] ?? '' ),
			'type'  => (string) ( $variable['type'] ?? '' ),
			'label' => (string) ( $variable['label'] ?? '' ),
			'value' => is_scalar( $value ) ? (string) $value : '',
		);
	}

	/**
	 * Repositorio de variables del Kit activo.
	 *
	 * @throws \RuntimeException Si Elementor no trae variables o no hay Kit.
	 */
	private function repository(): object {
		$class = self::NS . '\Storage\Variables_Repository';
		$kit   = $this->system->kit();

		if ( ! class_exists( $class ) || null === $kit ) {
			throw new \RuntimeException( 'Esta versión de Elementor no trae variables globales o no hay Kit activo.' );
		}

		return new $class( $kit );
	}

	/**
	 * Servicio de variables sobre ese repositorio.
	 *
	 * @throws \RuntimeException Si Elementor no trae el servicio.
	 */
	private function service(): object {
		$service   = self::NS . '\Services\Variables_Service';
		$processor = self::NS . '\Services\Batch_Operations\Batch_Processor';

		if ( ! class_exists( $service ) || ! class_exists( $processor ) ) {
			throw new \RuntimeException( 'Esta versión de Elementor no trae el servicio de variables globales.' );
		}

		return new $service( $this->repository(), new $processor() );
	}
}
