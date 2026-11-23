<?php
/**
 * Catálogo cerrado de datos dinámicos (dynamic tags) de Elementor que el conector asigna.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Lista cerrada de tags, con su grupo, su categoría y los ajustes que admite cada uno.
 *
 * Es una constante, no una configuración: un tag que no esté aquí no se asigna
 * nunca (shortcode, request-arg, user-info, campos personalizados, ACF…: leen
 * datos sensibles o ejecutan código y el render los evalúa para cualquier
 * visitante). Los tags son de Elementor Pro; si el sitio no los tiene
 * registrados se devuelve `nexo_mcp_dynamic_tag_unavailable`.
 *
 * Las comprobaciones que dependen del sitio llegan como funciones inyectables
 * (tags registrados, taxonomía pública) para probar sin Elementor ni WordPress.
 * La fuente de producción es `\Elementor\Plugin::$instance->dynamic_tags->get_tags()`,
 * no el registro atómico (`Dynamic_Tags_Editor_Config`), que descarta los tags de texto
 * fuera del editor.
 *
 * Los ajustes (L6/L7 de `odd/tasks/datos-dinamicos.md`) se normalizan: los de texto
 * pasan por `sanitize_text_field()` (texto plano, sin HTML) y no pueden superar
 * {@see self::MAX_TEXT} caracteres; los selects son enumeraciones cerradas; los
 * interruptores valen `yes` o ``; los números son enteros dentro de un rango.
 */
final class DynamicTagCatalog {

	public const MAX_TEXT = 200;

	/**
	 * Props de destino: «widget.prop» → categoría de la prop.
	 *
	 * `link` (categoría url) queda fuera de T2: no se verificó su forma ni su render.
	 *
	 * @var array<string, string>
	 */
	public const TARGETS = array(
		'e-heading.title'       => 'text',
		'e-paragraph.paragraph' => 'text',
		'e-button.text'         => 'text',
		'e-image.image'         => 'image',
	);

