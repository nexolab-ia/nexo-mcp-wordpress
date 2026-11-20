<?php
/**
 * El guardia del transporte cuenta una unidad por pedido y responde 429 al pasar el límite.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Tests;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\MCP\ServerRegistrar;
use NexoDev\NexoMCP\MCP\TransportGuard;
use NexoDev\NexoMCP\OAuth\ScopeMap;
use NexoDev\NexoMCP\Security\HttpsPolicy;
use NexoDev\NexoMCP\Security\RateLimiter;

final class TransportGuardTest extends NexoTestCase {

	private const ROUTE = '/nexo-mcp/v1/mcp';

	private function guard( array $overrides = array() ): TransportGuard {
		$settings = $this->settings( $overrides );
		$this->connect_with_scopes( array( ScopeMap::CONNECT ) );

		return new TransportGuard(
			$settings,
			new HttpsPolicy(),
			new PermissionPolicy( $settings ),
			new RateLimiter(),
			new AuditLogger( new AuditRepository() )
		);
	}

	private function request( string $route = self::ROUTE ): \WP_REST_Request {
		return new \WP_REST_Request( 'POST', $route );
	}

	private function units(): int {
		return array_sum( \WP_Test_State::$rate_hits );
	}

	/**
	 * Filas de auditoría por estado.
	 */
	private function audited( string $status ): int {
		return count( array_filter( \WP_Test_State::$audit, static fn ( $row ) => $status === $row['status'] ) );
	}

	/**
	 * Aplica el gancho `rest_post_dispatch` de S4 como lo haría WordPress.
	 *
	 * @param mixed $response Respuesta previa.
	 */
	private function dispatch( TransportGuard $guard, $response, \WP_REST_Request $request ) {
		return ( new ServerRegistrar( $guard ) )->limit_response( $response, null, $request );
	}

	public function test_el_mismo_pedido_evaluado_cuatro_veces_consume_una_unidad(): void {
		$guard   = $this->guard();
		$request = $this->request();

		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertTrue( $guard->authorize( $request ) );
		}

		$this->assertSame( 1, $this->units() );
	}

	public function test_pedidos_distintos_cuentan_aparte(): void {
		$guard = $this->guard();

		$this->assertTrue( $guard->authorize( $this->request() ) );
		$this->assertTrue( $guard->authorize( $this->request() ) );

		$this->assertSame( 2, $this->units() );
	}

	public function test_la_denegacion_se_audita_una_vez_por_pedido(): void {
		$guard   = $this->guard( array( 'server_enabled' => false ) );
		$request = $this->request();

		for ( $i = 0; $i < 4; $i++ ) {
			$result = $guard->authorize( $request );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'nexo_mcp_server_disabled', $result->get_error_code() );
		}
		$this->assertSame( 1, $this->audited( 'denied' ) );

		$guard->authorize( $this->request() );
		$this->assertSame( 2, $this->audited( 'denied' ) );
	}

	public function test_el_limite_audita_una_vez_y_repite_el_mismo_error(): void {
		\WP_Test_State::$filters['nexo_mcp_transport_rate_limit'] = 1;
		$guard   = $this->guard();
		$request = $this->request();

		$this->assertTrue( $guard->authorize( $request ) );
		$first = $guard->authorize( $this->request() );
		$this->assertSame( 'nexo_mcp_rate_limit_exceeded', $first->get_error_code() );

		$same_request = $this->request();
		$guard->authorize( $same_request );
		$guard->authorize( $same_request );
		$guard->authorize( $same_request );

		$this->assertSame( 3, $this->units() );
		$this->assertSame( 2, $this->audited( 'limited' ) );
	}

	public function test_el_limite_responde_429_con_retry_after(): void {
		\WP_Test_State::$filters['nexo_mcp_transport_rate_limit'] = 1;
		$guard = $this->guard();
		$guard->authorize( $this->request() );

		$request = $this->request();
		$error   = $guard->authorize( $request );
		$this->assertSame( 'nexo_mcp_rate_limit_exceeded', $error->get_error_code() );

		$response = new \WP_REST_Response(
			array( 'code' => 'rest_forbidden', 'message' => 'Sorry, you are not allowed to do that.', 'data' => array( 'status' => 403 ) ),
			403
		);
		$result   = $this->dispatch( $guard, $response, $request );

		$retry_after = $error->get_error_data()['retry_after'];
		$this->assertGreaterThanOrEqual( 1, $retry_after );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame(
			array(
				'code'    => 'nexo_mcp_rate_limit_exceeded',
				'message' => $error->get_error_message(),
				'data'    => array( 'status' => 429, 'retry_after' => $retry_after ),
			),
			$result->get_data()
		);
		$this->assertSame( (string) $retry_after, $result->get_headers()['Retry-After'] );
	}

	public function test_una_denegacion_que_no_es_de_limite_no_cambia(): void {
		$guard   = $this->guard( array( 'server_enabled' => false ) );
		$request = $this->request();
		$guard->authorize( $request );

		$data     = array( 'code' => 'rest_forbidden', 'message' => 'Sorry', 'data' => array( 'status' => 403 ) );
		$response = new \WP_REST_Response( $data, 403 );
		$result   = $this->dispatch( $guard, $response, $request );

		$this->assertSame( 403, $result->get_status() );
		$this->assertSame( $data, $result->get_data() );
		$this->assertArrayNotHasKey( 'Retry-After', $result->get_headers() );
	}

	public function test_otra_ruta_o_pedido_aprobado_no_cambian(): void {
		\WP_Test_State::$filters['nexo_mcp_transport_rate_limit'] = 1;
		$guard = $this->guard();
		$guard->authorize( $this->request() );

		$limited = $this->request( '/otro/v1/ruta' );
		$guard->authorize( $limited );
		$other = new \WP_REST_Response( array( 'x' => 1 ), 403 );
		$this->assertSame( 403, $this->dispatch( $guard, $other, $limited )->get_status() );

		$fresh = $this->request();
		$ok    = new \WP_REST_Response( array( 'x' => 1 ), 200 );
		$this->assertSame( 200, $this->dispatch( $guard, $ok, $fresh )->get_status() );
	}

	public function test_sin_pedido_cada_llamada_consume_como_antes(): void {
		$guard = $this->guard();

		$this->assertTrue( $guard->authorize() );
		$this->assertTrue( $guard->authorize() );
		$this->assertSame( 2, $this->units() );
	}

	public function test_el_ticket_de_subida_consume_en_cada_llamada(): void {
		$guard = $this->guard();

		$this->assertTrue( $guard->authorize_upload_ticket() );
		$this->assertTrue( $guard->authorize_upload_ticket() );
		$this->assertSame( 2, $this->units() );
	}

	public function test_register_engancha_el_429_en_rest_post_dispatch(): void {
		$registrar = new ServerRegistrar( $this->guard() );
		$registrar->register();

		$this->assertContains(
			array( 'rest_post_dispatch', array( $registrar, 'limit_response' ), 9, 3 ),
			\WP_Test_State::$hooks,
			'register() tiene que enganchar limit_response antes del desafío de BearerTokenGuard (prioridad 10)'
		);
	}

	public function test_una_denegacion_no_se_vuelve_permiso_en_el_mismo_pedido(): void {
		$guard   = $this->guard();
		$request = $this->request();
		\WP_Test_State::$user_id = 0;
		$this->assertSame( 'nexo_mcp_authentication_required', $guard->authorize( $request )->get_error_code() );

		\WP_Test_State::$user_id = 1;
		$this->assertInstanceOf( \WP_Error::class, $guard->authorize( $request ) );
		$this->assertTrue( $guard->authorize( $this->request() ), 'un pedido nuevo se evalúa de cero' );
	}

	public function test_un_permiso_no_pasa_a_otro_pedido(): void {
		$guard = $this->guard();
		$this->assertTrue( $guard->authorize( $this->request() ) );

		\WP_Test_State::$user_id = 0;
		$this->assertSame( 'nexo_mcp_authentication_required', $guard->authorize( $this->request() )->get_error_code() );
	}

	public function test_pedidos_intercalados_se_reevaluan(): void {
		$guard = $this->guard();
		$a     = $this->request();
		$b     = $this->request();
		$guard->authorize( $a );
		$guard->authorize( $b );
		$guard->authorize( $a );

		$this->assertSame( 3, $this->units() );
		$this->assertNull( $guard->rate_limit_error( $b ) );
	}

	public function test_sin_pedido_no_toca_la_decision_recordada(): void {
		\WP_Test_State::$filters['nexo_mcp_transport_rate_limit'] = 1;
		$guard = $this->guard();
		$guard->authorize( $this->request() );
		$limitado = $this->request();
		$guard->authorize( $limitado );
		$guard->authorize();
		$guard->authorize_upload_ticket();

		$this->assertSame( 4, $this->units() );
		$this->assertNotNull( $guard->rate_limit_error( $limitado ) );
		$this->assertNull( $guard->rate_limit_error( null ) );
		$this->assertNull( $guard->rate_limit_error( $this->request() ) );
	}
}
