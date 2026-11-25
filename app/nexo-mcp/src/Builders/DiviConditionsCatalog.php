<?php
/**
 * Catálogo de las condiciones de visualización de Divi 5 que el conector deja escribir (S5), para `list-divi-conditions`.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Describe, sin escribir nada, lo que {@see DiviConditionsSchema} admite: tipos, reglas, ajustes con sus valores permitidos
 * y las listas vivas del sitio (roles, tipos de contenido públicos y si WooCommerce está activo). Las reglas y las claves
 * salen de {@see DiviConditionsSchema::definition()}, así que el catálogo no se desvía del esquema.
 */
final class DiviConditionsCatalog {

	/**
	 * Etiqueta y descripción de cada tipo.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const TYPES = array(
		'logged_in'        => array( 'Sesión', 'Visitante con o sin sesión iniciada.' ),
		'user_role'        => array( 'Rol', 'Rol EXACTO del usuario con sesión (no equivale a una capacidad). roles: slugs de roles del sitio (lista roles).' ),
		'date_time'        => array( 'Fecha y hora', 'Antes o después de una fecha (hora del sitio), en una fecha concreta o en días de la semana, con franja horaria opcional. Sin repeticiones.' ),
		'post_type'        => array( 'Tipo de contenido', 'Tipo del contenido que se está viendo (lista post_types).' ),
		'categories'       => array( 'Categorías', 'El contenido está (o no) en alguna de las categorías (taxonomía category; ids de término que existan; ver nexo-mcp-list-terms).' ),
		'tags'             => array( 'Etiquetas', 'El contenido tiene (o no) alguna de las etiquetas (taxonomía post_tag; ids de término que existan).' ),
		'posts'            => array( 'Contenidos concretos', 'El contenido que se está viendo es (o no) uno de estos contenidos publicados de UN tipo (post_type y ids publicados de ese tipo).' ),
		'browser'          => array( 'Navegador', 'Navegador por User-Agent (lo controla el visitante; no sirve para restringir).' ),
		'operating_system' => array( 'Sistema operativo', 'Sistema operativo por User-Agent (lo controla el visitante; no sirve para restringir). No es «tipo de dispositivo».' ),
		'url_parameter'    => array( 'Parámetro de URL', 'Un parámetro de la URL (?nombre=valor); lo controla el visitante.' ),
		'cart_contents'    => array( 'Carrito de WooCommerce', 'Contenido del carrito del visitante. Sólo con WooCommerce activo.' ),
		'product_stock'    => array( 'Existencias de WooCommerce', 'Productos con o sin existencias. Sólo con WooCommerce activo.' ),
		'product_purchase' => array( 'Compras de WooCommerce', 'Si el visitante con sesión compró (o no) productos. Sólo con WooCommerce activo.' ),
	);

	/**
	 * Tipos que requieren WooCommerce.
	 *
	 * @var array<int, string>
	 */
	private const WOO = array( 'cart_contents', 'product_stock', 'product_purchase' );

	/**
	 * Ajustes: tipo y descripción por clave de entrada.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SETTINGS = array(
		'roles'      => array( 'list<string>', '1 a 10 slugs de rol que existan.' ),
		'post_types' => array( 'list<string>', '1 a 10 tipos de contenido públicos.' ),
		'terms'      => array( 'list<integer>', '1 a 20 ids de término que existan en la taxonomía del tipo.' ),
		'post_type'  => array( 'string', 'Tipo de contenido público (no attachment).' ),
		'posts'      => array( 'list<integer>', '1 a 20 ids de contenido publicado del tipo post_type.' ),
		'browsers'   => array( 'list<string>', 'Valores de values.' ),
		'systems'    => array( 'list<string>', 'Valores de values.' ),
		'weekdays'   => array( 'list<string>', 'Con on_days: días de la semana, valores de values.' ),
		'products'   => array( 'list<integer>', '1 a 20 ids de productos que existan; sólo con las reglas que nombran productos (has_product, not_has_product, in_stock, out_of_stock, has_bought_product, has_not_bought_product).' ),
		'name'       => array( 'string', 'Nombre del parámetro: 1 a 40 caracteres [A-Za-z0-9_-].' ),
		'value'      => array( 'string', 'Sólo con equals, not_equals, contains y not_contains: 1 a 100 caracteres, sin < > [ ] $ \\ ni control.' ),
		'date'       => array( 'string', 'Con before, after y on_date: fecha real Y-m-d (1970-2100).' ),
		'time'       => array( 'string', 'Con before y after: hora HH:MM (por omisión 00:00).' ),
		'all_day'    => array( 'boolean', 'Con on_date y on_days: true por omisión; con false hacen falta from_time y until_time.' ),
		'from_time'  => array( 'string', 'Con all_day false: hora HH:MM, anterior a until_time.' ),
		'until_time' => array( 'string', 'Con all_day false: hora HH:MM, posterior a from_time.' ),
	);

	/**
	 * Roles: fn(): array<string, string> (slug => nombre).
	 *
	 * @var callable
	 */
	private $roles;

