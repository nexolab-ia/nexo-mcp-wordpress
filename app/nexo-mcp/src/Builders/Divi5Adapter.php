<?php
/**
 * Adaptador de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Lee las páginas de Divi 5, guardadas como bloques `divi/*` en `post_content`.
 *
 * Divi 4 (shortcodes `et_pb_*`) sigue en {@see DiviAdapter}. Aquí sólo hay
 * lectura: los nodos se direccionan por `path` y `fingerprint`
 * ({@see Divi5Tree}) y las escrituras llegan con herramientas propias, así que
 * las del contrato genérico devuelven error en vez de simular éxito.
 */
final class Divi5Adapter implements BuilderAdapter {

	/**
	 * Esquema que resume las condiciones de visualización al leer (S7).
	 *
	 * @var DiviConditionsSchema
	 */
	private DiviConditionsSchema $conditions;

	/**
	 * Construye el adaptador.
	 *
	 * @param DiviConditionsSchema|null $conditions Esquema para resumir las condiciones leídas (las pruebas inyectan sus fuentes).
	 */
	public function __construct( ?DiviConditionsSchema $conditions = null ) {
		$this->conditions = $conditions ?? new DiviConditionsSchema();
	}

	/**
	 * Identificador estable del constructor.
	 */
	public function slug(): string {
		return 'divi5';
	}

	/**
	 * Nombre legible para el propietario del sitio.
	 */
	public function label(): string {
		return 'Divi 5';
	}

	/**
	 * Reclama el contenido que tenga bloques `divi/`.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool {
		return Divi5Tree::is_divi5( (string) $post->post_content );
	}

	/**
	 * Sobrescribir `post_content` a ciegas rompería los comentarios de bloque.
	 */
	public function accepts_full_content_write(): bool {
		return false;
	}

	/**
	 * Entrega el árbol aplanado: el `id` es el `path`.
	 *
	 * `fingerprint`, `label`, `link`, `image`, `preset` y `conditions` (resumen, nunca valores crudos) viajan en `attributes`.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array {
		$nodes = array();

		$this->flatten( Divi5Tree::build( (string) $post->post_content, $this->conditions ), $nodes );

		return $nodes;
	}

	/**
	 * Entrega el árbol anidado, con `path`, `fingerprint` y `children` (y `conditions`, el resumen, en los módulos que las llevan).
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function read_tree( \WP_Post $post ): array {
		return Divi5Tree::build( (string) $post->post_content, $this->conditions );
	}

	/**
	 * Aún no hay edición de texto genérica para Divi 5.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Ruta del nodo.
	 * @param string   $text    Texto nuevo.
	 *
	 * @return \WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text ) {
		return $this->unsupported();
	}

	/**
	 * Aún no hay edición de atributos genérica para Divi 5.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Ruta del nodo.
	 * @param string   $attribute Atributo.
	 * @param mixed    $value     Valor.
	 *
	 * @return \WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value ) {
		return $this->unsupported();
	}

	/**
	 * Purga la caché que genera Divi por página (y, en un layout del Theme Builder vivo, la de todo el sitio).
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void {
		$this->purge( $post, true );
	}

	/**
	 * Purga la caché de un layout del Theme Builder recién creado y sin asignar: no se muestra en ninguna página, así que
	 * no hay CSS de todo el sitio que renovar (sólo el suyo y los plugins de caché).
	 *
	 * @param \WP_Post $post Layout creado.
	 */
	public function flush_unassigned( \WP_Post $post ): void {
		$this->purge( $post, false );
	}

	/**
	 * Purga la caché de un contenido.
	 *
	 * @param \WP_Post $post      Contenido modificado.
	 * @param bool     $site_wide Si un layout vivo renueva también la caché de todo el sitio.
	 */
	private function purge( \WP_Post $post, bool $site_wide ): void {
		// Un layout del Theme Builder vivo (no la copia de trabajo, que no está en ninguna plantilla) se muestra en todas las
		// páginas que su plantilla cubre: el CSS de cada una lleva su id en el nombre y sólo se renueva con la marca global
		// (L4 (c)). `remove_static_resources` no hace nada, sin avisar, si el usuario no tiene `edit_posts` y no es cron.
		$live_layout = $site_wide && DiviLayoutGuard::is_layout( $post ) && 0 === WorkingCopy::original_of( $post );

		if ( class_exists( '\ET_Core_PageResource' ) ) {
			\ET_Core_PageResource::remove_static_resources( $post->ID, 'all' );

			if ( $live_layout ) {
				\ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			}
		}

		if ( $live_layout && function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
			et_theme_builder_clear_wp_cache( 'all' );
		}

		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache();
		}

		clean_post_cache( $post->ID );
	}

	/**
	 * Error común de las escrituras no disponibles.
	 */
	private function unsupported(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_builder_unsupported',
			__( 'Las páginas de Divi 5 se editan por ruta y huella con las herramientas de Divi 5, no con esta operación.', 'nexo-mcp' )
		);
	}

	/**
	 * Aplana el árbol en nodos normalizados.
	 *
	 * @param array<int, array<string, mixed>> $tree  Nodos del nivel.
	 * @param array<int, BuilderNode>          $nodes Salida.
	 */
	private function flatten( array $tree, array &$nodes ): void {
		foreach ( $tree as $node ) {
			$attributes = array( 'fingerprint' => (string) $node['fingerprint'] );

			foreach ( array( 'label', 'link', 'image', 'preset', 'conditions' ) as $key ) {
				if ( isset( $node[ $key ] ) ) {
					$attributes[ $key ] = $node[ $key ];
				}
			}

			$nodes[] = new BuilderNode(
				(string) $node['path'],
				(string) $node['type'],
				isset( $node['text'] ) ? (string) $node['text'] : null,
				false,
				__( 'Se edita por ruta y huella con las herramientas de Divi 5.', 'nexo-mcp' ),
				$attributes
			);

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$this->flatten( $node['children'], $nodes );
			}
		}
	}
}
