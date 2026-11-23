<?php
/**
 * Edición de un módulo de Divi 5 por ruta y huella, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

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
 * El parche validado sólo trae rutas `<ruta>.desktop.value`; se fusiona en profundidad
 * con los atributos del bloque, de modo que tablet, teléfono, hover y todo lo no tocado
 * queda igual. El contenido que ya pasó el validador se guarda con los filtros kses
 * quitados sólo durante esa escritura: el kses del núcleo corrompe los comentarios de
 * bloque cuando el usuario no tiene `unfiltered_html`.
 */
final class Divi5ModuleEditor {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Adaptador de Divi 5.
	 *
	 * @var Divi5Adapter
	 */
	private Divi5Adapter $adapter;

	/**
	 * Respaldo previo a la escritura.
	 *
	 * @var BuilderBackup
	 */
	private BuilderBackup $backup;

	/**
	 * Copias de trabajo.
	 *
	 * @var WorkingCopy
	 */
	private WorkingCopy $copies;

	/**
	 * Validador del parche.
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Comprobación del bloqueo de edición: fn( \WP_Post ): true|\WP_Error.
	 *
	 * @var callable
	 */
	private $lock_check;

	/**
	 * Indica si Divi 5 está activo: fn(): bool.
	 *
	 * @var callable
	 */
	private $divi5_active;