	/**
	 * Tipos de contenido públicos: fn(): array<string, string> (nombre => etiqueta).
	 *
	 * @var callable
	 */
	private $post_types;

	/**
	 * WooCommerce activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $woo_active;

	/**
	 * Construye el catálogo; cada función tiene su fuente de producción por omisión.
	 *
	 * @param callable|null $roles      fn(): array<string, string>.
	 * @param callable|null $post_types fn(): array<string, string>.
	 * @param callable|null $woo_active fn(): bool.
	 */
	public function __construct( ?callable $roles = null, ?callable $post_types = null, ?callable $woo_active = null ) {
		$this->roles      = $roles ?? array( self::class, 'registered_roles' );
		$this->post_types = $post_types ?? array( self::class, 'registered_post_types' );
		$this->woo_active = $woo_active ?? static fn(): bool => class_exists( 'WooCommerce' );
	}

	/**
	 * Roles del sitio (fuente de producción): slug => nombre.
	 *
	 * @return array<string, string>
	 */
	public static function registered_roles(): array {
		$out = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$out[ (string) $slug ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * Tipos de contenido públicos (fuente de producción): nombre => etiqueta.
	 *
	 * @return array<string, string>
	 */
	public static function registered_post_types(): array {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			$out[ (string) $name ] = (string) ( $object->labels->name ?? $name );
		}

		return $out;
	}

	/**
	 * El catálogo.
	 *
	 * @return array<string, mixed>
	 */
	public function listing(): array {
		$def    = DiviConditionsSchema::definition();
		$woo    = (bool) ( $this->woo_active )();
		$values = array(
			'browsers' => $def['browsers'],
			'systems'  => $def['systems'],
			'weekdays' => $def['weekdays'],
		);
		$types  = array();

		foreach ( self::TYPES as $type => $info ) {
			$settings = array();

			foreach ( $def['keys'][ $type ] as $key ) {
				if ( 'rule' === $key ) {
					continue;
				}

				$settings[ $key ] = array(
					'type'        => self::SETTINGS[ $key ][0],
					'description' => self::SETTINGS[ $key ][1],
				);

				if ( isset( $values[ $key ] ) ) {
					$settings[ $key ]['values'] = $values[ $key ];
				}
			}

			$types[] = array(
				'type'        => $type,
				'label'       => $info[0],
				'description' => $info[1],
				'rules'       => array_keys( $def['rules'][ $type ] ),
				'settings'    => $settings,
				'requires'    => in_array( $type, self::WOO, true ) ? 'woocommerce' : '',
				'available'   => ! in_array( $type, self::WOO, true ) || $woo,
			);
		}

		return array(
			'match'          => array( 'all', 'any' ),
			'max_conditions' => DiviConditionsSchema::MAX_CONDITIONS,
			'modules'        => DiviConditionsSchema::MODULES,
			'types'          => $types,
			'roles'          => self::pairs( ( $this->roles )(), 'slug' ),
			'post_types'     => self::pairs( ( $this->post_types )(), 'name' ),
			'woocommerce'    => $woo,
		);
	}

	/**
	 * Lista {slug|name, label} a partir de un mapa.
	 *
	 * @param mixed  $map Mapa clave => etiqueta.
	 * @param string $key Nombre de la clave en la salida.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function pairs( $map, string $key ): array {
		$out = array();

		foreach ( is_array( $map ) ? $map : array() as $id => $label ) {
			$out[] = array(
				$key    => (string) $id,
				'label' => (string) $label,
			);
		}

		return $out;
	}
}
