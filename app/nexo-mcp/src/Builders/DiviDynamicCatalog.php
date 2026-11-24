<?php
/**
 * Catálogo cerrado de fuentes de contenido dinámico de Divi 5 que el conector deja escribir.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lista cerrada (S6 de `odd/tasks/divi-dinamico.md`) con los nombres reales de las opciones de Divi 5, su categoría y
 * los ajustes que admite cada una.
 *
 * Decisión del usuario: «Entrada (título, extracto, fecha, autor, términos, enlace, imagen destacada), sitio (título,
 * lema, URL, logo), fecha actual, WooCommerce básico (título, precio, SKU, descripción corta) y campos de texto de ACF.
 * Fuera: meta personalizado, usuarios, enlaces a otros posts». El título de un producto es `post_title` (Divi no
 * registra un `product_title` fuera de los bucles). Es una constante, no una configuración: una opción que no esté aquí
 * no se escribe nunca (`post_meta_key`, `custom_meta_<clave>` de meta crudo, `loop_*`, `post_link_url_<tipo>`, el
 * perfil o la URL del autor, variables globales…).
 *
 * Un token es `$variable({"type":"content","value":{"name":…,"settings":{…}}})$` (la misma forma que escribe el
 * Visual Builder con `JSON.stringify`). Aquí se acepta sólo si es UN token completo (sin texto alrededor), de tipo
 * `content`, con una opción de la lista que Divi registre para esa página y con ajustes válidos; lo que se guarda es
 * la forma canónica que arma este catálogo, nunca la cadena del cliente. Los ajustes son acotados: texto plano sin
 * marcado, sin shortcodes, sin `$` ni `\` (no puede traer otro token), selects cerrados, `enable_html` siempre `off`.
 *
 * Lo que depende del sitio llega como funciones inyectables (Divi activo, opciones que Divi registra para una página,
 * campos de ACF, tipo de la página, taxonomías del tipo) para probar sin Divi, ACF ni WordPress.
 */
final class DiviDynamicCatalog {

	public const MAX_TEXT = 200;

	/**
	 * Prefijo con el que Divi nombra las fuentes de campos personalizados (aquí, sólo los de ACF).
	 */
	public const ACF_PREFIX = 'custom_meta_';

	/**
	 * Tipos de campo de ACF que se admiten.
	 *
	 * @var array<int, string>
	 */
	private const ACF_TYPES = array( 'text', 'textarea', 'number' );

	private const BEFORE_AFTER = array(
		'before' => array( 'type' => 'text' ),
		'after'  => array( 'type' => 'text' ),
	);

	/**
	 * Formato de fecha propio (`custom_date_format`): letras, números, espacio y , . : / - (sin `\`).
	 */
	private const DATE_PATTERN = '/^[A-Za-z0-9 ,.:\/\-]{1,40}\z/';

	/**
	 * Formatos de fecha del selector de Divi.
	 *
	 * @var array<int, string>
	 */
	private const DATE_FORMATS = array( 'default', 'M j, Y', 'F d, Y', 'm/d/Y', 'm.d.Y', 'j M, Y', 'l, M d', 'custom' );

	/**
	 * Fuentes: nombre de Divi → etiqueta, categoría (`text`, `url`, `image`), requisito y ajustes.
	 *
	 * `requires`: `''` (cualquier página), `taxonomy` (el tipo tiene taxonomías públicas) o `product` (la página es un producto de WooCommerce).
	 *
	 * @var array<string, array{label: string, category: string, requires: string, settings: array<string, array<string, mixed>>}>
	 */
	private const SOURCES = array(
		'post_title'                => array(
			'label'    => 'Título de la entrada o página (en un producto, el título del producto)',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER,
		),
		'post_excerpt'              => array(
			'label'    => 'Extracto de la entrada',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER + array(
				'words'           => array(
					'type' => 'number',
					'min'  => 1,
					'max'  => 500,
				),
				'read_more_label' => array( 'type' => 'text' ),
			),
		),
		'post_date'                 => array(
			'label'    => 'Fecha de publicación',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER + array(
				'date_format'        => array(
					'type'    => 'enum',
					'options' => self::DATE_FORMATS,
				),
				'custom_date_format' => array( 'type' => 'date_format' ),
			),
		),
		'post_categories'           => array(
			'label'    => 'Categorías de la entrada',
			'category' => 'text',
			'requires' => 'taxonomy',
			'settings' => self::BEFORE_AFTER + array(
				'link_to_term_page' => array( 'type' => 'onoff' ),
				'separator'         => array( 'type' => 'text' ),
				'category_type'     => array(
					'type'     => 'taxonomy',
					'defaults' => array( 'category', 'product_cat' ),
				),
			),
		),
		'post_tags'                 => array(
			'label'    => 'Etiquetas de la entrada',
			'category' => 'text',
			'requires' => 'taxonomy',
			'settings' => self::BEFORE_AFTER + array(
				'link_to_term_page' => array( 'type' => 'onoff' ),
				'separator'         => array( 'type' => 'text' ),
				'category_type'     => array(
					'type'     => 'taxonomy',
					'defaults' => array( 'post_tag', 'product_tag' ),
				),
			),
		),
		'post_link'                 => array(
			'label'    => 'Enlace a la entrada (texto con enlace)',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER + array(
				'text'        => array(
					'type'    => 'enum',
					'options' => array( 'post_title', 'custom' ),
				),
				'custom_text' => array( 'type' => 'text' ),
			),
		),
		'post_link_url'             => array(
			'label'    => 'URL de la entrada actual',
			'category' => 'url',
			'requires' => '',
			'settings' => array(),
		),
		'post_author'               => array(
			'label'    => 'Autor de la entrada',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER + array(
				'name_format'      => array(
					'type'    => 'enum',
					'options' => array( 'display_name', 'first_last_name', 'last_first_name', 'first_name', 'last_name', 'nickname' ),
				),
				'link'             => array( 'type' => 'onoff' ),
				'link_destination' => array(
					'type'    => 'enum',
					'options' => array( 'author_archive', 'author_website' ),
					'default' => 'author_archive',
					'when'    => array( 'link' => 'on' ),
				),
			),
		),
		'post_featured_image'       => array(
			'label'    => 'Imagen destacada',
			'category' => 'image',
			'requires' => '',
			'settings' => array(),
		),
		'site_title'                => array(
			'label'    => 'Título del sitio',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER,
		),
		'site_tagline'              => array(
			'label'    => 'Lema del sitio',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER,
		),
		'home_url'                  => array(
			'label'    => 'URL del sitio (página de inicio)',
			'category' => 'url',
			'requires' => '',
			'settings' => array(),
		),
		'site_logo'                 => array(
			'label'    => 'Logo del sitio',
			'category' => 'image',
			'requires' => '',
			'settings' => array(),
		),
		'current_date'              => array(
			'label'    => 'Fecha actual',
			'category' => 'text',
			'requires' => '',
			'settings' => self::BEFORE_AFTER + array(
				'date_format'        => array(
					'type'    => 'enum',
					'options' => self::DATE_FORMATS,
				),
				'custom_date_format' => array( 'type' => 'date_format' ),
			),
		),
		'product_price'             => array(
			'label'    => 'Precio del producto (WooCommerce)',
			'category' => 'text',
			'requires' => 'product',
			'settings' => self::BEFORE_AFTER,
		),
		'product_sku'               => array(
			'label'    => 'SKU del producto (WooCommerce)',
			'category' => 'text',
			'requires' => 'product',
			'settings' => self::BEFORE_AFTER,
		),
		'product_short_description' => array(
			'label'    => 'Descripción corta del producto (WooCommerce)',
			'category' => 'text',
			'requires' => 'product',
			'settings' => self::BEFORE_AFTER,
		),
	);

