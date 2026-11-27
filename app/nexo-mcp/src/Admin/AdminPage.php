<?php
/**
 * Pantalla administrativa principal.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Admin;

use NexoDev\NexoMCP\Audit\AuditRepository;
use NexoDev\NexoMCP\Authentication\ApplicationPasswordManager;
use NexoDev\NexoMCP\Builders\BuilderRegistry;
use NexoDev\NexoMCP\Licensing\ApiEndpoint;
use NexoDev\NexoMCP\OAuth\TokenRepository;
use NexoDev\NexoMCP\Registration\RegistrationState;
use NexoDev\NexoMCP\Support\Settings;
use NexoDev\NexoMCP\Updates\UpdateState;

/**
 * Registra y muestra la pantalla de administración Nexo MCP.
 */
final class AdminPage {

	public const PAGE_SLUG = 'nexo-mcp';

	/**
	 * Configuración persistente.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Controlador de Settings API.
	 *
	 * @var SettingsController
	 */
	private SettingsController $settings_controller;

	/**
	 * API oficial de credenciales.
	 *
	 * @var ApplicationPasswordManager
	 */
	private ApplicationPasswordManager $credential_manager;

	/**
	 * Acceso al historial reciente.
	 *
	 * @var AuditRepository
	 */
	private AuditRepository $audit_repository;

	/**
	 * Estado local del registro obligatorio.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $registration_state;

	/**
	 * Almacenamiento de tokens OAuth.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $token_repository;

	/**
	 * Construye la pantalla administrativa.
	 *
	 * @param Settings                   $settings           Configuración persistente.
	 * @param ApplicationPasswordManager $credential_manager Gestor de credenciales.
	 * @param AuditRepository            $audit_repository   Historial persistente.
	 * @param RegistrationState          $registration_state Estado del registro.
	 * @param TokenRepository            $token_repository   Conexiones OAuth vigentes.
	 */
	public function __construct(
		Settings $settings,
		ApplicationPasswordManager $credential_manager,
		AuditRepository $audit_repository,
		RegistrationState $registration_state,
		TokenRepository $token_repository
	) {
		$this->settings            = $settings;
		$this->settings_controller = new SettingsController();
		$this->credential_manager  = $credential_manager;
		$this->audit_repository    = $audit_repository;
		$this->registration_state  = $registration_state;
		$this->token_repository    = $token_repository;
	}

