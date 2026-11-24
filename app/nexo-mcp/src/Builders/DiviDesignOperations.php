<?php
/**
 * Diseño global de Divi 5: crear y cambiar colores, variables y tipografía, con validación estricta.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Operaciones de escritura de las herramientas `set-divi-*`, `delete-divi-global-color` y `archive-divi-variable`.
 *
 * Valida todo antes de tocar nada (un campo malo no deja escrito ninguno) y escribe con
 * {@see DiviDesignStore}, que respalda, bloquea y vacía el CSS. Todo error de validación es
 * `nexo_mcp_divi_design_invalid` con «ruta: motivo» y `data.path`.
 */
final class DiviDesignOperations {

	/**
	 * Filas por lote al buscar el uso de un color.
	 */
	private const SCAN_BATCH = 200;

	/**
	 * Almacén del diseño global.
	 *
	 * @var DiviDesignStore
	 */
	private DiviDesignStore $store;

	/**
	 * Fn( string $color_id ): array<int, int>. Ids de las entradas de Divi 5 no borradas que usan el color.
	 *
	 * @var callable
	 */
	private $find_usage;

	/**
	 * Fn( int $attachment_id ): ?string. URL de un adjunto, o null si no existe.
	 *
	 * @var callable
	 */
	private $attachment_url;

	/**
	 * Fn(): string. Diez caracteres [a-z0-9] para un id nuevo.
	 *
	 * @var callable
	 */
	private $suffix;

	/**
	 * Construye las operaciones; sin argumentos opcionales usa WordPress de verdad.
	 *
	 * @param DiviDesignStore $store          Almacén.
	 * @param callable|null   $find_usage     fn( string $color_id ): array<int, int>.
	 * @param callable|null   $attachment_url fn( int $id ): ?string.
	 * @param callable|null   $suffix         fn(): string.
	 */
	public function __construct( DiviDesignStore $store, ?callable $find_usage = null, ?callable $attachment_url = null, ?callable $suffix = null ) {
		$this->store          = $store;
		$this->find_usage     = $find_usage ?? array( self::class, 'scan_color_usage' );
		$this->attachment_url = $attachment_url ?? static function ( int $id ): ?string {
			if ( 'attachment' !== get_post_type( $id ) ) {
				return null;
			}

			$url = wp_get_attachment_url( $id );

			return is_string( $url ) && '' !== $url ? $url : null;
		};
		$this->suffix         = $suffix ?? array( self::class, 'random_suffix' );
	}

	/**
	 * Sufijo para un id nuevo: 10 caracteres [a-z0-9].
	 */
	public function new_suffix(): string {
		return (string) ( $this->suffix )();
	}

