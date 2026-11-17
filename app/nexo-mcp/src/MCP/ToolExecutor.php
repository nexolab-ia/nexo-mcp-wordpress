<?php
/**
 * Ejecución auditada de herramientas MCP.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Audit\AuditLogger;

/**
 * Registra resultado, errores y duración de cada ability.
 */
final class ToolExecutor {

	/**
	 * Logger seguro.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Construye el ejecutor.
	 *
	 * @param AuditLogger $audit_logger Auditoría persistente.
	 */
	public function __construct( AuditLogger $audit_logger ) {
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Ejecuta una lectura y registra el resultado.
	 *
	 * @param string   $tool       Nombre corto de la herramienta.
	 * @param array    $parameters Parámetros validados.
	 * @param callable $callback   Lógica de lectura.
	 * @return mixed
	 */
	public function read( string $tool, array $parameters, callable $callback ) {
		return $this->execute( $tool, 'read', $parameters, $callback );
	}

	/**
	 * Ejecuta una escritura y registra el resultado.
	 *
	 * @param string   $tool       Nombre corto de la herramienta.
	 * @param array    $parameters Parámetros validados.
	 * @param callable $callback   Lógica de escritura.
	 * @return mixed
	 */
	public function write( string $tool, array $parameters, callable $callback ) {
		return $this->execute( $tool, 'write', $parameters, $callback );
	}

	/**
	 * Registra una herramienta negada antes de ejecutarse.
	 *
	 * La autorización corta antes que el ejecutor, así que una denegación no
	 * dejaba rastro: el dueño veía en el log las llamadas que sí corrieron y
	 * ninguna de las que el conector rechazó. Para diagnosticar un «no puedo
	 * editar» eso es justo la mitad que falta.
	 *
	 * Devuelve el mismo error para poder registrarlo y responderlo de una vez.
	 *
	 * @param string    $tool       Nombre corto de la herramienta.
	 * @param string    $operation  read o write.
	 * @param array     $parameters Parámetros recibidos.
	 * @param \WP_Error $reason     Causa de la denegación.
	 */
	public function denied( string $tool, string $operation, array $parameters, \WP_Error $reason ): \WP_Error {
		$this->audit_logger->log( $tool, $operation, 'denied', $parameters, $reason );

		return $reason;
	}

	/**
	 * Ejecuta y audita una operación.
	 *
	 * @param string   $tool       Nombre corto.
	 * @param string   $operation  read o write.
	 * @param array    $parameters Parámetros validados.
	 * @param callable $callback   Lógica de negocio.
	 * @return mixed
	 */
	private function execute( string $tool, string $operation, array $parameters, callable $callback ) {
		$started = microtime( true );

		try {
			$result = $callback();
			$status = is_wp_error( $result ) ? 'error' : 'success';
		} catch ( \Throwable $exception ) {
			$result = new \WP_Error(
				'nexo_mcp_tool_failed',
				__( 'La herramienta no pudo completar la solicitud.', 'nexo-mcp' )
			);
			$status = 'error';
		}

		$this->audit_logger->log(
			$tool,
			$operation,
			$status,
			$parameters,
			$result,
			( microtime( true ) - $started ) * 1000
		);

		return $result;
	}
}
