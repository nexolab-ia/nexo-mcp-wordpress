<?php
/**
 * Esquema cerrado del bucle de consulta de Divi 5 que el conector deja escribir (`module.advanced.loop`).
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decisión S7 de `odd/tasks/divi-dinamico.md`: «Un solo tipo de contenido público (entradas, páginas, productos…), sólo
 * publicado y sin contraseña, máximo 24 por bucle, sin anidamiento, sin metaQuery ni búsqueda; orden por
 * fecha/título/id, desplazamiento, excluir actual, categorías/etiquetas».
 *
 * Divi 5 guarda el bucle en `module.advanced.loop.desktop.value` (ver `LoopUtils::get_query_args_from_attrs`). Aquí se
 * arma SÓLO con claves nuestras, nunca con las del cliente:
 *
 * - `enable: on`, `queryType: post_types` y `subTypes: [{value: <tipo>, label}]` (un solo tipo público, sin
 *   `attachment` ni `any`: con adjuntos o sin tipo Divi consulta también `private`/`inherit`).
 * - `orderBy` (`date` | `title` | `ID`), `order` (`ascending` | `descending`), `postPerPage` (1-24, texto),
 *   `postOffset` (0-1000, texto) y `excludeCurrentPost` (`on` | `off`).
 * - `ignoreStickysPost: on`: Divi antepone entradas fijas fuera del tope; con `on` salen exactamente `postPerPage`.
 * - `includePostWithSpecificTerms`: `[{categoryId: <taxonomía>, selectedOptions: [{value: <id>, label}]}]` (sólo si hay términos).
 * - `loopId`: `loop-nx` + 8 caracteres `[0-9a-z]` ({@see self::ID_PATTERN}). Divi lo usa para la paginación por URL
 *   (`?<loopId>=N`) y la guarda {@see DiviLoopGuard} lo reconoce por ese prefijo para fijar «sólo publicado y sin
 *   contraseña» (Divi no lee ninguna clave guardada para eso).
 *
 * Nunca se escribe `metaQuery`, `search`, `includeSpecificPosts`, `excludeSpecificPosts` ni
 * `excludePostWithSpecificTerms`. Un bucle ya guardado se reconoce como «del conector» ({@see self::valid_stored()})
 * sólo si es EXACTAMENTE lo que este esquema escribiría: sirve de contexto de los tokens `loop_*`.
 *
 * Lo que depende del sitio (tipos de contenido, taxonomías, términos) llega como funciones inyectables.
 */
final class DiviLoopSchema {

	public const MAX_PER_PAGE = 24;

	public const MAX_OFFSET = 1000;

	/**
	 * Términos por taxonomía y taxonomías por bucle.
	 */
	public const MAX_TERMS = 20;

	public const MAX_TAXONOMIES = 5;

	/**
	 * Forma del `loopId` del conector: `loop-` (como el Visual Builder) + `nx` + 8 caracteres. Divi sólo exige texto.
	 */
	public const ID_PATTERN = '/^loop-nx[0-9a-z]{8}\z/';

	/**
	 * Módulos del catálogo de D2 en los que Divi 5 admite `module.advanced.loop` (no `divi/icon` ni `divi/video`).
	 *
	 * @var array<int, string>
	 */
	public const MODULES = array(
		'divi/section',
		'divi/row',
		'divi/column',
		'divi/text',
		'divi/heading',
		'divi/button',
		'divi/image',
		'divi/divider',
		'divi/blurb',
		'divi/icon-list',
		'divi/icon-list-item',
	);

	/**
	 * Claves de entrada: `post_type`, `per_page`, `order_by` y `order` son obligatorias.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = array( 'post_type', 'per_page', 'order_by', 'order', 'offset', 'exclude_current', 'terms' );

	private const REQUIRED = array( 'post_type', 'per_page', 'order_by', 'order' );

	/**
	 * Entrada → valor de Divi (`id` se guarda como `ID`, el valor del selector del Visual Builder).
	 *
	 * @var array<string, string>
	 */
	private const ORDER_BY = array(
		'date'  => 'date',
		'title' => 'title',
		'id'    => 'ID',
	);

