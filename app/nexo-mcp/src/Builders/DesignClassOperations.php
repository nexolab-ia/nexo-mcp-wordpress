<?php
/**
 * Crear, modificar y borrar clases globales de Elementor 4.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Operaciones de clases globales (kind `classes`), siempre por `DesignSystemWriter::write()`.
 *
 * `validate` hace TODA la validación y devuelve el plan; `apply` sólo escribe los items
 * tocados con `apply_changes` (en `create` el orden queda con la clase al final) y no hace
 * ninguna escritura más.
 *
 * - id `g-` generado aquí, distinto de los existentes;
 * - label con formato `/^[a-z][a-z-_0-9]*$/i`, máx. 50, único sin distinguir mayúsculas
 *   (repetido → `nexo_mcp_design_label_taken`, nunca se renombra);
 * - máximo 1000 clases;
 * - variantes con la validación de F2/F3: `ElementorTreeValidator` (claves, breakpoint, estado,
 *   propiedades y hojas de CSS) y después el analizador de estilos de Elementor;
 * - borrar una clase en uso exige `force` (`nexo_mcp_design_in_use`).
 */
final class DesignClassOperations {

	public const MAX_CLASSES = 1000;

	public const MAX_LABEL = 50;

	private const LABEL_PATTERN = '/^[a-z][a-z-_0-9]*$/iD';

	/**
	 * Escritor con bloqueo, respaldo y deshacer.
	 *
	 * @var DesignSystemWriter
	 */
	private DesignSystemWriter $writer;

	/**
	 * Acceso a Elementor.
	 *
	 * @var DesignClassBackend
	 */
	private DesignClassBackend $backend;

	/**
	 * Generador de ids: fn(): string.
	 *
	 * @var callable
	 */
	private $id_generator;

