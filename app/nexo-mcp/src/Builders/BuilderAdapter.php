<?php
/**
 * Contrato común de los constructores visuales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Traduce la estructura propietaria de un constructor a un modelo común.
 *
 * Cada constructor guarda su contenido de forma distinta: Elementor en un JSON
 * dentro de `post_meta`, Divi y WPBakery en shortcodes anidados dentro de
 * `post_content`. Exponer esas diferencias al cliente MCP obligaría a una
 * herramienta por constructor; el modelo normalizado permite una sola.
 */
interface BuilderAdapter {

	/**
	 * Identificador estable del constructor.
	 */
	public function slug(): string;

	/**
	 * Nombre legible para el propietario del sitio.
	 */
	public function label(): string;

	/**
	 * Indica si el contenido fue construido con este constructor.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function handles( \WP_Post $post ): bool;

	/**
	 * Indica si reescribir `post_content` entero surte efecto.
	 *
	 * Elementor guarda su árbol en `post_meta`, y Divi o WPBakery esperan sus
	 * propios shortcodes: sobrescribir el contenido ahí no cambia la página o la
	 * rompe. El editor de bloques es el caso contrario, porque `post_content` es
	 * justamente donde vive. Distinguirlos evita rechazar ediciones válidas.
	 */
	public function accepts_full_content_write(): bool;

	/**
	 * Entrega los nodos de texto del contenido, ya normalizados.
	 *
	 * @param \WP_Post $post Contenido leído.
	 *
	 * @return array<int, BuilderNode>
	 */
	public function read_nodes( \WP_Post $post ): array;

	/**
	 * Reemplaza el texto de un nodo conservando el resto de la estructura.
	 *
	 * @param \WP_Post $post    Contenido editado.
	 * @param string   $node_id Identificador del nodo.
	 * @param string   $text    Texto nuevo, ya sanitizado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_text( \WP_Post $post, string $node_id, string $text );

	/**
	 * Reemplaza un atributo de un nodo conservando el resto de la estructura.
	 *
	 * Va aparte de la edición de texto porque el riesgo no es el mismo: un
	 * atributo decide a dónde va el visitante o qué recurso carga su navegador,
	 * así que tiene su propio scope y su propia allowlist. El valor llega ya
	 * validado por {@see AttributeValue}.
	 *
	 * Un adaptador que todavía no admita atributos devuelve un WP_Error, nunca
	 * `true`: informar éxito sin efecto es el fallo que este plugin persigue.
	 *
	 * @param \WP_Post $post      Contenido editado.
	 * @param string   $node_id   Identificador del nodo.
	 * @param string   $attribute Atributo a escribir.
	 * @param mixed    $value     Valor ya validado.
	 *
	 * @return true|\WP_Error
	 */
	public function update_node_attribute( \WP_Post $post, string $node_id, string $attribute, $value );

	/**
	 * Invalida el CSS o la caché que el constructor genera por página.
	 *
	 * @param \WP_Post $post Contenido modificado.
	 */
	public function flush( \WP_Post $post ): void;
}
