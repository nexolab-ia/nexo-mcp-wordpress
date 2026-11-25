<?php
/**
 * Edición de un módulo de Divi 5 por ruta y huella, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Cambia el contenido y/o el diseño de escritorio de un módulo de contenido del catálogo.
 *
 * Mismo camino que {@see DynamicTagAssigner}: todo corre bajo el bloqueo del original;
 * destino (copia o borrador), bloqueo de edición y modo «sólo borradores» antes de leer;
 * luego se localiza el nodo por `path`, se compara su `fingerprint` (si cambió, no se
 * escribe), se valida el parche con {@see DiviTreeValidator::validate_patch()} y sólo
 * entonces se crea o reutiliza la copia, se respalda y se guarda. Nunca publica.
 *
 * `preset` (`{module?, groups?}`) pone o quita `modulePreset`/`groupPreset` del bloque con
 * {@see DiviTreeValidator::validate_preset_ref()}; los ids deben existir en el catálogo de presets. Con `preset`, el
 * destino, la validación y el guardado corren además bajo el bloqueo de presets ({@see DiviDesignStore::LOCK_ID}, el del
 * borrado de presets), tomado después del de la página: un borrado no puede colarse entre «el preset existe» y «la
 * página se guardó»; si está ocupado se responde su error y no se escribe. Sin `preset` no se toma.
 *
 * `dynamic` (`{field, source}`; sólo lo pasa `set-divi-dynamic`) cambia en cambio UN campo de contenido con un token de
 * contenido dinámico (o, con `source` null, lo deja vacío si hoy tiene un token). El token sale del
 * {@see DiviDynamicCatalog} a través del validador que se pasó al construir: sin contexto dinámico cualquier token se
 * rechaza, y quien llama sólo lo pasa tras `PermissionPolicy::can_write_divi_dynamic()`. Con o sin `dynamic`, el
 * bloque final se reescanea: sus únicos tokens son los que ya tenía y, a lo sumo, el que acaba de emitir el validador en
 * la ruta del campo ({@see DiviTreeValidator::stray_token_path()}); cualquier otro se rechaza sin escribir.
 *
 * `loop` (sólo lo pasa `set-divi-loop`; un arreglo {@see DiviLoopSchema::validate()} o `null`) activa o quita el bucle de
 * consulta de un módulo que lo admite ({@see DiviLoopSchema::MODULES}): escribe `module.advanced.loop.desktop.value` con
 * SÓLO las claves del esquema (nada del cliente pasa tal cual) y ninguna otra cosa del bloque. Se rechaza si algún
 * ancestro o descendiente ya tiene un bucle activo (`nexo_mcp_divi_loop_nested`) o si dentro quedan tokens `loop_*` que el
 * bucle nuevo (o ninguno) no admite (`nexo_mcp_divi_loop_in_use`). Con `dynamic`, las fuentes `loop_*` valen sólo si el
 * módulo o un ancestro tiene un bucle del conector válido AHORA ({@see DiviLoopSchema::valid_stored()}; el más interno manda).
 *
 * `conditions` (sólo lo pasa `set-divi-conditions`; `{match, conditions}` de {@see DiviConditionsSchema::validate()}) pone o
 * quita las condiciones de visualización de un módulo que las admite ({@see DiviConditionsSchema::MODULES}): escribe
 * `module.decoration.conditions.desktop.value` con SÓLO lo que arma {@see DiviConditionsSchema::stored()} (conserva los
 * `id` de lo que no cambió) y ninguna otra cosa del bloque; la lista vacía quita la clave y poda los padres vacíos. Una
 * lista que no es del conector ({@see DiviConditionsSchema::summary()}, `ours` falso) sólo se reemplaza o quita con
 * `replace_foreign: true` (`nexo_mcp_divi_conditions_foreign`, con el resumen en `data`).
 *
 * El parche validado sólo trae rutas `<ruta>.desktop.value`; se fusiona en profundidad
 * con los atributos del bloque, de modo que tablet, teléfono, hover y todo lo no tocado
 * queda igual. El contenido que ya pasó el validador se guarda con los filtros kses
 * quitados sólo durante esa escritura: el kses del núcleo corrompe los comentarios de
 * bloque cuando el usuario no tiene `unfiltered_html`.
 */
