<?php
/**
 * Estado local del registro obligatorio.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Registration;

use NexoDev\NexoMCP\Security\LocalEnvironment;

/**
 * Gestiona la identidad local y el token revocable de la instalación.
 */
final class RegistrationState {

	public const OPTION_NAME = 'nexo_mcp_registration';

	/**
	 * Días que el conector sigue operando sin poder contactar la plataforma.
	 *
	 * Una caída del servicio no puede apagar el sitio de nadie: sería un daño
	 * mayor que el que evita. Sólo un rechazo explícito corta el paso.
	 */
	public const GRACE_DAYS = 14;

	/**
	 * Valores iniciales.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'installation_id' => '',
			'email'           => '',
			'token'           => '',
			'status'          => 'unverified',
			'verified_at'     => '',
			'consent_version' => '',
			// Datos de la licencia. Son un reflejo de lo que dijo la plataforma
			// en la última respuesta: se muestran, nunca se usan para decidir.
			// Quien decide si hay cupo es siempre el servidor.
			'license_prefix'  => '',
			'plan'            => '',
			'site_limit'      => '',
			'sites_used'      => '',
			'updates_until'   => '',
			'latest_version'  => '',
			'last_check'      => '',
			// Momento del primer intento fallido de contacto. En blanco cuando
			// la última revalidación funcionó.
			'offline_since'   => '',
		);
	}

	/**
	 * Devuelve el estado normalizado.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		$stored = $this->is_network_wide()
			? get_site_option( self::OPTION_NAME, array() )
			: get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$state = array_merge( self::defaults(), $stored );

		foreach ( $state as $key => $value ) {
			$state[ $key ] = is_string( $value ) ? $value : '';
		}

		return $state;
	}

	/**
	 * Obtiene o genera el UUID estable de la instalación.
	 */
	public function installation_id(): string {
		$state = $this->all();

		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $state['installation_id'] ) ) {
			return $state['installation_id'];
		}

		$state['installation_id'] = wp_generate_uuid4();
		$this->persist( $state );

		return $state['installation_id'];
	}

	/**
	 * Indica si existe una verificación local completa.
	 *
	 * Se admiten dos formatos de token: el hexadecimal de 64 que emitía el
	 * registro por código y el base64url que emite la activación por licencia.
	 * Aceptar ambos evita que una instalación anterior quede fuera al
	 * actualizar.
	 */
	public function is_verified(): bool {
		$state = $this->all();

		return 'verified' === $state['status']
			&& '' !== $state['email']
			&& ( 1 === preg_match( '/^[0-9a-f]{64}$/', $state['token'] )
				|| 1 === preg_match( '/^[A-Za-z0-9_-]{40,50}$/', $state['token'] ) );
	}

	/**
	 * Indica si la instalación cumple el requisito para operar.
	 *
	 * Es lo que deben consultar el panel y el transporte: `is_verified()`
	 * describe el estado local, esto describe si se puede trabajar. Una
	 * licencia verificada que lleva demasiado tiempo sin poder revalidarse
	 * deja de cumplir, pero recién después del periodo de gracia.
	 */
	public function meets_requirement(): bool {
		// Sin servicio de licencias configurado (NEXO_MCP_API_URL) no hay a
		// quién pedir verificación: el conector funciona completo.
		if ( ! \NexoDev\NexoMCP\Licensing\ApiEndpoint::is_configured() ) {
			return true;
		}

		if ( self::requirement_bypassed() ) {
			return true;
		}

		return $this->is_verified() && ! $this->grace_expired();
	}

	/**
	 * Indica si se agotó el margen sin contacto con la plataforma.
	 */
	public function grace_expired(): bool {
		return $this->grace_days_left() <= 0;
	}

	/**
	 * Días que quedan antes de que la falta de contacto detenga el conector.
	 *
	 * Devuelve `GRACE_DAYS` cuando el último contacto fue correcto, que es el
	 * caso normal.
	 */
	public function grace_days_left(): int {
		$desde = $this->all()['offline_since'];

		if ( '' === $desde ) {
			return self::GRACE_DAYS;
		}

		$inicio = strtotime( $desde . ' UTC' );

		if ( false === $inicio ) {
			return self::GRACE_DAYS;
		}

		$transcurridos = ( time() - $inicio ) / DAY_IN_SECONDS;

		return (int) max( 0, ceil( self::GRACE_DAYS - $transcurridos ) );
	}

	/**
	 * Indica si el conector opera sin haber podido confirmar la licencia.
	 */
	public function is_offline(): bool {
		return '' !== $this->all()['offline_since'];
	}

	/**
	 * Guarda los datos que devolvió la plataforma tras una respuesta correcta.
	 *
	 * Deja el contador de gracia en cero: hubo contacto.
	 *
	 * @param array<string, mixed> $license       Bloque `license` de la respuesta.
	 * @param string               $latest_version Última versión publicada, si vino.
	 */
	public function store_license( array $license, string $latest_version = '' ): void {
		$state = $this->all();

		$state['plan']           = isset( $license['plan'] ) ? sanitize_key( (string) $license['plan'] ) : '';
		$state['site_limit']     = isset( $license['limit'] ) ? (string) (int) $license['limit'] : '';
		$state['sites_used']     = isset( $license['used'] ) ? (string) (int) $license['used'] : '';
		$state['updates_until']  = isset( $license['updatesUntil'] ) && is_string( $license['updatesUntil'] )
			? sanitize_text_field( $license['updatesUntil'] )
			: '';
		$state['latest_version'] = '' !== $latest_version ? sanitize_text_field( $latest_version ) : '';
		$state['last_check']     = current_time( 'mysql', true );
		$state['offline_since']  = '';

		$this->persist( $state );
	}

	/**
	 * Anota que no se pudo contactar la plataforma.
	 *
	 * Sólo marca el primer fallo: el margen se cuenta desde entonces y no se
	 * reinicia con cada reintento, o no vencería nunca.
	 */
	public function mark_offline(): void {
		$state = $this->all();

		if ( '' === $state['offline_since'] ) {
			$state['offline_since'] = current_time( 'mysql', true );
			$this->persist( $state );
		}
	}

	/**
	 * Permite operar sin registro sólo en desarrollo local.
	 *
	 * Mismo escape que la política HTTPS, y por el mismo motivo: un sitio de
	 * pruebas sin salida a internet no podría verificar su email ni ejecutar su
	 * batería de comprobaciones.
	 *
	 * La condición vive en {@see LocalEnvironment} porque exigir sólo las dos
	 * constantes no bastaba: las dos se escriben en `wp-config.php`, así que
	 * cualquier sitio en producción podía declararse local y saltarse el
	 * registro entero. Ahora se mira también la dirección del sitio.
	 */
	public static function requirement_bypassed(): bool {
		return LocalEnvironment::bypass_allowed();
	}

	/**
	 * Guarda la verificación sin autoload.
	 *
	 * @param string $email           Email verificado.
	 * @param string $token           Token revocable.
	 * @param string $consent_version Versión del consentimiento.
	 */
	public function verify( string $email, string $token, string $consent_version ): void {
		$state = $this->all();

		$state['email']           = sanitize_email( $email );
		$state['token']           = $token;
		$state['status']          = 'verified';
		$state['verified_at']     = current_time( 'mysql', true );
		$state['consent_version'] = sanitize_text_field( $consent_version );

		$this->persist( $state );
	}

	/**
	 * Registra una activación por licencia.
	 *
	 * La clave completa no se guarda: sólo su prefijo, para poder mostrar cuál
	 * es sin dejarla escrita en la base del sitio. Si alguien compromete este
	 * WordPress no se lleva una licencia utilizable en los demás.
	 *
	 * @param string               $email           Correo del dueño de la licencia.
	 * @param string               $token           Token de instalación emitido.
	 * @param string               $consent_version Versión del consentimiento.
	 * @param string               $license_prefix  Prefijo visible de la clave.
	 * @param array<string, mixed> $license         Bloque `license` de la respuesta.
	 */
	public function activate_with_license(
		string $email,
		string $token,
		string $consent_version,
		string $license_prefix,
		array $license
	): void {
		$state = $this->all();

		$state['email']           = sanitize_email( $email );
		$state['token']           = $token;
		$state['status']          = 'verified';
		$state['verified_at']     = current_time( 'mysql', true );
		$state['consent_version'] = sanitize_text_field( $consent_version );
		$state['license_prefix']  = sanitize_text_field( $license_prefix );

		$this->persist( $state );

		// Guarda plan, cupos y última comprobación, y deja limpio el margen.
		$this->store_license( $license );
	}

	/**
	 * Devuelve el token sólo para llamadas servidor a servidor.
	 */
	public function token(): string {
		return $this->all()['token'];
	}

	/**
	 * Elimina email, token y consentimiento, conservando el UUID local.
	 */
	public function clear(): void {
		$installation_id = $this->installation_id();
		$state           = self::defaults();

		$state['installation_id'] = $installation_id;
		$this->persist( $state );
	}

	/**
	 * Guarda el estado donde corresponda según el alcance de la activación.
	 *
	 * @param array<string, string> $state Estado completo.
	 */
	private function persist( array $state ): void {
		if ( $this->is_network_wide() ) {
			update_site_option( self::OPTION_NAME, $state );

			return;
		}

		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Indica si el plugin está activo para toda la red.
	 *
	 * Con activación de red el registro se guarda una vez para todos los
	 * sitios: verificar sitio por sitio chocaría con el techo de códigos por
	 * hora y dejaría una red grande sin poder arrancar.
	 */
	private function is_network_wide(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( NEXO_MCP_FILE ) );
	}
}
