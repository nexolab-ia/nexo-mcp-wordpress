<?php
/**
 * Selección del proveedor SEO vigente.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Seo;

/**
 * Decide qué plugin gobierna el SEO del sitio.
 *
 * El orden importa: si hay un plugin SEO instalado es el que emite el título y
 * las etiquetas, así que el conector escribe en sus metadatos en lugar de en los
 * propios. Sólo cuando no hay ninguno reconocido el conector toma el mando.
 */
final class SeoProviderRegistry {

	/**
	 * Proveedores reconocidos, del más específico al de respaldo.
	 *
	 * @var array<int, SeoProvider>
	 */
	private array $providers;

	/**
	 * Construye el registro.
	 *
	 * @param array<int, SeoProvider>|null $providers Proveedores a consultar.
	 */
	public function __construct( ?array $providers = null ) {
		$this->providers = $providers ?? array(
			new YoastProvider(),
			new RankMathProvider(),
			new AioseoProvider(),
			new SeoPressProvider(),
		);
	}

	/**
	 * Devuelve el proveedor que gobierna el sitio.
	 */
	public function active(): SeoProvider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_active() ) {
				return $provider;
			}
		}

		return new NativeProvider();
	}

	/**
	 * Indica si el SEO lo gobierna un plugin externo.
	 */
	public function delegated(): bool {
		return 'nexo' !== $this->active()->slug();
	}
}
