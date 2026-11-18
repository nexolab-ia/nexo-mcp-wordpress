<?php
/**
 * Variables globales de Elementor: crear, modificar y borrar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Cada operación pasa por `DesignSystemWriter::write()`: `validate` hace toda la validación
 * (formato, unicidad, límite, tipo, uso) y devuelve el plan; `apply` sólo escribe.
 */
final class DesignVariableOperations {

	/**
	 * Máximo de variables activas de Elementor.
	 */
	public const MAX_ACTIVE = 1000;

	/**
	 * Máximo de caracteres de la etiqueta.
	 */
	public const MAX_LABEL = 50;

	/**
	 * Etiqueta segura como nombre de propiedad CSS personalizada.
	 */
	private const LABEL_PATTERN = '/^[a-zA-Z0-9_-]+$/D';

	/**
	 * Tipos entre los que se puede cambiar una variable existente.
	 *
	 * @var array<int, string>
	 */
	private const SIZE_TYPES = array( DesignVariableValue::SIZE, DesignVariableValue::CUSTOM_SIZE );

	/**
	 * Escritor con bloqueo, respaldo y Actividad.
	 *
	 * @var DesignSystemWriter
	 */
	private DesignSystemWriter $writer;

	/**
	 * Variables de Elementor.
	 *
	 * @var DesignVariableStore
	 */
	private DesignVariableStore $variables;

	/**
	 * Construye las operaciones.
	 *
	 * @param DesignSystemWriter       $writer    Escritor.
	 * @param DesignVariableStore|null $variables Variables; por defecto las de Elementor.
	 */
	public function __construct( DesignSystemWriter $writer, ?DesignVariableStore $variables = null ) {
		$this->writer    = $writer;
		$this->variables = $variables ?? new DesignVariableElementorStore();
	}

	/**
	 * Crea una variable.
	 *
	 * @param array<string, mixed> $params `type`, `label`, `value`.
	 *
	 * @return array<string, mixed>|\WP_Error `{id, type, label, value}`.
	 */
	public function create( array $params ) {
		return $this->writer->write(
			'variables',
			'create-variable',
			function () use ( $params ) {
				$type = $params['type'] ?? null;

				if ( ! is_string( $type ) || ! in_array( $type, DesignVariableValue::types(), true ) ) {
					return $this->invalid(
						'type',
						sprintf(
							/* translators: %s: tipos. */
							__( 'debe ser uno de: %s.', 'nexo-mcp' ),
							implode( ', ', DesignVariableValue::types() )
						)
					);
				}

				$label = $this->check_label( $params['label'] ?? null );

				if ( is_wp_error( $label ) ) {
					return $label;
				}

				$value = $this->check_value( $type, $params['value'] ?? null );

				if ( is_wp_error( $value ) ) {
					return $value;
				}

				$all     = $this->variables->variables();
				$taken   = $this->check_label_free( $all, $label, null );
				$limited = is_wp_error( $taken ) ? $taken : $this->check_limit( $all );

				if ( is_wp_error( $limited ) ) {
					return $limited;
				}

				return array(
					'type'  => $type,
					'label' => $label,
					'value' => $value,
				);
			},
			fn( array $plan ) => $this->variables->create( $plan['type'], $plan['label'], $plan['value'] ),
			$this->audit_params( $params, array( 'type', 'label', 'value' ) )
		);
	}

