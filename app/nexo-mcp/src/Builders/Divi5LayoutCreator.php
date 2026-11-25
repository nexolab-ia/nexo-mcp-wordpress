<?php
/**
 * Creación de un layout del Theme Builder de Divi 5 (encabezado, cuerpo o pie), publicado y sin asignar.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\PostLock;

/**
 * Valida las secciones y crea el layout: `publish` y sin asignar a ninguna plantilla (inerte hasta enlazarlo).
 *
 * Orden: permiso del Theme Builder ({@see DiviLayoutGuard::denied()}, ANTES de mirar la entrada), tipo
 * (`header`, `body`, `footer`), Divi 5 activo, título, validación propia ({@see DiviTreeValidator::validate_tree()} en modo
 * layout, así que además de los módulos de página admite `divi/post-content`, `divi/post-title` y `divi/menu`, con
 * `divi/placeholder` como padre), serialización a bloques bajo un `divi/placeholder` con `builderVersion`, e inserción con
 * los filtros kses quitados sólo durante el insert ({@see Divi5ContentWriter::without_kses()}). Se inserta con
 * `et_theme_builder_insert_layout()` si existe (pone el término `layout_type` y las metas del constructor, como el Theme
 * Builder de Divi) y con `wp_insert_post()` si no. Después se marcan las metas de Divi 5, se vacía el CSS ({@see Divi5Adapter::flush()}:
 * en un layout vivo, el de todo el sitio) y se comprueba que lo guardado es lo serializado. Si algo falla tras crear
 * el layout, se borra: un error no deja un layout PUBLICADO a medias (un layout no `publish` también lo ve el público, así
 * que nunca se crea en otro estado). No toca el Theme Builder de Divi ni ninguna plantilla (eso es T6) y nunca usa
 * `sync-to-server` (L4 (b)).
 *
 * Un cuerpo sin `divi/post-content` se crea igual (la regla S6 se aplica al asignarlo) pero la respuesta lo avisa.
 *
 * Si algún nodo trae `preset`, validar y crear corren bajo el bloqueo de presets ({@see DiviDesignStore::LOCK_ID}).
 */
final class Divi5LayoutCreator {

	/**
	 * Permiso del Theme Builder: fn(): bool.
	 *
	 * @var callable
	 */
	private $allowed;

	/**
	 * Validador del árbol.
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Adaptador de Divi 5 (vaciado de caché).
	 *
	 * @var Divi5Adapter
	 */
	private Divi5Adapter $adapter;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Versión de Divi para `builderVersion`: fn(): string.
	 *
	 * @var callable
	 */
	private $version;

	/**
	 * Inserción del layout: fn( array $data ): int|\WP_Error|false.
	 *
	 * @var callable
	 */
	private $inserter;

	/**
	 * Borrado del layout a medias: fn( int $id ): mixed (falsy si falla).
	 *
	 * @var callable
	 */
	private $deleter;

	/**
	 * Bloqueo de presets: fn( callable ): mixed.
	 *
	 * @var callable
	 */
	private $presets_lock;

	/**
	 * Construye el creador.
	 *
	 * @param DiviTreeValidator $validator    Validador (el de producción; se usa en modo layout).
	 * @param callable          $allowed      fn(): bool. `PermissionPolicy::can_write_divi_theme_builder()`.
	 * @param callable|null     $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null     $version      fn(): string. Por omisión, la versión de Divi instalada.
	 * @param callable|null     $inserter     fn( array ): int|\WP_Error|false. Por omisión, `et_theme_builder_insert_layout()` si existe y si no `wp_insert_post()`.
	 * @param Divi5Adapter|null $adapter      Adaptador de Divi 5.
	 * @param callable|null     $deleter      fn( int ): mixed. Por omisión, `wp_delete_post( $id, true )`.
	 * @param callable|null     $presets_lock fn( callable ): mixed. Bloqueo de presets; por omisión, el de {@see DiviDesignStore::LOCK_ID}.
	 */
	public function __construct(
		DiviTreeValidator $validator,
		callable $allowed,
		?callable $divi5_active = null,
		?callable $version = null,
		?callable $inserter = null,
		?Divi5Adapter $adapter = null,
		?callable $deleter = null,
		?callable $presets_lock = null
	) {
		$this->validator    = $validator;
		$this->allowed      = $allowed;
		$this->divi5_active = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->version      = $version ?? array( Divi5BlockBuilder::class, 'installed_version' );
		$this->inserter     = $inserter ?? static function ( array $data ) {
			return function_exists( 'et_theme_builder_insert_layout' ) ? et_theme_builder_insert_layout( $data ) : wp_insert_post( $data, true );
		};
		$this->adapter      = $adapter ?? new Divi5Adapter();
		$this->deleter      = $deleter ?? static fn( int $id ) => wp_delete_post( $id, true );
		$this->presets_lock = $presets_lock ?? static fn( callable $work ) => PostLock::run( DiviDesignStore::LOCK_ID, $work );
	}