final class Divi5ModuleEditor {

	/**
	 * Camino de escritura compartido.
	 *
	 * @var Divi5ContentWriter
	 */
	private Divi5ContentWriter $writer;

	/**
	 * Validador del parche.
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Bloqueo de presets: fn( callable ): mixed.
	 *
	 * @var callable
	 */
	private $presets_lock;

	/**
	 * Esquema del bucle de consulta.
	 *
	 * @var DiviLoopSchema
	 */
	private DiviLoopSchema $loops;

	/**
	 * Esquema de las condiciones de visualización.
	 *
	 * @var DiviConditionsSchema
	 */
	private DiviConditionsSchema $conditions;

	/**
	 * Construye el editor.
	 *
	 * @param Settings                  $settings     Configuración.
	 * @param Divi5Adapter              $adapter      Adaptador de Divi 5.
	 * @param BuilderBackup             $backup       Respaldos.
	 * @param WorkingCopy               $copies       Copias de trabajo.
	 * @param DiviTreeValidator         $validator    Validador del parche.
	 * @param callable                  $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null             $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null             $saver        fn( int, string ): true|\WP_Error. Por omisión, `wp_update_post()` (sólo pruebas).
	 * @param callable|null             $presets_lock fn( callable ): mixed. Bloqueo de presets; por omisión, el de {@see DiviDesignStore::LOCK_ID}.
	 * @param DiviLoopSchema|null       $loops      Esquema del bucle de consulta (las pruebas inyectan tipos y términos).
	 * @param DiviConditionsSchema|null $conditions Esquema de las condiciones de visualización (las pruebas inyectan roles, tipos y WooCommerce).
	 */
	public function __construct(
		Settings $settings,
		Divi5Adapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		DiviTreeValidator $validator,
		callable $lock_check,
		?callable $divi5_active = null,
		?callable $saver = null,
		?callable $presets_lock = null,
		?DiviLoopSchema $loops = null,
		?DiviConditionsSchema $conditions = null
	) {
		$this->writer       = new Divi5ContentWriter( $settings, $adapter, $backup, $copies, $lock_check, $divi5_active, $saver );
		$this->validator    = $validator;
		$this->presets_lock = $presets_lock ?? static fn( callable $work ) => PostLock::run( DiviDesignStore::LOCK_ID, $work );
		$this->loops        = $loops ?? new DiviLoopSchema();
		$this->conditions   = $conditions ?? new DiviConditionsSchema();
	}

