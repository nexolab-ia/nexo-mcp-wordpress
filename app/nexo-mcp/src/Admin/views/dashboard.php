<?php
/**
 * Vista principal del panel Nexo MCP.
 *
 * @var array<string, bool|int> $settings
 * @var string                  $endpoint
 * @var bool                    $is_https
 * @var bool                    $is_local_http
 * @var bool                    $is_server_ready
 * @var bool                    $is_woocommerce
 * @var string                  $adapter_version
 * @var list<object>            $audit_entries
 * @var array<string, string>   $registration
 * @var list<array>             $oauth_connections
 * @var string                  $oauth_site_url
 *
 * @package NexoDev\NexoMCP
 */

defined( 'ABSPATH' ) || exit;

$connection_profile = ! empty( $settings['read_only'] ) ? 'read' : 'manage';
$read_scopes        = \NexoDev\NexoMCP\OAuth\ScopeMap::to_string(
	\NexoDev\NexoMCP\OAuth\ScopeMap::for_profile( 'read' )
);
$manage_scopes      = \NexoDev\NexoMCP\OAuth\ScopeMap::to_string(
	\NexoDev\NexoMCP\OAuth\ScopeMap::for_profile( 'manage' )
);
$initial_scopes     = 'read' === $connection_profile ? $read_scopes : $manage_scopes;
$oauth_resource_url = \NexoDev\NexoMCP\OAuth\DiscoveryController::protected_resource_url();
$oauth_server_url   = \NexoDev\NexoMCP\OAuth\DiscoveryController::authorization_server_url();
$environment_type   = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
$is_production      = 'production' === $environment_type;
$images_url         = plugins_url( 'assets/images/', NEXO_MCP_FILE );
$site_host          = (string) wp_parse_url( $oauth_site_url, PHP_URL_HOST );

/*
 * Opciones sensibles: las que amplían lo que la IA conectada puede hacer, y
 * que rigen de inmediato para todas las conexiones (ADR-027). Se destacan y,
 * al moverlas hacia el lado riesgoso, el panel pide escribir una palabra. Es
 * una protección contra el clic distraído, no un control de acceso: guardar
 * sigue exigiendo nonce y capacidad en el servidor.
 *
 * `risk` dice en qué estado es riesgosa: `on` al encenderla, `off` al apagarla.
 */
