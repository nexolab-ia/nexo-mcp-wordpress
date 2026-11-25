<?php
/**
 * Herramientas de plantillas del Theme Builder de Divi 5: listar, crear o reemplazar y borrar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lógica de `list-divi-theme-builder`, `set-divi-template` y `delete-divi-template` (T6 de
 * `odd/tasks/divi-theme-builder.md`; decisiones S5, S6 y S7).
 *
 * Una plantilla decide qué ve TODO el sitio y Divi elige UNA sola por petición. El orden es siempre: permiso del Theme
 * Builder ({@see PermissionPolicy::can_write_divi_theme_builder()}, ANTES de mirar la entrada), Divi 5 activo, forma de la
 * entrada y, dentro del bloqueo único del Theme Builder ({@see DiviTemplateStore::locked()}), las reglas del esquema
 * cerrado ({@see DiviThemeBuilderSchema}), las de «ajena» y «sitio entero», el respaldo y la escritura.
 */
final class DiviTemplateEditor {

	public const CODE_DENIED = 'nexo_mcp_divi_template_denied';

	/**
	 * Esquema de plantillas.
	 *
	 * @var DiviThemeBuilderSchema
	 */
	private DiviThemeBuilderSchema $schema;

	/**
	 * Almacén de plantillas.
	 *
	 * @var DiviTemplateStore
	 */
	private DiviTemplateStore $store;

	/**
	 * Permiso del Theme Builder: fn(): bool.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * El layout lleva `divi/post-content`: fn( int ): bool.
	 *
	 * @var callable
	 */
	private $has_post_content;

	/**
	 * Construye el editor.
	 *
	 * @param DiviThemeBuilderSchema $schema           Esquema.
	 * @param DiviTemplateStore      $store            Almacén.
	 * @param callable               $allowed          fn(): bool. `PermissionPolicy::can_write_divi_theme_builder()`.
	 * @param callable|null          $divi5_active     fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null          $has_post_content fn( int ): bool. Por omisión, {@see DiviThemeBuilderSchema::layout_has_post_content()}.
	 */
	public function __construct( DiviThemeBuilderSchema $schema, DiviTemplateStore $store, callable $allowed, ?callable $divi5_active = null, ?callable $has_post_content = null ) {
		$this->schema           = $schema;
		$this->store            = $store;
		$this->allowed          = $allowed;
		$this->divi5_active     = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->has_post_content = $has_post_content ?? array( DiviThemeBuilderSchema::class, 'layout_has_post_content' );
	}

