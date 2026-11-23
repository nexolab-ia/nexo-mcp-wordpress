<?php
/**
 * Componentes de Elementor 4: listar y crear como datos.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Envuelve el repositorio y los validadores de Elementor para listar y crear componentes.
 *
 * Crear un componente es cambiar el sitio, no una página. Orden: disponibilidad
 * (Editor V4 activo y Pro con licencia: `Components_Access_Controller::can_create()`),
 * título, validación propia del árbol ({@see ElementorTreeValidator}), saneado con los
 * parsers de Elementor, armado, los validadores de Elementor que el repositorio no corre
 * (`Save_Components_Validator`, `Circular_Dependency_Validator`, `Non_Atomic_Widget_Validator`)
 * y sólo entonces `Components_Repository::create()` como borrador. Nunca se publica.
 *
 * Las dependencias de Elementor llegan como funciones inyectables para probar sin él.
 */
final class ComponentStore {

	public const MIN_TITLE = 2;
	public const MAX_TITLE = 200;

	private const UID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * Guarda de esquema.
	 *
	 * @var ElementorSchemaGuard
	 */
	private ElementorSchemaGuard $guard;

	/**
	 * Pro con licencia activa: fn(): bool.
	 *
	 * @var callable
	 */
	private $can_create;

	/**
	 * Componentes existentes: fn(): array de {id, uid, title, status, modified, archived}.
	 *
	 * @var callable
	 */
	private $lister;

	/**
	 * Validadores de Elementor: fn( string $title, string $uid, array $elements ): ?string (mensaje de rechazo).
	 *
	 * @var callable
	 */
	private $validator;

	/**
	 * Creación: fn( string $title, array $elements, string $status, string $uid ): int. Lanza si falla.
	 *
	 * @var callable
	 */
	private $creator;

	/**
	 * Pro con licencia activa para insertar en páginas: fn(): bool.
	 *
	 * @var callable
	 */
	private $can_add;

	/**
	 * Construye el almacén.
	 *
	 * @param ElementorSchemaGuard|null $guard      Guarda de esquema (las pruebas ponen una sin Elementor).
	 * @param callable|null             $can_create Pro con licencia activa.
	 * @param callable|null             $lister     Componentes existentes.
	 * @param callable|null             $validator  Validadores de Elementor.
	 * @param callable|null             $creator    Creación en el repositorio de Elementor.
	 * @param callable|null             $can_add    Insertar en páginas permitido (Pro con licencia).
	 */
	public function __construct(
		?ElementorSchemaGuard $guard = null,
		?callable $can_create = null,
		?callable $lister = null,
		?callable $validator = null,
		?callable $creator = null,
		?callable $can_add = null
	) {
		$this->guard      = $guard ?? new ElementorSchemaGuard();
		$this->can_create = $can_create ?? array( self::class, 'elementor_can_create' );
		$this->lister     = $lister ?? array( self::class, 'elementor_list' );
		$this->validator  = $validator ?? array( self::class, 'elementor_validate' );
		$this->creator    = $creator ?? array( self::class, 'elementor_create' );
		$this->can_add    = $can_add ?? array( self::class, 'elementor_can_add_to_page' );
	}

	/**
	 * Guarda de esquema con la que se insertan instancias en una página.
	 */
	public function guard(): ElementorSchemaGuard {
		return $this->guard;
	}

	/**
	 * Comprueba que se puedan insertar instancias (Editor V4 y Pro con licencia activa).
	 *
	 * @return true|\WP_Error `nexo_mcp_components_unavailable` si no.
	 */
	public function require_insertable() {
		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return new \WP_Error( 'nexo_mcp_components_unavailable', $ready->get_error_message() );
		}

		if ( ! (bool) ( $this->can_add )() ) {
			return new \WP_Error(
				'nexo_mcp_components_unavailable',
				__( 'Insertar componentes requiere Elementor Pro con licencia activa.', 'nexo-mcp' )
			);
		}