	/**
	 * Ajustes comunes de los tags de texto.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const TEXT_COMMON = array(
		'before'   => array( 'type' => 'text' ),
		'after'    => array( 'type' => 'text' ),
		'fallback' => array( 'type' => 'text' ),
	);

	private const SWITCH_NAME = 'switch';

	/**
	 * Tags: nombre → grupo, categoría y ajustes.
	 *
	 * @var array<string, array{group: string, category: string, settings: array<string, array<string, mixed>>}>
	 */
	private const TAGS = array(
		'site-title'          => array(
			'group'    => 'site',
			'category' => 'text',
			'settings' => self::TEXT_COMMON,
		),
		'site-tagline'        => array(
			'group'    => 'site',
			'category' => 'text',
			'settings' => self::TEXT_COMMON,
		),
		'page-title'          => array(
			'group'    => 'site',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'include_context' => array( 'type' => self::SWITCH_NAME ),
				'show_home_title' => array( 'type' => self::SWITCH_NAME ),
			),
		),
		'post-title'          => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON,
		),
		'post-excerpt'        => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'max_length'            => array(
					'type' => 'number',
					'min'  => 0,
					'max'  => 10000,
				),
				'apply_to_post_content' => array( 'type' => self::SWITCH_NAME ),
			),
		),
		'post-date'           => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'type'          => array(
					'type'    => 'enum',
					'options' => array( 'post_date_gmt', 'post_modified_gmt' ),
				),
				'format'        => array(
					'type'    => 'enum',
					'options' => array( 'default', 'human', 'custom' ),
				),
				'custom_format' => array( 'type' => 'text' ),
			),
		),
		'post-time'           => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'type'          => array(
					'type'    => 'enum',
					'options' => array( 'post_date_gmt', 'post_modified_gmt' ),
				),
				'format'        => array(
					'type'    => 'enum',
					'options' => array( 'default', 'custom' ),
				),
				'custom_format' => array( 'type' => 'text' ),
			),
		),
		'post-terms'          => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'taxonomy'  => array( 'type' => 'taxonomy' ),
				'separator' => array( 'type' => 'text' ),
				'link'      => array( 'type' => self::SWITCH_NAME ),
			),
		),
		'post-id'             => array(
			'group'    => 'post',
			'category' => 'text',
			'settings' => self::TEXT_COMMON,
		),
		'archive-title'       => array(
			'group'    => 'archive',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'include_context' => array( 'type' => self::SWITCH_NAME ),
			),
		),
		'author-name'         => array(
			'group'    => 'author',
			'category' => 'text',
			'settings' => self::TEXT_COMMON,
		),
		'current-date-time'   => array(
			'group'    => 'site',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'date_format'   => array(
					'type'    => 'enum',
					'options' => array( 'default', '', 'F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'custom' ),
				),
				'time_format'   => array(
					'type'    => 'enum',
					'options' => array( 'default', '', 'g:i a', 'g:i A', 'H:i' ),
				),
				'custom_format' => array( 'type' => 'text' ),
			),
		),
		'comments-number'     => array(
			'group'    => 'comments',
			'category' => 'text',
			'settings' => self::TEXT_COMMON + array(
				'format_no_comments'   => array( 'type' => 'text' ),
				'format_one_comments'  => array( 'type' => 'text' ),
				'format_many_comments' => array( 'type' => 'text' ),
				'link_to'              => array(
					'type'    => 'enum',
					'options' => array( '', 'comments_link' ),
				),
			),
		),
		'site-url'            => array(
			'group'    => 'site',
			'category' => 'url',
			'settings' => array(),
		),
		'post-url'            => array(
			'group'    => 'post',
			'category' => 'url',
			'settings' => array(),
		),
		'archive-url'         => array(
			'group'    => 'archive',
			'category' => 'url',
			'settings' => array(),
		),
		'comments-url'        => array(
			'group'    => 'comments',
			'category' => 'url',
			'settings' => array(),
		),
		// `post-featured-image.fallback` (media) no se admite: sin ajustes.
		'site-logo'           => array(
			'group'    => 'site',
			'category' => 'image',
			'settings' => array(),
		),
		'post-featured-image' => array(
			'group'    => 'post',
			'category' => 'image',
			'settings' => array(),
		),
	);

	/**
	 * Tags registrados en el sitio: fn(): array<int, string>.
	 *
	 * @var callable
	 */
	private $registered_tags;

	/**
	 * Taxonomía registrada y pública: fn( string $name ): bool.
	 *
	 * @var callable
	 */
	private $taxonomy_check;

	/**
	 * Título de un tag registrado: fn( string $name ): string; vacío si no hay.
	 *
	 * @var callable
	 */
	private $label_of;

	/**
	 * Construye el catálogo.
	 *
	 * @param callable|null $registered_tags fn(): array<int, string> con los nombres de los tags registrados.
	 *                                        Por omisión, `\Elementor\Plugin::$instance->dynamic_tags->get_tags()`.
	 * @param callable|null $taxonomy_check  fn( string ): bool, si es una taxonomía registrada y pública. Por omisión, la de WordPress.
	 * @param callable|null $label_of        fn( string ): string, el título del tag registrado ('' si no hay). Por omisión, el de Elementor.
	 */
	public function __construct( ?callable $registered_tags = null, ?callable $taxonomy_check = null, ?callable $label_of = null ) {
		$this->registered_tags = $registered_tags ?? array( self::class, 'elementor_registered_tags' );
		$this->taxonomy_check  = $taxonomy_check ?? array( self::class, 'public_taxonomy' );
		$this->label_of        = $label_of ?? array( self::class, 'elementor_tag_label' );
	}

	/**
	 * Tags del catálogo registrados en el sitio, con lo que hace falta para asignarlos.
	 *
	 * Sin tags registrados (sin Elementor Pro) devuelve una lista vacía, no un error.
	 *
	 * @return array<int, array{name: string, group: string, label: string, categories: array<int, string>, settings: array<string, array<string, mixed>>, targets: array<int, string>}>
	 */
	public function listing(): array {
		$out = array();

		foreach ( $this->available() as $name ) {
			$tag   = self::TAGS[ $name ];
			$label = trim( (string) ( $this->label_of )( $name ) );

			$out[] = array(
				'name'       => $name,
				'group'      => $tag['group'],
				'label'      => '' !== $label ? $label : $name,
				'categories' => array( $tag['category'] ),
				'settings'   => $tag['settings'],
				'targets'    => array_keys( array_filter( self::TARGETS, static fn( string $category ): bool => $category === $tag['category'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Definición cerrada de todos los tags del catálogo.
	 *
	 * @return array<string, array{group: string, category: string, settings: array<string, array<string, mixed>>}>
	 */
	public static function definitions(): array {
		return self::TAGS;
	}

	/**
	 * Nombres de los tags del catálogo.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array_keys( self::TAGS );
	}

	/**
	 * Tags del catálogo que el sitio tiene registrados.
	 *
	 * @return array<int, string>
	 */
	public function available(): array {
		$registered = $this->registered();

		return array_values( array_filter( self::names(), static fn( string $name ): bool => in_array( $name, $registered, true ) ) );
	}

	/**
	 * Valida y normaliza una prop dinámica para un widget y una prop de destino.
	 *
	 * @param string $widget Tipo del widget (`e-heading`…).
	 * @param string $prop   Clave de la prop (`title`…).
	 * @param mixed  $value  `{"$$type":"dynamic","value":{name, group, settings}}`.
	 *
	 * @return array<string, mixed>|\WP_Error Prop normalizada (ajustes saneados) o error.
	 */
	public function validate( string $widget, string $prop, $value ) {
		$target = $widget . '.' . $prop;

		if ( ! isset( self::TARGETS[ $target ] ) ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_target_unsupported',
				sprintf(
					/* translators: 1: widget.prop, 2: destinos admitidos. */
					__( 'la propiedad «%1$s» no admite datos dinámicos. Destinos admitidos: %2$s.', 'nexo-mcp' ),
					$target,
					implode( ', ', array_keys( self::TARGETS ) )
				)
			);
		}

		if ( ! is_array( $value ) || 'dynamic' !== ( $value['$$type'] ?? null ) || ! is_array( $value['value'] ?? null ) || array_is_list( $value['value'] ) ) {
			return $this->invalid( __( 'debe tener la forma {"$$type": "dynamic", "value": {"name", "group", "settings"}}.', 'nexo-mcp' ) );
		}

		$inner = $value['value'];
		$extra = array_diff( array_map( 'strval', array_keys( $inner ) ), array( 'name', 'group', 'settings' ) );
		$outer = array_diff( array_map( 'strval', array_keys( $value ) ), array( '$$type', 'value' ) );

		if ( array() !== $extra || array() !== $outer ) {
			return $this->invalid( __( 'sólo admite las claves «name», «group» y «settings» dentro de «value».', 'nexo-mcp' ) );
		}

		$name  = $inner['name'] ?? null;
		$group = $inner['group'] ?? null;

		if ( ! is_string( $name ) || ! is_string( $group ) ) {
			return $this->invalid( __( '«name» y «group» deben ser texto.', 'nexo-mcp' ) );
		}

		if ( ! isset( self::TAGS[ $name ] ) ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_tag_unknown',
				sprintf(
					/* translators: 1: tag, 2: tags admitidos. */
					__( 'el tag «%1$s» no está permitido. Tags admitidos: %2$s.', 'nexo-mcp' ),
					$name,
					implode( ', ', self::names() )
				)
			);
		}

		$tag = self::TAGS[ $name ];

		if ( $group !== $tag['group'] ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_group_mismatch',
				sprintf(
					/* translators: 1: tag, 2: grupo recibido, 3: grupo del tag. */
					__( 'el grupo «%2$s» no corresponde al tag «%1$s» (grupo «%3$s»).', 'nexo-mcp' ),
					$name,
					$group,
					$tag['group']
				)
			);
		}

		if ( self::TARGETS[ $target ] !== $tag['category'] ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_category_mismatch',
				sprintf(
					/* translators: 1: tag, 2: categoría del tag, 3: destino, 4: categoría del destino. */
					__( 'el tag «%1$s» es de categoría «%2$s» y «%3$s» necesita la categoría «%4$s».', 'nexo-mcp' ),
					$name,
					$tag['category'],
					$target,
					self::TARGETS[ $target ]
				)
			);
		}

		if ( ! in_array( $name, $this->registered(), true ) ) {
			return new \WP_Error(
				'nexo_mcp_dynamic_tag_unavailable',
				sprintf(
					/* translators: %s: tag. */
					__( 'el tag «%s» no está registrado en este sitio (los datos dinámicos requieren Elementor Pro).', 'nexo-mcp' ),
					$name
				)
			);
		}

		$settings = $this->normalize_settings( $name, $tag['settings'], $inner['settings'] ?? array() );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return array(
			'$$type' => 'dynamic',
			'value'  => array(
				'name'     => $name,
				'group'    => $group,
				'settings' => $settings,
			),
		);
	}

	/**
	 * Valida y sanea los ajustes de un tag.
	 *
	 * @param string                              $name     Tag.
	 * @param array<string, array<string, mixed>> $allowed  Ajustes admitidos.
	 * @param mixed                               $settings Ajustes recibidos.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function normalize_settings( string $name, array $allowed, $settings ) {
		if ( ! is_array( $settings ) || ( array() !== $settings && array_is_list( $settings ) ) ) {
			return $this->invalid( __( '«settings» debe ser un objeto.', 'nexo-mcp' ) );
		}

		$out = array();

		foreach ( $settings as $key => $setting ) {
			$key = (string) $key;

			if ( ! isset( $allowed[ $key ] ) ) {
				return new \WP_Error(
					'nexo_mcp_dynamic_setting_unknown',
					sprintf(
						/* translators: 1: ajuste, 2: tag, 3: ajustes admitidos. */
						__( 'el ajuste «%1$s» no existe en «%2$s». Admitidos: %3$s.', 'nexo-mcp' ),
						$key,
						$name,
						array() === $allowed ? __( 'ninguno', 'nexo-mcp' ) : implode( ', ', array_keys( $allowed ) )
					)
				);
			}

			$clean = $this->normalize_setting( $name, $key, $allowed[ $key ], $setting );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out[ $key ] = $clean['value'];
		}

		return $out;
	}

	/**
	 * Normaliza un ajuste.
	 *
	 * @param string               $name    Tag.
	 * @param string               $key     Ajuste.
	 * @param array<string, mixed> $rule    Regla del ajuste.
	 * @param mixed                $setting Valor recibido.
	 *
	 * @return array{value: mixed}|\WP_Error
	 */
	private function normalize_setting( string $name, string $key, array $rule, $setting ) {
		$where = $name . '.' . $key;

		switch ( $rule['type'] ) {
			case 'text':
				if ( ! is_string( $setting ) ) {
					return $this->bad_setting( $where, __( 'debe ser texto.', 'nexo-mcp' ) );
				}

				// Se quitan las etiquetas aquí, sin depender de lo que haga sanitize_text_field().
				$clean = sanitize_text_field( wp_strip_all_tags( $setting ) );

				if ( mb_strlen( $clean ) > self::MAX_TEXT ) {
					return $this->bad_setting(
						$where,
						sprintf(
							/* translators: %d: caracteres máximos. */
							__( 'no puede superar %d caracteres.', 'nexo-mcp' ),
							self::MAX_TEXT
						)
					);
				}

				return array( 'value' => $clean );

			case 'number':
				if ( ! is_int( $setting ) || $setting < $rule['min'] || $setting > $rule['max'] ) {
					return $this->bad_setting(
						$where,
						sprintf(
							/* translators: 1: mínimo, 2: máximo. */
							__( 'debe ser un entero entre %1$d y %2$d.', 'nexo-mcp' ),
							$rule['min'],
							$rule['max']
						)
					);
				}

				return array( 'value' => $setting );

			case self::SWITCH_NAME:
				if ( ! in_array( $setting, array( 'yes', '' ), true ) ) {
					return $this->bad_setting( $where, __( 'debe ser «yes» o una cadena vacía.', 'nexo-mcp' ) );
				}

				return array( 'value' => $setting );

			case 'enum':
				if ( ! is_string( $setting ) || ! in_array( $setting, $rule['options'], true ) ) {
					return $this->bad_setting(
						$where,
						sprintf(
							/* translators: %s: opciones. */
							__( 'debe ser una de: %s.', 'nexo-mcp' ),
							implode( ' | ', array_map( static fn( string $option ): string => '' === $option ? '«»' : $option, $rule['options'] ) )
						)
					);
				}

				return array( 'value' => $setting );

			default: // taxonomy.
				if ( ! is_string( $setting ) || '' === $setting || ! (bool) ( $this->taxonomy_check )( $setting ) ) {
					return $this->bad_setting( $where, __( 'debe ser una taxonomía registrada y pública.', 'nexo-mcp' ) );
				}

				return array( 'value' => $setting );
		}
	}

	/**
	 * Tags registrados en el sitio.
	 *
	 * @return array<int, string>
	 */
	private function registered(): array {
		$names = ( $this->registered_tags )();

		return is_array( $names ) ? array_values( array_filter( array_map( 'strval', $names ) ) ) : array();
	}

	/**
	 * Error de forma.
	 *
	 * @param string $reason Motivo.
	 */
	private function invalid( string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_dynamic_value_invalid', $reason );
	}

	/**
	 * Error de un ajuste.
	 *
	 * @param string $where  «tag.ajuste».
	 * @param string $reason Motivo.
	 */
	private function bad_setting( string $where, string $reason ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_dynamic_setting_invalid', 'settings.' . $where . ': ' . $reason );
	}

	/**
	 * Nombres de los tags que registra Elementor (fuente de producción).
	 *
	 * @return array<int, string>
	 */
	public static function elementor_registered_tags(): array {
		// Nombre de clase en una variable: Elementor no se carga ni se analiza aquí.
		$plugin = '\Elementor\Plugin';

		if ( ! class_exists( $plugin ) || ! isset( $plugin::$instance->dynamic_tags ) ) {
			return array();
		}

		$tags = $plugin::$instance->dynamic_tags->get_tags();

		return is_array( $tags ) ? array_map( 'strval', array_keys( $tags ) ) : array();
	}

	/**
	 * Título del tag según Elementor ('' si no se puede leer).
	 *
	 * @param string $name Tag.
	 */
	public static function elementor_tag_label( string $name ): string {
		$plugin = '\Elementor\Plugin';

		if ( ! class_exists( $plugin ) || ! isset( $plugin::$instance->dynamic_tags ) ) {
			return '';
		}

		try {
			$tags     = $plugin::$instance->dynamic_tags->get_tags();
			$instance = is_array( $tags ) && is_array( $tags[ $name ] ?? null ) ? ( $tags[ $name ]['instance'] ?? null ) : null;
			$title    = is_object( $instance ) && method_exists( $instance, 'get_title' ) ? $instance->get_title() : '';
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Si el nombre es una taxonomía registrada y pública.
	 *
	 * @param string $name Taxonomía.
	 */
	public static function public_taxonomy( string $name ): bool {
		$taxonomy = taxonomy_exists( $name ) ? get_taxonomy( $name ) : false;

		return false !== $taxonomy && ! empty( $taxonomy->public );
	}
}