	/**
	 * Crea el layout.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `type`, `title`, `sections`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ) {
		if ( ! ( $this->allowed )() ) {
			return DiviLayoutGuard::denied();
		}

		$slot = isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : '';

		if ( ! isset( DiviLayoutGuard::LAYOUT_TYPES[ $slot ] ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_layout_type_invalid',
				__( 'type debe ser header, body o footer.', 'nexo-mcp' )
			);
		}

		if ( ! ( $this->divi5_active )() ) {
			return Divi5ContentWriter::required_error();
		}

		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';

		if ( '' === $title ) {
			return new \WP_Error(
				'nexo_mcp_divi_title_required',
				__( 'Indica el título del layout.', 'nexo-mcp' )
			);
		}

		if ( DiviTreeValidator::tree_has_preset( $input['sections'] ?? null ) ) {
			return ( $this->presets_lock )( fn() => $this->create_unlocked( $slot, $title, $input ) );
		}

		return $this->create_unlocked( $slot, $title, $input );
	}

	/**
	 * Valida y crea (con el bloqueo de presets en mano si hace falta).
	 *
	 * @param string               $slot  `header`, `body` o `footer`.
	 * @param string               $title Título limpio.
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_unlocked( string $slot, string $title, array $input ) {
		$clean = $this->validator->with_layout()->validate_tree( $input['sections'] ?? null, 'divi/placeholder' );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$blocks  = Divi5BlockBuilder::build(
			array(
				array(
					'type'     => 'divi/placeholder',
					'children' => $clean,
				),
			),
			(string) ( $this->version )()
		);
		$content = serialize_blocks( $blocks );

		return $this->save_layout( $slot, $title, $content );
	}

	/**
	 * Inserta el layout publicado; si algo falla después, lo borra.
	 *
	 * @param string $slot    `header`, `body` o `footer`.
	 * @param string $title   Título.
	 * @param string $content Contenido serializado.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function save_layout( string $slot, string $title, string $content ) {
		$data     = array(
			'post_type'    => DiviLayoutGuard::LAYOUT_TYPES[ $slot ],
			'post_status'  => 'publish',
			'post_title'   => wp_slash( $title ),
			'post_content' => wp_slash( $content ),
		);
		$inserter = $this->inserter;

		try {
			$layout_id = Divi5ContentWriter::without_kses( static fn() => $inserter( $data ) );
		} catch ( \Throwable $e ) {
			return $this->failed( __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		if ( is_wp_error( $layout_id ) ) {
			return $layout_id;
		}

		if ( ! is_int( $layout_id ) || $layout_id <= 0 ) {
			return $this->failed( __( 'WordPress no creó el layout.', 'nexo-mcp' ) );
		}

		try {
			$post = get_post( $layout_id );

			// Un filtro que reescribe el contenido (kses de otro plugin, por ejemplo) deja Divi roto.
			if ( ! $post instanceof \WP_Post || $content !== $post->post_content || 'publish' !== $post->post_status ) {
				return $this->rollback( $layout_id, __( 'WordPress guardó un contenido o un estado distinto del pedido.', 'nexo-mcp' ) );
			}

			Divi5ContentWriter::mark( $post );
			$this->adapter->flush( $post );
		} catch ( \Throwable $e ) {
			return $this->rollback( $layout_id, __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		$nodes = array();

		foreach ( Divi5Tree::build( $content ) as $node ) {
			$nodes = array_merge( $nodes, Divi5Tree::summarize( $node ) );
		}

		return array(
			'id'        => $layout_id,
			'type'      => $slot,
			'post_type' => DiviLayoutGuard::LAYOUT_TYPES[ $slot ],
			'status'    => (string) get_post_status( $layout_id ),
			'assigned'  => false,
			'nodes'     => $nodes,
			'warnings'  => $this->warnings( $slot, $content ),
		);
	}

	/**
	 * Avisos de la respuesta: el layout nace sin asignar y, si es un cuerpo, sin `divi/post-content`.
	 *
	 * @param string $slot    `header`, `body` o `footer`.
	 * @param string $content Contenido guardado.
	 *
	 * @return array<int, string>
	 */
	private function warnings( string $slot, string $content ): array {
		$warnings = array(
			__( 'El layout está publicado pero sin asignar a ninguna plantilla: no se muestra en el sitio y Divi lo marca como sin uso y lo manda a la papelera a los 7 días si sigue así. Asígnalo a una plantilla antes.', 'nexo-mcp' ),
		);

		if ( 'body' === $slot && ! DiviLayoutGuard::has_post_content( $content ) ) {
			$warnings[] = __( 'Este cuerpo no tiene divi/post-content: asignado a páginas, entradas o la tienda REEMPLAZA su contenido (se pierden el texto de la página y, en WooCommerce, el carrito y el pago). Añade un divi/post-content en una columna antes de asignarlo.', 'nexo-mcp' );
		}

		return $warnings;
	}

	/**
	 * Borra el layout creado y devuelve el error.
	 *
	 * @param int    $layout_id Layout creado por esta llamada.
	 * @param string $reason    Motivo.
	 */
	private function rollback( int $layout_id, string $reason ): \WP_Error {
		$deleted = ( $this->deleter )( $layout_id );

		if ( ! $deleted && get_post( $layout_id ) instanceof \WP_Post ) {
			return new \WP_Error(
				'nexo_mcp_divi_save_failed',
				sprintf(
					/* translators: 1: motivo, 2: id del layout. */
					__( 'No se pudo crear el layout de Divi 5: %1$s El layout %2$d quedó creado y publicado, sin asignar, y no se pudo borrar: revísalo o bórralo desde WordPress.', 'nexo-mcp' ),
					$reason,
					$layout_id
				)
			);
		}

		return $this->failed( $reason, true );
	}

	/**
	 * Error de guardado.
	 *
	 * @param string $reason  Motivo.
	 * @param bool   $deleted Si había un layout a medias y se eliminó.
	 */
	private function failed( string $reason, bool $deleted = false ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_save_failed',
			sprintf(
				/* translators: %s: motivo. */
				__( 'No se pudo crear el layout de Divi 5: %s', 'nexo-mcp' ),
				$reason
			) . ( $deleted ? ' ' . __( 'El layout creado se eliminó.', 'nexo-mcp' ) : '' )
		);
	}
}
