<?php
/**
 * Operaciones de lectura y edición sobre constructores visuales.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Punto único de entrada del módulo de constructores.
 *
 * Concentra las comprobaciones que deben ocurrir sí o sí antes de tocar el
 * contenido: que el bloqueo de edición del propietario se respete, que exista
 * un respaldo restaurable y que el texto entrante venga sanitizado.
 */
final class BuilderGateway {

	/**
	 * Longitud máxima admitida para el texto de un nodo.
	 */
	private const MAX_TEXT = 65535;

	/**
	 * Registro de adaptadores.
	 *
	 * @var BuilderRegistry
	 */
	private BuilderRegistry $registry;

	/**
	 * Respaldo previo a la escritura.
	 *
	 * @var BuilderBackup
	 */
	private BuilderBackup $backup;

	/**
	 * Construye el gateway.
	 *
	 * @param BuilderRegistry|null $registry Registro de adaptadores.
	 * @param BuilderBackup|null   $backup   Gestor de respaldos.
	 */
	public function __construct( ?BuilderRegistry $registry = null, ?BuilderBackup $backup = null ) {
		$this->registry = $registry ?? new BuilderRegistry();
		$this->backup   = $backup ?? new BuilderBackup();
	}

	/**
	 * Describe la estructura editable de un contenido.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_structure( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			// Sin este aviso, una página de Avada o Bricks se anunciaba como
			// «contenido corriente» y el cliente intentaba editarla con las
			// herramientas normales: en el mejor caso no pasaba nada, en el peor
			// se sobrescribía el layout. Decir qué constructor es evita las dos.
			$ajeno = ( new ForeignBuilderDetector() )->detect( $post );

			if ( null !== $ajeno ) {
				return array(
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'builder' => $ajeno,
					'notice'  => sprintf(
						/* translators: %s: nombre del constructor detectado. */
						__( 'Esta página está hecha con %s, que el conector todavía no sabe editar. No intentes escribir su contenido: edítala desde su propio constructor.', 'nexo-mcp' ),
						$ajeno
					),
					'nodes'   => array(),
				);
			}

			return array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'builder' => null,
				'notice'  => __( 'Este contenido no usa un constructor reconocido; se edita con las herramientas de contenido habituales.', 'nexo-mcp' ),
				'nodes'   => array(),
			);
		}

		$read  = $adapter->read_nodes( $post );
		$nodes = array_map(
			static fn( BuilderNode $node ): array => $node->to_array(),
			$read
		);

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'builder'  => $adapter->slug(),
			'label'    => $adapter->label(),
			'editable' => count(
				array_filter( $read, static fn( BuilderNode $node ): bool => $node->editable )
			),
			'backup'   => $this->backup->describe( $post->ID ),
			'nodes'    => $nodes,
		);
	}

	/**
	 * Reemplaza el texto de un nodo del constructor.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_node_text( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_unknown',
				__( 'Este contenido no usa un constructor reconocido.', 'nexo-mcp' )
			);
		}

		$locked = $this->locked_by_other( $post );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$node_id = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a editar.', 'nexo-mcp' )
			);
		}

		$text = (string) ( $input['text'] ?? '' );

		if ( strlen( $text ) > self::MAX_TEXT ) {
			return new \WP_Error(
				'nexo_mcp_builder_text_too_long',
				__( 'El texto excede la longitud admitida.', 'nexo-mcp' )
			);
		}

		// El nodo se valida antes de tocar el respaldo. De lo contrario una
		// operación condenada a fallar —un elemento inexistente, o uno vedado por
		// admitir código— sobrescribiría el último estado bueno con el actual y
		// dejaría al propietario sin punto de retorno.
		$target = $this->find_node( $adapter, $post, $node_id );

		if ( null === $target ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		if ( ! $target->editable ) {
			return new \WP_Error(
				'nexo_mcp_builder_type_forbidden',
				'' !== $target->reason
					? $target->reason
					: __( 'Ese elemento no admite edición.', 'nexo-mcp' )
			);
		}

		// El marcado se restringe al mismo conjunto que el resto del conector,
		// que ya excluye script, iframe y atributos de evento.
		$text = wp_kses_post( $text );

		// Sin respaldo no se escribe: las revisiones de WordPress no cubren
		// `post_meta` y una escritura fallida dejaría la página irrecuperable.
		if ( ! $this->backup->capture( $post, $adapter ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_backup_failed',
				__( 'No fue posible respaldar el contenido; la edición se canceló.', 'nexo-mcp' )
			);
		}

		$result = $adapter->update_node_text( $post, $node_id, $text );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$adapter->flush( $post );

		return array(
			'id'      => $post->ID,
			'builder' => $adapter->slug(),
			'node_id' => $node_id,
			'updated' => true,
			'backup'  => $this->backup->describe( $post->ID ),
		);
	}

	/**
	 * Reemplaza un atributo de un nodo del constructor.
	 *
	 * Sigue el mismo orden que la edición de texto y por el mismo motivo: se
	 * comprueba todo lo que puede fallar —el nodo, el atributo, el valor— antes
	 * de tocar el respaldo. Al revés, una operación condenada a fallar
	 * sobrescribiría el último estado bueno y dejaría al propietario sin retorno.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_node_attribute( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$adapter = $this->registry->resolve( $post );

		if ( null === $adapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_unknown',
				__( 'Este contenido no usa un constructor reconocido.', 'nexo-mcp' )
			);
		}

		$locked = $this->locked_by_other( $post );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$node_id = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a editar.', 'nexo-mcp' )
			);
		}

		$attribute = isset( $input['attribute'] ) ? sanitize_key( (string) $input['attribute'] ) : '';

		$target = $this->find_node( $adapter, $post, $node_id );

		if ( null === $target ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'El elemento indicado no existe en esta página.', 'nexo-mcp' )
			);
		}

		// Aquí no se mira `editable`: ese campo dice si el TEXTO se puede tocar, y
		// una imagen no tiene texto pero sí atributos. Lo que decide es la
		// allowlist de atributos, que además excluye los tipos que admiten código.
		if ( ! EditableAttributes::allows( $adapter->slug(), $target->type, $attribute ) ) {
			$admitidos = EditableAttributes::for_type( $adapter->slug(), $target->type );

			return new \WP_Error(
				'nexo_mcp_builder_attr_denied',
				array() === $admitidos
					? sprintf(
						/* translators: %s: tipo del elemento. */
						__( 'El elemento «%s» no admite editar atributos, sólo su texto.', 'nexo-mcp' ),
						$target->type
					)
					: sprintf(
						/* translators: 1: tipo del elemento, 2: lista de atributos admitidos. */
						__( 'El elemento «%1$s» no admite ese atributo. Admite: %2$s.', 'nexo-mcp' ),
						$target->type,
						implode( ', ', $admitidos )
					)
			);
		}

		$value = $this->validate_attribute( $attribute, $input );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		// Sin respaldo no se escribe: las revisiones de WordPress no cubren
		// `post_meta` y una escritura fallida dejaría la página irrecuperable.
		if ( ! $this->backup->capture( $post, $adapter ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_backup_failed',
				__( 'No fue posible respaldar el contenido; la edición se canceló.', 'nexo-mcp' )
			);
		}

		$result = $adapter->update_node_attribute( $post, $node_id, $attribute, $value );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$adapter->flush( $post );

		return array(
			'id'        => $post->ID,
			'builder'   => $adapter->slug(),
			'node_id'   => $node_id,
			'attribute' => $attribute,
			'updated'   => true,
			'backup'    => $this->backup->describe( $post->ID ),
		);
	}

	/**
	 * Duplica un elemento de la página junto al original o a otro elemento.
	 *
	 * Mismo orden que las ediciones: todo lo que puede fallar se comprueba al
	 * preparar la copia, y recién entonces se respalda y se guarda.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function edit_structure( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$adapter = $this->registry->resolve( $post );

		if ( ! $adapter instanceof StructureAdapter ) {
			return new \WP_Error(
				'nexo_mcp_builder_structure_unsupported',
				__( 'Por ahora sólo se pueden duplicar elementos en páginas de Elementor.', 'nexo-mcp' )
			);
		}

		$locked = $this->locked_by_other( $post );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$node_id  = isset( $input['node_id'] ) ? sanitize_text_field( (string) $input['node_id'] ) : '';
		$target   = isset( $input['target'] ) ? sanitize_text_field( (string) $input['target'] ) : '';
		$position = 'before' === ( $input['position'] ?? 'after' ) ? 'before' : 'after';

		if ( '' === $node_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_node_missing',
				__( 'Debes indicar el elemento a duplicar.', 'nexo-mcp' )
			);
		}

		$prepared = $adapter->prepare_duplicate( $post, $node_id, $target, $position );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( ! $this->backup->capture( $post, $adapter ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_backup_failed',
				__( 'No fue posible respaldar el contenido; la edición se canceló.', 'nexo-mcp' )
			);
		}

		$saved = $adapter->save_tree( $post, $prepared['tree'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$adapter->flush( $post );

		return array(
			'id'      => $post->ID,
			'created' => array_map(
				static fn( BuilderNode $node ): array => $node->to_array(),
				$prepared['created']
			),
			'backup'  => $this->backup->describe( $post->ID ),
		);
	}

	/**
	 * Convierte el valor recibido en algo seguro de escribir.
	 *
	 * @param string               $attribute Atributo solicitado.
	 * @param array<string, mixed> $input     Parámetros de la herramienta.
	 *
	 * @return mixed|\WP_Error
	 */
	private function validate_attribute( string $attribute, array $input ) {
		switch ( $attribute ) {
			case EditableAttributes::LINK:
				return AttributeValue::link( (string) ( $input['value'] ?? '' ) );

			case EditableAttributes::IMAGE:
				// Se espera el identificador de la biblioteca, nunca una URL: una
				// dirección arbitraria cargaría un recurso de un tercero en la
				// página del propietario.
				return AttributeValue::image( (int) ( $input['value'] ?? 0 ) );

			case EditableAttributes::ALT:
				return AttributeValue::alt( (string) ( $input['value'] ?? '' ) );

			default:
				return new \WP_Error(
					'nexo_mcp_builder_attr_unknown',
					__( 'Atributo no reconocido. Se admiten: link, image y alt.', 'nexo-mcp' )
				);
		}
	}

	/**
	 * Restaura el último estado respaldado.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore_backup( array $input ) {
		$post = $this->resolve_post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$locked = $this->locked_by_other( $post );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$restored = $this->backup->restore( $post );

		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$adapter = $this->registry->resolve( $post );

		if ( null !== $adapter ) {
			$adapter->flush( $post );
		}

		return array(
			'id'       => $post->ID,
			'restored' => true,
		);
	}

	/**
	 * Localiza un nodo dentro del contenido.
	 *
	 * @param BuilderAdapter $adapter Constructor que gobierna el contenido.
	 * @param \WP_Post       $post    Contenido consultado.
	 * @param string         $node_id Identificador buscado.
	 */
	private function find_node( BuilderAdapter $adapter, \WP_Post $post, string $node_id ): ?BuilderNode {
		foreach ( $adapter->read_nodes( $post ) as $node ) {
			if ( $node->id === $node_id ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Obtiene el contenido indicado comprobando que sea editable.
	 *
	 * @param array<string, mixed> $input Parámetros de la herramienta.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_post( array $input ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( 0 === $post_id ) {
			return new \WP_Error(
				'nexo_mcp_builder_invalid_id',
				__( 'Debes indicar el contenido.', 'nexo-mcp' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'nexo_mcp_builder_not_found',
				__( 'El contenido no existe.', 'nexo-mcp' )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_forbidden',
				__( 'No tienes permiso sobre este contenido.', 'nexo-mcp' )
			);
		}

		return $post;
	}

	/**
	 * Rechaza la escritura si otro usuario tiene el contenido abierto.
	 *
	 * El constructor guarda el árbol completo al cerrar, de modo que escribir
	 * mientras alguien edita significa que uno de los dos cambios se pierde.
	 *
	 * @param \WP_Post $post Contenido evaluado.
	 *
	 * @return true|\WP_Error
	 */
	private function locked_by_other( \WP_Post $post ) {
		$lock = (string) get_post_meta( $post->ID, '_edit_lock', true );

		if ( '' === $lock ) {
			return true;
		}

		$parts = explode( ':', $lock );
		$time  = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$owner = isset( $parts[1] ) ? (int) $parts[1] : 0;

		/** Ventana de vigencia del bloqueo de edición de WordPress. */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );

		if ( 0 < $owner && get_current_user_id() !== $owner && $window > ( time() - $time ) ) {
			return new \WP_Error(
				'nexo_mcp_builder_locked',
				__( 'Otro usuario tiene este contenido abierto en el editor.', 'nexo-mcp' )
			);
		}

		return true;
	}
}