	/**
	 * Lista las plantillas del Theme Builder en vivo con todo lo que hace falta para decidir un cambio.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_templates() {
		$gate = $this->gate();

		if ( null !== $gate ) {
			return $gate;
		}

		$live = $this->store->live_id();
		$seen = $this->inspect( $live );
		$view = $this->view( $seen['items'] );

		return array(
			'live'             => array(
				'id'     => $live > 0 ? $live : null,
				'exists' => $live > 0,
			),
			'draft'            => $this->draft( $this->store->draft_id() ),
			'templates'        => $seen['items'],
			'default_template' => $view['default_template'],
			'contexts'         => $view['contexts'],
			'shadows'          => $view['shadows'],
			'catalog'          => $this->schema->catalog(),
			'limits'           => array(
				'max_templates' => DiviTemplateStore::MAX_TEMPLATES,
				'max_contexts'  => DiviThemeBuilderSchema::MAX_CONTEXTS,
				'max_title'     => DiviThemeBuilderSchema::MAX_TITLE,
			),
			'warnings'         => $seen['warnings'],
		);
	}

	/**
	 * Crea o REEMPLAZA una plantilla del Theme Builder en vivo.
	 *
	 * Reemplazar es reemplazar: lo que no se pasa vuelve a su valor por defecto (listas vacías, ranuras sin layout propio,
	 * `default` false), por eso `enabled` es obligatorio. Una plantilla nueva nace SIEMPRE con `enabled: false` y se activa con
	 * una segunda llamada.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_template( array $input ) {
		$gate = $this->gate();

		if ( null !== $gate ) {
			return $gate;
		}

		$id = $this->target_id( $input, false );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$flag = $this->flag( $input, 'replace_foreign' );

		if ( $flag instanceof \WP_Error ) {
			return $flag;
		}

		if ( ! array_key_exists( 'enabled', $input ) ) {
			return $this->invalid( 'enabled', __( 'es obligatorio (true o false): set-divi-template reemplaza la plantilla entera y una plantilla nueva nace deshabilitada.', 'nexo-mcp' ) );
		}

		$template = array_diff_key(
			$input,
			array(
				'id'              => true,
				'replace_foreign' => true,
			)
		);

		return $this->store->locked( fn() => $this->set_locked( $id, $template, $flag ) );
	}

	/**
	 * Saca una plantilla del Theme Builder en vivo y la manda a la papelera (como Divi), con respaldo.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `id`, `confirm_site_wide`, `replace_foreign`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_template( array $input ) {
		$gate = $this->gate();

		if ( null !== $gate ) {
			return $gate;
		}

		$id = $this->target_id( $input, true );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$confirm = $this->flag( $input, 'confirm_site_wide' );

		if ( $confirm instanceof \WP_Error ) {
			return $confirm;
		}

		$foreign = $this->flag( $input, 'replace_foreign' );

		if ( $foreign instanceof \WP_Error ) {
			return $foreign;
		}

		return $this->store->locked( fn() => $this->delete_locked( (int) $id, $confirm, $foreign ) );
	}

	/**
	 * El id de la plantilla objetivo: entero positivo, opcional o no.
	 *
	 * @param array<string, mixed> $input    Entrada.
	 * @param bool                 $required Si es obligatorio.
	 *
	 * @return int|null|\WP_Error
	 */
	private function target_id( array $input, bool $required ) {
		if ( ! array_key_exists( 'id', $input ) ) {
			return $required ? $this->invalid( 'id', __( 'es obligatorio: el id de la plantilla (de nexo-mcp-list-divi-theme-builder).', 'nexo-mcp' ) ) : null;
		}

		if ( ! is_int( $input['id'] ) || $input['id'] < 1 ) {
			return $this->invalid( 'id', __( 'debe ser el id entero de una plantilla del Theme Builder en vivo.', 'nexo-mcp' ) );
		}

		return $input['id'];
	}

	/**
	 * Un booleano opcional de la entrada (por omisión false).
	 *
	 * @param array<string, mixed> $input Entrada.
	 * @param string               $key   Clave.
	 *
	 * @return bool|\WP_Error
	 */
	private function flag( array $input, string $key ) {
		$value = $input[ $key ] ?? false;

		return is_bool( $value ) ? $value : $this->invalid( $key, __( 'debe ser un booleano (true o false).', 'nexo-mcp' ) );
	}

