<?php
/**
 * Creación de una página de Divi 5 en borrador con módulos reales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Valida las secciones y crea la página: nunca la publica.
 *
 * Orden: Divi 5 activo, título, validación propia ({@see DiviTreeValidator::validate_tree()}
 * con `divi/placeholder` como padre, así que la raíz sólo admite secciones), serialización a
 * bloques bajo un `divi/placeholder` con `builderVersion`, e inserción del borrador con los
 * filtros kses quitados sólo durante el insert ({@see Divi5ContentWriter::without_kses()}).
 * Después se marcan las metas de Divi 5, se vacía la caché CSS y se comprueba que lo guardado
 * es lo serializado. Si algo falla tras crear el borrador, se borra: un error no deja páginas a medias.
 */
final class Divi5PageCreator {

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
	 * Inserción del borrador: fn( array $data ): int|\WP_Error.
	 *
	 * @var callable
	 */
	private $inserter;

	/**
	 * Borrado del borrador a medias: fn( int $id ): mixed (falsy si falla).
	 *
	 * @var callable
	 */
	private $deleter;

	/**
	 * Construye el creador.
	 *
	 * @param DiviTreeValidator|null $validator    Validador (por omisión, el de producción).
	 * @param callable|null          $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null          $version      fn(): string. Por omisión, la versión de Divi instalada.
	 * @param callable|null          $inserter     fn( array ): int|\WP_Error. Por omisión, `wp_insert_post()` (sólo pruebas).
	 * @param Divi5Adapter|null      $adapter      Adaptador de Divi 5.
	 * @param callable|null          $deleter      fn( int ): mixed. Por omisión, `wp_delete_post( $id, true )` (sólo pruebas).
	 */
	public function __construct(
		?DiviTreeValidator $validator = null,
		?callable $divi5_active = null,
		?callable $version = null,
		?callable $inserter = null,
		?Divi5Adapter $adapter = null,
		?callable $deleter = null
	) {
		$this->validator    = $validator ?? new DiviTreeValidator();
		$this->divi5_active = $divi5_active ?? array( Divi5ContentWriter::class, 'divi5_installed' );
		$this->version      = $version ?? array( Divi5BlockBuilder::class, 'installed_version' );
		$this->inserter     = $inserter ?? static fn( array $data ) => wp_insert_post( $data, true );
		$this->adapter      = $adapter ?? new Divi5Adapter();
		$this->deleter      = $deleter ?? static fn( int $id ) => wp_delete_post( $id, true );
	}

	/**
	 * Crea la página.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta: `title`, `sections`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ) {
		if ( ! ( $this->divi5_active )() ) {
			return Divi5ContentWriter::required_error();
		}

		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';

		if ( '' === $title ) {
			return new \WP_Error(
				'nexo_mcp_divi_title_required',
				__( 'Indica el título de la página.', 'nexo-mcp' )
			);
		}

		$clean = $this->validator->validate_tree( $input['sections'] ?? null, 'divi/placeholder' );

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

		return $this->save_page( $title, $content );
	}

	/**
	 * Inserta el borrador; si algo falla después, lo borra.
	 *
	 * @param string $title   Título.
	 * @param string $content Contenido serializado.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function save_page( string $title, string $content ) {
		$data     = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => wp_slash( $title ),
			'post_content' => wp_slash( $content ),
		);
		$inserter = $this->inserter;

		try {
			$post_id = Divi5ContentWriter::without_kses( static fn() => $inserter( $data ) );
		} catch ( \Throwable $e ) {
			return $this->failed( __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		try {
			$post = get_post( $post_id );

			// Un filtro que reescribe el contenido (kses de otro plugin, por ejemplo) deja Divi roto.
			if ( ! $post instanceof \WP_Post || $content !== $post->post_content ) {
				return $this->rollback( $post_id, __( 'WordPress guardó un contenido distinto del pedido.', 'nexo-mcp' ) );
			}

			Divi5ContentWriter::mark( $post );
			$this->adapter->flush( $post );
		} catch ( \Throwable $e ) {
			return $this->rollback( $post_id, __( 'error interno al guardar.', 'nexo-mcp' ) );
		}

		$tree  = Divi5Tree::build( $content );
		$nodes = array();

		foreach ( $tree as $node ) {
			$nodes = array_merge( $nodes, Divi5Tree::summarize( $node ) );
		}

		return array(
			'id'          => $post_id,
			'status'      => (string) get_post_status( $post_id ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'nodes'       => $nodes,
		);
	}

	/**
	 * Borra el borrador creado y devuelve el error.
	 *
	 * @param int    $post_id Borrador creado por esta llamada.
	 * @param string $reason  Motivo.
	 */
	private function rollback( int $post_id, string $reason ): \WP_Error {
		$error   = $this->failed( $reason );
		$deleted = ( $this->deleter )( $post_id );

		if ( ! $deleted ) {
			$draft = get_post( $post_id );

			return $draft instanceof \WP_Post
				? WorkingCopy::orphan_error( $draft, $error )
				: $error;
		}

		return $this->failed( $reason, true );
	}

	/**
	 * Error de guardado.
	 *
	 * @param string $reason  Motivo.
	 * @param bool   $deleted Si había un borrador a medias y se eliminó.
	 */
	private function failed( string $reason, bool $deleted = false ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_divi_save_failed',
			sprintf(
				/* translators: %s: motivo. */
				__( 'No se pudo crear la página de Divi 5: %s', 'nexo-mcp' ),
				$reason
			) . ( $deleted ? ' ' . __( 'El borrador creado se eliminó.', 'nexo-mcp' ) : '' )
		);
	}
}