	/**
	 * Fuentes por elemento de un bucle del conector (S7): espejo de S6 con los nombres `loop_*` de Divi.
	 *
	 * `requires`: `loop` (cualquier bucle del conector), `loop_taxonomy` (el tipo del bucle tiene taxonomías públicas) o
	 * `loop_product` (el bucle es de productos de WooCommerce). Nunca usuarios, términos, menús ni meta personalizado:
	 * esas familias `loop_*` de Divi no están aquí y se rechazan siempre.
	 *
	 * @var array<string, array{label: string, category: string, requires: string, settings: array<string, array<string, mixed>>}>
	 */
	private const LOOP_SOURCES = array(
		'loop_post_title'                => array(
			'label'    => 'Título de cada elemento del bucle',
			'category' => 'text',
			'requires' => 'loop',
			'settings' => self::BEFORE_AFTER,
		),
		'loop_post_excerpt'              => array(
			'label'    => 'Extracto de cada elemento del bucle',
			'category' => 'text',
			'requires' => 'loop',
			'settings' => self::BEFORE_AFTER + array(
				'words'           => array(
					'type' => 'number',
					'min'  => 1,
					'max'  => 500,
				),
				'read_more_label' => array( 'type' => 'text' ),
			),
		),
		'loop_post_date'                 => array(
			'label'    => 'Fecha de publicación de cada elemento del bucle',
			'category' => 'text',
			'requires' => 'loop',
			'settings' => self::BEFORE_AFTER + array(
				'date_format'        => array(
					'type'    => 'enum',
					'options' => self::DATE_FORMATS,
				),
				'custom_date_format' => array( 'type' => 'date_format' ),
			),
		),
		'loop_post_author'               => array(
			'label'    => 'Autor (nombre público) de cada elemento del bucle',
			'category' => 'text',
			'requires' => 'loop',
			'settings' => self::BEFORE_AFTER + array(
				'name_format'      => array(
					'type'    => 'enum',
					'options' => array( 'display_name', 'first_last_name', 'last_first_name', 'first_name', 'last_name', 'nickname' ),
				),
				'link'             => array( 'type' => 'onoff' ),
				'link_destination' => array(
					'type'    => 'enum',
					'options' => array( 'author_archive', 'author_website' ),
					'default' => 'author_archive',
					'when'    => array( 'link' => 'on' ),
				),
			),
		),
		'loop_post_terms'                => array(
			'label'    => 'Términos (categorías, etiquetas…) de cada elemento del bucle',
			'category' => 'text',
			'requires' => 'loop_taxonomy',
			'settings' => self::BEFORE_AFTER + array(
				'links'         => array( 'type' => 'onoff' ),
				'separator'     => array( 'type' => 'text' ),
				'taxonomy_type' => array(
					'type'     => 'taxonomy',
					'defaults' => array( 'category', 'product_cat' ),
				),
			),
		),
		'loop_post_link'                 => array(
			'label'    => 'URL de cada elemento del bucle',
			'category' => 'url',
			'requires' => 'loop',
			'settings' => array(),
		),
		'loop_post_featured_image'       => array(
			'label'    => 'Imagen destacada de cada elemento del bucle',
			'category' => 'image',
			'requires' => 'loop',
			'settings' => array(),
		),
		'loop_product_title'             => array(
			'label'    => 'Título de cada producto del bucle (WooCommerce)',
			'category' => 'text',
			'requires' => 'loop_product',
			'settings' => self::BEFORE_AFTER,
		),
		'loop_product_price_current'     => array(
			'label'    => 'Precio actual de cada producto del bucle (WooCommerce)',
			'category' => 'text',
			'requires' => 'loop_product',
			'settings' => self::BEFORE_AFTER,
		),
		'loop_product_sku'               => array(
			'label'    => 'SKU de cada producto del bucle (WooCommerce)',
			'category' => 'text',
			'requires' => 'loop_product',
			'settings' => self::BEFORE_AFTER,
		),
		'loop_product_short_description' => array(
			'label'    => 'Descripción corta de cada producto del bucle (WooCommerce)',
			'category' => 'text',
			'requires' => 'loop_product',
			'settings' => self::BEFORE_AFTER,
		),
	);

