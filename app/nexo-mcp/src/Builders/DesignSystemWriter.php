<?php
/**
 * Escritura del sistema de diseño: bloqueo, respaldo, vuelta atrás, deshacer y Actividad.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Support\PostLock;

/**
 * Envuelve toda escritura de clases, variables o Kit en el mismo protocolo.
 *
 * 1. Requisitos: Elementor, Kit activo y (clases y variables) elementos atómicos.
 * 2. Bloqueo por el id del Kit: las tres clases de escritura compiten por la misma llave.
 * 3. Dentro del bloqueo: validar (sin escribir), comprobar que el bloqueo sigue siendo
 *    propio, guardar el estado previo como único «último cambio» y aplicar.
 * 4. Si aplicar falla (excepción o `WP_Error`) se restaura el estado previo y el respaldo
 *    anterior; si ni eso se puede, el error lo dice y el respaldo queda para deshacer a mano.
 * 5. Tras escribir o restaurar se limpia el CSS generado y queda registro en Actividad.
 */
final class DesignSystemWriter {

	/**
	 * Opción (sin autocarga) con el último cambio respaldado.
	 */
	public const BACKUP_OPTION = 'nexo_mcp_design_system_backup';

	/**
	 * Tipos de escritura admitidos.
	 *
	 * @var array<int, string>
	 */
	private const KINDS = array( 'classes', 'variables', 'kit' );

	/**
	 * Listas del Kit que respalda `snapshot( 'kit' )`.
	 *
	 * @var array<int, string>
	 */
	private const KIT_LISTS = array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' );

	/**
	 * Almacén de Elementor.
	 *
	 * @var DesignSystemStore
	 */
	private DesignSystemStore $store;

	/**
	 * Registro de Actividad: fn( herramienta, estado, parámetros, resultado ).
	 *
	 * @var callable
	 */
	private $audit;

	/**
	 * Construye el escritor.
	 *
	 * @param DesignSystemStore|null $store Almacén; por defecto el de Elementor.
	 * @param callable|null          $audit Registro de Actividad; por defecto `AuditLogger`.
	 */
	public function __construct( ?DesignSystemStore $store = null, ?callable $audit = null ) {
		$this->store = $store ?? new ElementorDesignStore();
		$this->audit = $audit ?? static function ( string $tool, string $status, $params, $result ): void {
			( new AuditLogger( new AuditRepository() ) )->log( $tool, 'write', $status, $params, $result );
		};
	}

	/**
	 * Escribe con respaldo.
	 *
	 * `$validate` no escribe: devuelve el plan (se le pasa a `$apply`) o un `WP_Error`.
	 * `$apply( $plan )` escribe y devuelve el resultado; puede traer `undo_note`, que se
	 * guarda en el respaldo y no se devuelve.
	 *
	 * @param string               $kind      classes, variables o kit.
	 * @param string               $operation Nombre de la operación (para Actividad y el respaldo).
	 * @param callable             $validate  fn(): mixed|\WP_Error.
	 * @param callable             $apply     fn( mixed $plan ): array|\WP_Error.
	 * @param array<string, mixed> $params    Parámetros para Actividad.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function write( string $kind, string $operation, callable $validate, callable $apply, array $params = array() ) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error(
				'nexo_mcp_design_kind_invalid',
				__( 'Tipo de sistema de diseño desconocido.', 'nexo-mcp' )
			);
		}

		$kit_id = $this->preflight( $kind );

		if ( is_wp_error( $kit_id ) ) {
			return $kit_id;
		}

		return PostLock::run(
			$kit_id,
			fn() => $this->write_locked( $kind, $operation, $validate, $apply, $params, $kit_id )
		);
	}

	/**
	 * Deshace el último cambio del sistema de diseño.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		$backup = $this->read_backup();

		if ( null === $backup ) {
			return $this->no_backup();
		}

		$kit_id = $this->preflight( $backup['kind'] );

		if ( is_wp_error( $kit_id ) ) {
			return $kit_id;
		}

		return PostLock::run( $kit_id, fn() => $this->undo_locked( $kit_id ) );
	}

	/**
	 * Comprueba Elementor, el Kit y los elementos atómicos.
	 *
	 * @param string $kind Tipo.
	 *
	 * @return int|\WP_Error Id del Kit.
	 */
	private function preflight( string $kind ) {
		if ( ! $this->store->available() ) {
			return new \WP_Error(
				'nexo_mcp_elementor_missing',
				__( 'Elementor no está activo en este sitio, así que no hay sistema de diseño que cambiar.', 'nexo-mcp' )
			);
		}

		$kit_id = $this->store->kit_id();

		if ( $kit_id <= 0 ) {
			return new \WP_Error(
				'nexo_mcp_elementor_kit_missing',
				__( 'Elementor no tiene un Kit activo, así que no hay sistema de diseño que cambiar.', 'nexo-mcp' )
			);
		}

		if ( 'kit' !== $kind && ! $this->store->atomic() ) {
			return new \WP_Error(
				'nexo_mcp_elementor_v4_required',
				__( 'Las clases y variables globales requieren el Editor V4 de Elementor (elementos atómicos) activo.', 'nexo-mcp' )
			);
		}

		return $kit_id;
	}