	/**
	 * Edita el módulo.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `path`, `fingerprint`, `content`, `design`, `preset`, `dynamic` (`{field, source}`), `loop` (parámetros del bucle o null), `conditions` (`{match, conditions}`), `replace_foreign`, `draft`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit( \WP_Post $post, array $input ) {
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				if ( null === ( $input['preset'] ?? null ) ) {
					return $this->edit_locked( $fresh, $input );
				}

				return ( $this->presets_lock )( fn() => $this->edit_locked( $fresh, $input ) );
			}
		);
	}

	/**
	 * Edita ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_locked( \WP_Post $post, array $input ) {
		$target = $this->writer->target( $post, $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$prepared = $this->prepare( $target['source'], $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$written = $this->writer->write( $post, $target, $prepared['content'] );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$fresh = get_post( $written->ID );
		$node  = Divi5Tree::locate( Divi5Tree::build( $fresh instanceof \WP_Post ? (string) $fresh->post_content : $prepared['content'] ), $prepared['path'] );

		return array_merge(
			Divi5ContentWriter::describe( $written ),
			array(
				'path'        => $prepared['path'],
				'fingerprint' => null === $node ? '' : (string) $node['fingerprint'],
			),
			$prepared['extra']
		);
	}

	/**
	 * Localiza el nodo, comprueba la huella, valida el parche y arma el contenido nuevo.
	 *
	 * @param \WP_Post             $source Contenido sobre el que se trabaja.
	 * @param array<string, mixed> $input  Parámetros.
	 *
	 * @return array{content: string, path: string, extra: array<string, mixed>}|\WP_Error
	 */
	private function prepare( \WP_Post $source, array $input ) {
		$path        = isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '';
		$fingerprint = isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '';
		$blocks      = parse_blocks( (string) $source->post_content );
		$tree        = Divi5Tree::build( (string) $source->post_content );
		$node        = Divi5Tree::locate( $tree, $path );
		$block       = Divi5Tree::locate_block( $blocks, $path );

		if ( null === $node || null === $block ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ! Divi5Tree::fingerprint_matches( $tree, $path, $fingerprint ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_node_changed',
				__( 'El módulo cambió desde que lo leíste (la huella no coincide). Vuelve a leer la página con nexo-mcp-get-page-structure; no se escribió nada.', 'nexo-mcp' )
			);
		}

		$type = (string) $node['type'];

		if ( array_key_exists( 'loop', $input ) ) {
			return $this->prepare_loop( $blocks, $path, $block, $type, $input['loop'] );
		}

		if ( array_key_exists( 'conditions', $input ) ) {
			$replace_foreign = isset( $input['replace_foreign'] ) && true === $input['replace_foreign'];

			return $this->prepare_conditions( $blocks, $path, $block, $type, $input['conditions'], $replace_foreign );
		}

		if ( ! in_array( $type, DiviModuleCatalog::CONTENT_MODULES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_module_denied',
				sprintf(
					/* translators: %s: nombre del bloque. */
					__( '«%s» no es un módulo de contenido editable (sólo texto, título, botón, imagen, divisor, blurb, ícono, video y lista).', 'nexo-mcp' ),
					$type
				)
			);
		}

		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$dynamic = isset( $input['dynamic'] ) && is_array( $input['dynamic'] ) ? $input['dynamic'] : null;
		$extra   = array();
		$spot    = null;
		$removes = null !== $dynamic && null === ( $dynamic['source'] ?? null );

		// Las fuentes `loop_*` valen sólo con un bucle del conector válido (propio o de un ancestro) en el árbol de AHORA.
		$validator = null !== $dynamic && ! $removes ? $this->validator->with_loop( $this->loop_context( $path, $blocks ) ) : $this->validator;

		if ( null !== $dynamic ) {
			$assigned = $this->dynamic_patch( $type, $dynamic, $attrs );

			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}

			$input['content'] = $assigned['content'];
			$spot             = $assigned['spot'];
			$extra            = $assigned['extra'];
		}

		$patch = array();

		foreach ( array( 'content', 'design' ) as $slot ) {
			if ( array_key_exists( $slot, $input ) && null !== $input[ $slot ] ) {
				$patch[ $slot ] = $input[ $slot ];
			}
		}

		$preset = $input['preset'] ?? null;
		$clean  = array();

		// Sin `preset` el parche vacío se rechaza como siempre; con `preset` el parche puede ir vacío.
		if ( array() !== $patch || null === $preset ) {
			// Quitar un token escribe una cadena vacía fija (no hay nada del cliente que validar).
			$clean = $removes ? array( 'content' => $patch['content'] ) : $validator->validate_patch( $type, $patch );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
		}

		$ref = null === $preset ? null : $this->validator->validate_preset_ref( $type, $preset );

		if ( is_wp_error( $ref ) ) {
			return $ref;
		}

		$emitted = array();

		if ( null !== $spot && ! $removes ) {
			$token = self::value_at( $clean['content'] ?? array(), $spot['location'] );

			if ( ! is_string( $token ) ) {
				return $this->stray( $spot['dotted'] );
			}

			$emitted = array( $spot['dotted'] => $token );
			$extra   = array_merge( $extra, array( 'token' => $token ) );
		}

		$original = DiviTreeValidator::token_strings( $attrs, '' );

		foreach ( $clean as $part ) {
			$attrs = self::merge( $attrs, $part );
		}