	/**
	 * Crea (sin id) o actualiza (con id) un color global.
	 *
	 * @param array<string, mixed> $input `{id?, label, color}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_color( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$id = $this->color_id( $input );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$label = $this->plain_text( $input['label'] ?? null, 'label', 100 );

		if ( $label instanceof \WP_Error ) {
			return $label;
		}

		$color = $this->color( $input['color'] ?? null, 'color' );

		if ( $color instanceof \WP_Error ) {
			return $color;
		}

		$final   = $id;
		$created = null === $id;
		$result  = $this->store->update_colors(
			function ( array $current ) use ( $id, $label, $color, &$final ) {
				$now = gmdate( 'Y-m-d\TH:i:s' ) . '.000Z';

				if ( null !== $id ) {
					if ( ! isset( $current[ $id ] ) || ! is_array( $current[ $id ] ) ) {
						return $this->not_found( $id );
					}

					$current[ $id ] = array_merge(
						$current[ $id ],
						array(
							'color'       => $color,
							'label'       => $label,
							'lastUpdated' => $now,
						)
					);

					return $current;
				}

				$new = null;

				for ( $attempt = 0; $attempt < 10; ++$attempt ) {
					$candidate = 'gcid-' . $this->new_suffix();

					if ( 'gcid-' !== $candidate && ! isset( $current[ $candidate ] ) ) {
						$new = $candidate;
						break;
					}
				}

				if ( null === $new ) {
					return new \WP_Error( 'nexo_mcp_divi_design_write_failed', __( 'No se pudo generar un id libre para el color.', 'nexo-mcp' ) );
				}

				$final           = $new;
				$current[ $new ] = array(
					'color'       => $color,
					'label'       => $label,
					'folder'      => '',
					'lastUpdated' => $now,
					'status'      => 'active',
					'usedInPosts' => array(),
				);

				return $current;
			},
			'set-divi-global-color'
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return array_merge(
			array(
				'id'      => $final,
				'label'   => $label,
				'color'   => $color,
				'created' => $created,
			),
			$result
		);
	}

	/**
	 * Borra un color global; en uso sólo con `force` (el permiso destructivo lo exige la herramienta).
	 *
	 * @param array<string, mixed> $input `{id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_color( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$id = $this->color_id( $input, true );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		if ( null === $id || ! isset( $this->store->current_colors()[ $id ] ) ) {
			return $this->not_found( (string) $id );
		}

		$force = true === ( $input['force'] ?? false );
		$posts = array();

		// La búsqueda de uso corre dentro del bloqueo de la tienda, junto con el borrado: comprobar y actuar
		// es atómico y nadie puede empezar a usar el color entre una cosa y la otra.
		$result = $this->store->update_colors(
			function ( array $current ) use ( $id, $force, &$posts ) {
				if ( ! isset( $current[ $id ] ) ) {
					return $this->not_found( $id );
				}

				$posts = array_values( array_map( 'intval', (array) ( $this->find_usage )( $id ) ) );

				if ( array() !== $posts && ! $force ) {
					return new \WP_Error(
						'nexo_mcp_divi_color_in_use',
						sprintf(
							/* translators: 1: id del color, 2: ids de las entradas. */
							__( 'El color %1$s está en uso en estas entradas de Divi 5 (ids): %2$s. Borrarlo deja esos módulos sin color; no se borró nada. Repite con force: true (pide además el permiso destructivo) para borrarlo igual.', 'nexo-mcp' ),
							$id,
							implode( ', ', $posts )
						),
						array( 'post_ids' => $posts )
					);
				}

				unset( $current[ $id ] );

				return $current;
			},
			'delete-divi-global-color'
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return array_merge(
			array(
				'deleted'        => $id,
				'posts_affected' => $posts,
			),
			$result
		);
	}

	/**
	 * Crea (sin id) o actualiza (con id) una variable de diseño.
	 *
	 * @param array<string, mixed> $input `{type, id?, label, value}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_variable( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$type = $this->variable_type( $input );

		if ( $type instanceof \WP_Error ) {
			return $type;
		}

		$id = $this->variable_id( $input );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$label = $this->plain_text( $input['label'] ?? null, 'label', 100 );

		if ( $label instanceof \WP_Error ) {
			return $label;
		}

		$value = $this->variable_value( $type, $input['value'] ?? null );

		if ( $value instanceof \WP_Error ) {
			return $value;
		}

		$final   = $id;
		$created = null === $id;
		$result  = $this->store->update_variables(
			$type,
			function ( array $current ) use ( $id, $label, $value, &$final ) {
				if ( null !== $id ) {
					if ( ! isset( $current[ $id ] ) || ! is_array( $current[ $id ] ) ) {
						return $this->not_found( $id );
					}

					$current[ $id ] = array_merge(
						$current[ $id ],
						array(
							'label' => $label,
							'value' => $value,
						)
					);

					return $current;
				}

				$new   = null;
				$order = 0;

				foreach ( $current as $variable ) {
					$order = max( $order, is_array( $variable ) && isset( $variable['order'] ) && is_numeric( $variable['order'] ) ? (int) $variable['order'] : 0 );
				}

				for ( $attempt = 0; $attempt < 10; ++$attempt ) {
					$candidate = 'gvid-' . $this->new_suffix();

					if ( 'gvid-' !== $candidate && ! isset( $current[ $candidate ] ) ) {
						$new = $candidate;
						break;
					}
				}

				if ( null === $new ) {
					return new \WP_Error( 'nexo_mcp_divi_design_write_failed', __( 'No se pudo generar un id libre para la variable.', 'nexo-mcp' ) );
				}

				$final           = $new;
				$current[ $new ] = array(
					'label'  => $label,
					'value'  => $value,
					'order'  => $order + 1,
					'status' => 'active',
				);

				return $current;
			},
			'set-divi-variable'
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return array_merge(
			array(
				'type'    => $type,
				'id'      => $final,
				'label'   => $label,
				'value'   => $value,
				'created' => $created,
			),
			$result
		);
	}

	/**
	 * Archiva o reactiva una variable (no se borra, como hace Divi).
	 *
	 * @param array<string, mixed> $input `{type, id, archived}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function archive_variable( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$type = $this->variable_type( $input );

		if ( $type instanceof \WP_Error ) {
			return $type;
		}

		$id = $this->variable_id( $input );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		if ( null === $id ) {
			return $this->invalid( 'id', __( 'es obligatorio.', 'nexo-mcp' ) );
		}

		if ( ! is_bool( $input['archived'] ?? null ) ) {
			return $this->invalid( 'archived', __( 'debe ser true (archivar) o false (reactivar).', 'nexo-mcp' ) );
		}

		$status  = $input['archived'] ? 'archived' : 'active';
		$current = $this->store->current_variables( $type );

		if ( ! isset( $current[ $id ] ) || ! is_array( $current[ $id ] ) ) {
			return $this->not_found( $id );
		}

		$existing = isset( $current[ $id ]['status'] ) && is_string( $current[ $id ]['status'] ) ? $current[ $id ]['status'] : 'active';
		$base     = array(
			'type'   => $type,
			'id'     => $id,
			'status' => $status,
		);

		if ( $existing === $status ) {
			return array_merge( $base, array( 'changed' => false ) );
		}

		$result = $this->store->update_variables(
			$type,
			function ( array $variables ) use ( $id, $status ) {
				if ( ! isset( $variables[ $id ] ) || ! is_array( $variables[ $id ] ) ) {
					return $this->not_found( $id );
				}

				$variables[ $id ]['status'] = $status;

				return $variables;
			},
			'archive-divi-variable'
		);

		return $result instanceof \WP_Error ? $result : array_merge( $base, array( 'changed' => true ), $result );
	}

	/**
	 * Cambia fuentes, pesos, tamaño y altura del cuerpo y los colores del Customizer, de una vez.
	 *
	 * @param array<string, mixed> $input `{heading_font?, body_font?, heading_font_weight?, body_font_weight?, body_font_size?, body_line_height?, colors?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_typography( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! isset( DiviDesignStore::TYPOGRAPHY[ (string) $key ] ) && 'colors' !== (string) $key ) {
				return $this->invalid( (string) $key, __( 'campo desconocido.', 'nexo-mcp' ) );
			}
		}

		$changes = array();

		foreach ( $input as $name => $value ) {
			$name = (string) $name;

			if ( null === $value || 'colors' === $name ) {
				continue;
			}

			$clean = $this->typography_value( $name, $value );

			if ( $clean instanceof \WP_Error ) {
				return $clean;
			}

			$changes[ DiviDesignStore::TYPOGRAPHY[ $name ] ] = $clean;
		}

		$colors = $input['colors'] ?? null;

		if ( null !== $colors && ! is_array( $colors ) ) {
			return $this->invalid( 'colors', __( 'debe ser un objeto {accent?, secondary_accent?, header?, font?, link?}.', 'nexo-mcp' ) );
		}

		foreach ( (array) $colors as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( DiviDesignStore::CUSTOMIZER_COLORS[ $name ] ) ) {
				return $this->invalid( 'colors.' . $name, __( 'color del Customizer desconocido (accent, secondary_accent, header, font, link).', 'nexo-mcp' ) );
			}

			if ( null === $value ) {
				continue;
			}

			$clean = $this->color( $value, 'colors.' . $name );

			if ( $clean instanceof \WP_Error ) {
				return $clean;
			}

			$changes[ DiviDesignStore::CUSTOMIZER_COLORS[ $name ] ] = $clean;
		}

		if ( array() === $changes ) {
			return new \WP_Error( 'nexo_mcp_divi_design_nothing_to_change', __( 'No hay nada que cambiar: manda al menos una fuente, peso, tamaño, altura de línea o color.', 'nexo-mcp' ) );
		}

		$result = $this->store->write_theme_options( $changes, 'set-divi-typography' );

		return $result instanceof \WP_Error ? $result : array_merge( array( 'changed' => $changes ), $result );
	}

	/**
	 * Entradas de Divi 5 no borradas que usan un color global (`gcid-…` en el contenido).
	 *
	 * @param string $id Id del color.
	 *
	 * @return array<int, int>
	 */
	public static function scan_color_usage( string $id ): array {
		global $wpdb;

		$posts = array();
		$after = 0;
		$full  = false;
		$found = 0;
		$exact = '/' . preg_quote( $id, '/' ) . '(?![A-Za-z0-9_-])/';

		// Sin tope de filas: se recorre por lotes (clave `ID > último`) y cada lote se filtra con la expresión exacta,
		// así un prefijo parecido (`gcid-abc` vs `gcid-abcd`) no puede agotar un límite y esconder un uso real.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Búsqueda puntual de uso, sin caché a propósito.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_type <> %s AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s AND post_content LIKE %s ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tablas de Core.
					$after,
					'revision',
					'%' . $wpdb->esc_like( $id ) . '%',
					'%' . $wpdb->esc_like( 'wp:divi/' ) . '%',
					self::SCAN_BATCH
				),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['ID'] ) ) {
					continue;
				}

				$after = max( $after, (int) $row['ID'] );

				if ( isset( $row['post_content'] ) && 1 === preg_match( $exact, (string) $row['post_content'] ) ) {
					$posts[] = (int) $row['ID'];
				}
			}
			$full  = count( $rows ) >= self::SCAN_BATCH;
			$found = count( $posts );
		} while ( $full && $found < 100 );

		return array_slice( $posts, 0, 100 );
	}

	/**
	 * Diez caracteres aleatorios [a-z0-9].
	 */
	public static function random_suffix(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';

		for ( $i = 0; $i < 10; ++$i ) {
			$out .= $alphabet[ random_int( 0, 35 ) ];
		}

		return $out;
	}

	/**
	 * Id de un color de la entrada: null si no viene; error si es inválido o es de los del Customizer.
	 *
	 * @param array<string, mixed> $input    Entrada.
	 * @param bool                 $required El id es obligatorio.
	 *
	 * @return string|null|\WP_Error
	 */
	private function color_id( array $input, bool $required = false ) {
		$id = $input['id'] ?? null;

		if ( null === $id && ! $required ) {
			return null;
		}

		if ( ! is_string( $id ) || 1 !== preg_match( '/\Agcid-[A-Za-z0-9_-]+\z/', $id ) ) {
			return $this->invalid( 'id', __( 'debe ser un id de color global (gcid-…).', 'nexo-mcp' ) );
		}

		if ( in_array( $id, DiviDesignStore::CUSTOMIZER_COLOR_IDS, true ) ) {
			return $this->invalid(
				'id',
				__( 'es un color del Customizer de Divi: no se edita ni se borra aquí; cámbialo con set-divi-typography (colors).', 'nexo-mcp' ),
				'nexo_mcp_divi_design_customizer_color'
			);
		}

		return $id;
	}

	/**
	 * Tipo de variable de la entrada.
	 *
	 * @param array<string, mixed> $input Entrada.
	 *
	 * @return string|\WP_Error
	 */
	private function variable_type( array $input ) {
		$type = $input['type'] ?? null;

		if ( is_string( $type ) && in_array( $type, DiviDesignStore::WRITE_TYPES, true ) ) {
			return $type;
		}

		return new \WP_Error(
			'nexo_mcp_divi_design_type_invalid',
			sprintf(
				/* translators: %s: tipos admitidos. */
				__( 'type: tipo de variable no admitido. Se pueden escribir: %s (los gradientes son sólo de lectura).', 'nexo-mcp' ),
				implode( ', ', DiviDesignStore::WRITE_TYPES )
			),
			array( 'path' => 'type' )
		);
	}

	/**
	 * Id de variable de la entrada: null si no viene.
	 *
	 * @param array<string, mixed> $input Entrada.
	 *
	 * @return string|null|\WP_Error
	 */
	private function variable_id( array $input ) {
		$id = $input['id'] ?? null;

		if ( null === $id ) {
			return null;
		}

		if ( ! is_string( $id ) || 1 !== preg_match( '/\Agvid-[A-Za-z0-9_-]+\z/', $id ) ) {
			return $this->invalid( 'id', __( 'debe ser un id de variable (gvid-…).', 'nexo-mcp' ) );
		}

		return $id;
	}

	/**
	 * Valida el valor de una variable según su tipo y lo devuelve como se guarda.
	 *
	 * @param string $type  Tipo.
	 * @param mixed  $value Valor.
	 *
	 * @return string|\WP_Error
	 */
	private function variable_value( string $type, $value ) {
		switch ( $type ) {
			case 'numbers':
				if ( is_int( $value ) || is_float( $value ) ) {
					$value = (string) $value;
				}

				if ( is_string( $value ) && 1 === preg_match( '/\A-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|em|rem|%|vw|vh)?\z/', $value ) ) {
					return $value;
				}

				return $this->invalid( 'value', __( 'debe ser un número con unidad px, em, rem, %, vw o vh, o sin unidad (12px, 1.5rem, 50%, 16).', 'nexo-mcp' ) );

			case 'strings':
				return $this->plain_text( $value, 'value', 200 );

			case 'images':
				return $this->image( $value );

			case 'links':
				return $this->link( $value );

			default:
				return $this->font( $value, 'value' );
		}
	}

	/**
	 * Imagen: id de adjunto (se guarda su URL) o URL http/https.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return string|\WP_Error
	 */
	private function image( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/\A\d+\z/', $value ) ) ) {
			$url = ( $this->attachment_url )( (int) $value );

			return null !== $url ? $url : $this->invalid( 'value', __( 'no es el id de un adjunto de la biblioteca de medios.', 'nexo-mcp' ) );
		}

		if ( is_string( $value ) && strlen( $value ) <= 2000 && 1 === preg_match( '/\Ahttps?:\/\/[^\s<>"\'\\\\]+\z/i', $value ) && is_string( wp_parse_url( $value, PHP_URL_HOST ) ) ) {
			return $value;
		}

		return $this->invalid( 'value', __( 'debe ser el id de un adjunto o una URL http/https sin espacios ni comillas.', 'nexo-mcp' ) );
	}

	/**
	 * Enlace: http, https, mailto, tel o ruta relativa.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return string|\WP_Error
	 */
	private function link( $value ) {
		$error = $this->invalid( 'value', __( 'debe ser un enlace http, https, mailto, tel o una ruta relativa, sin espacios ni comillas.', 'nexo-mcp' ) );

		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 2000 || 1 === preg_match( '/[\s<>"\'\\\\\x00-\x1F\x7F]/', $value ) ) {
			return $error;
		}

		if ( 1 === preg_match( '/\Ahttps?:\/\/\S+\z/i', $value ) ) {
			return is_string( wp_parse_url( $value, PHP_URL_HOST ) ) ? $value : $error;
		}

		if ( 1 === preg_match( '/\Amailto:\S+\z/i', $value ) || 1 === preg_match( '/\Atel:[+0-9().\-]+\z/i', $value ) ) {
			return $value;
		}

		// Relativa: sin esquema (`javascript:`, `data:`…) y sin `//` inicial (otro sitio).
		if ( 1 === preg_match( '/\A[A-Za-z][A-Za-z0-9+.\-]*:/', $value ) || str_starts_with( $value, '//' ) ) {
			return $error;
		}

		return $value;
	}

	/**
	 * Nombre de familia tipográfica: `[A-Za-z0-9 '_-]` de hasta 100.
	 *
	 * @param mixed  $value Valor.
	 * @param string $path  Campo.
	 *
	 * @return string|\WP_Error
	 */
	private function font( $value, string $path ) {
		if ( is_string( $value ) && 1 === preg_match( "/\\A[A-Za-z0-9 '_-]{1,100}\\z/", $value ) ) {
			return $value;
		}

		return $this->invalid( $path, __( 'debe ser un nombre de familia de hasta 100 caracteres con letras, números, espacios, comillas simples, guion y guion bajo.', 'nexo-mcp' ) );
	}

	/**
	 * Valida un campo de tipografía del cuerpo o los títulos.
	 *
	 * @param string $name  Campo.
	 * @param mixed  $value Valor.
	 *
	 * @return string|\WP_Error
	 */
	private function typography_value( string $name, $value ) {
		if ( 'heading_font' === $name || 'body_font' === $name ) {
			return $this->font( $value, $name );
		}

		$text = is_int( $value ) || is_float( $value ) ? (string) $value : $value;

		if ( 'heading_font_weight' === $name || 'body_font_weight' === $name ) {
			if ( is_string( $text ) && ( 1 === preg_match( '/\A[1-9]00\z/', $text ) || 1 === preg_match( '/\A(?:normal|bold)\z/i', $text ) ) ) {
				return strtolower( $text );
			}

			return $this->invalid( $name, __( 'debe ser 100, 200… 900, normal o bold.', 'nexo-mcp' ) );
		}

		if ( 'body_font_size' === $name ) {
			if ( is_string( $text ) && 1 === preg_match( '/\A(\d+(?:\.\d+)?)(?:px)?\z/', $text, $found ) && (float) $found[1] >= 8 && (float) $found[1] <= 72 ) {
				return (string) (float) $found[1];
			}

			return $this->invalid( $name, __( 'debe ser un tamaño en px entre 8 y 72 (16 o 16px).', 'nexo-mcp' ) );
		}

		if ( is_string( $text ) && 1 === preg_match( '/\A(?:\d+(?:\.\d+)?|\.\d+)\z/', $text ) && (float) $text >= 1 && (float) $text <= 3 ) {
			return (string) (float) $text;
		}

		return $this->invalid( $name, __( 'debe ser un número entre 1 y 3 (1.7).', 'nexo-mcp' ) );
	}

	/**
	 * Color: `#hex` (3, 4, 6 u 8), `rgb[a]()` o `hsl[a]()`.
	 *
	 * @param mixed  $value Valor.
	 * @param string $path  Campo.
	 *
	 * @return string|\WP_Error
	 */
	private function color( $value, string $path ) {
		$n = '(?:\d+(?:\.\d+)?|\.\d+)';

		if ( is_string( $value ) && (
			1 === preg_match( '/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\z/', $value )
			|| 1 === preg_match( '/\Argba?\(\s*' . $n . '%?\s*,\s*' . $n . '%?\s*,\s*' . $n . '%?\s*(?:,\s*' . $n . '%?\s*)?\)\z/i', $value )
			|| 1 === preg_match( '/\Ahsla?\(\s*-?' . $n . '(?:deg)?\s*,\s*' . $n . '%\s*,\s*' . $n . '%\s*(?:,\s*' . $n . '%?\s*)?\)\z/i', $value )
		) ) {
			return $value;
		}

		return $this->invalid( $path, __( 'debe ser un color #hex (3, 4, 6 u 8 dígitos), rgb()/rgba() o hsl()/hsla().', 'nexo-mcp' ) );
	}

	/**
	 * Texto plano de 1 a `$max` caracteres, sin etiquetas ni caracteres de control.
	 *
	 * @param mixed  $value Valor.
	 * @param string $path  Campo.
	 * @param int    $max   Largo máximo.
	 *
	 * @return string|\WP_Error
	 */
	private function plain_text( $value, string $path, int $max ) {
		$text = is_string( $value ) ? trim( $value ) : null;

		if ( null !== $text && '' !== $text && mb_strlen( $text ) <= $max && 1 !== preg_match( '/[<>\x00-\x1F\x7F]/', $text ) ) {
			return $text;
		}

		return $this->invalid(
			$path,
			sprintf(
				/* translators: %d: largo máximo. */
				__( 'debe ser texto plano de 1 a %d caracteres, sin etiquetas ni saltos de línea.', 'nexo-mcp' ),
				$max
			)
		);
	}

	/**
	 * Error de validación con «ruta: motivo» y `data.path`.
	 *
	 * @param string $path   Campo.
	 * @param string $reason Motivo.
	 * @param string $code   Código.
	 */
	private function invalid( string $path, string $reason, string $code = 'nexo_mcp_divi_design_invalid' ): \WP_Error {
		return new \WP_Error( $code, $path . ': ' . $reason, array( 'path' => $path ) );
	}

	/**
	 * Id inexistente.
	 *
	 * @param string $id Id.
	 */
	private function not_found( string $id ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_design_not_found',
			sprintf(
				/* translators: %s: id. */
				__( 'No existe «%s» en el diseño global de Divi. Revisa los ids con get-divi-design-system.', 'nexo-mcp' ),
				$id
			),
			array( 'id' => $id )
		);
	}
}
