<?php
/**
 * Presets de Divi 5: crear, editar, fijar el predeterminado y borrar, con validación estricta de los attrs.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Operaciones de escritura de `set-divi-preset`, `set-divi-default-preset` y `delete-divi-preset`.
 *
 * Valida todo antes de tocar nada y escribe con {@see DiviPresetStore::update()}, que respalda, bloquea,
 * regenera el CSS con `GlobalPreset::save_data` y audita. Divi no valida los `attrs` de un preset (guarda
 * cualquier forma y valor), así que aquí sólo entra lo que {@see DiviTreeValidator::validate_preset_attrs()}
 * deja pasar: rutas de diseño de escritorio del catálogo de D2, con sus mismas reglas de valor. Un item que
 * se edita conserva todo lo que no se toca (`renderAttrs`, `styleAttrs`, `groupPresets`, `priority`, `order`…).
 * Todo error de entrada es `nexo_mcp_divi_preset_invalid` con «ruta: motivo» y `data.path`.
 */
final class DiviPresetOperations {

	/**
	 * Filas por lote al buscar el uso de un preset.
	 */
	private const SCAN_BATCH = 200;

	/**
	 * Ids de entradas que se devuelven al buscar el uso (el resto sólo se cuenta). Única fuente del tope: la descripción
	 * de la herramienta y las pruebas lo toman de aquí.
	 */
	public const MAX_POST_IDS = 100;

	/**
	 * Código interno de «nada que cambiar» (no sale de esta clase).
	 */
	private const UNCHANGED = 'nexo_mcp_divi_preset_unchanged';

	/**
	 * Almacén de presets.
	 *
	 * @var DiviPresetStore
	 */
	private DiviPresetStore $store;

	/**
	 * Fn( string $kind, string $id ): array{post_ids: array<int, int>, total: int}. Entradas de Divi 5 no borradas que usan el preset (los primeros ids y el total).
	 *
	 * @var callable
	 */
	private $find_usage;

	/**
	 * Fn(): string. UUID v4 para un preset nuevo.
	 *
	 * @var callable
	 */
	private $uuid;

	/**
	 * Fn(): int. Segundos desde la época.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Fn(): string. Versión de Divi que se anota en el preset.
	 *
	 * @var callable
	 */
	private $version;

	/**
	 * Validador de los attrs (el mismo del árbol de módulos).
	 *
	 * @var DiviTreeValidator|null
	 */
	private ?DiviTreeValidator $validator;

	/**
	 * Construye las operaciones; sin argumentos opcionales usa WordPress y Divi de verdad.
	 *
	 * @param DiviPresetStore        $store      Almacén.
	 * @param callable|null          $find_usage fn( string $kind, string $id ): array{post_ids: array<int, int>, total: int}.
	 * @param callable|null          $uuid       fn(): string.
	 * @param callable|null          $now        fn(): int.
	 * @param callable|null          $version    fn(): string.
	 * @param DiviTreeValidator|null $validator  Validador de attrs.
	 */
	public function __construct(
		DiviPresetStore $store,
		?callable $find_usage = null,
		?callable $uuid = null,
		?callable $now = null,
		?callable $version = null,
		?DiviTreeValidator $validator = null
	) {
		$this->store      = $store;
		$this->find_usage = $find_usage ?? array( self::class, 'scan_preset_usage' );
		$this->uuid       = $uuid ?? static fn(): string => wp_generate_uuid4();
		$this->now        = $now ?? static fn(): int => time();
		$this->version    = $version ?? static fn(): string => defined( 'ET_BUILDER_VERSION' ) ? (string) constant( 'ET_BUILDER_VERSION' ) : '5.0.0';
		$this->validator  = $validator;
	}

