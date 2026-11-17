<?php
/**
 * Creación de una página atómica de Elementor 4 en borrador.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Valida el árbol y crea la página: nunca la publica.
 *
 * Orden: título, requisitos de Elementor (v4 activo), validación propia
 * ({@see ElementorTreeValidator}), validación de Elementor (`Props_Parser` y
 * `Style_Parser` con el esquema de cada tipo) y sólo entonces se escribe. Si el
 * guardado falla se borra el borrador recién creado, de modo que un error no deja
 * páginas a medias.
 *
 * Toda clase de Elementor se usa tras comprobar que existe: sin Elementor la
 * herramienta responde un error claro en vez de un fatal.
 */
final class ElementorPageCreator {

	/**
	 * Sistema de diseño: acceso guardado a Elementor y a las clases globales.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $design;

	/**
	 * Guarda de esquema compartida con la edición de secciones.
	 *
	 * @var ElementorSchemaGuard
	 */
	private ElementorSchemaGuard $guard;

	/**
	 * Construye el creador.
	 *
	 * @param ElementorDesignSystem|null $design Acceso a Elementor.
	 * @param ElementorSchemaGuard|null  $guard  Guarda de esquema (las pruebas ponen una sin Elementor).
	 */
	public function __construct( ?ElementorDesignSystem $design = null, ?ElementorSchemaGuard $guard = null ) {
		$this->design = $design ?? new ElementorDesignSystem();
		$this->guard  = $guard ?? new ElementorSchemaGuard( $this->design );
	}

	/**
	 * Crea la página.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ) {
		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? trim( sanitize_text_field( $input['title'] ) ) : '';

		if ( '' === $title ) {
			return new \WP_Error(
				'nexo_mcp_elementor_title_required',
				__( 'Indica el título de la página.', 'nexo-mcp' )
			);
		}

		$ready = $this->guard->require_v4();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$elements = $input['elements'] ?? null;
		$checked  = $this->guard->validator()->validate( $elements );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$checked = $this->guard->validate( $elements );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$tree = ( new ElementorTreeBuilder() )->build( $elements );

		return $this->save_page( $title, isset( $input['slug'] ) && is_string( $input['slug'] ) ? $input['slug'] : '', $tree );
	}

	/**
	 * Crea el borrador y guarda el árbol; si falla, borra lo creado.
	 *
	 * @param string                           $title Título.
	 * @param string                           $slug  Slug opcional.
	 * @param array<int, array<string, mixed>> $tree  Árbol armado.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function save_page( string $title, string $slug, array $tree ) {
		$post = array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $title,
		);

		if ( '' !== $slug ) {
			$post['post_name'] = sanitize_title( $slug );
		}

		$post_id = wp_insert_post( $post, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$plugin   = $this->design->plugin();
		$expected = ElementorTreeBuilder::count_nodes( $tree );

		try {
			$documents = null !== $plugin && isset( $plugin->documents ) && is_object( $plugin->documents ) ? $plugin->documents : null;
			$document  = null !== $documents && method_exists( $documents, 'get' ) ? $documents->get( $post_id ) : false;

			if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) || ! method_exists( $document, 'set_is_built_with_elementor' ) ) {
				return $this->rollback( $post_id, __( 'Elementor no reconoce las páginas como documentos editables en este sitio.', 'nexo-mcp' ) );
			}

			$document->set_is_built_with_elementor( true );

			if ( true !== $document->save( array( 'elements' => $tree ) ) ) {
				return $this->rollback( $post_id, __( 'Elementor no pudo guardar el árbol.', 'nexo-mcp' ) );
			}
		} catch ( \Throwable $e ) {
			return $this->rollback(
				$post_id,
				sprintf(
					/* translators: %s: mensaje de Elementor. */
					__( 'Elementor no pudo guardar el árbol: %s', 'nexo-mcp' ),
					$e->getMessage()
				)
			);
		}

		// Elementor descarta en silencio los tipos que no tiene registrados: si
		// lo guardado no es todo lo pedido, la página no sirve y se deshace.
		$stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );

		if ( ! is_array( $stored ) || ElementorTreeBuilder::count_nodes( $stored ) !== $expected ) {
			return $this->rollback( $post_id, __( 'Elementor guardó menos elementos de los pedidos.', 'nexo-mcp' ) );
		}

		return array(
			'id'          => $post_id,
			'status'      => (string) get_post_status( $post_id ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'edit_url'    => method_exists( $document, 'get_edit_url' ) ? (string) $document->get_edit_url() : '',
			'nodes'       => $expected,
		);
	}

	/**
	 * Borra el borrador creado y devuelve el error.
	 *
	 * @param int    $post_id Borrador creado por esta llamada.
	 * @param string $reason  Motivo.
	 */
	private function rollback( int $post_id, string $reason ): \WP_Error {
		wp_delete_post( $post_id, true );

		return new \WP_Error(
			'nexo_mcp_elementor_save_failed',
			$reason . ' ' . __( 'El borrador creado se eliminó.', 'nexo-mcp' )
		);
	}
}
