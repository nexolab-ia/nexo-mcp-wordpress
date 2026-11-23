<?php
/**
 * Resolución del constructor de un contenido.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide qué adaptador corresponde a cada contenido.
 *
 * El orden importa: Elementor se consulta primero porque su marca vive en
 * `post_meta` y puede convivir con restos de shortcodes de un constructor
 * anterior en `post_content`.
 */
final class BuilderRegistry {

	/**
	 * Adaptadores disponibles.
	 *
	 * @var array<int, BuilderAdapter>
	 */
	private array $adapters;

	/**
	 * Construye el registro con los adaptadores admitidos.
	 *
	 * @param array<int, BuilderAdapter>|null $adapters Adaptadores propios.
	 */
	public function __construct( ?array $adapters = null ) {
		// El editor de bloques va al final a propósito: una página de Elementor o
		// Divi puede conservar bloques sueltos en `post_content`, y evaluarlo
		// primero se los atribuiría a Gutenberg dejando fuera el documento real
		// del constructor.
		$this->adapters = $adapters ?? array(
			new ElementorAdapter(),
			// Los bloques `divi/` son inequívocos; va antes que Divi 4 para que un
			// `[et_pb_section` citado dentro de un texto de Divi 5 no lo reclame, y
			// antes que el editor de bloques, que si no se quedaría la página.
			new Divi5Adapter(),
			new DiviAdapter(),
			new WpBakeryAdapter(),
			// Flatsome envuelve su layout en un bloque, así que va antes del editor
			// de bloques: si no, Gutenberg lo reclama y no encuentra ningún texto.
			new FlatsomeAdapter(),
			new GutenbergAdapter(),
		);
	}

	/**
	 * Localiza el adaptador que gobierna un contenido.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 */
	public function resolve( \WP_Post $post ): ?BuilderAdapter {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->handles( $post ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Localiza un adaptador por su identificador.
	 *
	 * @param string $slug Identificador del constructor.
	 */
	public function by_slug( string $slug ): ?BuilderAdapter {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->slug() === $slug ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Entrega los constructores reconocidos.
	 *
	 * @return array<int, string>
	 */
	public function supported(): array {
		return array_map(
			static fn( BuilderAdapter $adapter ): string => $adapter->slug(),
			$this->adapters
		);
	}

	/**
	 * Detecta qué constructores están instalados y activos en el sitio.
	 *
	 * @return array<int, string>
	 */
	public static function active_slugs(): array {
		$active = array();

		// El editor de bloques viene con WordPress, así que basta con que su API
		// esté disponible: un sitio que fuerza el editor clásico no la usa.
		if ( function_exists( 'has_blocks' ) ) {
			$active[] = 'gutenberg';
		}

		if ( defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' ) ) {
			$active[] = 'elementor';
		}

		if ( function_exists( 'et_setup_theme' ) || defined( 'ET_BUILDER_VERSION' ) ) {
			$active[] = 'divi';
		}

		// Divi 5 trae su propio constructor (bloques `divi/`) junto al de Divi 4.
		if ( defined( 'ET_BUILDER_5_DIR' ) ) {
			$active[] = 'divi5';
		}

		if ( defined( 'WPB_VC_VERSION' ) ) {
			$active[] = 'wpbakery';
		}

		if ( 'flatsome' === get_template() ) {
			$active[] = 'flatsome';
		}

		return $active;
	}
}