		// Defensa en profundidad sobre el bloque final: sólo los tokens que ya tenía y el que acaba de emitir el validador.
		$stray = DiviTreeValidator::stray_token_path( $attrs, '', array_merge( $original, $emitted ) );

		if ( null !== $stray ) {
			return $this->stray( $stray );
		}

		if ( null !== $ref ) {
			$attrs = DiviTreeValidator::apply_preset_ref( $attrs, $ref );
		}

		$block['attrs'] = $attrs;
		$replaced       = Divi5Tree::replace_block( $blocks, $path, $block );

		if ( null === $replaced ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		return array(
			'content' => serialize_blocks( $replaced ),
			'path'    => $path,
			'extra'   => $extra,
		);
	}

	/**
	 * Activa, cambia o quita el bucle de consulta de un módulo: el bloque nuevo ya serializado.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques de la página.
	 * @param string                           $path   Ruta del módulo.
	 * @param array<string, mixed>             $block  Bloque del módulo.
	 * @param string                           $type   Nombre del bloque.
	 * @param mixed                            $loop   Parámetros del bucle (o null para quitarlo).
	 *
	 * @return array{content: string, path: string, extra: array<string, mixed>}|\WP_Error
	 */
	private function prepare_loop( array $blocks, string $path, array $block, string $type, $loop ) {
		if ( ! in_array( $type, DiviLoopSchema::MODULES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_module_denied',
				sprintf(
					/* translators: 1: nombre del bloque, 2: módulos. */
					__( '«%1$s» no admite bucle de consulta. Admiten: %2$s.', 'nexo-mcp' ),
					$type,
					implode( ', ', DiviLoopSchema::MODULES )
				)
			);
		}

		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$removes = null === $loop;
		$params  = null;

		if ( $removes ) {
			if ( ! isset( $attrs['module']['advanced']['loop'] ) ) {
				return new \WP_Error(
					'nexo_mcp_divi_loop_not_set',
					__( 'loop: este módulo no tiene bucle de consulta; no se cambia nada.', 'nexo-mcp' ),
					array( 'path' => 'loop' )
				);
			}
		} else {
			$params = $this->loops->validate( $loop );

			if ( $params instanceof \WP_Error ) {
				return $params;
			}

			$nested = $this->nested_loop_path( $blocks, $path, $block );

			if ( null !== $nested ) {
				return new \WP_Error(
					'nexo_mcp_divi_loop_nested',
					sprintf(
						/* translators: %s: ruta del módulo que ya tiene bucle. */
						__( 'loop: no se anidan bucles y el módulo «%s» (ancestro o descendiente) ya tiene uno activo; no se escribe nada. Quita ese bucle primero.', 'nexo-mcp' ),
						$nested
					),
					array( 'path' => 'loop' )
				);
			}
		}

		$blocked = $this->loop_tokens_in_use( $block, $path, null === $params ? null : $params['post_type'] );

		if ( null !== $blocked ) {
			return new \WP_Error(
				'nexo_mcp_divi_loop_in_use',
				sprintf(
					/* translators: 1: ruta, 2: fuente. */
					__( 'loop: en «%1$s» hay una fuente «%2$s» que este bucle no admite (o ya no habría bucle para ella); no se escribe nada. Quítala primero con nexo-mcp-set-divi-dynamic (source: null) o cambia el tipo del bucle.', 'nexo-mcp' ),
					$blocked['path'],
					$blocked['name']
				),
				array( 'path' => 'loop' )
			);
		}

		$existing = DiviLoopSchema::loop_value( $attrs );
		$original = DiviTreeValidator::token_strings( $attrs, '' );
		$extra    = array(
			'loop'    => null,
			'removed' => true,
		);

		if ( null === $params ) {
			$attrs = DiviLoopSchema::without_loop( $attrs );
		} else {
			$own     = null === $existing ? null : $this->loops->valid_stored( $existing );
			$loop_id = null === $own ? $this->loops->new_id() : $own['loop_id'];

			if ( ! isset( $attrs['module'] ) || ! is_array( $attrs['module'] ) ) {
				$attrs['module'] = array();
			}

			if ( ! isset( $attrs['module']['advanced'] ) || ! is_array( $attrs['module']['advanced'] ) ) {
				$attrs['module']['advanced'] = array();
			}

			$attrs['module']['advanced']['loop'] = array( 'desktop' => array( 'value' => $this->loops->stored( $params, $loop_id ) ) );
			$extra                               = array(
				'loop' => array(
					'post_type'       => $params['post_type'],
					'per_page'        => $params['per_page'],
					'order_by'        => $params['order_by'],
					'order'           => $params['order'],
					'offset'          => $params['offset'],
					'exclude_current' => $params['exclude_current'],
					'terms'           => $params['terms'],
					'loop_id'         => $loop_id,
				),
			);
		}

		// Defensa en profundidad: el bloque no gana ningún token (ni se tocan los que tenía).
		$stray = DiviTreeValidator::stray_token_path( $attrs, '', $original );

		if ( null !== $stray ) {
			return $this->stray( $stray );
		}

		$block['attrs'] = $attrs;
		$replaced       = Divi5Tree::replace_block( $blocks, $path, $block );

		if ( null === $replaced ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		return array(
			'content' => serialize_blocks( $replaced ),
			'path'    => $path,
			'extra'   => $extra,
		);
	}

	/**
	 * Pone o quita las condiciones de visualización de un módulo: el bloque nuevo ya serializado.
	 *
	 * @param array<int, array<string, mixed>> $blocks          Bloques de la página.
	 * @param string                           $path            Ruta del módulo.
	 * @param array<string, mixed>             $block           Bloque del módulo.
	 * @param string                           $type            Nombre del bloque.
	 * @param mixed                            $conditions      `{match, conditions}` del cliente.
	 * @param bool                             $replace_foreign Si se confirma reemplazar o quitar una lista que no es del conector.
	 *
	 * @return array{content: string, path: string, extra: array<string, mixed>}|\WP_Error
	 */
	private function prepare_conditions( array $blocks, string $path, array $block, string $type, $conditions, bool $replace_foreign ) {
		if ( ! in_array( $type, DiviConditionsSchema::MODULES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_module_denied',
				sprintf(
					/* translators: 1: nombre del bloque, 2: módulos. */
					__( '«%1$s» no admite condiciones de visualización. Admiten: %2$s.', 'nexo-mcp' ),
					$type,
					implode( ', ', DiviConditionsSchema::MODULES )
				)
			);
		}

		$params = $this->conditions->validate( $conditions );

		if ( $params instanceof \WP_Error ) {
			return $params;
		}

		$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$existing = $attrs['module']['decoration']['conditions']['desktop']['value'] ?? null;
		$replaced = null;

		if ( null !== $existing && array() !== $existing ) {
			$summary = $this->conditions->summary( $existing );

			if ( ! $summary['ours'] ) {
				if ( ! $replace_foreign ) {
					return new \WP_Error(
						'nexo_mcp_divi_conditions_foreign',
						__( 'conditions: este módulo ya tiene condiciones que no son de este conector (por ejemplo hechas en el Visual Builder); reemplazarlas o quitarlas las pierde. Revisa el resumen en data.summary y, si quieres hacerlo, repite con replace_foreign: true. No se escribe nada.', 'nexo-mcp' ),
						array(
							'path'    => 'conditions',
							'summary' => $summary,
						)
					);
				}

				$replaced = $summary;
			}
		}

		$original = DiviTreeValidator::token_strings( $attrs, '' );
		$removes  = array() === $params['conditions'];
		$extra    = array(
			'match'      => null,
			'conditions' => array(),
			'removed'    => true,
		);

		if ( $removes ) {
			$attrs = self::without_conditions( $attrs );
		} else {
			$stored = $this->conditions->stored( $params, $existing );
			$cursor = &$attrs;

			foreach ( array( 'module', 'decoration', 'conditions', 'desktop' ) as $step ) {
				$cursor[ $step ] = is_array( $cursor[ $step ] ?? null ) ? $cursor[ $step ] : array();
				$cursor          = &$cursor[ $step ];
			}

			$cursor['value'] = $stored;
			unset( $cursor );

			$extra = array(
				'match'      => $params['match'],
				'conditions' => $stored,
			);
		}

		if ( null !== $replaced ) {
			$extra['replaced'] = $replaced;
		}

		// Defensa en profundidad: el bloque no gana ningún token (ni se tocan los que tenía).
		$stray = DiviTreeValidator::stray_token_path( $attrs, '', $original );

		if ( null !== $stray ) {
			return $this->stray( $stray );
		}

		$block['attrs'] = $attrs;
		$replaced_tree  = Divi5Tree::replace_block( $blocks, $path, $block );

		if ( null === $replaced_tree ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		return array(
			'content' => serialize_blocks( $replaced_tree ),
			'path'    => $path,
			'extra'   => $extra,
		);
	}

	/**
	 * Quita `module.decoration.conditions.desktop.value` y poda los padres que quedan vacíos (`desktop`, `conditions`,
	 * `decoration`, `module`); lo demás (otros puntos de ruptura, otra decoración) no se toca.
	 *
	 * @param array<string, mixed> $attrs Atributos del bloque.
	 *
	 * @return array<string, mixed>
	 */
	private static function without_conditions( array $attrs ): array {
		$desktop = $attrs['module']['decoration']['conditions']['desktop'] ?? null;

		if ( ! is_array( $desktop ) ) {
			return $attrs;
		}

		unset( $desktop['value'] );

		$levels = array( 'module', 'decoration', 'conditions', 'desktop' );
		$inner  = $desktop;

		for ( $i = count( $levels ) - 1; $i >= 0; $i-- ) {
			$holder = &$attrs;

			foreach ( array_slice( $levels, 0, $i ) as $step ) {
				$holder = &$holder[ $step ];
			}

			if ( array() === $inner ) {
				unset( $holder[ $levels[ $i ] ] );
			} else {
				$holder[ $levels[ $i ] ] = $inner;
			}

			$inner = $holder;
			unset( $holder );
		}

		return $attrs;
	}

	/**
	 * Ruta del primer módulo con un bucle activo entre los ancestros y los descendientes del módulo, o null.
	 *
	 * @param array<int, array<string, mixed>> $blocks Bloques de la página.
	 * @param string                           $path   Ruta del módulo.
	 * @param array<string, mixed>             $block  Bloque del módulo.
	 */
	private function nested_loop_path( array $blocks, string $path, array $block ): ?string {
		$parts   = explode( '.', $path );
		$current = '';

		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$current  = '' === $current ? $part : $current . '.' . $part;
			$ancestor = Divi5Tree::locate_block( $blocks, $current );

			if ( null !== $ancestor && DiviLoopSchema::has_loop( is_array( $ancestor['attrs'] ?? null ) ? $ancestor['attrs'] : array() ) ) {
				return $current;
			}
		}

		foreach ( Divi5Tree::descendants( $block, $path ) as $child ) {
			if ( DiviLoopSchema::has_loop( is_array( $child['block']['attrs'] ?? null ) ? $child['block']['attrs'] : array() ) ) {
				return $child['path'];
			}
		}

		return null;
	}

	/**
	 * Primer token `loop_*` del módulo o de sus descendientes que el bucle nuevo no admitiría (o cualquiera si se quita).
	 *
	 * @param array<string, mixed> $block     Bloque del módulo.
	 * @param string               $path      Ruta del módulo.
	 * @param string|null          $post_type Tipo del bucle nuevo, o null si se quita.
	 *
	 * @return array{path: string, name: string}|null
	 */
	private function loop_tokens_in_use( array $block, string $path, ?string $post_type ): ?array {
		$nodes = array_merge(
			array(
				array(
					'path'  => $path,
					'block' => $block,
				),
			),
			Divi5Tree::descendants( $block, $path )
		);

		foreach ( $nodes as $node ) {
			$attrs = is_array( $node['block']['attrs'] ?? null ) ? $node['block']['attrs'] : array();

			foreach ( DiviTreeValidator::token_strings( $attrs, '' ) as $token ) {
				foreach ( DiviDynamicCatalog::loop_token_names( $token ) as $name ) {
					if ( null === $post_type || ! DiviDynamicCatalog::loop_allows( $name, $post_type ) ) {
						return array(
							'path' => $node['path'],
							'name' => $name,
						);
					}
				}
			}
		}

		return null;
	}

	/**
	 * Tipo de contenido del bucle del conector que rige el módulo (el suyo o el del ancestro más cercano con un bucle
	 * activo), o null si ese bucle no es del conector o no hay bucle.
	 *
	 * @param string                           $path   Ruta del módulo.
	 * @param array<int, array<string, mixed>> $blocks Bloques de la página.
	 */
	private function loop_context( string $path, array $blocks ): ?string {
		$type    = null;
		$current = '';

		foreach ( explode( '.', $path ) as $part ) {
			$current = '' === $current ? $part : $current . '.' . $part;
			$found   = Divi5Tree::locate_block( $blocks, $current );
			$attrs   = null !== $found && is_array( $found['attrs'] ?? null ) ? $found['attrs'] : array();

			if ( DiviLoopSchema::has_loop( $attrs ) ) {
				$valid = $this->loops->valid_stored( DiviLoopSchema::loop_value( $attrs ) );
				$type  = null === $valid ? null : $valid['post_type'];
			}
		}

		return $type;
	}

	/**
	 * Arma el parche de contenido de `set-divi-dynamic`: el valor del cliente (`{name, settings?}`) como token en bruto
	 * en la ruta del campo, o la cadena vacía si `source` es null (sólo si el campo tiene hoy un token).
	 *
	 * El token en bruto no se guarda: lo valida y re-serializa {@see DiviTreeValidator::validate_patch()} con el catálogo.
	 *
	 * @param string               $type    Módulo.
	 * @param array<string, mixed> $dynamic `{field, source}`.
	 * @param array<string, mixed> $attrs   Atributos actuales del bloque.
	 *
	 * @return array{content: array<string, mixed>, spot: array{location: array<int, string>, dotted: string}, extra: array<string, mixed>}|\WP_Error
	 */
	private function dynamic_patch( string $type, array $dynamic, array $attrs ) {
		$field = is_string( $dynamic['field'] ?? null ) ? $dynamic['field'] : '';
		$spec  = DiviDynamicCatalog::field( $type, $field );

		if ( null === $spec ) {
			$fields = DiviDynamicCatalog::fields_of( $type );

			return new \WP_Error(
				'nexo_mcp_divi_dynamic_invalid',
				sprintf(
					/* translators: 1: campo, 2: módulo, 3: campos admitidos. */
					__( 'field: «%1$s» no es un campo de %2$s que admita contenido dinámico. Admitidos: %3$s.', 'nexo-mcp' ),
					$field,
					$type,
					array() === $fields ? __( 'ninguno', 'nexo-mcp' ) : implode( ', ', $fields )
				),
				array( 'path' => 'field' )
			);
		}

		$location = array_merge( explode( '.', $spec['path'] ), array( 'desktop', 'value' ), '' === $spec['key'] ? array() : array( $spec['key'] ) );
		$dotted   = implode( '.', $location );
		$source   = $dynamic['source'] ?? null;

		if ( null === $source ) {
			$current = self::value_at( $attrs, $location );

			if ( ! is_string( $current ) || ! DiviTreeValidator::has_dynamic_token( $current ) ) {
				return new \WP_Error(
					'nexo_mcp_divi_dynamic_not_set',
					sprintf(
						/* translators: %s: campo. */
						__( 'field: «%s» no tiene contenido dinámico; no se cambia nada (para poner un texto usa nexo-mcp-edit-divi-module).', 'nexo-mcp' ),
						$field
					),
					array( 'path' => 'field' )
				);
			}

			// Sólo se vacía un campo que es EXACTAMENTE un token: si lo rodea otro texto (p. ej. un <p> del Visual Builder) se borraría ese texto.
			if ( ! DiviDynamicCatalog::is_single_token( $current ) ) {
				return new \WP_Error(
					'nexo_mcp_divi_dynamic_mixed',
					sprintf(
						/* translators: %s: campo. */
						__( 'field: «%s» mezcla el token con otro texto (o lleva más de uno); quitarlo borraría ese texto y no se cambia nada. Reemplaza el valor entero del campo con nexo-mcp-edit-divi-module.', 'nexo-mcp' ),
						$field
					),
					array( 'path' => 'field' )
				);
			}

			return array(
				'content' => self::nest( $location, '' ),
				'spot'    => array(
					'location' => $location,
					'dotted'   => $dotted,
				),
				'extra'   => array(
					'field'   => $field,
					'removed' => true,
				),
			);
		}

		$raw = self::raw_token( $source );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'content' => self::nest( $location, $raw ),
			'spot'    => array(
				'location' => $location,
				'dotted'   => $dotted,
			),
			'extra'   => array( 'field' => $field ),
		);
	}

