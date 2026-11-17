<?php
/**
 * Información de actualización guardada en el sitio.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Updates;

/**
 * Guarda lo último que dijo la plataforma sobre versiones disponibles.
 *
 * WordPress consulta si hay actualizaciones muchas veces por carga, así que
 * preguntar por red cada vez dejaría el panel lento y golpearía la plataforma
 * sin motivo. Se guarda la respuesta y se refresca cada doce horas o cuando
 * el administrador lo pida.
 */
final class UpdateState {

	public const OPTION_NAME = 'nexo_mcp_update';

	/**
	 * Horas que vale la información guardada.
	 *
	 * Doce es lo que usa WordPress para su propio ciclo de actualizaciones;
	 * seguir el mismo ritmo evita que el aviso aparezca a destiempo.
	 */
	private const TTL_HOURS = 12;

	/**
	 * Valores iniciales.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'version'    => '',
			'package'    => '',
			'checksum'   => '',
			'changelog'  => '',
			'published'  => '',
			'min_wp'     => '',
			'min_php'    => '',
			'tested_wp'  => '',
			'size'       => '',
			'checked_at' => '',
		);
	}

	/**
	 * Devuelve el estado normalizado.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$state = array_merge( self::defaults(), $stored );

		foreach ( $state as $key => $value ) {
			$state[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $state;
	}

	/**
	 * Guarda lo que informó la plataforma.
	 *
	 * @param array<string, mixed>|null $update Bloque `update` de la respuesta, o null si no hay.
	 */
	public function store( ?array $update ): void {
		$state = self::defaults();

		if ( is_array( $update ) && isset( $update['version'] ) ) {
			$state['version']   = sanitize_text_field( (string) $update['version'] );
			$state['package']   = esc_url_raw( (string) ( $update['package'] ?? '' ) );
			$state['checksum']  = sanitize_text_field( (string) ( $update['checksum'] ?? '' ) );
			$state['changelog'] = wp_kses_post( (string) ( $update['changelog'] ?? '' ) );
			$state['published'] = sanitize_text_field( (string) ( $update['published'] ?? '' ) );
			$state['min_wp']    = sanitize_text_field( (string) ( $update['minWp'] ?? '' ) );
			$state['min_php']   = sanitize_text_field( (string) ( $update['minPhp'] ?? '' ) );
			$state['tested_wp'] = sanitize_text_field( (string) ( $update['testedWp'] ?? '' ) );
			$state['size']      = isset( $update['size'] ) ? (string) (int) $update['size'] : '';
		}

		$state['checked_at'] = current_time( 'mysql', true );

		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Indica si hay que volver a preguntar.
	 */
	public function is_stale(): bool {
		$checked = $this->all()['checked_at'];

		if ( '' === $checked ) {
			return true;
		}

		$momento = strtotime( $checked . ' UTC' );

		if ( false === $momento ) {
			return true;
		}

		return ( time() - $momento ) >= self::TTL_HOURS * HOUR_IN_SECONDS;
	}

	/**
	 * Indica si la versión guardada es más nueva que la instalada.
	 *
	 * Se compara con `version_compare` y no con una resta: «0.100.0» es mayor
	 * que «0.22.0» aunque como texto parezca lo contrario.
	 */
	public function has_update(): bool {
		$state = $this->all();

		return '' !== $state['version']
			&& '' !== $state['package']
			&& version_compare( $state['version'], NEXO_MCP_VERSION, '>' );
	}

	/**
	 * Olvida lo guardado, para que la próxima consulta vaya a la plataforma.
	 */
	public function forget(): void {
		delete_option( self::OPTION_NAME );
	}
}