	/**
	 * Modifica una variable (el tipo sólo entre los dos de tamaño).
	 *
	 * @param array<string, mixed> $params `id` y al menos uno de `label`, `value`, `type`.
	 *
	 * @return array<string, mixed>|\WP_Error `{id, type, label, value}`.
	 */
	public function update( array $params ) {
		return $this->writer->write(
			'variables',
			'update-variable',
			function () use ( $params ) {
				$all     = $this->variables->variables();
				$id      = $this->active_id( $all, $params['id'] ?? null );
				$current = is_string( $id ) ? $all[ $id ] : null;

				if ( ! is_string( $id ) || null === $current ) {
					return $id;
				}

				$has = static fn( string $key ): bool => isset( $params[ $key ] );

				if ( ! $has( 'label' ) && ! $has( 'value' ) && ! $has( 'type' ) ) {
					return $this->invalid( 'id', __( 'indicá qué cambiar: label, value o type.', 'nexo-mcp' ) );
				}

				$changes = array();
				$type    = $current['type'];

				if ( $has( 'type' ) ) {
					$wanted = $params['type'];

					if ( ! is_string( $wanted ) || ! in_array( $wanted, DesignVariableValue::types(), true ) ) {
						return $this->invalid(
							'type',
							sprintf(
								/* translators: %s: tipos. */
								__( 'debe ser uno de: %s.', 'nexo-mcp' ),
								implode( ', ', DesignVariableValue::types() )
							)
						);
					}

					if ( $wanted !== $type ) {
						if ( ! in_array( $type, self::SIZE_TYPES, true ) || ! in_array( $wanted, self::SIZE_TYPES, true ) ) {
							return $this->invalid( 'type', __( 'el tipo sólo se puede cambiar entre global-size-variable y global-custom-size-variable.', 'nexo-mcp' ) );
						}

						$type            = $wanted;
						$changes['type'] = $wanted;
					}
				}

				$label = $current['label'];

				if ( $has( 'label' ) ) {
					$checked = $this->check_label( $params['label'] );

					if ( is_wp_error( $checked ) ) {
						return $checked;
					}

					$taken = $this->check_label_free( $all, $checked, $id );

					if ( is_wp_error( $taken ) ) {
						return $taken;
					}

					$label = $checked;

					if ( $checked !== $current['label'] ) {
						$changes['label'] = $checked;
					}
				}

				$value = $current['value'];

				if ( $has( 'value' ) ) {
					$checked = $this->check_value( $type, $params['value'] );

					if ( is_wp_error( $checked ) ) {
						return $checked;
					}

					$value = $checked;

					if ( $checked !== $current['value'] ) {
						$changes['value'] = $checked;
					}
				} elseif ( isset( $changes['type'] ) ) {
					// Cambió el tipo sin valor nuevo: el valor guardado tiene que valer para el tipo nuevo.
					$checked = $this->check_value( $type, $current['value'] );

					if ( is_wp_error( $checked ) ) {
						return $checked;
					}
				}

				return array(
					'id'      => $id,
					'changes' => $changes,
					'result'  => array(
						'id'    => $id,
						'type'  => $type,
						'label' => $label,
						'value' => $value,
					),
				);
			},
			function ( array $plan ) {
				if ( array() === $plan['changes'] ) {
					return $plan['result'];
				}

				return $this->variables->update( $plan['id'], $plan['changes'] );
			},
			$this->audit_params( $params, array( 'id', 'label', 'value', 'type' ) )
		);
	}

	/**
	 * Borra una variable (borrado lógico de Elementor).
	 *
	 * @param array<string, mixed> $params `id` y `force` (opcional).
	 *
	 * @return array<string, mixed>|\WP_Error `{deleted, pages_affected}`.
	 */
	public function delete( array $params ) {
		return $this->writer->write(
			'variables',
			'delete-variable',
			function () use ( $params ) {
				$all = $this->variables->variables();
				$id  = $this->active_id( $all, $params['id'] ?? null );

				if ( ! is_string( $id ) ) {
					return $id;
				}

				$force = $params['force'] ?? false;

				if ( ! is_bool( $force ) ) {
					return $this->invalid( 'force', __( 'debe ser true o false.', 'nexo-mcp' ) );
				}

				$used = $this->variables->used_in( $id );

				if ( array() !== $used && ! $force ) {
					/* translators: 1: etiqueta, 2: cantidad de contenidos. */
					$message = 1 === count( $used )
						? /* translators: 1: etiqueta, 2: cantidad de contenidos. */ __( 'No se borró la variable «%1$s»: se usa en %2$d contenido. Pasá force: true para borrarla igual.', 'nexo-mcp' )
						: /* translators: 1: etiqueta, 2: cantidad de contenidos. */ __( 'No se borró la variable «%1$s»: se usa en %2$d contenidos. Pasá force: true para borrarla igual.', 'nexo-mcp' );

					return new \WP_Error(
						'nexo_mcp_design_in_use',
						sprintf( $message, $all[ $id ]['label'], count( $used ) ),
						array(
							'id'      => $id,
							'used_in' => $used,
						)
					);
				}

				return array(
					'id'    => $id,
					'pages' => $used,
				);
			},
			function ( array $plan ) {
				$this->variables->delete( $plan['id'] );

				$result = array(
					'deleted'        => $plan['id'],
					'pages_affected' => $plan['pages'],
				);

				if ( array() !== $plan['pages'] ) {
					$result['undo_note'] = __( 'Las páginas que usaban la variable conservan una referencia a una variable borrada hasta que se deshaga el cambio: deshacer la restaura.', 'nexo-mcp' );
				}

				return $result;
			},
			$this->audit_params( $params, array( 'id', 'force' ) )
		);
	}

	/**
	 * Id de una variable activa, o `nexo_mcp_design_not_found`.
	 *
	 * @param array<string, array{type: string, label: string, value: string, deleted: bool}> $all Colección.
	 * @param mixed                                                                           $id  Id pedido.
	 *
	 * @return string|\WP_Error
	 */
	private function active_id( array $all, $id ) {
		if ( is_string( $id ) && isset( $all[ $id ] ) && ! $all[ $id ]['deleted'] ) {
			return $id;
		}

		$shown = is_string( $id ) ? $id : '';

		return new \WP_Error(
			'nexo_mcp_design_not_found',
			sprintf(
				/* translators: %s: id. */
				__( 'No existe una variable con el id «%s» (o ya está borrada).', 'nexo-mcp' ),
				$shown
			),
			array( 'id' => $shown )
		);
	}

