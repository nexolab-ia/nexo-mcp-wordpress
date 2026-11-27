<?php
/**
 * Esquema cerrado de los ítems de la biblioteca de Divi 5 que el conector deja guardar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decisión D10 de L2 de `odd/tasks/divi-theme-builder.md` (S1, S3 y S7).
 *
 * Divi guarda un ítem de la biblioteca como un post `et_pb_layout` con los términos `layout_type`
 * (`layout|section|row|module`), `scope` (`global|non_global`), `module_width` (`regular`) y `layout_category`, y las metas
 * `_et_pb_built_for_post_type`, `_et_pb_template_type` (el tipo), `_et_pb_use_divi_5` y, en un módulo, `_et_pb_module_type`
 * (y `_et_pb_excluded_global_options` si es global). Aquí se arma SÓLO eso ({@see self::stored()}).
 *
 * El contenido pasa por el validador de Divi 5 ({@see DiviTreeValidator}): el padre de la lista depende del tipo
 * ({@see self::root_parent()}) y la forma se comprueba con {@see self::check_shape()}. Lo que Divi guardó con módulos que el
 * catálogo no conoce se detecta con {@see self::foreign_types()}.
 *
 * Las lecturas de WordPress son funciones inyectables con una fuente de producción por omisión. Cualquier excepción de
 * una fuente cuenta como «no hay dato»: ni la validación ni las lecturas lanzan con datos basura (lección L7 de
 * `divi-condiciones.md`).
 */
final class DiviLibrarySchema {

	public const CODE_INVALID = 'nexo_mcp_divi_library_invalid';

	public const POST_TYPE = 'et_pb_layout';

	public const MAX_TITLE = 100;

	public const MAX_CATEGORIES = 10;

	public const MAX_CATEGORY = 50;

	public const MAX_LIST = 100;

	public const DEFAULT_LIST = 50;

	public const TYPES = array( 'layout', 'section', 'row', 'module' );

	public const SCOPES = array( 'non_global', 'global' );

	/**
	 * Estados con los que un ítem se lista y se toca. `trash` y `auto-draft` quedan fuera (T8, aviso 4 del verificador).
	 *
	 * @var array<int, string>
	 */
	public const LIVE_STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	private const KEYS = array( 'id', 'title', 'type', 'scope', 'nodes', 'categories', 'replace_foreign' );

	/**
	 * Términos del ítem: fn( int $id, string $taxonomy ): array<int, string>|\WP_Error (nombres o slugs).
	 *
	 * @var callable
	 */
	private $terms;

	/**
	 * Metas del ítem: fn( int $id, string $key ): mixed.
	 *
	 * @var callable
	 */
	private $meta;

	/**
	 * Construye el esquema.
	 *
	 * @param callable|null $terms fn( int, string ): array|\WP_Error. Por omisión, `wp_get_object_terms()` con los nombres (los slugs para `scope`, `layout_type`).
	 * @param callable|null $meta  fn( int, string ): mixed. Por omisión, `get_post_meta()`.
	 */
	public function __construct( ?callable $terms = null, ?callable $meta = null ) {
		$this->terms = $terms ?? static function ( int $id, string $taxonomy ) {
			if ( ! function_exists( 'wp_get_object_terms' ) ) {
				return array();
			}

			$fields = 'layout_category' === $taxonomy ? 'names' : 'slugs';

			return wp_get_object_terms( $id, $taxonomy, array( 'fields' => $fields ) );
		};
		$this->meta  = $meta ?? static fn( int $id, string $key ) => get_post_meta( $id, $key, true );
	}