	/**
	 * Construye las operaciones.
	 *
	 * @param DesignSystemWriter      $writer       Escritor.
	 * @param DesignClassBackend|null $backend      Acceso a Elementor; por omisión el real.
	 * @param callable|null           $id_generator Generador de ids `g-…`; por omisión aleatorio de 7 hex como Elementor.
	 */
	public function __construct( DesignSystemWriter $writer, ?DesignClassBackend $backend = null, ?callable $id_generator = null ) {
		$this->writer       = $writer;
		$this->backend      = $backend ?? new DesignClassElementorBackend();
		$this->id_generator = $id_generator ?? static fn(): string => 'g-' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Crea una clase al final del orden.
	 *
	 * @param array<string, mixed> $params `{label, variants}`.
	 *
	 * @return array<string, mixed>|\WP_Error `{id, label}`.
	 */
	public function create( array $params ) {
		return $this->writer->write(
			'classes',
			'create-class',
			fn() => $this->plan_create( $params ),
			fn( $plan ) => $this->apply( $plan ),
			$this->audit_params( $params )
		);
	}

	/**
	 * Modifica el label y/o las variantes de una clase.
	 *
	 * @param array<string, mixed> $params `{id, label?, variants?}`.
	 *
	 * @return array<string, mixed>|\WP_Error `{id, label}`.
	 */
	public function update( array $params ) {
		return $this->writer->write(
			'classes',
			'update-class',
			fn() => $this->plan_update( $params ),
			fn( $plan ) => $this->apply( $plan ),
			$this->audit_params( $params )
		);
	}

	/**
	 * Borra una clase; en uso, sólo con `force`.
	 *
	 * @param array<string, mixed> $params `{id, force?}`.
	 *
	 * @return array<string, mixed>|\WP_Error `{deleted, pages_affected}`.
	 */
	public function delete( array $params ) {
		return $this->writer->write(
			'classes',
			'delete-class',
			fn() => $this->plan_delete( $params ),
			fn( $plan ) => $this->apply( $plan ),
			$this->audit_params( $params )
		);
	}

	/**
	 * Escribe el plan: sólo `apply_changes`, nada más.
	 *
	 * @param mixed $plan Plan de `plan_*()`.
	 *
	 * @return array<string, mixed>
	 */
	private function apply( $plan ): array {
		$this->backend->write( $plan['touched'], $plan['changes'], $plan['order'] );

		return $plan['result'];
	}

	/**
	 * Plan de `create`.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function plan_create( array $params ) {
		$unknown = $this->unknown_params( $params, array( 'label', 'variants' ) );

		if ( null !== $unknown ) {
			return $unknown;
		}

		$state = $this->backend->read();

		$label = $this->check_label( $params['label'] ?? null, $state['items'], '' );

		if ( is_wp_error( $label ) ) {
			return $label;
		}

		if ( count( $state['items'] ) >= self::MAX_CLASSES ) {
			return new \WP_Error(
				'nexo_mcp_design_limit_reached',
				sprintf(
					/* translators: %d: máximo de clases. */
					__( 'Ya hay %d clases globales, el máximo. Borrá alguna antes de crear otra.', 'nexo-mcp' ),
					self::MAX_CLASSES
				),
				array( 'max' => self::MAX_CLASSES )
			);
		}

		if ( ! array_key_exists( 'variants', $params ) ) {
			return $this->params_error( 'variants', __( 'indicá la lista de variantes (puede ir vacía).', 'nexo-mcp' ) );
		}

		$id    = $this->new_id( $state );
		$style = $this->check_style( $id, $label, $params['variants'] );

		if ( is_wp_error( $style ) ) {
			return $style;
		}

		return array(
			'touched' => array( $id => $style ),
			'changes' => array(
				'added'    => array( $id ),
				'deleted'  => array(),
				'modified' => array(),
				'order'    => true,
			),
			'order'   => array_merge( $this->order_of( $state ), array( $id ) ),
			'result'  => array(
				'id'    => $id,
				'label' => $label,
			),
		);
	}

	/**
	 * Plan de `update`.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function plan_update( array $params ) {
		$unknown = $this->unknown_params( $params, array( 'id', 'label', 'variants' ) );

		if ( null !== $unknown ) {
			return $unknown;
		}

		$id = $this->check_id( $params );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( ! array_key_exists( 'label', $params ) && ! array_key_exists( 'variants', $params ) ) {
			return $this->params_error( 'label', __( 'indicá label, variants o ambos: no hay nada que modificar.', 'nexo-mcp' ), false );
		}

		$state = $this->backend->read();

		if ( ! isset( $state['items'][ $id ] ) ) {
			return $this->not_found( $id );
		}

		$current = $state['items'][ $id ];
		$label   = (string) ( $current['label'] ?? '' );

		if ( array_key_exists( 'label', $params ) ) {
			$label = $this->check_label( $params['label'], $state['items'], $id );

			if ( is_wp_error( $label ) ) {
				return $label;
			}
		}

		$variants = $current['variants'] ?? array();

		if ( array_key_exists( 'variants', $params ) ) {
			$variants = $params['variants'];
		}

		$style = $this->check_style( $id, $label, $variants, $current, array_key_exists( 'variants', $params ) );

		if ( is_wp_error( $style ) ) {
			return $style;
		}

		return array(
			'touched' => array( $id => $style ),
			'changes' => array(
				'added'    => array(),
				'deleted'  => array(),
				'modified' => array( $id ),
				'order'    => false,
			),
			'order'   => $this->order_of( $state ),
			'result'  => array(
				'id'    => $id,
				'label' => $label,
			),
		);
	}

	/**
	 * Plan de `delete`.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function plan_delete( array $params ) {
		$unknown = $this->unknown_params( $params, array( 'id', 'force' ) );

		if ( null !== $unknown ) {
			return $unknown;
		}

		$id = $this->check_id( $params );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( array_key_exists( 'force', $params ) && ! is_bool( $params['force'] ) ) {
			return $this->params_error( 'force', __( 'debe ser verdadero o falso.', 'nexo-mcp' ) );
		}

		$state = $this->backend->read();

		if ( ! isset( $state['items'][ $id ] ) ) {
			return $this->not_found( $id );
		}

		$used  = $this->backend->used_in( $id );
		$force = true === ( $params['force'] ?? false );
		$one   = 1 === count( $used );

		/* translators: 1: id de la clase, 2: cantidad de páginas, 3: títulos. */
		$in_use = $one ? __( 'La clase %1$s está en uso en %2$d página (%3$s). No se borró: si querés borrarla igual, repetí con force.', 'nexo-mcp' ) : __( 'La clase %1$s está en uso en %2$d páginas (%3$s). No se borró: si querés borrarla igual, repetí con force.', 'nexo-mcp' );

		/* translators: 1: id de la clase, 2: cantidad de páginas, 3: títulos. */
		$removed = $one ? __( 'La clase %1$s se quitó de %2$d página (%3$s). Deshacer la devuelve a la lista de clases, pero no vuelve a ponerla en esas páginas.', 'nexo-mcp' ) : __( 'La clase %1$s se quitó de %2$d páginas (%3$s). Deshacer la devuelve a la lista de clases, pero no vuelve a ponerla en esas páginas.', 'nexo-mcp' );

		if ( array() !== $used && ! $force ) {
			return new \WP_Error(
				'nexo_mcp_design_in_use',
				sprintf(
					$in_use,
					$id,
					count( $used ),
					$this->titles( $used )
				),
				array(
					'id'      => $id,
					'used_in' => $used,
				)
			);
		}

		$result = array(
			'deleted'        => $id,
			'pages_affected' => $used,
		);

		if ( array() !== $used ) {
			$result['undo_note'] = sprintf(
				$removed,
				$id,
				count( $used ),
				$this->titles( $used )
			);
		}

		return array(
			'touched' => array(),
			'changes' => array(
				'added'    => array(),
				'deleted'  => array( $id ),
				'modified' => array(),
				'order'    => true,
			),
			'order'   => array_values( array_diff( $this->order_of( $state ), array( $id ) ) ),
			'result'  => $result,
		);
	}

	/**
	 * Valida el label: formato, longitud y unicidad sin distinguir mayúsculas.
	 *
	 * @param mixed                               $label Label recibido.
	 * @param array<string, array<string, mixed>> $items Clases existentes.
	 * @param string                              $own   Id de la clase que se modifica ('' al crear).
	 *
	 * @return string|\WP_Error
	 */
	private function check_label( $label, array $items, string $own ) {
		if ( ! is_string( $label ) || strlen( $label ) > self::MAX_LABEL || 1 !== preg_match( self::LABEL_PATTERN, $label ) ) {
			return new \WP_Error(
				'nexo_mcp_design_invalid',
				__( 'label: debe empezar con una letra, usar sólo letras, números, guiones y guiones bajos, y tener como máximo 50 caracteres.', 'nexo-mcp' ),
				array( 'path' => 'label' )
			);
		}

		foreach ( $items as $id => $item ) {
			$other = (string) ( $item['label'] ?? '' );

			if ( (string) $id !== $own && strtolower( $other ) === strtolower( $label ) ) {
				return new \WP_Error(
					'nexo_mcp_design_label_taken',
					sprintf(
						/* translators: 1: label existente, 2: id de la clase. */
						__( 'label: ya existe una clase llamada «%1$s» (%2$s). Elegí otro nombre: no se renombra solo.', 'nexo-mcp' ),
						$other,
						(string) $id
					),
					array(
						'path' => 'label',
						'id'   => (string) $id,
					)
				);
			}
		}

		return $label;
	}

	/**
	 * Valida las variantes como un estilo local de F2/F3 y con el analizador de Elementor.
	 *
	 * @param string               $id       Id de la clase.
	 * @param string               $label    Label ya validado.
	 * @param mixed                $variants Variantes recibidas.
	 * @param array<string, mixed> $base     Item actual (al modificar), para conservar sus otras claves.
	 * @param bool                 $check_variants Valida las variantes con el validador de F2/F3 (no hace falta si no cambian).
	 *
	 * @return array<string, mixed>|\WP_Error Item listo para escribir.
	 */
	private function check_style( string $id, string $label, $variants, array $base = array(), bool $check_variants = true ) {
		if ( ! is_array( $variants ) || ! array_is_list( $variants ) ) {
			return $this->params_error( 'variants', __( 'debe ser una lista de variantes.', 'nexo-mcp' ) );
		}

		foreach ( $variants as $index => $variant ) {
			if ( is_array( $variant ) && isset( $variant['meta'] ) && is_array( $variant['meta'] ) && ! array_key_exists( 'state', $variant['meta'] ) ) {
				$variants[ $index ]['meta']['state'] = null; // Sin estado = estado base.
			}
		}

		$style = array_merge(
			$base,
			array(
				'id'       => $id,
				'type'     => 'class',
				'label'    => $label,
				'variants' => $variants,
			)
		);

		if ( $check_variants ) {
			$validator = new ElementorTreeValidator( array( 'e-div-block' => array() ), array(), null, $this->backend->style_props() );
			$checked   = $validator->validate(
				array(
					array(
						'type'     => 'e-div-block',
						'settings' => array(),
						'styles'   => array( $id => array_intersect_key( $style, array_flip( ElementorTreeValidator::STYLE_KEYS ) ) ),
					),
				)
			);

			if ( is_wp_error( $checked ) ) {
				return $this->relative( $checked, 'elements[0].styles.' . $id . '.' );
			}
		}

		$parsed = $this->backend->parse_style( $style );

		if ( is_wp_error( $parsed ) ) {
			return $this->as_invalid( $parsed );
		}

		return $parsed;
	}

	/**
	 * Un error de validación de un tercero con el código único `nexo_mcp_design_invalid`.
	 *
	 * @param \WP_Error $error Error con mensaje `ruta: motivo` y `path` en los datos.
	 */
	private function as_invalid( \WP_Error $error ): \WP_Error {
		return new \WP_Error( 'nexo_mcp_design_invalid', $error->get_error_message(), $error->get_error_data() );
	}

	/**
	 * Reescribe un error del validador con la ruta relativa a la clase (`variants[0].props.color`).
	 *
	 * @param \WP_Error $error  Error del validador.
	 * @param string    $prefix Prefijo de ruta del validador.
	 */
	private function relative( \WP_Error $error, string $prefix ): \WP_Error {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();

		if ( ! str_starts_with( $message, $prefix ) ) {
			return $this->as_invalid( $error );
		}

		$path = is_array( $data ) && isset( $data['path'] ) && is_string( $data['path'] ) && str_starts_with( $data['path'], $prefix )
			? substr( $data['path'], strlen( $prefix ) )
			: substr( $message, strlen( $prefix ), (int) strpos( $message, ':', strlen( $prefix ) ) - strlen( $prefix ) );

		return new \WP_Error(
			'nexo_mcp_design_invalid',
			substr( $message, strlen( $prefix ) ),
			array( 'path' => $path )
		);
	}

	/**
	 * Id `g-` que no choca con ninguno existente.
	 *
	 * @param array{items: array<string, array<string, mixed>>, order: array<int, string>} $state Estado actual.
	 */
	private function new_id( array $state ): string {
		do {
			$id = (string) ( $this->id_generator )();
		} while ( isset( $state['items'][ $id ] ) || in_array( $id, $state['order'], true ) );

		return $id;
	}

	/**
	 * Orden actual sin ids huérfanos, más las clases que Elementor no lista en el orden.
	 *
	 * @param array{items: array<string, array<string, mixed>>, order: array<int, string>} $state Estado actual.
	 *
	 * @return array<int, string>
	 */
	private function order_of( array $state ): array {
		$order = array_values( array_filter( $state['order'], static fn( $id ): bool => isset( $state['items'][ $id ] ) ) );

		return array_values( array_unique( array_merge( $order, array_map( 'strval', array_keys( $state['items'] ) ) ) ) );
	}

	/**
	 * Id de clase recibido.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 *
	 * @return string|\WP_Error
	 */
	private function check_id( array $params ) {
		$id = $params['id'] ?? null;

		if ( ! is_string( $id ) || '' === $id ) {
			return $this->params_error( 'id', __( 'indicá el id de la clase (g-…).', 'nexo-mcp' ) );
		}

		return $id;
	}

	/**
	 * Error de clase inexistente.
	 *
	 * @param string $id Id pedido.
	 */
	private function not_found( string $id ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_design_not_found',
			sprintf(
				/* translators: %s: id de la clase. */
				__( 'No existe ninguna clase global con el id «%s».', 'nexo-mcp' ),
				$id
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Rechaza parámetros que la operación no conoce.
	 *
	 * @param array<string, mixed> $params  Parámetros.
	 * @param array<int, string>   $allowed Admitidos.
	 */
	private function unknown_params( array $params, array $allowed ): ?\WP_Error {
		foreach ( array_keys( $params ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return $this->params_error(
					(string) $key,
					sprintf(
						/* translators: %s: parámetros admitidos. */
						__( 'parámetro desconocido. Admitidos: %s.', 'nexo-mcp' ),
						implode( ', ', $allowed )
					)
				);
			}
		}

		return null;
	}

	/**
	 * Error de parámetro mal formado.
	 *
	 * @param string $path   Parámetro.
	 * @param string $reason Motivo.
	 * @param bool   $prefix Antepone la ruta al mensaje.
	 */
	private function params_error( string $path, string $reason, bool $prefix = true ): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_design_invalid',
			$prefix ? $path . ': ' . $reason : $reason,
			array( 'path' => $path )
		);
	}

	/**
	 * Títulos de las páginas, para los mensajes.
	 *
	 * @param array<int, array{id: int, title: string}> $used Páginas.
	 */
	private function titles( array $used ): string {
		return implode( ', ', array_map( static fn( array $page ): string => $page['title'], $used ) );
	}

	/**
	 * Parámetros para Actividad: sin el contenido de las variantes.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 *
	 * @return array<string, mixed>
	 */
	private function audit_params( array $params ): array {
		if ( isset( $params['variants'] ) && is_array( $params['variants'] ) ) {
			$params['variants'] = count( $params['variants'] );
		}

		return $params;
	}
}
