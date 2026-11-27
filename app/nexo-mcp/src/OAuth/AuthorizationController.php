<?php
/**
 * Endpoint de autorización y pantalla de consentimiento.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\OAuth;

use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Security\ClientIpResolver;
use NexoDev\NexoMCP\Security\HttpsPolicy;
use NexoDev\NexoMCP\Security\RateLimiter;

/**
 * Atiende el paso interactivo del flujo de autorización.
 *
 * El consentimiento se monta sobre la sesión de WordPress: si el propietario no
 * ha iniciado sesión, se le envía al login y se le devuelve aquí. Nunca se pide
 * una contraseña dentro del flujo del cliente MCP.
 */
final class AuthorizationController {

	/**
	 * Ruta interceptada para el endpoint de autorización.
	 */
	public const PATH = 'nexo-mcp-oauth/authorize';

	/**
	 * Acción del nonce de consentimiento.
	 */
	private const NONCE_ACTION = 'nexo_mcp_oauth_consent';

	/**
	 * Registro de clientes.
	 *
	 * @var ClientRegistry
	 */
	private ClientRegistry $clients;

	/**
	 * Almacenamiento persistente.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $repository;

	/**
	 * Política HTTPS.
	 *
	 * @var HttpsPolicy
	 */
	private HttpsPolicy $https_policy;

	/**
	 * Limitador persistente.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $rate_limiter;

	/**
	 * Auditoría persistente.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Resolución de IP.
	 *
	 * @var ClientIpResolver
	 */
	private ClientIpResolver $ip_resolver;

	/**
	 * Construye el endpoint.
	 *
	 * @param ClientRegistry  $clients      Registro de clientes.
	 * @param TokenRepository $repository   Almacenamiento persistente.
	 * @param HttpsPolicy     $https_policy Política HTTPS.
	 * @param RateLimiter     $rate_limiter Limitador persistente.
	 * @param AuditLogger     $audit_logger Auditoría segura.
	 */
	public function __construct(
		ClientRegistry $clients,
		TokenRepository $repository,
		HttpsPolicy $https_policy,
		RateLimiter $rate_limiter,
		AuditLogger $audit_logger
	) {
		$this->clients      = $clients;
		$this->repository   = $repository;
		$this->https_policy = $https_policy;
		$this->rate_limiter = $rate_limiter;
		$this->audit_logger = $audit_logger;
		$this->ip_resolver  = new ClientIpResolver();
	}