	/**
	 * Divi 5 activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi_active;

	/**
	 * Opciones que Divi registra para una página: fn( int $post_id ): array<string, array{type?: string}>.
	 *
	 * @var callable
	 */
	private $registry;

	/**
	 * Campos de ACF de las páginas: fn( ?int $post_id ): array<int, array{name, label, type, group}>.
	 *
	 * @var callable
	 */
	private $acf_fields;

	/**
	 * Tipo de contenido de una página: fn( int $post_id ): string ('' si no existe).
	 *
	 * @var callable
	 */
	private $post_type_of;

	/**
	 * Taxonomías públicas de un tipo: fn( string $post_type ): array<int, string>.
	 *
	 * @var callable
	 */
	private $taxonomies_of;

	/**
	 * Opciones que Divi registró por página en este catálogo (o el error si falló): se consulta una vez por página.
	 *
	 * @var array<int, array<string, mixed>|\WP_Error>
	 */
	private array $registry_cache = array();

	/**
	 * Campos de ACF por página (`all` para todos los grupos) en este catálogo (o el error si falló).
	 *
	 * @var array<string, array<int, array{name: string, label: string, type: string, group: string}>|\WP_Error>
	 */
	private array $acf_cache = array();

	/**
	 * Construye el catálogo.
	 *
	 * @param callable|null $divi_active   fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null $registry      fn( int ): array<string, array{type?: string}>. Por omisión, `DynamicContentOptions::get_options( $id, 'display' )` de Divi.
	 * @param callable|null $acf_fields    fn( ?int ): array<int, array{name: string, label: string, type: string, group: string}>. Por omisión, la API de ACF (grupos activos que aplican a la página).
	 * @param callable|null $post_type_of  fn( int ): string. Por omisión, `get_post_type()`.
	 * @param callable|null $taxonomies_of fn( string ): array<int, string>. Por omisión, las taxonomías públicas del tipo.
	 */
	public function __construct( ?callable $divi_active = null, ?callable $registry = null, ?callable $acf_fields = null, ?callable $post_type_of = null, ?callable $taxonomies_of = null ) {
		$this->divi_active   = $divi_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->registry      = $registry ?? array( self::class, 'divi_registered_options' );
		$this->acf_fields    = $acf_fields ?? array( self::class, 'acf_registered_fields' );
		$this->post_type_of  = $post_type_of ?? static fn( int $post_id ): string => (string) get_post_type( $post_id );
		$this->taxonomies_of = $taxonomies_of ?? array( self::class, 'public_taxonomies' );
	}

	/**
	 * Nombres de las fuentes de la lista cerrada (sin las de ACF, que se resuelven por página).
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array_keys( self::SOURCES );
	}

	/**
	 * Nombres de las fuentes por elemento de un bucle del conector (`loop_*`), aparte de {@see self::names()}.
	 *
	 * @return array<int, string>
	 */
	public static function loop_names(): array {
		return array_keys( self::LOOP_SOURCES );
	}

	/**
	 * Si una fuente `loop_*` de la lista se puede usar en un bucle de ese tipo de contenido (los productos de
	 * WooCommerce sólo en un bucle de `product`; que el tipo tenga taxonomías se comprueba al validar).
	 *
	 * @param string $name      Opción.
	 * @param string $post_type Tipo del bucle.
	 */
	public static function loop_allows( string $name, string $post_type ): bool {
		return isset( self::LOOP_SOURCES[ $name ] ) && ( 'loop_product' !== self::LOOP_SOURCES[ $name ]['requires'] || 'product' === $post_type );
	}

	/**
	 * Nombres `loop_*` que aparecen en una cadena con tokens (cualquier forma de `loop_<algo>`), o vacío si no hay token.
	 *
	 * @param string $value Cadena.
	 *
	 * @return array<int, string>
	 */
	public static function loop_token_names( string $value ): array {
		if ( ! DiviTreeValidator::has_dynamic_token( $value ) || false === preg_match_all( '/loop_[a-z0-9_]+/i', $value, $match ) ) {
			return array();
		}

		return array_values( array_unique( $match[0] ) );
	}

	/**
	 * Indica si el valor es EXACTAMENTE un token de contenido bien formado, sin texto ni etiquetas alrededor.
	 *
	 * @param string $value Valor del campo.
	 */
	public static function is_single_token( string $value ): bool {
		return null !== self::parse( $value );
	}

	/**
	 * Fuentes que se pueden usar, con etiqueta, categoría y esquema de ajustes.
	 *
	 * Sin `$post_id` devuelve toda la lista cerrada y los campos de ACF de todos los grupos activos (cada una con su
	 * `requires`: las de WooCommerce sólo valen en un producto). Con `$post_id` sólo lo que Divi registra para esa
	 * página (los de WooCommerce en un producto, las taxonomías si el tipo las tiene) y los campos de ACF de los
	 * grupos que aplican a ella.
	 *
	 * @param int|null $post_id Página, o null.
	 *
	 * @return array<int, array{name: string, label: string, category: string, source: string, requires: string, settings: array<string, array<string, mixed>>, forced: array<string, string>}>|\WP_Error
	 */
	public function listing( ?int $post_id = null ) {
		if ( ! $this->active() ) {
			return $this->unavailable();
		}

		$out = array();

		foreach ( self::SOURCES as $name => $source ) {
			if ( null !== $post_id ) {
				$reason = $this->not_applicable( $name, $post_id );

				if ( $reason instanceof \WP_Error ) {
					return $reason;
				}

				if ( null !== $reason ) {
					continue;
				}
			}

			$out[] = array(
				'name'     => $name,
				'label'    => $source['label'],
				'category' => $source['category'],
				'source'   => 'divi',
				'requires' => $source['requires'],
				'settings' => $this->schema( $source['settings'], $post_id ),
				'forced'   => 'text' === $source['category'] ? array( 'enable_html' => 'off' ) : array(),
			);
		}

		$fields = $this->acf( $post_id );

		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}

