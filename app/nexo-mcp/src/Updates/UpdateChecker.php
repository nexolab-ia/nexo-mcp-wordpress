<?php
/**
 * Integración con el sistema de actualizaciones de WordPress.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Updates;

use NexoDev\NexoMCP\Registration\RegistrationState;
use stdClass;

/**
 * Hace que el plugin se actualice como cualquier otro.
 *
 * WordPress ya sabe avisar, descargar e instalar; lo único que le falta es
 * saber dónde preguntar, porque este plugin no está en el directorio oficial.
 * Esta clase le contesta esa pregunta y deja que WordPress haga el resto: por
 * eso el aviso, el botón «Actualizar ahora» y las actualizaciones automáticas
 * funcionan sin escribir nada específico para cada caso.
 *
 * Sin licencia activa no se informa ninguna versión. El sitio sigue operando
 * con la que tiene: lo que se pierde es el canal de actualizaciones, no el
 * plugin.
 */
final class UpdateChecker {

	/**
	 * Información local de versiones.
	 *
	 * @var UpdateState
	 */
	private UpdateState $state;

	/**
	 * Estado de la licencia.
	 *
	 * @var RegistrationState
	 */
	private RegistrationState $license;

	/**
	 * Construye el comprobador.
	 *
	 * @param UpdateState       $state   Información guardada de versiones.
	 * @param RegistrationState $license Estado de la licencia.
	 */
	public function __construct( UpdateState $state, RegistrationState $license ) {
		$this->state   = $state;
		$this->license = $license;
	}

