<?php
/**
 * Orquestador principal del plugin.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP;

use NexoDev\NexoMCP\Admin\AdminPage;
use NexoDev\NexoMCP\Admin\CredentialController;
use NexoDev\NexoMCP\Admin\LicenseController;
use NexoDev\NexoMCP\Admin\OAuthConnectionsController;
use NexoDev\NexoMCP\Admin\RegistrationController;
use NexoDev\NexoMCP\Admin\ReportController;
use NexoDev\NexoMCP\Admin\WorkingCopyController;
use NexoDev\NexoMCP\Audit\AuditLogger;
use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authentication\ApplicationPasswordManager;
use NexoDev\NexoMCP\Authentication\AuthenticationContext;
use NexoDev\NexoMCP\Builders\DiviLoopGuard;
use NexoDev\NexoMCP\Installation\Installer;
use NexoDev\NexoMCP\Licensing\LicenseClient;
use NexoDev\NexoMCP\Media\UploadController;
use NexoDev\NexoMCP\MCP\AbilityRegistrar;
use NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar;
use NexoDev\NexoMCP\MCP\ServerRegistrar;
use NexoDev\NexoMCP\MCP\ToolExecutor;
use NexoDev\NexoMCP\MCP\TransportGuard;
use NexoDev\NexoMCP\Authorization\PermissionPolicy;
use NexoDev\NexoMCP\OAuth\AuthorizationController;
use NexoDev\NexoMCP\OAuth\BearerTokenGuard;
use NexoDev\NexoMCP\OAuth\ClientRegistry;
use NexoDev\NexoMCP\OAuth\DiscoveryController;
use NexoDev\NexoMCP\OAuth\TokenController;
use NexoDev\NexoMCP\OAuth\TokenRepository;
use NexoDev\NexoMCP\Security\HttpsPolicy;
use NexoDev\NexoMCP\Security\RateLimiter;
use NexoDev\NexoMCP\Registration\RegistrationClient;
use NexoDev\NexoMCP\Registration\RegistrationState;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\Updates\UpdateChecker;
use NexoDev\NexoMCP\Updates\UpdateState;
use NexoDev\NexoMCP\WordPress\ReadGateway;
use NexoDev\NexoMCP\WordPress\WriteGateway;
use NexoDev\NexoMCP\WordPress\AppearanceGateway;
use NexoDev\NexoMCP\WordPress\ContentAdminGateway;
use NexoDev\NexoMCP\WordPress\MaintenanceGateway;
use NexoDev\NexoMCP\WordPress\SeoGateway;
use NexoDev\NexoMCP\WordPress\WorkingCopy;
use NexoDev\NexoMCP\WooCommerce\ProductGateway;

/**
 * Coordina el ciclo de ejecución del plugin.
 */
final class Plugin {

	/**
	 * Instancia compartida.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Evita registrar hooks más de una vez.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Configuración persistente.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construye las dependencias compartidas.
	 */
	private function __construct() {
		$this->settings = new Settings();
	}

