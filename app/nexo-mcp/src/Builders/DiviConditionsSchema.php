<?php
/**
 * Esquema cerrado de las condiciones de visualización de Divi 5 que el conector deja escribir.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decisiones S5, S6 y S8 de `odd/tasks/divi-condiciones.md`: catálogo «núcleo» (sesión, rol, fecha/hora y día de la
 * semana, tipo de contenido, categorías/etiquetas, posts concretos, navegador, SO, parámetro de URL y WooCommerce),
 * conjunto entero con `match: all|any` y nada de dispositivo (eso es `disabledOn`, otro atributo).
 *
 * Divi 5 guarda las condiciones en `module.decoration.conditions.desktop.value`: una LISTA de
 * `{id, conditionName, conditionSettings, operator}`. Aquí se arma SÓLO con claves nuestras:
 *
 * - `id`: UUID v4 en minúsculas ({@see self::ID_PATTERN}); Divi usa el `id` como clave de cookie en `numberOfViews`, por
 *   eso es único y estable: {@see self::stored()} conserva el de la misma posición si la condición no cambió.
 * - `operator`: el mismo en TODAS (`all` → `AND`, `any` → `OR`). Divi recorre la lista de la última a la primera y el
 *   resultado de una lista con operadores mezclados depende del orden; con uno solo es previsible. Con `any`, Divi
 *   descarta una segunda condición `OR` del mismo tipo (salvo fecha/hora y parámetro de URL): se rechaza en vez de
 *   ignorarla en silencio.
 * - `conditionSettings.enableCondition: "on"` siempre (con `off` Divi salta la condición y la cuenta como mostrada).
 *
 * Falla abierta: Divi MUESTRA el módulo si el nombre es desconocido, y su `DateTimeImmutable` lanza excepción con una
 * fecha o `modify()` basura al renderizar. Por eso todo se rechaza, nunca se sanea: listas cerradas, `Y-m-d` reales,
 * `HH:MM`, textos de ≤100 caracteres sin `< > [ ] $ \` ni control, e ids que existen (vía funciones inyectables).
 *
 * Excluidos (S5): customField, pageVisit, postVisit, numberOfViews, cookie, categoryPage, tagPage, dateArchive,
 * searchResults, author y dynamicPosts como nombre. Los «posts concretos» se guardan como Divi lo hace: `conditionName`
 * = tipo de contenido y `dynamicPosts`.
 */
final class DiviConditionsSchema {

	public const CODE = 'nexo_mcp_divi_conditions_invalid';

	public const MAX_CONDITIONS = 5;

	public const MAX_TEXT = 100;

	public const MAX_ITEMS = 20;

	public const MAX_ROLES = 10;

	/**
	 * `id` de condición: UUID v4 en minúsculas.
	 */
	public const ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	/**
	 * Módulos del catálogo de D2 que admiten `module.decoration.conditions` en Divi 5.19.1.
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
		'divi/icon',
		'divi/video',
		'divi/icon-list',
		'divi/icon-list-item',
	);

	/**
	 * Tipo de entrada → `conditionName` de Divi (los «posts concretos» usan el tipo de contenido).
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'logged_in'        => 'loggedInStatus',
		'user_role'        => 'userRole',
		'date_time'        => 'dateTime',
		'post_type'        => 'postType',
		'categories'       => 'categories',
		'tags'             => 'tags',
		'posts'            => '(tipo de contenido)',
		'browser'          => 'browser',
		'operating_system' => 'operatingSystem',
		'url_parameter'    => 'urlParameter',
		'cart_contents'    => 'cartContents',
		'product_stock'    => 'productStock',
		'product_purchase' => 'productPurchase',
	);

	/**
	 * Los 23 `conditionName` de Divi 5.19.1 (más `dynamicPosts`, que no es nombre): un tipo de contenido con ese slug
	 * se evaluaría como esa condición, no como «posts concretos».
	 *
	 * @var array<int, string>
	 */
	private const DIVI_NAMES = array(
		'loggedInStatus',
		'userRole',
		'author',
		'postType',
		'categories',
		'tags',
		'categoryPage',
		'tagPage',
		'dateTime',
		'dateArchive',
		'browser',
		'operatingSystem',
		'urlParameter',
		'cookie',
		'customField',
		'numberOfViews',
		'pageVisit',
		'postVisit',
		'searchResults',
		'productPurchase',
		'cartContents',
		'productStock',
		'dynamicPosts',
	);