	/**
	 * Engancha la intercepción de la ruta.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_handle' ), 0 );
	}

	/**
	 * Atiende la solicitud cuando corresponde al endpoint de autorización.
	 */
	public function maybe_handle(): void {
		if ( self::PATH !== $this->current_path() ) {
			return;
		}

		if ( ! $this->https_policy->allows_current_request() ) {
			$this->render_error(
				__( 'Conexión no segura', 'nexo-mcp' ),
				__( 'La autorización exige HTTPS.', 'nexo-mcp' )
			);
		}

		$request = $this->collect_request();

		// El cliente y la URI de retorno se validan antes que nada: mientras no
		// sean confiables, cualquier error se muestra aquí y jamás se redirige.
		$client = $this->clients->resolve( $request['client_id'] );

		if ( is_wp_error( $client ) ) {
			$this->render_error(
				__( 'Cliente no reconocido', 'nexo-mcp' ),
				$client->get_error_message()
			);
		}

		if ( '' === $request['redirect_uri']
			|| ! $this->clients->is_registered_redirect_uri( $client, $request['redirect_uri'] )
		) {
			$this->render_error(
				__( 'URI de retorno no autorizada', 'nexo-mcp' ),
				__( 'La dirección de retorno no coincide con ninguna registrada por el cliente.', 'nexo-mcp' )
			);
		}

		// A partir de aquí la redirección es segura y los errores viajan al cliente.
		if ( 'code' !== $request['response_type'] ) {
			$this->redirect_error( $request, 'unsupported_response_type', 'Sólo se admite response_type=code.' );
		}

		if ( '' === $request['code_challenge'] || ! Pkce::is_supported_method( $request['code_challenge_method'] ) ) {
			$this->redirect_error( $request, 'invalid_request', 'Se exige PKCE con code_challenge_method=S256.' );
		}

		if ( '' !== $request['resource']
			&& ! DiscoveryController::accepts_resource( $request['resource'] )
		) {
			/*
			 * Nombrar las dos direcciones ahorra el diagnóstico entero: quien
			 * publica el sitio detrás de un túnel recibe aquí la dirección que su
			 * cliente cree verdadera, y le basta pegarla en el panel. Ninguna de
			 * las dos es secreta: la propia ya se anuncia en el documento de
			 * descubrimiento, y la otra la envió el cliente.
			 */
			$this->redirect_error(
				$request,
				'invalid_target',
				sprintf(
					'El recurso solicitado no corresponde a este servidor. Este conector se identifica como %1$s y la solicitud pidió %2$s. Si publicas el sitio detrás de un túnel o un proxy, declara esa dirección en el panel Nexo MCP.',
					DiscoveryController::resource_identifier(),
					$request['resource']
				)
			);
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::authorize_url( $request ) ) );
			exit;
		}

		$user_id = get_current_user_id();
		$scopes  = ScopeMap::filter_by_user( ScopeMap::parse( $request['scope'] ), $user_id );

		if ( ! in_array( ScopeMap::CONNECT, $scopes, true ) ) {
			$this->render_error(
				__( 'Sin acceso MCP', 'nexo-mcp' ),
				__( 'Tu usuario no tiene permiso para conectar clientes MCP a este sitio.', 'nexo-mcp' )
			);
		}

		if ( $this->is_consent_submission() ) {
			$this->handle_consent( $request, $client, $scopes, $user_id );
		}

		$this->render_consent( $request, $client, $scopes );
	}

	/**
	 * Procesa el envío del formulario de consentimiento.
	 *
	 * @param array<string, string> $request Parámetros normalizados.
	 * @param array<string, mixed>  $client  Cliente resuelto.
	 * @param array<int, string>    $scopes  Scopes concedibles.
	 * @param int                   $user_id Usuario que consiente.
	 */
	private function handle_consent( array $request, array $client, array $scopes, int $user_id ): void {
		$nonce = isset( $_POST['nexo_mcp_consent_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['nexo_mcp_consent_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->render_error(
				__( 'Solicitud expirada', 'nexo-mcp' ),
				__( 'Vuelve a iniciar la conexión desde tu cliente MCP.', 'nexo-mcp' )
			);
		}

		$decision = isset( $_POST['nexo_mcp_decision'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['nexo_mcp_decision'] ) )
			: 'deny';

		if ( 'allow' !== $decision ) {
			$this->audit_logger->log( 'oauth', 'read', 'denied', array( 'client_id' => $request['client_id'] ) );
			$this->redirect_error( $request, 'access_denied', 'El propietario rechazó la solicitud.' );
		}

		$identity = (string) $user_id . '|' . ( $this->ip_resolver->resolve() ?? 'unknown' );
		$limit    = $this->rate_limiter->consume( $identity, 'oauth_authorize', 20, MINUTE_IN_SECONDS );

		if ( is_wp_error( $limit ) || ! $limit->allowed ) {
			$this->redirect_error( $request, 'temporarily_unavailable', 'Demasiadas solicitudes de autorización.' );
		}

		$code = TokenRepository::generate_secret( 32 );

		$stored = $this->repository->store_code(
			$code,
			$request['client_id'],
			$user_id,
			$request['redirect_uri'],
			$scopes,
			'' !== $request['resource'] ? $request['resource'] : DiscoveryController::resource_identifier(),
			$request['code_challenge'],
			$request['code_challenge_method']
		);

		if ( ! $stored ) {
			$this->redirect_error( $request, 'server_error', 'No fue posible emitir el código de autorización.' );
		}

		$this->audit_logger->log(
			'oauth',
			'write',
			'success',
			array(
				'client_id' => $request['client_id'],
				'client'    => $client['client_name'] ?? '',
				'scope'     => ScopeMap::to_string( $scopes ),
			)
		);

		$parameters = array(
			'code' => $code,
			'iss'  => DiscoveryController::issuer(),
		);

		if ( '' !== $request['state'] ) {
			$parameters['state'] = $request['state'];
		}

		$this->redirect_to_client( $request['redirect_uri'], $parameters );
	}

	/**
	 * Normaliza los parámetros de entrada.
	 *
	 * @return array<string, string>
	 */
	private function collect_request(): array {
		// Los parámetros del flujo OAuth son públicos por definición y aquí sólo se
		// normalizan. La emisión del código, que es la acción con efecto, verifica
		// el nonce en handle_consent() antes de tocar nada.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$source = array_merge( $_GET, $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		$read = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] )
				? sanitize_text_field( wp_unslash( (string) $source[ $key ] ) )
				: '';
		};

		return array(
			'client_id'             => $read( 'client_id' ),
			'redirect_uri'          => $read( 'redirect_uri' ),
			'response_type'         => $read( 'response_type' ),
			'scope'                 => $read( 'scope' ),
			'state'                 => $read( 'state' ),
			'resource'              => $read( 'resource' ),
			'code_challenge'        => $read( 'code_challenge' ),
			'code_challenge_method' => '' !== $read( 'code_challenge_method' ) ? $read( 'code_challenge_method' ) : 'S256',
		);
	}

	/**
	 * Indica si la solicitud actual es el envío del consentimiento.
	 */
	private function is_consent_submission(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		return 'POST' === $method;
	}

	/**
	 * Redirige al cliente con un error del flujo.
	 *
	 * @param array<string, string> $request     Parámetros normalizados.
	 * @param string                $code        Código de error OAuth.
	 * @param string                $description Descripción segura.
	 */
	private function redirect_error( array $request, string $code, string $description ): void {
		$parameters = array(
			'error'             => $code,
			'error_description' => $description,
			'iss'               => DiscoveryController::issuer(),
		);

		if ( '' !== $request['state'] ) {
			$parameters['state'] = $request['state'];
		}

		$this->redirect_to_client( $request['redirect_uri'], $parameters );
	}

	/**
	 * Envía al usuario de vuelta al cliente.
	 *
	 * @param string                $redirect_uri Destino validado.
	 * @param array<string, string> $parameters   Parámetros de la respuesta.
	 */
	private function redirect_to_client( string $redirect_uri, array $parameters ): void {
		$separator = false === strpos( $redirect_uri, '?' ) ? '?' : '&';
		$location  = $redirect_uri . $separator . http_build_query( $parameters );

		if ( ! headers_sent() ) {
			status_header( 302 );
			header( 'Location: ' . $location, true, 302 );
		}

		exit;
	}

	/**
	 * Reconstruye la URL de autorización a partir de los parámetros normalizados.
	 *
	 * No se reutiliza `REQUEST_URI`: `sanitize_text_field()` elimina las
	 * secuencias porcentuales, de modo que una URI de retorno codificada
	 * perdería sus separadores al volver del formulario de acceso.
	 *
	 * @param array<string, string> $request Parámetros normalizados.
	 */
	public static function authorize_url( array $request ): string {
		$parameters = array_filter(
			$request,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);

		return home_url( '/' . self::PATH ) . '?' . http_build_query( $parameters );
	}

	/**
	 * Normaliza la ruta solicitada.
	 *
	 * El valor sólo se compara contra rutas conocidas, nunca se emite ni se
	 * usa para construir consultas, por lo que se conserva sin sanitizar el
	 * texto para no alterar su codificación.
	 */
	private function current_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sólo se compara contra rutas fijas; sanitize_text_field() destruiría el percent-encoding.
		$uri = wp_unslash( (string) $_SERVER['REQUEST_URI'] );

		if ( '' === $uri ) {
			return '';
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( rawurldecode( $path ), '/' );
	}

	/**
	 * Muestra la pantalla de consentimiento.
	 *
	 * @param array<string, string> $request Parámetros normalizados.
	 * @param array<string, mixed>  $client  Cliente resuelto.
	 * @param array<int, string>    $scopes  Scopes concedibles.
	 */
	private function render_consent( array $request, array $client, array $scopes ): void {
		$user = wp_get_current_user();

		$body = $this->consent_markup(
			$request,
			$client,
			$scopes,
			(string) $user->user_login,
			wp_nonce_field( self::NONCE_ACTION, 'nexo_mcp_consent_nonce', true, false )
		);

		$this->render_page( __( 'Autorizar conexión', 'nexo-mcp' ), $body );
	}

	/**
	 * Construye el contenido de la pantalla de consentimiento.
	 *
	 * @param array<string, string> $request     Parámetros normalizados.
	 * @param array<string, mixed>  $client      Cliente resuelto.
	 * @param array<int, string>    $scopes      Scopes concedibles.
	 * @param string                $user_login  Usuario que consiente.
	 * @param string                $nonce_field Campo del nonce ya generado.
	 */
	private function consent_markup( array $request, array $client, array $scopes, string $user_login, string $nonce_field ): string {
		$client_name = isset( $client['client_name'] ) && '' !== $client['client_name']
			? (string) $client['client_name']
			: (string) $request['client_id'];

		$items = '';
		$index = 0;

		foreach ( $scopes as $scope ) {
			++$index;

			$items .= sprintf(
				'<li style="--i:%1$d">
					<span class="idx">%2$s</span>
					<span class="body"><span class="key">%3$s</span><span class="label">%4$s</span></span>
					<span class="tick" aria-hidden="true"></span>
				</li>',
				$index,
				esc_html( str_pad( (string) $index, 2, '0', STR_PAD_LEFT ) ),
				esc_html( $scope ),
				esc_html( ScopeMap::label_for( $scope ) )
			);
		}

		$hidden = '';

		foreach ( $request as $key => $value ) {
			$hidden .= sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $key ),
				esc_attr( $value )
			);
		}

		return sprintf(
			'<main class="sheet">
				<aside class="rail">
					<div class="rail-top">
						<img class="logo" src="%18$s" alt="Nexo MCP" width="140" height="26" />
						<span class="sig-sub">%1$s</span>
					</div>
					<dl class="rail-meta">
						<div><dt>%2$s</dt><dd>%3$s</dd></div>
						<div><dt>%4$s</dt><dd>%5$s</dd></div>
					</dl>
					<div class="rail-foot">
						<span class="ver">v%6$s</span>
						<span class="proto">OAuth 2.1 · PKCE S256</span>
					</div>
				</aside>

				<section class="stage">
					<p class="eyebrow">%7$s</p>
					<h1 class="title">%8$s</h1>
					<p class="claim">%9$s</p>

					<p class="rule"><span>%10$s</span></p>
					<ol class="scopes">%11$s</ol>

					<form method="post" class="decide">
						%12$s
						%13$s
						<button type="submit" name="nexo_mcp_decision" value="deny" class="btn ghost">%14$s</button>
						<button type="submit" name="nexo_mcp_decision" value="allow" class="btn go">
							<span>%15$s</span>
						</button>
					</form>

					<p class="foot">
						<span>%16$s</span>
						<span class="by">%17$s</span>
					</p>
				</section>
			</main>',
			esc_html__( 'Nexo MCP', 'nexo-mcp' ),
			esc_html__( 'Sitio', 'nexo-mcp' ),
			esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			esc_html__( 'Sesión', 'nexo-mcp' ),
			esc_html( $user_login ),
			esc_html( NEXO_MCP_VERSION ),
			esc_html__( 'Solicitud de acceso', 'nexo-mcp' ),
			esc_html( $client_name ),
			esc_html__( 'quiere operar sobre este sitio en tu nombre.', 'nexo-mcp' ),
			esc_html__( 'Permisos solicitados', 'nexo-mcp' ),
			$items,
			$hidden,
			$nonce_field,
			esc_html__( 'Rechazar', 'nexo-mcp' ),
			esc_html__( 'Autorizar acceso', 'nexo-mcp' ),
			esc_html__( 'Revocable cuando quieras desde el panel Nexo MCP.', 'nexo-mcp' ),
			esc_html__( 'Nexo MCP · software libre GPL', 'nexo-mcp' ),
			esc_url( $this->logo_url() )
		);
	}

	/**
	 * Muestra un error terminal sin redirigir.
	 *
	 * @param string $title   Título seguro.
	 * @param string $message Mensaje seguro.
	 */
	private function render_error( string $title, string $message ): void {
		$body = sprintf(
			'<main class="sheet sheet--halt">
				<aside class="rail">
					<div class="rail-top">
						<img class="logo" src="%7$s" alt="Nexo MCP" width="140" height="26" />
						<span class="sig-sub">%1$s</span>
					</div>
					<div class="rail-foot">
						<span class="ver">v%2$s</span>
						<span class="proto">%3$s</span>
					</div>
				</aside>
				<section class="stage">
					<p class="eyebrow eyebrow--halt">%4$s</p>
					<h1 class="title">%5$s</h1>
					<p class="claim">%6$s</p>
				</section>
			</main>',
			esc_html__( 'Nexo MCP', 'nexo-mcp' ),
			esc_html( NEXO_MCP_VERSION ),
			esc_html__( 'Solicitud detenida', 'nexo-mcp' ),
			esc_html__( 'No se puede continuar', 'nexo-mcp' ),
			esc_html( $title ),
			esc_html( $message ),
			esc_url( $this->logo_url() )
		);

		$this->render_page( $title, $body, 400 );
	}

	/**
	 * Emite una página autónoma y termina la solicitud.
	 *
	 * @param string $title  Título del documento.
	 * @param string $body   Contenido ya escapado.
	 * @param int    $status Código HTTP.
	 */
	private function render_page( string $title, string $body, int $status = 200 ): void {
		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-Frame-Options: DENY' );
			header( 'Referrer-Policy: no-referrer' );
		}

		printf(
			'<!DOCTYPE html><html lang="%1$s"><head><meta charset="utf-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta name="robots" content="noindex, nofollow" />
			<title>%2$s</title><style>%3$s</style></head><body>%4$s</body></html>',
			esc_attr( str_replace( '_', '-', get_locale() ) ),
			esc_html( $title ),
			$this->styles(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS estático propio.
			$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Contenido escapado en su construcción.
		);

		exit;
	}

	/**
	 * Dirección del logo Nexo en blanco, servido desde el propio plugin.
	 */
	private function logo_url(): string {
		return plugins_url( 'assets/images/logo-nexo.svg', NEXO_MCP_FILE );
	}

	/**
	 * Entrega la hoja de estilos de la pantalla.
	 *
	 * Usa la identidad Nexo «Mapa claro» del panel (admin.css): banda slate que
	 * conserva legible el logo de texto blanco, hoja clara de bordes finos y
	 * radios chicos, botón primario teal y foco índigo. Sólo fuentes del
	 * sistema: la pantalla vive fuera del administrador y no depende de ningún
	 * recurso externo.
	 */
	private function styles(): string {
		return ":root{
			/* Paleta base: cada literal vive aquí una sola vez. */
			--slate-200:#e2e8f0; --slate-300:#cbd5e1; --slate-muted:#a5b4c8; --slate-700:#334155;
			--bg:#e9eef4; --panel:#f8fafc; --fg:#0f172a; --fg-soft:#475569; --muted:var(--fg-soft);
			--border:#c7d2de; --input:#64748b; --row:#eef2f7; --rail:#0f172a;
			--rail-fg:var(--slate-200); --rail-muted:var(--slate-muted); --rail-line:var(--slate-700);
			--primary:#0f766e; --primary-hover:#115e59; --accent:#22d3ee; --focus:#4f46e5;
			--accent-ink:var(--primary);
			--err:#b91c1c;
			--sans:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
			--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
		}
		@media (prefers-color-scheme:dark){
			:root{
				--bg:#0b1220; --panel:#111c2e; --fg:var(--slate-200); --fg-soft:var(--slate-300); --muted:var(--slate-muted);
				--border:var(--slate-700); --row:#16233a;
				--focus:#22d3ee; --accent-ink:#5eead4;
				--err:#f87171;
			}
		}

		*{margin:0;padding:0;box-sizing:border-box}
		html{-webkit-text-size-adjust:100%}
		body{
			font-family:var(--sans); font-size:14px;
			background:var(--bg); color:var(--fg);
			min-height:100vh; display:flex; align-items:center; justify-content:center;
			padding:clamp(0px,4vw,40px); line-height:1.5;
		}
		:focus-visible{outline:3px solid var(--focus);outline-offset:2px}

		.sheet{
			width:min(860px,100%); display:grid; grid-template-columns:220px 1fr;
			background:var(--panel); border:1px solid var(--border); border-radius:8px;
			overflow:hidden;
		}

		/* ---------- Banda de identidad ---------- */
		.rail{
			position:relative; background:var(--rail); color:var(--rail-fg);
			border-bottom:3px solid var(--accent);
			padding:28px 24px 32px; display:flex; flex-direction:column; gap:24px;
		}
		.rail-top{display:flex;flex-direction:column;gap:10px}
		.logo{display:block;width:auto;height:26px;max-width:100%}
		.sig-sub{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--rail-muted)}
		.rail-meta{margin-top:auto;display:grid;gap:16px}
		.rail-meta div{display:flex;flex-direction:column;gap:2px}
		.rail-meta dt{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--rail-muted)}
		.rail-meta dd{font-size:14px;font-weight:600;word-break:break-word}
		.rail-foot{
			display:flex; flex-direction:column; gap:2px;
			padding-top:16px; border-top:1px solid var(--rail-line);
		}
		.ver{font-size:13px;font-weight:600}
		.proto{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--rail-muted)}

		/* ---------- Zona de decisión ---------- */
		.stage{padding:32px;display:flex;flex-direction:column}
		.eyebrow{
			font-size:12px; letter-spacing:.08em; text-transform:uppercase;
			color:var(--accent-ink); font-weight:700; margin-bottom:8px;
		}
		.eyebrow--halt{color:var(--err)}
		.title{
			font-size:clamp(22px,3.6vw,28px); font-weight:700; letter-spacing:-.2px;
			line-height:1.15; color:var(--fg); word-break:break-word;
		}
		.claim{font-size:14px;color:var(--fg-soft);margin-top:6px;max-width:46ch}

		.rule{
			margin:24px 0 8px; font-size:12px; letter-spacing:.08em; text-transform:uppercase;
			color:var(--fg-soft); font-weight:700;
		}

		.scopes{list-style:none;display:flex;flex-direction:column;border:1px solid var(--border);border-radius:6px;overflow:hidden}
		.scopes li{
			display:grid; grid-template-columns:22px 1fr 14px; align-items:center; gap:10px;
			padding:7px 14px; border-bottom:1px solid var(--border);
		}
		.scopes li:nth-child(even){background:var(--row)}
		.scopes li:last-child{border-bottom:0}
		.idx{font-size:12px;color:var(--muted);font-variant-numeric:tabular-nums}
		.body{display:flex;align-items:baseline;justify-content:space-between;gap:12px;min-width:0}
		/* El nombre técnico va a la derecha: una línea por permiso. */
		.key{order:1;flex:none;font-family:var(--mono);font-size:12px;color:var(--fg-soft)}
		.label{font-size:13px;color:var(--fg)}
		/* Visto dibujado con bordes: sin imágenes ni íconos externos. */
		.tick{
			width:6px; height:11px; justify-self:center; margin-top:-3px;
			border:solid var(--accent-ink); border-width:0 2px 2px 0; transform:rotate(45deg);
		}

		/* ---------- Acciones ---------- */
		.decide{display:flex;gap:10px;margin-top:24px}
		.btn{
			font-family:inherit; font-size:14px; font-weight:700; cursor:pointer;
			border:2px solid var(--input); border-radius:4px; padding:10px 18px;
			background:var(--panel); color:var(--fg);
			transition:background .15s ease,border-color .15s ease;
		}
		.btn:hover{border-color:var(--fg)}
		.ghost{flex:0 0 auto}
		.go{flex:1;border-color:var(--primary);background:var(--primary);color:#fff}
		.go:hover{border-color:var(--primary-hover);background:var(--primary-hover)}

		.foot{
			margin-top:20px; padding-top:14px; border-top:1px solid var(--border);
			font-size:12px; color:var(--fg-soft);
			display:flex; align-items:baseline; justify-content:space-between; gap:16px; flex-wrap:wrap;
		}
		.by{color:var(--fg-soft);white-space:nowrap}

		.sheet--halt .stage{justify-content:center;min-height:220px}

		/* Pantallas de poca altura: comprime para evitar desplazamiento vertical. */
		@media (min-width:721px) and (max-height:760px){
			.rail{padding:22px 22px 26px;gap:16px}
			.stage{padding:24px 28px 22px}
			.scopes li{padding:5px 14px}
			.rule{margin:16px 0 6px}
			.decide{margin-top:18px}
			.btn{padding:9px 16px}
			.foot{margin-top:14px;padding-top:10px}
		}

		@media (max-width:720px){
			body{align-items:stretch;padding:0}
			.sheet{grid-template-columns:1fr;border:0;border-radius:0}
			.rail{padding:24px 16px 28px;gap:18px;flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap}
			.rail-meta{margin-top:0;grid-auto-flow:column;gap:20px}
			.rail-foot{width:100%;padding-top:14px}
			.stage{padding:28px 16px}
			.body{flex-direction:column;gap:0}
			.key{order:0}
			.decide{flex-direction:column-reverse}
			.ghost{width:100%}
		}
		@media (prefers-reduced-motion:reduce){
			*{animation:none!important;transition:none!important}
		}";
	}
}
