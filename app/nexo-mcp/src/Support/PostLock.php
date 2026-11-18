<?php
/**
 * Bloqueo por contenido original para las escrituras que leen y reescriben.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Support;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Exclusión mutua entre procesos PHP simultáneos, con la base de datos como árbitro.
 *
 * Dos ediciones a la vez sobre una misma página publicada leían el mismo estado
 * («sin copia», o el mismo árbol de Elementor) y una pisaba a la otra. Cada
 * escritura de lectura-y-reescritura corre aquí dentro, con la clave del ORIGINAL
 * (así editar por el ID de la copia o por el del original compite por lo mismo).
 *
 * Por qué no `add_option()`: antes de insertar consulta `get_option()` y la caché
 * de opciones (`notoptions`/`alloptions`, o la caché de objetos persistente), y
 * esa caché puede estar vencida en un proceso: dos procesos creerían ser los
 * primeros. Aquí la decisión sale de una sola sentencia atómica contra la base,
 * que no mira ninguna caché y sólo cuenta filas afectadas:
 *
 * - Adquirir: `INSERT IGNORE` en `wp_options` (`option_name` es UNIQUE). 1 fila =
 *   se obtuvo; 0 = ya existía.
 * - Tomar uno vencido: `UPDATE ... WHERE option_name = x AND option_value = <lo
 *   leído>`. Si dos procesos intentan tomarlo, el primero cambia el valor y la
 *   condición del segundo ya no se cumple (0 filas). Nunca «borrar y volver a
 *   agregar», que dejaría una ventana en la que ambos ganan.
 * - Liberar: `DELETE ... WHERE option_name = x AND option_value = <el propio>`: sólo
 *   el dueño (un bloqueo que venció y fue tomado por otro no se borra).
 *
 * El valor es `token|vencimiento`. La fila es `autoload = no`, así que no engorda la
 * carga de opciones. Es reentrante dentro del mismo proceso.
 *
 * Sin latido: el vencimiento no se renueva mientras el trabajo corre. Un trabajo que
 * dura más que {@see self::TTL} podría seguir escribiendo después de que otro proceso
 * tomó el bloqueo vencido. Por eso cada ruta bloqueada llama a {@see self::verify()}
 * justo antes de su primera escritura (la fase de validación y armado es la larga; las
 * escrituras son milisegundos): si el bloqueo ya es de otro no se escribe y la herramienta
 * responde `nexo_mcp_busy`; si sigue siendo propio, se renueva el vencimiento (un paso
 * atómico) para que lo que falta de escritura quede cubierto. No hay comprobación al
 * final de {@see self::run()}: para entonces ya se escribió y no se podría deshacer.
 * Cada toma de un bloqueo vencido queda en el log de auditoría.
 */
final class PostLock {

	/**
	 * Segundos de vigencia: un proceso que muere no traba el contenido para siempre.
	 */
	public const TTL = 30;

	/**
	 * Espera máxima, en segundos, antes de responder «ocupado» (ajustable en pruebas).
	 *
	 * @var float
	 */
	public static float $wait = 5.0;

	/**
	 * Bloqueos que este proceso ya tiene: ID => array( token, profundidad ).
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private static array $held = array();

	/**
	 * Ejecuta `$work` con el bloqueo del contenido, o devuelve el error de ocupado.
	 *
	 * Siempre libera (try/finally). Al obtenerlo se descarta la caché de metadatos del
	 * contenido: lo que otro proceso escribió antes no debe verse desactualizado.
	 *
	 * @param int      $id   ID del contenido original.
	 * @param callable $work Trabajo a ejecutar: fn(): mixed.
	 *
	 * @return mixed|\WP_Error Lo que devuelve `$work`, o `nexo_mcp_busy`.
	 */
	public static function run( int $id, callable $work ) {
		if ( isset( self::$held[ $id ] ) ) {
			++self::$held[ $id ][1];

			try {
				return $work();
			} finally {
				--self::$held[ $id ][1];
			}
		}

		$token = self::acquire( $id, self::$wait );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		self::$held[ $id ] = array( $token, 1 );
		self::forget( $id );

		try {
			return $work();
		} finally {
			unset( self::$held[ $id ] );
			self::release( $id, $token );
		}
	}

