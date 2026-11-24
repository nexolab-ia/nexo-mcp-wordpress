<?php
/**
 * Guarda de visibilidad de los bucles de consulta que escribe el conector: sólo publicado y sin contraseña.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Divi 5 arma la consulta de un bucle de tipos de contenido en `LoopUtils::_build_post_query_args`: con un solo tipo
 * `post_status` es `publish` (con `any` o `attachment` incluye `private` e `inherit`), pero NO excluye las entradas con
 * contraseña (sus títulos salen como «Protegido: …») y ninguna clave guardada lo controla. Esta guarda fija, en el filtro
 * `divi_loop_data_after_execution` (el último antes de ejecutar la consulta; Divi lo aplica a cada bucle al renderizar
 * y al predecir la paginación, con `$loop_data`, los atributos del bloque y el bloque), para los bucles del conector:
 *
 * - `post_status = publish` y `has_password = false` (sin `post_password`);
 * - `posts_per_page` ≤ {@see DiviLoopSchema::MAX_PER_PAGE} (y 24 si falta o es inválido), aunque alguien edite el valor guardado.
 *
 * Un bucle es «del conector» si su `loopId` tiene la forma de {@see DiviLoopSchema::ID_PATTERN} y es de tipos de
 * contenido (`query_type = post_types`); los bucles hechos en el Visual Builder (otro `loopId`) y las consultas de
 * usuarios, términos o menús no se tocan. Si el plugin se desactiva, la guarda deja de actuar.
 */
final class DiviLoopGuard {

	/**
	 * Filtro de Divi 5 (`LoopUtils::parse_and_duplicate_loop_blocks` y `generate_predictive_query`).
	 */
	public const FILTER = 'divi_loop_data_after_execution';

	/**
	 * Engancha la guarda al filtro de Divi con la prioridad más tardía.
	 */
	public function register(): void {
		add_filter( self::FILTER, array( $this, 'force' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Fija las condiciones de visibilidad en los datos de un bucle del conector.
	 *
	 * @param mixed $loop_data Datos del bucle (`query_args`, `query_type`, `post_type`…).
	 * @param mixed $attrs     Atributos del bloque con el bucle.
	 *
	 * @return mixed Los mismos datos, con la consulta endurecida si el bucle es del conector.
	 */
	public function force( $loop_data, $attrs = array() ) {
		if ( ! is_array( $loop_data ) || ! isset( $loop_data['query_args'] ) || ! is_array( $loop_data['query_args'] ) || 'post_types' !== ( $loop_data['query_type'] ?? null ) ) {
			return $loop_data;
		}

		$value   = is_array( $attrs ) ? DiviLoopSchema::loop_value( $attrs ) : null;
		$loop_id = null === $value ? null : ( $value['loopId'] ?? null );

		if ( ! is_string( $loop_id ) || 1 !== preg_match( DiviLoopSchema::ID_PATTERN, $loop_id ) ) {
			return $loop_data;
		}

		$args = $loop_data['query_args'];

		$args['post_status']  = 'publish';
		$args['has_password'] = false;
		unset( $args['post_password'] );

		$per_page = $args['posts_per_page'] ?? null;

		$args['posts_per_page']  = is_numeric( $per_page ) && (int) $per_page >= 1 && (int) $per_page <= DiviLoopSchema::MAX_PER_PAGE ? (int) $per_page : DiviLoopSchema::MAX_PER_PAGE;
		$loop_data['query_args'] = $args;

		return $loop_data;
	}
}