		return true;
	}

	/**
	 * Si el componente existe, no está archivado y no está en la papelera.
	 *
	 * @param int $id Id del post `elementor_component`.
	 */
	public function usable( int $id ): bool {
		foreach ( ( $this->lister )() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return empty( $row['archived'] ) && 'trash' !== ( $row['status'] ?? '' );
			}
		}

		return false;
	}

	/**
	 * Componentes no archivados (o todos), sin el árbol.
	 *
	 * @param bool $include_archived Incluir archivados (y marcar `archived`).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( bool $include_archived = false ): array {
		$out = array();

		foreach ( ( $this->lister )() as $row ) {
			$archived = ! empty( $row['archived'] );

			if ( $archived && ! $include_archived ) {
				continue;
			}

			$item = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'uid'      => (string) ( $row['uid'] ?? '' ),
				'title'    => (string) ( $row['title'] ?? '' ),
				'status'   => (string) ( $row['status'] ?? '' ),
				'modified' => (string) ( $row['modified'] ?? '' ),
			);

			if ( $include_archived ) {
				$item['archived'] = $archived;
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Crea un componente en borrador a partir de un árbol atómico.
	 *
	 * @param array<string, mixed> $input {title, elements}.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ) {
		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return new \WP_Error( 'nexo_mcp_components_unavailable', $ready->get_error_message() );
		}

		if ( ! ( $this->can_create )() ) {
			return new \WP_Error(
				'nexo_mcp_components_unavailable',
				__( 'Crear componentes requiere Elementor Pro con licencia activa y el Editor V4. No se creó nada.', 'nexo-mcp' )
			);
		}

		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );

		if ( $len < self::MIN_TITLE || $len > self::MAX_TITLE ) {
			return new \WP_Error(
				'nexo_mcp_component_invalid',
				sprintf(
					/* translators: 1: mínimo, 2: máximo. */
					__( 'El título del componente debe tener entre %1$d y %2$d caracteres.', 'nexo-mcp' ),
					self::MIN_TITLE,
					self::MAX_TITLE
				)
			);
		}

		$elements = $input['elements'] ?? null;
		$checked  = $this->guard->validator()->validate( $elements );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$elements = (array) $elements;
		$checked  = $this->guard->validate( $elements );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$clean = $this->guard->sanitize( $elements );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$tree = ( new ElementorTreeBuilder() )->build( $clean );
		$uid  = self::new_uid();

		$rejection = ( $this->validator )( $title, $uid, $tree );

		if ( null !== $rejection ) {
			return new \WP_Error( 'nexo_mcp_component_invalid', self::plain_message( $rejection ) );
		}

		try {
			$id = ( $this->creator )( $title, $tree, 'draft', $uid );
		} catch ( \Throwable $e ) {
			// Elementor ya borró el post a medias.
			return new \WP_Error( 'nexo_mcp_component_invalid', self::plain_message( $e->getMessage() ) );
		}

		return array(
			'id'     => $id,
			'uid'    => $uid,
			'title'  => $title,
			'status' => 'draft',
		);
	}

	/**
	 * Los mensajes de Elementor llegan con entidades HTML (`&#039;`): se devuelven como texto.
	 *
	 * @param string $message Mensaje de Elementor.
	 */
	private static function plain_message( string $message ): string {
		return html_entity_decode( $message, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Uid propio: `nexo-` y 12 caracteres [a-z0-9].
	 */
	private static function new_uid(): string {
		$out = '';

		for ( $i = 0; $i < 12; $i++ ) {
			$out .= self::UID_ALPHABET[ random_int( 0, strlen( self::UID_ALPHABET ) - 1 ) ];
		}

		return 'nexo-' . $out;
	}

	/**
	 * Producción: Pro con licencia activa.
	 */
	public static function elementor_can_create(): bool {
		$controller = '\Elementor\Modules\Components\Components_Access_Controller';

		return class_exists( $controller ) && (bool) $controller::can_create();
	}

	/**
	 * Producción: Pro con licencia activa para insertar en páginas.
	 */
	public static function elementor_can_add_to_page(): bool {
		$controller = '\Elementor\Modules\Components\Components_Access_Controller';

		return class_exists( $controller ) && (bool) $controller::can_add_to_page();
	}

	/**
	 * Producción: componentes del repositorio de Elementor (sin módulo, lista vacía).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function elementor_list(): array {
		$repository = '\Elementor\Modules\Components\Components_Repository';

		if ( ! class_exists( $repository ) || ! post_type_exists( 'elementor_component' ) ) {
			return array();
		}

		$out = array();

		try {
			foreach ( $repository::make()->all()->all() as $component ) {
				$post  = get_post( (int) $component['id'] );
				$out[] = array(
					'id'       => (int) $component['id'],
					'uid'      => (string) $component['uid'],
					'title'    => (string) $component['title'],
					'status'   => $post instanceof \WP_Post ? $post->post_status : '',
					'modified' => $post instanceof \WP_Post ? $post->post_modified : '',
					'archived' => ! empty( $component['is_archived'] ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $out;
	}

	/**
	 * Producción: los validadores de Elementor que el repositorio no corre.
	 *
	 * @param string                           $title    Título.
	 * @param string                           $uid      Uid.
	 * @param array<int, array<string, mixed>> $elements Árbol armado.
	 */
	public static function elementor_validate( string $title, string $uid, array $elements ): ?string {
		$collection = '\Elementor\Core\Utils\Collection';
		$repository = '\Elementor\Modules\Components\Components_Repository';
		$save       = '\Elementor\Modules\Components\Save_Components_Validator';
		$circular   = '\Elementor\Modules\Components\Circular_Dependency_Validator';
		$atomic     = '\Elementor\Modules\Components\Non_Atomic_Widget_Validator';

		if ( ! class_exists( $repository ) || ! class_exists( $save ) || ! class_exists( $circular ) || ! class_exists( $atomic ) || ! class_exists( $collection ) ) {
			return __( 'Esta versión de Elementor no trae los validadores de componentes.', 'nexo-mcp' );
		}

		try {
			$items = $collection::make(
				array(
					array(
						'uid'      => $uid,
						'title'    => $title,
						'elements' => $elements,
					),
				)
			);
			$saved = $save::make( $repository::make()->all() )->validate( $items );

			if ( empty( $saved['success'] ) ) {
				return implode( ' ', array_map( 'strval', (array) ( $saved['messages'] ?? array() ) ) );
			}

			$circle = $circular::make()->validate_new_components( $items );

			if ( empty( $circle['success'] ) ) {
				return implode( ' ', array_map( 'strval', (array) ( $circle['messages'] ?? array() ) ) );
			}

			$flat = $atomic::make()->validate_items( $items );

			if ( empty( $flat['success'] ) ) {
				return implode( ' ', array_map( 'strval', (array) ( $flat['messages'] ?? array() ) ) );
			}
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Producción: `Components_Repository::create()`.
	 *
	 * @param string                           $title    Título.
	 * @param array<int, array<string, mixed>> $elements Árbol armado.
	 * @param string                           $status   Estado.
	 * @param string                           $uid      Uid.
	 *
	 * @throws \Exception Si Elementor no puede crear (ya borró el post).
	 */
	public static function elementor_create( string $title, array $elements, string $status, string $uid ): int {
		$repository = '\Elementor\Modules\Components\Components_Repository';

		if ( ! class_exists( $repository ) ) {
			throw new \Exception( 'Components_Repository no disponible.' );
		}

		return (int) $repository::make()->create( $title, $elements, $status, $uid );
	}
}
