<?php
/**
 * Diseño global de Divi 5: lectura, escritura con respaldo, historial y deshacer.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * Paleta global, variables, colores del Customizer y tipografía base de Divi 5.
 *
 * Toda escritura sigue el mismo protocolo, con el bloqueo propio alrededor:
 * leer el estado, guardar el valor anterior del área tocada en la opción no
 * autocargada {@see self::HISTORY_OPTION} (últimos 20 cambios), escribir con las
 * funciones de Divi (un `update_option` crudo no regenera el CSS), vaciar el CSS
 * generado y dejar registro en Actividad. Deshacer restaura el último cambio con
 * las mismas funciones y lo saca de la pila.
 *
 * Cada dependencia de Divi se inyecta como callable para poder probarla sin Divi.
 */
final class DiviDesignStore {

	/**
	 * Opción (sin autocarga) con el historial de cambios.
	 */
	public const HISTORY_OPTION = 'nexo_mcp_divi_design_history';

	/**
	 * Cambios que se conservan.
	 */
	public const HISTORY_LIMIT = 20;

	/**
	 * Tipos de variable que se leen.
	 *
	 * @var array<int, string>
	 */
	public const READ_TYPES = array( 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' );

	/**
	 * Tipos de variable que se escriben (los gradientes son sólo de lectura).
	 *
	 * @var array<int, string>
	 */
	public const WRITE_TYPES = array( 'numbers', 'strings', 'images', 'links', 'fonts' );

	/**
	 * Colores del Customizer: nombre público => clave en `et_divi`.
	 *
	 * @var array<string, string>
	 */
	public const CUSTOMIZER_COLORS = array(
		'accent'           => 'accent_color',
		'secondary_accent' => 'secondary_accent_color',
		'header'           => 'header_color',
		'font'             => 'font_color',
		'link'             => 'link_color',
	);

	/**
	 * Ids de los colores del Customizer dentro de la paleta de Divi.
	 *
	 * @var array<int, string>
	 */
	public const CUSTOMIZER_COLOR_IDS = array( 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color' );

	/**
	 * Tipografía: nombre público => clave en `et_divi`.
	 *
	 * @var array<string, string>
	 */
	public const TYPOGRAPHY = array(
		'heading_font'        => 'heading_font',
		'body_font'           => 'body_font',
		'heading_font_weight' => 'heading_font_weight',
		'body_font_weight'    => 'body_font_weight',
		'body_font_size'      => 'body_font_size',
		'body_line_height'    => 'body_font_height',
	);

	/**
	 * Clave de bloqueo (no es un contenido: sólo da nombre a la cerradura).
	 */
	private const LOCK_ID = 0;

	/**
	 * Fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Fn(): array. Colores globales guardados.
	 *
	 * @var callable
	 */
	private $get_colors;

	/**
	 * Fn( array ): void. Escribe la lista completa de colores globales.
	 *
	 * @var callable
	 */
	private $set_colors;

	/**
	 * Fn(): array. Variables guardadas, por tipo.
	 *
	 * @var callable
	 */
	private $get_variables;

	/**
	 * Fn( array ): null|\WP_Error|true. Escribe todas las variables.
	 *
	 * @var callable
	 */
	private $set_variables;

	/**
	 * Fn(): array. Opciones crudas de `et_divi`.
	 *
	 * @var callable
	 */
	private $get_options;

	/**
	 * Fn( string, mixed ): void. Escribe una opción de `et_divi` (null la quita).
	 *
	 * @var callable
	 */
	private $set_option;

	/**
	 * Fn(): void. Vacía el CSS generado.
	 *
	 * @var callable
	 */
	private $flush;

	/**
	 * Fn( tool, status, params, result ): void.
	 *
	 * @var callable
	 */
	private $audit;

	/**
	 * Fn( callable ): mixed. Ejecuta el trabajo bajo el bloqueo.
	 *
	 * @var callable
	 */
	private $lock;

	/**
	 * Construye el almacén; sin argumentos usa Divi 5 de verdad.
	 *
	 * @param callable|null $divi5_active  fn(): bool.
	 * @param callable|null $get_colors    fn(): array.
	 * @param callable|null $set_colors    fn( array $all ): void.
	 * @param callable|null $get_variables fn(): array.
	 * @param callable|null $set_variables fn( array $all ): null|\WP_Error|true.
	 * @param callable|null $get_options   fn(): array.
	 * @param callable|null $set_option    fn( string $key, mixed $value ): void.
	 * @param callable|null $flush         fn(): void.
	 * @param callable|null $audit         fn( string $tool, string $status, mixed $params, mixed $result ): void.
	 * @param callable|null $lock          fn( callable $work ): mixed.
	 */
	public function __construct(
		?callable $divi5_active = null,
		?callable $get_colors = null,
		?callable $set_colors = null,
		?callable $get_variables = null,
		?callable $set_variables = null,
		?callable $get_options = null,
		?callable $set_option = null,
		?callable $flush = null,
		?callable $audit = null,
		?callable $lock = null
	) {
		$this->divi5_active  = $divi5_active ?? static fn(): bool => Divi5ContentWriter::divi5_installed();
		$this->get_colors    = $get_colors ?? array( self::class, 'divi_get_colors' );
		$this->set_colors    = $set_colors ?? array( self::class, 'divi_set_colors' );
		$this->get_variables = $get_variables ?? array( self::class, 'divi_get_variables' );
		$this->set_variables = $set_variables ?? array( self::class, 'divi_set_variables' );
		$this->get_options   = $get_options ?? static function (): array {
			$options = get_option( 'et_divi', array() );

			return is_array( $options ) ? $options : array();
		};
		$this->set_option    = $set_option ?? array( self::class, 'divi_set_option' );
		$this->flush         = $flush ?? array( self::class, 'divi_flush' );
		$this->audit         = $audit ?? static function ( string $tool, string $status, $params, $result ): void {
			( new AuditLogger( new AuditRepository() ) )->log( $tool, 'write', $status, $params, $result );
		};
		$this->lock          = $lock ?? static fn( callable $work ) => PostLock::run( self::LOCK_ID, $work );
	}

	/**
	 * Indica si Divi 5 está activo.
	 */
	public function available(): bool {
		return (bool) ( $this->divi5_active )();
	}

	/**
	 * Lee el diseño global.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read() {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$colors = array();

		foreach ( $this->colors() as $id => $color ) {
			$colors[] = array(
				'id'     => (string) $id,
				'label'  => isset( $color['label'] ) && is_scalar( $color['label'] ) ? (string) $color['label'] : '',
				'color'  => isset( $color['color'] ) && is_scalar( $color['color'] ) ? (string) $color['color'] : '',
				'status' => isset( $color['status'] ) && is_scalar( $color['status'] ) ? (string) $color['status'] : 'active',
			);
		}

		$stored    = $this->variables();
		$variables = array();

		foreach ( self::READ_TYPES as $type ) {
			$variables[ $type ] = array();
			$list               = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? $stored[ $type ] : array();

			foreach ( $list as $id => $variable ) {
				if ( ! is_array( $variable ) ) {
					continue;
				}

				$variables[ $type ][] = array(
					'id'     => (string) $id,
					'label'  => isset( $variable['label'] ) && is_scalar( $variable['label'] ) ? (string) $variable['label'] : '',
					'value'  => isset( $variable['value'] ) && is_scalar( $variable['value'] ) ? (string) $variable['value'] : '',
					'status' => isset( $variable['status'] ) && is_scalar( $variable['status'] ) ? (string) $variable['status'] : 'active',
				);
			}
		}

		$options    = $this->options();
		$customizer = array();
		$typography = array();

		foreach ( self::CUSTOMIZER_COLORS as $name => $key ) {
			$customizer[ $name ] = $this->scalar_option( $options, $key );
		}

		foreach ( self::TYPOGRAPHY as $name => $key ) {
			$typography[ $name ] = $this->scalar_option( $options, $key );
		}

		return array(
			'colors'            => $colors,
			'variables'         => $variables,
			'customizer_colors' => $customizer,
			'typography'        => $typography,
			'history_count'     => count( $this->history() ),
		);
	}

	/**
	 * Escribe la lista completa de colores globales.
	 *
	 * @param array<string, mixed> $all  Lista completa; los ids de los colores del Customizer se ignoran.
	 * @param string               $tool Herramienta que escribe (Actividad e historial).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function write_colors( array $all, string $tool ) {
		return $this->update_colors( static fn(): array => $all, $tool );
	}

	/**
	 * Cambia los colores globales: lee, cambia y escribe la lista completa bajo el mismo bloqueo.
	 *
	 * @param callable $mutate fn( array $current ): array|\WP_Error. Recibe la lista actual (sin los ids del
	 *                         Customizer, que viven en `et_divi`) y devuelve la completa; un error no escribe nada.
	 * @param string   $tool   Herramienta que escribe.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_colors( callable $mutate, string $tool ) {
		return $this->write(
			$tool,
			'colors',
			function () use ( $mutate ) {
				$previous = $this->colors();
				$new      = $mutate( $this->without_customizer_ids( $previous ) );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				// Los 5 colores del Customizer viven en `et_divi` (no en esta lista): `set_global_colors` los
				// escribiría sin respaldo, así que se quitan; se cambian con `write_theme_options`.
				$new = $this->without_customizer_ids( $new );

				return array(
					'previous' => $previous,
					'written'  => $new,
					'apply'    => function () use ( $new ) {
						( $this->set_colors )( $new );

						return null;
					},
				);
			}
		);
	}

	/**
	 * Escribe la lista completa de variables de un tipo.
	 *
	 * @param string               $type Numbers, strings, images, links o fonts.
	 * @param array<string, mixed> $all  Lista completa de ese tipo.
	 * @param string               $tool Herramienta que escribe.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function write_variables( string $type, array $all, string $tool ) {
		return $this->update_variables( $type, static fn(): array => $all, $tool );
	}

	/**
	 * Cambia las variables de un tipo: lee, cambia y escribe bajo el mismo bloqueo.
	 *
	 * @param string   $type   Numbers, strings, images, links o fonts.
	 * @param callable $mutate fn( array $current ): array|\WP_Error. Recibe la lista de ese tipo y devuelve la completa.
	 * @param string   $tool   Herramienta que escribe.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_variables( string $type, callable $mutate, string $tool ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		if ( ! in_array( $type, self::WRITE_TYPES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_design_type_invalid',
				sprintf(
					/* translators: %s: tipos admitidos. */
					__( 'Tipo de variable no admitido. Se pueden escribir: %s (los gradientes son sólo de lectura).', 'nexo-mcp' ),
					implode( ', ', self::WRITE_TYPES )
				)
			);
		}

		return $this->write(
			$tool,
			'variables:' . $type,
			function () use ( $type, $mutate ) {
				$stored   = $this->variables();
				$previous = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? $stored[ $type ] : null;
				$new      = $mutate( $previous ?? array() );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				return array(
					'previous' => $previous,
					'written'  => $new,
					'apply'    => function () use ( $type, $new ) {
						$stored          = $this->variables();
						$stored[ $type ] = $new;

						return ( $this->set_variables )( $stored );
					},
				);
			}
		);
	}

	/**
	 * Escribe opciones de `et_divi` (colores del Customizer, fuentes y tipografía).
	 *
	 * Todo o nada: si una opción falla, las ya escritas vuelven a su valor anterior y no queda historial.
	 *
	 * @param array<string, mixed> $changes Clave de `et_divi` => valor nuevo.
	 * @param string               $tool    Herramienta que escribe.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function write_theme_options( array $changes, string $tool ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$allowed = array_merge( array_values( self::CUSTOMIZER_COLORS ), array_values( self::TYPOGRAPHY ) );
		$invalid = array_diff( array_map( 'strval', array_keys( $changes ) ), $allowed );

		if ( array() === $changes || array() !== $invalid ) {
			return new \WP_Error(
				'nexo_mcp_divi_design_option_invalid',
				array() === $changes
					? __( 'No hay opciones de Divi que cambiar.', 'nexo-mcp' )
					: sprintf(
						/* translators: %s: claves rechazadas. */
						__( 'Opciones de Divi no admitidas: %s.', 'nexo-mcp' ),
						implode( ', ', $invalid )
					)
			);
		}

		return $this->write(
			$tool,
			'theme_options',
			function () use ( $changes ) {
				$options  = $this->options();
				$previous = array();

				foreach ( array_keys( $changes ) as $key ) {
					$previous[ (string) $key ] = array_key_exists( $key, $options ) ? $options[ $key ] : null;
				}

				return array(
					'previous' => $previous,
					'written'  => $changes,
					'apply'    => function () use ( $changes, $previous ) {
						$done = array();

						try {
							foreach ( $changes as $key => $value ) {
								$done[] = (string) $key;
								( $this->set_option )( (string) $key, $value );
							}
						} catch ( \Throwable $e ) {
							$this->revert_options( $done, $previous );

							throw $e;
						}

						return null;
					},
				);
			}
		);
	}

	/**
	 * Colores globales actuales (sin bloqueo; para validar antes de escribir).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function current_colors(): array {
		return $this->colors();
	}

	/**
	 * Variables actuales de un tipo (sin bloqueo; para validar antes de escribir).
	 *
	 * @param string $type Tipo de variable.
	 *
	 * @return array<string, mixed>
	 */
	public function current_variables( string $type ): array {
		$stored = $this->variables();

		return isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? $stored[ $type ] : array();
	}

	/**
	 * Deshace el último cambio del diseño global.
	 *
	 * @param bool $force Restaurar aunque el área haya cambiado después de lo que escribió el conector.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo( bool $force = false ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		return ( $this->lock )( fn() => $this->undo_locked( $force ) );
	}

	/**
	 * Historial guardado (los más viejos primero); lo malformado se ignora.
	 *
	 * @return array<int, array{area: string, previous: mixed, tool: string, user: int, date: string, written?: mixed}>
	 */
	public function history(): array {
		$stored = get_option( self::HISTORY_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$entries = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['area'] ) || ! is_string( $entry['area'] ) || ! array_key_exists( 'previous', $entry ) ) {
				continue;
			}

			$normal = array(
				'area'     => $entry['area'],
				'previous' => $entry['previous'],
				'tool'     => isset( $entry['tool'] ) && is_string( $entry['tool'] ) ? $entry['tool'] : '',
				'user'     => isset( $entry['user'] ) ? (int) $entry['user'] : 0,
				'date'     => isset( $entry['date'] ) && is_string( $entry['date'] ) ? $entry['date'] : '',
			);

			if ( array_key_exists( 'written', $entry ) ) {
				$normal['written'] = $entry['written'];
			}

			$entries[] = $normal;
		}

		return $entries;
	}

	/**
	 * Protocolo común de escritura: bloqueo, respaldo, escritura, flush y Actividad.
	 *
	 * @param string   $tool Herramienta.
	 * @param string   $area Área respaldada.
	 * @param callable $plan fn(): \WP_Error|array{previous: mixed, written: mixed, apply: callable}. Corre bajo el
	 *                       bloqueo: lee el estado, calcula lo que se escribe y da la función que lo escribe con Divi.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( string $tool, string $area, callable $plan ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		return ( $this->lock )( fn() => $this->write_locked( $tool, $area, $plan ) );
	}

	/**
	 * Cuerpo de `write()` bajo el bloqueo.
	 *
	 * @param string   $tool Herramienta.
	 * @param string   $area Área respaldada.
	 * @param callable $plan Plan de escritura.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write_locked( string $tool, string $area, callable $plan ) {
		try {
			$step = $plan();
		} catch ( \Throwable $e ) {
			return $this->fail( $tool, 'nexo_mcp_divi_design_write_failed', $this->message( $e ) );
		}

		if ( is_wp_error( $step ) ) {
			return $step;
		}

		$old_history = get_option( self::HISTORY_OPTION, null );
		$history     = $this->history();
		$history[]   = array(
			'area'     => $area,
			'previous' => $step['previous'],
			'written'  => $step['written'],
			'tool'     => $tool,
			'user'     => get_current_user_id(),
			'date'     => gmdate( 'Y-m-d H:i:s' ),
		);
		$history     = array_slice( $history, -self::HISTORY_LIMIT );

		// `update_option()` devuelve false también cuando nada cambia: lo que cuenta es lo guardado.
		update_option( self::HISTORY_OPTION, $history, false );

		if ( get_option( self::HISTORY_OPTION, null ) !== $history ) {
			$this->restore_history( $old_history );

			return $this->fail(
				$tool,
				'nexo_mcp_divi_design_backup_failed',
				__( 'No se pudo guardar el respaldo del estado actual, así que no se escribió nada.', 'nexo-mcp' )
			);
		}

		try {
			$written = ( $step['apply'] )();
		} catch ( \Throwable $e ) {
			$this->restore_history( $old_history );

			return $this->fail( $tool, 'nexo_mcp_divi_design_write_failed', $this->message( $e ) );
		}

		if ( is_wp_error( $written ) ) {
			$this->restore_history( $old_history );

			return $this->fail( $tool, 'nexo_mcp_divi_design_write_failed', $written->get_error_message() );
		}

		$this->flush();
		$this->record_actual_state( $history, $area, $step['written'] );

		$result = array(
			'area'           => $area,
			'undo_available' => true,
		);

		$this->log( $tool, 'success', array( 'area' => $area ), $result );

		return $result;
	}

	/**
	 * Si Divi guardó el área distinta de lo pedido (normaliza), el historial recuerda lo que quedó guardado.
	 *
	 * Un fallo aquí es seguro: deshacer se negaría y `force` lo permite.
	 *
	 * @param array<int, array<string, mixed>> $history Historial recién guardado.
	 * @param string                           $area    Área.
	 * @param mixed                            $written Lo que se pidió escribir.
	 */
	private function record_actual_state( array $history, string $area, $written ): void {
		try {
			$actual = $this->area_state( $area, $written );

			if ( $this->same_state( $actual, $written ) ) {
				return;
			}

			$last                        = array_key_last( $history );
			$history[ $last ]['written'] = $actual;
			update_option( self::HISTORY_OPTION, $history, false );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Estado actual de un área, en la forma en que se escribió.
	 *
	 * @param string $area    Área.
	 * @param mixed  $written Lo escrito (para saber qué claves de las opciones mirar).
	 *
	 * @return mixed
	 */
	private function area_state( string $area, $written ) {
		if ( 'colors' === $area ) {
			return $this->without_customizer_ids( $this->colors() );
		}

		if ( str_starts_with( $area, 'variables:' ) ) {
			return $this->current_variables( substr( $area, 10 ) );
		}

		$options = $this->options();
		$state   = array();

		foreach ( is_array( $written ) ? array_keys( $written ) : array() as $key ) {
			$state[ (string) $key ] = array_key_exists( $key, $options ) ? $options[ $key ] : null;
		}

		return $state;
	}

	/**
	 * Igualdad de estados: mismas claves y valores; un número y su texto son lo mismo (Divi guarda `'14'` o `14`).
	 *
	 * @param mixed $a Un estado.
	 * @param mixed $b Otro.
	 */
	private function same_state( $a, $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}

			foreach ( $a as $key => $value ) {
				if ( ! array_key_exists( $key, $b ) || ! $this->same_state( $value, $b[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}

		return $a === $b;
	}

	/**
	 * Quita los ids de los colores del Customizer de una lista de colores.
	 *
	 * @param array<string, mixed> $colors Lista.
	 *
	 * @return array<string, mixed>
	 */
	private function without_customizer_ids( array $colors ): array {
		foreach ( array_keys( $colors ) as $id ) {
			if ( in_array( $id, self::CUSTOMIZER_COLOR_IDS, true ) ) {
				unset( $colors[ $id ] );
			}
		}

		return $colors;
	}

	/**
	 * Devuelve las opciones ya escritas a su valor anterior (todo o nada); un fallo aquí no tapa el original.
	 *
	 * @param array<int, string>   $keys     Claves tocadas.
	 * @param array<string, mixed> $previous Valores anteriores (null = no existía).
	 */
	private function revert_options( array $keys, array $previous ): void {
		foreach ( array_reverse( $keys ) as $key ) {
			try {
				( $this->set_option )( $key, $previous[ $key ] ?? null );
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		$this->flush();
	}

	/**
	 * Cuerpo de `undo()` bajo el bloqueo.
	 *
	 * @param bool $force Restaurar aunque el área haya cambiado después.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function undo_locked( bool $force ) {
		// Se relee dentro del bloqueo: otro proceso pudo escribir o deshacer mientras se esperaba.
		$history = $this->history();
		$entry   = end( $history );
		$tool    = 'undo-divi-design-change';

		if ( false === $entry ) {
			return new \WP_Error(
				'nexo_mcp_divi_design_nothing_to_undo',
				__( 'No hay ningún cambio del diseño global de Divi para deshacer.', 'nexo-mcp' )
			);
		}

		// Deshacer reemplaza el área entera: si alguien la cambió después (p. ej. desde el panel de Divi) se perdería.
		if ( ! $force && array_key_exists( 'written', $entry ) && ! $this->same_state( $this->area_state( $entry['area'], $entry['written'] ), $entry['written'] ) ) {
			return $this->fail(
				$tool,
				'nexo_mcp_divi_design_changed_since',
				__( 'Esta área del diseño global cambió después del último cambio del conector (por ejemplo desde el panel de Divi); deshacer pisaría ese cambio y no se restauró nada. Repite con force: true para restaurarla igual.', 'nexo-mcp' )
			);
		}

		try {
			$this->restore( $entry['area'], $entry['previous'] );
		} catch ( \Throwable $e ) {
			$this->flush();

			return $this->fail(
				$tool,
				'nexo_mcp_divi_design_undo_failed',
				sprintf(
					/* translators: %s: motivo. */
					__( 'No se pudo deshacer el último cambio: %s. El respaldo se conserva.', 'nexo-mcp' ),
					$this->message( $e )
				)
			);
		}

		array_pop( $history );
		update_option( self::HISTORY_OPTION, $history, false );
		$this->flush();

		// Lo que cuenta es lo guardado: si la entrada sigue en la pila no se informa éxito.
		if ( get_option( self::HISTORY_OPTION, null ) !== $history ) {
			return $this->fail(
				$tool,
				'nexo_mcp_divi_design_history_failed',
				__( 'El valor anterior se restauró, pero no se pudo sacar el cambio del historial: sigue en la pila y deshacerlo de nuevo pedirá force. Revisa el estado con get-divi-design-system.', 'nexo-mcp' )
			);
		}

		$result = array(
			'area'       => $entry['area'],
			'tool'       => $entry['tool'],
			'changed_at' => $entry['date'],
			'remaining'  => count( $history ),
		);

		$this->log( $tool, 'success', array( 'area' => $entry['area'] ), $result );

		return $result;
	}

	/**
	 * Devuelve un área a su valor anterior con las funciones de Divi.
	 *
	 * @param string $area     Área.
	 * @param mixed  $previous Valor guardado.
	 *
	 * @throws \RuntimeException Si Divi rechaza la restauración o el área no se conoce.
	 */
	private function restore( string $area, $previous ): void {
		if ( 'colors' === $area && is_array( $previous ) ) {
			( $this->set_colors )( $this->without_customizer_ids( $previous ) );

			return;
		}

		if ( 'theme_options' === $area && is_array( $previous ) ) {
			foreach ( $previous as $key => $value ) {
				( $this->set_option )( (string) $key, $value );
			}

			return;
		}

		if ( str_starts_with( $area, 'variables:' ) ) {
			$type = substr( $area, 10 );

			if ( ! in_array( $type, self::WRITE_TYPES, true ) || ( null !== $previous && ! is_array( $previous ) ) ) {
				throw new \RuntimeException( esc_html__( 'El respaldo de variables no es válido.', 'nexo-mcp' ) );
			}

			$stored = $this->variables();
			$list   = is_array( $previous ) ? $previous : array();

			// Divi rechaza una lista a la que le falta un id guardado: lo creado después se archiva, no se borra.
			$current = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? $stored[ $type ] : array();

			foreach ( $current as $id => $variable ) {
				if ( ! array_key_exists( $id, $list ) && is_array( $variable ) ) {
					$variable['status'] = 'archived';
					$list[ $id ]        = $variable;
				}
			}

			$stored[ $type ] = $list;
			$result          = ( $this->set_variables )( $stored );

			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}

			return;
		}

		throw new \RuntimeException( esc_html__( 'El respaldo no tiene una forma que se pueda restaurar.', 'nexo-mcp' ) );
	}

	/**
	 * Deja el historial como estaba antes de un intento fallido.
	 *
	 * @param mixed $old Valor anterior de la opción (null = no existía).
	 */
	private function restore_history( $old ): void {
		if ( null === $old ) {
			delete_option( self::HISTORY_OPTION );

			return;
		}

		update_option( self::HISTORY_OPTION, $old, false );
	}

	/**
	 * Colores globales guardados.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function colors(): array {
		$colors = ( $this->get_colors )();

		return is_array( $colors ) ? $colors : array();
	}

	/**
	 * Variables guardadas, por tipo.
	 *
	 * @return array<string, mixed>
	 */
	private function variables(): array {
		$variables = ( $this->get_variables )();

		return is_array( $variables ) ? $variables : array();
	}

	/**
	 * Opciones crudas de `et_divi`.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		$options = ( $this->get_options )();

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Valor de una opción como texto, o null si falta.
	 *
	 * @param array<string, mixed> $options Opciones.
	 * @param string               $key     Clave.
	 */
	private function scalar_option( array $options, string $key ): ?string {
		return isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? (string) $options[ $key ] : null;
	}

	/**
	 * Vacía el CSS generado; un fallo no tumba la escritura ya hecha.
	 */
	private function flush(): void {
		try {
			( $this->flush )();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Error de escritura registrado en Actividad.
	 *
	 * @param string $tool    Herramienta.
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 */
	private function fail( string $tool, string $code, string $message ): \WP_Error {
		$this->log( $tool, 'error', array(), array( 'error' => $code ) );

		return new \WP_Error( $code, $message );
	}

	/**
	 * Mensaje de una excepción, con un texto de respaldo si viene vacío.
	 *
	 * @param \Throwable $error Excepción.
	 */
	private function message( \Throwable $error ): string {
		$message = trim( $error->getMessage() );

		return '' !== $message ? $message : __( 'Divi no pudo guardar el cambio.', 'nexo-mcp' );
	}

	/**
	 * Registra en Actividad; un fallo del registro nunca rompe la escritura.
	 *
	 * @param string $tool   Herramienta.
	 * @param string $status success o error.
	 * @param mixed  $params Parámetros.
	 * @param mixed  $result Resultado.
	 */
	private function log( string $tool, string $status, $params, $result ): void {
		try {
			( $this->audit )( $tool, $status, $params, $result );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Colores globales guardados en `et_divi['et_global_data']['global_colors']`.
	 *
	 * @return array<string, mixed>
	 */
	public static function divi_get_colors(): array {
		if ( ! function_exists( 'et_get_option' ) ) {
			return array();
		}

		$data = maybe_unserialize( et_get_option( 'et_global_data' ) );

		return is_array( $data ) && isset( $data['global_colors'] ) && is_array( $data['global_colors'] ) ? $data['global_colors'] : array();
	}

	/**
	 * Escribe los colores globales con `GlobalData::set_global_colors` (también vacía el CSS).
	 *
	 * @param array<string, mixed> $all Lista completa.
	 *
	 * @throws \RuntimeException Si Divi no está disponible.
	 */
	public static function divi_set_colors( array $all ): void {
		$callable = array( '\ET\Builder\Packages\GlobalData\GlobalData', 'set_global_colors' );

		if ( ! is_callable( $callable ) ) {
			throw new \RuntimeException( esc_html__( 'Divi 5 no ofrece la función para guardar los colores globales.', 'nexo-mcp' ) );
		}

		call_user_func( $callable, $all );
	}

	/**
	 * Variables guardadas en `et_divi_global_variables`.
	 *
	 * @return array<string, mixed>
	 */
	public static function divi_get_variables(): array {
		$stored = maybe_unserialize( get_option( 'et_divi_global_variables', array() ) );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Escribe las variables con `GlobalData::set_global_variables`.
	 *
	 * Divi devuelve null sin escribir cuando el usuario no puede gestionar variables: aquí eso es un error.
	 *
	 * @param array<string, mixed> $all Todas las variables.
	 *
	 * @return \WP_Error|null
	 */
	public static function divi_set_variables( array $all ) {
		$callable = array( '\ET\Builder\Packages\GlobalData\GlobalData', 'set_global_variables' );

		if ( ! is_callable( $callable ) ) {
			return new \WP_Error( 'nexo_mcp_divi_design_write_failed', __( 'Divi 5 no ofrece la función para guardar las variables.', 'nexo-mcp' ) );
		}

		if ( ! current_user_can( 'edit_theme_options' ) || ( function_exists( 'et_pb_is_allowed' ) && ! et_pb_is_allowed( 'variables_manager' ) ) ) {
			return new \WP_Error( 'nexo_mcp_divi_design_write_failed', __( 'Este usuario no puede gestionar las variables de diseño de Divi.', 'nexo-mcp' ) );
		}

		$result = call_user_func( $callable, $all );

		return is_wp_error( $result ) ? $result : null;
	}

	/**
	 * Escribe una opción de `et_divi` con `et_update_option`; null la quita.
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor (null = quitar la clave).
	 *
	 * @throws \RuntimeException Si Divi no está disponible.
	 */
	public static function divi_set_option( string $key, $value ): void {
		if ( null === $value ) {
			$options = get_option( 'et_divi', array() );

			if ( is_array( $options ) && array_key_exists( $key, $options ) ) {
				unset( $options[ $key ] );
				update_option( 'et_divi', $options );
			}

			return;
		}

		if ( ! function_exists( 'et_update_option' ) ) {
			throw new \RuntimeException( esc_html__( 'Divi no ofrece la función para guardar sus opciones.', 'nexo-mcp' ) );
		}

		et_update_option( $key, $value );
	}

	/**
	 * Vacía el CSS generado por Divi.
	 */
	public static function divi_flush(): void {
		if ( class_exists( 'ET_Core_PageResource' ) ) {
			\ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
		}
	}
}
