<?php
/**
 * Mantenimiento controlado de plugins.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\WordPress;

use NexoDev\NexoMCP\Support\Settings;

/**
 * Prepara y ejecuta una única acción aprobada con APIs nativas de WordPress.
 */
final class MaintenanceGateway {

	private const PLAN_PREFIX = 'nexo_mcp_maintenance_';

	private const LOCK_OPTION = 'nexo_mcp_maintenance_lock';

	/**
	 * Lista plugins y actualizaciones sin exponer rutas absolutas.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_plugins(): array {
		$this->load_plugin_api();
		wp_update_plugins();
		$plugins = get_plugins();
		$updates = get_site_transient( 'update_plugins' );

		return array_map(
			static function ( array $plugin, string $file ) use ( $updates ): array {
				$update = is_object( $updates ) && isset( $updates->response[ $file ] )
					? $updates->response[ $file ]
					: null;

				return array(
					'file'             => $file,
					'name'             => (string) ( $plugin['Name'] ?? '' ),
					'version'          => (string) ( $plugin['Version'] ?? '' ),
					'active'           => is_plugin_active( $file ),
					'network_active'   => is_multisite() && is_plugin_active_for_network( $file ),
					'update_available' => is_object( $update ),
					'new_version'      => is_object( $update ) ? (string) ( $update->new_version ?? '' ) : '',
					'requires_php'     => is_object( $update ) ? (string) ( $update->requires_php ?? '' ) : '',
					'requires_wp'      => is_object( $update ) ? (string) ( $update->requires ?? '' ) : '',
				);
			},
			$plugins,
			array_keys( $plugins )
		);
	}

	/**
	 * Lista temas instalados y sus actualizaciones.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_themes(): array {
		$this->load_theme_api();
		wp_update_themes();

		$updates = get_site_transient( 'update_themes' );
		$active  = wp_get_theme();
		$listado = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$pendiente = is_object( $updates ) && isset( $updates->response[ $stylesheet ] )
				? $updates->response[ $stylesheet ]
				: null;

			$listado[] = array(
				'stylesheet'       => (string) $stylesheet,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => (string) $theme->get( 'Version' ),
				'active'           => $active instanceof \WP_Theme && $active->get_stylesheet() === $stylesheet,
				'parent'           => (string) ( $theme->parent() ? $theme->parent()->get_stylesheet() : '' ),
				'update_available' => is_array( $pendiente ) || is_object( $pendiente ),
				'new_version'      => is_array( $pendiente ) ? (string) ( $pendiente['new_version'] ?? '' ) : '',
			);
		}

		return $listado;
	}

	/**
	 * Prepara una acción y entrega un token de un solo uso.
	 *
	 * @param array<string, mixed> $input Acción.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function prepare_action( array $input ) {
		$action = (string) $input['action'];
		$target = (string) ( $input['target'] ?? 'plugin' );

		if ( ! in_array( $action, array( 'update', 'activate', 'deactivate', 'install', 'delete' ), true ) ) {
			return new \WP_Error( 'nexo_mcp_maintenance_action_denied', __( 'Acción de mantenimiento no permitida.', 'nexo-mcp' ) );
		}

		if ( ! in_array( $target, array( 'plugin', 'theme' ), true ) ) {
			return new \WP_Error( 'nexo_mcp_maintenance_target_denied', __( 'Sólo se admiten plugins y temas.', 'nexo-mcp' ) );
		}

		if ( 'delete' === $action ) {
			$denegado = $this->deletion_denied( $target );

			if ( null !== $denegado ) {
				return $denegado;
			}
		}

		if ( 'theme' === $target ) {
			return $this->prepare_theme_action( $action, $input );
		}

		$this->load_plugin_api();
		$plan = array(
			'action'     => $action,
			'target'     => 'plugin',
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
		);

		if ( 'install' === $action ) {
			$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );

			if ( '' === $slug || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
				return new \WP_Error( 'nexo_mcp_plugin_slug_invalid', __( 'Slug de plugin inválido.', 'nexo-mcp' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) || ! is_object( $api ) || empty( $api->download_link ) ) {
				return is_wp_error( $api )
					? $api
					: new \WP_Error( 'nexo_mcp_plugin_not_found', __( 'No se encontró el plugin en WordPress.org.', 'nexo-mcp' ) );
			}

			$download_host = (string) wp_parse_url( (string) $api->download_link, PHP_URL_HOST );

			if ( ! str_ends_with( $download_host, '.wordpress.org' ) ) {
				return new \WP_Error( 'nexo_mcp_plugin_source_denied', __( 'La descarga no pertenece a WordPress.org.', 'nexo-mcp' ) );
			}

			$plan['slug']          = $slug;
			$plan['name']          = sanitize_text_field( (string) ( $api->name ?? $slug ) );
			$plan['version']       = sanitize_text_field( (string) ( $api->version ?? '' ) );
			$plan['download_link'] = esc_url_raw( (string) $api->download_link, array( 'https' ) );
		} else {
			$plugin_file = plugin_basename( sanitize_text_field( (string) ( $input['plugin'] ?? '' ) ) );
			$plugins     = get_plugins();

			if ( ! isset( $plugins[ $plugin_file ] ) || 0 !== validate_plugin( $plugin_file ) ) {
				return new \WP_Error( 'nexo_mcp_plugin_not_found', __( 'No se encontró el plugin solicitado.', 'nexo-mcp' ) );
			}

			if ( plugin_basename( NEXO_MCP_FILE ) === $plugin_file ) {
				return new \WP_Error( 'nexo_mcp_self_maintenance_denied', __( 'Nexo MCP no puede modificarse durante su propia solicitud.', 'nexo-mcp' ) );
			}

			if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
				return new \WP_Error( 'nexo_mcp_network_plugin_denied', __( 'Los plugins activos para toda la red deben gestionarse manualmente desde el administrador de red.', 'nexo-mcp' ) );
			}

			$plan['plugin']  = $plugin_file;
			$plan['name']    = sanitize_text_field( (string) ( $plugins[ $plugin_file ]['Name'] ?? $plugin_file ) );
			$plan['version'] = sanitize_text_field( (string) ( $plugins[ $plugin_file ]['Version'] ?? '' ) );

			if ( 'update' === $action ) {
				wp_update_plugins();
				$updates = get_site_transient( 'update_plugins' );

				if ( ! is_object( $updates ) || ! isset( $updates->response[ $plugin_file ] ) ) {
					return new \WP_Error( 'nexo_mcp_plugin_up_to_date', __( 'El plugin no tiene una actualización disponible.', 'nexo-mcp' ) );
				}

				$update              = $updates->response[ $plugin_file ];
				$plan['new_version'] = sanitize_text_field( (string) ( $update->new_version ?? '' ) );
			}

			if ( 'activate' === $action && is_plugin_active( $plugin_file ) ) {
				return new \WP_Error( 'nexo_mcp_plugin_already_active', __( 'El plugin ya está activo.', 'nexo-mcp' ) );
			}

			if ( 'deactivate' === $action && ! is_plugin_active( $plugin_file ) ) {
				return new \WP_Error( 'nexo_mcp_plugin_already_inactive', __( 'El plugin ya está inactivo.', 'nexo-mcp' ) );
			}

			if ( 'delete' === $action ) {
				$activo = $this->plugin_active_denied( $plugin_file );

				if ( null !== $activo ) {
					return $activo;
				}

				$plan['warning'] = __( 'Borrar un plugin elimina sus archivos del servidor y no se puede deshacer. Si trae desinstalador, WordPress lo ejecuta y también se borran sus datos y ajustes.', 'nexo-mcp' );
			}
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( $this->plan_key( $token ), $plan, 10 * MINUTE_IN_SECONDS );

		return $this->approval( $token, $plan );
	}

	/**
	 * Prepara una acción sobre un tema.
	 *
	 * Un tema no se «desactiva»: o está activo o no lo está, así que esa acción
	 * se rechaza en vez de traducirla a algo que el cliente no pidió.
	 *
	 * @param string               $action Acción solicitada.
	 * @param array<string, mixed> $input  Entrada validada.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function prepare_theme_action( string $action, array $input ) {
		if ( 'deactivate' === $action ) {
			return new \WP_Error(
				'nexo_mcp_theme_deactivate_denied',
				__( 'Un tema no se desactiva: se cambia activando otro.', 'nexo-mcp' )
			);
		}

		$this->load_theme_api();

		$plan = array(
			'action'     => $action,
			'target'     => 'theme',
			'user_id'    => get_current_user_id(),
			'created_at' => time(),
		);

		if ( 'install' === $action ) {
			$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );

			if ( '' === $slug || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
				return new \WP_Error( 'nexo_mcp_theme_slug_invalid', __( 'Slug de tema inválido.', 'nexo-mcp' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/theme.php';
			$api = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) || ! is_object( $api ) || empty( $api->download_link ) ) {
				return is_wp_error( $api )
					? $api
					: new \WP_Error( 'nexo_mcp_theme_not_found', __( 'No se encontró el tema en WordPress.org.', 'nexo-mcp' ) );
			}

			$download_host = (string) wp_parse_url( (string) $api->download_link, PHP_URL_HOST );

			if ( ! str_ends_with( $download_host, '.wordpress.org' ) ) {
				return new \WP_Error( 'nexo_mcp_theme_source_denied', __( 'La descarga no pertenece a WordPress.org.', 'nexo-mcp' ) );
			}

			$plan['slug']          = $slug;
			$plan['name']          = sanitize_text_field( (string) ( $api->name ?? $slug ) );
			$plan['version']       = sanitize_text_field( (string) ( $api->version ?? '' ) );
			$plan['download_link'] = esc_url_raw( (string) $api->download_link, array( 'https' ) );

			$token = wp_generate_password( 32, false, false );
			set_transient( $this->plan_key( $token ), $plan, 10 * MINUTE_IN_SECONDS );

			return $this->approval( $token, $plan );
		}

		$stylesheet = sanitize_text_field( (string) ( $input['theme'] ?? '' ) );
		$theme      = wp_get_theme( $stylesheet );

		if ( '' === $stylesheet || ! $theme->exists() ) {
			return new \WP_Error( 'nexo_mcp_theme_not_found', __( 'No se encontró el tema solicitado.', 'nexo-mcp' ) );
		}

		$activo = wp_get_theme();

		$plan['theme']   = $theme->get_stylesheet();
		$plan['name']    = sanitize_text_field( (string) $theme->get( 'Name' ) );
		$plan['version'] = sanitize_text_field( (string) $theme->get( 'Version' ) );
		$plan['active']  = $activo instanceof \WP_Theme && $activo->get_stylesheet() === $theme->get_stylesheet();

		if ( 'update' === $action ) {
			wp_update_themes();
			$updates = get_site_transient( 'update_themes' );

			if ( ! is_object( $updates ) || ! isset( $updates->response[ $theme->get_stylesheet() ] ) ) {
				return new \WP_Error( 'nexo_mcp_theme_up_to_date', __( 'El tema no tiene una actualización disponible.', 'nexo-mcp' ) );
			}

			$plan['new_version'] = sanitize_text_field( (string) ( $updates->response[ $theme->get_stylesheet() ]['new_version'] ?? '' ) );
		}

		if ( 'activate' === $action ) {
			if ( $plan['active'] ) {
				return new \WP_Error( 'nexo_mcp_theme_already_active', __( 'El tema ya está activo.', 'nexo-mcp' ) );
			}

			// Cambiar de tema rehace la portada entera. Se avisa en el plan para
			// que quien apruebe sepa que no es una actualización menor.
			$plan['warning'] = __( 'Activar otro tema cambia por completo el aspecto del sitio y puede dejar widgets y menús sin asignar.', 'nexo-mcp' );
		}

		if ( 'delete' === $action ) {
			$en_uso = $this->theme_in_use_denied( $plan['theme'] );

			if ( null !== $en_uso ) {
				return $en_uso;
			}

			$plan['warning'] = __( 'Borrar un tema elimina sus archivos del servidor y no se puede deshacer.', 'nexo-mcp' );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient( $this->plan_key( $token ), $plan, 10 * MINUTE_IN_SECONDS );

		return $this->approval( $token, $plan );
	}

	/**
	 * Da forma a la respuesta de aprobación.
	 *
	 * @param string               $token Token generado.
	 * @param array<string, mixed> $plan  Plan almacenado.
	 * @return array<string, mixed>
	 */
	private function approval( string $token, array $plan ): array {
		return array(
			'approval_token' => $token,
			'expires_in'     => 10 * MINUTE_IN_SECONDS,
			'plan'           => $this->public_plan( $plan ),
			'confirmation'   => __( 'Revisa el plan y usa execute-plugin-action con este token sólo si apruebas la operación.', 'nexo-mcp' ),
		);
	}