		foreach ( $fields as $field ) {
			$out[] = array(
				'name'     => self::ACF_PREFIX . $field['name'],
				'label'    => sprintf( /* translators: 1: etiqueta del campo, 2: grupo de ACF. */ __( 'Campo de ACF «%1$s» (%2$s)', 'nexo-mcp' ), $field['label'], $field['group'] ),
				'category' => 'text',
				'source'   => 'acf',
				'requires' => '',
				'settings' => $this->schema( self::BEFORE_AFTER, $post_id ),
				'forced'   => array( 'enable_html' => 'off' ),
			);
		}

		return $out;
	}

	/**
	 * Fuentes por elemento de un bucle del conector, con su esquema de ajustes y dónde valen (`requires`).
	 *
	 * Con `$post_id` sólo las que Divi registra para esa página. Las taxonomías de `taxonomy_type` son las del tipo del
	 * bucle, que se conoce al escribir (aquí se publica el esquema sin cerrar).
	 *
	 * @param int|null $post_id Página, o null.
	 *
	 * @return array<int, array{name: string, label: string, category: string, source: string, requires: string, settings: array<string, array<string, mixed>>, forced: array<string, string>}>|\WP_Error
	 */
	public function loop_listing( ?int $post_id = null ) {
		if ( ! $this->active() ) {
			return $this->unavailable();
		}

		$out = array();

		foreach ( self::LOOP_SOURCES as $name => $source ) {
			if ( null !== $post_id ) {
				$reason = $this->registry_reason( $name, $source['category'], $post_id );

				if ( $reason instanceof \WP_Error ) {
					return $reason;
				}

				if ( null !== $reason ) {
					continue;
				}
			}

			$out[] = array(
				'name'     => $name,
				'label'    => $source['label'],
				'category' => $source['category'],
				'source'   => 'divi-loop',
				'requires' => $source['requires'],
				'settings' => $this->schema( $source['settings'], null ),
				'forced'   => 'text' === $source['category'] ? array( 'enable_html' => 'off' ) : array(),
			);
		}

		return $out;
	}

	/**
	 * Valida un token de contenido dinámico y devuelve su forma canónica.
	 *
	 * Los errores llevan sólo el motivo; quien llama le pone la ruta. Códigos: `nexo_mcp_divi_dynamic_unavailable`
	 * (sin Divi 5), `nexo_mcp_divi_dynamic_denied` (no es exactamente un token bien formado, la opción no está en la
	 * lista, no aplica a la página o es de otra categoría que el campo) y `nexo_mcp_divi_dynamic_invalid` (ajustes inválidos).
	 *
	 * @param string      $value    Valor del campo, tal como lo manda el cliente.
	 * @param string      $category Categoría del campo: `text`, `url` o `image`.
	 * @param int         $post_id  Página donde se escribe (el original, no la copia).
	 * @param string|null $loop_type Tipo de contenido del bucle del conector (propio o de un ancestro) donde está el campo, o null si no hay uno: sólo con él valen las fuentes `loop_*`.
	 *
	 * @return string|\WP_Error
	 */
	public function validate_token( string $value, string $category, int $post_id, ?string $loop_type = null ) {
		if ( ! $this->active() ) {
			return $this->unavailable();
		}

		$parsed = self::parse( $value );

		if ( null === $parsed ) {
			return $this->denied( __( 'debe ser exactamente un token $variable({"type":"content","value":{"name":…,"settings":{…}}})$ bien formado, sin texto ni etiquetas alrededor y sin formas codificadas.', 'nexo-mcp' ) );
		}

		list( $name, $settings ) = $parsed;
		$source                  = $this->source_of( $name, $post_id );

		if ( $source instanceof \WP_Error ) {
			return $source;
		}

		if ( null === $source ) {
			return $this->denied(
				sprintf(
					/* translators: %s: nombre de la opción. */
					__( 'la opción «%s» no está en la lista cerrada de contenido dinámico (ver nexo-mcp-list-divi-dynamic-sources).', 'nexo-mcp' ),
					$name
				)
			);
		}

		if ( $source['category'] !== $category ) {
			return $this->denied(
				sprintf(
					/* translators: 1: opción, 2: categoría de la opción, 3: categoría del campo. */
					__( 'la opción «%1$s» es de categoría «%2$s» y este campo necesita la categoría «%3$s».', 'nexo-mcp' ),
					$name,
					$source['category'],
					$category
				)
			);
		}

		$reason = $this->not_applicable( $name, $post_id, $loop_type );

		if ( $reason instanceof \WP_Error ) {
			return $reason;
		}

		if ( null !== $reason ) {
			return $this->denied( $reason );
		}

		$clean = $this->normalize_settings( $name, $source['category'], $this->schema( $source['settings'], $post_id, $loop_type ), $settings );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		if ( 'text' === $source['category'] ) {
			$clean['enable_html'] = 'off';
		}

		$json = wp_json_encode(
			array(
				'type'  => 'content',
				'value' => array(
					'name'     => $name,
					'settings' => array() === $clean ? new \stdClass() : $clean,
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $json ) ? '$variable(' . $json . ')$' : $this->denied( __( 'no se pudo codificar el token.', 'nexo-mcp' ) );
	}

	/**
	 * Categoría que un campo del catálogo de módulos acepta según su especificación de valor, o null si no admite dinámico.
	 *
	 * @param mixed $spec `plain`, `rich`, `link`, `src`… (las especificaciones de {@see DiviModuleCatalog}).
	 */
	public static function category_of_spec( $spec ): ?string {
		switch ( $spec ) {
			case 'plain':
			case 'rich':
				return 'text';
			case 'link':
				return 'url';
			case 'src':
				return 'image';
			default:
				return null;
		}
	}

	/**
	 * Campos de contenido de cada módulo del catálogo que aceptan un token, por categoría.
	 *
	 * @return array<string, array<string, array<int, string>>> módulo → categoría → rutas (`button.innerContent.text`).
	 */
	public static function targets(): array {
		$out = array();

		foreach ( DiviModuleCatalog::modules() as $module ) {
			foreach ( DiviModuleCatalog::content_paths( $module ) as $path => $spec ) {
				if ( 'module.meta.adminLabel' === $path ) {
					continue;
				}

				$fields = is_array( $spec ) && isset( $spec['object'] ) ? $spec['object'] : array( '' => $spec );

				foreach ( $fields as $key => $field_spec ) {
					$category = self::category_of_spec( $field_spec );

					if ( null !== $category ) {
						$out[ $module ][ $category ][] = '' === $key ? $path : $path . '.' . $key;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Resuelve un campo («title.innerContent», «button.innerContent.linkUrl») de un módulo: su ruta de atributos, la clave
	 * dentro del objeto de valor (`''` si el valor es la cadena) y su categoría; null si no es un destino de {@see self::targets()}.
	 *
	 * @param string $module Nombre de bloque.
	 * @param string $field  Campo tal como lo publica `targets()`.
	 *
	 * @return array{path: string, key: string, category: string}|null
	 */
	public static function field( string $module, string $field ): ?array {
		foreach ( DiviModuleCatalog::content_paths( $module ) as $path => $spec ) {
			if ( 'module.meta.adminLabel' === $path ) {
				continue;
			}

			$fields = is_array( $spec ) && isset( $spec['object'] ) ? $spec['object'] : array( '' => $spec );

			foreach ( $fields as $key => $field_spec ) {
				$category = self::category_of_spec( $field_spec );

				if ( null !== $category && ( '' === $key ? $path : $path . '.' . $key ) === $field ) {
					return array(
						'path'     => (string) $path,
						'key'      => (string) $key,
						'category' => $category,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Campos de un módulo que admiten un token (todas las categorías), agrupados por categoría.
	 *
	 * @param string $module Nombre de bloque.
	 *
	 * @return array<int, string>
	 */
	public static function fields_of( string $module ): array {
		$out = array();

		foreach ( self::targets()[ $module ] ?? array() as $paths ) {
			$out = array_merge( $out, $paths );
		}

		return $out;
	}

	/**
	 * Extrae el nombre y los ajustes de un valor que es exactamente un token `content`, o null.
	 *
	 * @param string $value Valor.
	 *
	 * @return array{0: string, 1: mixed}|null
	 */
	private static function parse( string $value ): ?array {
		if ( 1 !== preg_match( '/^\$variable\((\{.*\})\)\$\z/s', $value, $match ) ) {
			return null;
		}

		$data = json_decode( $match[1], true, 8 );

		if ( ! is_array( $data ) || array_is_list( $data ) || array( 'type', 'value' ) !== self::sorted_keys( $data ) || 'content' !== $data['type'] ) {
			return null;
		}

		$inner = $data['value'];

		if ( ! is_array( $inner ) || array_is_list( $inner ) || array( 'name', 'settings' ) !== self::sorted_keys( $inner ) || ! is_string( $inner['name'] ) ) {
			return null;
		}

		return array( $inner['name'], $inner['settings'] );
	}

	/**
	 * Claves de un arreglo, ordenadas.
	 *
	 * @param array<int|string, mixed> $data Arreglo.
	 *
	 * @return array<int, string>
	 */
	private static function sorted_keys( array $data ): array {
		$keys = array_map( 'strval', array_keys( $data ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Definición de una fuente (de la lista o de ACF) por su nombre en Divi, o null si no es de la lista.
	 *
	 * @param string $name    Nombre de la opción.
	 * @param int    $post_id Página.
	 *
	 * @return array{category: string, settings: array<string, array<string, mixed>>}|\WP_Error|null
	 */
	private function source_of( string $name, int $post_id ) {
		if ( isset( self::SOURCES[ $name ] ) ) {
			return self::SOURCES[ $name ];
		}

		if ( isset( self::LOOP_SOURCES[ $name ] ) ) {
			return self::LOOP_SOURCES[ $name ];
		}

		if ( str_starts_with( $name, self::ACF_PREFIX ) ) {
			$fields = $this->acf( $post_id );

			if ( $fields instanceof \WP_Error ) {
				return $fields;
			}

			foreach ( $fields as $field ) {
				if ( self::ACF_PREFIX . $field['name'] === $name ) {
					return array(
						'category' => 'text',
						'settings' => self::BEFORE_AFTER,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Motivo por el que una fuente de la lista no aplica a la página (o, las `loop_*`, al bucle), o null si aplica.
	 *
	 * @param string      $name      Opción de la lista.
	 * @param int         $post_id   Página.
	 * @param string|null $loop_type Tipo del bucle del conector en que está el campo, o null.
	 *
	 * @return string|\WP_Error|null El motivo, el error de «no disponible» si Divi no pudo responder, o null si aplica.
	 */
	private function not_applicable( string $name, int $post_id, ?string $loop_type = null ) {
		if ( isset( self::LOOP_SOURCES[ $name ] ) ) {
			$reason = $this->loop_context_reason( $name, $loop_type );

			return null !== $reason ? $reason : $this->registry_reason( $name, self::LOOP_SOURCES[ $name ]['category'], $post_id );
		}

		if ( ! isset( self::SOURCES[ $name ] ) ) {
			return null;
		}

		$source = self::SOURCES[ $name ];
		$type   = (string) ( $this->post_type_of )( $post_id );

		if ( 'product' === $source['requires'] && 'product' !== $type ) {
			return sprintf(
				/* translators: %s: opción. */
				__( 'la opción «%s» sólo aplica a productos de WooCommerce y esta página no lo es.', 'nexo-mcp' ),
				$name
			);
		}

		if ( 'taxonomy' === $source['requires'] && array() === $this->taxonomies( $type ) ) {
			return sprintf(
				/* translators: %s: opción. */
				__( 'la opción «%s» necesita un tipo de contenido con taxonomías públicas y el de esta página no tiene.', 'nexo-mcp' ),
				$name
			);
		}

		return $this->registry_reason( $name, $source['category'], $post_id );
	}

	/**
	 * Motivo por el que una fuente `loop_*` no vale donde se quiere poner (fuera de un bucle del conector, tipo sin
	 * productos o sin taxonomías), o null.
	 *
	 * @param string      $name      Opción `loop_*`.
	 * @param string|null $loop_type Tipo del bucle, o null si no hay un bucle del conector en la cadena.
	 */
	private function loop_context_reason( string $name, ?string $loop_type ): ?string {
		if ( null === $loop_type ) {
			return sprintf(
				/* translators: %s: opción. */
				__( 'la opción «%s» sólo vale dentro de un módulo con un bucle de consulta del conector (nexo-mcp-set-divi-loop), propio o de un ancestro, y este campo no está en uno.', 'nexo-mcp' ),
				$name
			);
		}

		$requires = self::LOOP_SOURCES[ $name ]['requires'];

		if ( 'loop_product' === $requires && 'product' !== $loop_type ) {
			return sprintf(
				/* translators: 1: opción, 2: tipo del bucle. */
				__( 'la opción «%1$s» sólo aplica a bucles de productos de WooCommerce y este bucle es de «%2$s».', 'nexo-mcp' ),
				$name,
				$loop_type
			);
		}

		if ( 'loop_taxonomy' === $requires && array() === $this->taxonomies( $loop_type ) ) {
			return sprintf(
				/* translators: 1: opción, 2: tipo del bucle. */
				__( 'la opción «%1$s» necesita un tipo de contenido con taxonomías públicas y el del bucle («%2$s») no tiene.', 'nexo-mcp' ),
				$name,
				$loop_type
			);
		}

		return null;
	}

	/**
	 * Lo que Divi registra para la página: si no registra la opción o la registra con otro tipo, el motivo; si Divi no
	 * puede responder, el error; si coincide, null.
	 *
	 * @param string $name     Opción de la lista.
	 * @param string $category Categoría esperada.
	 * @param int    $post_id  Página.
	 *
	 * @return string|\WP_Error|null
	 */
	private function registry_reason( string $name, string $category, int $post_id ) {
		$registered = $this->registered( $post_id );

		if ( $registered instanceof \WP_Error ) {
			return $registered;
		}

		if ( ! isset( $registered[ $name ] ) || ! is_array( $registered[ $name ] ) ) {
			return sprintf(
				/* translators: %s: opción. */
				__( 'Divi no registra la opción «%s» para esta página.', 'nexo-mcp' ),
				$name
			);
		}

		$divi_type = $registered[ $name ]['type'] ?? $category;

		return $divi_type === $category
			? null
			: sprintf(
				/* translators: 1: opción, 2: tipo que registra Divi. */
				__( 'Divi registra la opción «%1$s» con el tipo «%2$s», que no es el esperado.', 'nexo-mcp' ),
				$name,
				is_string( $divi_type ) ? $divi_type : '?'
			);
	}

	/**
	 * Esquema de ajustes que se publica de una fuente, con la taxonomía cerrada a las de la página.
	 *
	 * @param array<string, array<string, mixed>> $settings  Ajustes de la fuente.
	 * @param int|null                            $post_id   Página, o null.
	 * @param string|null                         $loop_type Tipo del bucle (las taxonomías salen de él en vez de la página), o null.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schema( array $settings, ?int $post_id, ?string $loop_type = null ): array {
		foreach ( $settings as $key => $rule ) {
			if ( 'taxonomy' === $rule['type'] && ( null !== $post_id || null !== $loop_type ) ) {
				$options          = $this->taxonomies( null !== $loop_type ? $loop_type : (string) ( $this->post_type_of )( (int) $post_id ) );
				$default          = $this->default_taxonomy( $rule, $options );
				$settings[ $key ] = array(
					'type'    => 'enum',
					'options' => $options,
				) + ( null === $default ? array() : array( 'default' => $default ) );
			}

			if ( 'date_format' === $rule['type'] ) {
				$settings[ $key ] = array(
					'type'    => 'text',
					'pattern' => self::DATE_PATTERN,
					'max'     => 40,
				);
			}
		}

		return $settings;
	}

	/**
	 * Valida los ajustes de una opción y los devuelve normalizados, en el orden del esquema.
	 *
	 * `enable_html` no es del esquema: en una fuente de texto sólo vale `off` (se escribe siempre aparte) y en las demás no existe.
	 *
	 * @param string                              $name     Opción.
	 * @param string                              $category Categoría de la opción.
	 * @param array<string, array<string, mixed>> $schema   Ajustes admitidos.
	 * @param mixed                               $settings Ajustes recibidos.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	private function normalize_settings( string $name, string $category, array $schema, $settings ) {
		if ( ! is_array( $settings ) || ( array() !== $settings && array_is_list( $settings ) ) ) {
			return $this->invalid( __( '«settings» debe ser un objeto.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $settings ) as $key ) {
			$key = (string) $key;

			if ( 'enable_html' === $key && 'text' === $category ) {
				if ( 'off' !== $settings[ $key ] ) {
					return $this->bad( $name . '.enable_html', __( 'sólo puede ser «off»: el HTML crudo no se admite.', 'nexo-mcp' ) );
				}

				continue;
			}

			if ( ! isset( $schema[ $key ] ) ) {
				return $this->invalid(
					sprintf(
						/* translators: 1: ajuste, 2: opción, 3: ajustes admitidos. */
						__( 'el ajuste «%1$s» no existe en «%2$s». Admitidos: %3$s.', 'nexo-mcp' ),
						$key,
						$name,
						array() === $schema ? __( 'ninguno', 'nexo-mcp' ) : implode( ', ', array_keys( $schema ) )
					)
				);
			}
		}

		$out = array();

		foreach ( $schema as $key => $rule ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$clean = $this->normalize_setting( $name . '.' . $key, $rule, $settings[ $key ] );

			if ( $clean instanceof \WP_Error ) {
				return $clean;
			}

			$out[ $key ] = $clean;
		}

		// Divi no pinta la taxonomía ni el enlace si faltan: el Visual Builder escribe siempre los valores por omisión, aquí se completan (`when`: sólo si otro ajuste vale lo indicado).
		foreach ( $schema as $key => $rule ) {
			$when = is_array( $rule['when'] ?? null ) ? $rule['when'] : array();

			if ( ! array_key_exists( $key, $out ) && 'enum' === $rule['type'] && isset( $rule['default'] ) && array_intersect_assoc( $when, $out ) === $when ) {
				$out[ $key ] = $rule['default'];
			}
		}

		return $this->in_schema_order( $out, $schema );
	}

	/**
	 * Ordena los ajustes como el esquema.
	 *
	 * @param array<string, string>               $values Ajustes.
	 * @param array<string, array<string, mixed>> $schema Esquema.
	 *
	 * @return array<string, string>
	 */
	private function in_schema_order( array $values, array $schema ): array {
		$out = array();

		foreach ( array_keys( $schema ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$out[ $key ] = $values[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Taxonomía por omisión de un ajuste entre las del tipo, o null.
	 *
	 * @param array<string, mixed> $rule    Regla (`defaults`: preferidas en orden).
	 * @param array<int, string>   $options Taxonomías del tipo.
	 */
	private function default_taxonomy( array $rule, array $options ): ?string {
		foreach ( is_array( $rule['defaults'] ?? null ) ? $rule['defaults'] : array() as $candidate ) {
			if ( in_array( $candidate, $options, true ) ) {
				return (string) $candidate;
			}
		}

		return null;
	}

	/**
	 * Normaliza el valor de un ajuste.
	 *
	 * @param string               $where   «opción.ajuste».
	 * @param array<string, mixed> $rule    Regla.
	 * @param mixed                $setting Valor recibido.
	 *
	 * @return string|\WP_Error
	 */
	private function normalize_setting( string $where, array $rule, $setting ) {
		switch ( $rule['type'] ) {
			case 'text':
				if ( isset( $rule['pattern'] ) ) {
					return is_string( $setting ) && 1 === preg_match( (string) $rule['pattern'], $setting )
						? $setting
						: $this->bad( $where, __( 'debe ser un formato de fecha con letras, números, espacio y , . : / -.', 'nexo-mcp' ) );
				}

				return $this->safe_text( $where, $setting );

			case 'number':
				$number = is_int( $setting ) ? $setting : ( is_string( $setting ) && 1 === preg_match( '/^[1-9][0-9]{0,5}\z/', $setting ) ? (int) $setting : null );

				return null !== $number && $number >= $rule['min'] && $number <= $rule['max']
					? (string) $number
					: $this->bad( $where, sprintf( /* translators: 1: mínimo, 2: máximo. */ __( 'debe ser un entero entre %1$d y %2$d.', 'nexo-mcp' ), $rule['min'], $rule['max'] ) );

			case 'onoff':
				return in_array( $setting, array( 'on', 'off' ), true ) ? (string) $setting : $this->bad( $where, __( 'debe ser «on» u «off».', 'nexo-mcp' ) );

			default: // enum.
				return is_string( $setting ) && in_array( $setting, $rule['options'], true )
					? $setting
					: $this->bad( $where, sprintf( /* translators: %s: opciones. */ __( 'debe ser una de: %s.', 'nexo-mcp' ), implode( ' | ', $rule['options'] ) ) );
		}
	}

	/**
	 * Texto plano de un ajuste: sin etiquetas, shortcodes, `$` ni `\`, sin caracteres de control y de hasta {@see self::MAX_TEXT}.
	 *
	 * No se sanea en silencio: lo que no es texto plano se rechaza, y lo que vale se guarda tal cual.
	 *
	 * @param string $where   «opción.ajuste».
	 * @param mixed  $setting Valor recibido.
	 *
	 * @return string|\WP_Error
	 */
	private function safe_text( string $where, $setting ) {
		if ( ! is_string( $setting ) ) {
			return $this->bad( $where, __( 'debe ser texto.', 'nexo-mcp' ) );
		}

		$decoded = html_entity_decode( $setting, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( 1 !== preg_match( '//u', $setting ) || mb_strlen( $setting ) > self::MAX_TEXT || 1 === preg_match( '/[\x00-\x1F\x7F<>\[\]$\\\\]/', $setting . $decoded ) || DiviTreeValidator::has_dynamic_token( $setting ) ) {
			return $this->bad(
				$where,
				sprintf(
					/* translators: %d: caracteres máximos. */
					__( 'debe ser texto plano de hasta %d caracteres, sin etiquetas, corchetes (shortcodes), «$», «\\» ni caracteres de control.', 'nexo-mcp' ),
					self::MAX_TEXT
				)
			);
		}

		return $setting;
	}

	/**
	 * Campos de ACF admitidos: tipo texto, área de texto o número, de grupos registrados, con un nombre seguro y que no empieza por `_`.
	 *
	 * @param int|null $post_id Página, o null para todos los grupos.
	 *
	 * @return array<int, array{name: string, label: string, type: string, group: string}>|\WP_Error
	 */
	private function acf( ?int $post_id ) {
		$key = null === $post_id ? 'all' : (string) $post_id;

		if ( ! array_key_exists( $key, $this->acf_cache ) ) {
			$this->acf_cache[ $key ] = $this->load_acf( $post_id );
		}

		return $this->acf_cache[ $key ];
	}

	/**
	 * Pide los campos de ACF al resolvedor; si falla o no devuelve una lista, el catálogo no puede responder.
	 *
	 * @param int|null $post_id Página, o null.
	 *
	 * @return array<int, array{name: string, label: string, type: string, group: string}>|\WP_Error
	 */
	private function load_acf( ?int $post_id ) {
		try {
			$fields = ( $this->acf_fields )( $post_id );
		} catch ( \Throwable $e ) {
			return $this->resolver_failed( 'ACF' );
		}

		if ( ! is_array( $fields ) ) {
			return $this->resolver_failed( 'ACF' );
		}

		$out = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'], $field['type'] ) || ! is_string( $field['name'] ) || ! is_string( $field['type'] ) ) {
				continue;
			}

			if ( ! in_array( $field['type'], self::ACF_TYPES, true ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,99}\z/', $field['name'] ) ) {
				continue;
			}

			$out[] = array(
				'name'  => $field['name'],
				'label' => isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $field['name'],
				'type'  => $field['type'],
				'group' => isset( $field['group'] ) && is_string( $field['group'] ) ? $field['group'] : '',
			);
		}

		return $out;
	}

	/**
	 * Opciones que Divi registra para una página, consultadas una vez; si Divi falla, el catálogo no puede responder.
	 *
	 * @param int $post_id Página.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function registered( int $post_id ) {
		if ( ! array_key_exists( $post_id, $this->registry_cache ) ) {
			try {
				$options = ( $this->registry )( $post_id );
			} catch ( \Throwable $e ) {
				$options = null;
			}

			$this->registry_cache[ $post_id ] = is_array( $options ) ? $options : $this->resolver_failed( 'Divi' );
		}

		return $this->registry_cache[ $post_id ];
	}

	/**
	 * Error de un resolvedor (Divi o ACF) que no pudo responder: no es lo mismo que «no registra la opción».
	 *
	 * @param string $who Quién falló.
	 */
	private function resolver_failed( string $who ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_dynamic_unavailable',
			sprintf(
				/* translators: %s: Divi o ACF. */
				__( 'no se pudo consultar las fuentes dinámicas de %s en este momento; no se escribe nada. Inténtalo de nuevo.', 'nexo-mcp' ),
				$who
			)
		);
	}

	/**
	 * Taxonomías públicas de un tipo.
	 *
	 * @param string $post_type Tipo de contenido.
	 *
	 * @return array<int, string>
	 */
	private function taxonomies( string $post_type ): array {
		if ( '' === $post_type ) {
			return array();
		}

		$names = ( $this->taxonomies_of )( $post_type );

		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Divi 5 activo.
	 */
	private function active(): bool {
		return (bool) ( $this->divi_active )();
	}

	/**
	 * Error de Divi 5 no disponible.
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error( 'nexo_mcp_divi_dynamic_unavailable', __( 'el contenido dinámico necesita Divi 5 activo en este sitio.', 'nexo-mcp' ) );
	}

	/**
	 * Error de token no permitido.
	 *
	 * @param string $reason Motivo.
	 */
	private function denied( string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_divi_dynamic_denied', $reason );
	}

	/**
	 * Error de ajustes inválidos.
	 *
	 * @param string $reason Motivo.
	 */
	private function invalid( string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_divi_dynamic_invalid', $reason );
	}

	/**
	 * Error de un ajuste.
	 *
	 * @param string $where  «opción.ajuste».
	 * @param string $reason Motivo.
	 */
	private function bad( string $where, string $reason ): \WP_Error {
		return $this->invalid( 'settings.' . $where . ': ' . $reason );
	}

	/**
	 * Opciones que registra Divi 5 para una página (fuente de producción).
	 *
	 * Si Divi no carga su clase de opciones, falla o no devuelve una lista, lanza: el catálogo lo toma como «no
	 * disponible» (`nexo_mcp_divi_dynamic_unavailable`) en vez de confundirlo con «Divi no registra la opción».
	 *
	 * @param int $post_id Página.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws \RuntimeException Si Divi no puede responder.
	 */
	public static function divi_registered_options( int $post_id ): array {
		// Nombre de clase en una variable: Divi no se carga ni se analiza aquí.
		$class = '\ET\Builder\Packages\Module\Layout\Components\DynamicContent\DynamicContentOptions';

		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( 'Divi no carga DynamicContentOptions.' );
		}

		$options = $class::get_options( $post_id, 'display' );

		if ( ! is_array( $options ) ) {
			throw new \RuntimeException( 'DynamicContentOptions::get_options no devolvió una lista.' );
		}

		return $options;
	}

	/**
	 * Campos de los grupos de ACF activos (que aplican a la página, si se da), sin leer meta: sólo la API de ACF.
	 *
	 * Sin ACF no hay campos (lista vacía); si ACF falla, la excepción sube y el catálogo responde «no disponible».
	 *
	 * @param int|null $post_id Página, o null para todos los grupos activos.
	 *
	 * @return array<int, array{name: string, label: string, type: string, group: string}>
	 */
	public static function acf_registered_fields( ?int $post_id ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$out    = array();
		$groups = null === $post_id ? acf_get_field_groups() : acf_get_field_groups( array( 'post_id' => $post_id ) );

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['active'] ) ) {
				continue;
			}

			$fields = acf_get_fields( $group );

			foreach ( $fields as $field ) {
				if ( isset( $field['name'], $field['type'] ) ) {
					$out[] = array(
						'name'  => (string) $field['name'],
						'label' => (string) ( $field['label'] ?? '' ),
						'type'  => (string) $field['type'],
						'group' => (string) ( $group['title'] ?? '' ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Taxonomías públicas de un tipo de contenido que se gestionan en el escritorio (sin `post_format`).
	 *
	 * @param string $post_type Tipo.
	 *
	 * @return array<int, string>
	 */
	public static function public_taxonomies( string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$names      = array();

		foreach ( $taxonomies as $name => $taxonomy ) {
			if ( ! empty( $taxonomy->public ) && ! empty( $taxonomy->show_ui ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}
}
