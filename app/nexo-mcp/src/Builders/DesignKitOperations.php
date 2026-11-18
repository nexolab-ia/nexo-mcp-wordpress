<?php
/**
 * Operaciones sobre los colores y la tipografía globales del Kit.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * `update` del Kit: modifica, crea y borra colores y tipografías globales.
 *
 * - Con `id` modifica el item existente (system o custom); sin `id` crea uno custom con `_id` nuevo.
 * - `delete` sólo borra items custom; uno system se rechaza.
 * - Se guardan listas completas (Elementor fusiona por índice las que llegan por `update_settings()`),
 *   sólo las que cambian.
 * - `validate` hace toda la validación y arma el plan; `apply` sólo escribe.
 *
 * Las claves de tipografía salen del control de grupo de tipografía de Elementor 4.4.1
 * (`includes/controls/groups/typography.php`), con sus campos responsivos. Quedan fuera
 * `typography_weight` y `typography_width` (fuentes variables), que sólo existen si un filtro
 * de Elementor los activa.
 */
final class DesignKitOperations {

	private const TITLE_MAX = 100;

	private const LISTS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	/**
	 * Sufijos de los campos responsivos de Elementor (escritorio y dispositivos activables).
	 *
	 * @var array<int, string>
	 */
	private const DEVICES = array( '', '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' );

	/**
	 * Valores de los campos de lista del control de tipografía (`''` = «Default» no se acepta: se quita con null).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SELECTS = array(
		'typography_typography'      => array( 'custom' ),
		'typography_font_weight'     => array( '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ),
		'typography_text_transform'  => array( 'uppercase', 'lowercase', 'capitalize', 'none' ),
		'typography_font_style'      => array( 'normal', 'italic', 'oblique' ),
		'typography_text_decoration' => array( 'underline', 'overline', 'line-through', 'none' ),
	);

	/**
	 * Unidades de los campos de tamaño del control de tipografía (todos responsivos).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SIZES = array(
		'typography_font_size'      => array( 'px', 'em', 'rem', 'vw', 'custom' ),
		'typography_line_height'    => array( 'px', 'em', 'rem', 'lh', 'rlh', 'custom' ),
		'typography_letter_spacing' => array( 'px', 'em', 'rem', 'custom' ),
		'typography_word_spacing'   => array( 'px', 'em', 'rem', 'custom' ),
	);

	private const FAMILY_KEY = 'typography_font_family';

	/**
	 * Escritor con respaldo.
	 *
	 * @var DesignSystemWriter
	 */
	private DesignSystemWriter $writer;

	/**
	 * Listas del Kit.
	 *
	 * @var DesignKitStore
	 */
	private DesignKitStore $store;

	/**
	 * Generador de ids de 7 caracteres hexadecimales.
	 *
	 * @var callable
	 */
	private $ids;