	/**
	 * Crea (sin id) o actualiza (con id) un preset de módulo o de grupo.
	 *
	 * @param array<string, mixed> $input `{kind, name, id?, label, attrs?, module?, group_id?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_preset( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$target = $this->target( $input );

		if ( $target instanceof \WP_Error ) {
			return $target;
		}

		list( $kind, $name ) = $target;

		$id = $input['id'] ?? null;

		if ( null !== $id && ( ! is_string( $id ) || '' === $id || strlen( $id ) > 200 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $id ) ) ) {
			return $this->invalid( 'id', __( 'debe ser el id de un preset existente (texto); sin id se crea uno nuevo.', 'nexo-mcp' ) );
		}

		$label = $this->label( $input['label'] ?? null );

		if ( $label instanceof \WP_Error ) {
			return $label;
		}

		$attrs = $input['attrs'] ?? null;

		if ( null !== $attrs && ( ! is_array( $attrs ) || array() === $attrs || array_is_list( $attrs ) ) ) {
			return $this->invalid( 'attrs', __( 'debe ser un objeto de atributos con al menos un valor (los presets vacíos los descarta Divi).', 'nexo-mcp' ) );
		}

		if ( null === $id && null === $attrs ) {
			return $this->invalid( 'attrs', __( 'es obligatorio para crear un preset (un preset sin atributos lo descarta Divi).', 'nexo-mcp' ) );
		}

		$final   = $id;
		$created = null === $id;
		$result  = $this->store->update(
			function ( array $current ) use ( $kind, $name, $id, $label, $attrs, $input, &$final ) {
				$collection = isset( $current[ $kind ][ $name ] ) && is_array( $current[ $kind ][ $name ] ) ? $current[ $kind ][ $name ] : array();
				$items      = isset( $collection['items'] ) && is_array( $collection['items'] ) ? $collection['items'] : array();
				$existing   = null;

				if ( null !== $id ) {
					$existing = isset( $items[ $id ] ) && is_array( $items[ $id ] ) ? $items[ $id ] : null;

					if ( null === $existing ) {
						return $this->not_found( $id );
					}
				}

				$context = $this->context( $kind, $name, $existing, $input );

				if ( $context instanceof \WP_Error ) {
					return $context;
				}

				list( $module, $group_id ) = $context;

				$clean = null;

				if ( null !== $attrs ) {
					$clean = $this->validator()->validate_preset_attrs( $module, $attrs, $group_id );

					if ( $clean instanceof \WP_Error ) {
						return $this->attrs_error( $clean );
					}
				}

				$now = (int) ( $this->now )();
				$uid = get_current_user_id();

				if ( null !== $existing ) {
					$existing['name']    = $label;
					$existing['updated'] = $now;

					if ( null !== $clean ) {
						$existing['attrs']    = $clean;
						$existing['authorId'] = $uid;
					}

					$items[ $id ] = $existing;
				} else {
					$new_id = $this->unused_id( $items );

					if ( null === $new_id ) {
						return new \WP_Error( 'nexo_mcp_divi_design_write_failed', __( 'No se pudo generar un id nuevo para el preset.', 'nexo-mcp' ) );
					}

					$final = $new_id;
					$head  = array(
						'type' => $kind,
						'id'   => $new_id,
						'name' => $label,
					);
					$tail  = array(
						'moduleName' => $module,
						'version'    => (string) ( $this->version )(),
						'created'    => $now,
						'updated'    => $now,
						'authorId'   => $uid,
						'attrs'      => $clean,
					);

					// Un preset de grupo lleva además el nombre y el id del grupo, entre el nombre y el módulo.
					$item = 'group' === $kind
						? array_merge(
							$head,
							array(
								'groupName' => $name,
								'groupId'   => (string) $group_id,
							),
							$tail
						)
						: array_merge( $head, $tail );

					$items[ $new_id ] = $item;
				}

				if ( ! array_key_exists( 'default', $collection ) ) {
					$collection['default'] = '';
				}

				$collection['items']       = $items;
				$current['module']         = isset( $current['module'] ) && is_array( $current['module'] ) ? $current['module'] : array();
				$current['group']          = isset( $current['group'] ) && is_array( $current['group'] ) ? $current['group'] : array();
				$current[ $kind ][ $name ] = $collection;

				return $current;
			},
			'set-divi-preset'
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return array_merge(
			array(
				'kind'    => $kind,
				'name'    => $name,
				'id'      => $final,
				'label'   => $label,
				'created' => $created,
			),
			$result
		);
	}

	/**
	 * Fija (con id) o quita (null) el predeterminado de un módulo o de un grupo de opciones.
	 *
	 * @param array<string, mixed> $input `{kind, name, id|null}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_default_preset( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$target = $this->target( $input );

		if ( $target instanceof \WP_Error ) {
			return $target;
		}

		list( $kind, $name ) = $target;

		if ( ! array_key_exists( 'id', $input ) || ( null !== $input['id'] && ( ! is_string( $input['id'] ) || '' === $input['id'] ) ) ) {
			return $this->invalid( 'id', __( 'debe ser el id de un preset de esa colección o null para quitar el predeterminado.', 'nexo-mcp' ) );
		}

		$id     = $input['id'];
		$result = $this->store->update(
			function ( array $current ) use ( $kind, $name, $id ) {
				$collection = isset( $current[ $kind ][ $name ] ) && is_array( $current[ $kind ][ $name ] ) ? $current[ $kind ][ $name ] : null;
				$stored     = null !== $collection && isset( $collection['default'] ) && is_string( $collection['default'] ) ? $collection['default'] : '';

				if ( null === $id ) {
					if ( null === $collection || '' === $stored ) {
						return new \WP_Error( self::UNCHANGED );
					}

					$current[ $kind ][ $name ]['default'] = '';

					return $current;
				}

				$item = null !== $collection && isset( $collection['items'][ $id ] ) && is_array( $collection['items'][ $id ] ) ? $collection['items'][ $id ] : null;

				if ( null === $item ) {
					return $this->not_found( $id );
				}

				$context = $this->context( $kind, $name, $item, array() );

				if ( $context instanceof \WP_Error ) {
					return $context;
				}

				if ( $stored === $id ) {
					return new \WP_Error( self::UNCHANGED );
				}

				$current[ $kind ][ $name ]['default'] = $id;

				return $current;
			},
			'set-divi-default-preset'
		);

		$base = array(
			'kind' => $kind,
			'name' => $name,
			'id'   => $id,
		);

		if ( $result instanceof \WP_Error ) {
			return self::UNCHANGED === $result->get_error_code() ? array_merge( $base, array( 'changed' => false ) ) : $result;
		}

		return array_merge( $base, array( 'changed' => true ), $result );
	}

	/**
	 * Borra un preset; en uso sólo con `force` (el permiso destructivo lo exige la herramienta).
	 *
	 * @param array<string, mixed> $input `{kind, name, id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_preset( array $input ) {
		if ( ! $this->store->available() ) {
			return Divi5ContentWriter::required_error();
		}

		$target = $this->target( $input );

		if ( $target instanceof \WP_Error ) {
			return $target;
		}

		list( $kind, $name ) = $target;

		$id = $input['id'] ?? null;

		if ( ! is_string( $id ) || '' === $id ) {
			return $this->invalid( 'id', __( 'es obligatorio: el id del preset que se borra.', 'nexo-mcp' ) );
		}

		$force      = true === ( $input['force'] ?? false );
		$posts      = array();
		$total      = 0;
		$cleared    = false;
		$unverified = false;
		$removed    = array();

		// La búsqueda de uso corre dentro del bloqueo del almacén, junto con el borrado: comprobar y actuar
		// es atómico y nadie puede empezar a usar el preset entre una cosa y la otra.
		$result = $this->store->update(
			function ( array $current ) use ( $kind, $name, $id, $force, &$posts, &$total, &$cleared, &$unverified, &$removed ) {
				$collection = isset( $current[ $kind ][ $name ] ) && is_array( $current[ $kind ][ $name ] ) ? $current[ $kind ][ $name ] : null;
				$item       = null !== $collection && isset( $collection['items'][ $id ] ) && is_array( $collection['items'][ $id ] ) ? $collection['items'][ $id ] : null;

				if ( null === $item ) {
					return $this->not_found( $id );
				}

				$context = $this->context( $kind, $name, $item, array() );

				if ( $context instanceof \WP_Error ) {
					return $context;
				}

				// WordPress escapa `--`, `<`, `>`, `&` y `"` (y Divi acepta ids libres) en el JSON del bloque: la búsqueda del
				// id tal cual no los encontraría y daría un «sin uso» falso. Elección: no se adivina la forma escapada; el
				// borrado sin `force` se rechaza y con `force` se avisa de que el uso no se verificó.
				if ( self::is_unscannable_id( $id ) ) {
					if ( ! $force ) {
						return new \WP_Error(
							'nexo_mcp_divi_preset_usage_unknown',
							sprintf(
								/* translators: %s: id del preset. */
								__( 'El id «%s» tiene caracteres que WordPress escapa en el contenido de las páginas (como --, <, >, & o comillas): no se puede comprobar si el preset está en uso. No se borró nada. Repite con force: true (pide además el permiso destructivo) si estás seguro.', 'nexo-mcp' ),
								$id
							),
							array( 'id' => $id )
						);
					}

					$unverified = true;
					$usage      = array(
						'post_ids' => array(),
						'total'    => 0,
					);
				} else {
					$usage = $this->usage( $kind, $id );
				}

				$posts   = $usage['post_ids'];
				$total   = $usage['total'];
				$linking = 'group' === $kind ? $this->presets_linking_group( $current, $id ) : array();

				if ( ( array() !== $posts || array() !== $linking ) && ! $force ) {
					$data = array( 'post_ids' => $posts );

					if ( $total > count( $posts ) ) {
						$data['post_ids_truncated'] = true;
						$data['post_total']         = $total;
					}

					if ( array() !== $linking ) {
						$data['preset_ids'] = $linking;
					}

					return new \WP_Error( 'nexo_mcp_divi_preset_in_use', $this->in_use_message( $id, $posts, $linking, $total ), $data );
				}

				unset( $collection['items'][ $id ] );
				$cleared = isset( $collection['default'] ) && $collection['default'] === $id;

				if ( $cleared ) {
					$collection['default'] = '';
				}

				if ( array() === $collection['items'] ) {
					unset( $current[ $kind ][ $name ] );
				} else {
					$current[ $kind ][ $name ] = $collection;
				}

				// Un preset de grupo enlazado desde un preset de módulo: el enlace se quita en el mismo cambio.
				if ( 'group' === $kind && array() !== $linking ) {
					$current = $this->unlink_group( $current, $id, $removed );
				}

				return $current;
			},
			'delete-divi-preset'
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$out = array(
			'deleted'         => $id,
			'kind'            => $kind,
			'name'            => $name,
			'default_cleared' => $cleared,
			'posts_affected'  => $posts,
		);

		if ( $total > count( $posts ) ) {
			$out['posts_affected_truncated'] = true;
			$out['posts_affected_total']     = $total;
		}

		if ( $unverified ) {
			$out['usage_unverified'] = true;
		}

		if ( array() !== $removed ) {
			$out['links_removed'] = $removed;
		}

		return array_merge( $out, $result );
	}

	/**
	 * Entradas de Divi 5 no borradas que usan un preset (su id exacto en `modulePreset` o en el `presetId` de `groupPreset`).
	 *
	 * Devuelve los primeros {@see self::MAX_POST_IDS} ids y el total: se recorren todos los lotes para contar, así que un
	 * uso grande no se confunde con uno chico.
	 *
	 * Busca el id con `wp_json_encode` (sin escapar `/` ni Unicode) porque así queda el id dentro del JSON de los
	 * atributos de un bloque. WordPress, al guardar el bloque (`serialize_block_attributes()`), además reemplaza en ese
	 * JSON `--` por `\u002d\u002d`, `<` por `\u003c`, `>` por `\u003e`, `&` por `\u0026` y `"` por `\u0022`: un id con
	 * esos caracteres no aparece tal cual en `post_content`, así que esta búsqueda no lo encuentra. Por eso
	 * {@see self::is_unscannable_id()} los aparta antes (sin `force` el borrado se rechaza); mantener ambas listas
	 * de caracteres al día juntas.
	 *
	 * @param string $kind `module` o `group`.
	 * @param string $id   Id del preset.
	 *
	 * @return array{post_ids: array<int, int>, total: int}
	 */
	public static function scan_preset_usage( string $kind, string $id ): array {
		global $wpdb;

		$posts  = array();
		$total  = 0;
		$after  = 0;
		$more   = true;
		$quoted = preg_quote( substr( (string) wp_json_encode( $id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 1, -1 ), '/' );
		$key    = 'group' === $kind ? 'presetId' : 'modulePreset';
		$exact  = '/"' . $key . '"\s*:\s*(?:\[[^\]]*"' . $quoted . '"[^\]]*\]|"' . $quoted . '")/';

		// Sin tope de filas: se recorre por lotes (clave `ID > último`) y cada lote se filtra con la expresión exacta,
		// así un id parecido (`abc` frente a `abc-1`) no puede agotar un límite y esconder un uso real.
		while ( $more ) {
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
					++$total;

					if ( count( $posts ) < self::MAX_POST_IDS ) {
						$posts[] = (int) $row['ID'];
					}
				}
			}

			$more = count( $rows ) >= self::SCAN_BATCH;
		}

		return array(
			'post_ids' => $posts,
			'total'    => $total,
		);
	}

	/**
	 * Indica si el id tiene caracteres que WordPress escapa en el JSON de un bloque (`--`, `<`, `>`, `&`, comillas, barra
	 * invertida) o que no son ASCII imprimible: la búsqueda de uso no los puede encontrar con seguridad.
	 *
	 * La regex y {@see self::scan_preset_usage()} son las dos mitades de lo mismo: WordPress escapa los atributos de un
	 * bloque en `serialize_block_attributes()` (`--` → `\u002d\u002d`, `<` → `\u003c`, `>` → `\u003e`, `&` → `\u0026`,
	 * `"` → `\u0022`; la barra invertida y lo no ASCII también cambian de forma en el JSON), y el escaneo sólo conoce la
	 * forma de `wp_json_encode`, sin esos escapes. Cualquier carácter que se agregue a una lista va en la otra.
	 *
	 * @param string $id Id del preset.
	 */
	private static function is_unscannable_id( string $id ): bool {
		return 1 === preg_match( '/--|[<>&"\\\\]|[^\x20-\x7e]/', $id );
	}

	/**
	 * Uso de un preset con la forma `{post_ids, total}` (el total nunca es menor que los ids).
	 *
	 * @param string $kind `module` o `group`.
	 * @param string $id   Id del preset.
	 *
	 * @return array{post_ids: array<int, int>, total: int}
	 */
	private function usage( string $kind, string $id ): array {
		$found = ( $this->find_usage )( $kind, $id );
		$ids   = isset( $found['post_ids'] ) && is_array( $found['post_ids'] ) ? array_values( array_map( 'intval', $found['post_ids'] ) ) : array();

		return array(
			'post_ids' => $ids,
			'total'    => max( count( $ids ), isset( $found['total'] ) && is_numeric( $found['total'] ) ? (int) $found['total'] : 0 ),
		);
	}

	/**
	 * Quita el id de un preset de grupo de los `groupPresets` de todos los presets de módulo.
	 *
	 * Un grupo cuya lista de ids queda vacía se quita; si `groupPresets` queda vacío se quita también. El resto del
	 * preset de módulo no cambia.
	 *
	 * @param array<string, mixed> $current Catálogo completo.
	 * @param string               $id      Id del preset de grupo borrado.
	 * @param array<int, string>   $removed Ids de los presets de módulo que se cambiaron (salida).
	 *
	 * @return array<string, mixed>
	 */
	private function unlink_group( array $current, string $id, array &$removed ): array {
		$modules = isset( $current['module'] ) && is_array( $current['module'] ) ? $current['module'] : array();

		foreach ( $modules as $module => $collection ) {
			if ( ! is_array( $collection ) || ! isset( $collection['items'] ) || ! is_array( $collection['items'] ) ) {
				continue;
			}

			foreach ( $collection['items'] as $key => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['groupPresets'] ) || ! is_array( $item['groupPresets'] ) ) {
					continue;
				}

				$links   = $item['groupPresets'];
				$changed = false;

				foreach ( $links as $group_id => $entry ) {
					$ids = is_array( $entry ) ? ( $entry['presetId'] ?? null ) : $entry;
					$ids = is_array( $ids ) ? $ids : array( $ids );

					if ( ! in_array( $id, $ids, true ) ) {
						continue;
					}

					$changed = true;
					$left    = array_values( array_filter( $ids, static fn( $other ): bool => $other !== $id ) );

					if ( array() === $left ) {
						unset( $links[ $group_id ] );
					} elseif ( is_array( $entry ) ) {
						$links[ $group_id ]['presetId'] = $left;
					} else {
						$links[ $group_id ] = $left;
					}
				}

				if ( ! $changed ) {
					continue;
				}

				if ( array() === $links ) {
					unset( $item['groupPresets'] );
				} else {
					$item['groupPresets'] = $links;
				}

				$current['module'][ $module ]['items'][ $key ] = $item;
				$removed[]                                     = isset( $item['id'] ) && is_string( $item['id'] ) ? $item['id'] : (string) $key;
			}
		}

		return $current;
	}

	/**
	 * Validador de attrs (se crea al usarlo).
	 */
	private function validator(): DiviTreeValidator {
		$this->validator ??= new DiviTreeValidator();

		return $this->validator;
	}

	/**
	 * Tipo y nombre de la colección de la entrada: `module` (un módulo del catálogo) o `group` (un grupo de opciones).
	 *
	 * @param array<string, mixed> $input Entrada.
	 *
	 * @return array{0: string, 1: string}|\WP_Error
	 */
	private function target( array $input ) {
		$kind = $input['kind'] ?? null;

		if ( 'module' !== $kind && 'group' !== $kind ) {
			return $this->invalid( 'kind', __( 'debe ser «module» (preset de un módulo) o «group» (preset de un grupo de opciones).', 'nexo-mcp' ) );
		}

		$name = $input['name'] ?? null;

		if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\/-]{0,99}\z/', $name ) ) {
			return $this->invalid(
				'name',
				'module' === $kind
					? __( 'debe ser el nombre de bloque de un módulo del catálogo (divi/text, divi/heading…).', 'nexo-mcp' )
					: __( 'debe ser el nombre del grupo de opciones (divi/background, divi/font-body…), como lo da get-divi-presets en available_groups.', 'nexo-mcp' )
			);
		}

		if ( 'module' === $kind && ! DiviModuleCatalog::has( $name ) ) {
			return $this->module_unknown();
		}

		return array( $kind, $name );
	}

	/**
	 * Módulo y grupo al que pertenece el preset, ya validados.
	 *
	 * @param string                    $kind     `module` o `group`.
	 * @param string                    $name     Módulo o grupo de la colección.
	 * @param array<string, mixed>|null $existing Item guardado (null al crear).
	 * @param array<string, mixed>      $input    Entrada (`module` y `group_id` de un preset de grupo nuevo).
	 *
	 * @return array{0: string, 1: string|null}|\WP_Error
	 */
	private function context( string $kind, string $name, ?array $existing, array $input ) {
		if ( 'module' === $kind ) {
			return array( $name, null );
		}

		$given_module = $input['module'] ?? null;
		$given_group  = $input['group_id'] ?? null;

		if ( null !== $existing ) {
			$module   = isset( $existing['moduleName'] ) && is_string( $existing['moduleName'] ) ? $existing['moduleName'] : '';
			$group_id = isset( $existing['groupId'] ) && is_string( $existing['groupId'] ) ? $existing['groupId'] : '';

			if ( null !== $given_module && $given_module !== $module ) {
				return $this->invalid( 'module', __( 'un preset de grupo no cambia de módulo: se omite o es el del preset.', 'nexo-mcp' ) );
			}

			if ( null !== $given_group && $given_group !== $group_id ) {
				return $this->invalid( 'group_id', __( 'un preset de grupo no cambia de grupo: se omite o es el del preset.', 'nexo-mcp' ) );
			}

			if ( ! DiviModuleCatalog::has( $module ) ) {
				return $this->module_unknown();
			}

			return array( $module, '' === $group_id ? null : $group_id );
		}

		if ( ! is_string( $given_module ) || '' === $given_module ) {
			return $this->invalid( 'module', __( 'es obligatorio para crear un preset de grupo: el módulo del catálogo para el que se hace.', 'nexo-mcp' ) );
		}

		if ( ! is_string( $given_group ) || '' === $given_group ) {
			return $this->invalid( 'group_id', __( 'es obligatorio para crear un preset de grupo: el id del grupo (por ejemplo module.decoration.background, de available_groups).', 'nexo-mcp' ) );
		}

		if ( ! DiviModuleCatalog::has( $given_module ) ) {
			return $this->module_unknown();
		}

		$groups = $this->store->module_groups( $given_module );

		if ( ! isset( $groups[ $given_group ] ) ) {
			return $this->invalid(
				'group_id',
				sprintf(
					/* translators: 1: módulo, 2: grupos que tiene. */
					__( '«%1$s» no tiene ese grupo de opciones. Grupos: %2$s.', 'nexo-mcp' ),
					$given_module,
					implode( ', ', array_keys( $groups ) )
				)
			);
		}

		if ( $groups[ $given_group ] !== $name ) {
			return $this->invalid(
				'name',
				sprintf(
					/* translators: 1: grupo, 2: id del grupo. */
					__( 'el grupo %2$s se llama «%1$s»; usa ese nombre.', 'nexo-mcp' ),
					$groups[ $given_group ],
					$given_group
				)
			);
		}

		return array( $given_module, $given_group );
	}

	/**
	 * Nombre del preset: texto plano de 1 a 100 caracteres, sin etiquetas, saltos ni shortcodes.
	 *
	 * @param mixed $value Valor.
	 *
	 * @return string|\WP_Error
	 */
	private function label( $value ) {
		$text = is_string( $value ) ? trim( $value ) : null;

		if ( null !== $text && '' !== $text && mb_strlen( $text ) <= 100 && 1 !== preg_match( '/[<>\x00-\x1F\x7F]/', $text ) && 1 !== preg_match( '/\[\/?[A-Za-z_][\w-]*[^\]]*\]/', $text ) ) {
			return $text;
		}

		return $this->invalid( 'label', __( 'debe ser texto plano de 1 a 100 caracteres, sin etiquetas, saltos de línea ni shortcodes.', 'nexo-mcp' ) );
	}

	/**
	 * Id nuevo que no esté en la colección.
	 *
	 * @param array<string, mixed> $items Items de la colección.
	 */
	private function unused_id( array $items ): ?string {
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$candidate = (string) ( $this->uuid )();

			if ( '' !== $candidate && ! isset( $items[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Presets de módulo que enlazan un preset de grupo en sus `groupPresets`.
	 *
	 * @param array<string, mixed> $current Catálogo completo.
	 * @param string               $id      Id del preset de grupo.
	 *
	 * @return array<int, string>
	 */
	private function presets_linking_group( array $current, string $id ): array {
		$found   = array();
		$modules = isset( $current['module'] ) && is_array( $current['module'] ) ? $current['module'] : array();

		foreach ( $modules as $collection ) {
			$items = is_array( $collection ) && isset( $collection['items'] ) && is_array( $collection['items'] ) ? $collection['items'] : array();

			foreach ( $items as $key => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['groupPresets'] ) ) {
					continue;
				}

				$encoded = wp_json_encode( $item['groupPresets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

				if ( is_string( $encoded ) && str_contains( $encoded, (string) wp_json_encode( $id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) {
					$found[] = isset( $item['id'] ) && is_string( $item['id'] ) ? $item['id'] : (string) $key;
				}
			}
		}

		return $found;
	}

	/**
	 * Mensaje de «en uso».
	 *
	 * @param string             $id      Id del preset.
	 * @param array<int, int>    $posts   Entradas que lo usan.
	 * @param array<int, string> $linking Presets de módulo que lo enlazan.
	 * @param int                $total   Total de entradas que lo usan (puede ser mayor que las listadas).
	 */
	private function in_use_message( string $id, array $posts, array $linking, int $total = 0 ): string {
		$where = array();

		if ( array() !== $posts ) {
			if ( $total > count( $posts ) ) {
				/* translators: 1: cuántas se listan, 2: total de entradas, 3: ids de las entradas. */
				$where[] = sprintf( __( 'en %2$d entradas de Divi 5 (se listan las primeras %1$d, ids: %3$s)', 'nexo-mcp' ), count( $posts ), $total, implode( ', ', $posts ) );
			} else {
				/* translators: %s: ids de las entradas. */
				$where[] = sprintf( __( 'en estas entradas de Divi 5 (ids): %s', 'nexo-mcp' ), implode( ', ', $posts ) );
			}
		}

		if ( array() !== $linking ) {
			/* translators: %s: ids de los presets. */
			$where[] = sprintf( __( 'enlazado desde estos presets de módulo (ids): %s', 'nexo-mcp' ), implode( ', ', $linking ) );
		}

		return sprintf(
			/* translators: 1: id del preset, 2: dónde se usa. */
			__( 'El preset %1$s está en uso %2$s. Borrarlo deja esos módulos sin su estilo; no se borró nada. Repite con force: true (pide además el permiso destructivo) para borrarlo igual.', 'nexo-mcp' ),
			$id,
			implode( '; ', $where )
		);
	}

	/**
	 * Error de validación de attrs del validador del árbol, con el código de preset y la regla original.
	 *
	 * @param \WP_Error $error Error del validador.
	 */
	private function attrs_error( \WP_Error $error ): \WP_Error {
		// El contenido dinámico denegado conserva su propio código (con `data.path`), como en el resto de escrituras de Divi.
		if ( 'nexo_mcp_divi_dynamic_denied' === $error->get_error_code() ) {
			return $error;
		}

		$data = $error->get_error_data();
		$path = is_array( $data ) && isset( $data['path'] ) && is_string( $data['path'] ) ? $data['path'] : 'attrs';

		return new \WP_Error(
			'nexo_mcp_divi_preset_invalid',
			$error->get_error_message(),
			array(
				'path' => $path,
				'rule' => $error->get_error_code(),
			)
		);
	}

	/**
	 * Módulo fuera del catálogo.
	 */
	private function module_unknown(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_preset_module_unknown',
			sprintf(
				/* translators: %s: módulos admitidos. */
				__( 'Módulo fuera del catálogo. Los presets se gestionan para: %s.', 'nexo-mcp' ),
				implode( ', ', DiviModuleCatalog::modules() )
			)
		);
	}

	/**
	 * Error de entrada con «ruta: motivo» y `data.path`.
	 *
	 * @param string $path   Campo.
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_divi_preset_invalid', $path . ': ' . $reason, array( 'path' => $path ) );
	}

	/**
	 * Id inexistente.
	 *
	 * @param string $id Id.
	 */
	private function not_found( string $id ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_preset_not_found',
			sprintf(
				/* translators: %s: id. */
				__( 'No existe el preset «%s» en esa colección de Divi. Revisa los ids con get-divi-presets.', 'nexo-mcp' ),
				$id
			),
			array( 'id' => $id )
		);
	}
}