	/**
	 * Valida la entrada de `save-divi-library-item` y devuelve la forma normalizada.
	 *
	 * Sin `$current` es un alta: `title`, `type` y `nodes` son obligatorios. Con `$current` (lo que ya tiene el ítem) es una
	 * actualización: `type` y `scope`, si se pasan, tienen que coincidir (no se cambian) y hay que cambiar algo.
	 *
	 * @param mixed                                   $input   Entrada recibida.
	 * @param array{type: string, scope: string}|null $current Lo que ya tiene el ítem, o null en un alta.
	 *
	 * @return array{title: string|null, type: string, scope: string, categories: array<int, string>|null, nodes: mixed, replace_foreign: bool}|\WP_Error
	 */
	public function parse_save( $input, ?array $current = null ) {
		if ( ! is_array( $input ) ) {
			return $this->error( '', __( 'la entrada debe ser un objeto.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				return $this->error( (string) $key, __( 'no es un campo conocido.', 'nexo-mcp' ) );
			}
		}

		$title = $this->title( $input, null === $current );

		if ( $title instanceof \WP_Error ) {
			return $title;
		}

		$type = $this->closed( $input, 'type', self::TYPES, $current['type'] ?? null, null !== $current );

		if ( $type instanceof \WP_Error ) {
			return $type;
		}

		$scope = $this->closed( $input, 'scope', self::SCOPES, null === $current ? 'non_global' : $current['scope'], null !== $current );

		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}

		$categories = $this->categories( $input );

		if ( $categories instanceof \WP_Error ) {
			return $categories;
		}

		$flag = $input['replace_foreign'] ?? false;

		if ( ! is_bool( $flag ) ) {
			return $this->error( 'replace_foreign', __( 'debe ser true o false.', 'nexo-mcp' ) );
		}

		$has_nodes = array_key_exists( 'nodes', $input );

		if ( null === $current && ! $has_nodes ) {
			return $this->error( 'nodes', __( 'es obligatorio.', 'nexo-mcp' ) );
		}

		if ( null !== $current && null === $title && ! $has_nodes && null === $categories ) {
			return $this->error( '', __( 'no hay nada que cambiar: pasa title, nodes o categories.', 'nexo-mcp' ) );
		}

		return array(
			'title'           => $title,
			'type'            => $type,
			'scope'           => $scope,
			'categories'      => $categories,
			'nodes'           => $input['nodes'] ?? null,
			'replace_foreign' => $flag,
		);
	}

	/**
	 * Padre de la lista de nodos de cada tipo, para {@see DiviTreeValidator::validate_tree()}.
	 *
	 * @param string $type Tipo del ítem.
	 */
	public static function root_parent( string $type ): string {
		switch ( $type ) {
			case 'row':
				return 'divi/section';
			case 'module':
				return 'divi/column';
			default:
				return 'divi/placeholder';
		}
	}

	/**
	 * Comprueba la forma del árbol ya validado: una sección, una fila o un módulo; un layout lleva una o más secciones.
	 *
	 * @param string                           $type  Tipo del ítem.
	 * @param array<int, array<string, mixed>> $clean Árbol saneado por el validador.
	 *
	 * @return true|\WP_Error
	 */
	public function check_shape( string $type, array $clean ) {
		if ( 'layout' !== $type && 1 !== count( $clean ) ) {
			return $this->error(
				'nodes',
				sprintf(
					/* translators: %s: tipo del ítem. */
					__( 'un ítem de tipo %s lleva exactamente un nodo.', 'nexo-mcp' ),
					$type
				)
			);
		}

		return true;
	}

	/**
	 * Módulo del ítem de tipo `module` (`divi/text`), o null si no tiene uno claro.
	 *
	 * @param array<int, array<string, mixed>> $clean Árbol saneado.
	 */
	public static function module_type( array $clean ): ?string {
		$type = $clean[0]['type'] ?? null;

		return is_string( $type ) && DiviModuleCatalog::has( $type ) ? $type : null;
	}

	/**
	 * Términos y metas con que Divi guarda un ítem nuevo (lo que escribe el Visual Builder).
	 *
	 * @param string      $type        Tipo.
	 * @param string      $scope       `global` o `non_global`.
	 * @param string|null $module_type Módulo (sólo tipo `module`).
	 *
	 * @return array{terms: array<string, array<int, string>>, meta: array<string, string>}
	 */
	public function stored( string $type, string $scope, ?string $module_type = null ): array {
		$meta = array(
			'_et_pb_built_for_post_type' => 'page',
			'_et_pb_template_type'       => $type,
			'_et_pb_use_divi_5'          => 'on',
		);

		if ( 'module' === $type ) {
			$meta['_et_pb_module_type'] = (string) $module_type;

			if ( 'global' === $scope ) {
				$meta['_et_pb_excluded_global_options'] = '[]';
			}
		}

		return array(
			'terms' => array(
				'layout_type'  => array( $type ),
				'scope'        => array( $scope ),
				'module_width' => array( 'regular' ),
			),
			'meta'  => $meta,
		);
	}

	/**
	 * Lo que el ítem guarda hoy: tipo, alcance, categorías y módulo. Un dato que falta o es basura queda vacío.
	 *
	 * @param int $id Ítem.
	 *
	 * @return array{type: string, scope: string, categories: array<int, string>, module_type: string}
	 */
	public function read( int $id ): array {
		$types  = $this->strings( $id, 'layout_type' );
		$scopes = $this->strings( $id, 'scope' );
		$type   = array_values( array_intersect( self::TYPES, $types ) )[0] ?? '';
		$module = $this->safe_meta( $id, '_et_pb_module_type' );

		return array(
			'type'        => $type,
			'scope'       => in_array( 'global', $scopes, true ) || $this->meta_global( $id ) ? 'global' : 'non_global',
			'categories'  => $this->strings( $id, 'layout_category' ),
			'module_type' => is_string( $module ) ? $module : '',
		);
	}

