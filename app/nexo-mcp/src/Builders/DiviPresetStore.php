<?php
/**
 * Presets de módulos de Divi 5: lectura y escritura con respaldo en el historial del diseño global.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Catálogo de presets de módulo y de grupo de opciones, guardado en una sola opción de Divi.
 *
 * Divi 5 guarda todo en `et_divi_builder_global_presets_d5`
 * `{module:{<módulo>:{default, items:{<id>:{…}}}}, group:{<grupo>:{default, items}}}`. Sus rutas y
 * `GlobalPreset::save_data` REEMPLAZAN el catálogo entero y no tienen historial, respaldo ni control
 * de concurrencia, así que toda escritura de este almacén pasa por {@see self::update()}:
 * bajo el bloqueo del diseño global lee la opción completa, deja que quien llama la cambie, guarda
 * el valor anterior y el escrito en el historial de {@see DiviDesignStore} (área `presets`, una sola
 * pila de deshacer con el diseño global) y escribe con `GlobalPreset::save_data`, que además vacía
 * el CSS generado. Nunca un `update_option` crudo: no regeneraría el CSS (L2 de `divi-presets.md`).
 *
 * Al construirlo registra el área `presets` en el almacén de diseño, de modo que
 * `undo-divi-design-change` sepa leer su estado actual (comprobación de cambios ajenos) y restaurarla.
 * Cada dependencia de Divi se inyecta como callable para poder probarlo sin Divi.
 */
final class DiviPresetStore {

	/**
	 * Nombre del área en el historial.
	 */
	public const AREA = 'presets';

	/**
	 * Opción de Divi 5 con el catálogo de presets.
	 */
	public const OPTION = 'et_divi_builder_global_presets_d5';

	/**
	 * Almacén del diseño global: historial, bloqueo, flush y Actividad compartidos.
	 *
	 * @var DiviDesignStore
	 */
	private DiviDesignStore $design;

	/**
	 * Fn(): ?array. Opción cruda (null si no existe; no migra ni escribe nada).
	 *
	 * @var callable
	 */
	private $get_data;

	/**
	 * Fn( array ): mixed. Escribe el catálogo completo con `GlobalPreset::save_data`.
	 *
	 * @var callable
	 */
	private $save_data;

	/**
	 * Fn(): void. Borra la opción (para deshacer lo que la creó).
	 *
	 * @var callable
	 */
	private $clear_data;

	/**
	 * Fn( string ): array<string, string>. Grupos de opciones de un módulo: id de grupo => nombre de grupo.
	 *
	 * @var callable
	 */
	private $module_groups;

	/**
	 * Construye el almacén; sin callables usa Divi 5 de verdad.
	 *
	 * @param DiviDesignStore $design        Almacén del diseño global (historial y deshacer).
	 * @param callable|null   $get_data      fn(): ?array.
	 * @param callable|null   $save_data     fn( array $all ): mixed.
	 * @param callable|null   $clear_data    fn(): void.
	 * @param callable|null   $module_groups fn( string $module ): array<string, string>.
	 */
	public function __construct(
		DiviDesignStore $design,
		?callable $get_data = null,
		?callable $save_data = null,
		?callable $clear_data = null,
		?callable $module_groups = null
	) {
		$this->design        = $design;
		$this->get_data      = $get_data ?? array( self::class, 'divi_get_data' );
		$this->save_data     = $save_data ?? array( self::class, 'divi_save_data' );
		$this->clear_data    = $clear_data ?? array( self::class, 'divi_clear_data' );
		$this->module_groups = $module_groups ?? array( self::class, 'divi_module_groups' );

		$design->register_area(
			self::AREA,
			fn() => $this->stored(),
			function ( $previous ): void {
				if ( null === $previous ) {
					( $this->clear_data )();

					return;
				}

				if ( ! is_array( $previous ) ) {
					throw new \RuntimeException( esc_html__( 'El respaldo de los presets no es válido.', 'nexo-mcp' ) );
				}

				( $this->save_data )( $previous );
			}
		);
	}

	/**
	 * Indica si Divi 5 está activo.
	 */
	public function available(): bool {
		return $this->design->available();
	}

	/**
	 * Grupos de opciones de un módulo según Divi (id de grupo => nombre de grupo); un fallo es una lista vacía.
	 *
	 * @param string $module Nombre de bloque.
	 *
	 * @return array<string, string>
	 */
	public function module_groups( string $module ): array {
		return $this->groups_of( $module );
	}