$sensitive_options = array(
	'read_only'                        => array(
		'risk'          => 'off',
		'confirm-word'  => __( 'lectura', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres apagar el modo sólo lectura?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá crear y modificar contenido de este sitio desde ahora, sin volver a pedirte permiso.', 'nexo-mcp' ),
	),
	'drafts_only'                      => array(
		'risk'          => 'off',
		'confirm-word'  => __( 'borradores', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres dejar de limitar la IA a borradores?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá modificar páginas y entradas ya publicadas desde ahora, y tus visitantes verán esos cambios de inmediato.', 'nexo-mcp' ),
	),
	'publishing_enabled'               => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'publicar', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar la publicación remota?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá publicar, despublicar y enviar contenido a la papelera desde ahora, sin volver a pedirte permiso.', 'nexo-mcp' ),
	),
	'maintenance_tools_enabled'        => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'plugins', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar el mantenimiento de plugins?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá instalar, actualizar, activar y desactivar plugins y temas desde ahora. Un plugin defectuoso puede dejar el sitio fuera de servicio.', 'nexo-mcp' ),
	),
	'design_system_tools_enabled'      => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'diseño', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar los cambios al sistema de diseño?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá cambiar las clases, variables, colores y tipografía globales de Elementor de todo el sitio, y tus visitantes verán esos cambios de inmediato. Cada cambio guarda un respaldo y se puede deshacer el último.', 'nexo-mcp' ),
	),
	'builders_dynamic_enabled'         => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'dinámicos', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres permitir datos dinámicos en las páginas?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá asociar a textos e imágenes de las páginas datos del sitio (título, fecha, imagen destacada…) que se muestran a todos los visitantes. Sólo una lista cerrada de tags sin datos sensibles; el cambio va a la copia de trabajo.', 'nexo-mcp' ),
	),
	'builders_components_enabled'      => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'componentes', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres permitir crear componentes de Elementor?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá crear componentes reutilizables de Elementor 4 (siempre en borrador). Un componente es parte del sitio, no de una sola página: al publicarlo y usarlo en varias páginas, un cambio se ve en todas.', 'nexo-mcp' ),
	),
	'divi_design_tools_enabled'        => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'divi', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar los cambios al diseño global de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá cambiar la paleta de colores, las variables, las fuentes y la tipografía globales de Divi 5 de todo el sitio, y tus visitantes verán esos cambios de inmediato. Cada cambio guarda un respaldo y se puede deshacer.', 'nexo-mcp' ),
	),
	'divi_presets_tools_enabled'       => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'presets', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar los cambios a los presets de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá crear, cambiar y borrar los presets de módulos y de grupos de opciones de Divi 5, y fijar el predeterminado de cada módulo. Un preset afecta a todos los módulos que lo usan, y el predeterminado a todos los que no tienen otro: tus visitantes verán esos cambios de inmediato. Cada cambio guarda un respaldo y se puede deshacer.', 'nexo-mcp' ),
	),
	'divi_dynamic_tools_enabled'       => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'dinámico', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar el contenido dinámico de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá poner en las páginas de Divi 5 contenido dinámico (título, extracto, fecha y autor de las entradas, datos del sitio, WooCommerce básico y campos de texto de ACF) y bucles de consulta que listan contenido del sitio. Cualquier visitante verá esos datos publicados en la página, y un bucle puede mostrar muchas entradas. Los cambios se escriben en la copia de trabajo de la página, que revisas antes de publicar.', 'nexo-mcp' ),
	),
	'divi_conditions_tools_enabled'    => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'condiciones', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar las condiciones de visualización de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá poner condiciones de visualización en los módulos de las páginas de Divi 5 (por ejemplo, mostrar u ocultar un bloque según el rol, el inicio de sesión, la fecha o el dispositivo del visitante). Una condición mal puesta puede ocultar contenido a todos tus visitantes o mostrar a todos lo que querías reservar. Los cambios se escriben en la copia de trabajo de la página, que revisas antes de publicar.', 'nexo-mcp' ),
	),
	'divi_theme_builder_tools_enabled' => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'themebuilder', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar el Theme Builder de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá crear y cambiar las plantillas del Theme Builder de Divi 5 y sus layouts (encabezado, pie, cuerpo, archivo y 404). Una plantilla decide qué ven todos tus visitantes en todo el sitio: una mal asignada puede tapar páginas o dejar sin contenido el cuerpo de muchas. Las plantillas nuevas nacen desactivadas, y cada cambio guarda un respaldo.', 'nexo-mcp' ),
	),
	'divi_library_tools_enabled'       => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'biblioteca', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar la biblioteca de Divi?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá guardar, cambiar y borrar elementos de la biblioteca de Divi 5 y ponerlos en las páginas. Un elemento global se repite en todas las páginas que lo usan: cambiarlo cambia todas a la vez, sin pasar por la copia de trabajo de cada página. Cada cambio guarda un respaldo.', 'nexo-mcp' ),
	),
	'extension_deletion_enabled'       => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'borrar', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres permitir que la IA borre plugins y temas?', 'nexo-mcp' ),
		'confirm-text'  => __( 'La IA conectada podrá borrar plugins y temas desactivados de este sitio. Si un plugin trae desinstalador, también se borran sus datos, y eso no se puede deshacer.', 'nexo-mcp' ),
	),
	'working_copy_default'             => array(
		'risk'          => 'off',
		'confirm-word'  => __( 'directo', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres que la IA edite lo publicado directamente?', 'nexo-mcp' ),
		'confirm-text'  => __( 'Los cambios a páginas y entradas publicadas dejarán de pasar por una copia de trabajo y tus visitantes los verán de inmediato.', 'nexo-mcp' ),
	),
	'enforce_connection_scopes'        => array(
		'risk'          => 'off',
		'confirm-word'  => __( 'alcances', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres dejar de respetar los alcances de cada conexión?', 'nexo-mcp' ),
		'confirm-text'  => __( 'Cada conexión podrá usar todo lo que permitan los módulos encendidos, aunque al autorizarla le hayas concedido menos permisos.', 'nexo-mcp' ),
	),
	'destructive_enabled'              => array(
		'risk'          => 'on',
		'confirm-word'  => __( 'eliminar', 'nexo-mcp' ),
		'confirm-title' => __( '¿Seguro que quieres activar las acciones destructivas?', 'nexo-mcp' ),
		'confirm-text'  => $is_production
			? __( 'Este sitio está en producción. La IA conectada podrá eliminar contenido de forma permanente desde ahora, sin volver a pedirte permiso.', 'nexo-mcp' )
			: __( 'La IA conectada podrá eliminar contenido de forma permanente desde ahora, sin volver a pedirte permiso.', 'nexo-mcp' ),
	),
);

// Elegir «Revisar y editar» en Conectar apaga el modo sólo lectura, pero el
// usuario está activando la edición: el diálogo nombra lo que se enciende.
$sensitive_options['connection_profile'] = array(
	'risk'          => 'on',
	'confirm-word'  => __( 'editar', 'nexo-mcp' ),
	'confirm-title' => __( '¿Seguro que quieres activar el modo edición?', 'nexo-mcp' ),
	'confirm-text'  => $sensitive_options['read_only']['confirm-text'],
);

/* translators: %s: palabra que hay que escribir para confirmar. */
$confirm_label = __( 'Escribe «%s» para confirmar', 'nexo-mcp' );

// Imprime los atributos data-* de una opción sensible, cada uno escapado.
$print_sensitive = static function ( string $key ) use ( $sensitive_options ): void {
	foreach ( $sensitive_options[ $key ] as $name => $value ) {
		echo ' data-nexo-' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}
};

/*
 * Cómo se registra este servidor en cada cliente MCP.
 *
 * `oauth` indica si el cliente resuelve solo el flujo de autorización. Cuando
 * no lo hace, se advierte en su bloque en lugar de entregar un comando que
 * el usuario no podría completar. `logo` es un archivo local de
 * assets/images; sin él se muestra `mark`.
 */
$oauth_clients = array(
	'claude-code' => array(
		'name'  => __( 'Claude Code', 'nexo-mcp' ),
		'mark'  => 'CL',
		'oauth' => true,
		'kind'  => 'command',
		'intro' => __( 'Ejecuta esto en tu terminal:', 'nexo-mcp' ),
		'code'  => 'claude mcp add --transport http nexo-wordpress ' . $endpoint,
		'after' => __( 'Después escribe /mcp dentro de Claude Code y elige Autenticar.', 'nexo-mcp' ),
	),
	'cursor'      => array(
		'name'  => __( 'Cursor', 'nexo-mcp' ),
		'mark'  => 'CU',
		'oauth' => true,
		'kind'  => 'file',
		'intro' => __( 'Agrega esto a ~/.cursor/mcp.json', 'nexo-mcp' ),
		'code'  => "{\n  \"mcpServers\": {\n    \"nexo-wordpress\": {\n      \"url\": \"" . $endpoint . "\"\n    }\n  }\n}",
		'after' => __( 'Cursor detectará que el servidor exige autorización y abrirá el navegador.', 'nexo-mcp' ),
	),
	'vscode'      => array(
		'name'  => __( 'VS Code', 'nexo-mcp' ),
		'mark'  => 'VS',
		'oauth' => true,
		'kind'  => 'command',
		'intro' => __( 'Ejecuta esto en tu terminal:', 'nexo-mcp' ),
		'code'  => 'code --add-mcp "{\"name\":\"nexo-wordpress\",\"type\":\"http\",\"url\":\"' . $endpoint . '\"}"',
		'after' => __( 'GitHub Copilot pedirá la autorización al conectarse por primera vez.', 'nexo-mcp' ),
	),
	'windsurf'    => array(
		'name'  => __( 'Windsurf', 'nexo-mcp' ),
		'mark'  => 'WS',
		'oauth' => true,
		'kind'  => 'file',
		'intro' => __( 'Agrega esto a ~/.codeium/windsurf/mcp_config.json', 'nexo-mcp' ),
		'code'  => "{\n  \"mcpServers\": {\n    \"nexo-wordpress\": {\n      \"serverUrl\": \"" . $endpoint . "\"\n    }\n  }\n}",
		'after' => __( 'Reinicia Windsurf y autoriza la conexión cuando te lo pida.', 'nexo-mcp' ),
	),
	'gemini-cli'  => array(
		'name'  => __( 'Gemini CLI', 'nexo-mcp' ),
		'mark'  => 'GM',
		'oauth' => true,
		'kind'  => 'file',
		'intro' => __( 'Agrega esto a ~/.gemini/settings.json', 'nexo-mcp' ),
		'code'  => "{\n  \"mcpServers\": {\n    \"nexo-wordpress\": {\n      \"httpUrl\": \"" . $endpoint . "\"\n    }\n  }\n}",
		'after' => __( 'Reinicia Gemini CLI. Verifica el nombre del campo en su documentación si tu versión es antigua.', 'nexo-mcp' ),
	),
	'codex'       => array(
		'name'         => __( 'Codex', 'nexo-mcp' ),
		'mark'         => 'CX',
		'oauth'        => true,
		'kind'         => 'bridge',
		'codex_bridge' => true,
		'intro'        => __( 'Método compatible recomendado mientras Codex corrige su transporte HTTP nativo:', 'nexo-mcp' ),
		'code'         => 'codex mcp add nexo_wordpress -- npx -y mcp-remote@0.1.38 ' . $endpoint . " --transport http-only --static-oauth-client-metadata '{\"scope\":\"" . $initial_scopes . "\"}'",
		'native_code'  => 'codex mcp add nexo_wordpress --url ' . $endpoint . ' --oauth-resource ' . $endpoint . "\n"
			. 'codex mcp login nexo_wordpress --scopes ' . str_replace( ' ', ',', $initial_scopes ),
		'after'        => __( 'Codex descargará el puente fijado, abrirá OAuth y WordPress te pedirá autorización.', 'nexo-mcp' ),
	),
	'antigravity' => array(
		'name'  => __( 'Antigravity', 'nexo-mcp' ),
		'mark'  => 'AG',
		'oauth' => true,
		'kind'  => 'file',
		'intro' => __( 'Registra el servidor con esta dirección en la configuración MCP del cliente:', 'nexo-mcp' ),
		'code'  => $endpoint,
		'after' => __( 'El cliente abrirá el navegador para autorizar la conexión.', 'nexo-mcp' ),
	),
	'other'       => array(
		'name'  => __( 'Otro cliente', 'nexo-mcp' ),
		'mark'  => '+',
		'oauth' => true,
		'kind'  => 'file',
		'intro' => __( 'Cualquier cliente compatible con MCP sobre HTTP sólo necesita esta dirección:', 'nexo-mcp' ),
		'code'  => $endpoint,
		'after' => __( 'Si el cliente implementa la especificación, descubrirá por sí solo cómo autorizarse.', 'nexo-mcp' ),
	),
);

/*
 * Secciones del panel, una por pestaña.
 *
 * La pestaña visible se decide aquí y no sólo en el navegador: así la página
 * llega ya en su sitio, sin mostrar otra un instante, y sigue funcionando
 * aunque el JavaScript falle. Al volver de un formulario se abre la pestaña
 * que lo contiene.
 */
$panel_tabs = array(
	'resumen'         => __( 'Resumen', 'nexo-mcp' ),
	'conectar'        => __( 'Conectar', 'nexo-mcp' ),
	'accesos'         => __( 'Accesos (OAuth)', 'nexo-mcp' ),
	'modulos'         => __( 'Módulos y límites', 'nexo-mcp' ),
	'licencia'        => __( 'Licencia y sitio', 'nexo-mcp' ),
	'actualizaciones' => __( 'Actualizaciones', 'nexo-mcp' ),
	'endpoint'        => __( 'Endpoint MCP', 'nexo-mcp' ),
	'actividad'       => __( 'Actividad', 'nexo-mcp' ),
	'soporte'         => __( 'Soporte', 'nexo-mcp' ),
);
$panel_url  = admin_url( 'admin.php?page=' . \NexoDev\NexoMCP\Admin\AdminPage::PAGE_SLUG );

// Sólo elige qué pestaña se ve; no cambia nada, por eso no lleva nonce.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

if ( isset( $panel_tabs[ $requested_tab ] ) ) {
	$active_tab = $requested_tab;
} elseif ( null !== $report_notice ) {
	// Vuelta del formulario de reporte: su aviso vive en esa pestaña.
	$active_tab = 'soporte';
} elseif ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	// Vuelta de options.php: el único formulario que lo usa es el de módulos.
	$active_tab = 'modulos';
} else {
	$active_tab = 'resumen';
}
?>
<div class="wrap nexo-mcp-admin">
	<header class="nexo-mcp-header">
		<h1 class="nexo-mcp-header__brand">
			<img
				src="<?php echo esc_url( $images_url . 'logo-nexo.svg' ); ?>"
				alt="<?php esc_attr_e( 'Nexo MCP', 'nexo-mcp' ); ?>"
				width="175"
				height="32"
			>
		</h1>
		<p class="nexo-mcp-header__meta">
			<span><?php esc_html_e( 'Versión', 'nexo-mcp' ); ?></span>
			<strong><?php echo esc_html( NEXO_MCP_VERSION ); ?></strong>
		</p>
	</header>

	<?php
	/*
	 * WordPress mueve los avisos de todos los plugins hasta justo antes de este
	 * marcador. Sin él los coloca tras el primer <h1>, que aquí vive dentro del
	 * encabezado, y un aviso ajeno aparece incrustado en medio de la portada.
	 */
	?>
	<hr class="wp-header-end">

	<?php settings_errors( \NexoDev\NexoMCP\Support\Settings::OPTION_NAME ); ?>

	<?php
	/*
	 * Aviso reservado para el caso en que el servidor intercepta /.well-known/.
	 * Nace oculto porque sólo el navegador puede confirmarlo, y trae redactado
	 * el texto que hay que enviarle al hosting: el dueño del sitio no tiene por
	 * qué saber traducir un 404 a una regla de servidor. Va fuera de las
	 * pestañas porque bloquea cualquier conexión, se esté donde se esté.
	 */
	?>
	<section class="nexo-mcp-discovery-alert" hidden>
		<h2><?php esc_html_e( 'Tu servidor está bloqueando el descubrimiento OAuth', 'nexo-mcp' ); ?></h2>
		<p>
			<?php esc_html_e( 'El conector está activo y responde bien, pero las dos direcciones que tu IA consulta antes de conectarse no llegan a WordPress: el servidor las atiende por su cuenta y devuelve una página de error. Ningún cliente MCP podrá autorizarse mientras siga así.', 'nexo-mcp' ); ?>
		</p>
		<p><?php esc_html_e( 'No es algo que el plugin pueda corregir por dentro. Abre un ticket con tu hosting y pega este texto:', 'nexo-mcp' ); ?></p>
		<blockquote class="nexo-mcp-discovery-alert__ticket">
			<?php
			printf(
				/* translators: 1: dirección del documento de recurso protegido, 2: dirección del documento del servidor de autorización. */
				esc_html__( 'Necesito que las rutas %1$s y %2$s (incluidas sus subrutas) se entreguen a PHP/WordPress en lugar de servirse desde el directorio estático .well-known. Son documentos que genera un plugin y hoy devuelven el 404 del servidor sin llegar a WordPress. El resto de .well-known, como acme-challenge, puede seguir igual.', 'nexo-mcp' ),
				esc_html( $oauth_resource_url ),
				esc_html( $oauth_server_url )
			);
			?>
		</blockquote>
		<div class="nexo-mcp-actions">
			<button type="button" class="button button-primary nexo-mcp-copy-ticket"><?php esc_html_e( 'Copiar texto para el hosting', 'nexo-mcp' ); ?></button>
			<button type="button" class="button nexo-mcp-recheck-discovery"><?php esc_html_e( 'Volver a comprobar', 'nexo-mcp' ); ?></button>
		</div>
		<p class="nexo-mcp-discovery-alert__status" role="status" aria-live="polite"></p>
	</section>

	<nav class="nexo-mcp-tabs" aria-label="<?php esc_attr_e( 'Secciones del panel', 'nexo-mcp' ); ?>">
		<?php foreach ( $panel_tabs as $tab_id => $tab_label ) : ?>
			<a
				class="nexo-mcp-tab"
				id="nexo-mcp-tablink-<?php echo esc_attr( $tab_id ); ?>"
				href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, $panel_url ) ); ?>"
				data-nexo-tab="<?php echo esc_attr( $tab_id ); ?>"
				<?php echo $active_tab === $tab_id ? 'aria-current="page"' : ''; ?>
			><?php echo esc_html( $tab_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php /* ------------------------------------------------------------ Resumen */ ?>
	<section
		id="nexo-mcp-panel-resumen"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="resumen"
		aria-labelledby="nexo-mcp-tablink-resumen"
		<?php echo 'resumen' === $active_tab ? '' : 'hidden'; ?>
	>
		<?php if ( $update_available ) : ?>
			<div class="nexo-mcp-callout nexo-mcp-callout--warn nexo-mcp-update-banner">
				<svg class="nexo-mcp-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
				<div class="nexo-mcp-callout__body">
					<strong><?php esc_html_e( 'Hay una versión nueva del conector', 'nexo-mcp' ); ?></strong>
					<p>
						<?php
						printf(
							/* translators: %s: versión disponible. */
							esc_html__( 'La versión %s está lista para instalar.', 'nexo-mcp' ),
							esc_html( $update['version'] )
						);
						?>
					</p>
				</div>
				<a
					class="button button-primary"
					href="<?php echo esc_url( add_query_arg( 'tab', 'actualizaciones', $panel_url ) ); ?>"
					data-nexo-tab="actualizaciones"
				><?php esc_html_e( 'Ver actualización', 'nexo-mcp' ); ?></a>
			</div>
		<?php endif; ?>

		<div class="nexo-mcp-status-grid" role="group" aria-label="<?php esc_attr_e( 'Estado del conector', 'nexo-mcp' ); ?>">
			<article class="nexo-mcp-status-card">
				<span class="nexo-mcp-status-card__label"><?php esc_html_e( 'Servidor MCP', 'nexo-mcp' ); ?></span>
				<strong class="nexo-mcp-status <?php echo $is_server_ready ? 'nexo-mcp-status--online' : 'nexo-mcp-status--offline'; ?>">
					<span aria-hidden="true"></span>
					<?php echo esc_html( $is_server_ready ? __( 'Activo', 'nexo-mcp' ) : __( 'Inactivo', 'nexo-mcp' ) ); ?>
				</strong>
				<small><?php esc_html_e( 'Expone módulos independientes mediante una allowlist estricta.', 'nexo-mcp' ); ?></small>
			</article>

			<article class="nexo-mcp-status-card">
				<span class="nexo-mcp-status-card__label"><?php esc_html_e( 'HTTPS', 'nexo-mcp' ); ?></span>
				<strong class="nexo-mcp-status <?php echo $is_https ? 'nexo-mcp-status--online' : 'nexo-mcp-status--warning'; ?>">
					<span aria-hidden="true"></span>
					<?php echo esc_html( $is_local_http ? __( 'Local sin HTTPS', 'nexo-mcp' ) : ( $is_https ? __( 'Disponible', 'nexo-mcp' ) : __( 'No detectado', 'nexo-mcp' ) ) ); ?>
				</strong>
				<small>
					<?php echo esc_html( $is_local_http ? __( 'Permitido sólo en este entorno local (NEXO_MCP_ALLOW_INSECURE_LOCAL).', 'nexo-mcp' ) : ( $is_https ? __( 'El sitio usa una URL segura.', 'nexo-mcp' ) : __( 'Las conexiones remotas serán bloqueadas.', 'nexo-mcp' ) ) ); ?>
				</small>
			</article>

			<article class="nexo-mcp-status-card">
				<span class="nexo-mcp-status-card__label"><?php esc_html_e( 'WooCommerce', 'nexo-mcp' ); ?></span>
				<strong class="nexo-mcp-status <?php echo $is_woocommerce ? 'nexo-mcp-status--online' : 'nexo-mcp-status--neutral'; ?>">
					<span aria-hidden="true"></span>
					<?php echo esc_html( $is_woocommerce ? __( 'Detectado', 'nexo-mcp' ) : __( 'No instalado', 'nexo-mcp' ) ); ?>
				</strong>
				<small><?php esc_html_e( 'El módulo seguirá siendo completamente opcional.', 'nexo-mcp' ); ?></small>
			</article>

			<?php
			/*
			 * El descubrimiento vive en /.well-known/, fuera del alcance de WordPress.
			 * Hay servidores que sirven ese directorio como archivos estáticos y la
			 * petición nunca llega a PHP: el conector queda mudo aunque esté activo.
			 * Sólo se puede saber pidiendo las direcciones desde fuera, así que la
			 * tarjeta nace en blanco y la completa el navegador.
			 */
			?>
			<article class="nexo-mcp-status-card nexo-mcp-discovery"
				data-resource-metadata="<?php echo esc_url( $oauth_resource_url ); ?>"
				data-authorization-metadata="<?php echo esc_url( $oauth_server_url ); ?>"
			>
				<span class="nexo-mcp-status-card__label"><?php esc_html_e( 'Descubrimiento OAuth', 'nexo-mcp' ); ?></span>
				<strong class="nexo-mcp-status nexo-mcp-status--neutral nexo-mcp-discovery__state">
					<span aria-hidden="true"></span>
					<?php esc_html_e( 'Comprobando', 'nexo-mcp' ); ?>
				</strong>
				<small class="nexo-mcp-discovery__hint"><?php esc_html_e( 'Las direcciones que tu IA consulta antes de pedir permiso.', 'nexo-mcp' ); ?></small>
			</article>
		</div>

		<div class="nexo-mcp-summary">
			<section class="nexo-mcp-card nexo-mcp-meta" aria-labelledby="nexo-mcp-meta-title">
				<div class="nexo-mcp-card__head">
					<div>
						<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Sistema', 'nexo-mcp' ); ?></p>
						<h2 class="nexo-mcp-card__title" id="nexo-mcp-meta-title"><?php esc_html_e( 'Entorno', 'nexo-mcp' ); ?></h2>
					</div>
				</div>
				<dl class="nexo-mcp-rows">
					<div>
						<dt><?php esc_html_e( 'Plugin', 'nexo-mcp' ); ?></dt>
						<dd><?php echo esc_html( NEXO_MCP_VERSION ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'WordPress', 'nexo-mcp' ); ?></dt>
						<dd><?php echo esc_html( get_bloginfo( 'version' ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'PHP', 'nexo-mcp' ); ?></dt>
						<dd><?php echo esc_html( PHP_VERSION ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'MCP Adapter', 'nexo-mcp' ); ?></dt>
						<dd><?php echo esc_html( $adapter_version ); ?></dd>
					</div>
				</dl>
			</section>

			<section class="nexo-mcp-card" aria-labelledby="nexo-mcp-start-title">
				<div class="nexo-mcp-card__head">
					<div>
						<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Primeros pasos', 'nexo-mcp' ); ?></p>
						<h2 class="nexo-mcp-card__title" id="nexo-mcp-start-title"><?php esc_html_e( 'Conecta tu sitio en un minuto', 'nexo-mcp' ); ?></h2>
					</div>
					<a
						class="button button-primary"
						href="<?php echo esc_url( add_query_arg( 'tab', 'conectar', $panel_url ) ); ?>"
						data-nexo-tab="conectar"
					><?php esc_html_e( 'Empezar', 'nexo-mcp' ); ?></a>
				</div>
				<p class="nexo-mcp-muted"><?php esc_html_e( 'Elige tu IA, define qué podrá hacer y autoriza el acceso desde el navegador. Sin contraseñas ni tokens manuales.', 'nexo-mcp' ); ?></p>
			</section>
		</div>
	</section>

	<?php /* ----------------------------------------------------------- Conectar */ ?>
	<section
		id="nexo-mcp-panel-conectar"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="conectar"
		aria-labelledby="nexo-mcp-tablink-conectar"
		<?php echo 'conectar' === $active_tab ? '' : 'hidden'; ?>
	>
		<section class="nexo-mcp-card nexo-mcp-oauth-panel" aria-labelledby="nexo-mcp-oauth-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Conexión asistida', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-oauth-title"><?php esc_html_e( 'Conecta WordPress en un minuto', 'nexo-mcp' ); ?></h2>
					<p class="nexo-mcp-card__lead"><?php esc_html_e( 'Elige tu IA y copia una sola instrucción. Sin contraseñas ni tokens manuales; si un cliente necesita compatibilidad, el panel lo explica.', 'nexo-mcp' ); ?></p>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'OAuth 2.1 · PKCE', 'nexo-mcp' ); ?></span>
			</div>

			<?php if ( ! $is_https ) : ?>
				<p class="nexo-mcp-callout nexo-mcp-callout--warn nexo-mcp-oauth-warning">
					<?php esc_html_e( 'Este sitio no usa HTTPS. La autorización quedará bloqueada hasta que el certificado esté activo.', 'nexo-mcp' ); ?>
				</p>
			<?php endif; ?>

			<ol class="nexo-mcp-steps nexo-mcp-oauth-steps">
				<li>
					<p class="nexo-mcp-step__title"><?php esc_html_e( '¿Con qué IA quieres conectar WordPress?', 'nexo-mcp' ); ?></p>
					<p class="nexo-mcp-step__hint"><?php esc_html_e( 'Elige tu cliente y te muestro exactamente qué copiar.', 'nexo-mcp' ); ?></p>
					<div class="nexo-mcp-client-grid nexo-mcp-oauth-grid">
						<?php foreach ( $oauth_clients as $client_key => $client ) : ?>
							<label class="nexo-mcp-client-card">
								<input
									type="radio"
									name="nexo_mcp_oauth_client"
									value="<?php echo esc_attr( $client_key ); ?>"
									<?php checked( 'claude-code', $client_key ); ?>
								/>
								<span class="nexo-mcp-client-card__mark" aria-hidden="true">
									<?php if ( ! empty( $client['logo'] ) ) : ?>
										<img src="<?php echo esc_url( $images_url . $client['logo'] ); ?>" alt="" width="20" height="20">
									<?php else : ?>
										<?php echo esc_html( $client['mark'] ); ?>
									<?php endif; ?>
								</span>
								<span class="nexo-mcp-client-card__copy">
									<strong><?php echo esc_html( $client['name'] ); ?></strong>
									<span>
										<?php
										echo esc_html(
											! empty( $client['codex_bridge'] )
												? __( 'Conexión compatible', 'nexo-mcp' )
												: __( 'Autorización directa', 'nexo-mcp' )
										);
										?>
									</span>
								</span>
								<svg class="nexo-mcp-client-card__check" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
							</label>
						<?php endforeach; ?>
					</div>
				</li>
				<li>
					<p class="nexo-mcp-step__title"><?php esc_html_e( 'Elige qué podrá hacer la IA', 'nexo-mcp' ); ?></p>
					<p class="nexo-mcp-step__hint"><?php esc_html_e( 'Empieza en solo lectura. Puedes ampliar el acceso después desde este mismo panel.', 'nexo-mcp' ); ?></p>
					<div
						class="nexo-mcp-profile-grid"
						data-read-scopes="<?php echo esc_attr( $read_scopes ); ?>"
						data-manage-scopes="<?php echo esc_attr( $manage_scopes ); ?>"
					>
						<label class="nexo-mcp-profile-card">
							<input type="radio" name="nexo_mcp_connection_profile" value="read" <?php checked( 'read', $connection_profile ); ?> />
							<span class="nexo-mcp-profile-card__copy">
								<strong><?php esc_html_e( 'Solo revisar', 'nexo-mcp' ); ?></strong>
								<small><?php esc_html_e( 'Puede leer el sitio. No puede crear, editar, publicar ni eliminar.', 'nexo-mcp' ); ?></small>
							</span>
							<em class="nexo-mcp-tag nexo-mcp-tag--ok"><?php esc_html_e( 'Recomendado', 'nexo-mcp' ); ?></em>
						</label>
						<label class="nexo-mcp-profile-card nexo-mcp-profile-card--sensitive">
							<input type="radio" name="nexo_mcp_connection_profile" value="manage" <?php $print_sensitive( 'connection_profile' ); ?> <?php checked( 'manage', $connection_profile ); ?> />
							<span class="nexo-mcp-profile-card__copy">
								<strong><?php esc_html_e( 'Revisar y editar', 'nexo-mcp' ); ?></strong>
								<small><?php esc_html_e( 'Puede usar los módulos que actives en «Módulos y límites». Publicar y eliminar siguen teniendo controles separados.', 'nexo-mcp' ); ?></small>
							</span>
							<em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em>
						</label>
					</div>
					<p class="nexo-mcp-profile-status" id="nexo-mcp-profile-status" role="status" aria-live="polite"></p>
				</li>
				<li>
					<p class="nexo-mcp-step__title"><?php esc_html_e( 'Sigue el método recomendado', 'nexo-mcp' ); ?></p>

					<?php foreach ( $oauth_clients as $client_key => $client ) : ?>
						<?php
						$prompt_server_name = 'codex' === $client_key ? 'nexo_wordpress' : 'nexo-wordpress';
						$client_prompt      = sprintf(
							/* translators: %s: nombre del servidor MCP configurado en el cliente. */
							__( 'Usa el servidor MCP %s para comprobar la conexión. Ejecuta únicamente la herramienta nexo-mcp-get-site-info y dime el nombre y la URL del sitio. No modifiques nada.', 'nexo-mcp' ),
							$prompt_server_name
						);
						?>
						<div
							class="nexo-mcp-oauth-recipe<?php echo $client['oauth'] ? '' : ' nexo-mcp-oauth-recipe--manual'; ?>"
							data-client="<?php echo esc_attr( $client_key ); ?>"
							data-client-name="<?php echo esc_attr( $client['name'] ); ?>"
							data-server-name="<?php echo esc_attr( $prompt_server_name ); ?>"
							data-bridge="<?php echo ! empty( $client['codex_bridge'] ) ? 'true' : 'false'; ?>"
							<?php echo 'claude-code' === $client_key ? '' : 'hidden'; ?>
						>
							<div class="nexo-mcp-recipe-head">
								<span><?php echo esc_html( $client['name'] ); ?></span>
								<small class="nexo-mcp-tag nexo-mcp-native-badge">
									<?php esc_html_e( 'OAuth nativo', 'nexo-mcp' ); ?>
								</small>
							</div>

							<?php if ( ! empty( $client['codex_bridge'] ) ) : ?>
								<div class="nexo-mcp-recipe-band nexo-mcp-codex-native">
									<div class="nexo-mcp-codex-native__intro">
										<span class="nexo-mcp-codex-native__mark" aria-hidden="true">MCP</span>
										<div>
											<strong><?php esc_html_e( 'Conexión comprobada con Codex', 'nexo-mcp' ); ?></strong>
											<p><?php esc_html_e( 'Usa mcp-remote@0.1.38 como puente temporal. OAuth sigue ocurriendo en WordPress y no se copian tokens.', 'nexo-mcp' ); ?></p>
										</div>
									</div>
									<ol class="nexo-mcp-codex-native__steps">
										<li><span>1</span><p><strong><?php esc_html_e( 'Copia la instrucción', 'nexo-mcp' ); ?></strong><?php esc_html_e( ' con el botón de abajo.', 'nexo-mcp' ); ?></p></li>
										<li><span>2</span><p><?php esc_html_e( 'Pégala en Codex y aprueba el cambio de configuración.', 'nexo-mcp' ); ?></p></li>
										<li><span>3</span><p><?php esc_html_e( 'Autoriza en WordPress cuando se abra el navegador.', 'nexo-mcp' ); ?></p></li>
										<li><span>4</span><p><?php esc_html_e( 'Codex comprobará el sitio sin modificarlo.', 'nexo-mcp' ); ?></p></li>
									</ol>
									<div class="nexo-mcp-codex-native__actions">
										<button type="button" class="button nexo-mcp-copy-instruction"><?php esc_html_e( 'Copiar y conectar con Codex', 'nexo-mcp' ); ?></button>
										<code data-codex-bridge-endpoint="<?php echo esc_attr( $endpoint ); ?>"><?php echo esc_html( $client['code'] ); ?></code>
									</div>
								</div>

								<div class="nexo-mcp-oauth-diagnostic"
									data-endpoint="<?php echo esc_url( $endpoint ); ?>"
									data-resource-metadata="<?php echo esc_url( $oauth_resource_url ); ?>"
									data-authorization-metadata="<?php echo esc_url( $oauth_server_url ); ?>"
								>
									<div>
										<strong><?php esc_html_e( '¿Quieres comprobar WordPress antes?', 'nexo-mcp' ); ?></strong>
										<p><?php esc_html_e( 'La prueba revisa el desafío 401 y ambos documentos OAuth. No inicia sesión ni modifica el sitio.', 'nexo-mcp' ); ?></p>
									</div>
									<button type="button" class="button nexo-mcp-test-oauth"><?php esc_html_e( 'Probar OAuth de WordPress', 'nexo-mcp' ); ?></button>
									<p class="nexo-mcp-oauth-diagnostic__status" role="status" aria-live="polite"></p>
								</div>
							<?php else : ?>
								<div class="nexo-mcp-recipe-band nexo-mcp-one-click">
									<div>
										<strong><?php esc_html_e( 'Una sola instrucción', 'nexo-mcp' ); ?></strong>
										<?php /* translators: %s: nombre del cliente de IA. */ ?>
										<p><?php printf( esc_html__( 'Pégala en %s. La IA configurará el servidor, pedirá tu aprobación y comprobará la conexión.', 'nexo-mcp' ), esc_html( $client['name'] ) ); ?></p>
									</div>
									<button type="button" class="button nexo-mcp-copy-instruction">
										<?php
										printf(
											/* translators: %s: nombre del cliente de IA. */
											esc_html__( 'Copiar y conectar con %s', 'nexo-mcp' ),
											esc_html( $client['name'] )
										);
										?>
									</button>
								</div>
							<?php endif; ?>

							<details class="nexo-mcp-manual-details">
								<summary><?php esc_html_e( 'Ver configuración manual', 'nexo-mcp' ); ?></summary>
								<div class="nexo-mcp-manual-details__body">
									<p class="nexo-mcp-oauth-hint"><?php echo esc_html( $client['intro'] ); ?></p>
									<div class="nexo-mcp-copyfield nexo-mcp-copyfield--block">
										<code
											id="nexo-mcp-oauth-code-<?php echo esc_attr( $client_key ); ?>"
											<?php echo 'codex' === $client_key ? 'data-codex-bridge-endpoint="' . esc_attr( $endpoint ) . '"' : ''; ?>
										><?php echo esc_html( $client['code'] ); ?></code>
										<button type="button" class="button nexo-mcp-copy-endpoint" data-target="nexo-mcp-oauth-code-<?php echo esc_attr( $client_key ); ?>"><?php esc_html_e( 'Copiar comando', 'nexo-mcp' ); ?></button>
									</div>
									<p class="nexo-mcp-oauth-after"><?php echo esc_html( $client['after'] ); ?></p>
									<code class="nexo-mcp-hidden-prompt" id="nexo-mcp-prompt-<?php echo esc_attr( $client_key ); ?>"><?php echo esc_html( $client_prompt ); ?></code>

									<?php if ( ! empty( $client['codex_bridge'] ) ) : ?>
										<details class="nexo-mcp-compat-fallback">
											<summary><?php esc_html_e( 'Alternativa experimental: HTTP nativo de Codex', 'nexo-mcp' ); ?></summary>
											<div>
												<p><?php esc_html_e( 'No requiere Node.js, pero Codex 0.146 puede fallar después de notifications/initialized. Úsalo cuando OpenAI publique la corrección.', 'nexo-mcp' ); ?></p>
												<div class="nexo-mcp-copyfield nexo-mcp-copyfield--block">
													<code id="nexo-mcp-codex-native-code" data-codex-native-endpoint="<?php echo esc_attr( $endpoint ); ?>"><?php echo esc_html( $client['native_code'] ); ?></code>
													<button type="button" class="button nexo-mcp-copy-endpoint" data-target="nexo-mcp-codex-native-code"><?php esc_html_e( 'Copiar alternativa nativa', 'nexo-mcp' ); ?></button>
												</div>
											</div>
										</details>
									<?php endif; ?>
								</div>
							</details>
						</div>
					<?php endforeach; ?>
				</li>
				<li>
					<p class="nexo-mcp-step__title"><?php esc_html_e( 'Autoriza en el navegador', 'nexo-mcp' ); ?></p>
					<p class="nexo-mcp-step__hint">
						<?php esc_html_e( 'El cliente abrirá una pantalla de este sitio con los permisos que solicita. Apruebas y queda conectado. No se copia ninguna contraseña.', 'nexo-mcp' ); ?>
					</p>
				</li>
			</ol>
		</section>
	</section>

	<?php /* ------------------------------------------------------ Accesos OAuth */ ?>
	<section
		id="nexo-mcp-panel-accesos"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="accesos"
		aria-labelledby="nexo-mcp-tablink-accesos"
		<?php echo 'accesos' === $active_tab ? '' : 'hidden'; ?>
	>
		<section class="nexo-mcp-card nexo-mcp-existing-connections" aria-labelledby="nexo-mcp-accesos-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Conexiones autorizadas', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-accesos-title"><?php esc_html_e( 'Accesos vigentes por OAuth', 'nexo-mcp' ); ?></h2>
					<p class="nexo-mcp-card__lead">
						<?php
						printf(
							/* translators: %s: enlace a la pestaña «Módulos y límites». */
							esc_html__( 'Cada conexión muestra los alcances que tiene concedidos y sólo puede usar esos. Puedes ampliarlos o reducirlos aquí sin reconectar el cliente. El límite superior para todas las conexiones lo define %s.', 'nexo-mcp' ),
							'<a href="' . esc_url( add_query_arg( 'tab', 'modulos', $panel_url ) ) . '" data-nexo-tab="modulos">' . esc_html__( 'Módulos y límites', 'nexo-mcp' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<?php $scopes_notice = \NexoDev\NexoMCP\Admin\OAuthConnectionsController::take_scopes_notice(); ?>
			<?php if ( null !== $scopes_notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $scopes_notice['type'] ); ?> inline">
					<p><?php echo esc_html( $scopes_notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $oauth_connections ) ) : ?>
				<p class="nexo-mcp-empty-connections">
					<?php esc_html_e( 'Todavía no hay clientes autorizados. Aparecerán aquí en cuanto alguno complete el flujo.', 'nexo-mcp' ); ?>
				</p>
			<?php else : ?>
				<div class="nexo-mcp-conn-list">
					<?php foreach ( $oauth_connections as $connection ) : ?>
						<?php
						$connection_user = get_userdata( (int) $connection['user_id'] );
						$connection_name = '' !== (string) $connection['client_name']
							? (string) $connection['client_name']
							: (string) $connection['client_id'];
						$last_used       = (string) $connection['last_used_at'];
						$has_been_used   = '' !== $last_used && '0000-00-00 00:00:00' !== $last_used;
						?>
						<article class="nexo-mcp-conn-card">
							<div class="nexo-mcp-conn-card__top">
								<div class="nexo-mcp-conn-main">
									<strong class="nexo-mcp-conn-name"><?php echo esc_html( $connection_name ); ?></strong>
									<span class="nexo-mcp-conn-meta">
										<span class="nexo-mcp-conn-owner">
											<?php
											printf(
												/* translators: %s: nombre de usuario de WordPress. */
												esc_html__( 'En nombre de %s', 'nexo-mcp' ),
												esc_html( false !== $connection_user ? $connection_user->user_login : (string) $connection['user_id'] )
											);
											?>
										</span>
										<span class="nexo-mcp-conn-used">
											<?php
											echo esc_html(
												$has_been_used
													? sprintf(
														/* translators: %s: fecha del último uso en UTC. */
														__( 'Último uso: %s UTC', 'nexo-mcp' ),
														$last_used
													)
													: __( 'Sin uso todavía', 'nexo-mcp' )
											);
											?>
										</span>
									</span>
								</div>
								<button
									type="button"
									class="button button-link-delete nexo-mcp-revoke-oauth"
									data-client="<?php echo esc_attr( (string) $connection['client_id'] ); ?>"
									data-user="<?php echo esc_attr( (string) $connection['user_id'] ); ?>"
								>
									<?php esc_html_e( 'Revocar', 'nexo-mcp' ); ?>
								</button>
							</div>
							<span class="nexo-mcp-conn-scopes">
								<?php foreach ( explode( ' ', (string) $connection['scope'] ) as $granted_scope ) : ?>
									<?php if ( '' !== $granted_scope ) : ?>
										<code><?php echo esc_html( $granted_scope ); ?></code>
									<?php endif; ?>
								<?php endforeach; ?>
							</span>
							<?php $connection_scopes = array_filter( explode( ' ', (string) $connection['scope'] ) ); ?>
							<details class="nexo-mcp-conn-edit">
								<summary><?php esc_html_e( 'Editar alcances de esta conexión', 'nexo-mcp' ); ?></summary>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nexo-mcp-conn-edit__form">
									<input type="hidden" name="action" value="<?php echo esc_attr( \NexoDev\NexoMCP\Admin\OAuthConnectionsController::SCOPES_ACTION ); ?>">
									<input type="hidden" name="client_id" value="<?php echo esc_attr( (string) $connection['client_id'] ); ?>">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $connection['user_id'] ); ?>">
									<?php wp_nonce_field( \NexoDev\NexoMCP\Admin\OAuthConnectionsController::SCOPES_ACTION ); ?>
									<?php foreach ( \NexoDev\NexoMCP\OAuth\ScopeMap::all() as $known_scope ) : ?>
										<label class="nexo-mcp-conn-edit__scope">
											<input
												type="checkbox"
												name="scopes[]"
												value="<?php echo esc_attr( $known_scope ); ?>"
												<?php checked( in_array( $known_scope, $connection_scopes, true ) ); ?>
												<?php disabled( \NexoDev\NexoMCP\OAuth\ScopeMap::CONNECT === $known_scope ); ?>
											>
											<code><?php echo esc_html( $known_scope ); ?></code>
											<span><?php echo esc_html( \NexoDev\NexoMCP\OAuth\ScopeMap::label_for( $known_scope ) ); ?></span>
										</label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Los módulos apagados en «Módulos y límites» siguen bloqueados aunque marques su alcance aquí.', 'nexo-mcp' ); ?></p>
									<button type="submit" class="button"><?php esc_html_e( 'Guardar alcances', 'nexo-mcp' ); ?></button>
								</form>
							</details>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	</section>

	<?php /* -------------------------------------------------- Módulos y límites */ ?>
	<section
		id="nexo-mcp-panel-modulos"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="modulos"
		aria-labelledby="nexo-mcp-tablink-modulos"
		<?php echo 'modulos' === $active_tab ? '' : 'hidden'; ?>
	>
		<section class="nexo-mcp-card" aria-labelledby="nexo-mcp-modulos-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Política operativa', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-modulos-title"><?php esc_html_e( 'Módulos y límites', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'Seguro por defecto', 'nexo-mcp' ); ?></span>
			</div>

			<?php
			/*
			 * Todos los ajustes van en un solo formulario: al guardar, lo que no
			 * llega en la petición vuelve a su valor por defecto.
			 */
			?>
			<form action="options.php" method="post" class="nexo-mcp-settings-form">
				<?php settings_fields( \NexoDev\NexoMCP\Admin\SettingsController::GROUP ); ?>

				<div class="nexo-mcp-callout nexo-mcp-security-note <?php echo $is_production ? 'nexo-mcp-callout--danger nexo-mcp-security-note--production' : 'nexo-mcp-callout--warn'; ?>" role="note">
					<svg class="nexo-mcp-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
					<div class="nexo-mcp-callout__body">
						<strong>
							<?php echo esc_html( $is_production ? __( 'Protección para sitio en producción', 'nexo-mcp' ) : __( 'Entorno no productivo detectado', 'nexo-mcp' ) ); ?>
						</strong>
						<p><?php esc_html_e( 'Nexo MCP no permite ejecutar PHP ni acceder libremente al sistema de archivos. Mantén publicación y eliminaciones apagadas salvo que sean imprescindibles; para cambios estructurales, trabaja primero en staging.', 'nexo-mcp' ); ?></p>
					</div>
				</div>

				<div class="nexo-mcp-toggles">
					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Servidor MCP', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Habilita el endpoint autenticado del conector.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[server_enabled]"
							value="1"
							<?php checked( ! empty( $settings['server_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Herramientas WordPress', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Páginas, artículos, medios y categorías.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[wordpress_tools_enabled]"
							value="1"
							<?php checked( ! empty( $settings['wordpress_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Contenido avanzado', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Medios, taxonomías y revisiones con permisos editoriales.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[content_tools_enabled]"
							value="1"
							<?php checked( ! empty( $settings['content_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'SEO técnico', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Metadatos, Open Graph, Schema, sitemap y robots.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[seo_tools_enabled]"
							value="1"
							<?php checked( ! empty( $settings['seo_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Apariencia global', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Identidad, CSS adicional y menús sin editar archivos del tema.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[appearance_tools_enabled]"
							value="1"
							<?php checked( ! empty( $settings['appearance_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Constructores visuales', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Textos, enlaces e imágenes en páginas de Elementor, Divi y WPBakery, con respaldo antes de cada cambio.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[builders_tools_enabled]"
							value="1"
							<?php checked( ! empty( $settings['builders_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Sistema de diseño de Elementor', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Cambia clases, variables, colores y tipografía de todo el sitio en vivo, con respaldo y deshacer.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[design_system_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'design_system_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['design_system_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Datos dinámicos de Elementor', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Asocia datos del sitio (título, fecha, imagen destacada…) a textos e imágenes de Elementor 4. Requiere Elementor Pro y el módulo de constructores.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[builders_dynamic_enabled]"
							value="1"
							<?php $print_sensitive( 'builders_dynamic_enabled' ); ?>
							<?php checked( ! empty( $settings['builders_dynamic_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Componentes de Elementor', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Crea componentes reutilizables de Elementor 4 (en borrador). Requiere Elementor Pro con licencia activa y el módulo de constructores.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[builders_components_enabled]"
							value="1"
							<?php $print_sensitive( 'builders_components_enabled' ); ?>
							<?php checked( ! empty( $settings['builders_components_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Diseño global de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Cambia paleta, variables, fuentes y tipografía globales de Divi 5 en todo el sitio en vivo, con respaldo y deshacer.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_design_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_design_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_design_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Presets de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Crea, cambia y borra los presets de módulos y de grupos de opciones de Divi 5, y fija el predeterminado, en todo el sitio en vivo, con respaldo y deshacer.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_presets_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_presets_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_presets_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Contenido dinámico de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Pone contenido dinámico y bucles de consulta en las páginas de Divi 5 (copia de trabajo, con respaldo): muestra datos del sitio a cualquier visitante.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_dynamic_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_dynamic_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_dynamic_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Condiciones de visualización de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Pone condiciones para mostrar u ocultar módulos de Divi 5 (copia de trabajo, con respaldo): decide qué ve cada visitante.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_conditions_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_conditions_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_conditions_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Theme Builder de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Crea y cambia plantillas del Theme Builder de Divi 5 y sus layouts (encabezado, pie, cuerpo, archivo, 404): decide qué ve todo el sitio.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_theme_builder_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_theme_builder_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_theme_builder_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Biblioteca de Divi', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Guarda, cambia y reutiliza elementos de la biblioteca de Divi 5: un elemento global cambia todas las páginas que lo usan.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[divi_library_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'divi_library_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['divi_library_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Mantenimiento de plugins', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Actualiza, instala, activa o desactiva con aprobación temporal.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[maintenance_tools_enabled]"
							value="1"
							<?php $print_sensitive( 'maintenance_tools_enabled' ); ?>
							<?php checked( ! empty( $settings['maintenance_tools_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Borrar plugins y temas', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Permite borrar plugins y temas desactivados. No se puede deshacer.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[extension_deletion_enabled]"
							value="1"
							<?php $print_sensitive( 'extension_deletion_enabled' ); ?>
							<?php checked( ! empty( $settings['extension_deletion_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<?php if ( $is_woocommerce ) : ?>
						<label class="nexo-mcp-toggle">
							<span class="nexo-mcp-toggle__text">
								<strong><?php esc_html_e( 'Herramientas WooCommerce', 'nexo-mcp' ); ?></strong>
								<small><?php esc_html_e( 'Productos simples, variables, precios, stock e imágenes.', 'nexo-mcp' ); ?></small>
							</span>
							<input
								type="checkbox"
								name="nexo_mcp_settings[woocommerce_tools_enabled]"
								value="1"
								<?php checked( ! empty( $settings['woocommerce_tools_enabled'] ) ); ?>
							>
							<i aria-hidden="true"></i>
						</label>
					<?php endif; ?>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Modo sólo lectura', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Impide cualquier creación o modificación.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[read_only]"
							value="1"
							<?php $print_sensitive( 'read_only' ); ?>
							<?php checked( ! empty( $settings['read_only'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Crear sólo borradores', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'La política prevalecerá sobre los permisos del usuario.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[drafts_only]"
							value="1"
							<?php $print_sensitive( 'drafts_only' ); ?>
							<?php checked( ! empty( $settings['drafts_only'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Editar lo publicado en una copia de trabajo', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Los cambios a páginas y entradas publicadas van primero a un borrador enlazado. Publícalo o descártalo desde la lista de páginas o entradas.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[working_copy_default]"
							value="1"
							<?php $print_sensitive( 'working_copy_default' ); ?>
							<?php checked( ! empty( $settings['working_copy_default'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Respetar los alcances de cada conexión', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Cada conexión OAuth sólo puede usar los permisos que se le concedieron (editables en Accesos). Los módulos del panel siguen siendo el límite superior.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[enforce_connection_scopes]"
							value="1"
							<?php $print_sensitive( 'enforce_connection_scopes' ); ?>
							<?php checked( ! empty( $settings['enforce_connection_scopes'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Purgar la caché al escribir', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Sin esto un cambio se guarda pero el visitante sigue viendo lo anterior. Purga sólo la página afectada, salvo en cambios globales.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[cache_purge_enabled]"
							value="1"
							<?php checked( ! empty( $settings['cache_purge_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Publicación remota', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Permite cambiar estados y publicar cuando el usuario tiene capacidad.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[publishing_enabled]"
							value="1"
							<?php $print_sensitive( 'publishing_enabled' ); ?>
							<?php checked( ! empty( $settings['publishing_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<label class="nexo-mcp-toggle nexo-mcp-toggle--sensitive">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'Acciones destructivas', 'nexo-mcp' ); ?> <em class="nexo-mcp-tag nexo-mcp-tag--sensitive"><?php esc_html_e( 'Sensible', 'nexo-mcp' ); ?></em></strong>
							<small><?php esc_html_e( 'Habilita eliminaciones explícitas. Déjalo apagado si no es imprescindible.', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[destructive_enabled]"
							value="1"
							<?php $print_sensitive( 'destructive_enabled' ); ?>
							<?php checked( ! empty( $settings['destructive_enabled'] ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>
				</div>

				<div class="nexo-mcp-proxy">
					<label class="nexo-mcp-toggle">
						<span class="nexo-mcp-toggle__text">
							<strong><?php esc_html_e( 'El sitio se publica por un túnel o proxy', 'nexo-mcp' ); ?></strong>
							<small><?php esc_html_e( 'Actívalo si tus clientes de IA llegan por una dirección distinta a la del sitio, como un túnel de desarrollo. Sin esto la autorización se rechaza con «invalid_target».', 'nexo-mcp' ); ?></small>
						</span>
						<input
							type="checkbox"
							name="nexo_mcp_settings[proxy_enabled]"
							value="1"
							<?php checked( '' !== (string) ( $settings['proxy_resource'] ?? '' ) ); ?>
						>
						<i aria-hidden="true"></i>
					</label>

					<p class="nexo-mcp-proxy-field">
						<label for="nexo-mcp-proxy-resource">
							<?php esc_html_e( 'Dirección pública del conector', 'nexo-mcp' ); ?>
						</label>
						<input
							type="url"
							id="nexo-mcp-proxy-resource"
							name="nexo_mcp_settings[proxy_resource]"
							value="<?php echo esc_attr( (string) ( $settings['proxy_resource'] ?? '' ) ); ?>"
							placeholder="https://tunnel-service.ejemplo.com/v1/mcp/tunnel_abc123"
							spellcheck="false"
							autocomplete="off"
						>
						<small>
							<?php
							printf(
								/* translators: %s: identificador canónico del conector. */
								esc_html__( 'La dirección exacta con la que tu cliente conoce este conector. Cópiala del error si lo viste, o del panel de tu túnel. Este sitio se identifica por su cuenta como %s.', 'nexo-mcp' ),
								'<code>' . esc_html( \NexoDev\NexoMCP\OAuth\DiscoveryController::resource_identifier() ) . '</code>'
							);
							?>
						</small>
					</p>
				</div>

				<?php submit_button( __( 'Guardar configuración', 'nexo-mcp' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
	</section>

	<?php /* ------------------------------------------------------ Sitio y cupos */ ?>
	<section
		id="nexo-mcp-panel-licencia"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="licencia"
		aria-labelledby="nexo-mcp-tablink-licencia"
		<?php echo 'licencia' === $active_tab ? '' : 'hidden'; ?>
	>
		<?php if ( ! $api_configured ) : ?>
		<section class="nexo-mcp-card nexo-mcp-api-missing">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Servicio externo', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title"><?php esc_html_e( 'Sin servicio de licencias', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'No configurado', 'nexo-mcp' ); ?></span>
			</div>
			<p class="nexo-mcp-callout"><?php esc_html_e( 'Este sitio no tiene un servidor de licencias configurado, así que no se pide clave ni registro: el conector funciona completo, con todas sus herramientas, sin límite de sitios.', 'nexo-mcp' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: línea de ejemplo para wp-config.php. */
					esc_html__( 'Para activarlo, agrega a wp-config.php: %s', 'nexo-mcp' ),
					'<code>' . esc_html( "define( 'NEXO_MCP_API_URL', 'https://tu-servidor.example/api' );" ) . '</code>'
				);
				?>
			</p>
		</section>
		<?php else : ?>
		<section class="nexo-mcp-card nexo-mcp-registration" aria-labelledby="nexo-mcp-licencia-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Licencia', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-licencia-title"><?php esc_html_e( 'Este sitio y tus cupos', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php echo esc_html( $license_plan_label ); ?></span>
			</div>

			<?php /* Sin licencia activa no se llega a esta vista: AdminPage muestra la activación. */ ?>
			<div class="nexo-mcp-box nexo-mcp-registration__verified">
				<div>
					<strong><?php echo esc_html( '' !== $site_host ? $site_host : $oauth_site_url ); ?></strong>
					<small>
						<?php
						if ( '' !== $license_sites_label ) {
							echo esc_html( $license_sites_label );
						} else {
							printf(
								/* translators: %s: fecha UTC de activación. */
								esc_html__( 'Activo desde el %s UTC', 'nexo-mcp' ),
								esc_html( $registration['verified_at'] )
							);
						}
						?>
					</small>
					<small>
						<?php
						echo '' !== $registration['license_prefix']
							? esc_html( $registration['license_prefix'] . '…' )
							: esc_html( $registration['email'] );
						?>
					</small>
				</div>
				<span class="nexo-mcp-status <?php echo $license_offline ? 'nexo-mcp-status--warning' : 'nexo-mcp-status--online'; ?>">
					<span aria-hidden="true"></span>
					<?php echo $license_offline ? esc_html__( 'Sin confirmar', 'nexo-mcp' ) : esc_html__( 'Activo', 'nexo-mcp' ); ?>
				</span>
			</div>

			<?php if ( $license_offline ) : ?>
				<p class="nexo-mcp-callout nexo-mcp-callout--warn nexo-mcp-license-offline">
					<?php
					printf(
						/* translators: %d: días restantes. */
						esc_html__( 'No se ha podido confirmar la licencia con el servidor. El conector sigue funcionando %d días más mientras se reintenta a diario.', 'nexo-mcp' ),
						(int) $license_grace_days
					);
					?>
				</p>
			<?php endif; ?>

			<div class="nexo-mcp-actions">
				<button class="button" type="button" data-nexo-license-action="recheck_license"><?php esc_html_e( 'Comprobar licencia', 'nexo-mcp' ); ?></button>
				<button class="button button-link-delete" type="button" data-nexo-license-action="release_license"><?php esc_html_e( 'Desconectar este sitio', 'nexo-mcp' ); ?></button>
			</div>

			<p class="nexo-mcp-registration__message" role="status" aria-live="polite"></p>
			<p class="description"><?php esc_html_e( 'Al desconectar, este sitio libera su cupo y deja de operar hasta que vuelvas a activarlo con una clave.', 'nexo-mcp' ); ?></p>
		</section>
		<?php endif; ?>
	</section>

	<?php /* ---------------------------------------------------- Actualizaciones */ ?>
	<section
		id="nexo-mcp-panel-actualizaciones"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="actualizaciones"
		aria-labelledby="nexo-mcp-tablink-actualizaciones"
		<?php echo 'actualizaciones' === $active_tab ? '' : 'hidden'; ?>
	>
		<?php if ( ! $api_configured ) : ?>
		<section class="nexo-mcp-card nexo-mcp-api-missing">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Servicio externo', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title"><?php esc_html_e( 'Sin canal de actualizaciones remoto', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'No configurado', 'nexo-mcp' ); ?></span>
			</div>
			<p class="nexo-mcp-callout"><?php esc_html_e( 'No hay un servidor de actualizaciones configurado. Actualiza Nexo MCP subiendo una versión nueva del ZIP desde Plugins › Añadir plugin.', 'nexo-mcp' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: línea de ejemplo para wp-config.php. */
					esc_html__( 'Para activarlo, agrega a wp-config.php: %s', 'nexo-mcp' ),
					'<code>' . esc_html( "define( 'NEXO_MCP_API_URL', 'https://tu-servidor.example/api' );" ) . '</code>'
				);
				?>
			</p>
		</section>
		<?php else : ?>
		<section class="nexo-mcp-card nexo-mcp-updates" aria-labelledby="nexo-mcp-updates-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Versiones', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-updates-title">
						<?php
						echo $update_available
							? esc_html__( 'Hay una versión nueva', 'nexo-mcp' )
							: esc_html__( 'Estás al día', 'nexo-mcp' );
						?>
					</h2>
				</div>
				<span class="nexo-mcp-tag"><?php echo esc_html( 'v' . NEXO_MCP_VERSION ); ?></span>
			</div>

			<div class="nexo-mcp-box">
				<div>
					<strong>
						<?php
						if ( $update_available ) {
							printf(
								/* translators: 1: versión instalada, 2: versión disponible. */
								esc_html__( '%1$s → %2$s', 'nexo-mcp' ),
								esc_html( NEXO_MCP_VERSION ),
								esc_html( $update['version'] )
							);
						} else {
							echo esc_html(
								'' !== $update['version'] ? $update['version'] : NEXO_MCP_VERSION
							);
						}
						?>
					</strong>
					<small>
						<?php
						if ( $update_available && '' !== $update['published'] ) {
							printf(
								/* translators: %s: fecha de publicación. */
								esc_html__( 'Publicada el %s', 'nexo-mcp' ),
								esc_html( gmdate( 'd-m-Y', (int) strtotime( $update['published'] ) ) )
							);
						} elseif ( '' !== $update['checked_at'] ) {
							printf(
								/* translators: %s: fecha UTC de la última comprobación. */
								esc_html__( 'Comprobado el %s UTC', 'nexo-mcp' ),
								esc_html( $update['checked_at'] )
							);
						} else {
							esc_html_e( 'Sin comprobar todavía', 'nexo-mcp' );
						}
						?>
					</small>
				</div>
				<span class="nexo-mcp-status <?php echo $update_available ? 'nexo-mcp-status--warning' : 'nexo-mcp-status--online'; ?>">
					<span aria-hidden="true"></span>
					<?php
					echo $update_available
						? esc_html__( 'Actualizable', 'nexo-mcp' )
						: esc_html__( 'Al día', 'nexo-mcp' );
					?>
				</span>
			</div>

			<?php if ( $update_available && array() !== $update_changelog ) : ?>
				<p class="nexo-mcp-eyebrow nexo-mcp-changelog__title">
					<?php esc_html_e( 'Qué trae', 'nexo-mcp' ); ?>
				</p>
				<ul class="nexo-mcp-changelog">
					<?php foreach ( $update_changelog as $linea ) : ?>
						<li><?php echo esc_html( $linea ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $update_available ) : ?>
				<div class="nexo-mcp-actions">
					<a class="button button-primary" href="<?php echo esc_url( $updates_url ); ?>">
						<?php esc_html_e( 'Ir a actualizar', 'nexo-mcp' ); ?>
					</a>
				</div>
				<p class="description">
					<?php esc_html_e( 'Se instala desde la pantalla de Plugins, como cualquier otro. Si tienes activadas las actualizaciones automáticas de WordPress, se instalará sola.', 'nexo-mcp' ); ?>
				</p>
			<?php else : ?>
				<?php
				/*
				 * El botón vive aquí y no sólo en la sección de licencia: quien
				 * quiere saber si hay algo nuevo mira las versiones, y
				 * «Comprobar licencia» no dice que también las busca.
				 */
				?>
				<div class="nexo-mcp-actions">
					<button class="button" type="button" data-nexo-check-updates>
						<?php esc_html_e( 'Buscar actualizaciones', 'nexo-mcp' ); ?>
					</button>
				</div>
				<p class="nexo-mcp-registration__message nexo-mcp-updates__message" role="status" aria-live="polite"></p>
				<p class="description">
					<?php esc_html_e( 'Las versiones nuevas llegan solas mientras tu licencia esté activa. Se comprueba una vez al día, y con este botón al momento.', 'nexo-mcp' ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php endif; ?>
	</section>

	<?php /* ------------------------------------------------------- Endpoint MCP */ ?>
	<section
		id="nexo-mcp-panel-endpoint"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="endpoint"
		aria-labelledby="nexo-mcp-tablink-endpoint"
		<?php echo 'endpoint' === $active_tab ? '' : 'hidden'; ?>
	>
		<section class="nexo-mcp-card" aria-labelledby="nexo-mcp-endpoint-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Conexión', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-endpoint-title"><?php esc_html_e( 'Endpoint MCP', 'nexo-mcp' ); ?></h2>
				</div>
			</div>

			<div class="nexo-mcp-endpoint">
				<code id="nexo-mcp-endpoint-url"><?php echo esc_html( $endpoint ); ?></code>
				<button class="button nexo-mcp-copy-endpoint" type="button" data-target="nexo-mcp-endpoint-url">
					<svg class="nexo-mcp-icon" viewBox="0 0 24 24" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
					<?php esc_html_e( 'Copiar', 'nexo-mcp' ); ?>
				</button>
			</div>
			<p class="description">
				<?php esc_html_e( 'Requiere HTTPS, una Application Password válida y un usuario con capacidades Nexo MCP.', 'nexo-mcp' ); ?>
			</p>
		</section>
	</section>

	<?php /* ---------------------------------------------------------- Actividad */ ?>
	<section
		id="nexo-mcp-panel-actividad"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="actividad"
		aria-labelledby="nexo-mcp-tablink-actividad"
		<?php echo 'actividad' === $active_tab ? '' : 'hidden'; ?>
	>
		<section class="nexo-mcp-card" aria-labelledby="nexo-mcp-actividad-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Trazabilidad', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-actividad-title"><?php esc_html_e( 'Actividad reciente', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'Sin secretos', 'nexo-mcp' ); ?></span>
			</div>

			<div class="nexo-mcp-table-wrap">
				<table class="nexo-mcp-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Fecha UTC', 'nexo-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Usuario', 'nexo-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Acción', 'nexo-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'nexo-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( array() === $audit_entries ) : ?>
							<tr>
								<td colspan="4" class="nexo-mcp-table__empty"><?php esc_html_e( 'Aún no hay actividad registrada.', 'nexo-mcp' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $audit_entries as $entry ) : ?>
								<tr>
									<td class="nexo-mcp-table__date"><?php echo esc_html( (string) $entry->occurred_at ); ?></td>
									<td class="nexo-mcp-table__user"><?php echo esc_html( $entry->user_id ? '#' . (string) $entry->user_id : '—' ); ?></td>
									<td><code><?php echo esc_html( (string) $entry->tool ); ?></code></td>
									<td><span class="nexo-mcp-event nexo-mcp-event--<?php echo esc_attr( sanitize_html_class( (string) $entry->status ) ); ?>"><?php echo esc_html( (string) $entry->status ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
	</section>

	<?php /* -------------------------------------------------- Reportar problema */ ?>
	<section
		id="nexo-mcp-panel-soporte"
		class="nexo-mcp-tabpanel"
		data-nexo-panel="soporte"
		aria-labelledby="nexo-mcp-tablink-soporte"
		<?php echo 'soporte' === $active_tab ? '' : 'hidden'; ?>
	>
		<?php if ( ! $api_configured ) : ?>
		<section class="nexo-mcp-card nexo-mcp-api-missing">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Servicio externo', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title"><?php esc_html_e( 'Sin servicio de reportes', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'No configurado', 'nexo-mcp' ); ?></span>
			</div>
			<p class="nexo-mcp-callout"><?php esc_html_e( 'No hay un servidor configurado para recibir reportes. Puedes describir el problema en el repositorio o canal donde obtuviste el plugin; adjunta la versión de WordPress, PHP y del plugin que ves en Resumen.', 'nexo-mcp' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: línea de ejemplo para wp-config.php. */
					esc_html__( 'Para activarlo, agrega a wp-config.php: %s', 'nexo-mcp' ),
					'<code>' . esc_html( "define( 'NEXO_MCP_API_URL', 'https://tu-servidor.example/api' );" ) . '</code>'
				);
				?>
			</p>
		</section>
		<?php else : ?>
		<section class="nexo-mcp-card" id="<?php echo esc_attr( \NexoDev\NexoMCP\Admin\ReportController::ANCHOR ); ?>" aria-labelledby="nexo-mcp-soporte-title">
			<div class="nexo-mcp-card__head">
				<div>
					<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Soporte', 'nexo-mcp' ); ?></p>
					<h2 class="nexo-mcp-card__title" id="nexo-mcp-soporte-title"><?php esc_html_e( 'Reportar un problema', 'nexo-mcp' ); ?></h2>
				</div>
				<span class="nexo-mcp-tag"><?php esc_html_e( 'Servicio configurado', 'nexo-mcp' ); ?></span>
			</div>

			<?php if ( null !== $report_notice ) : ?>
				<?php /* `inline` evita que common.js lo reubique junto al encabezado. */ ?>
				<div class="notice notice-<?php echo esc_attr( $report_notice['type'] ); ?> inline">
					<p><?php echo esc_html( $report_notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<p class="nexo-mcp-muted nexo-mcp-report__intro"><?php esc_html_e( 'Cuenta qué falló o qué te gustaría que hiciera el conector. El reporte se envía al servicio configurado en NEXO_MCP_API_URL.', 'nexo-mcp' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nexo-mcp-report__form">
				<input type="hidden" name="action" value="<?php echo esc_attr( \NexoDev\NexoMCP\Admin\ReportController::FORM_ACTION ); ?>">
				<?php wp_nonce_field( \NexoDev\NexoMCP\Admin\ReportController::NONCE_ACTION ); ?>

				<fieldset class="nexo-mcp-report__kind">
					<legend><?php esc_html_e( '¿Qué nos quieres contar?', 'nexo-mcp' ); ?></legend>
					<label>
						<input type="radio" name="kind" value="error" required <?php checked( 'error', $report_notice['kind'] ?? '' ); ?>>
						<span><?php esc_html_e( 'Algo falló', 'nexo-mcp' ); ?></span>
					</label>
					<label>
						<input type="radio" name="kind" value="sugerencia" required <?php checked( 'sugerencia', $report_notice['kind'] ?? '' ); ?>>
						<span><?php esc_html_e( 'Tengo una sugerencia', 'nexo-mcp' ); ?></span>
					</label>
				</fieldset>

				<label class="nexo-mcp-report__label" for="nexo-mcp-report-message"><?php esc_html_e( 'Cuéntame qué pasó', 'nexo-mcp' ); ?></label>
				<textarea
					id="nexo-mcp-report-message"
					name="message"
					rows="6"
					maxlength="<?php echo esc_attr( (string) $report_max_length ); ?>"
					minlength="<?php echo esc_attr( (string) $report_min_length ); ?>"
					required
					placeholder="<?php esc_attr_e( 'Qué intentabas hacer, qué esperabas y qué ocurrió en su lugar.', 'nexo-mcp' ); ?>"
				><?php echo esc_textarea( $report_notice['draft'] ?? '' ); ?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: %d: máximo de caracteres permitido. */
						esc_html__( 'Máximo %d caracteres.', 'nexo-mcp' ),
						(int) $report_max_length
					);
					?>
				</p>

				<div class="nexo-mcp-report__context">
					<strong><?php esc_html_e( 'Se envía junto a tu mensaje:', 'nexo-mcp' ); ?></strong>
					<ul>
						<li><?php echo esc_html( $registration['email'] ); ?></li>
						<li>
							<?php
							printf(
								/* translators: 1: versión del plugin, 2: versión de WordPress, 3: versión de PHP. */
								esc_html__( 'Plugin %1$s · WordPress %2$s · PHP %3$s', 'nexo-mcp' ),
								esc_html( NEXO_MCP_VERSION ),
								esc_html( get_bloginfo( 'version' ) ),
								esc_html( PHP_VERSION )
							);
							?>
						</li>
						<li>
							<?php
							echo esc_html(
								$report_builders
									? sprintf(
										/* translators: %s: lista de constructores activos. */
										__( 'Constructores activos: %s', 'nexo-mcp' ),
										implode( ', ', $report_builders )
									)
									: __( 'Sin constructores visuales activos', 'nexo-mcp' )
							);
							?>
						</li>
					</ul>
					<p class="description">
						<?php esc_html_e( 'Nunca se envían credenciales, tokens ni registros de actividad.', 'nexo-mcp' ); ?>
					</p>
				</div>

				<button class="button button-primary" type="submit">
					<?php esc_html_e( 'Enviar reporte', 'nexo-mcp' ); ?>
				</button>
			</form>
		</section>
		<?php endif; ?>
	</section>

	<p class="nexo-mcp-credit">
		<?php esc_html_e( 'Nexo MCP · software libre bajo GPL-2.0-or-later (ver NOTICE.md).', 'nexo-mcp' ); ?>
	</p>

	<?php
	/*
	 * Avisos breves (copias, revocaciones). Vive fuera de las pestañas para
	 * que se vea desde cualquiera de ellas; el navegador lo vacía solo.
	 */
	?>
	<p class="nexo-mcp-form-message nexo-mcp-toast" role="status" aria-live="polite" id="nexo-mcp-oauth-message"></p>

	<?php
	/*
	 * Confirmación de las opciones sensibles. Vive dentro del contenedor para
	 * heredar sus estilos y fuera de cualquier formulario: el de ajustes va a
	 * options.php y no admite otro anidado. Los textos de cada opción llegan en
	 * sus atributos data-* y el navegador los escribe con textContent.
	 */
	?>
	<dialog
		class="nexo-mcp-confirm"
		id="nexo-mcp-confirm"
		role="dialog"
		aria-modal="true"
		aria-labelledby="nexo-mcp-confirm-title"
		aria-describedby="nexo-mcp-confirm-text"
		data-label="<?php echo esc_attr( $confirm_label ); ?>"
	>
		<form method="dialog" class="nexo-mcp-confirm__form">
			<p class="nexo-mcp-eyebrow nexo-mcp-confirm__eyebrow"><?php esc_html_e( 'Opción sensible', 'nexo-mcp' ); ?></p>
			<h2 class="nexo-mcp-confirm__title" id="nexo-mcp-confirm-title"></h2>
			<p class="nexo-mcp-confirm__text" id="nexo-mcp-confirm-text"></p>
			<label class="nexo-mcp-confirm__label" for="nexo-mcp-confirm-input"></label>
			<input
				type="text"
				class="nexo-mcp-confirm__input"
				id="nexo-mcp-confirm-input"
				autocomplete="off"
				autocapitalize="off"
				spellcheck="false"
			>
			<div class="nexo-mcp-confirm__actions">
				<button type="button" class="button nexo-mcp-confirm__cancel"><?php esc_html_e( 'Cancelar', 'nexo-mcp' ); ?></button>
				<button type="submit" class="button nexo-mcp-confirm__accept" disabled><?php esc_html_e( 'Confirmar', 'nexo-mcp' ); ?></button>
			</div>
		</form>
	</dialog>
</div>

<dialog
	class="nexo-mcp-secret-dialog"
	id="nexo-mcp-secret-dialog"
	data-endpoint="<?php echo esc_attr( $endpoint ); ?>"
>
	<form method="dialog">
		<button class="nexo-mcp-dialog-close" aria-label="<?php esc_attr_e( 'Cerrar', 'nexo-mcp' ); ?>">×</button>
	</form>
	<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Visible una sola vez', 'nexo-mcp' ); ?></p>
	<h2 id="nexo-mcp-secret-title"><?php esc_html_e( 'Conexión creada', 'nexo-mcp' ); ?></h2>
	<p id="nexo-mcp-secret-message"></p>
	<label><?php esc_html_e( 'Usuario WordPress', 'nexo-mcp' ); ?></label>
	<code id="nexo-mcp-secret-username"></code>
	<label for="nexo-mcp-secret-value"><?php esc_html_e( 'Application Password', 'nexo-mcp' ); ?></label>
	<div class="nexo-mcp-secret-field">
		<input
			id="nexo-mcp-secret-value"
			type="password"
			readonly
			autocomplete="new-password"
			spellcheck="false"
			aria-describedby="nexo-mcp-secret-copy-status"
		>
		<button
			class="nexo-mcp-secret-visibility"
			id="nexo-mcp-toggle-secret"
			type="button"
			aria-label="<?php esc_attr_e( 'Mostrar contraseña', 'nexo-mcp' ); ?>"
			aria-pressed="false"
		>
			<svg class="nexo-mcp-eye nexo-mcp-eye--open" viewBox="0 0 24 24" aria-hidden="true">
				<path d="M2.4 12s3.5-6 9.6-6 9.6 6 9.6 6-3.5 6-9.6 6-9.6-6-9.6-6Z"></path>
				<circle cx="12" cy="12" r="2.8"></circle>
			</svg>
			<svg class="nexo-mcp-eye nexo-mcp-eye--closed" viewBox="0 0 24 24" aria-hidden="true">
				<path d="m3 3 18 18M10.6 6.2A9.8 9.8 0 0 1 12 6c6.1 0 9.6 6 9.6 6a15.5 15.5 0 0 1-2.6 3.2M6.2 6.2C3.7 8 2.4 12 2.4 12s3.5 6 9.6 6c1.5 0 2.8-.4 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
			</svg>
		</button>
	</div>
	<div class="nexo-mcp-secret-actions">
		<button class="button button-primary" id="nexo-mcp-copy-secret" type="button">
			<?php esc_html_e( 'Copiar contraseña', 'nexo-mcp' ); ?>
		</button>
		<span id="nexo-mcp-secret-copy-status" role="status" aria-live="polite"></span>
	</div>

	<section class="nexo-mcp-setup" aria-labelledby="nexo-mcp-setup-title">
		<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Último paso', 'nexo-mcp' ); ?></p>
		<h3 id="nexo-mcp-setup-title"><?php esc_html_e( 'Copia esto en tu IA', 'nexo-mcp' ); ?></h3>
		<p>
			<?php esc_html_e( 'La instrucción ya incluye el cliente elegido, el endpoint y esta credencial.', 'nexo-mcp' ); ?>
		</p>

		<div class="nexo-mcp-setup-client">
			<span><?php esc_html_e( 'Preparado para', 'nexo-mcp' ); ?></span>
			<strong id="nexo-mcp-selected-client"><?php esc_html_e( 'Codex', 'nexo-mcp' ); ?></strong>
		</div>

		<label for="nexo-mcp-setup-preview"><?php esc_html_e( 'Instrucción lista', 'nexo-mcp' ); ?></label>
		<textarea
			id="nexo-mcp-setup-preview"
			readonly
			spellcheck="false"
			aria-describedby="nexo-mcp-setup-note nexo-mcp-setup-copy-status"
		></textarea>

		<div class="nexo-mcp-setup__actions">
			<button class="button button-primary" id="nexo-mcp-copy-setup" type="button">
				<?php esc_html_e( 'Copiar instrucción', 'nexo-mcp' ); ?>
			</button>
			<span id="nexo-mcp-setup-copy-status" role="status" aria-live="polite"></span>
		</div>

		<p class="nexo-mcp-setup__note" id="nexo-mcp-setup-note">
			<?php esc_html_e( 'La copia contiene la credencial real. Pégala sólo en el cliente de IA que vas a configurar.', 'nexo-mcp' ); ?>
		</p>
	</section>
</dialog>