	/**
	 * Construye las operaciones.
	 *
	 * @param DesignSystemWriter  $writer Escritor con bloqueo y respaldo.
	 * @param DesignKitStore|null $store  Listas del Kit; por defecto las de Elementor.
	 * @param callable|null       $ids    Generador de ids (fn(): string); por defecto aleatorio.
	 */
	public function __construct( DesignSystemWriter $writer, ?DesignKitStore $store = null, ?callable $ids = null ) {
		$this->writer = $writer;
		$this->store  = $store ?? new DesignKitElementorStore();
		$this->ids    = $ids ?? static fn(): string => substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Modifica, crea y borra colores y tipografías del Kit.
	 *
	 * @param array<string, mixed> $params `{colors?, typography?, delete?}`.
	 *
	 * @return array<string, mixed>|\WP_Error `{colors, typography}` con la forma de `get-design-system`.
	 */
	public function update( array $params ) {
		return $this->writer->write(
			'kit',
			'update',
			fn() => $this->plan( $params ),
			fn( array $plan ) => $this->commit( $plan ),
			$params
		);
	}

	/**
	 * Escribe las listas del plan y entrega el resultado ya calculado.
	 *
	 * @param array{changed: array<string, array<int, array<string, mixed>>>, result: array<string, mixed>} $plan Plan de `plan()`.
	 *
	 * @return array<string, mixed>
	 */
	private function commit( array $plan ): array {
		$this->store->save( $plan['changed'] );

		return $plan['result'];
	}

	/**
	 * Valida todo y arma el plan; no escribe.
	 *
	 * @param array<string, mixed> $params Petición.
	 *
	 * @return array{changed: array<string, array<int, array<string, mixed>>>, result: array<string, mixed>}|\WP_Error
	 */
	private function plan( array $params ) {
		foreach ( array_keys( $params ) as $key ) {
			if ( ! in_array( $key, array( 'colors', 'typography', 'delete' ), true ) ) {
				return $this->invalid(
					'update',
					sprintf( 'no admite la clave «%s»; sólo colors, typography y delete.', $key )
				);
			}
		}

		$sections = array();

		foreach ( array( 'colors', 'typography', 'delete' ) as $name ) {
			$value = $params[ $name ] ?? null;

			if ( null === $value ) {
				$value = array();
			}

			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return $this->invalid( $name, 'tiene que ser una lista.' );
			}

			$sections[ $name ] = $value;
		}

		if ( array() === $sections['colors'] && array() === $sections['typography'] && array() === $sections['delete'] ) {
			return new \WP_Error(
				'nexo_mcp_design_nothing_to_change',
				__( 'No hay nada que cambiar: mandá colors, typography o delete con al menos un elemento.', 'nexo-mcp' )
			);
		}

		$edits = array();

		foreach ( array( 'colors', 'typography' ) as $name ) {
			$edits[ $name ] = $this->parse_items( $name, $sections[ $name ] );

			if ( is_wp_error( $edits[ $name ] ) ) {
				return $edits[ $name ];
			}
		}

		$deletes = $this->parse_deletes( $sections['delete'], array_merge( $this->ids_of( $edits['colors'] ), $this->ids_of( $edits['typography'] ) ) );

		if ( is_wp_error( $deletes ) ) {
			return $deletes;
		}

		return $this->resolve( $edits, $deletes );
	}