	/**
	 * Catálogo actual completo (sin bloqueo; para validar antes de escribir). Sin la opción, un arreglo vacío.
	 *
	 * @return array<string, mixed>
	 */
	public function current(): array {
		$stored = $this->stored();

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Lee los presets.
	 *
	 * Sin filtro devuelve todo sin los atributos (pueden ser muchos); con `$module` devuelve los presets de ese
	 * módulo y los de grupo hechos para él, con sus atributos.
	 *
	 * @param string|null $module Nombre de bloque (`divi/text`) del catálogo de D2, o null para todos.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read( ?string $module = null ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		if ( null !== $module && ! DiviModuleCatalog::has( $module ) ) {
			return new \WP_Error(
				'nexo_mcp_divi_preset_module_unknown',
				sprintf(
					/* translators: %s: módulos admitidos. */
					__( 'Módulo fuera del catálogo. Los presets se gestionan para: %s.', 'nexo-mcp' ),
					implode( ', ', DiviModuleCatalog::modules() )
				)
			);
		}

		$data       = $this->current();
		$with_attrs = null !== $module;
		$modules    = array();
		$groups     = array();

		$stored_modules = isset( $data['module'] ) && is_array( $data['module'] ) ? $data['module'] : array();

		foreach ( $stored_modules as $name => $collection ) {
			$name = (string) $name;

			if ( ! is_array( $collection ) || ( null !== $module && $module !== $name ) ) {
				continue;
			}

			$modules[] = array(
				'module'     => $name,
				'in_catalog' => DiviModuleCatalog::has( $name ),
				'default'    => $this->default_id( $collection ),
				'presets'    => $this->items( $collection, $with_attrs, null ),
			);
		}

		$stored_groups = isset( $data['group'] ) && is_array( $data['group'] ) ? $data['group'] : array();

		foreach ( $stored_groups as $name => $collection ) {
			if ( ! is_array( $collection ) ) {
				continue;
			}

			$items = $this->items( $collection, $with_attrs, $module );

			if ( null !== $module && array() === $items ) {
				continue;
			}

			$groups[] = array(
				'group'   => (string) $name,
				'default' => $this->default_id( $collection ),
				'presets' => $items,
			);
		}

		$available = array();

		foreach ( null !== $module ? array( $module ) : DiviModuleCatalog::modules() as $name ) {
			$list = array();

			foreach ( $this->groups_of( $name ) as $id => $group ) {
				$list[] = array(
					'id'   => (string) $id,
					'name' => (string) $group,
				);
			}

			$available[] = array(
				'module' => $name,
				'groups' => $list,
			);
		}

		return array(
			'modules'          => $modules,
			'groups'           => $groups,
			'available_groups' => $available,
			'history_count'    => count( $this->design->history() ),
		);
	}

	/**
	 * Único punto de escritura de los presets: lee la opción completa, la cambia y la escribe bajo el bloqueo.
	 *
	 * @param callable $mutate fn( array $current ): array|\WP_Error. Recibe el catálogo completo actual (vacío si la
	 *                         opción no existe) y devuelve el catálogo completo nuevo; un error no escribe nada.
	 * @param string   $tool   Herramienta que escribe (Actividad e historial).
	 *
	 * @return array<string, mixed>|\WP_Error `{area: 'presets', undo_available: true}`.
	 */
	public function update( callable $mutate, string $tool ) {
		if ( ! $this->available() ) {
			return Divi5ContentWriter::required_error();
		}

		return $this->design->write_area(
			$tool,
			self::AREA,
			function () use ( $mutate ) {
				$previous = $this->stored();
				$new      = $mutate( is_array( $previous ) ? $previous : array() );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				return array(
					'previous' => $previous,
					'written'  => $new,
					'apply'    => function () use ( $new ) {
						( $this->save_data )( $new );

						return null;
					},
				);
			}
		);
	}

	/**
	 * Opción cruda: null si no existe o no es un arreglo.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stored(): ?array {
		$data = ( $this->get_data )();

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Id del predeterminado de una colección, o null si no hay.
	 *
	 * @param array<mixed> $collection Colección `{default, items}`.
	 */
	private function default_id( array $collection ): ?string {
		return isset( $collection['default'] ) && is_string( $collection['default'] ) && '' !== $collection['default'] ? $collection['default'] : null;
	}

	/**
	 * Presets de una colección en la forma pública.
	 *
	 * @param array<mixed> $collection Colección `{default, items}`.
	 * @param bool         $attrs      Incluir los atributos.
	 * @param string|null  $module     Si se da, sólo los hechos para ese módulo (presets de grupo).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items( array $collection, bool $attrs, ?string $module ): array {
		$items = isset( $collection['items'] ) && is_array( $collection['items'] ) ? $collection['items'] : array();
		$list  = array();

		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$owner = isset( $item['moduleName'] ) && is_string( $item['moduleName'] ) ? $item['moduleName'] : '';

			if ( null !== $module && $owner !== $module ) {
				continue;
			}

			$entry = array(
				'id'      => isset( $item['id'] ) && is_scalar( $item['id'] ) && '' !== (string) $item['id'] ? (string) $item['id'] : (string) $key,
				'name'    => isset( $item['name'] ) && is_scalar( $item['name'] ) ? (string) $item['name'] : '',
				'module'  => $owner,
				'created' => isset( $item['created'] ) && is_numeric( $item['created'] ) ? (int) $item['created'] : 0,
				'updated' => isset( $item['updated'] ) && is_numeric( $item['updated'] ) ? (int) $item['updated'] : 0,
			);

			if ( isset( $item['groupId'] ) && is_string( $item['groupId'] ) ) {
				$entry['group_id'] = $item['groupId'];
			}

			if ( $attrs ) {
				$entry['attrs'] = isset( $item['attrs'] ) && is_array( $item['attrs'] ) ? $item['attrs'] : array();
			}

			$list[] = $entry;
		}

		return $list;
	}

	/**
	 * Grupos de opciones de un módulo según Divi; un fallo es una lista vacía.
	 *
	 * @param string $module Nombre de bloque.
	 *
	 * @return array<string, string>
	 */
	private function groups_of( string $module ): array {
		try {
			$groups = ( $this->module_groups )( $module );
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * Opción cruda `et_divi_builder_global_presets_d5` (sin `GlobalPreset::get_data`, que migra y escribe al leer).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function divi_get_data(): ?array {
		$data = maybe_unserialize( get_option( self::OPTION, null ) );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Escribe el catálogo completo con `GlobalPreset::save_data` (también vacía el CSS).
	 *
	 * @param array<string, mixed> $all Catálogo completo.
	 *
	 * @return mixed
	 *
	 * @throws \RuntimeException Si Divi no está disponible.
	 */
	public static function divi_save_data( array $all ) {
		$callable = array( '\ET\Builder\Packages\GlobalData\GlobalPreset', 'save_data' );

		if ( ! is_callable( $callable ) ) {
			throw new \RuntimeException( esc_html__( 'Divi 5 no ofrece la función para guardar los presets.', 'nexo-mcp' ) );
		}

		return call_user_func( $callable, $all );
	}

	/**
	 * Borra la opción de presets (sólo para deshacer lo que la creó; el CSS lo vacía el almacén después).
	 */
	public static function divi_clear_data(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Grupos de opciones de un módulo con `GlobalPreset::get_group_preset_default_attr`.
	 *
	 * @param string $module Nombre de bloque.
	 *
	 * @return array<string, string> Id de grupo => nombre de grupo.
	 */
	public static function divi_module_groups( string $module ): array {
		$registration = '\ET\Builder\Packages\ModuleLibrary\ModuleRegistration';
		$preset       = '\ET\Builder\Packages\GlobalData\GlobalPreset';

		if ( ! is_callable( array( $registration, 'get_module_settings' ) ) || ! is_callable( array( $preset, 'get_group_preset_default_attr' ) ) ) {
			return array();
		}

		$settings = call_user_func( array( $registration, 'get_module_settings' ), $module );

		if ( ! is_object( $settings ) ) {
			return array();
		}

		$groups = call_user_func( array( $preset, 'get_group_preset_default_attr' ), $settings );
		$list   = array();

		foreach ( is_array( $groups ) ? $groups : array() as $id => $group ) {
			if ( is_array( $group ) && isset( $group['groupName'] ) && is_string( $group['groupName'] ) ) {
				$list[ (string) $id ] = $group['groupName'];
			}
		}

		return $list;
	}
}