	/**
	 * Descarta de la caché de objetos el contenido y sus metadatos.
	 *
	 * Lo leído antes de obtener el bloqueo (`get_post()`, `get_post_meta()`) puede
	 * estar viejo: otro proceso escribió mientras se esperaba. `wp_update_post()`
	 * parte del `get_post()` cacheado, así que sin esto devolvería a su valor viejo
	 * los campos que esta llamada no cambia. Después de llamarla hay que volver a
	 * leer con `get_post()`; nunca usar un objeto leído antes del bloqueo.
	 *
	 * @param int ...$ids IDs de contenido.
	 */
	public static function forget( int ...$ids ): void {
		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'post_meta' );
			}
		}
	}

	/**
	 * Intenta adquirir el bloqueo, esperando hasta `$wait` segundos.
	 *
	 * @param int   $id   ID del contenido original.
	 * @param float $wait Espera máxima en segundos.
	 *
	 * @return string|\WP_Error Token del dueño, o `nexo_mcp_busy`.
	 */
	public static function acquire( int $id, float $wait ) {
		global $wpdb;

		$token    = bin2hex( random_bytes( 8 ) );
		$name     = self::name( $id );
		$deadline = microtime( true ) + $wait;

		do {
			$value = $token . '|' . ( time() + self::TTL );
			$rows  = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					$value
				)
			);

			if ( 1 === (int) $rows ) {
				return $token;
			}

			// Ya existe: si venció, se toma con una comparación atómica.
			$current = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name )
			);

			if ( is_string( $current ) && self::expired( $current ) && self::take_over( $id, $current, $token ) ) {
				return $token;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}

			usleep( 50000 );
		} while ( true );

		return new \WP_Error(
			'nexo_mcp_busy',
			__( 'Otra edición de este contenido está en curso. Reintentá en unos segundos.', 'nexo-mcp' )
		);
	}

	/**
	 * Comprueba, justo antes de escribir, que el bloqueo que este proceso obtuvo sigue siendo suyo.
	 *
	 * Si venció y otro lo tomó (o la fila desapareció) no se debe escribir: responde
	 * `nexo_mcp_busy`. Si sigue siendo propio, renueva el vencimiento con una
	 * comparación atómica. Sin bloqueo propio para ese ID (no hay trabajo bloqueado en
	 * este proceso) no hay nada que comprobar.
	 *
	 * @param int $id ID del contenido original (la clave del bloqueo).
	 *
	 * @return true|\WP_Error
	 */
	public static function verify( int $id ) {
		global $wpdb;

		if ( ! isset( self::$held[ $id ] ) ) {
			return true;
		}

		$token   = self::$held[ $id ][0];
		$name    = self::name( $id );
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name )
		);

		if ( is_string( $current ) && str_starts_with( $current, $token . '|' ) ) {
			$renewed = $token . '|' . ( time() + self::TTL );

			// Con el mismo valor (misma marca de tiempo) un UPDATE no cambia filas: ya está renovado.
			if ( $renewed === $current ) {
				return true;
			}

			$rows = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$renewed,
					$name,
					$current
				)
			);

			if ( 1 === (int) $rows ) {
				return true;
			}

			$now = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name )
			);

			if ( is_string( $now ) && str_starts_with( $now, $token . '|' ) ) {
				return true;
			}
		}

		return new \WP_Error(
			'nexo_mcp_busy',
			__( 'Otra edición tomó este contenido mientras esta trabajaba (el bloqueo venció). No se escribió nada. Reintentá en unos segundos.', 'nexo-mcp' )
		);
	}

	/**
	 * Toma un bloqueo vencido sólo si sigue valiendo lo que se leyó.
	 *
	 * @param int    $id    ID del contenido original.
	 * @param string $stale Valor vencido tal como se leyó.
	 * @param string $token Token del nuevo dueño.
	 */
	public static function take_over( int $id, string $stale, string $token ): bool {
		global $wpdb;

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token . '|' . ( time() + self::TTL ),
				self::name( $id ),
				$stale
			)
		);

		if ( 1 !== (int) $rows ) {
			return false;
		}

		self::audit_takeover( $id, $stale );

		return true;
	}

	/**
	 * Registra en el log de auditoría que se tomó un bloqueo vencido.
	 *
	 * Un bloqueo vencido suele ser un proceso que murió, pero también uno que sigue
	 * trabajando: el rastro permite verlo. Un fallo de la auditoría nunca impide el bloqueo.
	 *
	 * @param int    $id    ID del contenido original.
	 * @param string $stale Valor vencido tal como se leyó.
	 */
	private static function audit_takeover( int $id, string $stale ): void {
		$parts   = explode( '|', $stale );
		$expired = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$result  = $expired > 0 ? array( 'seconds_overdue' => max( 0, time() - $expired ) ) : array( 'malformed' => true );

		try {
			( new AuditLogger( new AuditRepository() ) )->log( 'lock-takeover', 'write', 'success', array( 'post_id' => $id ), $result );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Libera el bloqueo si todavía es de `$token`.
	 *
	 * @param int    $id    ID del contenido original.
	 * @param string $token Token devuelto por `acquire()`.
	 */
	public static function release( int $id, string $token ): bool {
		global $wpdb;

		$name = self::name( $id );
		$last = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name )
		);

		if ( ! is_string( $last ) || ! str_starts_with( $last, $token . '|' ) ) {
			return false;
		}

		$rows = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s",
				$name,
				$last
			)
		);

		return 1 === (int) $rows;
	}

	/**
	 * Nombre de la opción que hace de cerradura.
	 *
	 * @param int $id ID del contenido original.
	 */
	private static function name( int $id ): string {
		return 'nexo_mcp_lock_' . $id;
	}

	/**
	 * Indica si el valor guardado ya venció (o no se entiende, que cuenta como vencido).
	 *
	 * @param string $value Valor `token|vencimiento`.
	 */
	private static function expired( string $value ): bool {
		$parts = explode( '|', $value );

		return (int) ( $parts[1] ?? 0 ) < time();
	}
}