	/**
	 * Valida la forma y los valores de los items de una sección; todavía sin mirar el Kit.
	 *
	 * @param string            $name  colors o typography.
	 * @param array<int, mixed> $items Items de la petición.
	 *
	 * @return array<int, array{index: int, id: string|null, fields: array<string, mixed>}>|\WP_Error
	 */
	private function parse_items( string $name, array $items ) {
		$out  = array();
		$seen = array();

		foreach ( $items as $index => $item ) {
			$path = sprintf( '%s[%d]', $name, $index );

			if ( ! is_array( $item ) || array() !== array_filter( array_keys( $item ), 'is_int' ) ) {
				return $this->invalid( $path, 'tiene que ser un objeto.' );
			}

			$id = null;

			if ( array_key_exists( 'id', $item ) ) {
				if ( ! is_string( $item['id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $item['id'] ) ) {
					return $this->invalid( $path . '.id', 'tiene que ser un id en texto.' );
				}

				$id = $item['id'];

				if ( isset( $seen[ $id ] ) ) {
					return $this->invalid( $path . '.id', sprintf( '«%s» se repite en la petición.', $id ) );
				}

				$seen[ $id ] = true;
			}

			$fields = $this->parse_fields( $name, $path, $item );

			if ( is_wp_error( $fields ) ) {
				return $fields;
			}

			if ( null === $id ) {
				$missing = $this->missing_for_create( $name, $path, $fields );

				if ( null !== $missing ) {
					return $missing;
				}
			} elseif ( array() === $fields ) {
				return $this->invalid( $path, 'no trae nada que cambiar.' );
			}

			$out[] = array(
				'index'  => (int) $index,
				'id'     => $id,
				'fields' => $fields,
			);
		}

		return $out;
	}

	/**
	 * Valida los campos de un item (sin `id`) y los entrega ya normalizados.
	 *
	 * @param string               $name Sección.
	 * @param string               $path Ruta del item.
	 * @param array<string, mixed> $item Item de la petición.
	 *
	 * @return array<string, mixed>|\WP_Error `null` en un valor significa «quitar la clave» (sólo tipografía).
	 */
	private function parse_fields( string $name, string $path, array $item ) {
		$fields = array();

		foreach ( $item as $key => $value ) {
			$key = (string) $key;

			if ( 'id' === $key ) {
				continue;
			}

			$field_path = $path . '.' . $key;

			if ( 'title' === $key ) {
				if ( ! $this->valid_title( $value ) ) {
					return $this->invalid( $field_path, sprintf( 'tiene que ser texto plano de hasta %d caracteres, sin etiquetas.', self::TITLE_MAX ) );
				}

				$fields['title'] = $value;
				continue;
			}

			if ( 'colors' === $name ) {
				if ( 'color' !== $key ) {
					return $this->invalid( $path, sprintf( 'no admite la clave «%s»; sólo id, title y color.', $key ) );
				}

				if ( ! is_string( $value ) || 1 !== preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
					return $this->invalid( $field_path, 'usá #rgb, #rrggbb o #rrggbbaa.' );
				}

				$fields['color'] = $value;
				continue;
			}

			$spec = self::typography_keys();

			if ( ! isset( $spec[ $key ] ) ) {
				return $this->invalid( $path, sprintf( 'no admite la clave «%s»; sólo id, title y las claves typography_* de Elementor.', $key ) );
			}

			if ( null === $value ) {
				$fields[ $key ] = null;
				continue;
			}

			$normalized = $this->typography_value( $spec[ $key ], $value, $field_path );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$fields[ $key ] = $normalized['value'];
		}

		return $fields;
	}

	/**
	 * Error si falta algo obligatorio para crear (título y, en colores, color).
	 *
	 * @param string               $name   Sección.
	 * @param string               $path   Ruta del item.
	 * @param array<string, mixed> $fields Campos validados.
	 */
	private function missing_for_create( string $name, string $path, array $fields ): ?\WP_Error {
		if ( ! isset( $fields['title'] ) ) {
			return $this->invalid( $path . '.title', 'es obligatorio para crear.' );
		}

		if ( 'colors' === $name && ! isset( $fields['color'] ) ) {
			return $this->invalid( $path . '.color', 'es obligatorio para crear.' );
		}

		return null;
	}

	/**
	 * Valida la lista `delete`.
	 *
	 * @param array<int, mixed>  $ids     Ids pedidos.
	 * @param array<int, string> $claimed Ids ya usados por las modificaciones de la petición.
	 *
	 * @return array<int, string>|\WP_Error
	 */
	private function parse_deletes( array $ids, array $claimed ) {
		$seen = array_fill_keys( $claimed, true );
		$out  = array();

		foreach ( $ids as $index => $id ) {
			$path = sprintf( 'delete[%d]', $index );

			if ( ! is_string( $id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
				return $this->invalid( $path, 'tiene que ser un id en texto.' );
			}

			if ( isset( $seen[ $id ] ) ) {
				return $this->invalid( $path, sprintf( '«%s» se repite en la petición.', $id ) );
			}

			$seen[ $id ] = true;
			$out[]       = $id;
		}

		return $out;
	}

	/**
	 * Aplica la petición validada a las listas vigentes y arma el plan.
	 *
	 * @param array<string, array<int, array{index: int, id: string|null, fields: array<string, mixed>}>> $edits   Items por sección.
	 * @param array<int, string>                                                                          $deletes Ids a borrar.
	 *
	 * @return array{changed: array<string, array<int, array<string, mixed>>>, result: array<string, mixed>}|\WP_Error
	 */
	private function resolve( array $edits, array $deletes ) {
		$lists   = $this->store->lists();
		$changed = array();
		$taken   = array();

		foreach ( self::LISTS as $key ) {
			$lists[ $key ] = array_values( $lists[ $key ] ?? array() );

			foreach ( $lists[ $key ] as $item ) {
				if ( isset( $item['_id'] ) && is_string( $item['_id'] ) ) {
					$taken[ $item['_id'] ] = true;
				}
			}
		}

		foreach ( array( 'colors', 'typography' ) as $name ) {
			foreach ( $edits[ $name ] as $edit ) {
				if ( null === $edit['id'] ) {
					continue;
				}

				$found = $this->find( $lists, array( 'system_' . $name, 'custom_' . $name ), $edit['id'] );

				if ( null === $found ) {
					return new \WP_Error(
						'nexo_mcp_design_not_found',
						'colors' === $name
							/* translators: %s: id. */
							? sprintf( __( 'No existe el color «%s».', 'nexo-mcp' ), $edit['id'] )
							/* translators: %s: id. */
							: sprintf( __( 'No existe la tipografía «%s».', 'nexo-mcp' ), $edit['id'] ),
						array( 'id' => $edit['id'] )
					);
				}

				$lists[ $found[0] ][ $found[1] ] = $this->merge( $name, $lists[ $found[0] ][ $found[1] ], $edit['fields'] );
				$changed[ $found[0] ]            = true;
			}
		}

		foreach ( array( 'colors', 'typography' ) as $name ) {
			foreach ( $edits[ $name ] as $edit ) {
				if ( null !== $edit['id'] ) {
					continue;
				}

				$id = $this->new_id( $taken );

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				$item = array( '_id' => $id );

				$lists[ 'custom_' . $name ][] = $this->merge( $name, $item, $edit['fields'] );
				$changed[ 'custom_' . $name ] = true;
			}
		}

		$removals = $this->removals( $lists, $deletes );

		if ( is_wp_error( $removals ) ) {
			return $removals;
		}

		foreach ( $removals as $key => $ids ) {
			$lists[ $key ]   = array_values( array_filter( $lists[ $key ], static fn( array $item ): bool => ! in_array( $item['_id'] ?? null, $ids, true ) ) );
			$changed[ $key ] = true;
		}

		$out = array();

		foreach ( self::LISTS as $key ) {
			if ( isset( $changed[ $key ] ) ) {
				$out[ $key ] = $lists[ $key ];
			}
		}

		return array(
			'changed' => $out,
			'result'  => array(
				'colors'     => $this->colors( $lists ),
				'typography' => $this->typography( $lists ),
			),
		);
	}

	/**
	 * Decide qué custom se borra por cada lista; un id de sistema o desconocido se rechaza.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists   Listas vigentes (con las modificaciones).
	 * @param array<int, string>                              $deletes Ids pedidos.
	 *
	 * @return array<string, array<int, string>>|\WP_Error Ids a quitar por lista.
	 */
	private function removals( array $lists, array $deletes ) {
		$out = array();

		foreach ( $deletes as $id ) {
			if ( null !== $this->find( $lists, array( 'system_colors', 'system_typography' ), $id ) ) {
				return new \WP_Error(
					'nexo_mcp_design_system_item',
					/* translators: %s: id. */
					sprintf( __( '«%s» es un item del sistema: se puede modificar pero no borrar.', 'nexo-mcp' ), $id ),
					array( 'id' => $id )
				);
			}

			$found = false;

			foreach ( array( 'custom_colors', 'custom_typography' ) as $key ) {
				if ( null !== $this->find( $lists, array( $key ), $id ) ) {
					$out[ $key ][] = $id;
					$found         = true;
				}
			}

			if ( ! $found ) {
				return new \WP_Error(
					'nexo_mcp_design_not_found',
					/* translators: %s: id. */
					sprintf( __( 'No existe ningún item personalizado con id «%s».', 'nexo-mcp' ), $id ),
					array( 'id' => $id )
				);
			}
		}

		return $out;
	}

	/**
	 * Busca un item por `_id` en las listas dadas.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists Listas.
	 * @param array<int, string>                              $keys  Listas donde buscar.
	 * @param string                                          $id    Id.
	 *
	 * @return array{0: string, 1: int}|null Lista e índice.
	 */
	private function find( array $lists, array $keys, string $id ): ?array {
		foreach ( $keys as $key ) {
			foreach ( $lists[ $key ] as $index => $item ) {
				if ( ( $item['_id'] ?? null ) === $id ) {
					return array( $key, $index );
				}
			}
		}

		return null;
	}

	/**
	 * Aplica campos validados a un item; en tipografía deja activo el modo `custom`.
	 *
	 * @param string               $name   Sección.
	 * @param array<string, mixed> $item   Item actual.
	 * @param array<string, mixed> $fields Campos nuevos (`null` quita la clave).
	 *
	 * @return array<string, mixed>
	 */
	private function merge( string $name, array $item, array $fields ): array {
		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				unset( $item[ $key ] );
			} else {
				$item[ $key ] = $value;
			}
		}

		if ( 'typography' === $name && ! isset( $item['typography_typography'] ) ) {
			$item['typography_typography'] = 'custom';
		}

		return $item;
	}

	/**
	 * Id nuevo de 7 hexadecimales que no exista en ninguna lista ni se haya dado ya.
	 *
	 * @param array<int|string, bool> $taken Ids en uso (se completa).
	 *
	 * @return string|\WP_Error
	 */
	private function new_id( array &$taken ) {
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$id = ( $this->ids )();

			if ( 1 === preg_match( '/^[0-9a-f]{7}$/', $id ) && ! isset( $taken[ $id ] ) ) {
				$taken[ $id ] = true;

				return $id;
			}
		}

		return new \WP_Error(
			'nexo_mcp_design_write_failed',
			__( 'No se pudo generar un id nuevo para el item.', 'nexo-mcp' )
		);
	}

