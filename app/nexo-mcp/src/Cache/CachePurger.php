<?php
/**
 * Purga de caché tras una escritura.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Cache;

use NexoDev\NexoMCP\Support\Settings;

/**
 * Invalida la caché después de escribir, y cuenta lo que hizo.
 *
 * El resultado viaja en la respuesta de cada herramienta a propósito. Purgar en
 * silencio dejaría al cliente sin saber si el cambio ya es visible, y callar un
 * fallo de purga reproduce el problema que esto viene a resolver: éxito
 * informado y cambio invisible.
 */
final class CachePurger {

	/**
	 * Configuración operativa.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Proveedores reconocidos.
	 *
	 * @var array<int, CacheProvider>
	 */
	private array $providers;

	/**
	 * Construye el purgador.
	 *
	 * @param Settings                       $settings  Configuración operativa.
	 * @param array<int, CacheProvider>|null $providers Proveedores propios.
	 */
	public function __construct( Settings $settings, ?array $providers = null ) {
		$this->settings  = $settings;
		$this->providers = $providers ?? KnownCacheProviders::all();
	}

	/**
	 * Purga lo correspondiente a un contenido concreto.
	 *
	 * @param int $post_id Contenido modificado.
	 * @return array<string, mixed>
	 */
	public function after_post_change( int $post_id ): array {
		return $this->run(
			'post',
			static fn( CacheProvider $provider ): bool => $provider->purge_post( $post_id )
		);
	}

	/**
	 * Purga el sitio completo tras un cambio global.
	 *
	 * @return array<string, mixed>
	 */
	public function after_global_change(): array {
		return $this->run(
			'site',
			static fn( CacheProvider $provider ): bool => $provider->purge_all()
		);
	}

	/**
	 * Ejecuta la purga sobre todos los proveedores activos.
	 *
	 * Se recorren todos y no sólo el primero: convivir con dos plugins de caché
	 * es habitual —uno de página y otro de objetos— y purgar sólo uno deja el
	 * contenido viejo servido por el otro.
	 *
	 * @param string   $scope  Alcance aplicado.
	 * @param callable $accion Purga a ejecutar por proveedor.
	 * @return array<string, mixed>
	 */
	private function run( string $scope, callable $accion ): array {
		if ( ! (bool) $this->settings->get( 'cache_purge_enabled' ) ) {
			return array(
				'purged' => false,
				'reason' => __( 'La purga automática de caché está desactivada en los ajustes.', 'nexo-mcp' ),
			);
		}

		$hechos   = array();
		$fallidos = array();

		foreach ( $this->providers as $provider ) {
			if ( ! $provider->is_active() ) {
				continue;
			}

			if ( $accion( $provider ) ) {
				$hechos[] = $provider->slug();
				continue;
			}

			$fallidos[] = $provider->slug();
		}

		if ( array() === $hechos && array() === $fallidos ) {
			return array(
				'purged' => false,
				'reason' => __( 'No hay ningún plugin de caché reconocido en este sitio.', 'nexo-mcp' ),
			);
		}

		$resultado = array(
			'purged'    => array() !== $hechos,
			'scope'     => $scope,
			'providers' => $hechos,
		);

		if ( array() !== $fallidos ) {
			$resultado['failed'] = $fallidos;
		}

		$cdn = $this->cdn_detectada();

		if ( '' !== $cdn ) {
			// Purgar el plugin local no alcanza cuando hay un borde delante: el
			// HTML sigue servido desde el CDN hasta que expire o se purgue allá,
			// y decirlo evita que el cambio parezca perdido.
			$resultado['cdn_warning'] = sprintf(
				/* translators: %s: nombre del CDN detectado. */
				__( 'Hay %s delante del sitio. Su caché de borde no se purga desde aquí y puede seguir sirviendo la versión anterior.', 'nexo-mcp' ),
				$cdn
			);
		}

		return $resultado;
	}

	/**
	 * Detecta un CDN delante del sitio por sus cabeceras habituales.
	 */
	private function cdn_detectada(): string {
		if ( isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return 'Cloudflare';
		}

		if ( isset( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) ) {
			return 'Sucuri';
		}

		return '';
	}
}
