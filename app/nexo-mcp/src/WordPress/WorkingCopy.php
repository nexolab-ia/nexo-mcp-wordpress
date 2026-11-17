<?php
/**
 * Copia de trabajo de contenido publicado.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Builders\BuilderBackup;
use NexoDev\NexoMCP\Builders\BuilderRegistry;
use NexoDev\NexoMCP\Cache\CachePurger;
use NexoDev\NexoMCP\Support\PostLock;
use NexoDev\NexoMCP\Support\Settings;

/**
 * Prepara cambios de una página o entrada publicada sin tocar la versión viva.
 *
 * La copia es un borrador del mismo tipo y sin slug propio que clona título,
 * contenido, taxonomías y la meta del original: así arrastra el documento de
 * Elementor, Divi o WPBakery, el SEO y la plantilla, y su vista previa se ve
 * igual que la página real. Publicarla fusiona sus datos en el original y la
 * borra; descartarla sólo la borra. La herramienta MCP y wp-admin llaman a
 * estas mismas funciones, cada una con sus propios permisos (ADR-031).
 */
final class WorkingCopy {

	/**
	 * En la copia: ID del original.
	 */
	public const META_DRAFT_OF = '_nexo_mcp_draft_of';

	/**
	 * En la copia: última modificación conocida del original al copiarlo.
	 */
	public const META_BASE = '_nexo_mcp_draft_base';

	/**
	 * En el original: ID de su copia.
	 */
	public const META_DRAFT = '_nexo_mcp_draft';

	/**
	 * Estados de lo que un visitante ve o verá: publicado, programado o privado.
	 */
	private const LIVE = array( 'publish', 'future', 'private' );

	/**
	 * Meta que no viaja entre copia y original.
	 *
	 * Son bloqueos y rastros de edición, avisos de ping, el CSS que Elementor
	 * regenera solo y el enlace propio de la copia.
	 */
	private const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_elementor_css' );

	/**
	 * Prefijos de meta que tampoco viajan.
	 */
	private const SKIP_PREFIXES = array( '_wp_trash_meta_', '_nexo_mcp_' );

	/**
	 * Claves de los bloqueos que `with_lock()` retiene ahora (la última es la más interna).
	 *
	 * @var array<int, int>
	 */
	private static array $keys = array();

	/**
	 * Purga de caché tras fusionar.
	 *
	 * @var CachePurger
	 */
	private CachePurger $cache;

	/**
	 * Registro de constructores visuales.
	 *
	 * @var BuilderRegistry
	 */
	private BuilderRegistry $registry;

	/**
	 * Respaldo previo a la fusión.
	 *
	 * @var BuilderBackup
	 */
	private BuilderBackup $backup;

	/**
	 * Construye el servicio.
	 *
	 * @param Settings         $settings Configuración operativa.
	 * @param CachePurger|null $cache    Purgador propio.
	 */
	public function __construct( Settings $settings, ?CachePurger $cache = null ) {
		$this->cache    = $cache ?? new CachePurger( $settings );
		$this->registry = new BuilderRegistry();
		$this->backup   = new BuilderBackup();
	}

	/**
	 * Indica si el contenido está a la vista, o lo estará.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public static function is_live( \WP_Post $post ): bool {
		return in_array( $post->post_status, self::LIVE, true );
	}

	/**
	 * Indica si una llamada pidió trabajar sobre la copia.
	 *
	 * @param array<string, mixed> $input Parámetros recibidos.
	 */
	public static function requested( array $input ): bool {
		return rest_sanitize_boolean( $input['draft'] ?? false );
	}