	/**
	 * Token en bruto con la forma que escribe el Visual Builder a partir de `{name, settings?}`.
	 *
	 * @param mixed $source Valor recibido.
	 *
	 * @return string|\WP_Error
	 */
	private static function raw_token( $source ) {
		$keys     = is_array( $source ) ? array_map( 'strval', array_keys( $source ) ) : array();
		$settings = is_array( $source ) ? ( $source['settings'] ?? array() ) : array();

		if ( ! is_array( $source ) || array_is_list( $source ) || ! is_string( $source['name'] ?? null ) || array() !== array_diff( $keys, array( 'name', 'settings' ) ) || ! is_array( $settings ) || ( array() !== $settings && array_is_list( $settings ) ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_dynamic_invalid',
				__( 'source: debe ser un objeto {name, settings?} con «name» de la lista de nexo-mcp-list-divi-dynamic-sources y «settings» un objeto, o null para quitar el token.', 'nexo-mcp' ),
				array( 'path' => 'source' )
			);
		}

		$json = wp_json_encode(
			array(
				'type'  => 'content',
				'value' => array(
					'name'     => $source['name'],
					'settings' => array() === $settings ? new \stdClass() : $settings,
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $json )
			? '$variable(' . $json . ')$'
			: new \WP_Error( 'nexo_mcp_divi_dynamic_invalid', __( 'source: contiene datos que no se pueden codificar como JSON.', 'nexo-mcp' ), array( 'path' => 'source' ) );
	}

	/**
	 * Anida un valor en la ruta dada: `nest(['a','b'], 1)` → `['a' => ['b' => 1]]`.
	 *
	 * @param array<int, string> $location Claves.
	 * @param mixed              $value    Valor de la hoja.
	 *
	 * @return array<string, mixed>
	 */
	private static function nest( array $location, $value ): array {
		$key = array_shift( $location );

		return array( (string) $key => array() === $location ? $value : self::nest( $location, $value ) );
	}

	/**
	 * Valor en una ruta de claves, o null.
	 *
	 * @param mixed              $data     Datos.
	 * @param array<int, string> $location Claves.
	 *
	 * @return mixed
	 */
	private static function value_at( $data, array $location ) {
		foreach ( $location as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}

			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * Error del reescaneo final: un token que no es de los permitidos.
	 *
	 * @param string $path Ruta de atributos (`title.innerContent.desktop.value`).
	 */
	private function stray( string $path ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_dynamic_denied',
			$path . ': ' . DiviTreeValidator::dynamic_reason(),
			array( 'path' => $path )
		);
	}

	/**
	 * Fusiona en profundidad: los objetos se recorren, lo demás se reemplaza.
	 *
	 * @param array<mixed, mixed> $base  Atributos actuales.
	 * @param array<mixed, mixed> $patch Parche validado.
	 *
	 * @return array<mixed, mixed>
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && array() !== $value && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