	/**
	 * Obtiene la instancia principal.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registra los módulos disponibles en esta etapa.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		( new Installer() )->maybe_upgrade();
		( new AuthenticationContext() )->register();

		$audit_repository  = new AuditRepository();
		$audit_logger      = new AuditLogger( $audit_repository );
		$permission_policy = new PermissionPolicy( $this->settings );

		$read_gateway = new ReadGateway();

		$tool_executor = new ToolExecutor( $audit_logger );

		( new AbilityRegistrar(
			$this->settings,
			$permission_policy,
			$read_gateway,
			$tool_executor,
			new WriteGateway( $this->settings, $read_gateway )
		) )->register();

		$seo_gateway = new SeoGateway();
		$seo_gateway->register_frontend_hooks();

		// Bucles de consulta de Divi 5 escritos por el conector: sólo publicado y sin contraseña (S7).
		( new DiviLoopGuard() )->register();

		$content_gateway = new ContentAdminGateway( $this->settings );

		( new ExtendedAbilityRegistrar(
			$this->settings,
			$permission_policy,
			$tool_executor,
			$content_gateway,
			$seo_gateway,
			new AppearanceGateway(),
			new MaintenanceGateway(),
			new ProductGateway( $this->settings )
		) )->register();

		$this->boot_oauth( $audit_logger );

		$transport_guard = new TransportGuard(
			$this->settings,
			new HttpsPolicy(),
			$permission_policy,
			new RateLimiter(),
			$audit_logger
		);

		( new ServerRegistrar( $transport_guard ) )->register();

		// La carga binaria vive fuera de MCP porque JSON-RPC no tiene canal
		// para un archivo, pero comparte con él el mismo guardia de transporte
		// y la misma política de permisos.
		( new UploadController(
			$transport_guard,
			$permission_policy,
			$content_gateway,
			$audit_logger
		) )->register();

		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Se distribuye por .zip, no por wordpress.org: WordPress no cargaría las traducciones solo.
		load_plugin_textdomain(
			'nexo-mcp',
			false,
			dirname( plugin_basename( NEXO_MCP_FILE ) ) . '/languages'
		);

		// Se engancha siempre, no sólo en el panel: la revalidación diaria corre
		// por wp-cron, donde `is_admin()` es falso, y dentro del bloque de abajo
		// el evento quedaría registrado pero nunca se atendería.
		$license_state = new RegistrationState();
		$update_state  = new UpdateState();

		( new LicenseController( $license_state, new LicenseClient(), $update_state ) )->register();

		// Igual que el anterior: las actualizaciones automáticas de WordPress
		// corren por cron, sin panel a la vista.
		( new UpdateChecker( $update_state, $license_state ) )->register();

		if ( is_admin() ) {
			$credential_manager = new ApplicationPasswordManager();
			$registration_state = new RegistrationState();
			$token_repository   = new TokenRepository();

			( new CredentialController( $credential_manager, $audit_logger ) )->register();
			( new RegistrationController( $registration_state, new RegistrationClient() ) )->register();
			( new OAuthConnectionsController( $token_repository, $audit_logger ) )->register();
			( new AdminPage(
				$this->settings,
				$credential_manager,
				$audit_repository,
				$registration_state,
				$token_repository
			) )->register();
			( new ReportController( $registration_state, new RegistrationClient() ) )->register();

			// Publicar o descartar desde la lista de páginas y entradas lo que
			// la IA dejó en una copia de trabajo (ADR-031).
			( new WorkingCopyController( new WorkingCopy( $this->settings ), $audit_logger ) )->register();
		}

		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 20 );
		}
	}

	/**
	 * Registra el servidor de autorización OAuth 2.1 y el guardia de tokens.
	 *
	 * @param AuditLogger $audit_logger Auditoría compartida.
	 */
	private function boot_oauth( AuditLogger $audit_logger ): void {
		$repository   = new TokenRepository();
		$clients      = new ClientRegistry( $repository );
		$https_policy = new HttpsPolicy();
		$rate_limiter = new RateLimiter();

		( new BearerTokenGuard( $repository ) )->register();
		( new DiscoveryController() )->register();

		( new AuthorizationController(
			$clients,
			$repository,
			$https_policy,
			$rate_limiter,
			$audit_logger
		) )->register();

		( new TokenController(
			$clients,
			$repository,
			$https_policy,
			$rate_limiter,
			$audit_logger
		) )->register();

		if ( ! wp_next_scheduled( 'nexo_mcp_oauth_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nexo_mcp_oauth_purge' );
		}

		add_action(
			'nexo_mcp_oauth_purge',
			static function () use ( $repository ): void {
				$repository->purge_expired();

				// El registro dinámico sortea un client_id nuevo en cada alta, así
				// que sin esta poda la tabla de clientes sólo crece.
				$repository->purge_orphan_clients();
			}
		);
	}

	/**
	 * Instala la configuración segura en sitios creados después de una activación de red.
	 *
	 * @param \WP_Site $site Sitio recién creado.
	 */
	public function initialize_site( \WP_Site $site ): void {
		$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
		$plugin_basename = plugin_basename( NEXO_MCP_FILE );

		if ( ! is_array( $network_plugins ) || ! isset( $network_plugins[ $plugin_basename ] ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		( new Installer() )->install();
		restore_current_blog();
	}
}