	/**
	 * Ids pedidos por las modificaciones de una sección.
	 *
	 * @param array<int, array{index: int, id: string|null, fields: array<string, mixed>}> $edits Items.
	 *
	 * @return array<int, string>
	 */
	private function ids_of( array $edits ): array {
		return array_values(
			array_filter(
				array_map( static fn( array $edit ): ?string => $edit['id'], $edits ),
				static fn( ?string $id ): bool => null !== $id
			)
		);
	}

	/**
	 * Colores con la forma de `get-design-system`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists Listas.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function colors( array $lists ): array {
		$out = array();

		foreach ( array_merge( $lists['system_colors'], $lists['custom_colors'] ) as $item ) {
			$out[] = array(
				'id'    => (string) ( $item['_id'] ?? '' ),
				'title' => (string) ( $item['title'] ?? '' ),
				'color' => (string) ( $item['color'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Tipografía con la forma de `get-design-system`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $lists Listas.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function typography( array $lists ): array {
		$out = array();

		foreach ( array_merge( $lists['system_typography'], $lists['custom_typography'] ) as $item ) {
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
	 * Claves `typography_*` admitidas y cómo se valida cada una.
	 *
	 * @return array<string, array{type: string, options?: array<int, string>, units?: array<int, string>}>
	 */
	public static function typography_keys(): array {
		$keys = array( self::FAMILY_KEY => array( 'type' => 'family' ) );

		foreach ( self::SELECTS as $key => $options ) {
			$keys[ $key ] = array(
				'type'    => 'select',
				'options' => $options,
			);
		}

		foreach ( self::SIZES as $key => $units ) {
			foreach ( self::DEVICES as $device ) {
				$keys[ $key . $device ] = array(
					'type'  => 'size',
					'units' => $units,
				);
			}
		}

		return $keys;
	}