	/**
	 * Entrada → valor de Divi del sentido del orden.
	 *
	 * @var array<string, string>
	 */
	private const ORDER = array(
		'asc'  => 'ascending',
		'desc' => 'descending',
	);

	/**
	 * Tipo de contenido: fn( string ): array{public: bool, label: string}|null.
	 *
	 * @var callable
	 */
	private $post_types;

	/**
	 * Taxonomías públicas de un tipo: fn( string ): array<int, string>.
	 *
	 * @var callable
	 */
	private $taxonomies_of;

	/**
	 * Nombre de un término o null si no existe: fn( int, string ): string|null.
	 *
	 * @var callable
	 */
	private $term_label;

	/**
	 * Generador de `loopId`: fn(): string.
	 *
	 * @var callable
	 */
	private $new_id;

	/**
	 * Construye el esquema.
	 *
	 * @param callable|null $post_types    fn( string ): array{public: bool, label: string}|null. Por omisión, `get_post_type_object()`.
	 * @param callable|null $taxonomies_of fn( string ): array<int, string>. Por omisión, {@see DiviDynamicCatalog::public_taxonomies()}.
	 * @param callable|null $term_label    fn( int, string ): string|null. Por omisión, `get_term()`.
	 * @param callable|null $new_id        fn(): string. Por omisión, {@see self::random_id()}.
	 */
	public function __construct( ?callable $post_types = null, ?callable $taxonomies_of = null, ?callable $term_label = null, ?callable $new_id = null ) {
		$this->post_types    = $post_types ?? array( self::class, 'registered_post_type' );
		$this->taxonomies_of = $taxonomies_of ?? array( DiviDynamicCatalog::class, 'public_taxonomies' );
		$this->term_label    = $term_label ?? array( self::class, 'registered_term_label' );
		$this->new_id        = $new_id ?? array( self::class, 'random_id' );
	}

