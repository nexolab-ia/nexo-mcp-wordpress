<?php
/**
 * Contrato de los constructores que admiten cambios de estructura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Duplica elementos de una página sin aceptar contenido nuevo del cliente.
 *
 * Va aparte de {@see BuilderAdapter} porque sólo Elementor lo implementa por
 * ahora: obligar a todos los adaptadores a declararlo sería llenar el resto de
 * métodos que devuelven error. La preparación y el guardado van separados para
 * que el gateway respalde entre ambos, sólo cuando la operación ya es válida.
 */
interface StructureAdapter {

	/**
	 * Prepara la página con una copia del elemento, sin guardarla.
	 *
	 * @param \WP_Post $post     Contenido editado.
	 * @param string   $node_id  Elemento a duplicar.
	 * @param string   $target   Elemento junto al que va la copia; vacío, el original.
	 * @param string   $position `before` o `after`.
	 *
	 * @return array{tree: array<int, mixed>, created: array<int, BuilderNode>}|\WP_Error
	 */
	public function prepare_duplicate( \WP_Post $post, string $node_id, string $target, string $position );

	/**
	 * Guarda un documento ya preparado y validado.
	 *
	 * @param \WP_Post          $post Contenido editado.
	 * @param array<int, mixed> $tree Documento completo.
	 *
	 * @return true|\WP_Error
	 */
	public function save_tree( \WP_Post $post, array $tree );
}