	/**
	 * Cuerpo de `write()` bajo el bloqueo del Kit.
	 *
	 * @param string               $kind      Tipo.
	 * @param string               $operation Operación.
	 * @param callable             $validate  Validación.
	 * @param callable             $apply     Escritura.
	 * @param array<string, mixed> $params    Parámetros para Actividad.
	 * @param int                  $kit_id    Id del Kit (clave del bloqueo).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function write_locked( string $kind, string $operation, callable $validate, callable $apply, array $params, int $kit_id ) {
		$tool = 'design-system-' . $operation;

		try {
			$plan = $validate();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'nexo_mcp_design_write_failed', $this->failure_message( $e ) );
		}

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// La validación puede ser larga: antes de la primera escritura se confirma que el bloqueo sigue siendo propio.
		$verified = PostLock::verify( $kit_id );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		try {
			$snapshot = $this->store->snapshot( $kind );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'nexo_mcp_design_write_failed', $this->failure_message( $e ) );
		}

		// Un respaldo que `undo()` no aceptaría no sirve: si el estado real no pasa la misma comprobación, no se escribe.
		$state = $this->normalize_state( $kind, $snapshot );

		if ( null === $state ) {
			return new \WP_Error(
				'nexo_mcp_design_backup_failed',
				__( 'El estado actual no tiene una forma que se pueda respaldar y deshacer, así que no se escribió nada.', 'nexo-mcp' )
			);
		}

		$previous_backup = get_option( self::BACKUP_OPTION, null );

		$backup = array(
			'kind'      => $kind,
			'operation' => $operation,
			'kit_id'    => $kit_id,
			'state'     => $state,
			'at'        => gmdate( 'Y-m-d H:i:s' ),
			'user'      => get_current_user_id(),
			'note'      => '',
		);

		// `update_option()` devuelve false también cuando nada cambia: lo que cuenta es lo guardado.
		update_option( self::BACKUP_OPTION, $backup, false );

		if ( get_option( self::BACKUP_OPTION, null ) !== $backup ) {
			return new \WP_Error(
				'nexo_mcp_design_backup_failed',
				__( 'No se pudo guardar el respaldo del estado actual, así que no se escribió nada.', 'nexo-mcp' )
			);
		}

		try {
			$result = $apply( $plan );
		} catch ( \Throwable $e ) {
			return $this->roll_back( $kind, $state, $previous_backup, new \WP_Error( 'nexo_mcp_design_write_failed', $this->failure_message( $e ) ), $tool, $params );
		}

		if ( is_wp_error( $result ) ) {
			return $this->roll_back( $kind, $state, $previous_backup, $result, $tool, $params );
		}

		$result = is_array( $result ) ? $result : array();
		$note   = isset( $result['undo_note'] ) && is_string( $result['undo_note'] ) ? $result['undo_note'] : '';
		unset( $result['undo_note'] );

		if ( '' !== $note ) {
			$backup = $this->read_backup();

			if ( null !== $backup ) {
				$backup['note'] = $note;
				update_option( self::BACKUP_OPTION, $backup, false );
			}
		}

		$this->clear_cache();

		$result['undo_available'] = true;

		$this->log( $tool, 'success', $params, $result );

		return $result;
	}

	/**
	 * Restaura el estado previo tras una escritura fallida.
	 *
	 * @param string               $kind            Tipo.
	 * @param array<string, mixed> $state           Estado previo.
	 * @param mixed                $previous_backup Valor anterior de la opción de respaldo (null = no había).
	 * @param \WP_Error            $error           Error original.
	 * @param string               $tool            Nombre en Actividad.
	 * @param array<string, mixed> $params          Parámetros para Actividad.
	 *
	 * @return \WP_Error
	 */
	private function roll_back( string $kind, array $state, $previous_backup, \WP_Error $error, string $tool, array $params ): \WP_Error {
		try {
			$this->store->restore( $kind, $state );
		} catch ( \Throwable $e ) {
			$this->clear_cache();

			$failed = new \WP_Error(
				'nexo_mcp_design_rollback_failed',
				sprintf(
					/* translators: 1: error original, 2: error al restaurar. */
					__( 'La escritura falló (%1$s) y tampoco se pudo restaurar el estado previo (%2$s). El respaldo quedó guardado: probá deshacer el último cambio.', 'nexo-mcp' ),
					$error->get_error_message(),
					$e->getMessage()
				),
				array( 'original_error' => $error->get_error_code() )
			);

			$summary = array(
				'error'          => $failed->get_error_code(),
				'original_error' => $error->get_error_code(),
			);

			$this->log( $tool, 'error', $params, $summary );

			return $failed;
		}

		if ( null === $previous_backup ) {
			delete_option( self::BACKUP_OPTION );
		} else {
			update_option( self::BACKUP_OPTION, $previous_backup, false );
		}

		$this->clear_cache();
		$summary = array(
			'error'    => $error->get_error_code(),
			'restored' => true,
		);

		$this->log( $tool, 'error', $params, $summary );

		return $error;
	}

