<?php
/**
 * Dobles para los ítems globales de la biblioteca (T10): el filtro de Divi que despega las instancias al mandar un global a la
 * papelera (`GlobalLayout::preserve_instances_before_delete`, 5.19.1) y una base de datos que contesta la búsqueda de uso.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace ET\Builder\Packages\GlobalLayout {

	/**
	 * Como el de Divi: incrusta el ítem en cada contenido que lo referencia y devuelve false (aborta el borrado) si no puede.
	 */
	final class GlobalLayout {

		public const MARKER = '<!-- wp:divi/text -->DETACHED<!-- /wp:divi/text -->';

		/** @var string `detach` (normal), `abort` (devuelve false sin tocar nada) o `noop` (dice que fue bien pero no despega). */
		public static string $mode = 'detach';

		/**
		 * Como `GlobalLayout.php:50-57`: recorre TODA la tabla de contenidos, sin filtrar por estado ni tipo (papelera, revisiones y
		 * borradores automáticos incluidos), y aborta si el usuario no puede editar alguno de los que cambia.
		 *
		 * @param mixed    $override Resultado previo del filtro.
		 * @param \WP_Post $post     Ítem que se manda a la papelera.
		 *
		 * @return mixed
		 */
		public static function preserve_instances_before_delete( $override, $post ) {
			if ( null !== $override ) {
				return $override;
			}

			if ( 'abort' === self::$mode ) {
				return false;
			}

			$id = (int) $post->ID;

			foreach ( \WP_Test_State::$posts as $other ) {
				if ( $other->ID === $id || ! \NexoDev\NexoMCP\Builders\DiviLibraryUsage::references( (string) $other->post_content, $id ) ) {
					continue;
				}

				if ( ! current_user_can( 'edit_post', $other->ID ) ) {
					return false;
				}

				if ( 'noop' !== self::$mode ) {
					$other->post_content = (string) preg_replace( '/<!-- wp:divi\/global-layout \{[^}]*"globalModule":"?' . $id . '"?[^}]*\} \/-->/', self::MARKER, (string) $other->post_content );
				}
			}

			return $override;
		}
	}
}

namespace {

	/**
	 * `$wpdb` que contesta la consulta de `DiviLibraryUsage::fetch_batch()` con los contenidos de las pruebas: los que mencionan una
	 * referencia global, de id mayor que el último visto y distintos del ítem, de cien en cien. NO aplica los filtros de tipo y
	 * estado de la SQL (eso lo hace la base real): así se prueba también el filtro de PHP.
	 */
	final class WP_Library_Wpdb_Double extends WPDB_Test_Double {

		public string $posts = 'wp_posts';

		/** @var string Último error de la base, como `$wpdb->last_error`. */
		public string $last_error = '';

		/** @var int Veces que se consultó. */
		public int $queries = 0;

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_results( $sql, $output = null ): array {
			++$this->queries;
			preg_match( '/ID > (\d+) AND ID <> (\d+)/', (string) $sql, $m );
			$rows = array();

			foreach ( WP_Test_State::$posts as $post ) {
				if ( $post->ID > (int) $m[1] && $post->ID !== (int) $m[2] && ( str_contains( (string) $post->post_content, 'globalModule' ) || str_contains( (string) $post->post_content, 'global_module' ) ) ) {
					$rows[ $post->ID ] = array(
						'ID'           => $post->ID,
						'post_type'    => $post->post_type,
						'post_status'  => $post->post_status,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
					);
				}
			}

			ksort( $rows );

			return array_slice( array_values( $rows ), 0, 100 );
		}
	}
}