	/**
	 * Valida y escribe, con el bloqueo del Theme Builder en mano.
	 *
	 * @param int|null             $id              Plantilla a reemplazar, o null para crear una.
	 * @param array<string, mixed> $template        Plantilla del cliente (sin `id` ni `replace_foreign`).
	 * @param bool                 $replace_foreign Si confirmó reemplazar una plantilla ajena.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set_locked( ?int $id, array $template, bool $replace_foreign ) {
		$params = $this->schema->validate( $template );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$tb       = $this->store->live_id();
		$mapping  = $this->store->mapping( $tb );
		$replaced = null;

		if ( null !== $id ) {
			$found = $this->live_template( $id, $tb, $mapping );

			if ( $found instanceof \WP_Error ) {
				return $found;
			}

			$before = $this->schema->summary( $found['metas'], $found['post']->post_title );

			if ( ! $before['ours'] ) {
				if ( ! $replace_foreign ) {
					return $this->foreign( $id, $before );
				}

				$replaced = $before;
			}

			if ( $before['site_wide'] && true !== $params['confirm_site_wide'] ) {
				return new \WP_Error(
					DiviThemeBuilderSchema::CODE_SITE_WIDE,
					'confirm_site_wide: ' . __( 'esta plantilla ya cambia todo el sitio (es la por defecto o se asigna a un …:all, archive:all o 404): cambiarla o quitarle eso también lo cambia. Repite la llamada con confirm_site_wide: true si es lo que quieres.', 'nexo-mcp' ),
					array( 'path' => 'confirm_site_wide' )
				);
			}
		} elseif ( count( $mapping ) >= DiviTemplateStore::MAX_TEMPLATES ) {
			return new \WP_Error(
				'nexo_mcp_divi_template_too_many',
				sprintf(
					/* translators: %d: máximo. */
					__( 'El Theme Builder ya tiene %d plantillas, el máximo que el conector administra. Borra una con nexo-mcp-delete-divi-template o reemplaza una existente con su id.', 'nexo-mcp' ),
					DiviTemplateStore::MAX_TEMPLATES
				),
				array( 'max' => DiviTemplateStore::MAX_TEMPLATES )
			);
		}

		if ( true === $params['default'] ) {
			$other = $this->other_default( $mapping, $id );

			if ( null !== $other ) {
				return new \WP_Error(
					'nexo_mcp_divi_template_default_exists',
					sprintf(
						/* translators: %d: id de la plantilla por defecto. */
						__( 'Ya hay una plantilla por defecto (id %d): sólo puede haber una. Reemplázala con su id o quítale default antes.', 'nexo-mcp' ),
						$other
					),
					array( 'id' => $other )
				);
			}
		}

		if ( null === $id ) {
			// Una plantilla nueva nace deshabilitada y se activa con una segunda llamada (S7).
			$params['enabled'] = false;

			return $this->create_locked( $params, $this->schema->stored( $params ) );
		}

		return $this->replace_locked( $id, $tb, $params, $this->schema->stored( $params ), $replaced );
	}

	/**
	 * Crea la plantilla y la enlaza al Theme Builder en vivo (que se crea si falta).
	 *
	 * @param array<string, mixed>                     $params Plantilla validada.
	 * @param array<string, string|array<int, string>> $stored Metas a escribir.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_locked( array $params, array $stored ) {
		$tb = $this->store->ensure_live_id();

		if ( $tb <= 0 ) {
			return new \WP_Error(
				'nexo_mcp_divi_template_unavailable',
				__( 'No hay un Theme Builder en vivo y Divi no pudo crearlo (¿está activo el Theme Builder de Divi?).', 'nexo-mcp' )
			);
		}

		$id = $this->store->insert( $params['title'] );

		if ( is_wp_error( $id ) ) {
			return $this->failed( $id->get_error_message() );
		}

		$undo = function () use ( $tb, $id ): void {
			$this->store->unmap( $tb, $id );
			$this->store->discard( $id );
		};

		$snapshot = $this->store->snapshot( $id, $tb, 'create', false );

		if ( ! $this->store->save_backup( $id, $snapshot ) ) {
			$undo();

			return $this->backup_failed();
		}

		$held = $this->store->verify_lock();

		if ( is_wp_error( $held ) ) {
			$undo();

			return $held;
		}

		$this->store->write( $id, $stored );

		if ( ! $this->store->map( $tb, $id ) || ! $this->matches( $id, $params ) ) {
			$undo();

			return $this->failed( __( 'Lo guardado no coincide con lo pedido.', 'nexo-mcp' ) );
		}

		return $this->set_response( $id, $tb, $params, true, $snapshot, null, null );
	}

	/**
	 * Reemplaza una plantilla del Theme Builder en vivo; si algo falla vuelve a su estado anterior.
	 *
	 * @param int                                      $id       Plantilla.
	 * @param int                                      $tb       Theme Builder en vivo.
	 * @param array<string, mixed>                     $params   Plantilla validada.
	 * @param array<string, string|array<int, string>> $stored   Metas a escribir.
	 * @param array<string, mixed>|null                $replaced Resumen de la plantilla ajena que se reemplaza.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function replace_locked( int $id, int $tb, array $params, array $stored, ?array $replaced ) {
		$snapshot = $this->store->snapshot( $id, $tb, 'replace', true );

		if ( ! $this->store->save_backup( $id, $snapshot ) ) {
			return $this->backup_failed();
		}

		$held = $this->store->verify_lock();

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		if ( (string) ( $snapshot['title'] ?? '' ) !== $params['title'] ) {
			$renamed = $this->store->rename( $id, $params['title'] );

			if ( is_wp_error( $renamed ) ) {
				return $this->undone( $id, $tb, $renamed->get_error_message() );
			}
		}

		$this->store->write( $id, $stored );

		if ( ! $this->store->map( $tb, $id ) || ! $this->matches( $id, $params ) ) {
			return $this->undone( $id, $tb, __( 'Lo guardado no coincide con lo pedido.', 'nexo-mcp' ) );
		}

		return $this->set_response( $id, $tb, $params, false, $snapshot, $replaced, $this->schema->summary( $snapshot['metas'], (string) $snapshot['title'] ) );
	}

	/**
	 * Saca la plantilla del mapeo y la manda a la papelera, bajo el bloqueo del Theme Builder.
	 *
	 * @param int  $id      Plantilla.
	 * @param bool $confirm `confirm_site_wide`.
	 * @param bool $foreign `replace_foreign`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function delete_locked( int $id, bool $confirm, bool $foreign ) {
		$tb    = $this->store->live_id();
		$found = $this->live_template( $id, $tb, $this->store->mapping( $tb ) );

		if ( $found instanceof \WP_Error ) {
			return $found;
		}

		$before = $this->schema->summary( $found['metas'], $found['post']->post_title );

		if ( ! $before['ours'] && ! $foreign ) {
			return $this->foreign( $id, $before );
		}

		if ( $before['site_wide'] && ! $confirm ) {
			return new \WP_Error(
				DiviThemeBuilderSchema::CODE_SITE_WIDE,
				'confirm_site_wide: ' . __( 'esta plantilla es la por defecto o se asigna a un …:all, archive:all o 404: quitarla cambia todo el sitio. Repite la llamada con confirm_site_wide: true si es lo que quieres.', 'nexo-mcp' ),
				array( 'path' => 'confirm_site_wide' )
			);
		}

		$snapshot = $this->store->snapshot( $id, $tb, 'delete', true );

		if ( ! $this->store->save_backup( $id, $snapshot ) ) {
			return $this->backup_failed();
		}

		$held = $this->store->verify_lock();

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		$this->store->unmap( $tb, $id );

		if ( ! $this->store->trash( $id ) ) {
			return $this->undone( $id, $tb, __( 'WordPress no pudo enviar la plantilla a la papelera.', 'nexo-mcp' ) );
		}

		$cache    = $this->store->clear_caches();
		$layouts  = array_values( array_filter( array_column( array( $before['header'], $before['body'], $before['footer'] ), 'layout_id' ), 'is_int' ) );
		$warnings = $cache['warnings'];

		if ( array() !== $layouts ) {
			$warnings[] = __( 'Los layouts de la plantilla siguen existiendo, pero ya no están en ninguna plantilla en vivo: el próximo guardado en vivo de Divi los marca como sin uso y a los 7 días los manda a la papelera.', 'nexo-mcp' );
		}

		return array(
			'id'                 => $id,
			'deleted'            => true,
			'status'             => (string) get_post_status( $id ),
			'title'              => $before['title'],
			'was_default'        => $before['default'],
			'site_wide'          => $before['site_wide'],
			'layouts'            => $layouts,
			'live_theme_builder' => $tb,
			'previous'           => $before,
			'backup'             => $this->backup_info( $snapshot ),
			'cache'              => array(
				'css'        => $cache['css'],
				'page_cache' => $cache['page_cache'],
			),
			'warnings'           => $warnings,
		);
	}

	/**
	 * Una plantilla del mapeo en vivo que se puede cambiar: existe, es un `et_template`, no está en la papelera y está en el
	 * Theme Builder en vivo.
	 *
	 * @param int             $id      Plantilla.
	 * @param int             $tb      Theme Builder en vivo.
	 * @param array<int, int> $mapping Mapeo en vivo.
	 *
	 * @return array{post: \WP_Post, metas: array<string, string|array<int, string>>}|\WP_Error
	 */
	private function live_template( int $id, int $tb, array $mapping ) {
		$post = $this->store->template( $id );

		if ( null === $post || 'trash' === $post->post_status || $tb <= 0 || ! in_array( $id, $mapping, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_template_not_found',
				__( 'No hay una plantilla con ese id en el Theme Builder en vivo (vuelve a leer con nexo-mcp-list-divi-theme-builder). Para crear una nueva, no pases id.', 'nexo-mcp' ),
				array( 'id' => $id )
			);
		}

		return array(
			'post'  => $post,
			'metas' => $this->store->metas( $id ),
		);
	}

	/**
	 * Error de una plantilla ajena sin `replace_foreign`.
	 *
	 * @param int                  $id      Plantilla.
	 * @param array<string, mixed> $summary Resumen de lo que hay.
	 */
	private function foreign( int $id, array $summary ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_template_foreign',
			__( 'Esta plantilla no es del conector (la hizo el Visual Builder o tiene ajustes que el conector no representa): reemplazarla o borrarla pierde lo que tiene. El resumen de lo que hay va en data.summary. Repite con replace_foreign: true para confirmar; se guarda un respaldo.', 'nexo-mcp' ),
			array(
				'id'      => $id,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Id de otra plantilla por defecto habilitada o no (que no esté en la papelera), o null.
	 *
	 * @param array<int, int> $mapping Mapeo en vivo.
	 * @param int|null        $except  Plantilla que se está escribiendo.
	 */
	private function other_default( array $mapping, ?int $except ): ?int {
		foreach ( $mapping as $other ) {
			$post = $this->store->template( $other );

			if ( $other === $except || null === $post || 'trash' === $post->post_status ) {
				continue;
			}

			if ( '1' === ( $this->store->metas( $other )['_et_default'] ?? null ) ) {
				return $other;
			}
		}

		return null;
	}

	/**
	 * Si lo guardado se lee igual que lo pedido (no se fía de la escritura: las metas de Divi tienen trampas).
	 *
	 * @param int                  $id     Plantilla.
	 * @param array<string, mixed> $params Plantilla validada.
	 */
	private function matches( int $id, array $params ): bool {
		$post = get_post( $id );
		$got  = $this->schema->valid_stored( $this->store->metas( $id ), $post instanceof \WP_Post ? $post->post_title : '' );

		if ( null === $got ) {
			return false;
		}

		foreach ( array( 'enabled', 'default', 'use_on', 'exclude_from', 'header', 'body', 'footer' ) as $key ) {
			if ( $got[ $key ] !== $params[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Algo falló después de escribir: se vuelve al estado respaldado y se dice.
	 *
	 * @param int    $id     Plantilla.
	 * @param int    $tb     Theme Builder en vivo.
	 * @param string $reason Motivo.
	 */
	private function undone( int $id, int $tb, string $reason ): \WP_Error {
		$restored = $this->store->restore( $id, $tb );

		return $this->failed(
			$reason . ' ' . ( true === $restored
				? __( 'La plantilla volvió a su estado anterior.', 'nexo-mcp' )
				: __( 'No se pudo volver al estado anterior automáticamente: restáurala con nexo-mcp-restore-builder-backup y su id.', 'nexo-mcp' ) )
		);
	}

	/**
	 * Respuesta de set-divi-template tras guardar y borrar las cachés.
	 *
	 * @param int                       $id       Plantilla.
	 * @param int                       $tb       Theme Builder en vivo.
	 * @param array<string, mixed>      $params   Lo guardado.
	 * @param bool                      $created  Si es nueva.
	 * @param array<string, mixed>      $snapshot Respaldo.
	 * @param array<string, mixed>|null $replaced Resumen de la ajena reemplazada.
	 * @param array<string, mixed>|null $previous Resumen de lo que había antes (null al crear).
	 *
	 * @return array<string, mixed>
	 */
	private function set_response( int $id, int $tb, array $params, bool $created, array $snapshot, ?array $replaced, ?array $previous ): array {
		$cache    = $this->store->clear_caches();
		$seen     = $this->inspect( $tb );
		$view     = $this->view( $seen['items'] );
		$wide     = $this->schema->summary( $this->store->metas( $id ), $params['title'] )['site_wide'];
		$warnings = array();

		if ( $created ) {
			$warnings[] = sprintf(
				/* translators: %d: id de la plantilla. */
				__( 'La plantilla se creó DESHABILITADA (enabled: false) y todavía no cambia nada en el sitio. Revísala con nexo-mcp-list-divi-theme-builder y actívala con una segunda llamada a set-divi-template con id: %d y enabled: true.', 'nexo-mcp' ),
				$id
			);
		}

		$out = array(
			'id'                 => $id,
			'created'            => $created,
			'title'              => $params['title'],
			'enabled'            => $params['enabled'],
			'default'            => $params['default'],
			'site_wide'          => $wide,
			'effect'             => $wide ? $this->effect( $params['enabled'] ) : null,
			'ours'               => true,
			'use_on'             => $params['use_on'],
			'exclude_from'       => $params['exclude_from'],
			'header'             => $params['header'],
			'body'               => $params['body'],
			'footer'             => $params['footer'],
			'shadowed_by'        => array_values( array_filter( $view['shadows'], static fn( array $row ): bool => $id === $row['template'] ) ),
			'shadows'            => array_values( array_filter( $view['shadows'], static fn( array $row ): bool => $id === $row['covered_by'] ) ),
			'live_theme_builder' => $tb,
			'previous'           => $previous,
			'backup'             => $this->backup_info( $snapshot ),
			'cache'              => array(
				'css'        => $cache['css'],
				'page_cache' => $cache['page_cache'],
			),
			'warnings'           => array_merge( $warnings, $cache['warnings'] ),
		);

		if ( $created ) {
			$out['needs_enable'] = true;
		}

		if ( null !== $replaced ) {
			$out['replaced'] = $replaced;
		}

		return $out;
	}

	/**
	 * Texto del efecto de una plantilla de sitio entero.
	 *
	 * @param bool $enabled Si está habilitada.
	 */
	private function effect( bool $enabled ): string {
		return $enabled
			? __( 'Cambia lo que ve todo el sitio: afecta a todas las páginas y entradas (y a lo que cubran sus contextos) que no tengan una plantilla más específica. Revisa shadowed_by.', 'nexo-mcp' )
			: __( 'Cuando se habilite cambiará lo que ve todo el sitio: afectará a todas las páginas y entradas (y a lo que cubran sus contextos) que no tengan una plantilla más específica. Ahora está deshabilitada y no afecta a nada.', 'nexo-mcp' );
	}

	/**
	 * Datos del respaldo para la salida.
	 *
	 * @param array<string, mixed> $snapshot Respaldo.
	 *
	 * @return array{operation: string, captured_at: string, existed: bool}
	 */
	private function backup_info( array $snapshot ): array {
		return array(
			'operation'   => (string) $snapshot['operation'],
			'captured_at' => (string) $snapshot['captured_at'],
			'existed'     => true === $snapshot['existed'],
		);
	}

	/**
	 * Error de entrada propio del editor.
	 *
	 * @param string $path   Campo.
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return new \WP_Error(
			DiviThemeBuilderSchema::CODE_INVALID,
			$path . ': ' . $reason,
			array( 'path' => $path )
		);
	}

	/**
	 * No se pudo guardar.
	 *
	 * @param string $reason Motivo.
	 */
	private function failed( string $reason ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_template_save_failed',
			sprintf(
				/* translators: %s: motivo. */
				__( 'No se pudo guardar la plantilla del Theme Builder: %s', 'nexo-mcp' ),
				$reason
			)
		);
	}

	/**
	 * No se pudo guardar el respaldo: no se escribe nada sin él.
	 */
	private function backup_failed(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_template_backup_failed',
			__( 'No se pudo guardar el respaldo de la plantilla y no se cambió nada: sin respaldo no se escribe.', 'nexo-mcp' )
		);
	}

	/**
	 * Permiso y Divi 5: lo primero que se comprueba en toda herramienta.
	 *
	 * @return \WP_Error|null
	 */
	private function gate(): ?\WP_Error {
		if ( ! ( $this->allowed )() ) {
			return self::denied();
		}

		return ( $this->divi5_active )() ? null : Divi5ContentWriter::required_error();
	}

	/**
	 * Error de las herramientas de plantillas sin el permiso del Theme Builder.
	 */
	public static function denied(): \WP_Error {
		return new \WP_Error(
			self::CODE_DENIED,
			__( 'Las plantillas del Theme Builder de Divi deciden lo que ve todo el sitio: sólo se leen y se cambian con el permiso del Theme Builder (scope mcp:divi_theme_builder con su interruptor encendido y un administrador con el permiso theme_builder de Divi), no con el permiso de una página.', 'nexo-mcp' )
		);
	}

	/**
	 * El borrador del Visual Builder, si lo hay.
	 *
	 * @param int $draft Id del borrador (0 si no hay).
	 *
	 * @return array<string, mixed>
	 */
	private function draft( int $draft ): array {
		if ( $draft <= 0 ) {
			return array(
				'exists' => false,
				'id'     => null,
			);
		}

		return array(
			'exists' => true,
			'id'     => $draft,
			'note'   => sprintf(
				/* translators: %d: id del borrador. */
				__( 'Hay un borrador del Theme Builder hecho en el Visual Builder (auto-draft, id %d). El conector lo ignora: sólo lee y escribe el Theme Builder en vivo. WordPress barre los auto-draft a los 7 días. Si alguien tiene el Visual Builder abierto y guarda en vivo con el estado viejo, Divi reescribe el mapeo entero y pisa lo que se hizo aquí.', 'nexo-mcp' ),
				$draft
			),
		);
	}

	/**
	 * Recorre el mapeo del Theme Builder en vivo: cada plantilla resumida, con sus layouts, y los avisos.
	 *
	 * @param int $tb Theme Builder en vivo (0 si no hay).
	 *
	 * @return array{items: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
	 */
	private function inspect( int $tb ): array {
		$items    = array();
		$warnings = array();
		$defaults = array();

		foreach ( $this->store->mapping( $tb ) as $id ) {
			$post = $this->store->template( $id );

			if ( null === $post ) {
				$warnings[] = $this->warning( 'orphan_mapping', __( 'El mapeo del Theme Builder tiene una fila que no apunta a una plantilla: Divi la ignora.', 'nexo-mcp' ), array( 'template_id' => $id ) );
				continue;
			}

			$item = array_merge(
				array(
					'id'     => $id,
					'status' => (string) $post->post_status,
				),
				$this->schema->summary( $this->store->metas( $id ), $post->post_title )
			);

			if ( 'trash' === $item['status'] ) {
				$warnings[] = $this->warning( 'template_trashed', __( 'La plantilla está en la papelera pero sigue en el mapeo: Divi no la usa.', 'nexo-mcp' ), array( 'template_id' => $id ) );
			}

			if ( $this->store->is_marked_unused( $id ) ) {
				$warnings[] = $this->warning( 'scheduled_for_trash', __( 'Divi marcó esta plantilla como sin uso: pasados 7 días la manda a la papelera.', 'nexo-mcp' ), array( 'template_id' => $id ) );
			}

			if ( $item['default'] ) {
				$defaults[] = $id;
			}

			$replaces = $item['default'] || $this->content_context( $item['use_on'] );

			foreach ( array( 'header', 'body', 'footer' ) as $slot ) {
				$item[ $slot ] = $this->slot( $id, $slot, $item, $replaces, $warnings );
			}

			$items[] = $item;
		}

		if ( count( $defaults ) > 1 ) {
			$warnings[] = $this->warning( 'multiple_defaults', __( 'Hay más de una plantilla por defecto: sólo una vale (Divi elige la de menor id). Deja una.', 'nexo-mcp' ), array( 'template_ids' => $defaults ) );
		}

		return array(
			'items'    => $items,
			'warnings' => $warnings,
		);
	}

	/**
	 * Una ranura de la plantilla con el estado de su layout; añade a `$warnings` lo que no cuadra.
	 *
	 * @param int                              $id       Plantilla.
	 * @param string                           $slot     `header`, `body` o `footer`.
	 * @param array<string, mixed>             $item     Resumen de la plantilla.
	 * @param bool                             $replaces Si la plantilla ocupa el lugar del contenido (por defecto o contexto de contenido).
	 * @param array<int, array<string, mixed>> $warnings Avisos.
	 *
	 * @return array<string, mixed>
	 */
	private function slot( int $id, string $slot, array $item, bool $replaces, array &$warnings ): array {
		$out    = $item[ $slot ];
		$layout = $out['layout_id'];

		if ( 'body' === $slot ) {
			$out['has_post_content'] = false;
		}

		if ( null === $layout ) {
			return $out;
		}

		$post = get_post( $layout );

		if ( ! $post instanceof \WP_Post ) {
			$out['status'] = 'missing';
		} elseif ( DiviLayoutGuard::LAYOUT_TYPES[ $slot ] !== $post->post_type ) {
			$out['status'] = 'wrong_type';
		} else {
			$out['status'] = (string) $post->post_status;
		}

		$at = array(
			'template_id' => $id,
			'layout_id'   => $layout,
			'slot'        => $slot,
		);

		if ( in_array( $out['status'], array( 'missing', 'wrong_type' ), true ) ) {
			$warnings[] = $this->warning(
				'layout_invalid',
				sprintf(
					/* translators: 1: id, 2: ranura. */
					__( 'El layout %1$d no existe o no es un layout de tipo %2$s: Divi lo ignora y esa área sale como si no hubiera layout.', 'nexo-mcp' ),
					$layout,
					$slot
				),
				$at
			);

			return $out;
		}

		if ( 'publish' !== $out['status'] ) {
			$warnings[] = $this->warning(
				'layout_not_published',
				sprintf(
					/* translators: 1: id, 2: estado. */
					__( 'El layout %1$d está en estado %2$s: Divi muestra igualmente un layout que no es publish (salvo trash) y lo ve el público. Publícalo o quítalo de la plantilla.', 'nexo-mcp' ),
					$layout,
					$out['status']
				),
				$at
			);
		}

		if ( $this->store->is_marked_unused( $layout ) ) {
			$warnings[] = $this->warning( 'scheduled_for_trash', __( 'Divi marcó este layout como sin uso: pasados 7 días lo manda a la papelera.', 'nexo-mcp' ), $at );
		}

		if ( 'body' === $slot ) {
			$out['has_post_content'] = true === ( $this->has_post_content )( $layout );

			if ( ! $out['has_post_content'] && $replaces && true === $item['enabled'] && 'publish' === $item['status'] && $out['enabled'] ) {
				$warnings[] = $this->warning( 'body_replaces_content', __( 'El cuerpo no lleva divi/post-content: REEMPLAZA el contenido de las páginas, entradas y pantallas de WooCommerce a las que se asigna (el carrito, por ejemplo, dejaría de verse).', 'nexo-mcp' ), $at );
			}
		}

		return $out;
	}

	/**
	 * Si algún contexto de la plantilla ocupa el lugar del contenido (inicio, singulares y WooCommerce).
	 *
	 * @param array<int, array{id: string|null, supported: bool, priority: int|null}> $contexts Contextos resumidos.
	 */
	private function content_context( array $contexts ): bool {
		foreach ( $contexts as $context ) {
			$id = $context['id'];

			if ( is_string( $id ) && ( 'homepage' === $id || str_starts_with( $id, 'singular:' ) || str_starts_with( $id, 'woocommerce:' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Quién gana y qué tapa a cuál, a partir de las plantillas resumidas.
	 *
	 * @param array<int, array<string, mixed>> $items Plantillas resumidas ({@see self::inspect()}).
	 *
	 * @return array{default_template: int|null, contexts: array<int, array<string, mixed>>, shadows: array<int, array<string, mixed>>}
	 */
	private function view( array $items ): array {
		$list    = array_map(
			static fn( array $item ): array => array(
				'key'          => $item['id'],
				'enabled'      => true === $item['enabled'] && 'publish' === $item['status'],
				'default'      => $item['default'],
				'use_on'       => array_values( array_filter( array_column( $item['use_on'], 'id' ), 'is_string' ) ),
				'exclude_from' => array_values( array_filter( array_column( $item['exclude_from'], 'id' ), 'is_string' ) ),
			),
			$items
		);
		$default = $this->schema->winner( $list, array() );

		return array(
			'default_template' => null !== $default && 'default' === $default['via'] ? (int) $default['key'] : null,
			'contexts'         => $this->contexts( $items, $list ),
			'shadows'          => $this->schema->shadows( $list ),
		);
	}

	/**
	 * Cada contexto del catálogo en uso, con la plantilla que gana si la petición sólo cumple ése.
	 *
	 * @param array<int, array<string, mixed>> $items Plantillas resumidas.
	 * @param array<int, array<string, mixed>> $templates Lista de {@see DiviThemeBuilderSchema::winner()}.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contexts( array $items, array $templates ): array {
		$used = array();

		foreach ( $items as $item ) {
			foreach ( $item['use_on'] as $context ) {
				$id = $context['id'];

				if ( is_string( $id ) && null !== $context['priority'] ) {
					$used[ $id ]['priority']    = $context['priority'];
					$used[ $id ]['templates'][] = $item['id'];
				}
			}
		}

		$out = array();

		foreach ( $used as $context => $data ) {
			$winner = $this->schema->winner( $templates, array( $context ) );
			$out[]  = array(
				'context'   => $context,
				'priority'  => $data['priority'],
				'winner'    => null === $winner ? null : (int) $winner['key'],
				'via'       => null === $winner ? null : $winner['via'],
				'templates' => array_values( array_unique( $data['templates'] ) ),
			);
		}

		usort( $out, static fn( array $a, array $b ): int => array( $b['priority'], $a['context'] ) <=> array( $a['priority'], $b['context'] ) );

		return $out;
	}

	/**
	 * Un aviso de la salida.
	 *
	 * @param string               $code    Código corto.
	 * @param string               $message Texto.
	 * @param array<string, mixed> $at      Dónde (plantilla, layout, ranura).
	 *
	 * @return array<string, mixed>
	 */
	private function warning( string $code, string $message, array $at ): array {
		return array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$at
		);
	}
}