	/**
	 * Indica si el ítem es global, con la regla de Divi (`GlobalLayout::is_global_layout_template()`): el término `global` de
	 * `scope` o, para los globales importados de Divi 4, un módulo (`_et_pb_module_type`) con opciones excluidas
	 * (`_et_pb_excluded_global_options`) no vacías. Si no se pueden leer los términos se trata como global (falla cerrado,
	 * como el permiso: {@see PermissionPolicy::can_write_divi_library()} usa {@see self::meta_is_global()}).
	 *
	 * @param int $id Ítem.
	 */
	public function is_global( int $id ): bool {
		try {
			$scopes = ( $this->terms )( $id, 'scope' );
		} catch ( \Throwable $e ) {
			return true;
		}

		return ! is_array( $scopes ) || in_array( 'global', $scopes, true ) || $this->meta_global( $id );
	}

	/**
	 * Bloque raíz del ítem si se puede referenciar con `divi/global-layout`: existe, es de la biblioteca, está publicado (Divi no
	 * renderiza un global en borrador, privado o en la papelera, L2), es global y es una sección, una fila o un módulo. Si no, null.
	 *
	 * @param int $id Ítem.
	 */
	public function global_block( int $id ): ?string {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ! $this->is_global( $id ) ) {
			return null;
		}

		$read = $this->read( $id );

		if ( 'section' === $read['type'] || 'row' === $read['type'] ) {
			return 'divi/' . $read['type'];
		}

