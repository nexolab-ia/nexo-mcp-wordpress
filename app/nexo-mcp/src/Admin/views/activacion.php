<?php
/**
 * Pantalla de activación por licencia.
 *
 * Es lo primero que ve quien instala el plugin: sin una licencia activa el
 * conector no opera y el panel no se muestra. Un solo campo, un solo paso.
 *
 * @package NexoDev\NexoMCP
 *
 * @var bool $grace_expired  Si se agotó el margen sin contacto con la plataforma.
 * @var bool $custom_endpoint Si el plugin apunta a un servidor distinto del oficial.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexo-mcp-admin nexo-mcp-onboarding">
	<?php
	/*
	 * WordPress mueve los avisos de todos los plugins hasta justo antes de este
	 * marcador. Sin él los coloca tras el primer <h1>, que aquí vive dentro de la
	 * tarjeta, y un aviso ajeno aparece incrustado junto a «Activa tu licencia».
	 */
	?>
	<hr class="wp-header-end">

	<div class="nexo-mcp-onboarding__sheet">
		<aside class="nexo-mcp-onboarding__aside">
			<div class="nexo-mcp-onboarding__brand">
				<img
					src="<?php echo esc_url( plugins_url( 'assets/images/logo-nexo.svg', NEXO_MCP_FILE ) ); ?>"
					alt="<?php esc_attr_e( 'Nexo MCP', 'nexo-mcp' ); ?>"
					width="153"
					height="28"
				>
			</div>

			<ol class="nexo-mcp-onboarding__steps">
				<li class="is-current">
					<span>01</span>
					<div>
						<strong><?php esc_html_e( 'Activa tu licencia', 'nexo-mcp' ); ?></strong>
						<small><?php esc_html_e( 'La clave que te entregó tu proveedor', 'nexo-mcp' ); ?></small>
					</div>
				</li>
				<li>
					<span>02</span>
					<div>
						<strong><?php esc_html_e( 'Conecta tu IA', 'nexo-mcp' ); ?></strong>
						<small><?php esc_html_e( 'Copias la dirección y autorizas', 'nexo-mcp' ); ?></small>
					</div>
				</li>
				<li>
					<span>03</span>
					<div>
						<strong><?php esc_html_e( 'Trabaja en WordPress', 'nexo-mcp' ); ?></strong>
						<small><?php esc_html_e( 'Herramientas con permisos', 'nexo-mcp' ); ?></small>
					</div>
				</li>
			</ol>

			<p class="nexo-mcp-onboarding__version">
				<?php echo esc_html( 'v' . NEXO_MCP_VERSION ); ?>
			</p>
		</aside>

		<main class="nexo-mcp-onboarding__main">
			<p class="nexo-mcp-eyebrow"><?php esc_html_e( 'Paso 01 de 03', 'nexo-mcp' ); ?></p>

			<?php if ( $grace_expired ) : ?>
				<h1><?php esc_html_e( 'Hay que volver a confirmar tu licencia', 'nexo-mcp' ); ?></h1>
				<p class="nexo-mcp-onboarding__lead">
					<?php esc_html_e( 'Pasaron dos semanas sin que pudiéramos confirmar tu licencia con el servidor. Vuelve a activarla para seguir trabajando: si el problema era de conexión, se resuelve al primer intento.', 'nexo-mcp' ); ?>
				</p>
			<?php else : ?>
				<h1><?php esc_html_e( 'Activa tu licencia', 'nexo-mcp' ); ?></h1>
				<p class="nexo-mcp-onboarding__lead">
					<?php esc_html_e( 'Pega la clave de licencia que te entregó el proveedor del servicio configurado en NEXO_MCP_API_URL. Con eso este sitio queda habilitado y puedes empezar a trabajar.', 'nexo-mcp' ); ?>
				</p>
			<?php endif; ?>

			<form id="nexo-mcp-license-form" class="nexo-mcp-registration__form">
				<label for="nexo-mcp-license-key"><?php esc_html_e( 'Clave de licencia', 'nexo-mcp' ); ?></label>
				<input
					id="nexo-mcp-license-key"
					name="license_key"
					type="text"
					inputmode="text"
					autocomplete="off"
					spellcheck="false"
					placeholder="PMCP-XXXXX-XXXXX-XXXXX-XXXXX"
					maxlength="40"
					required
				>
				<button class="button button-primary button-hero" type="submit">
					<?php esc_html_e( 'Activar este sitio', 'nexo-mcp' ); ?>
				</button>
			</form>

			<p class="nexo-mcp-registration__message" role="status" aria-live="polite"></p>

			<p class="nexo-mcp-onboarding__note">
				<?php esc_html_e( 'Tu licencia sirve para varios sitios. Los entornos locales y de staging no consumen ninguno.', 'nexo-mcp' ); ?>
			</p>

			<p class="nexo-mcp-onboarding__note">
				<?php esc_html_e( '¿No usas un servicio de licencias? Quita la constante NEXO_MCP_API_URL de wp-config.php y el conector funcionará completo sin clave.', 'nexo-mcp' ); ?>
			</p>

			<?php if ( $custom_endpoint ) : ?>
				<p class="nexo-mcp-onboarding__note">
					<strong><?php esc_html_e( 'Ojo:', 'nexo-mcp' ); ?></strong>
					<?php esc_html_e( 'este sitio valida su licencia contra el servidor definido en NEXO_MCP_API_URL.', 'nexo-mcp' ); ?>
				</p>
			<?php endif; ?>
		</main>
	</div>

	<p class="nexo-mcp-credit">
		<?php esc_html_e( 'Nexo MCP · software libre bajo GPL-2.0-or-later.', 'nexo-mcp' ); ?>
	</p>
</div>