	/**
	 * Decide si una edición debe ir a la copia de trabajo.
	 *
	 * Con «Editar publicado en copia de trabajo» encendido (por defecto), toda
	 * edición a contenido a la vista va primero a un borrador enlazado, sin
	 * excepción: el original sólo cambia cuando alguien publica la copia. Con
	 * el ajuste apagado rige el comportamiento opcional: el cliente lo pide
	 * con `draft: true`.
	 *
	 * @param \WP_Post             $post     Contenido que se quiere editar.
	 * @param array<string, mixed> $input    Parámetros recibidos.
	 * @param Settings             $settings Configuración operativa.
	 */
	public static function should_stage( \WP_Post $post, array $input, Settings $settings ): bool {
		if ( ! self::is_live( $post ) ) {
			return false;
		}

		return (bool) $settings->get( 'working_copy_default' ) || self::requested( $input );
	}

	/**
	 * Entrega el original del que este contenido es copia, o 0.
	 *
	 * Sólo cuenta un borrador. Si alguien publicó la copia a mano desde el
	 * editor, ya es contenido real: no se fusiona ni se borra como copia.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public static function original_of( \WP_Post $post ): int {
		if ( 'draft' !== $post->post_status ) {
			return 0;
		}

		return absint( get_post_meta( $post->ID, self::META_DRAFT_OF, true ) );
	}

	/**
	 * Entrega la copia vigente de un original, o 0.
	 *
	 * El enlace se confirma desde los dos lados, así una copia borrada,
	 * publicada a mano o duplicada por otro plugin no pasa por vigente.
	 *
	 * @param \WP_Post $original Contenido original.
	 */
	public static function copy_of( \WP_Post $original ): int {
		$copy_id = absint( get_post_meta( $original->ID, self::META_DRAFT, true ) );

		if ( 0 === $copy_id ) {
			return 0;
		}

		$copy = get_post( $copy_id );

		return $copy instanceof \WP_Post && self::original_of( $copy ) === (int) $original->ID ? $copy_id : 0;
	}

	/**
	 * Corre una escritura bajo el bloqueo del original y con el estado releído.
	 *
	 * Todo lo leído antes de esperar el bloqueo puede estar viejo, así que dentro se
	 * descarta la caché del contenido, de su original y de su copia y se vuelve a
	 * resolver la clave: si mientras tanto la copia se descartó o se publicó, el
	 * original cambia y se reintenta con el bueno. `$work` debe releer con
	 * `get_post()` cada contenido que use. Con `$refuse_discarded` se rechaza un
	 * ID que ya es una copia a la papelera (escribir ahí se perdería sin avisar).
	 *
	 * @param int      $post_id          Contenido indicado (original o copia).
	 * @param callable $work             Escritura: fn(): mixed.
	 * @param bool     $refuse_discarded Rechazar copias ya descartadas.
	 *
	 * @return mixed|\WP_Error Lo que devuelve `$work`, `nexo_mcp_busy` o `nexo_mcp_draft_orphan`.
	 */
	public static function with_lock( int $post_id, callable $work, bool $refuse_discarded = true ) {
		$retry = new \stdClass();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$key = self::original_id_for( $post_id );

			$result = PostLock::run(
				$key,
				static function () use ( $post_id, $key, $work, $refuse_discarded, $retry ) {
					PostLock::forget( $post_id, $key );

					if ( self::original_id_for( $post_id ) !== $key ) {
						return $retry;
					}

					$original = get_post( $key );

					if ( $original instanceof \WP_Post ) {
						PostLock::forget( absint( get_post_meta( $original->ID, self::META_DRAFT, true ) ) );
					}

					$post = get_post( $post_id );

					if ( $refuse_discarded && $post instanceof \WP_Post && 'trash' === $post->post_status && absint( get_post_meta( $post->ID, self::META_DRAFT_OF, true ) ) > 0 ) {
						return new \WP_Error(
							'nexo_mcp_draft_orphan',
							__( 'Esta copia de trabajo ya fue descartada o publicada. No se escribió nada.', 'nexo-mcp' )
						);
					}

					self::$keys[] = $key;

					try {
						return $work();
					} finally {
						array_pop( self::$keys );
					}
				}
			);

			if ( $retry !== $result ) {
				return $result;
			}
		}