		return 'module' === $read['type'] && DiviModuleCatalog::has( $read['module_type'] ) ? $read['module_type'] : null;
	}

	/**
	 * La alternativa de Divi para globales importados de Divi 4, a partir de las dos metas.
	 *
	 * @param mixed $module_type      `_et_pb_module_type`.
	 * @param mixed $excluded_options `_et_pb_excluded_global_options`.
	 */
	public static function meta_is_global( $module_type, $excluded_options ): bool {
		return ! empty( $module_type ) && ! empty( $excluded_options );
	}

	/**
	 * Clave para reconocer una categoría como lo hace WordPress: el slug del nombre sin escapar (`header` es `Header`, y
	 * `A & B` se guarda como `A &amp; B`).
	 *
	 * @param string $name Nombre de categoría.
	 */
	public static function category_key( string $name ): string {
		return sanitize_title( html_entity_decode( $name, ENT_QUOTES ) );
	}

	/**
	 * Mismas categorías (por {@see self::category_key()}) sin importar el orden.
	 *
	 * @param array<int, string> $a Nombres.
	 * @param array<int, string> $b Nombres.
	 */
	public static function same_categories( array $a, array $b ): bool {
		$a = array_map( array( self::class, 'category_key' ), $a );
		$b = array_map( array( self::class, 'category_key' ), $b );
		sort( $a );
		sort( $b );

		return $a === $b;
	}

	/**
	 * Crea las categorías que no existen y devuelve SÓLO los ids de las que creó esta llamada (las que ya existían, con el
	 * mismo nombre o slug, dan `term_exists` y no cuentan). Así un deshacer sabe qué es suyo ({@see self::drop_categories()}).
	 *
	 * @param array<int, string> $names Nombres pedidos.
	 *
	 * @return array<int, int>
	 */
	public function ensure_categories( array $names ): array {
		$created = array();

		try {
			foreach ( $names as $name ) {
				$term = function_exists( 'wp_insert_term' ) ? wp_insert_term( $name, 'layout_category' ) : null;

				if ( is_array( $term ) ) {
					$created[] = (int) $term['term_id'];
				}
			}
		} catch ( \Throwable $e ) {
			return $created;
		}

		return $created;
	}

	/**
	 * Borra categorías creadas por esta llamada ({@see self::ensure_categories()}) que ningún ítem usa. Si no se puede saber
	 * cuáles se usan no borra ninguna; nunca toca otras.
	 *
	 * @param array<int, int> $created Ids que creó esta llamada.
	 */
	public function drop_categories( array $created ): void {
		if ( array() === $created ) {
			return;
		}

		try {
			$used = function_exists( 'get_terms' ) ? get_terms(
				array(
					'taxonomy'   => 'layout_category',
					'hide_empty' => true,
					'fields'     => 'ids',
				)
			) : null;

			if ( ! is_array( $used ) ) {
				return;
			}

			foreach ( array_diff( $created, array_map( 'intval', $used ) ) as $term_id ) {
				wp_delete_term( $term_id, 'layout_category' );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Alternativa de Divi 4 para el ítem, leyendo las metas con la fuente inyectada (nunca lanza).
	 *
	 * @param int $id Ítem.
	 */
	private function meta_global( int $id ): bool {
		return self::meta_is_global( $this->safe_meta( $id, '_et_pb_module_type' ), $this->safe_meta( $id, '_et_pb_excluded_global_options' ) );
	}

	/**
	 * Tipos de bloque del contenido que el catálogo no conoce (módulos de Divi hechos en el Visual Builder). Un contenido
	 * que no es de Divi 5 (shortcodes de Divi 4, texto suelto) da `legacy`.
	 *
	 * @param string $content `post_content` del ítem.
	 *
	 * @return array<int, string>
	 */
	public function foreign_types( string $content ): array {
		if ( '' !== trim( $content ) && ! Divi5Tree::is_divi5( $content ) ) {
			return array( 'legacy' );
		}

		try {
			$tree = Divi5Tree::build( $content );
		} catch ( \Throwable $e ) {
			return array( 'unparseable' );
		}

		$found = array();
		$this->collect_foreign( $tree, $found );

		return array_values( array_unique( $found ) );
	}

	/**
	 * Resumen de un ítem para el listado.
	 *
	 * @param \WP_Post $post Ítem.
	 *
	 * @return array<string, mixed>
	 */
	public function summary( \WP_Post $post ): array {
		$read = $this->read( (int) $post->ID );

		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) $post->post_title,
			'status'      => (string) $post->post_status,
			'type'        => $read['type'],
			'scope'       => $read['scope'],
			'categories'  => $read['categories'],
			'module_type' => $read['module_type'],
			'modified'    => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Valida los filtros de `list-divi-library`.
	 *
	 * @param mixed $input Entrada recibida.
	 *
	 * @return array{type: string, scope: string, category: string, search: string, limit: int}|\WP_Error
	 */
	public function parse_filters( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->error( '', __( 'la entrada debe ser un objeto.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, array( 'type', 'scope', 'category', 'search', 'limit' ), true ) ) {
				return $this->error( (string) $key, __( 'no es un campo conocido.', 'nexo-mcp' ) );
			}
		}

		$type  = $this->closed( $input, 'type', self::TYPES, '' );
		$scope = $this->closed( $input, 'scope', self::SCOPES, '' );

		if ( $type instanceof \WP_Error ) {
			return $type;
		}

		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}

		$text = array();

		foreach ( array( 'category', 'search' ) as $key ) {
			$value = $input[ $key ] ?? '';

			if ( ! is_string( $value ) || strlen( $value ) > 100 ) {
				return $this->error( $key, __( 'debe ser un texto de hasta 100 caracteres.', 'nexo-mcp' ) );
			}

			$text[ $key ] = trim( sanitize_text_field( $value ) );
		}

		$limit = $input['limit'] ?? self::DEFAULT_LIST;

		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIST ) {
			return $this->error( 'limit', sprintf( /* translators: %d: tope. */ __( 'debe ser un entero de 1 a %d.', 'nexo-mcp' ), self::MAX_LIST ) );
		}

		return array(
			'type'     => $type,
			'scope'    => $scope,
			'category' => $text['category'],
			'search'   => $text['search'],
			'limit'    => $limit,
		);
	}

	/**
	 * Error de entrada con la ruta del campo.
	 *
	 * @param string $path   Campo.
	 * @param string $reason Motivo.
	 */
	public function error( string $path, string $reason ): \WP_Error {
		return new \WP_Error(
			self::CODE_INVALID,
			'' === $path ? ucfirst( $reason ) : $path . ': ' . $reason,
			array(
				'status' => 400,
				'path'   => $path,
			)
		);
	}

	/**
	 * Título: texto de 1 a 100 caracteres sin `<` ni `>`. En una actualización puede faltar (null).
	 *
	 * @param array<mixed> $input    Entrada.
	 * @param bool         $required Si es obligatorio.
	 *
	 * @return string|null|\WP_Error
	 */
	private function title( array $input, bool $required ) {
		if ( ! array_key_exists( 'title', $input ) ) {
			return $required ? $this->error( 'title', __( 'es obligatorio.', 'nexo-mcp' ) ) : null;
		}

		$title = $input['title'];

		if ( ! is_string( $title ) ) {
			return $this->error( 'title', __( 'debe ser un texto.', 'nexo-mcp' ) );
		}

		$title = trim( sanitize_text_field( $title ) );
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );

		if ( '' === $title || $len > self::MAX_TITLE || preg_match( '/[<>]/', $title ) ) {
			return $this->error( 'title', sprintf( /* translators: %d: tope. */ __( 'debe tener de 1 a %d caracteres y no llevar < ni >.', 'nexo-mcp' ), self::MAX_TITLE ) );
		}

		return $title;
	}

	/**
	 * Valor de una lista cerrada. Sin valor: el de `$fallback` (o error si es null).
	 *
	 * @param array<mixed>      $input    Entrada.
	 * @param string            $key      Campo.
	 * @param array<int,string> $allowed  Valores admitidos.
	 * @param string|null       $fallback Valor si falta.
	 * @param bool              $fixed    Si el valor ya existe y no se puede cambiar (debe coincidir con `$fallback`).
	 *
	 * @return string|\WP_Error
	 */
	private function closed( array $input, string $key, array $allowed, ?string $fallback, bool $fixed = false ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return null === $fallback ? $this->error( $key, __( 'es obligatorio.', 'nexo-mcp' ) ) : $fallback;
		}

		$value = $input[ $key ];

		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			return $this->error( $key, sprintf( /* translators: %s: valores. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ', ', $allowed ) ) );
		}

		if ( $fixed && $fallback !== $value ) {
			return $this->error( $key, __( 'no se puede cambiar en un ítem que ya existe.', 'nexo-mcp' ) );
		}

		return $value;
	}

	/**
	 * Categorías: lista de hasta 10 nombres de 1 a 50 caracteres, sin comas ni `<` `>` y sin repetir. Ausente = null.
	 *
	 * @param array<mixed> $input Entrada.
	 *
	 * @return array<int, string>|null|\WP_Error
	 */
	private function categories( array $input ) {
		if ( ! array_key_exists( 'categories', $input ) ) {
			return null;
		}

		$list = $input['categories'];

		if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > self::MAX_CATEGORIES ) {
			return $this->error( 'categories', sprintf( /* translators: %d: tope. */ __( 'debe ser una lista de hasta %d nombres.', 'nexo-mcp' ), self::MAX_CATEGORIES ) );
		}

		$clean = array();

		foreach ( $list as $i => $name ) {
			$name = is_string( $name ) ? trim( sanitize_text_field( $name ) ) : '';

			if ( '' === $name || strlen( $name ) > self::MAX_CATEGORY || preg_match( '/[,<>]/', $name ) || in_array( $name, $clean, true ) ) {
				return $this->error( 'categories[' . $i . ']', sprintf( /* translators: %d: tope. */ __( 'debe ser un nombre de 1 a %d caracteres, sin comas ni < >, y no repetido.', 'nexo-mcp' ), self::MAX_CATEGORY ) );
			}

			$clean[] = $name;
		}

		return $clean;
	}

	/**
	 * Términos de una taxonomía como lista de textos; cualquier fallo da lista vacía.
	 *
	 * @param int    $id       Ítem.
	 * @param string $taxonomy Taxonomía.
	 *
	 * @return array<int, string>
	 */
	private function strings( int $id, string $taxonomy ): array {
		try {
			$terms = ( $this->terms )( $id, $taxonomy );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array_values( array_filter( $terms, 'is_string' ) );

		// WordPress guarda `&` como `&amp;` en el nombre del término.
		return 'layout_category' === $taxonomy ? array_map( static fn( string $name ): string => html_entity_decode( $name, ENT_QUOTES ), $names ) : $names;
	}

	/**
	 * Meta sin lanzar.
	 *
	 * @param int    $id  Ítem.
	 * @param string $key Meta.
	 *
	 * @return mixed
	 */
	private function safe_meta( int $id, string $key ) {
		try {
			return ( $this->meta )( $id, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Recoge los tipos de bloque de Divi fuera del catálogo.
	 *
	 * @param array<int, array<string, mixed>> $nodes Nodos de {@see Divi5Tree::build()}.
	 * @param array<int, string>               $found Acumulador.
	 */
	private function collect_foreign( array $nodes, array &$found ): void {
		foreach ( $nodes as $node ) {
			$type = isset( $node['type'] ) && is_string( $node['type'] ) ? $node['type'] : '';

			if ( '' !== $type && ! DiviModuleCatalog::has( $type ) ) {
				$found[] = $type;
			}

			$this->collect_foreign( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array(), $found );
		}
	}
}