	/**
	 * Guardado de `post_content`: fn( int $id, string $content ): true|\WP_Error.
	 *
	 * @var callable
	 */
	private $saver;

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
	 */
	public function __construct(
		Settings $settings,
		Divi5Adapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		DiviTreeValidator $validator,
		callable $lock_check,
		?callable $divi5_active = null,
		?callable $saver = null
	) {
		$this->settings     = $settings;
		$this->adapter      = $adapter;
		$this->backup       = $backup;
		$this->copies       = $copies;
		$this->validator    = $validator;
		$this->lock_check   = $lock_check;
		$this->divi5_active = $divi5_active ?? static fn(): bool => defined( 'ET_BUILDER_5_DIR' );
		$this->saver        = $saver ?? static function ( int $id, string $content ) {
			$saved = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $content ),
				),
				true
			);

			return is_wp_error( $saved ) ? $saved : true;
		};
	}

	/**
	 * Edita el módulo.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `path`, `fingerprint`, `content`, `design`, `draft`.
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

				return $this->edit_locked( $fresh, $input );
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
		if ( ! ( $this->divi5_active )() ) {
			return new \WP_Error(
				'nexo_mcp_divi5_required',
				__( 'Divi 5 no está activo en este sitio. Instala y activa el tema Divi 5 para editar módulos.', 'nexo-mcp' )
			);
		}

		if ( ! $this->adapter->handles( $post ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_not_divi5',
				__( 'Esta página no está hecha con Divi 5 (no tiene bloques «divi/»).', 'nexo-mcp' )
			);
		}

		$staged   = WorkingCopy::should_stage( $post, $input, $this->settings );
		$existing = $staged ? WorkingCopy::copy_of( $post ) : 0;
		$source   = $existing > 0 ? get_post( $existing ) : $post;

		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'nexo_mcp_draft_missing', __( 'No se pudo leer la copia de trabajo.', 'nexo-mcp' ) );
		}

		if ( (bool) $this->settings->get( 'drafts_only' ) && 'draft' !== $post->post_status && ! $staged ) {
			return new \WP_Error(
				'nexo_mcp_draft_required',
				__( 'El modo sólo borradores impide editar contenido publicado.', 'nexo-mcp' )
			);
		}

		foreach ( array_unique( array( $post->ID, $source->ID ) ) as $locked_id ) {
			$locked_post = get_post( $locked_id );
			$locked      = $locked_post instanceof \WP_Post ? ( $this->lock_check )( $locked_post ) : true;

			if ( is_wp_error( $locked ) ) {
				return $locked;
			}
		}

		$prepared = $this->prepare( $source, $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return $this->write( $post, $staged, $existing, $prepared );
	}

	/**
	 * Localiza el nodo, comprueba la huella, valida el parche y arma el contenido nuevo.
	 *
	 * @param \WP_Post             $source Contenido sobre el que se trabaja.
	 * @param array<string, mixed> $input  Parámetros.
	 *
	 * @return array{content: string, path: string}|\WP_Error
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

		$patch = array();

		foreach ( array( 'content', 'design' ) as $slot ) {
			if ( array_key_exists( $slot, $input ) && null !== $input[ $slot ] ) {
				$patch[ $slot ] = $input[ $slot ];
			}
		}

		$clean = $this->validator->validate_patch( $type, $patch );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		foreach ( $clean as $part ) {
			$attrs = self::merge( $attrs, $part );
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

	/**
	 * Crea o reutiliza la copia si corresponde, respalda y guarda.
	 *
	 * @param \WP_Post                             $post     Contenido indicado.
	 * @param bool                                 $staged   Si el cambio va a la copia de trabajo.
	 * @param int                                  $existing Copia que ya existía, o 0.
	 * @param array{content: string, path: string} $prepared Contenido nuevo y ruta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write( \WP_Post $post, bool $staged, int $existing, array $prepared ) {
		// Antes de la primera escritura el bloqueo pudo vencer y otro proceso tomarlo.
		$held = WorkingCopy::ensure_lock( (int) $post->ID );

		if ( is_wp_error( $held ) ) {
			return $held;
		}

		$target  = $post;
		$created = false;

		if ( $staged ) {
			$target = $this->copies->obtain( $post );

			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$created = 0 === $existing;
		}

		// Sin respaldo no se escribe.
		if ( ! $this->backup->capture( $target, $this->adapter ) ) {
			return $this->abort(
				$target,
				$created,
				new \WP_Error(
					'nexo_mcp_builder_backup_failed',
					__( 'No fue posible respaldar el contenido; el cambio se canceló.', 'nexo-mcp' )
				)
			);
		}

		$saved = $this->save( $target, $prepared['content'] );

		if ( is_wp_error( $saved ) ) {
			return $this->abort( $target, $created, $saved );
		}

		update_post_meta( $target->ID, '_et_pb_use_builder', 'on' );
		update_post_meta( $target->ID, '_et_pb_use_divi_5', 'on' );

		$this->adapter->flush( $target );

		$fresh = get_post( $target->ID );
		$node  = Divi5Tree::locate( Divi5Tree::build( $fresh instanceof \WP_Post ? (string) $fresh->post_content : $prepared['content'] ), $prepared['path'] );

		$out      = array( 'id' => (int) $target->ID );
		$original = WorkingCopy::original_of( $target );

		if ( $original > 0 ) {
			$out['draft_of'] = $original;
		}

		$out['preview_url'] = (string) get_preview_post_link( $target );
		$out['path']        = $prepared['path'];
		$out['fingerprint'] = null === $node ? '' : (string) $node['fingerprint'];

		return $out;
	}

	/**
	 * Guarda `post_content` con los filtros kses quitados sólo durante la escritura.
	 *
	 * El contenido ya pasó el validador. Los filtros se restauran en un `finally`, también si
	 * el guardado falla o lanza, y sólo si estaban activos.
	 *
	 * @param \WP_Post $target  Destino.
	 * @param string   $content Contenido serializado.
	 *
	 * @return true|\WP_Error
	 */
	private function save( \WP_Post $target, string $content ) {
		$active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		kses_remove_filters();

		try {
			return ( $this->saver )( (int) $target->ID, $content );
		} finally {
			if ( $active ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Devuelve el error y manda a la papelera la copia que esta llamada acaba de crear.
	 *
	 * @param \WP_Post  $target  Destino.
	 * @param bool      $created Si la copia la creó esta llamada.
	 * @param \WP_Error $error   Error a devolver.
	 */
	private function abort( \WP_Post $target, bool $created, \WP_Error $error ): \WP_Error {
		if ( $created ) {
			$discarded = $this->copies->discard( $target );

			if ( is_wp_error( $discarded ) ) {
				return WorkingCopy::orphan_error( $target, $error );
			}
		}

		return $error;
	}
}
