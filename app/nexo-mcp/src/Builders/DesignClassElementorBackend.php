<?php
/**
 * Acceso real a las clases globales de Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Implementación de {@see DesignClassBackend} sobre el repositorio de Elementor 4.4.1.
 */
final class DesignClassElementorBackend implements DesignClassBackend {

	private const REPOSITORY = '\Elementor\Modules\GlobalClasses\Global_Classes_Repository';

	private const RELATIONS = '\Elementor\Modules\GlobalClasses\Global_Classes_Relations';

	private const STYLE_PARSER = '\Elementor\Modules\AtomicWidgets\Parsers\Style_Parser';

	private const STYLE_SCHEMA = '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema';

	/**
	 * Lectura del Kit y de las banderas de Elementor.
	 *
	 * @var ElementorDesignSystem
	 */
	private ElementorDesignSystem $system;

	/**
	 * Construye el acceso.
	 *
	 * @param ElementorDesignSystem|null $system Lectura del sistema de diseño.
	 */
	public function __construct( ?ElementorDesignSystem $system = null ) {
		$this->system = $system ?? new ElementorDesignSystem();
	}

	/**
	 * {@inheritDoc}
	 */
	public function style_props(): array {
		$schema = self::STYLE_SCHEMA;

		return class_exists( $schema ) ? array_map( 'strval', array_keys( $schema::get() ) ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $style Estilo.
	 */
	public function parse_style( array $style ) {
		$parser = self::STYLE_PARSER;
		$schema = self::STYLE_SCHEMA;

		if ( ! class_exists( $parser ) || ! class_exists( $schema ) ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Esta versión de Elementor no trae el validador de estilos atómicos. Actualizá Elementor a la 4.x.', 'nexo-mcp' )
			);
		}

		foreach ( is_array( $style['variants'] ?? null ) ? array_keys( $style['variants'] ) : array() as $position ) {
			$style['variants'][ $position ]['props']      = is_array( $style['variants'][ $position ]['props'] ?? null ) ? $style['variants'][ $position ]['props'] : array();
			$style['variants'][ $position ]['custom_css'] = null;
		}

		try {
			$result = $parser::make( $schema::get() )->parse( $style );

			foreach ( $result->errors()->all() as $error ) {
				return new \WP_Error(
					'nexo_mcp_elementor_style_rejected',
					sprintf(
						/* translators: 1: ruta, 2: motivo que da Elementor. */
						__( '%1$s: Elementor rechazó el valor (%2$s).', 'nexo-mcp' ),
						(string) $error['key'],
						(string) $error['error']
					),
					array( 'path' => (string) $error['key'] )
				);
			}

			$parsed = $result->unwrap();
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'nexo_mcp_elementor_style_rejected',
				sprintf(
					/* translators: %s: mensaje de Elementor. */
					__( 'Elementor no pudo validar el estilo (%s).', 'nexo-mcp' ),
					$e->getMessage()
				),
				array( 'path' => 'variants' )
			);
		}

		return is_array( $parsed ) ? $parsed : $style;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read(): array {
		$all = $this->repository()->all( true )->get();

		return array(
			'items' => (array) $all['items'],
			'order' => array_values( (array) $all['order'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id de la clase.
	 */
	public function used_in( string $id ): array {
		$relations = self::RELATIONS;

		if ( ! class_exists( $relations ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) ( new $relations() )->get_posts_by_style( $id ) as $post_id ) {
			$post_id = (int) $post_id;
			$title   = trim( (string) get_the_title( $post_id ) );
			$out[]   = array(
				'id'    => $post_id,
				'title' => '' !== $title ? $title : __( '(sin título)', 'nexo-mcp' ),
			);
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, array<string, mixed>> $touched Items tocados.
	 * @param array<string, mixed>                $changes Cambios.
	 * @param array<int, string>                  $order   Orden final.
	 */
	public function write( array $touched, array $changes, array $order ): void {
		$this->repository()->apply_changes( $touched, $changes, $order );
	}

	/**
	 * Repositorio de clases globales del Kit activo.
	 *
	 * @throws \RuntimeException Si falta Elementor, el Kit o las clases globales.
	 */
	private function repository(): object {
		$repository = self::REPOSITORY;
		$kit        = $this->system->kit();

		if ( null === $kit ) {
			throw new \RuntimeException( 'No hay un Kit activo de Elementor.' );
		}

		if ( ! class_exists( $repository ) ) {
			throw new \RuntimeException( 'Esta versión de Elementor no trae clases globales.' );
		}

		return $repository::make( $kit );
	}
}