	/**
	 * Engancha los filtros que usa WordPress para actualizar.
	 */
	public function register(): void {
		add_filter( 'site_transient_update_plugins', array( $this, 'announce' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_before_download' ), 10, 3 );
	}

	/**
	 * Identificador del plugin tal como lo conoce WordPress.
	 */
	private function basename(): string {
		return plugin_basename( NEXO_MCP_FILE );
	}

	/**
	 * Nombre corto, el que usa la pantalla de detalles.
	 */
	private function slug(): string {
		return dirname( $this->basename() );
	}

	/**
	 * Añade la actualización a la lista que WordPress muestra y descarga.
	 *
	 * @param mixed $transient Lista de actualizaciones de WordPress.
	 * @return mixed
	 */
	public function announce( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$archivo = $this->basename();
		$ficha   = $this->build_offer();

		if ( null === $ficha ) {
			// Aparecer en `no_update` no es decorativo: es lo que hace que
			// WordPress muestre el interruptor de actualizaciones automáticas
			// para este plugin. Sin esto, el usuario no puede activarlas.
			$sin_novedad              = new stdClass();
			$sin_novedad->id          = $this->slug();
			$sin_novedad->slug        = $this->slug();
			$sin_novedad->plugin      = $archivo;
			$sin_novedad->new_version = NEXO_MCP_VERSION;
			$sin_novedad->url         = \NexoDev\NexoMCP\Licensing\ApiEndpoint::base();
			$sin_novedad->package     = '';

			$transient->no_update[ $archivo ] = $sin_novedad;

			unset( $transient->response[ $archivo ] );

			return $transient;
		}

		$transient->response[ $archivo ] = $ficha;
		unset( $transient->no_update[ $archivo ] );

		return $transient;
	}

	/**
	 * Rellena la ventana de «Ver detalles» con el historial de cambios.
	 *
	 * @param mixed                $resultado Respuesta previa de otro filtro.
	 * @param string               $accion    Acción solicitada.
	 * @param object|array<string> $argumentos Argumentos de la consulta.
	 * @return mixed
	 */
	public function details( $resultado, $accion, $argumentos ) {
		$slug = is_object( $argumentos ) && isset( $argumentos->slug )
			? (string) $argumentos->slug
			: '';

		if ( 'plugin_information' !== $accion || $slug !== $this->slug() ) {
			return $resultado;
		}

		$datos = $this->state->all();

		$informacion               = new stdClass();
		$informacion->name         = 'Nexo MCP';
		$informacion->slug         = $this->slug();
		$informacion->version      = $this->license->meets_requirement() && $this->state->has_update()
			? $datos['version']
			: NEXO_MCP_VERSION;
		$informacion->author       = 'NexoDev';
		$informacion->homepage     = \NexoDev\NexoMCP\Licensing\ApiEndpoint::base();
		$informacion->requires     = '' !== $datos['min_wp'] ? $datos['min_wp'] : '6.9';
		$informacion->requires_php = '' !== $datos['min_php'] ? $datos['min_php'] : '8.1';
		$informacion->tested       = $datos['tested_wp'];
		$informacion->last_updated = $datos['published'];
		$informacion->sections     = array(
			'description' => __( 'Conecta tu WordPress con clientes de IA a través del protocolo MCP, con permisos acotados y sin exponer credenciales.', 'nexo-mcp' ),
			'changelog'   => $this->changelog_html( $datos['changelog'] ),
		);

		if ( '' !== $datos['size'] ) {
			$informacion->download_link = $datos['package'];
		}

		return $informacion;
	}

	/**
	 * Comprueba la integridad del paquete antes de dejar que se instale.
	 *
	 * Es la última defensa: si alguien lograra interponerse entre el sitio y
	 * la plataforma, aquí se detecta que el archivo no es el que la plataforma
	 * dijo haber publicado. Sin esta comprobación el checksum guardado sería
	 * un adorno.
	 *
	 * @param mixed  $respuesta Respuesta previa; false deja seguir el curso normal.
	 * @param string $paquete   URL del paquete.
	 * @param object $upgrader  Instancia del actualizador.
	 * @return mixed
	 */
	public function verify_before_download( $respuesta, $paquete, $upgrader ) {
		$datos = $this->state->all();

		if ( '' === $datos['checksum'] || $paquete !== $datos['package'] ) {
			return $respuesta;
		}

		$descargado = download_url( $paquete, 300 );

		if ( is_wp_error( $descargado ) ) {
			return $descargado;
		}

		$calculado = hash_file( 'sha256', $descargado );

		if ( ! hash_equals( strtolower( $datos['checksum'] ), (string) $calculado ) ) {
			wp_delete_file( $descargado );

			return new \WP_Error(
				'nexo_mcp_checksum_mismatch',
				__( 'El archivo descargado no coincide con el publicado. La actualización se detuvo por seguridad.', 'nexo-mcp' )
			);
		}

		unset( $upgrader );

		return $descargado;
	}

	/**
	 * Construye la oferta de actualización, si corresponde.
	 *
	 * @return stdClass|null
	 */
	private function build_offer(): ?stdClass {
		// Sin licencia en regla no se ofrece nada. No es un castigo: es que el
		// canal de actualizaciones es justamente lo que se paga.
		if ( ! \NexoDev\NexoMCP\Licensing\ApiEndpoint::is_configured()
			|| ! $this->license->meets_requirement()
			|| ! $this->state->has_update()
		) {
			return null;
		}

		$datos = $this->state->all();

		// El sitio no debe recibir algo que no puede ejecutar. La plataforma ya
		// filtra por requisitos, pero comprobarlo aquí protege del caso en que
		// el sitio cambió de PHP después de la última consulta.
		if ( '' !== $datos['min_php'] && version_compare( PHP_VERSION, $datos['min_php'], '<' ) ) {
			return null;
		}

		if ( '' !== $datos['min_wp'] && version_compare( get_bloginfo( 'version' ), $datos['min_wp'], '<' ) ) {
			return null;
		}

		$oferta               = new stdClass();
		$oferta->id           = $this->slug();
		$oferta->slug         = $this->slug();
		$oferta->plugin       = $this->basename();
		$oferta->new_version  = $datos['version'];
		$oferta->url          = \NexoDev\NexoMCP\Licensing\ApiEndpoint::base();
		$oferta->package      = $datos['package'];
		$oferta->tested       = $datos['tested_wp'];
		$oferta->requires     = $datos['min_wp'];
		$oferta->requires_php = $datos['min_php'];

		return $oferta;
	}

	/**
	 * Da formato al historial de cambios.
	 *
	 * Llega como líneas sueltas desde la plataforma; se muestra como lista
	 * porque es como WordPress presenta los changelog y como espera leerlos
	 * quien abre esa ventana.
	 *
	 * @param string $texto Notas de la versión.
	 */
	private function changelog_html( string $texto ): string {
		$lineas = array_filter( array_map( 'trim', explode( "\n", $texto ) ) );

		if ( array() === $lineas ) {
			return '<p>' . esc_html__( 'Sin notas para esta versión.', 'nexo-mcp' ) . '</p>';
		}

		$items = '';

		foreach ( $lineas as $linea ) {
			$permitido = array(
				'b'      => array(),
				'strong' => array(),
				'code'   => array(),
			);

			$items .= '<li>' . wp_kses( $linea, $permitido ) . '</li>';
		}

		return '<ul>' . $items . '</ul>';
	}
}