	/**
	 * Valida y normaliza el valor de una clave de tipografía.
	 *
	 * @param array{type: string, options?: array<int, string>, units?: array<int, string>} $spec  Regla de la clave.
	 * @param mixed                                                                         $value Valor pedido.
	 * @param string                                                                        $path  Ruta para el error.
	 *
	 * @return array{value: mixed}|\WP_Error
	 */
	private function typography_value( array $spec, $value, string $path ) {
		switch ( $spec['type'] ) {
			case 'family':
				if ( ! is_string( $value ) || ! $this->safe_text( $value ) ) {
					return $this->invalid( $path, sprintf( 'el nombre de la fuente tiene que ser texto de hasta %d caracteres, sin ; { } < > \\ @ ni comillas.', self::TITLE_MAX ) );
				}

				$problem = ElementorTreeValidator::css_text_problem( $value );

				if ( null !== $problem ) {
					return $this->invalid( $path, $problem );
				}

				return array( 'value' => $value );

			case 'select':
				$options = $spec['options'] ?? array();

				if ( ( is_string( $value ) || is_int( $value ) ) && in_array( (string) $value, $options, true ) ) {
					return array( 'value' => (string) $value );
				}

				return $this->invalid( $path, 'valor no válido; opciones: ' . implode( ', ', $options ) . '.' );
		}

		$units = $spec['units'] ?? array();
		$error = $this->invalid(
			$path,
			sprintf(
				'tiene que ser {unit, size} con unit entre %s y size numérico (con unit custom, texto de hasta %d caracteres sin ; { } < > \\ @).',
				implode( ', ', $units ),
				self::TITLE_MAX
			)
		);

		if ( ! is_array( $value ) || array( 'size', 'unit' ) !== $this->sorted_keys( $value ) || ! is_string( $value['unit'] ) || ! in_array( $value['unit'], $units, true ) ) {
			return $error;
		}

		$size = $value['size'];

		if ( 'custom' === $value['unit'] ) {
			if ( ! is_string( $size ) || ! $this->safe_text( $size ) ) {
				return $error;
			}

			// Llega tal cual al CSS del Kit: misma regla que las hojas de F2/F3 (comentarios, funciones, comillas, paréntesis).
			$problem = ElementorTreeValidator::css_text_problem( $size );

			if ( null !== $problem ) {
				return $this->invalid( $path, $problem );
			}
		} elseif ( ! ( is_int( $size ) || is_float( $size ) || ( is_string( $size ) && is_numeric( $size ) ) ) || ! is_finite( (float) $size ) ) {
			return $error;
		}

		return array(
			'value' => array(
				'unit'  => $value['unit'],
				'size'  => $size,
				'sizes' => array(),
			),
		);
	}