	/**
	 * Cuerpo de `undo()` bajo el bloqueo del Kit.
	 *
	 * @param int $kit_id Id del Kit (clave del bloqueo).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function undo_locked( int $kit_id ) {
		// Se relee dentro del bloqueo: otro proceso pudo deshacer o escribir mientras se esperaba.
		$backup = $this->read_backup();

		if ( null === $backup ) {
			return $this->no_backup();
		}

		$params = array(
			'kind'      => $backup['kind'],
			'operation' => $backup['operation'],
		);

		// El respaldo es del Kit que estaba activo al escribir: con otro Kit activo restaurarlo escribiría en el equivocado.
		if ( $backup['kit_id'] !== $kit_id ) {
			$error = new \WP_Error(
				'nexo_mcp_design_kit_changed',
				sprintf(
					/* translators: 1: id del Kit del respaldo, 2: id del Kit activo. */
					__( 'El último cambio se hizo sobre el Kit %1$d y ahora el Kit activo es el %2$d, así que no se restauró nada. Reactivá el Kit %1$d para deshacerlo; el respaldo se conserva.', 'nexo-mcp' ),
					$backup['kit_id'],
					$kit_id
				),
				array(
					'backup_kit_id' => $backup['kit_id'],
					'active_kit_id' => $kit_id,
				)
			);

			$this->log( 'design-system-undo', 'error', $params, array( 'error' => $error->get_error_code() ) );

			return $error;
		}

		$verified = PostLock::verify( $kit_id );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		try {
			$this->store->restore( $backup['kind'], $backup['state'] );
		} catch ( \Throwable $e ) {
			$this->clear_cache();

			$error = new \WP_Error(
				'nexo_mcp_design_undo_failed',
				sprintf(
					/* translators: %s: motivo. */
					__( 'No se pudo deshacer el último cambio: %s. El respaldo se conserva.', 'nexo-mcp' ),
					$this->failure_message( $e )
				)
			);

			$this->log( 'design-system-undo', 'error', $params, array( 'error' => $error->get_error_code() ) );

			return $error;
		}

		delete_option( self::BACKUP_OPTION );
		$this->clear_cache();

		$result = array(
			'kind'       => $backup['kind'],
			'operation'  => $backup['operation'],
			'changed_at' => $backup['at'],
		);

		if ( '' !== $backup['note'] ) {
			$result['warnings'] = array( $backup['note'] );
		}

		$this->log( 'design-system-undo', 'success', $params, $result );

		return $result;
	}

	/**
	 * Respaldo guardado, o null si falta o no tiene la forma esperada.
	 *
	 * @return array{kind: string, operation: string, kit_id: int, state: array<string, mixed>, at: string, user: int, note: string}|null
	 */
	private function read_backup(): ?array {
		$backup = get_option( self::BACKUP_OPTION, null );

		if (
			! is_array( $backup )
			|| ! isset( $backup['kind'], $backup['operation'], $backup['state'] )
			|| ! is_string( $backup['kind'] )
			|| ! in_array( $backup['kind'], self::KINDS, true )
			|| ! is_string( $backup['operation'] )
		) {
			return null;
		}

		$state = $this->normalize_state( $backup['kind'], $backup['state'] );

		if ( null === $state ) {
			return null;
		}

		return array(
			'kind'      => $backup['kind'],
			'operation' => $backup['operation'],
			'kit_id'    => isset( $backup['kit_id'] ) && is_int( $backup['kit_id'] ) ? $backup['kit_id'] : 0,
			'state'     => $state,
			'at'        => isset( $backup['at'] ) && is_string( $backup['at'] ) ? $backup['at'] : '',
			'user'      => isset( $backup['user'] ) ? (int) $backup['user'] : 0,
			'note'      => isset( $backup['note'] ) && is_string( $backup['note'] ) ? $backup['note'] : '',
		);
	}

	/**
	 * Normaliza un estado a la forma que entrega `snapshot()` de su tipo, o null si no es válido.
	 *
	 * Lo que Elementor guarda de verdad no siempre es limpio, y un respaldo que no se pueda
	 * restaurar no sirve, así que se acepta y se corrige lo inofensivo:
	 *
	 * - clases: ids del orden sin item se descartan;
	 * - variables: un registro sin `watermark` pasa a `0`;
	 * - Kit: sólo las cuatro listas.
	 *
	 * Un respaldo malformado se trata como «no hay respaldo» (`nexo_mcp_design_no_backup`):
	 * nunca se restaura y no se toca. Un estado real que no lo pasa impide escribir.
	 *
	 * @param string $kind  Tipo.
	 * @param mixed  $state Estado de `snapshot()` o guardado.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalize_state( string $kind, $state ): ?array {
		if ( ! is_array( $state ) ) {
			return null;
		}

		if ( 'classes' === $kind ) {
			$keys = array_keys( $state );
			sort( $keys );

			if ( array( 'items', 'order' ) !== $keys ) {
				return null;
			}

			if ( ! is_array( $state['items'] ) || ! is_array( $state['order'] ) || ! array_is_list( $state['order'] ) ) {
				return null;
			}

			foreach ( $state['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					return null;
				}
			}

			$order = array();

			foreach ( $state['order'] as $id ) {
				if ( ! is_string( $id ) ) {
					return null;
				}

				if ( array_key_exists( $id, $state['items'] ) ) {
					$order[] = $id;
				}
			}

			return array(
				'items' => $state['items'],
				'order' => $order,
			);
		}

		if ( 'variables' === $kind ) {
			if ( array() === $state ) {
				return $state;
			}

			if ( ! isset( $state['data'] ) || ! is_array( $state['data'] ) ) {
				return null;
			}

			if ( ! array_key_exists( 'watermark', $state ) || null === $state['watermark'] ) {
				$state['watermark'] = 0;
			}

			if ( ! is_int( $state['watermark'] ) ) {
				return null;
			}

			foreach ( $state['data'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					return null;
				}
			}

			return $state;
		}

		foreach ( $state as $key => $list ) {
			if ( ! in_array( $key, self::KIT_LISTS, true ) || ! is_array( $list ) ) {
				return null;
			}
		}

		return $state;
	}

	/**
	 * Error de «no hay nada que deshacer».
	 */
	private function no_backup(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_design_no_backup',
			__( 'No hay ningún cambio del sistema de diseño para deshacer.', 'nexo-mcp' )
		);
	}

	/**
	 * Mensaje de una excepción, con un texto de respaldo si viene vacío.
	 *
	 * @param \Throwable $error Excepción.
	 */
	private function failure_message( \Throwable $error ): string {
		$message = trim( $error->getMessage() );

		return '' !== $message ? $message : __( 'Elementor no pudo guardar el cambio.', 'nexo-mcp' );
	}

	/**
	 * Descarta el CSS generado; un fallo no tumba la escritura ya hecha.
	 */
	private function clear_cache(): void {
		try {
			$this->store->clear_cache();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Registra en Actividad; un fallo del registro nunca rompe la escritura.
	 *
	 * @param string $tool   Herramienta.
	 * @param string $status success o error.
	 * @param mixed  $params Parámetros.
	 * @param mixed  $result Resultado.
	 */
	private function log( string $tool, string $status, $params, $result ): void {
		try {
			( $this->audit )( $tool, $status, $params, $result );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