	/**
	 * Registra hooks administrativos.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings_controller, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'hide_foreign_notices' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( NEXO_MCP_FILE ),
			array( $this, 'add_plugin_action_link' )
		);
	}

	/**
	 * Agrega el menú principal Nexo MCP.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Nexo MCP', 'nexo-mcp' ),
			__( 'Nexo MCP', 'nexo-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-share',
			58
		);
	}

	/**
	 * Oculta los avisos de otros plugins en la pantalla del conector.
	 *
	 * WordPress imprime en cualquier página todo lo que cuelga de los hooks de
	 * avisos, y eso metía banners ajenos (FiboSearch, WooCommerce…) dentro de
	 * este panel. El conector no usa esos hooks —sus avisos van en la propia
	 * vista—, así que aquí se vacían. En el resto de wp-admin siguen igual.
	 */
	public function hide_foreign_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'toplevel_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Carga assets únicamente en la pantalla del plugin.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'nexo-mcp-admin',
			plugins_url( 'assets/css/admin.css', NEXO_MCP_FILE ),
			array(),
			NEXO_MCP_VERSION
		);

		wp_enqueue_script(
			'nexo-mcp-admin',
			plugins_url( 'assets/js/admin.js', NEXO_MCP_FILE ),
			array(),
			NEXO_MCP_VERSION,
			true
		);

		wp_localize_script(
			'nexo-mcp-admin',
			'nexoMcpAdmin',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( CredentialController::NONCE_ACTION ),
				'registrationNonce'     => wp_create_nonce( RegistrationController::NONCE_ACTION ),
				'licenseNonce'          => wp_create_nonce( LicenseController::NONCE_ACTION ),
				'oauthNonce'            => wp_create_nonce( OAuthConnectionsController::NONCE_ACTION ),
				'confirmRelease'        => __( '¿Desconectar este sitio? Su cupo queda libre para usarlo en otro dominio y este WordPress dejará de operar hasta que vuelvas a activarlo.', 'nexo-mcp' ),
				'confirmRevokeOauth'    => __( '¿Revocar esta conexión? El cliente perderá el acceso de inmediato y tendrá que volver a autorizarse.', 'nexo-mcp' ),
				'commandCopySuccess'    => __( 'Comando copiado.', 'nexo-mcp' ),
				'endpointCopySuccess'   => __( 'Dirección copiada.', 'nexo-mcp' ),
				'codexUrlCopySuccess'   => __( 'URL copiada.', 'nexo-mcp' ),
				'oauthTestRunning'      => __( 'Comprobando desafío y metadatos OAuth…', 'nexo-mcp' ),
				'oauthTestSuccess'      => __( 'WordPress está listo para autorizar clientes mediante OAuth.', 'nexo-mcp' ),
				'oauthTestError'        => __( 'WordPress no completó la prueba OAuth.', 'nexo-mcp' ),
				'discoveryReady'        => __( 'Disponible', 'nexo-mcp' ),
				'discoveryReadyHint'    => __( 'Tu IA puede averiguar cómo autorizarse en este sitio.', 'nexo-mcp' ),
				'discoveryBlocked'      => __( 'Bloqueado', 'nexo-mcp' ),
				'discoveryBlockedHint'  => __( 'Tu servidor atiende /.well-known/ por su cuenta y no deja llegar la petición a WordPress.', 'nexo-mcp' ),
				'discoveryUnknown'      => __( 'Sin comprobar', 'nexo-mcp' ),
				'discoveryUnknownHint'  => __( 'No hubo respuesta al comprobar las direcciones de descubrimiento.', 'nexo-mcp' ),
				'mapDiscoveryReady'     => __( 'Las dos direcciones responden', 'nexo-mcp' ),
				'mapDiscoveryBlocked'   => __( 'Descubrimiento bloqueado', 'nexo-mcp' ),
				'mapDiscoveryUnknown'   => __( 'Descubrimiento sin comprobar', 'nexo-mcp' ),
				'discoveryRechecking'   => __( 'Comprobando de nuevo…', 'nexo-mcp' ),
				'discoveryRepaired'     => __( 'Listo: el descubrimiento ya llega a WordPress. Puedes conectar tu IA.', 'nexo-mcp' ),
				'discoveryStillBlocked' => __( 'Sigue bloqueado. El cambio en el servidor todavía no está aplicado.', 'nexo-mcp' ),
				'discoveryTicketCopied' => __( 'Texto copiado. Envíalo al soporte de tu hosting.', 'nexo-mcp' ),
				'confirmRevoke'         => __( '¿Revocar esta credencial? El cliente conectado perderá acceso inmediatamente.', 'nexo-mcp' ),
				'confirmRotate'         => __( '¿Rotar esta credencial? La anterior dejará de funcionar inmediatamente.', 'nexo-mcp' ),
				'genericError'          => __( 'No fue posible completar la operación.', 'nexo-mcp' ),
				'showSecret'            => __( 'Mostrar contraseña', 'nexo-mcp' ),
				'hideSecret'            => __( 'Ocultar contraseña', 'nexo-mcp' ),
				'copySuccess'           => __( 'Contraseña copiada.', 'nexo-mcp' ),
				'copyError'             => __( 'No fue posible copiarla. Intenta nuevamente.', 'nexo-mcp' ),
				'promptCopySuccess'     => __( 'Instrucción copiada. Pégala ahora en tu IA.', 'nexo-mcp' ),
				'promptCopyError'       => __( 'No fue posible copiar la instrucción.', 'nexo-mcp' ),
				'confirmDisconnect'     => __( '¿Desconectar esta instalación? El registro remoto quedará revocado.', 'nexo-mcp' ),
				'updates'               => array(
					'searching'       => __( 'Buscando…', 'nexo-mcp' ),
					'search'          => __( 'Buscar actualizaciones', 'nexo-mcp' ),
					'titleAvailable'  => __( 'Hay una versión nueva', 'nexo-mcp' ),
					'titleCurrent'    => __( 'Estás al día', 'nexo-mcp' ),
					'statusAvailable' => __( 'Actualizable', 'nexo-mcp' ),
					'statusCurrent'   => __( 'Al día', 'nexo-mcp' ),
					/* translators: %s: fecha de publicación. */
					'published'       => __( 'Publicada el %s', 'nexo-mcp' ),
					/* translators: %s: fecha UTC de la última comprobación. */
					'checked'         => __( 'Comprobado el %s UTC', 'nexo-mcp' ),
					'changelogTitle'  => __( 'Qué trae', 'nexo-mcp' ),
					'goUpdate'        => __( 'Ir a actualizar', 'nexo-mcp' ),
					'availableHint'   => __( 'Se instala desde la pantalla de Plugins, como cualquier otro. Si tienes activadas las actualizaciones automáticas de WordPress, se instalará sola.', 'nexo-mcp' ),
					'currentHint'     => __( 'Las versiones nuevas llegan solas mientras tu licencia esté activa. Se comprueba una vez al día, y con este botón al momento.', 'nexo-mcp' ),
					'noUpdates'       => __( 'No hay versiones nuevas. Tienes la última.', 'nexo-mcp' ),
				),
			)
		);
	}

	/**
	 * Agrega el acceso directo a la configuración.
	 *
	 * @param array<string, string> $links Enlaces actuales.
	 * @return array<string, string>
	 */
	public function add_plugin_action_link( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Configurar', 'nexo-mcp' )
			)
		);

		return $links;
	}

	/**
	 * Renderiza el dashboard administrativo.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta pantalla.', 'nexo-mcp' ) );
		}

		// Sin licencia activa el panel no se muestra: el conector no opera hasta
		// que el sitio queda habilitado.
		if ( ! $this->registration_state->meets_requirement() ) {
			$grace_expired   = $this->registration_state->is_verified()
				&& $this->registration_state->grace_expired();
			$custom_endpoint = ApiEndpoint::is_custom();

			require NEXO_MCP_PATH . 'src/Admin/views/activacion.php';

			return;
		}

		$api_configured  = ApiEndpoint::is_configured();
		$settings        = $this->settings->all();
		$endpoint        = rest_url( 'nexo-mcp/v1/mcp' );
		$is_local_http   = ! wp_is_using_https() && \NexoDev\NexoMCP\Security\LocalEnvironment::bypass_allowed();
		$is_https        = wp_is_using_https() || $is_local_http;
		$is_server_ready = ! empty( $settings['server_enabled'] )
			&& ! empty( $settings['wordpress_tools_enabled'] )
			&& $is_https;
		$is_woocommerce  = class_exists( 'WooCommerce' );
		$adapter_version = defined( 'WP_MCP_ADAPTER_VERSION' )
			? (string) WP_MCP_ADAPTER_VERSION
			: __( 'Incluido por Composer', 'nexo-mcp' );
		$eligible_users  = $this->credential_manager->get_eligible_users();
		$credential_rows = array();

		foreach ( $eligible_users as $eligible_user ) {
			foreach ( $this->credential_manager->list_for_user( (int) $eligible_user->ID ) as $credential ) {
				$credential_rows[] = array(
					'user'       => $eligible_user,
					'credential' => $credential,
				);
			}
		}

		$audit_entries     = $this->audit_repository->recent( 20 );
		$registration      = $this->registration_state->all();
		$oauth_connections = $this->token_repository->list_active_connections();

		// Estado de la licencia, tal como lo informó la plataforma en la última
		// respuesta. Se muestra, no se usa para decidir: quien autoriza es
		// siempre el servidor.
		$license_offline     = $this->registration_state->is_offline();
		$license_grace_days  = $this->registration_state->grace_days_left();
		$license_plan_label  = '' !== $registration['plan']
			? self::plan_label( $registration['plan'] )
			: __( 'Licencia activa', 'nexo-mcp' );
		$license_sites_label = '';

		// Estado de las versiones, tal como lo informó la plataforma. Se muestra
		// aquí para que no haya que ir a la pantalla de Plugins a saber si el
		// sitio está al día.
		$update_state     = new UpdateState();
		$update           = $update_state->all();
		$update_available = $update_state->has_update();
		$update_changelog = array_values(
			array_filter( array_map( 'trim', explode( "\n", $update['changelog'] ) ) )
		);
		$updates_url      = admin_url( 'plugins.php' );

		if ( '' !== $registration['site_limit'] ) {
			$license_sites_label = sprintf(
				/* translators: 1: sitios en uso, 2: sitios permitidos. */
				__( '%1$d de %2$d sitios en uso', 'nexo-mcp' ),
				(int) $registration['sites_used'],
				(int) $registration['site_limit']
			);
		}

		$oauth_site_url    = home_url();
		$report_notice     = ReportController::take_notice();
		$report_builders   = BuilderRegistry::active_slugs();
		$report_max_length = ReportController::max_message_length();
		$report_min_length = ReportController::min_message_length();

		require NEXO_MCP_PATH . 'src/Admin/views/dashboard.php';
	}

	/**
	 * Nombre legible del plan.
	 *
	 * Es sólo para mostrar. Los planes los define la plataforma, así que uno
	 * que no esté en esta lista se enseña tal cual en vez de ocultarse: un
	 * plan nuevo no puede quedar invisible por no haber actualizado el plugin.
	 *
	 * @param string $plan Código del plan.
	 */
	private static function plan_label( string $plan ): string {
		$conocidos = array(
			'private_beta' => __( 'Beta privada', 'nexo-mcp' ),
			'founding'     => __( 'Founding Edition', 'nexo-mcp' ),
			'starter'      => __( 'Starter', 'nexo-mcp' ),
			'pro'          => __( 'Pro', 'nexo-mcp' ),
			'agency'       => __( 'Agency', 'nexo-mcp' ),
			'enterprise'   => __( 'Enterprise', 'nexo-mcp' ),
		);

		return $conocidos[ $plan ] ?? ucfirst( str_replace( '_', ' ', $plan ) );
	}
}