	/**
	 * Claves ordenadas de un arreglo.
	 *
	 * @param array<mixed> $value Arreglo.
	 *
	 * @return array<int, int|string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys );

		return $keys;
	}

	/**
	 * Texto corto sin caracteres que rompan el CSS que Elementor genera con él.
	 *
	 * @param string $text Texto.
	 */
	private function safe_text( string $text ): bool {
		return 1 === preg_match( '/^[^;{}<>\\\\@"\x00-\x1f\x7f]{1,' . self::TITLE_MAX . '}$/u', $text ) && '' !== trim( $text );
	}

	/**
	 * Título: texto plano, sin etiquetas, no vacío y de hasta 100 caracteres.
	 *
	 * @param mixed $title Título pedido.
	 */
	private function valid_title( $title ): bool {
		return is_string( $title )
			&& '' !== trim( $title )
			&& mb_strlen( $title ) <= self::TITLE_MAX
			&& wp_strip_all_tags( $title ) === $title
			&& 1 !== preg_match( '/[\x00-\x1f\x7f]/', $title );
	}

	/**
	 * Error de validación con la ruta del campo.
	 *
	 * @param string $path    Ruta del campo (por ejemplo `colors[0].color`).
	 * @param string $message Qué está mal.
	 */
	private function invalid( string $path, string $message ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_design_invalid',
			$path . ': ' . $message,
			array( 'path' => $path )
		);
	}
}