		return new \WP_Error( 'nexo_mcp_busy', __( 'Otra edición de este contenido está en curso. Reintentá en unos segundos.', 'nexo-mcp' ) );
	}

	/**
	 * Comprueba, justo antes de escribir, que el bloqueo del original sigue siendo de esta llamada.
	 *
	 * Para las rutas que corren dentro de {@see self::with_lock()}: si el bloqueo venció y
	 * otro lo tomó, no se escribe y se responde `nexo_mcp_busy` ({@see PostLock::verify()}).
	 *
	 * @param int $post_id Contenido que se va a escribir (original o copia).
	 *
	 * @return true|\WP_Error
	 */
	public static function ensure_lock( int $post_id ) {
		// La clave que `with_lock()` retiene, no una recalculada: si el original dejó de existir
		// a mitad de la operación, recalcularla daría otra y no se comprobaría nada.
		$key = array() !== self::$keys ? self::$keys[ array_key_last( self::$keys ) ] : self::original_id_for( $post_id );

		return PostLock::verify( $key );
	}

	/**
	 * Error de copia que no se pudo descartar tras un fallo: puede quedar una copia viva.
	 *
	 * @param \WP_Post  $copy  Copia que esta llamada creó.
	 * @param \WP_Error $error Error original, que se conserva en los datos.
	 */
	public static function orphan_error( \WP_Post $copy, \WP_Error $error ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_builder_copy_orphan',
			sprintf(
				/* translators: 1: id de la copia, 2: error original. */
				__( 'La edición falló y tampoco se pudo mandar a la papelera la copia de trabajo %1$d que se había creado: puede quedar una copia a medio escribir (descártala desde WordPress). Error original: %2$s', 'nexo-mcp' ),
				(int) $copy->ID,
				$error->get_error_message()
			),
			array(
				'copy_id'          => (int) $copy->ID,
				'original_error'   => $error->get_error_code(),
				'original_message' => $error->get_error_message(),
			)
		);
	}

	/**
	 * Entrega la copia de un original, creándola si todavía no existe.
	 *
	 * Bajo el bloqueo del original ({@see PostLock}): dos primeras ediciones
	 * simultáneas no crean dos copias. El enlace a la copia se vuelve a leer ya
	 * dentro del bloqueo (otro proceso pudo crearla mientras se esperaba) y, si
	 * el bloqueo no se obtiene, no se crea nada y se devuelve `nexo_mcp_busy`.
	 *
	 * @param \WP_Post $original Contenido publicado.
	 * @return \WP_Post|\WP_Error
	 */
	public function obtain( \WP_Post $original ) {
		return PostLock::run(
			(int) $original->ID,
			function () use ( $original ) {
				// Se relee dentro del bloqueo: el objeto recibido puede ser viejo.
				$fresh    = get_post( $original->ID );
				$original = $fresh instanceof \WP_Post ? $fresh : $original;
				$copy_id  = self::copy_of( $original );

				if ( 0 === $copy_id ) {
					// Crear la copia es la primera escritura: antes, comprobar que el bloqueo sigue siendo nuestro.
					$held = PostLock::verify( (int) $original->ID );

					if ( is_wp_error( $held ) ) {
						return $held;
					}

					$copy_id = $this->create( $original );
				}

				if ( is_wp_error( $copy_id ) ) {
					return $copy_id;
				}

				$copy = get_post( $copy_id );

				return $copy instanceof \WP_Post
					? $copy
					: new \WP_Error( 'nexo_mcp_draft_missing', __( 'No se pudo leer la copia de trabajo.', 'nexo-mcp' ) );
			}
		);
	}

	/**
	 * Fusiona la copia en su original y la borra.
	 *
	 * @param \WP_Post $copy Copia de trabajo.
	 * @return array<string, int>|\WP_Error
	 */
	public function merge( \WP_Post $copy ) {
		return self::with_lock(
			(int) $copy->ID,
			function () use ( $copy ) {
				// La copia que recibió quien llamó se leyó antes de esperar: se vuelve a
				// leer, y si ya no existe o dejó de ser borrador de su original, la
				// comprobación de huérfana de `merge_locked()` lo rechaza.
				$fresh = get_post( $copy->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return $this->orphan();
				}

				$held = self::ensure_lock( (int) $fresh->ID );

				return is_wp_error( $held ) ? $held : $this->merge_locked( $fresh );
			},
			false
		);
	}

	/**
	 * Error de copia huérfana.
	 */
	private function orphan(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_draft_orphan',
			__( 'La página original ya no existe, está en la papelera o tiene otra copia. Descarta esta copia.', 'nexo-mcp' )
		);
	}

	/**
	 * Fusiona ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post $copy Copia de trabajo, releída dentro del bloqueo.
	 * @return array<string, int>|\WP_Error
	 */
	private function merge_locked( \WP_Post $copy ) {
		$original = $this->live_original( $copy );

		if ( null === $original ) {
			return $this->orphan();
		}

		// No se pisa nada: si el original cambió después de copiarlo, la copia
		// se armó sobre una versión que ya no existe.
		if ( $this->is_stale( $copy, $original ) ) {
			return new \WP_Error(
				'nexo_mcp_draft_stale',
				__( 'La página original cambió desde que se creó la copia. Descarta la copia y vuelve a crearla.', 'nexo-mcp' )
			);
		}

		// Las revisiones de WordPress no guardan `post_meta`: sin este respaldo
		// no habría cómo volver al documento del constructor (ADR-024).
		$adapter = $this->registry->resolve( $original );

		if ( null !== $adapter && ! $this->backup->capture( $original, $adapter ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_backup_failed',
				__( 'No fue posible respaldar el original; no se publicó nada.', 'nexo-mcp' )
			);
		}

		// Va primero porque es lo único que puede fallar: si falla, el original
		// queda intacto. WordPress guarda en la revisión lo que había.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $original->ID,
					'post_title'   => $copy->post_title,
					'post_content' => $copy->post_content,
					'post_excerpt' => $copy->post_excerpt,
				)
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Si una taxonomía no se pudiera escribir, el original conserva sus
		// términos: no se pierde nada, así que no se frena la fusión.
		$this->copy_terms( $copy->ID, $original->ID, $original->post_type );
		$this->copy_meta( $copy->ID, $original->ID );

		$merged = get_post( $original->ID );

		if ( $merged instanceof \WP_Post ) {
			$adapter = $this->registry->resolve( $merged );

			if ( null !== $adapter ) {
				$adapter->flush( $merged );
			}
		}

		$this->cache->after_post_change( $original->ID );

		wp_delete_post( $copy->ID, true );
		delete_post_meta( $original->ID, self::META_DRAFT );

		return array(
			'id'          => (int) $original->ID,
			'merged_from' => (int) $copy->ID,
		);
	}

	/**
	 * Entrega el original vigente de una copia, o null si quedó huérfana.
	 *
	 * Una copia es huérfana si su original ya no existe, está en la papelera,
	 * es de otro tipo o apunta a otra copia.
	 *
	 * @param \WP_Post $copy Copia de trabajo.
	 */
	public function live_original( \WP_Post $copy ): ?\WP_Post {
		$original = get_post( self::original_of( $copy ) );

		if ( ! $original instanceof \WP_Post
			|| 'trash' === $original->post_status
			|| $original->post_type !== $copy->post_type
			|| self::copy_of( $original ) !== (int) $copy->ID
		) {
			return null;
		}

		return $original;
	}

	/**
	 * Indica si el original cambió desde que se creó la copia.
	 *
	 * Es la regla con la que `merge()` rechaza publicar: sin base registrada,
	 * o con una última modificación distinta a la base, la copia es obsoleta.
	 *
	 * @param \WP_Post $copy     Copia de trabajo.
	 * @param \WP_Post $original Su original vigente.
	 */
	public function is_stale( \WP_Post $copy, \WP_Post $original ): bool {
		$base = (string) get_post_meta( $copy->ID, self::META_BASE, true );

		return '' === $base || $this->last_change( $original ) !== $base;
	}

	/**
	 * Entrega el ID del original si el contenido es una copia, o el mismo ID.
	 *
	 * Sirve para decidir permisos sobre el original aunque la llamada traiga
	 * el ID de la copia.
	 *
	 * @param int $id Contenido consultado.
	 */
	public static function original_id_for( int $id ): int {
		$post = get_post( $id );

		if ( $post instanceof \WP_Post ) {
			$original = self::original_of( $post );

			// Sólo si el original existe: con el original borrado, el permiso se
			// comprueba sobre la copia y la herramienta explica que está huérfana
			// en vez de responder con una denegación genérica.
			if ( $original > 0 && get_post( $original ) instanceof \WP_Post ) {
				return $original;
			}
		}

		return $id;
	}

	/**
	 * Entrega la meta que la fusión copiaría, con los valores ya deserializados.
	 *
	 * @param int $id Contenido.
	 * @return array<string, list<mixed>> Clave => valores.
	 */
	public function travelling_meta( int $id ): array {
		$all = get_post_meta( $id );

		if ( ! is_array( $all ) ) {
			return array();
		}

		$meta = array();

		foreach ( $all as $key => $values ) {
			$key = (string) $key;

			if ( ! is_array( $values ) || array() === $values || ! self::travels( $key ) ) {
				continue;
			}

			$meta[ $key ] = array_values( array_map( 'maybe_unserialize', $values ) );
		}

		return $meta;
	}

	/**
	 * Entrega las taxonomías que la fusión copia para un tipo de contenido.
	 *
	 * @param string $post_type Tipo de contenido.
	 * @return list<string>
	 */
	public function copied_taxonomies( string $post_type ): array {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			// El grupo de traducciones de Polylang une cada página con sus
			// versiones en otros idiomas. La copia no es una traducción, así
			// que no entra al grupo; el idioma (`language`) sí viaja.
			if ( 'post_translations' !== $taxonomy ) {
				$taxonomies[] = (string) $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Manda la copia a la papelera y limpia el enlace del original.
	 *
	 * Nunca se borra de forma permanente: una equivocación del cliente MCP no
	 * debe ser irreversible, y desde la papelera la persona puede recuperar la
	 * edición descartada. El original queda sin copia, así que la próxima
	 * edición arma una nueva; si se restaura esta desde la papelera, `merge()`
	 * la rechaza como huérfana.
	 *
	 * @param \WP_Post $copy Copia de trabajo.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function discard( \WP_Post $copy ) {
		return self::with_lock(
			(int) $copy->ID,
			function () use ( $copy ) {
				$fresh       = get_post( $copy->ID );
				$original_id = $fresh instanceof \WP_Post ? self::original_of( $fresh ) : 0;

				if ( ! $fresh instanceof \WP_Post || 0 === $original_id ) {
					return new \WP_Error( 'nexo_mcp_draft_not_copy', __( 'Este contenido no es una copia de trabajo.', 'nexo-mcp' ) );
				}

				$held = self::ensure_lock( (int) $fresh->ID );

				return is_wp_error( $held ) ? $held : $this->discard_locked( $fresh, $original_id );
			},
			false
		);
	}

	/**
	 * Descarta ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post $copy        Copia de trabajo.
	 * @param int      $original_id Su original.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function discard_locked( \WP_Post $copy, int $original_id ) {
		if ( ! wp_trash_post( $copy->ID ) ) {
			return new \WP_Error( 'nexo_mcp_draft_trash_failed', __( 'WordPress no pudo enviar la copia a la papelera.', 'nexo-mcp' ) );
		}

		// Sólo si todavía apunta a esta copia: otra más nueva no se desengancha.
		if ( absint( get_post_meta( $original_id, self::META_DRAFT, true ) ) === (int) $copy->ID ) {
			delete_post_meta( $original_id, self::META_DRAFT );
		}

		return array(
			'id'        => (int) $copy->ID,
			'discarded' => true,
			'status'    => (string) get_post_status( $copy->ID ),
			'message'   => __( 'La copia está en la papelera y puede restaurarse desde WordPress.', 'nexo-mcp' ),
		);
	}

	/**
	 * Crea la copia de un original publicado.
	 *
	 * @param \WP_Post $original Contenido publicado.
	 * @return int|\WP_Error
	 */
	private function create( \WP_Post $original ) {
		// Sin `post_name`: un borrador no recibe slug automático, así la copia
		// no compite con el original por su dirección.
		$copy_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $original->post_type,
					'post_status'  => 'draft',
					'post_author'  => (int) $original->post_author,
					'post_title'   => $original->post_title,
					'post_content' => $original->post_content,
					'post_excerpt' => $original->post_excerpt,
					'menu_order'   => (int) $original->menu_order,
					'post_parent'  => (int) $original->post_parent,
					'post_name'    => '',
				)
			),
			true
		);

		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$copy_id = (int) $copy_id;

		update_post_meta( $copy_id, self::META_DRAFT_OF, (int) $original->ID );
		update_post_meta( $copy_id, self::META_BASE, $this->last_change( $original ) );

		// Una copia sin sus términos se los quitaría al original al fusionarla.
		$terms = $this->copy_terms( (int) $original->ID, $copy_id, $original->post_type );

		if ( is_wp_error( $terms ) ) {
			wp_delete_post( $copy_id, true );
			return $terms;
		}

		$this->copy_meta( (int) $original->ID, $copy_id );
		update_post_meta( $original->ID, self::META_DRAFT, $copy_id );

		return $copy_id;
	}

	/**
	 * Última modificación conocida de un contenido.
	 *
	 * Es `post_modified_gmt`, salvo que el conector haya editado después un
	 * nodo del constructor: Elementor guarda en `post_meta` y esa escritura no
	 * mueve la fecha, pero sí deja la hora de su respaldo (ADR-024). Sin
	 * mirarla, la fusión pisaría ese cambio. Ambas van en UTC y con el mismo
	 * formato, así que se comparan como texto.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	private function last_change( \WP_Post $post ): string {
		$backup = $this->backup->describe( (int) $post->ID );

		return max( (string) $post->post_modified_gmt, (string) ( $backup['captured_at'] ?? '' ) );
	}

	/**
	 * Copia los términos de cada taxonomía del tipo.
	 *
	 * @param int    $from      Contenido de origen.
	 * @param int    $to        Contenido de destino.
	 * @param string $post_type Tipo de ambos.
	 * @return true|\WP_Error
	 */
	private function copy_terms( int $from, int $to, string $post_type ) {
		foreach ( $this->copied_taxonomies( $post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			$set = wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy );

			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		return true;
	}

	/**
	 * Copia la meta que viaja, clave por clave.
	 *
	 * Sólo actualiza o agrega: una clave que el destino tenga y el origen no se
	 * deja como está. Los valores llegan crudos de la base, así que se
	 * deserializan antes de escribirlos para no serializarlos dos veces.
	 *
	 * @param int $from Contenido de origen.
	 * @param int $to   Contenido de destino.
	 */
	private function copy_meta( int $from, int $to ): void {
		foreach ( $this->travelling_meta( $from ) as $key => $values ) {
			if ( 1 === count( $values ) ) {
				update_post_meta( $to, $key, wp_slash( $values[0] ) );
				continue;
			}

			delete_post_meta( $to, $key );

			foreach ( $values as $value ) {
				add_post_meta( $to, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Indica si una clave de meta viaja entre copia y original.
	 *
	 * @param string $key Clave evaluada.
	 */
	private static function travels( string $key ): bool {
		if ( in_array( $key, self::SKIP_META, true ) ) {
			return false;
		}

		foreach ( self::SKIP_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}
}
