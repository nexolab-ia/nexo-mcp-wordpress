<?php
/**
 * Edición de un módulo de Divi 5 por ruta y huella, en la copia de trabajo.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\WordPress\WorkingCopy;

/**
 * Cambia el contenido y/o el diseño de escritorio de un módulo de contenido del catálogo.
 *
 * Mismo camino que {@see DynamicTagAssigner}: todo corre bajo el bloqueo del original;
 * destino (copia o borrador), bloqueo de edición y modo «sólo borradores» antes de leer;
 * luego se localiza el nodo por `path`, se compara su `fingerprint` (si cambió, no se
 * escribe), se valida el parche con {@see DiviTreeValidator::validate_patch()} y sólo
 * entonces se crea o reutiliza la copia, se respalda y se guarda. Nunca publica.
 *
 * El parche validado sólo trae rutas `<ruta>.desktop.value`; se fusiona en profundidad
 * con los atributos del bloque, de modo que tablet, teléfono, hover y todo lo no tocado
 * queda igual. El contenido que ya pasó el validador se guarda con los filtros kses
 * quitados sólo durante esa escritura: el kses del núcleo corrompe los comentarios de
 * bloque cuando el usuario no tiene `unfiltered_html`.
 */
final class Divi5ModuleEditor {

	/**
	 * Camino de escritura compartido.
	 *
	 * @var Divi5ContentWriter
	 */
	private Divi5ContentWriter $writer;

	/**
	 * Validador del parche.
	 *
	 * @var DiviTreeValidator
	 */
	private DiviTreeValidator $validator;

	/**
	 * Construye el editor.
	 *
	 * @param Settings          $settings     Configuración.
	 * @param Divi5Adapter      $adapter      Adaptador de Divi 5.
	 * @param BuilderBackup     $backup       Respaldos.
	 * @param WorkingCopy       $copies       Copias de trabajo.
	 * @param DiviTreeValidator $validator    Validador del parche.
	 * @param callable          $lock_check   fn( \WP_Post ): true|\WP_Error.
	 * @param callable|null     $divi5_active fn(): bool. Por omisión, `ET_BUILDER_5_DIR` definida.
	 * @param callable|null     $saver        fn( int, string ): true|\WP_Error. Por omisión, `wp_update_post()` (sólo pruebas).
	 */
	public function __construct(
		Settings $settings,
		Divi5Adapter $adapter,
		BuilderBackup $backup,
		WorkingCopy $copies,
		DiviTreeValidator $validator,
		callable $lock_check,
		?callable $divi5_active = null,
		?callable $saver = null
	) {
		$this->writer    = new Divi5ContentWriter( $settings, $adapter, $backup, $copies, $lock_check, $divi5_active, $saver );
		$this->validator = $validator;
	}

	/**
	 * Edita el módulo.
	 *
	 * @param \WP_Post             $post  Contenido indicado (el original o su copia).
	 * @param array<string, mixed> $input Parámetros: `path`, `fingerprint`, `content`, `design`, `draft`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit( \WP_Post $post, array $input ) {
		return WorkingCopy::with_lock(
			(int) $post->ID,
			function () use ( $post, $input ) {
				// El objeto recibido se leyó antes de esperar el bloqueo: se relee.
				$fresh = get_post( $post->ID );

				if ( ! $fresh instanceof \WP_Post ) {
					return new \WP_Error( 'nexo_mcp_builder_not_found', __( 'El contenido no existe.', 'nexo-mcp' ) );
				}

				return $this->edit_locked( $fresh, $input );
			}
		);
	}

	/**
	 * Edita ya con el bloqueo del original en mano.
	 *
	 * @param \WP_Post             $post  Contenido indicado.
	 * @param array<string, mixed> $input Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function edit_locked( \WP_Post $post, array $input ) {
		$target = $this->writer->target( $post, $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$prepared = $this->prepare( $target['source'], $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$written = $this->writer->write( $post, $target, $prepared['content'] );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$fresh = get_post( $written->ID );
		$node  = Divi5Tree::locate( Divi5Tree::build( $fresh instanceof \WP_Post ? (string) $fresh->post_content : $prepared['content'] ), $prepared['path'] );

		return array_merge(
			Divi5ContentWriter::describe( $written ),
			array(
				'path'        => $prepared['path'],
				'fingerprint' => null === $node ? '' : (string) $node['fingerprint'],
			)
		);
	}

	/**
	 * Localiza el nodo, comprueba la huella, valida el parche y arma el contenido nuevo.
	 *
	 * @param \WP_Post             $source Contenido sobre el que se trabaja.
	 * @param array<string, mixed> $input  Parámetros.
	 *
	 * @return array{content: string, path: string}|\WP_Error
	 */
	private function prepare( \WP_Post $source, array $input ) {
		$path        = isset( $input['path'] ) && is_string( $input['path'] ) ? $input['path'] : '';
		$fingerprint = isset( $input['fingerprint'] ) && is_string( $input['fingerprint'] ) ? $input['fingerprint'] : '';
		$blocks      = parse_blocks( (string) $source->post_content );
		$tree        = Divi5Tree::build( (string) $source->post_content );
		$node        = Divi5Tree::locate( $tree, $path );
		$block       = Divi5Tree::locate_block( $blocks, $path );

		if ( null === $node || null === $block ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ! Divi5Tree::fingerprint_matches( $tree, $path, $fingerprint ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_node_changed',
				__( 'El módulo cambió desde que lo leíste (la huella no coincide). Vuelve a leer la página con nexo-mcp-get-page-structure; no se escribió nada.', 'nexo-mcp' )
			);
		}

		$type = (string) $node['type'];

		if ( ! in_array( $type, DiviModuleCatalog::CONTENT_MODULES, true ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_module_denied',
				sprintf(
					/* translators: %s: nombre del bloque. */
					__( '«%s» no es un módulo de contenido editable (sólo texto, título, botón, imagen, divisor, blurb, ícono, video y lista).', 'nexo-mcp' ),
					$type
				)
			);
		}

		$patch = array();

		foreach ( array( 'content', 'design' ) as $slot ) {
			if ( array_key_exists( $slot, $input ) && null !== $input[ $slot ] ) {
				$patch[ $slot ] = $input[ $slot ];
			}
		}

		$clean = $this->validator->validate_patch( $type, $patch );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		foreach ( $clean as $part ) {
			$attrs = self::merge( $attrs, $part );
		}

		$block['attrs'] = $attrs;
		$replaced       = Divi5Tree::replace_block( $blocks, $path, $block );

		if ( null === $replaced ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El módulo indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		return array(
			'content' => serialize_blocks( $replaced ),
			'path'    => $path,
		);
	}

	/**
	 * Fusiona en profundidad: los objetos se recorren, lo demás se reemplaza.
	 *
	 * @param array<mixed, mixed> $base  Atributos actuales.
	 * @param array<mixed, mixed> $patch Parche validado.
	 *
	 * @return array<mixed, mixed>
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && array() !== $value && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