	/**
	 * Valida el bucle del cliente y devuelve los parámetros normalizados.
	 *
	 * Errores `nexo_mcp_divi_loop_invalid` con `data.path` (`loop.per_page`, `loop.terms.category`…) y el mensaje
	 * `«ruta: motivo»`.
	 *
	 * @param mixed $loop `{post_type, per_page, order_by, order, offset?, exclude_current?, terms?}`.
	 *
	 * @return array{post_type: string, label: string, per_page: int, order_by: string, order: string, offset: int, exclude_current: bool, terms: array<string, array<int, int>>}|\WP_Error
	 */
	public function validate( $loop ) {
		if ( ! is_array( $loop ) || array() === $loop || array_is_list( $loop ) ) {
			return $this->invalid( 'loop', __( 'debe ser un objeto {post_type, per_page, order_by, order, offset?, exclude_current?, terms?} (o null para quitar el bucle).', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $loop ) as $key ) {
			if ( ! in_array( (string) $key, self::KEYS, true ) ) {
				return $this->invalid(
					'loop.' . $key,
					sprintf(
						/* translators: %s: claves admitidas. */
						__( 'no se admite (nada de metaQuery, búsqueda, tipos múltiples, estados ni claves de Divi). Admitidas: %s.', 'nexo-mcp' ),
						implode( ', ', self::KEYS )
					)
				);
			}
		}

		foreach ( self::REQUIRED as $key ) {
			if ( ! array_key_exists( $key, $loop ) ) {
				return $this->invalid( 'loop.' . $key, __( 'es obligatorio.', 'nexo-mcp' ) );
			}
		}

		$info = $this->check_post_type( $loop['post_type'] );

		if ( $info instanceof \WP_Error ) {
			return $info;
		}

		$per_page = $loop['per_page'];

		if ( ! is_int( $per_page ) || $per_page < 1 || $per_page > self::MAX_PER_PAGE ) {
			return $this->invalid( 'loop.per_page', sprintf( /* translators: %d: máximo. */ __( 'debe ser un entero entre 1 y %d.', 'nexo-mcp' ), self::MAX_PER_PAGE ) );
		}

		if ( ! is_string( $loop['order_by'] ) || ! isset( self::ORDER_BY[ $loop['order_by'] ] ) ) {
			return $this->invalid( 'loop.order_by', sprintf( /* translators: %s: valores. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ' | ', array_keys( self::ORDER_BY ) ) ) );
		}

		if ( ! is_string( $loop['order'] ) || ! isset( self::ORDER[ $loop['order'] ] ) ) {
			return $this->invalid( 'loop.order', sprintf( /* translators: %s: valores. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ' | ', array_keys( self::ORDER ) ) ) );
		}

		$offset = array_key_exists( 'offset', $loop ) ? $loop['offset'] : 0;

		if ( ! is_int( $offset ) || $offset < 0 || $offset > self::MAX_OFFSET ) {
			return $this->invalid( 'loop.offset', sprintf( /* translators: %d: máximo. */ __( 'debe ser un entero entre 0 y %d.', 'nexo-mcp' ), self::MAX_OFFSET ) );
		}

		$exclude = array_key_exists( 'exclude_current', $loop ) ? $loop['exclude_current'] : false;

		if ( ! is_bool( $exclude ) ) {
			return $this->invalid( 'loop.exclude_current', __( 'debe ser true o false.', 'nexo-mcp' ) );
		}

		$terms = $this->check_terms( array_key_exists( 'terms', $loop ) ? $loop['terms'] : array(), $info['type'] );

		if ( $terms instanceof \WP_Error ) {
			return $terms;
		}

		return array(
			'post_type'       => $info['type'],
			'label'           => $info['label'],
			'per_page'        => $per_page,
			'order_by'        => $loop['order_by'],
			'order'           => $loop['order'],
			'offset'          => $offset,
			'exclude_current' => $exclude,
			'terms'           => $terms,
		);
	}

	/**
	 * Valor guardado en `module.advanced.loop.desktop.value` para los parámetros ya validados.
	 *
	 * @param array{post_type: string, label: string, per_page: int, order_by: string, order: string, offset: int, exclude_current: bool, terms: array<string, array<int, int>>} $params  Resultado de {@see self::validate()}.
	 * @param string                                                                                                                                                             $loop_id `loopId` (de {@see self::new_id()} o el que ya tenía).
	 *
	 * @return array<string, mixed>
	 */
	public function stored( array $params, string $loop_id ): array {
		$value = array(
			'enable'             => 'on',
			'queryType'          => 'post_types',
			'subTypes'           => array(
				array(
					'value' => $params['post_type'],
					'label' => $params['label'],
				),
			),
			'orderBy'            => self::ORDER_BY[ $params['order_by'] ],
			'order'              => self::ORDER[ $params['order'] ],
			'postPerPage'        => (string) $params['per_page'],
			'postOffset'         => (string) $params['offset'],
			'excludeCurrentPost' => $params['exclude_current'] ? 'on' : 'off',
			'ignoreStickysPost'  => 'on',
		);

		$groups = array();

		foreach ( $params['terms'] as $taxonomy => $ids ) {
			$options = array();

			foreach ( $ids as $id ) {
				$options[] = array(
					'value' => $id,
					'label' => (string) ( $this->term_label )( $id, $taxonomy ),
				);
			}

			$groups[] = array(
				'categoryId'      => $taxonomy,
				'selectedOptions' => $options,
			);
		}

		if ( array() !== $groups ) {
			$value['includePostWithSpecificTerms'] = $groups;
		}

		$value['loopId'] = $loop_id;

		return $value;
	}

	/**
	 * `loopId` nuevo del conector.
	 */
	public function new_id(): string {
		return (string) ( $this->new_id )();
	}

	/**
	 * Reconoce un valor guardado como bucle del conector: sólo si coincide, salvo las etiquetas, con lo que
	 * {@see self::stored()} escribiría para sus propios parámetros. Cualquier clave de más (metaQuery, búsqueda,
	 * tipos, ids a mano), un tipo que hoy no es válido, un término que ya no existe o un `loopId` ajeno → null.
	 *
	 * @param mixed $stored Valor de `module.advanced.loop.desktop.value`.
	 *
	 * @return array{post_type: string, label: string, per_page: int, order_by: string, order: string, offset: int, exclude_current: bool, terms: array<string, array<int, int>>, loop_id: string}|null
	 */
	public function valid_stored( $stored ): ?array {
		$read = $this->read( $stored );

		if ( null === $read ) {
			return null;
		}

		$params = $this->validate( $read['input'] );

		if ( $params instanceof \WP_Error ) {
			return null;
		}

		if ( self::canonical( self::without_labels( $this->stored( $params, $read['loop_id'] ) ) ) !== self::canonical( self::without_labels( $stored ) ) ) {
			return null;
		}

		return $params + array( 'loop_id' => $read['loop_id'] );
	}

	/**
	 * Indica si los atributos de un bloque llevan un bucle de Divi activo (de cualquier origen).
	 *
	 * @param array<mixed, mixed> $attrs Atributos del bloque.
	 */
	public static function has_loop( array $attrs ): bool {
		$value = self::loop_value( $attrs );

		return null !== $value && is_string( $value['enable'] ?? null ) && 'on' === strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $value['enable'] ) );
	}

	/**
	 * Valor `module.advanced.loop.desktop.value` de unos atributos, o null si no hay un objeto.
	 *
	 * @param array<mixed, mixed> $attrs Atributos del bloque.
	 *
	 * @return array<mixed, mixed>|null
	 */
	public static function loop_value( array $attrs ): ?array {
		$value = $attrs['module']['advanced']['loop']['desktop']['value'] ?? null;

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Atributos sin `module.advanced.loop`, sin dejar `advanced` ni `module` vacíos.
	 *
	 * @param array<mixed, mixed> $attrs Atributos del bloque.
	 *
	 * @return array<mixed, mixed>
	 */
	public static function without_loop( array $attrs ): array {
		if ( isset( $attrs['module']['advanced'] ) && is_array( $attrs['module']['advanced'] ) ) {
			unset( $attrs['module']['advanced']['loop'] );

			if ( array() === $attrs['module']['advanced'] ) {
				unset( $attrs['module']['advanced'] );
			}

			if ( array() === $attrs['module'] ) {
				unset( $attrs['module'] );
			}
		}

		return $attrs;
	}

	/**
	 * `loopId` aleatorio `loop-nx` + 8 caracteres `[0-9a-z]`.
	 */
	public static function random_id(): string {
		$chars = '0123456789abcdefghijklmnopqrstuvwxyz';
		$id    = '';

		for ( $i = 0; $i < 8; $i++ ) {
			$id .= $chars[ random_int( 0, 35 ) ];
		}

		return 'loop-nx' . $id;
	}

	/**
	 * Tipo de contenido registrado (fuente de producción).
	 *
	 * @param string $type Tipo.
	 *
	 * @return array{public: bool, label: string}|null
	 */
	public static function registered_post_type( string $type ): ?array {
		$object = get_post_type_object( $type );

		if ( ! $object instanceof \WP_Post_Type ) {
			return null;
		}

		return array(
			'public' => (bool) $object->public,
			'label'  => (string) ( $object->labels->name ?? $object->label ),
		);
	}

	/**
	 * Nombre de un término (fuente de producción), o null si no existe en esa taxonomía.
	 *
	 * @param int    $id       Término.
	 * @param string $taxonomy Taxonomía.
	 */
	public static function registered_term_label( int $id, string $taxonomy ): ?string {
		$term = get_term( $id, $taxonomy );

		return $term instanceof \WP_Term ? $term->name : null;
	}

	/**
	 * Un solo tipo público, no `attachment` ni `any`.
	 *
	 * @param mixed $type Valor recibido.
	 *
	 * @return array{type: string, label: string}|\WP_Error
	 */
	private function check_post_type( $type ) {
		if ( ! is_string( $type ) || 1 !== preg_match( '/^[a-z0-9_\-]{1,20}\z/', $type ) ) {
			return $this->invalid( 'loop.post_type', __( 'debe ser UN tipo de contenido (por ejemplo post, page o product), con su nombre interno en minúsculas.', 'nexo-mcp' ) );
		}

		if ( in_array( $type, array( 'any', 'attachment' ), true ) ) {
			return $this->invalid(
				'loop.post_type',
				sprintf(
					/* translators: %s: tipo. */
					__( '«%s» no se admite: con él Divi consulta también contenido privado o adjuntos. Usa un tipo público concreto (post, page, product…).', 'nexo-mcp' ),
					$type
				)
			);
		}

		$info = ( $this->post_types )( $type );

		if ( ! is_array( $info ) || empty( $info['public'] ) ) {
			return $this->invalid(
				'loop.post_type',
				sprintf(
					/* translators: %s: tipo. */
					__( '«%s» no es un tipo de contenido público de este sitio.', 'nexo-mcp' ),
					$type
				)
			);
		}

		return array(
			'type'  => $type,
			'label' => is_string( $info['label'] ?? null ) && '' !== $info['label'] ? $info['label'] : $type,
		);
	}

	/**
	 * Términos `{taxonomía: [ids]}` de taxonomías públicas del tipo, existentes.
	 *
	 * @param mixed  $terms Valor recibido.
	 * @param string $type  Tipo del bucle.
	 *
	 * @return array<string, array<int, int>>|\WP_Error
	 */
	private function check_terms( $terms, string $type ) {
		if ( ! is_array( $terms ) || ( array() !== $terms && array_is_list( $terms ) ) ) {
			return $this->invalid( 'loop.terms', __( 'debe ser un objeto {taxonomía: [ids de término]}.', 'nexo-mcp' ) );
		}

		if ( count( $terms ) > self::MAX_TAXONOMIES ) {
			return $this->invalid( 'loop.terms', sprintf( /* translators: %d: máximo. */ __( 'admite hasta %d taxonomías.', 'nexo-mcp' ), self::MAX_TAXONOMIES ) );
		}

		$allowed = array_map( 'strval', (array) ( $this->taxonomies_of )( $type ) );
		$out     = array();

		foreach ( $terms as $taxonomy => $ids ) {
			$where = 'loop.terms.' . $taxonomy;

			if ( ! in_array( (string) $taxonomy, $allowed, true ) ) {
				return $this->invalid(
					$where,
					sprintf(
						/* translators: 1: taxonomía, 2: tipo, 3: taxonomías. */
						__( '«%1$s» no es una taxonomía pública del tipo «%2$s». Admitidas: %3$s.', 'nexo-mcp' ),
						$taxonomy,
						$type,
						array() === $allowed ? __( 'ninguna', 'nexo-mcp' ) : implode( ', ', $allowed )
					)
				);
			}

			if ( ! is_array( $ids ) || array() === $ids || ! array_is_list( $ids ) ) {
				return $this->invalid( $where, __( 'debe ser una lista no vacía de ids de término (enteros).', 'nexo-mcp' ) );
			}

			$unique = array();

			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) {
					return $this->invalid( $where, __( 'debe ser una lista no vacía de ids de término (enteros positivos).', 'nexo-mcp' ) );
				}

				$unique[ $id ] = $id;
			}

			if ( count( $unique ) > self::MAX_TERMS ) {
				return $this->invalid( $where, sprintf( /* translators: %d: máximo. */ __( 'admite hasta %d términos por taxonomía.', 'nexo-mcp' ), self::MAX_TERMS ) );
			}

			foreach ( $unique as $id ) {
				if ( null === ( $this->term_label )( $id, (string) $taxonomy ) ) {
					return $this->invalid( $where, sprintf( /* translators: 1: id, 2: taxonomía. */ __( 'el término %1$d no existe en «%2$s».', 'nexo-mcp' ), $id, $taxonomy ) );
				}
			}

			$out[ (string) $taxonomy ] = array_values( $unique );
		}

		return $out;
	}

	/**
	 * Lee un valor guardado en la forma de entrada, sólo si tiene exactamente las claves y los formatos que escribimos.
	 *
	 * @param mixed $stored Valor guardado.
	 *
	 * @return array{input: array<string, mixed>, loop_id: string}|null
	 */
	private function read( $stored ): ?array {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$required = array( 'enable', 'queryType', 'subTypes', 'orderBy', 'order', 'postPerPage', 'postOffset', 'excludeCurrentPost', 'ignoreStickysPost', 'loopId' );
		$keys     = array_map( 'strval', array_keys( $stored ) );
		$extra    = array_diff( $keys, array_merge( $required, array( 'includePostWithSpecificTerms' ) ) );
		$missing  = array_diff( $required, $keys );

		if ( array() !== $extra || array() !== $missing ) {
			return null;
		}

		$sub = $stored['subTypes'];

		if ( 'on' !== $stored['enable'] || 'post_types' !== $stored['queryType'] || 'on' !== $stored['ignoreStickysPost'] || ! is_array( $sub ) || 1 !== count( $sub ) || ! array_is_list( $sub ) || ! is_array( $sub[0] ) || ! is_string( $sub[0]['value'] ?? null ) ) {
			return null;
		}

		$order_by = array_search( $stored['orderBy'], self::ORDER_BY, true );
		$order    = array_search( $stored['order'], self::ORDER, true );
		$per_page = $stored['postPerPage'];
		$offset   = $stored['postOffset'];
		$loop_id  = $stored['loopId'];

		if ( false === $order_by || false === $order || ! is_string( $per_page ) || 1 !== preg_match( '/^[1-9][0-9]?\z/', $per_page ) || ! is_string( $offset ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,3})\z/', $offset ) || ! in_array( $stored['excludeCurrentPost'], array( 'on', 'off' ), true ) || ! is_string( $loop_id ) || 1 !== preg_match( self::ID_PATTERN, $loop_id ) ) {
			return null;
		}

		$terms = $this->read_terms( $stored['includePostWithSpecificTerms'] ?? array() );

		if ( null === $terms ) {
			return null;
		}

		return array(
			'input'   => array(
				'post_type'       => $sub[0]['value'],
				'per_page'        => (int) $per_page,
				'order_by'        => $order_by,
				'order'           => $order,
				'offset'          => (int) $offset,
				'exclude_current' => 'on' === $stored['excludeCurrentPost'],
				'terms'           => $terms,
			),
			'loop_id' => $loop_id,
		);
	}

	/**
	 * Términos guardados en la forma de entrada, o null si no tienen la forma exacta que escribimos.
	 *
	 * @param mixed $groups `includePostWithSpecificTerms`.
	 *
	 * @return array<string, array<int, int>>|null
	 */
	private function read_terms( $groups ): ?array {
		if ( ! is_array( $groups ) || ( array() !== $groups && ! array_is_list( $groups ) ) ) {
			return null;
		}

		$out = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! is_string( $group['categoryId'] ?? null ) || ! is_array( $group['selectedOptions'] ?? null ) || ! array_is_list( $group['selectedOptions'] ) || isset( $out[ $group['categoryId'] ] ) ) {
				return null;
			}

			$ids = array();

			foreach ( $group['selectedOptions'] as $option ) {
				if ( ! is_array( $option ) || ! is_int( $option['value'] ?? null ) ) {
					return null;
				}

				$ids[] = $option['value'];
			}

			$out[ $group['categoryId'] ] = $ids;
		}

		return $out;
	}

	/**
	 * Quita las claves `label` (etiquetas de interfaz, que cambian con el idioma) de `subTypes` y de `selectedOptions`.
	 *
	 * @param mixed $stored Valor guardado.
	 *
	 * @return mixed
	 */
	private static function without_labels( $stored ) {
		if ( ! is_array( $stored ) ) {
			return $stored;
		}

		foreach ( array( 'subTypes', 'includePostWithSpecificTerms' ) as $key ) {
			if ( ! isset( $stored[ $key ] ) || ! is_array( $stored[ $key ] ) ) {
				continue;
			}

			foreach ( $stored[ $key ] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				unset( $item['label'] );

				if ( isset( $item['selectedOptions'] ) && is_array( $item['selectedOptions'] ) ) {
					foreach ( $item['selectedOptions'] as $at => $option ) {
						if ( is_array( $option ) ) {
							unset( $option['label'] );
							$item['selectedOptions'][ $at ] = $option;
						}
					}
				}

				$stored[ $key ][ $index ] = $item;
			}
		}

		return $stored;
	}

	/**
	 * Forma canónica para comparar: claves de objeto ordenadas (las listas conservan su orden).
	 *
	 * @param mixed $value Valor.
	 *
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( self::class, 'canonical' ), $value );

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * Error de un campo del bucle.
	 *
	 * @param string $path   Ruta (`loop.per_page`).
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_divi_loop_invalid', $path . ': ' . $reason, array( 'path' => $path ) );
	}
}
