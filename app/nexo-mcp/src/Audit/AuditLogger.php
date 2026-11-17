<?php
/**
 * Servicio de auditoría con redacción.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Audit;

use NexoDev\NexoMCP\Authentication\AuthenticationContext;
use NexoDev\NexoMCP\Security\ClientIpResolver;
use NexoDev\NexoMCP\Security\SensitiveDataRedactor;

/**
 * Registra intentos y resultados sin secretos.
 */
final class AuditLogger {

	/**
	 * Persistencia.
	 *
	 * @var AuditRepository
	 */
	private AuditRepository $repository;

	/**
	 * Redactor recursivo.
	 *
	 * @var SensitiveDataRedactor
	 */
	private SensitiveDataRedactor $redactor;

	/**
	 * Resolución conservadora de IP.
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $ip_resolver;

	/**
	 * Construye el logger.
	 *
	 * @param AuditRepository $repository Persistencia.
	 */
	public function __construct( AuditRepository $repository ) {
		$this->repository  = $repository;
		$this->redactor    = new SensitiveDataRedactor();
		$this->ip_resolver = new ClientIpResolver();
	}

	/**
	 * Registra un evento.
	 *
	 * @param string $tool       Herramienta o acción.
	 * @param string $operation  read, write o admin.
	 * @param string $status     success, denied, limited o error.
	 * @param mixed  $parameters Parámetros originales.
	 * @param mixed  $result     Resultado resumible.
	 * @param float  $duration   Duración en milisegundos.
	 */
	public function log(
		string $tool,
		string $operation,
		string $status,
		$parameters = array(),
		$result = array(),
		float $duration = 0.0
	): bool {
		$ip = $this->ip_resolver->resolve();

		return $this->repository->insert(
			array(
				'occurred_at'       => current_time( 'mysql', true ),
				'user_id'           => 0 !== get_current_user_id() ? get_current_user_id() : null,
				'credential_uuid'   => AuthenticationContext::credential_uuid(),
				'tool'              => sanitize_key( $tool ),
				'operation'         => sanitize_key( $operation ),
				'status'            => sanitize_key( $status ),
				'parameter_summary' => $this->redactor->summarize( $parameters ),
				'result_summary'    => $this->redactor->summarize( $result ),
				'ip_hash'           => null === $ip ? null : hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ),
				'ip_address'        => null,
				'duration_ms'       => max( 0, $duration ),
			)
		);
	}
}
