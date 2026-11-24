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
	 * Construye el editor.
	 *
	 * @param Settings          $settings     Configuración.
	 * @param Divi5Adapter      $adapter      Adaptador de Divi 5.
	 * @param BuilderBackup     $backup       Respaldos.
	 * @param WorkingCopy       $copies       Copias de trabajo.
	 * @param DiviTreeValidator $validator    Validador del parche.
	 * @param callable          $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null     $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null     $saver        fn( int, string ): true|\WP_Error. Por omisión, `wp_update_post()` (sólo pruebas).
	 * @param callable|null     $presets_lock fn( callable ): mixed. Bloqueo de presets; por omisión, el de {@see DiviDesignStore::LOCK_ID}.
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
		?callable $presets_lock = null
	) {
		$this->writer       = new Divi5ContentWriter( $settings, $adapter, $backup, $copies, $lock_check, $divi5_active, $saver );
		$this->validator    = $validator;
		$this->presets_lock = $presets_lock ?? static fn( callable $work ) => PostLock::run( DiviDesignStore::LOCK_ID, $work );
	}

	/**
	 * Edita el módulo.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `path`, `fingerprint`, `content`, `design`, `preset`, `dynamic` (`{field, source}`), `draft`.
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
			$clean = $removes ? array( 'content' => $patch['content'] ) : $this->validator->validate_patch( $type, $patch );

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