	/**
	 * Ejecuta una acción preparada y consume su token.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_action( string $token ) {
		$key  = $this->plan_key( $token );
		$plan = get_transient( $key );

		if ( ! is_array( $plan ) || (int) ( $plan['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'nexo_mcp_approval_invalid', __( 'La aprobación no existe, expiró o pertenece a otro usuario.', 'nexo-mcp' ) );
		}

		delete_transient( $key );

		$existing_lock = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $existing_lock > 0 && $existing_lock < time() - 15 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK_OPTION );
		}

		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return new \WP_Error( 'nexo_mcp_maintenance_locked', __( 'Ya existe otra operación de mantenimiento en curso.', 'nexo-mcp' ) );
		}

		try {
			$result = $this->run_action( $plan );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		return $result;
	}

	/**
	 * Ejecuta mediante APIs nativas.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_action( array $plan ) {
		if ( 'theme' === ( $plan['target'] ?? 'plugin' ) ) {
			return $this->run_theme_action( $plan );
		}

		$this->load_plugin_api();
		$action = (string) $plan['action'];
		$plugin = (string) ( $plan['plugin'] ?? '' );

		// Hay que saber cómo estaba antes de tocarlo. Al actualizar, WordPress
		// desactiva el plugin y vuelve a activarlo con los archivos nuevos; si
		// esa recarga provoca un error fatal, lo deja apagado a propósito para
		// que el sitio no caiga entero. Sin este dato no hay forma de separar
		// «se actualizó y sigue andando» de «se actualizó y quedó muerto».
		$activo_antes = 'update' === $action && '' !== $plugin && is_plugin_active( $plugin );

		if ( 'activate' === $action ) {
			$result = activate_plugin( (string) $plan['plugin'], '', is_multisite() && is_network_admin(), true );
		} elseif ( 'deactivate' === $action ) {
			deactivate_plugins( (string) $plan['plugin'], false, is_multisite() && is_network_admin() );
			$result = true;
		} elseif ( 'delete' === $action ) {
			$result = $this->run_plugin_deletion( $plugin );
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = 'install' === $action
				? $upgrader->install( (string) $plan['download_link'] )
				: $upgrader->upgrade( (string) $plan['plugin'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		wp_clean_plugins_cache( true );

		// No se juzga por el valor de retorno sino por el estado que quedó. Las
		// funciones de WordPress no coinciden entre sí: `activate_plugin()`
		// devuelve null cuando activa bien, `deactivate_plugins()` no devuelve
		// nada, y el actualizador puede devolver false tanto por un fallo como
		// porque no había nada que hacer. Leer ese retorno como booleano hacía
		// que el conector informara error sobre una operación que sí ocurrió, y
		// lo natural entonces es reintentar algo que ya pasó.
		$confirmado = $this->plugin_action_confirmada( $action, $plan );

		if ( ! $confirmado ) {
			return new \WP_Error( 'nexo_mcp_maintenance_failed', __( 'WordPress no pudo completar la operación.', 'nexo-mcp' ) );
		}

		// `Plugin_Upgrader` apaga el plugin antes de reemplazar los archivos, en
		// `deactivate_plugin_before_upgrade()`, y **no vuelve a encenderlo**. El
		// core lo dice en su propio comentario: da por hecho que lo hará el
		// navegador desde wp-admin. Aquí no hay navegador, así que reactivarlo
		// es parte del trabajo y no una temeridad.
		//
		// Sin esto, cualquier actualización dejaba el plugin muerto informando
		// éxito: la versión en disco sí había cambiado, que era lo único que se
		// comprobaba, y la portada respondía 200 igual porque un WordPress sin
		// su tienda sigue sirviendo el blog.
		if ( $activo_antes && ! is_plugin_active( $plugin ) ) {
			// En silencio, como hace el propio wp-admin al terminar: el plugin
			// ya estaba activo, así que esto no es una activación nueva y sus
			// hooks de instalación no deben volver a correr.
			$reactivado = activate_plugin( $plugin, '', is_multisite() && is_network_admin(), true );

			if ( is_wp_error( $reactivado ) || ! is_plugin_active( $plugin ) ) {
				$instalados = get_plugins();

				return new \WP_Error(
					'nexo_mcp_maintenance_left_inactive',
					sprintf(
						/* translators: 1: nombre del plugin, 2: versión anterior, 3: versión instalada. */
						__( 'La actualización de %1$s se instaló (%2$s → %3$s), pero no se pudo volver a activar y el sitio quedó sin él. Suele significar que la versión nueva falla al cargarse. Revisa el sitio antes de insistir; si está sano, prueba a activarlo con la acción «activate». Reintentar la actualización no sirve, porque ya se aplicó.', 'nexo-mcp' ),
						(string) ( $instalados[ $plugin ]['Name'] ?? $plugin ),
						(string) ( $plan['version'] ?? '' ),
						(string) ( $instalados[ $plugin ]['Version'] ?? '' )
					),
					array(
						'plugin'       => $plugin,
						'from_version' => (string) ( $plan['version'] ?? '' ),
						'to_version'   => (string) ( $instalados[ $plugin ]['Version'] ?? '' ),
						'updated'      => true,
						'active'       => false,
						'reason'       => is_wp_error( $reactivado ) ? $reactivado->get_error_message() : '',
					) + $this->site_health()
				);
			}
		}

		$health = wp_safe_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 2,
			)
		);

		$response_code = is_wp_error( $health ) ? 0 : wp_remote_retrieve_response_code( $health );

		return array(
			'success'      => true,
			'action'       => $action,
			'plugin'       => (string) ( $plan['plugin'] ?? $plan['slug'] ?? '' ),
			'from_version' => (string) ( $plan['version'] ?? '' ),
			'to_version'   => (string) ( $plan['new_version'] ?? $plan['version'] ?? '' ),
			// Se informa siempre, no sólo cuando algo va mal: es el dato que
			// distingue una actualización que quedó andando de una que no, y
			// tenerlo a la vista evita depender de una llamada posterior.
			'active'       => '' !== $plugin && is_plugin_active( $plugin ),
			'site_health'  => $response_code >= 200 && $response_code < 500 ? 'reachable' : 'check_required',
			'http_status'  => $response_code,
		);
	}

	/**
	 * Comprueba en el sitio que la acción sobre un plugin surtió efecto.
	 *
	 * @param string               $action Acción ejecutada.
	 * @param array<string, mixed> $plan   Plan aprobado.
	 */
	private function plugin_action_confirmada( string $action, array $plan ): bool {
		$plugin = (string) ( $plan['plugin'] ?? '' );

		if ( 'activate' === $action ) {
			return is_plugin_active( $plugin );
		}

		if ( 'deactivate' === $action ) {
			return ! is_plugin_active( $plugin );
		}

		if ( 'update' === $action ) {
			$instalados = get_plugins();
			$actual     = (string) ( $instalados[ $plugin ]['Version'] ?? '' );
			$esperada   = (string) ( $plan['new_version'] ?? '' );

			// Basta con que ya no sea la versión de partida: si el repositorio
			// publicó otra mientras corría la operación, exigir la esperada
			// declararía fallida una actualización que sí ocurrió.
			$cambio  = '' !== $actual && (string) ( $plan['version'] ?? '' ) !== $actual;
			$llegada = '' !== $esperada && $esperada === $actual;

			return $cambio || $llegada;
		}

		if ( 'install' === $action ) {
			$slug = (string) ( $plan['slug'] ?? '' );

			foreach ( array_keys( get_plugins() ) as $archivo ) {
				if ( str_starts_with( (string) $archivo, $slug . '/' ) ) {
					return true;
				}
			}

			return false;
		}

		if ( 'delete' === $action ) {
			return ! isset( get_plugins()[ $plugin ] );
		}

		return false;
	}

	/**
	 * Borra un plugin desactivado.
	 *
	 * Todo se vuelve a comprobar aquí porque entre preparar y ejecutar pasan
	 * hasta diez minutos: el dueño pudo apagar el interruptor o alguien pudo
	 * activar el plugin, y una aprobación vieja no debe borrar algo que ya no
	 * cumple las condiciones con que se aprobó.
	 *
	 * @param string $plugin Archivo principal del plugin.
	 * @return true|\WP_Error
	 */
	private function run_plugin_deletion( string $plugin ) {
		$denegado = $this->deletion_denied( 'plugin' ) ?? $this->plugin_active_denied( $plugin );

		if ( null !== $denegado ) {
			return $denegado;
		}

		if ( ! $this->filesystem_is_direct() ) {
			return $this->filesystem_denied();
		}

		return $this->deletion_result( delete_plugins( array( $plugin ) ) );
	}

	/**
	 * Ejecuta la acción sobre un tema con las APIs nativas.
	 *
	 * @param array<string, mixed> $plan Plan aprobado.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function run_theme_action( array $plan ) {
		$this->load_theme_api();
		$action = (string) $plan['action'];

		if ( 'activate' === $action ) {
			switch_theme( (string) $plan['theme'] );
			$result = true;
		} elseif ( 'delete' === $action ) {
			$result = $this->run_theme_deletion( (string) $plan['theme'] );
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = 'install' === $action
				? $upgrader->install( (string) $plan['download_link'] )
				: $upgrader->upgrade( (string) $plan['theme'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		wp_clean_themes_cache( true );

		// Igual que con los plugins: se comprueba el estado, no el retorno.
		// `switch_theme()` no devuelve nada, así que su éxito sólo se ve mirando
		// cuál quedó activo.
		if ( ! $this->theme_action_confirmada( $action, $plan ) ) {
			return new \WP_Error( 'nexo_mcp_maintenance_failed', __( 'WordPress no pudo completar la operación.', 'nexo-mcp' ) );
		}

		return array(
			'success'      => true,
			'action'       => $action,
			'target'       => 'theme',
			'theme'        => (string) ( $plan['theme'] ?? $plan['slug'] ?? '' ),
			'from_version' => (string) ( $plan['version'] ?? '' ),
			'to_version'   => (string) ( $plan['new_version'] ?? $plan['version'] ?? '' ),
		) + $this->site_health();
	}

	/**
	 * Comprueba en el sitio que la acción sobre un tema surtió efecto.
	 *
	 * @param string               $action Acción ejecutada.
	 * @param array<string, mixed> $plan   Plan aprobado.
	 */
	private function theme_action_confirmada( string $action, array $plan ): bool {
		$stylesheet = (string) ( $plan['theme'] ?? '' );

		if ( 'activate' === $action ) {
			$activo = wp_get_theme();

			return $activo instanceof \WP_Theme && $activo->get_stylesheet() === $stylesheet;
		}

		if ( 'install' === $action ) {
			return wp_get_theme( (string) ( $plan['slug'] ?? '' ) )->exists();
		}

		if ( 'update' === $action ) {
			$tema = wp_get_theme( $stylesheet );

			if ( ! $tema->exists() ) {
				return false;
			}

			$actual = (string) $tema->get( 'Version' );

			return '' !== $actual && (string) ( $plan['version'] ?? '' ) !== $actual;
		}

		if ( 'delete' === $action ) {
			return ! wp_get_theme( $stylesheet )->exists();
		}

		return false;
	}

	/**
	 * Borra un tema que no sostiene al sitio.
	 *
	 * Se vuelve a comprobar por lo mismo que en `run_plugin_deletion()`: pudo
	 * activarse este tema, o uno hijo suyo, después de aprobar el borrado.
	 *
	 * @param string $stylesheet Carpeta del tema.
	 * @return true|\WP_Error
	 */
	private function run_theme_deletion( string $stylesheet ) {
		$denegado = $this->deletion_denied( 'theme' ) ?? $this->theme_in_use_denied( $stylesheet );

		if ( null !== $denegado ) {
			return $denegado;
		}

		if ( ! $this->filesystem_is_direct() ) {
			return $this->filesystem_denied();
		}

		return $this->deletion_result( delete_theme( $stylesheet ) );
	}

	/**
	 * Comprueba las condiciones comunes para borrar un plugin o un tema.
	 *
	 * Borrar tiene su propio interruptor, aparte del de mantenimiento: quien
	 * deja que la IA actualice plugins no necesariamente quiere que los borre.
	 *
	 * @param string $target plugin o theme.
	 */
	private function deletion_denied( string $target ): ?\WP_Error {
		if ( ! ( new Settings() )->get( 'extension_deletion_enabled' ) ) {
			return new \WP_Error(
				'nexo_mcp_extension_deletion_disabled',
				__( 'Borrar plugins y temas está apagado en este sitio. El dueño puede encenderlo en el panel Nexo MCP › «Borrar plugins y temas».', 'nexo-mcp' )
			);
		}

		// En una red, un plugin o un tema es de todos los sitios, y borrarlo
		// desde uno solo los deja sin él. Eso se decide en el administrador de red.
		if ( is_multisite() ) {
			return new \WP_Error(
				'nexo_mcp_network_deletion_denied',
				__( 'En una red multisitio los plugins y temas se borran desde el administrador de red, no desde el conector.', 'nexo-mcp' )
			);
		}

		$capacidad = 'theme' === $target ? 'delete_themes' : 'delete_plugins';

		if ( ! current_user_can( $capacidad ) ) {
			return new \WP_Error(
				'nexo_mcp_deletion_capability_denied',
				sprintf(
					/* translators: %s: capacidad de WordPress. */
					__( 'Tu usuario de WordPress no tiene el permiso «%s».', 'nexo-mcp' ),
					$capacidad
				)
			);
		}

		return null;
	}

	/**
	 * Rechaza borrar un plugin activo.
	 *
	 * Borrarlo encendido tumba al instante todo lo que dependa de él. Apagarlo
	 * antes es un paso aparte que el dueño ve y puede revisar.
	 *
	 * @param string $plugin Archivo principal del plugin.
	 */
	private function plugin_active_denied( string $plugin ): ?\WP_Error {
		if ( ! is_plugin_active( $plugin ) ) {
			return null;
		}

		return new \WP_Error(
			'nexo_mcp_plugin_active_delete_denied',
			__( 'El plugin está activo. Desactívalo primero con prepare-plugin-action y la acción «deactivate», y después prepara el borrado.', 'nexo-mcp' )
		);
	}

	/**
	 * Rechaza borrar el tema activo o el padre del activo.
	 *
	 * @param string $stylesheet Carpeta del tema.
	 */
	private function theme_in_use_denied( string $stylesheet ): ?\WP_Error {
		if ( get_stylesheet() === $stylesheet ) {
			return new \WP_Error(
				'nexo_mcp_theme_active_delete_denied',
				__( 'Es el tema activo del sitio. Activa otro tema antes de borrarlo.', 'nexo-mcp' )
			);
		}

		if ( get_template() === $stylesheet ) {
			return new \WP_Error(
				'nexo_mcp_theme_parent_delete_denied',
				__( 'Es el tema padre del tema activo, que lo necesita para funcionar. No se puede borrar mientras el tema activo dependa de él.', 'nexo-mcp' )
			);
		}

		return null;
	}

	/**
	 * Indica si WordPress puede borrar archivos sin pedir credenciales.
	 *
	 * `delete_plugins()` y `delete_theme()` fueron hechas para wp-admin: si el
	 * servidor exige FTP, imprimen el formulario de credenciales y cortan la
	 * petición con `exit`. En una llamada MCP eso rompe la respuesta y deja a
	 * la IA sin saber qué pasó. Por eso sólo se borra cuando WordPress escribe
	 * directo en disco, aunque tenga las credenciales FTP en wp-config: un
	 * fallo al conectar volvería a pedir el formulario.
	 */
	private function filesystem_is_direct(): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		return 'direct' === get_filesystem_method();
	}

	/**
	 * Error de sistema de archivos que pide credenciales.
	 */
	private function filesystem_denied(): \WP_Error {
		return new \WP_Error(
			'nexo_mcp_filesystem_credentials_required',
			__( 'El servidor pide credenciales FTP para borrar archivos, y el conector no puede ingresarlas. No se borró nada: hazlo desde wp-admin.', 'nexo-mcp' )
		);
	}

	/**
	 * Interpreta lo que devuelven `delete_plugins()` y `delete_theme()`.
	 *
	 * `true` es éxito y un `WP_Error` se entrega tal cual. `null` o `false`
	 * significan que WordPress se quedó esperando credenciales del sistema de
	 * archivos antes de tocar nada.
	 *
	 * @param mixed $resultado Retorno de la función de WordPress.
	 * @return true|\WP_Error
	 */
	private function deletion_result( $resultado ) {
		if ( true === $resultado || is_wp_error( $resultado ) ) {
			return $resultado;
		}

		return $this->filesystem_denied();
	}

	/**
	 * Comprueba que la portada siga respondiendo tras la operación.
	 *
	 * @return array<string, mixed>
	 */
	private function site_health(): array {
		$health        = wp_safe_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 2,
			)
		);
		$response_code = is_wp_error( $health ) ? 0 : wp_remote_retrieve_response_code( $health );

		return array(
			'site_health' => $response_code >= 200 && $response_code < 500 ? 'reachable' : 'check_required',
			'http_status' => $response_code,
		);
	}

	/**
	 * Carga funciones administrativas necesarias.
	 */
	private function load_plugin_api(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-includes/update.php';
	}

	/**
	 * Carga lo necesario para operar sobre temas.
	 */
	private function load_theme_api(): void {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-includes/update.php';
	}

	/**
	 * Genera una clave de transient no reversible.
	 *
	 * @param string $token Token.
	 */
	private function plan_key( string $token ): string {
		return self::PLAN_PREFIX . hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Elimina datos internos del plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	private function public_plan( array $plan ): array {
		unset( $plan['download_link'], $plan['user_id'] );
		return $plan;
	}
}