	/**
	 * Tipos de Divi que se rechazan con motivo propio (entrada con el nombre de Divi).
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED = array( 'customField', 'pageVisit', 'postVisit', 'numberOfViews', 'cookie', 'categoryPage', 'tagPage', 'dateArchive', 'searchResults', 'author', 'dynamicPosts' );

	/**
	 * Claves de entrada por tipo (además de `type`).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const KEYS = array(
		'logged_in'        => array( 'rule' ),
		'user_role'        => array( 'rule', 'roles' ),
		'date_time'        => array( 'rule', 'date', 'time', 'all_day', 'from_time', 'until_time', 'weekdays' ),
		'post_type'        => array( 'rule', 'post_types' ),
		'categories'       => array( 'rule', 'terms' ),
		'tags'             => array( 'rule', 'terms' ),
		'posts'            => array( 'rule', 'post_type', 'posts' ),
		'browser'          => array( 'rule', 'browsers' ),
		'operating_system' => array( 'rule', 'systems' ),
		'url_parameter'    => array( 'name', 'rule', 'value' ),
		'cart_contents'    => array( 'rule', 'products' ),
		'product_stock'    => array( 'rule', 'products' ),
		'product_purchase' => array( 'rule', 'products' ),
	);

	/**
	 * Regla de entrada → `displayRule` de Divi, por tipo.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const RULES = array(
		'logged_in'        => array(
			'logged_in'  => 'loggedIn',
			'logged_out' => 'loggedOut',
		),
		'user_role'        => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'date_time'        => array(
			'before'  => 'isBefore',
			'after'   => 'isAfter',
			'on_date' => 'isOnSpecificDate',
			'on_days' => 'isOnSpecificDays',
		),
		'post_type'        => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'categories'       => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'tags'             => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'posts'            => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'browser'          => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'operating_system' => array(
			'is'     => 'is',
			'is_not' => 'isNot',
		),
		'url_parameter'    => array(
			'equals'       => 'equals',
			'exists'       => 'exist',
			'not_equals'   => 'doesNotEqual',
			'not_exists'   => 'doesNotExist',
			'contains'     => 'contains',
			'not_contains' => 'doesNotContain',
		),
		'cart_contents'    => array(
			'has_products'    => 'hasProducts',
			'is_empty'        => 'isEmpty',
			'has_product'     => 'hasSpecificProduct',
			'not_has_product' => 'doesNotHaveSpecificProduct',
		),
		'product_stock'    => array(
			'in_stock'     => 'isInStock',
			'out_of_stock' => 'isOutOfStock',
		),
		'product_purchase' => array(
			'has_bought'             => 'hasBoughtProduct',
			'has_not_bought'         => 'hasNotBoughtProduct',
			'has_bought_product'     => 'hasBoughtSpecificProduct',
			'has_not_bought_product' => 'hasNotBoughtSpecificProduct',
		),
	);

	/**
	 * Reglas que exigen (y sólo ellas admiten) lista de productos.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const NEEDS_PRODUCTS = array(
		'cart_contents'    => array( 'has_product', 'not_has_product' ),
		'product_stock'    => array( 'in_stock', 'out_of_stock' ),
		'product_purchase' => array( 'has_bought_product', 'has_not_bought_product' ),
	);

	/**
	 * Reglas de `url_parameter` que comparan un valor.
	 *
	 * @var array<int, string>
	 */
	private const NEEDS_VALUE = array( 'equals', 'not_equals', 'contains', 'not_contains' );

	/**
	 * Valores de `browsers` de Divi (`Conditions::_process_browser_condition`).
	 *
	 * @var array<int, string>
	 */
	private const BROWSERS = array( 'chrome', 'firefox', 'safari', 'edge', 'ie', 'opera', 'maxthon', 'ucbrowser' );

	/**
	 * Valores de `operatingSystems` de Divi (`Conditions::_get_operating_system`).
	 *
	 * @var array<int, string>
	 */
	private const SYSTEMS = array( 'windows', 'macos', 'linux', 'android', 'iphone', 'ipad', 'ipod', 'appletv', 'playstation', 'xbox', 'nintendo', 'webos' );

