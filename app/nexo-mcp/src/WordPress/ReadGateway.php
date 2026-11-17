<?php
/**
 * Lecturas seguras de contenido WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Builders\BuilderRegistry;

/**
 * Aísla las consultas de WordPress usadas por las abilities de sólo lectura.
 */
final class ReadGateway {

	/**
	 * Registro de constructores visuales.
	 *
	 * @var BuilderRegistry|null
	 */
	private ?BuilderRegistry $builder_registry = null;

	/**
	 * Devuelve información pública y operativa del sitio.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_info(): array {
		return array(
			'name'           => (string) get_bloginfo( 'name' ),
			'description'    => (string) get_bloginfo( 'description' ),
			'url'            => home_url( '/' ),
			'language'       => (string) get_bloginfo( 'language' ),
			// El identificador (America/Santiago) si el sitio eligió una ciudad;
			// si no, el desfase fijo (-03:00). Así lo resuelve wp_timezone_string().
			'timezone'       => (string) wp_timezone_string(),
			// «Disuadir a los motores de búsqueda» guarda 0; los plugins de
			// sitio privado usan negativos.
			'search_visible' => (int) get_option( 'blog_public', 1 ) > 0,
			'wordpress'      => (string) get_bloginfo( 'version' ),
			'multisite'      => is_multisite(),
			'builders'       => $this->active_builders(),
		);
	}

	/**
	 * Lista contenido de un tipo permitido.
	 *
	 * @param string               $post_type page o post.
	 * @param array<string, mixed> $input     Filtros validados por Abilities API.
	 * @return list<array<string, mixed>>
	 */
	public function list_content( string $post_type, array $input ): array {
		$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$status   = (string) ( $input['status'] ?? 'publish' );
		$search   = sanitize_text_field( (string) ( $input['search'] ?? '' ) );

		$query = new \WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => $status,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				's'                   => $search,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$items[] = $this->summarize_post( $post );
		}

		return $items;
	}

	/**
	 * Obtiene una página o artículo por ID y tipo.
	 *
	 * @param int    $post_id   ID solicitado.
	 * @param string $post_type page o post.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_content( int $post_id, string $post_type ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'nexo_mcp_content_not_found',
				sprintf(
					/* translators: %d: ID solicitado. */
					__( 'No existe contenido con el ID %d.', 'nexo-mcp' ),
					$post_id
				)
			);
		}

		// Decir el tipo real permite que el cliente reintente con la herramienta
		// correcta en vez de insistir por permisos.
		if ( $post_type !== $post->post_type ) {
			return new \WP_Error(
				'nexo_mcp_content_type_mismatch',
				sprintf(
					/* translators: 1: ID solicitado, 2: tipo real del contenido, 3: tipo pedido. */
					__( 'El contenido %1$d es de tipo «%2$s», no «%3$s». Usa la herramienta correspondiente a ese tipo.', 'nexo-mcp' ),
					$post_id,
					$post->post_type,
					$post_type
				)
			);
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return new \WP_Error(
				'nexo_mcp_content_forbidden',
				__( 'No tienes permiso para leer este contenido.', 'nexo-mcp' )
			);
		}

		$salida = array_merge(
			$this->summarize_post( $post ),
			array(
				'content' => (string) $post->post_content,
				'excerpt' => (string) $post->post_excerpt,
				'author'  => (int) $post->post_author,
			)
		);

		// Copia de trabajo (ADR-031): la copia dice de quién es y dónde verla;
		// el original, si tiene una pendiente.
		$original = WorkingCopy::original_of( $post );

		if ( $original > 0 ) {
			$salida['draft_of']    = $original;
			$salida['preview_url'] = (string) get_preview_post_link( $post );
		} else {
			$copy = WorkingCopy::copy_of( $post );

			if ( $copy > 0 ) {
				$salida['draft_id'] = $copy;
			}
		}

		return $salida;
	}

	/**
	 * Normaliza campos compartidos sin exponer datos internos.
	 *
	 * @param \WP_Post $post Contenido.
	 * @return array<string, mixed>
	 */
	private function summarize_post( \WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'title'    => get_the_title( $post ),
			'status'   => (string) $post->post_status,
			'slug'     => (string) $post->post_name,
			'date_gmt' => (string) $post->post_date_gmt,
			'modified' => (string) $post->post_modified_gmt,
			'link'     => (string) get_permalink( $post ),
			'builder'  => $this->detect_builder( $post ),
		);
	}

	/**
	 * Identifica el constructor visual que gobierna un contenido.
	 *
	 * Viaja en cada lectura a propósito. Sin este dato, un cliente que edite
	 * `content` sobre una página de Elementor recibiría una confirmación de
	 * éxito mientras el cambio queda invisible, porque el constructor lee su
	 * propio árbol y no `post_content`.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	private function detect_builder( \WP_Post $post ): ?string {
		$adapter = $this->builders()->resolve( $post );

		return null === $adapter ? null : $adapter->slug();
	}

	/**
	 * Enumera los constructores visuales activos en el sitio.
	 *
	 * Permite al cliente saber de antemano con qué se construyó el sitio, antes
	 * de intentar editar una página concreta.
	 *
	 * @return array<int, string>
	 */
	private function active_builders(): array {
		return BuilderRegistry::active_slugs();
	}

	/**
	 * Entrega el registro de constructores, creándolo una sola vez.
	 */
	private function builders(): BuilderRegistry {
		if ( null === $this->builder_registry ) {
			$this->builder_registry = new BuilderRegistry();
		}

		return $this->builder_registry;
	}
}