	/**
	 * Etiqueta: identificador CSS seguro (letras, números, - y _), de 1 a 50 caracteres.
	 *
	 * @param mixed $label Etiqueta pedida.
	 *
	 * @return string|\WP_Error
	 */
	private function check_label( $label ) {
		if ( ! is_string( $label ) || '' === $label ) {
			return $this->invalid( 'label', __( 'es obligatoria y tiene que ser texto.', 'nexo-mcp' ) );
		}

		// Elementor escribe `--{label}:{valor};` dentro de `:root {}` del CSS del Kit y sólo le pasa
		// `sanitize_text_field`: la etiqueta tiene que ser un identificador de propiedad CSS seguro.
		if ( 1 !== preg_match( self::LABEL_PATTERN, $label ) || str_starts_with( $label, '--' ) ) {
			return $this->invalid( 'label', __( 'sólo puede tener letras sin tildes, números, guiones y guiones bajos (se usa como nombre de la propiedad CSS --etiqueta) y no puede empezar con «--».', 'nexo-mcp' ) );
		}

		if ( mb_strlen( $label ) > self::MAX_LABEL ) {
			return $this->invalid(
				'label',
				sprintf(
					/* translators: %d: máximo. */
					__( 'no puede pasar de %d caracteres.', 'nexo-mcp' ),
					self::MAX_LABEL
				)
			);
		}

		return $label;
	}

	/**
	 * Valor recortado y validado contra el tipo.
	 *
	 * @param string $type  Tipo.
	 * @param mixed  $value Valor pedido.
	 *
	 * @return string|\WP_Error
	 */
	private function check_value( string $type, $value ) {
		if ( ! is_string( $value ) ) {
			return $this->invalid( 'value', __( 'es obligatorio y tiene que ser texto.', 'nexo-mcp' ) );
		}

		$value   = trim( $value );
		$checked = DesignVariableValue::check( $type, $value );

		return true === $checked ? $value : $this->invalid( 'value', $checked );
	}

	/**
	 * Etiqueta única sin distinguir mayúsculas entre las no borradas.
	 *
	 * @param array<string, array{type: string, label: string, value: string, deleted: bool}> $all    Colección.
	 * @param string                                                                          $label  Etiqueta.
	 * @param string|null                                                                     $ignore Id que se ignora (la propia variable).
	 *
	 * @return true|\WP_Error
	 */
	private function check_label_free( array $all, string $label, ?string $ignore ) {
		foreach ( $all as $id => $variable ) {
			if ( $variable['deleted'] || (string) $id === $ignore ) {
				continue;
			}

			if ( 0 === strcasecmp( $variable['label'], $label ) ) {
				return new \WP_Error(
					'nexo_mcp_design_label_taken',
					sprintf(
						/* translators: %s: etiqueta. */
						__( 'label: ya existe una variable con la etiqueta «%s». Elegí otra.', 'nexo-mcp' ),
						$label
					),
					array(
						'path' => 'label',
						'id'   => (string) $id,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Tope de variables activas.
	 *
	 * @param array<string, array{type: string, label: string, value: string, deleted: bool}> $all Colección.
	 *
	 * @return true|\WP_Error
	 */
	private function check_limit( array $all ) {
		$active = count( array_filter( $all, static fn( array $variable ): bool => ! $variable['deleted'] ) );

		if ( $active >= self::MAX_ACTIVE ) {
			return new \WP_Error(
				'nexo_mcp_design_limit_reached',
				sprintf(
					/* translators: %d: máximo. */
					__( 'Ya hay %d variables activas, que es el máximo de Elementor. Borrá alguna antes de crear otra.', 'nexo-mcp' ),
					self::MAX_ACTIVE
				)
			);
		}

		return true;
	}

	/**
	 * Error de validación: mensaje `ruta: motivo` y la ruta en los datos.
	 *
	 * @param string $path   Campo.
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_design_invalid',
			$path . ': ' . $reason,
			array( 'path' => $path )
		);
	}

	/**
	 * Parámetros para Actividad: sólo los campos conocidos.
	 *
	 * @param array<string, mixed> $params Parámetros recibidos.
	 * @param array<int, string>   $keys   Campos que se registran.
	 *
	 * @return array<string, mixed>
	 */
	private function audit_params( array $params, array $keys ): array {
		return array_intersect_key( $params, array_flip( $keys ) );
	}
}