	/**
	 * Días de la semana de Divi (`strtolower( format( 'l' ) )`), en su orden canónico.
	 *
	 * @var array<int, string>
	 */
	private const WEEKDAYS = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );

	/**
	 * Taxonomía de cada tipo de términos.
	 *
	 * @var array<string, string>
	 */
	private const TAXONOMY = array(
		'categories' => 'category',
		'tags'       => 'post_tag',
	);

	/**
	 * Tipo de contenido: fn( string ): array{public: bool, label: string}|null.
	 *
	 * @var callable
	 */
	private $post_types;

	/**
	 * Nombre de un rol o null: fn( string ): string|null.
	 *
	 * @var callable
	 */
	private $roles;

	/**
	 * Nombre de un término o null: fn( int, string ): string|null.
	 *
	 * @var callable
	 */
	private $term_label;

	/**
	 * Entrada publicada: fn( int ): array{type: string, label: string}|null.
	 *
	 * @var callable
	 */
	private $posts;

	/**
	 * Nombre de un producto o null: fn( int ): string|null.
	 *
	 * @var callable
	 */
	private $products;

	/**
	 * WooCommerce activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $woo_active;

	/**
	 * Generador de `id`: fn(): string.
	 *
	 * @var callable
	 */
	private $new_id;

	/**
	 * Construye el esquema; cada función tiene su fuente de producción por omisión.
	 *
	 * @param callable|null $post_types fn( string ): array{public: bool, label: string}|null.
	 * @param callable|null $roles      fn( string ): string|null.
	 * @param callable|null $term_label fn( int, string ): string|null.
	 * @param callable|null $posts      fn( int ): array{type: string, label: string}|null (sólo publicadas).
	 * @param callable|null $products   fn( int ): string|null.
	 * @param callable|null $woo_active fn(): bool.
	 * @param callable|null $new_id     fn(): string (UUID v4).
	 */
	public function __construct( ?callable $post_types = null, ?callable $roles = null, ?callable $term_label = null, ?callable $posts = null, ?callable $products = null, ?callable $woo_active = null, ?callable $new_id = null ) {
		$this->post_types = $post_types ?? array( DiviLoopSchema::class, 'registered_post_type' );
		$this->roles      = $roles ?? array( self::class, 'registered_role' );
		$this->term_label = $term_label ?? array( DiviLoopSchema::class, 'registered_term_label' );
		$this->posts      = $posts ?? array( self::class, 'registered_post' );
		$this->products   = $products ?? array( self::class, 'registered_product' );
		$this->woo_active = $woo_active ?? static fn(): bool => class_exists( 'WooCommerce' );
		$this->new_id     = $new_id ?? array( self::class, 'random_id' );
	}

	/**
	 * Valida el conjunto del cliente y devuelve los parámetros normalizados.
	 *
	 * @param mixed $input `{match: all|any, conditions: [{type, …}]}`; lista vacía = quitar todas.
	 *
	 * @return array{match: string, conditions: array<int, array<string, mixed>>}|\WP_Error
	 */
	public function validate( $input ) {
		if ( ! is_array( $input ) || array() === $input || array_is_list( $input ) ) {
			return $this->invalid( 'conditions', __( 'debe ser un objeto {match: all|any, conditions: [{type, …}]} (o null para quitar todas).', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( (string) $key, array( 'match', 'conditions' ), true ) ) {
				return $this->invalid( (string) $key, __( 'no se admite. Admitidas: match, conditions.', 'nexo-mcp' ) );
			}
		}

		$list = $input['conditions'] ?? null;

		if ( ! is_array( $list ) || ( array() !== $list && ! array_is_list( $list ) ) ) {
			return $this->invalid( 'conditions', __( 'debe ser una lista de condiciones (vacía para quitarlas todas).', 'nexo-mcp' ) );
		}

		if ( count( $list ) > self::MAX_CONDITIONS ) {
			return $this->invalid( 'conditions', sprintf( /* translators: %d: máximo. */ __( 'admite hasta %d condiciones por módulo.', 'nexo-mcp' ), self::MAX_CONDITIONS ) );
		}

		$match = $input['match'] ?? ( array() === $list ? 'all' : null );

		if ( ! in_array( $match, array( 'all', 'any' ), true ) ) {
			return $this->invalid( 'match', __( 'debe ser all (todas se cumplen) o any (basta una).', 'nexo-mcp' ) );
		}

		$out   = array();
		$types = array();

		foreach ( $list as $index => $condition ) {
			$checked = $this->check_condition( 'conditions.' . $index, $condition );

			if ( $checked instanceof \WP_Error ) {
				return $checked;
			}

			$name = $this->condition_name( $checked );

			if ( 'any' === $match && isset( $types[ $name ] ) && ! in_array( $checked['type'], array( 'date_time', 'url_parameter' ), true ) ) {
				return $this->invalid( 'conditions.' . $index . '.type', __( 'con match any Divi descarta la segunda condición del mismo tipo: agrupa los valores en una sola o usa match all.', 'nexo-mcp' ) );
			}

			$types[ $name ] = true;
			$out[]          = $checked;
		}

		return array(
			'match'      => $match,
			'conditions' => $out,
		);
	}

	/**
	 * Valor guardado en `module.decoration.conditions.desktop.value` para los parámetros ya validados.
	 *
	 * @param array{match: string, conditions: array<int, array<string, mixed>>} $params   Resultado de {@see self::validate()}.
	 * @param mixed                                                              $existing Valor guardado antes: se conserva el `id` de la misma posición si la condición no cambió.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function stored( array $params, $existing = null ): array {
		$ids = array();
		$old = is_array( $existing ) && array_is_list( $existing ) ? $existing : array();

		foreach ( array_keys( $params['conditions'] ) as $index ) {
			$id    = null;
			$prior = $old[ $index ] ?? null;

			if ( is_array( $prior ) && is_string( $prior['id'] ?? null ) && 1 === preg_match( self::ID_PATTERN, $prior['id'] ) && ! in_array( $prior['id'], $ids, true ) ) {
				$same = $this->build( array_merge( $params, array( 'conditions' => array( $params['conditions'][ $index ] ) ) ), array( $prior['id'] ) );

				if ( self::canonical( self::without_labels( $same ) ) === self::canonical( self::without_labels( array( $prior ) ) ) ) {
					$id = $prior['id'];
				}
			}

			$ids[ $index ] = $id ?? $this->fresh_id( $ids, $old );
		}

		return $this->build( $params, array_values( $ids ) );
	}

	/**
	 * Reconoce un valor guardado como hecho por el conector: sólo si es EXACTAMENTE lo que {@see self::stored()}
	 * escribiría (salvo etiquetas). Claves de más, tipos fuera del catálogo, ids que ya no existen, operadores mezclados,
	 * `enableCondition: off`, `id` ajenos o repetidos → null.
	 *
	 * @param mixed $value Valor de `module.decoration.conditions.desktop.value`.
	 *
	 * @return array{match: string, conditions: array<int, array<string, mixed>>}|null
	 */
	public function valid_stored( $value ): ?array {
		$read = $this->read( $value );

		if ( null === $read ) {
			return null;
		}

		$params = $this->validate(
			array(
				'match'      => $read['match'],
				'conditions' => $read['conditions'],
			)
		);

		if ( $params instanceof \WP_Error || array() === $params['conditions'] ) {
			return null;
		}

		if ( self::canonical( self::without_labels( $this->build( $params, $read['ids'] ) ) ) !== self::canonical( self::without_labels( $value ) ) ) {
			return null;
		}

		return $params;
	}

	/**
	 * Resumen seguro de cualquier lista guardada (propia, del Visual Builder o rara). Del tipo sólo se muestra el nombre
	 * (si es un identificador corto); de los ajustes, sólo valores de listas cerradas, ids numéricos y slugs válidos de los
	 * tipos del catálogo: nunca textos libres, y de los excluidos o desconocidos, nada más que su nombre.
	 *
	 * @param mixed $value Valor de `module.decoration.conditions.desktop.value`.
	 *
	 * @return array{ours: bool, match: string|null, total: int, conditions: array<int, array{type: string, operator: string|null, enabled: bool, supported: bool, summary: string}>}
	 */
	public function summary( $value ): array {
		$list    = is_array( $value ) && array_is_list( $value ) ? $value : array();
		$ops     = array();
		$entries = array();

		foreach ( array_slice( $list, 0, 50 ) as $entry ) {
			$entry    = is_array( $entry ) ? $entry : array();
			$name     = is_string( $entry['conditionName'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,40}\z/', $entry['conditionName'] ) ? $entry['conditionName'] : 'unknown';
			$settings = is_array( $entry['conditionSettings'] ?? null ) ? $entry['conditionSettings'] : array();
			$operator = is_string( $entry['operator'] ?? null ) && in_array( $entry['operator'], array( 'AND', 'OR' ), true ) ? $entry['operator'] : null;
			$input    = $this->read_condition( $entry );
			$ops[]    = $operator ?? 'OR';

			$entries[] = array(
				'type'      => $name,
				'operator'  => $operator,
				'enabled'   => 'off' !== ( $settings['enableCondition'] ?? null ),
				'supported' => null !== $input,
				'summary'   => null !== $input ? $this->describe( $input ) : $name,
			);
		}

		$uniform = array() !== $ops && 1 === count( array_unique( $ops ) );

		return array(
			'ours'       => null !== $this->valid_stored( $value ),
			'match'      => $uniform ? ( 'AND' === $ops[0] ? 'all' : 'any' ) : null,
			'total'      => count( $list ),
			'conditions' => $entries,
		);
	}

	/**
	 * `id` de condición nuevo.
	 */
	public function new_id(): string {
		return (string) ( $this->new_id )();
	}

	/**
	 * UUID v4 aleatorio en minúsculas.
	 */
	public static function random_id(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Nombre de un rol (fuente de producción), o null si no existe.
	 *
	 * @param string $role Slug.
	 */
	public static function registered_role( string $role ): ?string {
		$names = wp_roles()->get_names();

		return isset( $names[ $role ] ) ? translate_user_role( (string) $names[ $role ] ) : null;
	}

	/**
	 * Entrada publicada (fuente de producción), o null.
	 *
	 * @param int $id Id.
	 *
	 * @return array{type: string, label: string}|null
	 */
	public static function registered_post( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		return array(
			'type'  => $post->post_type,
			'label' => $post->post_title,
		);
	}

	/**
	 * Nombre de un producto (fuente de producción), o null si no existe o WooCommerce no está.
	 *
	 * @param int $id Id.
	 */
	public static function registered_product( int $id ): ?string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $id );

		return is_object( $product ) ? (string) $product->get_name() : null;
	}

	/**
	 * Valida una condición de la entrada.
	 *
	 * @param string $at        Ruta (`conditions.2`).
	 * @param mixed  $condition Valor recibido.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_condition( string $at, $condition ) {
		if ( ! is_array( $condition ) || array() === $condition || array_is_list( $condition ) ) {
			return $this->invalid( $at, __( 'debe ser un objeto {type, …ajustes}.', 'nexo-mcp' ) );
		}

		$type = $condition['type'] ?? null;

		if ( ! is_string( $type ) || ! isset( self::KEYS[ $type ] ) ) {
			$excluded = is_string( $type ) && in_array( $type, self::EXCLUDED, true );

			return $this->invalid(
				$at . '.type',
				sprintf(
					/* translators: %s: tipos. */
					$excluded ? __( 'ese tipo no se admite (lee datos que controla el visitante, datos privados o visitas que la API no registra). Admitidos: %s.', 'nexo-mcp' ) : __( 'debe ser uno de: %s.', 'nexo-mcp' ),
					implode( ', ', array_keys( self::KEYS ) )
				)
			);
		}

		foreach ( array_keys( $condition ) as $key ) {
			if ( 'type' !== $key && ! in_array( (string) $key, self::KEYS[ $type ], true ) ) {
				return $this->invalid( $at . '.' . $key, sprintf( /* translators: 1: tipo, 2: claves. */ __( 'no se admite en «%1$s». Admitidas: %2$s.', 'nexo-mcp' ), $type, implode( ', ', array_merge( array( 'type' ), self::KEYS[ $type ] ) ) ) );
			}
		}

		if ( in_array( $type, array( 'cart_contents', 'product_stock', 'product_purchase' ), true ) && ! (bool) ( $this->woo_active )() ) {
			return $this->invalid( $at . '.type', __( 'WooCommerce no está activo en este sitio.', 'nexo-mcp' ) );
		}

		$rule = $condition['rule'] ?? null;

		if ( ! is_string( $rule ) || ! isset( self::RULES[ $type ][ $rule ] ) ) {
			return $this->invalid( $at . '.rule', sprintf( /* translators: %s: valores. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ' | ', array_keys( self::RULES[ $type ] ) ) ) );
		}

		$out = array(
			'type' => $type,
			'rule' => $rule,
		);
		$res = $this->check_settings( $at, $type, $rule, $condition );

		return $res instanceof \WP_Error ? $res : array_merge( $out, $res );
	}

	/**
	 * Ajustes propios de cada tipo.
	 *
	 * @param string               $at        Ruta.
	 * @param string               $type      Tipo.
	 * @param string               $rule      Regla de entrada.
	 * @param array<string, mixed> $condition Condición recibida.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_settings( string $at, string $type, string $rule, array $condition ) {
		switch ( $type ) {
			case 'user_role':
				$roles = $this->check_list( $at . '.roles', $condition['roles'] ?? null, self::MAX_ROLES, fn( $v ) => is_string( $v ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,60}\z/', $v ) && null !== ( $this->roles )( $v ), __( 'roles que existan en el sitio (slug)', 'nexo-mcp' ) );

				return $roles instanceof \WP_Error ? $roles : array( 'roles' => $roles );

			case 'post_type':
				$types = $this->check_list( $at . '.post_types', $condition['post_types'] ?? null, self::MAX_ROLES, fn( $v ) => is_string( $v ) && 1 === preg_match( '/^[a-z0-9_\-]{1,20}\z/', $v ) && $this->is_public_type( $v ), __( 'tipos de contenido públicos del sitio', 'nexo-mcp' ) );

				return $types instanceof \WP_Error ? $types : array( 'post_types' => $types );

			case 'categories':
			case 'tags':
				$taxonomy = self::TAXONOMY[ $type ];
				$terms    = $this->check_list( $at . '.terms', $condition['terms'] ?? null, self::MAX_ITEMS, fn( $v ) => is_int( $v ) && $v > 0 && null !== ( $this->term_label )( $v, $taxonomy ), sprintf( /* translators: %s: taxonomía. */ __( 'ids de términos que existan en «%s»', 'nexo-mcp' ), $taxonomy ) );

				return $terms instanceof \WP_Error ? $terms : array( 'terms' => $terms );

			case 'posts':
				return $this->check_posts( $at, $condition );

			case 'browser':
				$list = $this->check_list( $at . '.browsers', $condition['browsers'] ?? null, count( self::BROWSERS ), static fn( $v ) => is_string( $v ) && in_array( $v, self::BROWSERS, true ), implode( ' | ', self::BROWSERS ) );

				return $list instanceof \WP_Error ? $list : array( 'browsers' => $list );

			case 'operating_system':
				$list = $this->check_list( $at . '.systems', $condition['systems'] ?? null, count( self::SYSTEMS ), static fn( $v ) => is_string( $v ) && in_array( $v, self::SYSTEMS, true ), implode( ' | ', self::SYSTEMS ) );

				return $list instanceof \WP_Error ? $list : array( 'systems' => $list );

			case 'url_parameter':
				return $this->check_url_parameter( $at, $rule, $condition );

			case 'cart_contents':
			case 'product_stock':
			case 'product_purchase':
				return $this->check_products( $at, $type, $rule, $condition );

			case 'date_time':
				return $this->check_date_time( $at, $rule, $condition );

			default:
				return array();
		}
	}

	/**
	 * Posts concretos de un tipo público.
	 *
	 * @param string               $at        Ruta.
	 * @param array<string, mixed> $condition Condición.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_posts( string $at, array $condition ) {
		$type = $condition['post_type'] ?? null;

		if ( ! is_string( $type ) || 1 !== preg_match( '/^[a-z0-9_\-]{1,20}\z/', $type ) || in_array( $type, array_merge( self::DIVI_NAMES, array( 'any', 'attachment' ) ), true ) || ! $this->is_public_type( $type ) ) {
			return $this->invalid( $at . '.post_type', __( 'debe ser un tipo de contenido público del sitio (por ejemplo post, page o product).', 'nexo-mcp' ) );
		}

		$posts = $this->check_list(
			$at . '.posts',
			$condition['posts'] ?? null,
			self::MAX_ITEMS,
			function ( $v ) use ( $type ) {
				$info = is_int( $v ) && $v > 0 ? ( $this->posts )( $v ) : null;

				return is_array( $info ) && ( $info['type'] ?? null ) === $type;
			},
			sprintf( /* translators: %s: tipo. */ __( 'ids de contenido «%s» publicado', 'nexo-mcp' ), $type )
		);

		return $posts instanceof \WP_Error ? $posts : array(
			'post_type' => $type,
			'posts'     => $posts,
		);
	}

	/**
	 * Parámetro de URL.
	 *
	 * @param string               $at        Ruta.
	 * @param string               $rule      Regla.
	 * @param array<string, mixed> $condition Condición.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_url_parameter( string $at, string $rule, array $condition ) {
		$name = $condition['name'] ?? null;

		if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,40}\z/', $name ) ) {
			return $this->invalid( $at . '.name', __( 'debe ser un nombre de parámetro de 1 a 40 caracteres [A-Za-z0-9_-].', 'nexo-mcp' ) );
		}

		$out = array( 'name' => $name );

		if ( ! in_array( $rule, self::NEEDS_VALUE, true ) ) {
			return array_key_exists( 'value', $condition ) ? $this->invalid( $at . '.value', sprintf( /* translators: %s: regla. */ __( 'no se admite con la regla «%s».', 'nexo-mcp' ), $rule ) ) : $out;
		}

		$value = $condition['value'] ?? null;

		if ( ! is_string( $value ) || '' === $value || mb_strlen( $value ) > self::MAX_TEXT || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[<>\[\]$\\\\\x00-\x1F\x7F]|%[0-9a-f]{2}| {2,}/i', $value ) || trim( $value ) !== $value ) {
			return $this->invalid( $at . '.value', sprintf( /* translators: %d: máximo. */ __( 'debe ser un texto de 1 a %d caracteres, sin < > [ ] $ \\ ni control, sin %%xx, sin espacios dobles ni en los extremos.', 'nexo-mcp' ), self::MAX_TEXT ) );
		}

		return $out + array( 'value' => $value );
	}

	/**
	 * Productos de WooCommerce.
	 *
	 * @param string               $at        Ruta.
	 * @param string               $type      Tipo.
	 * @param string               $rule      Regla.
	 * @param array<string, mixed> $condition Condición.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_products( string $at, string $type, string $rule, array $condition ) {
		if ( ! in_array( $rule, self::NEEDS_PRODUCTS[ $type ], true ) ) {
			return array_key_exists( 'products', $condition ) ? $this->invalid( $at . '.products', sprintf( /* translators: %s: regla. */ __( 'no se admite con la regla «%s».', 'nexo-mcp' ), $rule ) ) : array();
		}

		$list = $this->check_list( $at . '.products', $condition['products'] ?? null, self::MAX_ITEMS, fn( $v ) => is_int( $v ) && $v > 0 && null !== ( $this->products )( $v ), __( 'ids de productos que existan', 'nexo-mcp' ) );

		return $list instanceof \WP_Error ? $list : array( 'products' => $list );
	}

	/**
	 * Fecha y hora. Divi hace `new DateTimeImmutable()` y `modify()` sin `try`: sólo pasan fechas reales y `HH:MM`.
	 *
	 * @param string               $at        Ruta.
	 * @param string               $rule      Regla.
	 * @param array<string, mixed> $condition Condición.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_date_time( string $at, string $rule, array $condition ) {
		$allowed = array(
			'before'  => array( 'date', 'time' ),
			'after'   => array( 'date', 'time' ),
			'on_date' => array( 'date', 'all_day', 'from_time', 'until_time' ),
			'on_days' => array( 'weekdays', 'all_day', 'from_time', 'until_time' ),
		)[ $rule ];

		foreach ( self::KEYS['date_time'] as $key ) {
			if ( 'rule' !== $key && array_key_exists( $key, $condition ) && ! in_array( $key, $allowed, true ) ) {
				return $this->invalid( $at . '.' . $key, sprintf( /* translators: %s: regla. */ __( 'no se admite con la regla «%s».', 'nexo-mcp' ), $rule ) );
			}
		}

		$out = array();

		if ( in_array( 'date', $allowed, true ) ) {
			if ( ! is_string( $condition['date'] ?? null ) || ! self::real_date( $condition['date'] ) ) {
				return $this->invalid( $at . '.date', __( 'debe ser una fecha real Y-m-d (por ejemplo 2026-12-24) entre 1970 y 2100.', 'nexo-mcp' ) );
			}

			$out['date'] = $condition['date'];
		}

		if ( in_array( 'time', $allowed, true ) ) {
			$time = array_key_exists( 'time', $condition ) ? $condition['time'] : '00:00';

			if ( ! self::real_time( $time ) ) {
				return $this->invalid( $at . '.time', __( 'debe ser una hora HH:MM (00:00 a 23:59).', 'nexo-mcp' ) );
			}

			$out['time'] = $time;
		}

		if ( in_array( 'weekdays', $allowed, true ) ) {
			$days = $this->check_list( $at . '.weekdays', $condition['weekdays'] ?? null, 7, static fn( $v ) => is_string( $v ) && in_array( $v, self::WEEKDAYS, true ), implode( ' | ', self::WEEKDAYS ) );

			if ( $days instanceof \WP_Error ) {
				return $days;
			}

			$out['weekdays'] = array_values( array_intersect( self::WEEKDAYS, $days ) );
		}

		if ( in_array( 'all_day', $allowed, true ) ) {
			$all_day = array_key_exists( 'all_day', $condition ) ? $condition['all_day'] : true;

			if ( ! is_bool( $all_day ) ) {
				return $this->invalid( $at . '.all_day', __( 'debe ser true o false.', 'nexo-mcp' ) );
			}

			$out['all_day'] = $all_day;

			if ( $all_day ) {
				foreach ( array( 'from_time', 'until_time' ) as $key ) {
					if ( array_key_exists( $key, $condition ) ) {
						return $this->invalid( $at . '.' . $key, __( 'sólo se admite con all_day false.', 'nexo-mcp' ) );
					}
				}
			} else {
				foreach ( array( 'from_time', 'until_time' ) as $key ) {
					if ( ! self::real_time( $condition[ $key ] ?? null ) ) {
						return $this->invalid( $at . '.' . $key, __( 'es obligatoria con all_day false y debe ser una hora HH:MM (00:00 a 23:59).', 'nexo-mcp' ) );
					}

					$out[ $key ] = $condition[ $key ];
				}

				if ( strcmp( (string) $out['from_time'], (string) $out['until_time'] ) >= 0 ) {
					return $this->invalid( $at . '.until_time', __( 'debe ser posterior a from_time (la franja no cruza la medianoche).', 'nexo-mcp' ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Lista no vacía, sin repetidos, de valores que cumplen `$ok`.
	 *
	 * @param string   $at   Ruta.
	 * @param mixed    $items Valor recibido.
	 * @param int      $max  Tope.
	 * @param callable $ok   fn( mixed ): bool.
	 * @param string   $what Qué se espera.
	 *
	 * @return array<int, mixed>|\WP_Error
	 */
	private function check_list( string $at, $items, int $max, callable $ok, string $what ) {
		if ( ! is_array( $items ) || array() === $items || ! array_is_list( $items ) || count( $items ) > $max ) {
			return $this->invalid( $at, sprintf( /* translators: 1: qué, 2: máximo. */ __( 'debe ser una lista de 1 a %2$d: %1$s.', 'nexo-mcp' ), $what, $max ) );
		}

		$seen = array();

		foreach ( $items as $item ) {
			if ( ! $ok( $item ) ) {
				return $this->invalid( $at, sprintf( /* translators: %s: qué. */ __( 'valor no válido; se espera %s.', 'nexo-mcp' ), $what ) );
			}

			$seen[ (string) $item ] = $item;
		}

		return array_values( $seen );
	}

	/**
	 * Tipo de contenido público registrado.
	 *
	 * @param string $type Tipo.
	 */
	private function is_public_type( string $type ): bool {
		$info = ( $this->post_types )( $type );

		return is_array( $info ) && ! empty( $info['public'] );
	}

	/**
	 * Fecha Y-m-d real entre 1970 y 2100.
	 *
	 * @param string $date Valor.
	 */
	private static function real_date( string $date ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m ) ) {
			return false;
		}

		return (int) $m[1] >= 1970 && (int) $m[1] <= 2100 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Hora HH:MM.
	 *
	 * @param mixed $time Valor.
	 */
	private static function real_time( $time ): bool {
		return is_string( $time ) && 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $time );
	}

	/**
	 * `conditionName` de una condición ya validada.
	 *
	 * @param array<string, mixed> $c Condición normalizada.
	 */
	private function condition_name( array $c ): string {
		return 'posts' === $c['type'] ? (string) $c['post_type'] : self::TYPES[ (string) $c['type'] ];
	}

	/**
	 * Arma la lista guardada (sin decidir `id`s).
	 *
	 * @param array{match: string, conditions: array<int, array<string, mixed>>} $params Parámetros validados.
	 * @param array<int, string>                                                 $ids    `id` por posición.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build( array $params, array $ids ): array {
		$out = array();

		foreach ( array_values( $params['conditions'] ) as $index => $c ) {
			$out[] = array(
				'id'                => $ids[ $index ],
				'conditionName'     => $this->condition_name( $c ),
				'conditionSettings' => $this->settings( $c ),
				'operator'          => 'all' === $params['match'] ? 'AND' : 'OR',
			);
		}

		return $out;
	}

	/**
	 * `conditionSettings` de una condición validada, en el orden del Visual Builder.
	 *
	 * @param array<string, mixed> $c Condición normalizada.
	 *
	 * @return array<string, mixed>
	 */
	private function settings( array $c ): array {
		$type = (string) $c['type'];
		$rule = self::RULES[ $type ][ (string) $c['rule'] ];
		$on   = array( 'enableCondition' => 'on' );

		switch ( $type ) {
			case 'user_role':
				return array(
					'displayRule' => $rule,
					'userRoles'   => array_map(
						fn( $role ) => array(
							'value' => $role,
							'label' => (string) ( $this->roles )( $role ),
						),
						$c['roles']
					),
					'userIds'     => '',
				) + $on;

			case 'post_type':
				return array(
					'displayRule' => $rule,
					'postTypes'   => array_map(
						fn( $t ) => array(
							'value' => $t,
							'label' => $this->type_label( $t ),
						),
						$c['post_types']
					),
				) + $on;

			case 'categories':
			case 'tags':
				$taxonomy = self::TAXONOMY[ $type ];

				return array(
					'displayRule' => $rule,
					$type         => array_map(
						fn( $id ) => array(
							'value'     => $id,
							'label'     => (string) ( $this->term_label )( $id, $taxonomy ),
							'groupSlug' => $taxonomy,
						),
						$c['terms']
					),
				) + $on;

			case 'posts':
				return array(
					'displayRule'   => $rule,
					'dynamicPosts'  => array_map(
						fn( $id ) => array(
							'value' => $id,
							'label' => (string) ( ( $this->posts )( $id )['label'] ?? '' ),
						),
						$c['posts']
					),
					'postTypeLabel' => $this->type_label( (string) $c['post_type'] ),
				) + $on;

			case 'browser':
				return array(
					'displayRule' => $rule,
					'browsers'    => $c['browsers'],
				) + $on;

			case 'operating_system':
				return array(
					'displayRule'      => $rule,
					'operatingSystems' => $c['systems'],
				) + $on;

			case 'url_parameter':
				return array(
					'selectUrlParameter' => 'specificUrlParameter',
					'displayRule'        => $rule,
					'urlParameterName'   => $c['name'],
					'urlParameterValue'  => $c['value'] ?? '',
				) + $on;

			case 'cart_contents':
			case 'product_stock':
			case 'product_purchase':
				$base = array( 'displayRule' => $rule );

				if ( isset( $c['products'] ) ) {
					$base['products'] = array_map(
						fn( $id ) => array(
							'value' => $id,
							'label' => (string) ( $this->products )( $id ),
						),
						$c['products']
					);
				}

				return $base + $on;

			case 'date_time':
				return $this->date_time_settings( $c, $rule );

			default:
				return array( 'displayRule' => $rule ) + $on;
		}
	}

	/**
	 * Ajustes de `dateTime` con la forma completa del Visual Builder (la repetición siempre apagada).
	 *
	 * @param array<string, mixed> $c    Condición normalizada.
	 * @param string               $rule `displayRule` de Divi.
	 *
	 * @return array<string, mixed>
	 */
	private function date_time_settings( array $c, string $rule ): array {
		$date = (string) ( $c['date'] ?? '' );

		return array(
			'displayRule'                 => $rule,
			'enableCondition'             => 'on',
			'date'                        => $date,
			'time'                        => (string) ( $c['time'] ?? '00:00' ),
			'allDay'                      => ( $c['all_day'] ?? true ) ? 'on' : 'off',
			'fromTime'                    => (string) ( $c['from_time'] ?? '00:00' ),
			'untilTime'                   => (string) ( $c['until_time'] ?? '00:00' ),
			'repeat'                      => 'off',
			'repeatFrequency'             => 'monthly',
			'repeatEnd'                   => 'never',
			'repeatUntilDate'             => $date,
			'repeatTimes'                 => '3',
			'weekdays'                    => $c['weekdays'] ?? array(),
			'repeatFrequencySpecificDays' => 'weekly',
		);
	}

	/**
	 * Nombre de un tipo de contenido.
	 *
	 * @param string $type Tipo.
	 */
	private function type_label( string $type ): string {
		$info = ( $this->post_types )( $type );

		return is_array( $info ) && is_string( $info['label'] ?? null ) && '' !== $info['label'] ? $info['label'] : $type;
	}

	/**
	 * `id` nuevo, distinto de los ya elegidos y de los de la lista anterior.
	 *
	 * @param array<int, string> $taken Ya elegidos.
	 * @param array<int, mixed>  $old   Lista anterior.
	 */
	private function fresh_id( array $taken, array $old ): string {
		$avoid = $taken;

		foreach ( $old as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) ) {
				$avoid[] = $entry['id'];
			}
		}

		for ( $i = 0; $i < 10; $i++ ) {
			$id = $this->new_id();

			if ( 1 === preg_match( self::ID_PATTERN, $id ) && ! in_array( $id, $avoid, true ) ) {
				return $id;
			}
		}

		do {
			$id = self::random_id();
		} while ( in_array( $id, $avoid, true ) );

		return $id;
	}

	/**
	 * Lee una lista guardada en la forma de entrada: sólo extrae; la comparación exacta la hace {@see self::valid_stored()}.
	 *
	 * @param mixed $value Valor guardado.
	 *
	 * @return array{match: string, conditions: array<int, array<string, mixed>>, ids: array<int, string>}|null
	 */
	private function read( $value ): ?array {
		if ( ! is_array( $value ) || array() === $value || ! array_is_list( $value ) || count( $value ) > self::MAX_CONDITIONS ) {
			return null;
		}

		$conditions = array();
		$ids        = array();
		$operator   = $value[0]['operator'] ?? null;

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['operator'] ?? null ) !== $operator || ! in_array( $operator, array( 'AND', 'OR' ), true ) || ! is_string( $entry['id'] ?? null ) || 1 !== preg_match( self::ID_PATTERN, $entry['id'] ) || in_array( $entry['id'], $ids, true ) ) {
				return null;
			}

			$input = $this->read_condition( $entry );

			if ( null === $input ) {
				return null;
			}

			$conditions[] = $input;
			$ids[]        = $entry['id'];
		}

		return array(
			'match'      => 'AND' === $operator ? 'all' : 'any',
			'conditions' => $conditions,
			'ids'        => $ids,
		);
	}

	/**
	 * Una condición guardada → forma de entrada (sin validar existencia), o null si su nombre o forma no son del catálogo.
	 *
	 * @param array<mixed, mixed> $entry Condición guardada.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read_condition( array $entry ): ?array {
		$name = $entry['conditionName'] ?? null;
		$set  = $entry['conditionSettings'] ?? null;

		if ( ! is_string( $name ) || ! is_array( $set ) ) {
			return null;
		}

		$type = array_search( $name, self::TYPES, true );

		if ( false === $type ) {
			// «Posts concretos»: el nombre es el tipo de contenido.
			if ( in_array( $name, self::DIVI_NAMES, true ) || ! is_array( $set['dynamicPosts'] ?? null ) ) {
				return null;
			}

			return $this->with_rule(
				'posts',
				$set,
				array(
					'type'      => 'posts',
					'post_type' => $name,
					'posts'     => self::values( $set['dynamicPosts'] ),
				)
			);
		}

		$type = (string) $type;
		$base = array( 'type' => $type );

		switch ( $type ) {
			case 'user_role':
				$base['roles'] = self::values( $set['userRoles'] ?? null );
				break;
			case 'post_type':
				$base['post_types'] = self::values( $set['postTypes'] ?? null );
				break;
			case 'categories':
			case 'tags':
				$base['terms'] = self::values( $set[ $type ] ?? null );
				break;
			case 'browser':
				$base['browsers'] = is_array( $set['browsers'] ?? null ) ? $set['browsers'] : null;
				break;
			case 'operating_system':
				$base['systems'] = is_array( $set['operatingSystems'] ?? null ) ? $set['operatingSystems'] : null;
				break;
			case 'url_parameter':
				$base['name'] = $set['urlParameterName'] ?? null;
				break;
			case 'cart_contents':
			case 'product_stock':
			case 'product_purchase':
				if ( is_array( $set['products'] ?? null ) ) {
					$base['products'] = self::values( $set['products'] );
				}
				break;
			case 'date_time':
				return $this->read_date_time( $set, $base );
			default:
				break;
		}

		$read = $this->with_rule( $type, $set, $base );

		if ( null !== $read && 'url_parameter' === $type && in_array( $read['rule'], self::NEEDS_VALUE, true ) ) {
			$read['value'] = $set['urlParameterValue'] ?? null;
		}

		return $read;
	}

	/**
	 * Añade `rule` (la inversa de `displayRule`) a una condición leída.
	 *
	 * @param string               $type Tipo.
	 * @param array<mixed, mixed>  $set  Ajustes guardados.
	 * @param array<string, mixed> $base Lectura parcial.
	 *
	 * @return array<string, mixed>|null
	 */
	private function with_rule( string $type, array $set, array $base ): ?array {
		$rule = is_string( $set['displayRule'] ?? null ) ? array_search( $set['displayRule'], self::RULES[ $type ], true ) : false;

		return false === $rule ? null : array_merge( $base, array( 'rule' => $rule ) );
	}

	/**
	 * `dateTime` guardado → entrada.
	 *
	 * @param array<mixed, mixed>  $set  Ajustes guardados.
	 * @param array<string, mixed> $base Lectura parcial.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read_date_time( array $set, array $base ): ?array {
		$read = $this->with_rule( 'date_time', $set, $base );

		if ( null === $read ) {
			return null;
		}

		$rule = $read['rule'];

		if ( in_array( $rule, array( 'before', 'after', 'on_date' ), true ) ) {
			$read['date'] = $set['date'] ?? null;
		}

		if ( in_array( $rule, array( 'before', 'after' ), true ) ) {
			$read['time'] = $set['time'] ?? null;
		} else {
			$read['all_day'] = 'off' === ( $set['allDay'] ?? null ) ? false : true;

			if ( false === $read['all_day'] ) {
				$read['from_time']  = $set['fromTime'] ?? null;
				$read['until_time'] = $set['untilTime'] ?? null;
			}

			if ( 'on_days' === $rule ) {
				$read['weekdays'] = $set['weekdays'] ?? null;
			}
		}

		return $read;
	}

	/**
	 * `value` de cada elemento `{value, label}` (null si no es una lista de objetos).
	 *
	 * @param mixed $items Lista guardada.
	 *
	 * @return array<int, mixed>|null
	 */
	private static function values( $items ): ?array {
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			return null;
		}

		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'value', $item ) ) {
				return null;
			}

			$out[] = $item['value'];
		}

		return $out;
	}

	/**
	 * Descripción segura de una condición reconocida: sólo reglas, ids y slugs ya validados por el patrón.
	 *
	 * @param array<string, mixed> $input Condición en forma de entrada.
	 */
	private function describe( array $input ): string {
		$type  = (string) $input['type'];
		$rule  = is_string( $input['rule'] ?? null ) && isset( self::RULES[ $type ][ $input['rule'] ] ) ? $input['rule'] : '?';
		$parts = array( $type . ' ' . $rule );

		foreach ( array( 'roles', 'post_types', 'terms', 'posts', 'browsers', 'systems', 'products', 'weekdays' ) as $key ) {
			if ( ! is_array( $input[ $key ] ?? null ) ) {
				continue;
			}

			$safe = array();

			foreach ( array_slice( $input[ $key ], 0, 20 ) as $item ) {
				if ( is_int( $item ) || ( is_string( $item ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,60}\z/', $item ) ) ) {
					$safe[] = (string) $item;
				}
			}

			$parts[] = $key . '=' . implode( ',', $safe );
		}

		if ( 'posts' === $type && is_string( $input['post_type'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,40}\z/', $input['post_type'] ) ) {
			$parts[] = 'post_type=' . $input['post_type'];
		}

		if ( 'url_parameter' === $type && is_string( $input['name'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,40}\z/', $input['name'] ) ) {
			$parts[] = 'name=' . $input['name'];
		}

		if ( 'date_time' === $type ) {
			foreach ( array(
				'date'       => 'real_date',
				'time'       => 'real_time',
				'from_time'  => 'real_time',
				'until_time' => 'real_time',
			) as $key => $check ) {
				if ( is_string( $input[ $key ] ?? null ) && self::$check( $input[ $key ] ) ) {
					$parts[] = $key . '=' . $input[ $key ];
				}
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Quita las claves `label` y `postTypeLabel` (textos de interfaz que cambian con el idioma o el título).
	 *
	 * @param mixed $value Valor guardado.
	 *
	 * @return mixed
	 */
	private static function without_labels( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		unset( $value['label'], $value['postTypeLabel'] );

		return array_map( array( self::class, 'without_labels' ), $value );
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
	 * Error de un campo.
	 *
	 * @param string $path   Ruta (`conditions.2.roles`).
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return new \WP_Error( self::CODE, $path . ': ' . $reason, array( 'path' => $path ) );
	}
}
