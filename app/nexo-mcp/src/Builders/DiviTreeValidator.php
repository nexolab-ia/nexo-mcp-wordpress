<?php
/**
 * Validación y saneado del árbol de módulos de Divi 5 que manda el cliente.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\Builders;

/**
 * Decide si un árbol (o un parche de un módulo) de Divi 5 se puede guardar y devuelve su copia saneada.
 *
 * Formato del árbol del cliente: una lista de nodos
 * `[ { "type": "divi/section", "attrs": { … }, "children": [ … ] } ]`, con `type` del
 * {@see DiviModuleCatalog}, `attrs` opcional (los atributos de bloque de Divi tal como se
 * guardan: `{"title":{"innerContent":{"desktop":{"value":"Hola"}}}}`) e hijos opcionales.
 * No lleva `builderVersion`, `modulePreset` ni ids: los pone quien escribe (`modulePreset` y `groupPreset`
 * dentro de `attrs` siempre se rechazan; para aplicar un preset el nodo lleva `preset`, ver {@see self::validate_preset_ref()}). El parche de un
 * módulo es `{ "content"?: attrs, "design"?: attrs }`: `content` sólo admite rutas de contenido
 * y `design` sólo rutas de diseño del catálogo.
 *
 * Todo atributo debe estar en una ruta del catálogo y llevar únicamente `desktop.value`: tablet,
 * teléfono, hover y cualquier otra clave se rechazan (al editar, quien escribe conserva las que ya existen).
 * Es una clase pura: la existencia de un shortcode y la URL de un adjunto llegan como
 * funciones, de modo que se prueba sin WordPress. El resultado nunca se escribe sin pasar por aquí.
 *
 * Todo error lleva el código de la regla, la ruta en el mensaje (`nodes[0].children[1].attrs.title.innerContent.tablet: …`)
 * y la ruta en `error_data`. Códigos: `nexo_mcp_divi_module_denied`, `_attr_denied`, `_value_invalid`,
 * `_nesting_invalid`, `_shortcode_denied`, `_dynamic_denied` (token `$variable(…)$`), `_dynamic_invalid` y
 * `_dynamic_unavailable` (sólo con contexto dinámico), `_tree_invalid` (forma), `_too_many_nodes` y `_too_large`.
 *
 * Contenido dinámico: por omisión (1.9.1) todo token `$variable(…)$` se rechaza. {@see self::with_dynamic()} devuelve
 * una copia que, para una página dada, acepta en un campo de texto, enlace o imagen un valor que sea exactamente UN
 * token de la lista cerrada de {@see DiviDynamicCatalog} con ajustes válidos y de la categoría del campo, y lo guarda en
 * su forma canónica (re-serializada por el catálogo, nunca la cadena del cliente). Las claves de diseño, las
 * etiquetas administrativas y los presets nunca admiten tokens. Quien llama decide si pasa el contexto
 * (`PermissionPolicy::can_write_divi_dynamic()`).
 */
final class DiviTreeValidator {

	public const MAX_NODES = 200;
	public const MAX_BYTES = 3145728;

	/**
	 * Claves de un nodo.
	 *
	 * @var array<int, string>
	 */
	private const NODE_KEYS = array( 'type', 'attrs', 'children', 'preset' );

	/**
	 * Etiquetas y atributos del texto rico.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const ALLOWED_HTML = array(
		'p'      => array(),
		'h1'     => array(),
		'h2'     => array(),
		'h3'     => array(),
		'h4'     => array(),
		'h5'     => array(),
		'h6'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		// Sin `target`: abrir en otra pestaña permitiría tabnabbing, y forzar `rel` reescribiendo el
		// HTML después de wp_kses rompió el entrecomillado (XSS). wp_kses lo quita y nada lo toca después.
		'a'      => array(
			'href' => true,
			'rel'  => true,
		),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'br'     => array(),
		'span'   => array(),
	);

	/**
	 * Comprobación de existencia de un shortcode.
	 *
	 * @var callable
	 */
	private $shortcode_exists;

	/**
	 * Resolución de un adjunto: URL o false.
	 *
	 * @var callable
	 */
	private $attachment_url;

	/**
	 * Catálogo de presets: fn(): array (la opción `et_divi_builder_global_presets_d5` completa).
	 *
	 * @var callable
	 */
	private $presets;

	/**
	 * Grupos de opciones de un módulo: fn( string $module ): array<string, string> (id de grupo => nombre de grupo).
	 *
	 * @var callable
	 */
	private $module_groups;

	/**
	 * Menús de navegación que existen: fn( int $id ): bool (id de término del menú). Sólo se llama con un `divi/menu`.
	 *
	 * @var callable
	 */
	private $menu_exists;

	/**
	 * Ítem global publicado de la biblioteca: fn( int $id ): ?string (su bloque raíz, o null si no es uno usable). Sólo se llama con
	 * una referencia `divi/global-layout` y {@see self::with_global_refs()}; una función que lanza cuenta como «no existe».
	 *
	 * @var callable
	 */
	private $global_item;

	/**
	 * Si se admiten referencias `divi/global-layout` (T10).
	 *
	 * @var bool
	 */
	private bool $global_refs = false;

	/**
	 * Si el árbol es el de un layout del Theme Builder: admite además {@see DiviModuleCatalog::LAYOUT_MODULES}.
	 *
	 * @var bool
	 */
	private bool $layout = false;

	/**
	 * Nodos vistos en la validación en curso.
	 *
	 * @var int
	 */
	private int $nodes = 0;

	/**
	 * Catálogo de contenido dinámico del contexto «permitir dinámico», o null (todo token se rechaza).
	 *
	 * @var DiviDynamicCatalog|null
	 */
	private ?DiviDynamicCatalog $dynamic = null;

	/**
	 * Página (el original) a la que se escribe, para el catálogo dinámico.
	 *
	 * @var int
	 */
	private int $dynamic_post = 0;

	/**
	 * Tipo de contenido del bucle del conector donde está el campo (propio o de un ancestro), o null si no hay uno.
	 *
	 * @var string|null
	 */
	private ?string $loop_type = null;

	/**
	 * Si el valor que se valida está en un campo que admite token (sólo dentro de una hoja de contenido y con contexto).
	 *
	 * @var bool
	 */
	private bool $token_slot = false;

	/**
	 * Tokens canónicos que este validador emitió en la hoja en curso (ruta del valor → token), para el reescaneo final.
	 *
	 * @var array<string, string>
	 */
	private array $emitted = array();

	/**
	 * Construye el validador.
	 *
	 * @param callable|null $shortcode_exists fn( string $name ): bool. Por omisión, `shortcode_exists()`.
	 * @param callable|null $attachment_url   fn( int $id ): string|false, URL del adjunto. Por omisión, `wp_get_attachment_url()`.
	 * @param callable|null $presets          fn(): array, catálogo de presets (sólo se llama si un nodo lleva `preset`). Por omisión, la opción de Divi sin migrar.
	 * @param callable|null $module_groups    fn( string $module ): array<string, string>, grupos de opciones del módulo. Por omisión, Divi.
	 * @param callable|null $menu_exists      fn( int $id ): bool, si existe un menú de navegación con ese id. Por omisión, `wp_get_nav_menus()`; una función que lanza cuenta como «no existe».
	 * @param callable|null $global_item      fn( int $id ): ?string, bloque raíz de un ítem global publicado de la biblioteca. Por omisión, {@see DiviLibrarySchema::global_block()}.
	 */
	public function __construct( ?callable $shortcode_exists = null, ?callable $attachment_url = null, ?callable $presets = null, ?callable $module_groups = null, ?callable $menu_exists = null, ?callable $global_item = null ) {
		$this->shortcode_exists = $shortcode_exists ?? static fn( string $name ): bool => shortcode_exists( $name );
		$this->attachment_url   = $attachment_url ?? static fn( int $id ) => wp_get_attachment_url( $id );
		$this->presets          = $presets ?? static fn(): array => (array) DiviPresetStore::divi_get_data();
		$this->module_groups    = $module_groups ?? array( DiviPresetStore::class, 'divi_module_groups' );
		$this->global_item      = $global_item ?? static fn( int $id ): ?string => ( new DiviLibrarySchema() )->global_block( $id );
		$this->menu_exists      = $menu_exists ?? static function ( int $id ): bool {
			foreach ( wp_get_nav_menus() as $menu ) {
				if ( (int) $menu->term_id === $id ) {
					return true;
				}
			}

			return false;
		};
	}

	/**
	 * Copia del validador para el árbol de un layout del Theme Builder: admite también `divi/post-content`, `divi/post-title` y `divi/menu`.
	 *
	 * El validador original no cambia (una página sigue sin admitirlos). Quien llama decide el contexto mirando el tipo del contenido.
	 */
	public function with_layout(): self {
		$copy         = clone $this;
		$copy->layout = true;

		return $copy;
	}

	/**
	 * Copia del validador que admite además referencias `divi/global-layout` a ítems globales de la biblioteca (T10).
	 *
	 * La forma es la que escribe Divi 5.19.1 (L2/L4 de `divi-theme-builder.md`): `{globalModule: "<id>", blockName: "<bloque>"}`, sin
	 * `localAttrs`, hijos ni preset. El ítem debe existir, ser global y estar publicado (si no, Divi no lo renderiza), y su bloque
	 * raíz debe ser `blockName`. El validador original no cambia.
	 */
	public function with_global_refs(): self {
		$copy              = clone $this;
		$copy->global_refs = true;

		return $copy;
	}

	/**
	 * Copia del validador que admite tokens de contenido dinámico de la lista cerrada para una página.
	 *
	 * El validador original no cambia. Sólo se llama cuando el permiso de escribir dinámico vale para la página.
	 *
	 * @param DiviDynamicCatalog $catalog Catálogo de fuentes dinámicas.
	 * @param int                $post_id Página (el original, no la copia de trabajo) donde se escribe.
	 */
	public function with_dynamic( DiviDynamicCatalog $catalog, int $post_id ): self {
		$copy               = clone $this;
		$copy->dynamic      = $catalog;
		$copy->dynamic_post = $post_id;
		$copy->token_slot   = false;
		$copy->emitted      = array();

		return $copy;
	}

	/**
	 * Copia del validador (con contexto dinámico) que además admite las fuentes `loop_*` del tipo de bucle dado.
	 *
	 * Quien llama decide el contexto mirando el árbol ACTUAL de la página: un bucle del conector válido en el módulo o en
	 * un ancestro. Sin contexto dinámico no cambia nada (todo token se rechaza).
	 *
	 * @param string|null $post_type Tipo de contenido del bucle, o null para quitar el contexto.
	 */
	public function with_loop( ?string $post_type ): self {
		$copy            = clone $this;
		$copy->loop_type = $post_type;

		return $copy;
	}

	/**
	 * Valida un árbol y devuelve su copia saneada.
	 *
	 * @param mixed  $nodes       Lista de nodos recibida del cliente.
	 * @param string $parent_type Padre de la lista (`DiviModuleCatalog::ROOT` o el nombre de bloque).
	 * @param int    $base_depth  Reservado para el llamador (niveles sobre la lista); no cambia las reglas.
	 * @param int    $base_nodes  Nodos del documento que no forman parte de la lista, para el tope de {@see self::MAX_NODES}.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function validate_tree( $nodes, string $parent_type = DiviModuleCatalog::ROOT, int $base_depth = 0, int $base_nodes = 0 ) {
		unset( $base_depth );

		if ( ! is_array( $nodes ) || array() === $nodes || ! array_is_list( $nodes ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'nodes', __( 'debe ser una lista no vacía de módulos.', 'nexo-mcp' ) );
		}

		$encoded = wp_json_encode( $nodes );

		if ( ! is_string( $encoded ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'nodes', __( 'contiene datos que no se pueden codificar como JSON.', 'nexo-mcp' ) );
		}

		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			return $this->error( 'nexo_mcp_divi_too_large', 'nodes', __( 'el árbol pesa más de 3 MB.', 'nexo-mcp' ) );
		}

		$this->nodes = $base_nodes;

		return $this->walk_list( $nodes, 'nodes', $parent_type );
	}

	/**
	 * Valida el parche `{content?, design?}` de un módulo y devuelve su copia saneada.
	 *
	 * @param string $type  Nombre de bloque del módulo que se edita.
	 * @param mixed  $patch Parche recibido.
	 *
	 * @return array{content?: array<string, mixed>, design?: array<string, mixed>}|\WP_Error
	 */
	public function validate_patch( string $type, $patch ) {
		if ( ! DiviModuleCatalog::has( $type, $this->layout ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', 'type', $this->denied_reason( $type ) );
		}

		if ( ! is_array( $patch ) || array() === $patch || array_is_list( $patch ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'patch', __( 'debe ser un objeto con «content» y/o «design».', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $patch ) as $key ) {
			if ( 'content' !== $key && 'design' !== $key ) {
				return $this->error( 'nexo_mcp_divi_attr_denied', 'patch.' . $key, __( 'sólo se admiten «content» y «design».', 'nexo-mcp' ) );
			}
		}

		$out = array();

		foreach ( array( 'content', 'design' ) as $slot ) {
			if ( ! array_key_exists( $slot, $patch ) ) {
				continue;
			}

			if ( ! is_array( $patch[ $slot ] ) || ( array() !== $patch[ $slot ] && array_is_list( $patch[ $slot ] ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $slot, __( 'debe ser un objeto de atributos.', 'nexo-mcp' ) );
			}

			$specs  = 'content' === $slot ? DiviModuleCatalog::content_paths( $type, $this->layout ) : DiviModuleCatalog::design_paths( $type, $this->layout );
			$result = $this->walk_attrs( $patch[ $slot ], $specs, '', $slot );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( array() !== $result ) {
				$out[ $slot ] = $result;
			}
		}

		if ( array() === $out ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', 'patch', __( 'no cambia nada: indica al menos un atributo en «content» o «design».', 'nexo-mcp' ) );
		}

		return $out;
	}

	/**
	 * Valida los `attrs` de un preset de módulo o de grupo y devuelve su copia saneada.
	 *
	 * Un preset sólo lleva diseño de escritorio: las rutas de diseño del catálogo del módulo (con el mismo
	 * `desktop.value` y las mismas reglas de valor que un nodo del árbol) y, si es un preset de grupo, sólo las
	 * que cuelgan del grupo. Cualquier otra clave (manejadores de eventos, `attributes` personalizados,
	 * contenido, tablet, hover…) se rechaza, tenga o no el usuario `unfiltered_html`.
	 *
	 * @param string      $module   Nombre de bloque del módulo para el que es el preset.
	 * @param mixed       $attrs    Atributos recibidos.
	 * @param string|null $group_id Id del grupo de opciones (`module.decoration.background`), o null en un preset de módulo.
	 * @param string      $display  Nombre del campo en los mensajes y en `data.path`.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate_preset_attrs( string $module, $attrs, ?string $group_id = null, string $display = 'attrs' ) {
		if ( null !== $this->dynamic ) {
			// Un preset vale para todo el sitio: nunca lleva tokens, aunque quien escribe tenga el contexto dinámico.
			$closed          = clone $this;
			$closed->dynamic = null;

			return $closed->validate_preset_attrs( $module, $attrs, $group_id, $display );
		}

		if ( ! DiviModuleCatalog::has( $module ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', 'module', $this->denied_reason( $module ) );
		}

		if ( ! is_array( $attrs ) || array() === $attrs || array_is_list( $attrs ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $display, __( 'debe ser un objeto de atributos con al menos un valor.', 'nexo-mcp' ) );
		}

		$specs = DiviModuleCatalog::design_paths( $module );

		if ( null !== $group_id ) {
			$specs = array_filter(
				$specs,
				static fn( string $path ): bool => $path === $group_id || str_starts_with( $path, $group_id . '.' ),
				ARRAY_FILTER_USE_KEY
			);
		}

		if ( array() === $specs ) {
			return $this->error( 'nexo_mcp_divi_attr_denied', $display, __( 'el catálogo no admite atributos de diseño para este módulo o grupo.', 'nexo-mcp' ) );
		}

		$clean = $this->walk_attrs( $attrs, $specs, '', $display );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( array() === $clean ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $display, __( 'no tiene ningún valor de diseño.', 'nexo-mcp' ) );
		}

		return $clean;
	}

	/**
	 * Valida la referencia `preset` de un módulo (`{module?: id|null, groups?: {<groupId>: id|null}}`).
	 *
	 * Cada id debe existir en el catálogo de presets: el de módulo en `module.<tipo>.items`, el de grupo en
	 * `group.<nombre del grupo>.items` con el mismo `groupId`; el `groupId` debe ser un grupo de opciones que
	 * Divi da a ese módulo y el nombre de grupo se toma de Divi, no del cliente. `null` quita la referencia.
	 *
	 * @param string $type    Nombre de bloque del módulo.
	 * @param mixed  $preset  Referencia recibida.
	 * @param string $display Nombre del campo en los mensajes y en `data.path`.
	 *
	 * @return array{module?: array<int, string>|null, groups?: array<string, array{presetId: array<int, string>, groupName: string}|null>}|\WP_Error
	 */
	public function validate_preset_ref( string $type, $preset, string $display = 'preset' ) {
		if ( ! DiviModuleCatalog::has( $type ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', 'type', $this->denied_reason( $type ) );
		}

		if ( ! is_array( $preset ) || array() === $preset || array_is_list( $preset ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $display, __( 'debe ser un objeto con «module» y/o «groups».', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $preset ) as $key ) {
			if ( 'module' !== $key && 'groups' !== $key ) {
				return $this->error( 'nexo_mcp_divi_attr_denied', $display . '.' . $key, __( 'sólo se admiten «module» y «groups».', 'nexo-mcp' ) );
			}
		}

		$catalog = ( $this->presets )();
		$catalog = is_array( $catalog ) ? $catalog : array();
		$out     = array();

		if ( array_key_exists( 'module', $preset ) ) {
			$id = $preset['module'];

			if ( null !== $id && ( ! is_string( $id ) || '' === $id ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $display . '.module', __( 'debe ser el id de un preset de módulo o null para quitarlo.', 'nexo-mcp' ) );
			}

			if ( null !== $id && ! $this->preset_exists( $catalog, 'module', $type, $id, null ) ) {
				return $this->unknown( $display . '.module', $id );
			}

			$out['module'] = null === $id ? null : array( $id );
		}

		if ( array_key_exists( 'groups', $preset ) ) {
			$groups = $preset['groups'];

			if ( ! is_array( $groups ) || array() === $groups || array_is_list( $groups ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $display . '.groups', __( 'debe ser un objeto {<id de grupo>: id del preset o null}.', 'nexo-mcp' ) );
			}

			$available = ( $this->module_groups )( $type );
			$available = is_array( $available ) ? $available : array();

			foreach ( $groups as $group_id => $id ) {
				$group_id = (string) $group_id;
				$where    = $display . '.groups.' . $group_id;

				if ( null !== $id && ( ! is_string( $id ) || '' === $id ) ) {
					return $this->error( 'nexo_mcp_divi_tree_invalid', $where, __( 'debe ser el id de un preset de grupo o null para quitarlo.', 'nexo-mcp' ) );
				}

				if ( ! isset( $available[ $group_id ] ) || ! is_string( $available[ $group_id ] ) ) {
					return $this->error(
						'nexo_mcp_divi_preset_unknown',
						$where,
						sprintf(
							/* translators: 1: id de grupo, 2: tipo de módulo. */
							__( '«%1$s» no es un grupo de opciones de «%2$s» (ver available_groups en nexo-mcp-get-divi-presets).', 'nexo-mcp' ),
							$group_id,
							$type
						)
					);
				}

				if ( null === $id ) {
					$out['groups'][ $group_id ] = null;
					continue;
				}

				if ( ! $this->preset_exists( $catalog, 'group', $available[ $group_id ], $id, $group_id, $type ) ) {
					return $this->unknown( $where, $id );
				}

				$out['groups'][ $group_id ] = array(
					'presetId'  => array( $id ),
					'groupName' => $available[ $group_id ],
				);
			}
		}

		return $out;
	}

	/**
	 * Aplica una referencia validada a los atributos de un bloque: `modulePreset` y `groupPreset` en el formato de Divi.
	 *
	 * Un valor `null` quita la clave (o el grupo); un `groupPreset` que se queda sin grupos se quita. Lo demás no cambia.
	 *
	 * @param array<string, mixed>                                                                                                         $attrs Atributos del bloque.
	 * @param array{module?: array<int, string>|null, groups?: array<string, array{presetId: array<int, string>, groupName: string}|null>} $ref   Resultado de {@see self::validate_preset_ref()}.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_preset_ref( array $attrs, array $ref ): array {
		if ( array_key_exists( 'module', $ref ) ) {
			if ( null === $ref['module'] ) {
				unset( $attrs['modulePreset'] );
			} else {
				$attrs['modulePreset'] = $ref['module'];
			}
		}

		if ( isset( $ref['groups'] ) ) {
			$current = isset( $attrs['groupPreset'] ) && is_array( $attrs['groupPreset'] ) ? $attrs['groupPreset'] : array();

			foreach ( $ref['groups'] as $group_id => $entry ) {
				if ( null === $entry ) {
					unset( $current[ $group_id ] );
				} else {
					$current[ $group_id ] = $entry;
				}
			}

			if ( array() === $current ) {
				unset( $attrs['groupPreset'] );
			} else {
				$attrs['groupPreset'] = $current;
			}
		}

		return $attrs;
	}

	/**
	 * Indica si un preset existe en el catálogo.
	 *
	 * @param array<mixed> $catalog  Catálogo completo.
	 * @param string       $kind     `module` o `group`.
	 * @param string       $name     Módulo (en `module`) o nombre de grupo (en `group`).
	 * @param string       $id       Id del preset.
	 * @param string|null  $group_id Id del grupo de opciones que debe tener el preset de grupo.
	 * @param string       $module   Módulo editado: el preset de grupo debe ser de ese módulo (si trae `moduleName`).
	 */
	private function preset_exists( array $catalog, string $kind, string $name, string $id, ?string $group_id, string $module = '' ): bool {
		$item = $catalog[ $kind ][ $name ]['items'][ $id ] ?? null;

		if ( ! is_array( $item ) ) {
			return false;
		}

		if ( null === $group_id ) {
			return true;
		}

		// Un preset de grupo se hizo para un módulo (`moduleName`, también en el payload de sync de Divi): sólo vale en ése.
		if ( isset( $item['moduleName'] ) && $item['moduleName'] !== $module ) {
			return false;
		}

		return ! isset( $item['groupId'] ) || $item['groupId'] === $group_id;
	}

	/**
	 * Indica si algún nodo del árbol (a cualquier profundidad) trae un `preset` no nulo.
	 *
	 * Quien escribe lo usa para tomar el bloqueo de presets sólo cuando hace falta: `preset: null` no aplica ninguno.
	 *
	 * @param mixed $nodes Árbol recibido (sin validar).
	 */
	public static function tree_has_preset( $nodes ): bool {
		if ( ! is_array( $nodes ) ) {
			return false;
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['preset'] ) || ( isset( $node['children'] ) && self::tree_has_preset( $node['children'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Error de preset que no existe.
	 *
	 * @param string $path Ruta del campo.
	 * @param string $id   Id recibido.
	 */
	private function unknown( string $path, string $id ): \WP_Error {
		return $this->error(
			'nexo_mcp_divi_preset_unknown',
			$path,
			sprintf(
				/* translators: %s: id del preset. */
				__( 'el preset «%s» no existe para este módulo o grupo (ver nexo-mcp-get-divi-presets).', 'nexo-mcp' ),
				$id
			)
		);
	}

	/**
	 * Recorre una lista de nodos hermanos.
	 *
	 * @param array<int, mixed> $nodes       Nodos.
	 * @param string            $path        Ruta de la lista.
	 * @param string            $parent_type Padre.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function walk_list( array $nodes, string $path, string $parent_type ) {
		$out = array();

		foreach ( $nodes as $index => $node ) {
			$result = $this->walk_node( $node, $path . '[' . $index . ']', $parent_type );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$out[] = $result;
		}

		return $out;
	}

	/**
	 * Valida un nodo y sus hijos.
	 *
	 * @param mixed  $node        Nodo.
	 * @param string $path        Ruta del nodo.
	 * @param string $parent_type Padre.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_node( $node, string $path, string $parent_type ) {
		if ( ! is_array( $node ) || ( array() !== $node && array_is_list( $node ) ) ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $path, __( 'cada módulo debe ser un objeto con «type».', 'nexo-mcp' ) );
		}

		++$this->nodes;

		if ( $this->nodes > self::MAX_NODES ) {
			return $this->error(
				'nexo_mcp_divi_too_many_nodes',
				$path,
				sprintf(
					/* translators: %d: máximo de módulos. */
					__( 'se supera el máximo de %d módulos.', 'nexo-mcp' ),
					self::MAX_NODES
				)
			);
		}

		foreach ( array_keys( $node ) as $key ) {
			if ( ! in_array( $key, self::NODE_KEYS, true ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$path . '.' . $key,
					sprintf(
						/* translators: 1: clave, 2: claves admitidas. */
						__( 'la clave «%1$s» no se admite en un módulo. Admitidas: %2$s.', 'nexo-mcp' ),
						(string) $key,
						implode( ', ', self::NODE_KEYS )
					)
				);
			}
		}

		$type = $node['type'] ?? null;

		if ( ! is_string( $type ) || '' === $type ) {
			return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.type', __( 'indica el tipo del módulo.', 'nexo-mcp' ) );
		}

		if ( $this->global_refs && DiviModuleCatalog::GLOBAL_REF === $type ) {
			return $this->walk_global_ref( $node, $path, $parent_type );
		}

		if ( ! DiviModuleCatalog::has( $type, $this->layout ) ) {
			return $this->error( 'nexo_mcp_divi_module_denied', $path . '.type', $this->denied_reason( $type ) );
		}

		if ( ! in_array( $type, DiviModuleCatalog::children( $parent_type, $this->layout ), true ) ) {
			return $this->error(
				'nexo_mcp_divi_nesting_invalid',
				$path . '.type',
				sprintf(
					/* translators: 1: tipo, 2: padre, 3: hijos admitidos. */
					__( '«%1$s» no cabe dentro de «%2$s». Admitidos ahí: %3$s.', 'nexo-mcp' ),
					$type,
					$parent_type,
					array() === DiviModuleCatalog::children( $parent_type, $this->layout ) ? __( 'ninguno', 'nexo-mcp' ) : implode( ', ', DiviModuleCatalog::children( $parent_type, $this->layout ) )
				)
			);
		}

		$out = array( 'type' => $type );

		if ( array_key_exists( 'attrs', $node ) ) {
			$attrs = $node['attrs'];

			if ( ! is_array( $attrs ) || ( array() !== $attrs && array_is_list( $attrs ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.attrs', __( 'debe ser un objeto de atributos.', 'nexo-mcp' ) );
			}

			$clean = $this->walk_attrs( $attrs, array_merge( DiviModuleCatalog::content_paths( $type, $this->layout ), DiviModuleCatalog::design_paths( $type, $this->layout ) ), '', $path . '.attrs' );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out['attrs'] = $clean;
		}

		if ( array_key_exists( 'preset', $node ) ) {
			$ref = $this->validate_preset_ref( $type, $node['preset'], $path . '.preset' );

			if ( is_wp_error( $ref ) ) {
				return $ref;
			}

			$with = self::apply_preset_ref( array(), $ref );

			if ( array() !== $with ) {
				// Los presets van primero, justo después de `builderVersion` (que añade quien escribe).
				$out['attrs'] = array_merge( $with, isset( $out['attrs'] ) ? $out['attrs'] : array() );
			}
		}

		if ( array_key_exists( 'children', $node ) ) {
			$children = $node['children'];

			if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $path . '.children', __( 'debe ser una lista de módulos.', 'nexo-mcp' ) );
			}

			$clean = $this->walk_list( $children, $path . '.children', $type );

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$out['children'] = $clean;
		}

		return $out;
	}

	/**
	 * Valida una referencia `divi/global-layout`: forma cerrada, ítem global publicado y lugar donde cabe su bloque.
	 *
	 * @param array<string, mixed> $node        Nodo (ya con las claves de nodo comprobadas).
	 * @param string               $path        Ruta del nodo.
	 * @param string               $parent_type Padre.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_global_ref( array $node, string $path, string $parent_type ) {
		$code = 'nexo_mcp_divi_global_ref_invalid';

		foreach ( array( 'children', 'preset' ) as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				return $this->error( $code, $path . '.' . $key, __( 'una referencia a un ítem global no lleva hijos ni preset.', 'nexo-mcp' ) );
			}
		}

		$attrs = $node['attrs'] ?? null;

		$keys = is_array( $attrs ) ? array_map( 'strval', array_keys( $attrs ) ) : array();

		sort( $keys );

		if ( ! is_array( $attrs ) || array( 'blockName', 'globalModule' ) !== $keys ) {
			return $this->error( $code, $path . '.attrs', __( 'debe ser exactamente {globalModule, blockName}: el id del ítem global y su bloque (divi/text, divi/row, divi/section…).', 'nexo-mcp' ) );
		}

		$id    = $attrs['globalModule'];
		$block = $attrs['blockName'];

		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 || ! is_string( $block ) ) {
			return $this->error( $code, $path . '.attrs', __( 'globalModule debe ser el id entero positivo de un ítem global de la biblioteca y blockName un texto.', 'nexo-mcp' ) );
		}

		try {
			$actual = ( $this->global_item )( (int) $id );
		} catch ( \Throwable $e ) {
			$actual = null;
		}

		if ( ! is_string( $actual ) || '' === $actual ) {
			return $this->error( $code, $path . '.attrs', __( 'el ítem no existe, no es global o no está publicado (Divi no renderiza un global que no esté publicado).', 'nexo-mcp' ) );
		}

		if ( $actual !== $block ) {
			return $this->error( $code, $path . '.attrs', sprintf( /* translators: %s: bloque del ítem. */ __( 'blockName no coincide con el bloque del ítem (%s).', 'nexo-mcp' ), $actual ) );
		}

		if ( ! in_array( $parent_type, DiviModuleCatalog::global_ref_parents( $block ), true ) ) {
			return $this->error(
				'nexo_mcp_divi_nesting_invalid',
				$path . '.type',
				sprintf(
					/* translators: 1: bloque, 2: padre. */
					__( 'una referencia a «%1$s» no cabe dentro de «%2$s».', 'nexo-mcp' ),
					$block,
					$parent_type
				)
			);
		}

		return array(
			'type'  => DiviModuleCatalog::GLOBAL_REF,
			'attrs' => array(
				'globalModule' => (string) (int) $id,
				'blockName'    => $block,
			),
		);
	}

	/**
	 * Recorre atributos hasta las rutas del catálogo y valida cada hoja.
	 *
	 * @param array<int|string, mixed> $attrs   Objeto de atributos en el nivel `$dotted`.
	 * @param array<string, mixed>     $specs   Rutas admitidas (ruta → especificación).
	 * @param string                   $dotted  Ruta del catálogo del nivel actual (`title.innerContent`).
	 * @param string                   $display Ruta del nivel actual para los mensajes.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function walk_attrs( array $attrs, array $specs, string $dotted, string $display ) {
		$out = array();

		foreach ( $attrs as $key => $child ) {
			$key   = (string) $key;
			$path  = '' === $dotted ? $key : $dotted . '.' . $key;
			$shown = $display . '.' . $key;

			if ( array_key_exists( $path, $specs ) ) {
				$leaf = $this->walk_leaf( $child, $specs[ $path ], $shown, $path );

				if ( is_wp_error( $leaf ) ) {
					return $leaf;
				}

				$out[ $key ] = $leaf;
				continue;
			}

			if ( ! $this->is_prefix( $path, $specs ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown,
					__( 'este atributo no está en la lista admitida del módulo (sólo contenido y diseño de escritorio del catálogo).', 'nexo-mcp' )
				);
			}

			if ( ! is_array( $child ) || ( array() !== $child && array_is_list( $child ) ) ) {
				return $this->error( 'nexo_mcp_divi_tree_invalid', $shown, __( 'debe ser un objeto.', 'nexo-mcp' ) );
			}

			$inner = $this->walk_attrs( $child, $specs, $path, $shown );

			if ( is_wp_error( $inner ) ) {
				return $inner;
			}

			if ( array() !== $inner ) {
				$out[ $key ] = $inner;
			}
		}

		return $out;
	}

	/**
	 * Indica si `$path` es el comienzo de alguna ruta del catálogo.
	 *
	 * @param string               $path  Ruta parcial.
	 * @param array<string, mixed> $specs Rutas admitidas.
	 */
	private function is_prefix( string $path, array $specs ): bool {
		foreach ( array_keys( $specs ) as $known ) {
			if ( str_starts_with( (string) $known, $path . '.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Valida el valor de una ruta: sólo `{desktop: {value}}`.
	 *
	 * @param mixed  $node   Valor recibido.
	 * @param mixed  $spec   Especificación del catálogo.
	 * @param string $shown  Ruta para los mensajes.
	 * @param string $dotted Ruta del catálogo del atributo (`title.innerContent`).
	 *
	 * @return array{desktop: array{value: mixed}}|\WP_Error
	 */
	private function walk_leaf( $node, $spec, string $shown, string $dotted = '' ) {
		if ( ! is_array( $node ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown, __( 'debe ser {"desktop": {"value": …}}.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $node ) as $key ) {
			if ( 'desktop' !== $key ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown . '.' . $key,
					sprintf(
						/* translators: %s: clave rechazada (tablet, phone, hover…). */
						__( 'sólo se admite el valor de escritorio («desktop»); «%s» no se escribe.', 'nexo-mcp' ),
						(string) $key
					)
				);
			}
		}

		$desktop = $node['desktop'] ?? null;

		if ( ! is_array( $desktop ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown . '.desktop', __( 'debe ser {"value": …}.', 'nexo-mcp' ) );
		}

		foreach ( array_keys( $desktop ) as $key ) {
			if ( 'value' !== $key ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$shown . '.desktop.' . $key,
					sprintf(
						/* translators: %s: clave rechazada. */
						__( 'sólo se admite «value» en el valor de escritorio; «%s» no se escribe.', 'nexo-mcp' ),
						(string) $key
					)
				);
			}
		}

		if ( ! array_key_exists( 'value', $desktop ) ) {
			return $this->error( 'nexo_mcp_divi_value_invalid', $shown . '.desktop.value', __( 'falta el valor.', 'nexo-mcp' ) );
		}

		$where = $shown . '.desktop.value';

		if ( null !== $this->dynamic && 'module.meta.adminLabel' !== $dotted ) {
			// Con el contexto dinámico los tokens se resuelven cadena por cadena en check_value (aceptar uno o rechazarlo).
			$this->token_slot = true;
			$this->emitted    = array();

			try {
				$value = $this->check_value( $desktop['value'], $spec, $where );
			} finally {
				$this->token_slot = false;
			}

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			// Defensa en profundidad: sobre el valor final (en cualquier forma, también compuesta) sólo valen los tokens que este validador emitió en esa ruta.
			$stray = self::stray_token_path( $value, $where, $this->emitted );

			return null !== $stray ? $this->dynamic_error( $stray ) : array( 'desktop' => array( 'value' => $value ) );
		}

		// Antes de sanear: lo que manda el cliente. Después: lo que se guardaría (quitar etiquetas o normalizar una URL puede armar un token).
		$found = self::dynamic_token_path( $desktop['value'], $where );

		if ( null !== $found ) {
			return $this->dynamic_error( $found );
		}

		$value = $this->check_value( $desktop['value'], $spec, $where );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$found = self::dynamic_token_path( $value, $where );

		if ( null !== $found ) {
			return $this->dynamic_error( $found );
		}

		return array( 'desktop' => array( 'value' => $value ) );
	}

	/**
	 * Valida y sanea un valor según su especificación.
	 *
	 * @param mixed  $value Valor.
	 * @param mixed  $spec  Especificación: tipo escalar, `enum` u `object`.
	 * @param string $path  Ruta del valor.
	 *
	 * @return mixed|\WP_Error
	 */
	private function check_value( $value, $spec, string $path ) {
		if ( $this->token_slot && is_string( $value ) ) {
			return $this->check_string_with_tokens( $value, $spec, $path );
		}

		return $this->check_value_closed( $value, $spec, $path );
	}

	/**
	 * Valor de texto en un campo que admite token: un token válido se devuelve canónico; lo demás se sanea como siempre y no puede armar un token.
	 *
	 * @param string $value Valor.
	 * @param mixed  $spec  Especificación del campo.
	 * @param string $path  Ruta del valor.
	 *
	 * @return mixed|\WP_Error
	 */
	private function check_string_with_tokens( string $value, $spec, string $path ) {
		if ( self::has_dynamic_token( $value ) ) {
			return $this->check_token( $value, $spec, $path );
		}

		$clean = $this->check_value_closed( $value, $spec, $path );

		// Quitar etiquetas o normalizar una URL puede armar un token: se revisa lo que se guardaría.
		return is_string( $clean ) && self::has_dynamic_token( $clean ) ? $this->dynamic_error( $path ) : $clean;
	}

	/**
	 * Acepta un token (devolviendo su forma canónica) o lo rechaza con el código y la ruta del campo.
	 *
	 * @param string $value Valor con el token.
	 * @param mixed  $spec  Especificación del campo.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_token( string $value, $spec, string $path ) {
		$category = DiviDynamicCatalog::category_of_spec( $spec );

		if ( null === $category || null === $this->dynamic ) {
			return $this->error( 'nexo_mcp_divi_dynamic_denied', $path, __( 'este campo no admite contenido dinámico (sólo texto, enlaces e imágenes de contenido).', 'nexo-mcp' ) );
		}

		$token = $this->dynamic->validate_token( $value, $category, $this->dynamic_post, $this->loop_type );

		if ( $token instanceof \WP_Error ) {
			return $this->error( (string) $token->get_error_code(), $path, $token->get_error_message() );
		}

		$this->emitted[ $path ] = $token;

		return $token;
	}

	/**
	 * Valida y sanea un valor sin admitir tokens (el comportamiento de 1.9.1; el que resuelve cada hoja).
	 *
	 * @param mixed  $value Valor.
	 * @param mixed  $spec  Especificación.
	 * @param string $path  Ruta del valor.
	 *
	 * @return mixed|\WP_Error
	 */
	private function check_value_closed( $value, $spec, string $path ) {
		if ( is_array( $spec ) && isset( $spec['enum'] ) ) {
			if ( ! is_string( $value ) || ! in_array( $value, $spec['enum'], true ) ) {
				return $this->invalid( $path, sprintf( /* translators: %s: valores admitidos. */ __( 'debe ser uno de: %s.', 'nexo-mcp' ), implode( ', ', $spec['enum'] ) ) );
			}

			return $value;
		}

		if ( is_array( $spec ) && isset( $spec['object'] ) ) {
			return $this->check_object( $value, $spec['object'], $path );
		}

		if ( ! is_string( $spec ) ) {
			return $this->invalid( $path, __( 'tipo de valor desconocido.', 'nexo-mcp' ) );
		}

		if ( 'src' === $spec ) {
			return $this->check_src( $value, $path );
		}

		if ( ! is_string( $value ) ) {
			return $this->invalid( $path, __( 'debe ser un texto.', 'nexo-mcp' ) );
		}

		switch ( $spec ) {
			case 'plain':
				return $this->check_plain( $value, $path );
			case 'rich':
				return $this->check_rich( $value, $path );
			case 'link':
				return '' === $value ? '' : $this->check_url( $value, $path, false, __( 'debe ser una dirección http, https, mailto, tel o una ruta del sitio (/…, #…, ?…).', 'nexo-mcp' ) );
			case 'media_url':
				return $this->check_url( $value, $path, true, __( 'debe ser una dirección http, https o una ruta del sitio (/…).', 'nexo-mcp' ) );
			case 'color':
				return DiviModuleCatalog::is_color( $value ) ? $value : $this->invalid( $path, __( 'debe ser #hex, rgb()/rgba()/hsl()/hsla() o una variable global de color.', 'nexo-mcp' ) );
			case 'size':
				return DiviModuleCatalog::is_size( $value ) ? $value : $this->invalid( $path, __( 'debe ser un tamaño con unidad (px, em, rem, %, vw, vh).', 'nexo-mcp' ) );
			case 'size_or_empty':
				return '' === $value || DiviModuleCatalog::is_size( $value ) ? $value : $this->invalid( $path, __( 'debe ser vacío o un tamaño con unidad (px, em, rem, %, vw, vh).', 'nexo-mcp' ) );
			case 'menu_id':
				return $this->check_menu_id( $value, $path );
			case 'date_format':
				return 1 === preg_match( '/^[A-Za-z0-9 ,.:\/\\-]{1,30}\z/', $value ) ? $value : $this->invalid( $path, __( 'debe ser un formato de fecha de PHP de hasta 30 caracteres (letras, números, espacios y , . : / - \\).', 'nexo-mcp' ) );
			case 'onoff':
				return 'on' === $value || 'off' === $value ? $value : $this->invalid( $path, __( 'debe ser «on» u «off».', 'nexo-mcp' ) );
			case 'family':
				return 1 === preg_match( '/^[\p{L}\p{N} _\-]{1,80}\z/u', $value ) ? $value : $this->invalid( $path, __( 'debe ser un nombre de fuente (letras, números, espacios, - y _).', 'nexo-mcp' ) );
			case 'weight':
				return 1 === preg_match( '/^(?:[1-9]00|normal|bold)\z/', $value ) ? $value : $this->invalid( $path, __( 'debe ser 100-900, normal o bold.', 'nexo-mcp' ) );
			case 'icon_unicode':
				return 1 === preg_match( '/^(?:&#x[0-9a-f]{1,6};|%%\d{1,4}%%)\z/i', $value ) ? $value : $this->invalid( $path, __( 'debe ser un código de ícono de Divi (&#x21; o %%3%%).', 'nexo-mcp' ) );
			default:
				return $this->invalid( $path, __( 'tipo de valor desconocido.', 'nexo-mcp' ) );
		}
	}

	/**
	 * Id de un menú de navegación que existe: cadena de dígitos (Divi lo guarda así) y un menú real.
	 *
	 * Un id inexistente NO falla en Divi (L4 (e): cae al primer menú disponible): por eso se comprueba aquí.
	 *
	 * @param string $value Valor.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_menu_id( string $value, string $path ) {
		$reason = __( 'debe ser el id (como texto) de un menú de navegación que existe en el sitio.', 'nexo-mcp' );

		if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}\z/', $value ) ) {
			return $this->invalid( $path, $reason );
		}

		try {
			$exists = (bool) ( $this->menu_exists )( (int) $value );
		} catch ( \Throwable $e ) {
			$exists = false;
		}

		return $exists ? $value : $this->invalid( $path, $reason );
	}

	/**
	 * Valida un objeto de valor: sólo las claves de la especificación.
	 *
	 * @param mixed                $value  Valor.
	 * @param array<string, mixed> $fields Claves admitidas y su especificación.
	 * @param string               $path   Ruta del valor.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_object( $value, array $fields, string $path ) {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			return $this->invalid( $path, __( 'debe ser un objeto.', 'nexo-mcp' ) );
		}

		$out = array();

		foreach ( $value as $key => $child ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, $fields ) ) {
				return $this->error(
					'nexo_mcp_divi_attr_denied',
					$path . '.' . $key,
					sprintf(
						/* translators: 1: clave, 2: claves admitidas. */
						__( 'la clave «%1$s» no se admite aquí. Admitidas: %2$s.', 'nexo-mcp' ),
						$key,
						implode( ', ', array_keys( $fields ) )
					)
				);
			}

			$checked = $this->check_value( $child, $fields[ $key ], $path . '.' . $key );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$out[ $key ] = $checked;
		}

		return $out;
	}

	/**
	 * Valida una imagen: id de adjunto existente (se devuelve su URL) o URL http/https/relativa.
	 *
	 * @param mixed  $value Valor.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_src( $value, string $path ) {
		if ( is_int( $value ) ) {
			$url = $value > 0 ? ( $this->attachment_url )( $value ) : false;

			if ( ! is_string( $url ) || '' === $url ) {
				return $this->invalid( $path, __( 'no existe ningún adjunto con ese identificador.', 'nexo-mcp' ) );
			}

			return $url;
		}

		if ( ! is_string( $value ) ) {
			return $this->invalid( $path, __( 'debe ser el identificador de un adjunto o una URL http/https.', 'nexo-mcp' ) );
		}

		return $this->check_url( $value, $path, true, __( 'debe ser el identificador de un adjunto o una URL http/https.', 'nexo-mcp' ) );
	}

	/**
	 * Valida una URL con el catálogo y la normaliza con `esc_url_raw`.
	 *
	 * @param string $value  URL.
	 * @param string $path   Ruta del valor.
	 * @param bool   $media  true: URL de medio (sólo http/https o ruta); false: enlace (también mailto y tel).
	 * @param string $reason Motivo si no vale.
	 *
	 * @return string|\WP_Error
	 */
	private function check_url( string $value, string $path, bool $media, string $reason ) {
		$valid = $media ? DiviModuleCatalog::is_media_url( $value ) : DiviModuleCatalog::is_url( $value );
		$clean = $valid ? esc_url_raw( trim( $value ), $media ? array( 'http', 'https' ) : DiviModuleCatalog::SCHEMES ) : '';

		if ( '' === $clean ) {
			return $this->invalid( $path, $reason );
		}

		return $clean;
	}

	/**
	 * Texto sin marcado: se quitan las etiquetas (y el contenido de script/style) y no admite shortcodes.
	 *
	 * @param string $value Texto.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_plain( string $value, string $path ) {
		// Se revisa también el resultado: quitar etiquetas puede armar un shortcode (`[gall<b></b>ery]`).
		$clean     = wp_strip_all_tags( $this->without_active_blocks( $value ) );
		$shortcode = $this->registered_shortcode( $value ) ?? $this->registered_shortcode( $clean );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		return $clean;
	}

	/**
	 * Texto rico: sin shortcodes, enlaces seguros y sólo la lista corta de etiquetas.
	 *
	 * @param string $value HTML.
	 * @param string $path  Ruta del valor.
	 *
	 * @return string|\WP_Error
	 */
	private function check_rich( string $value, string $path ) {
		$shortcode = $this->registered_shortcode( $value );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		if ( preg_match_all( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $value, $matches, PREG_SET_ORDER ) > 0 ) {
			foreach ( $matches as $match ) {
				$href = $match[1] . ( $match[2] ?? '' ) . ( $match[3] ?? '' );

				if ( '' !== $href && ! DiviModuleCatalog::is_url( $href ) ) {
					return $this->invalid( $path, __( 'un enlace del texto usa una dirección no permitida (sólo http, https, mailto, tel o rutas del sitio).', 'nexo-mcp' ) );
				}
			}
		}

		// Lo que se guarda es la salida de wp_kses: también se revisa, porque quitar etiquetas puede armar un shortcode.
		$clean     = wp_kses( $this->without_active_blocks( $value ), self::ALLOWED_HTML, DiviModuleCatalog::SCHEMES );
		$shortcode = $this->registered_shortcode( $clean );

		if ( null !== $shortcode ) {
			return $this->shortcode_error( $path, $shortcode );
		}

		return $clean;
	}

	/**
	 * Quita los bloques `<script>` y `<style>` con su contenido.
	 *
	 * @param string $value HTML.
	 */
	private function without_active_blocks( string $value ): string {
		return (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value );
	}

	/**
	 * Primer shortcode registrado que aparece en el texto (también con corchetes escritos como entidad), o null.
	 *
	 * Sigue la gramática de WordPress (`get_shortcode_regex`): el nombre registrado va justo después
	 * de `[` o `[/` y termina donde sigue un carácter que no es letra, dígito, `_` ni `-`; así
	 * `[gallery.]` o `[caption,]` cuentan como `gallery` y `caption`.
	 *
	 * @param string $value Texto.
	 */
	private function registered_shortcode( string $value ): ?string {
		foreach ( array( $value, html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $text ) {
			if ( preg_match_all( '/\[\/?([^\s\[\]<>&\/=\x00-\x20]+)/', $text, $matches ) > 0 ) {
				foreach ( $matches[1] as $run ) {
					$name = $this->shortcode_prefix( $run );

					if ( null !== $name ) {
						return $name;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Prefijo de `$run` que es un shortcode registrado y que WordPress cortaría ahí, o null.
	 *
	 * @param string $run Caracteres seguidos tras `[`.
	 */
	private function shortcode_prefix( string $run ): ?string {
		$length = strlen( $run );

		for ( $end = 1; $end <= $length; $end++ ) {
			if ( $end < $length && 1 === preg_match( '/[\w-]/', $run[ $end ] ) ) {
				continue;
			}

			$name = substr( $run, 0, $end );

			if ( (bool) ( $this->shortcode_exists )( $name ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Indica si un valor (cadena o estructura, a cualquier profundidad) lleva un token de contenido dinámico de Divi.
	 *
	 * Divi 5 resuelve al renderizar toda cadena que contenga el literal `$variable(` (sensible a mayúsculas, sin
	 * decodificar entidades), pero se rechaza también un superset conservador: mayúsculas, espacios dentro del
	 * token, entidades HTML (`&#36;`, `&#x24;`, `&dollar;`, con o sin `;`), escapes JSON (`\u0024`, `\x24`),
	 * `%24`, etiquetas intercaladas (`$<b></b>variable(`) y el marcador heredado `@ET-DC@` de Divi 4.
	 *
	 * @param mixed $value Valor a revisar.
	 */
	public static function has_dynamic_token( $value ): bool {
		return null !== self::dynamic_token_path( $value, '' );
	}

	/**
	 * Ruta (relativa, `.clave.sub`) de la primera cadena con un token dinámico dentro de un valor, o null si no hay.
	 *
	 * @param mixed  $value  Valor a revisar.
	 * @param string $prefix Ruta acumulada.
	 */
	private static function dynamic_token_path( $value, string $prefix ): ?string {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$found = self::dynamic_token_path( $item, $prefix . '.' . $key );

				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		}

		return is_string( $value ) && self::string_has_token( $value ) ? $prefix : null;
	}

	/**
	 * Ruta de la primera cadena con un token que no está en la lista de las permitidas (ruta → cadena exacta), o null.
	 *
	 * Reescaneo del valor final: acepta un token sólo si es EXACTAMENTE la cadena permitida en esa misma ruta, sin
	 * importar la forma del valor (cadena, objeto o lista anidada). Cualquier otra forma, codificada o no, en cualquier
	 * otra ruta, se reporta.
	 *
	 * @param mixed                 $value   Valor final.
	 * @param string                $prefix  Ruta del valor (las claves se agregan con «.»).
	 * @param array<string, string> $allowed Tokens permitidos por ruta.
	 */
	public static function stray_token_path( $value, string $prefix, array $allowed ): ?string {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$found = self::stray_token_path( $item, '' === $prefix ? (string) $key : $prefix . '.' . $key, $allowed );

				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		}

		return is_string( $value ) && self::string_has_token( $value ) && ( $allowed[ $prefix ] ?? null ) !== $value ? $prefix : null;
	}

	/**
	 * Cadenas con token de un valor, por ruta (para guardar las que ya había antes de una edición).
	 *
	 * @param mixed  $value  Valor.
	 * @param string $prefix Ruta del valor.
	 *
	 * @return array<string, string>
	 */
	public static function token_strings( $value, string $prefix ): array {
		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $item ) {
				$out += self::token_strings( $item, '' === $prefix ? (string) $key : $prefix . '.' . $key );
			}

			return $out;
		}

		return is_string( $value ) && self::string_has_token( $value ) ? array( $prefix => $value ) : array();
	}

	/**
	 * Indica si una cadena lleva un token, tras decodificar hasta 4 capas.
	 *
	 * @param string $value Cadena.
	 */
	private static function string_has_token( string $value ): bool {

		$text = $value;

		for ( $pass = 0; $pass < 4; $pass++ ) {
			$variants = array( $text, (string) preg_replace( '/<[^>]*>/', '', $text ), (string) preg_replace( '/<[^>]*>|[\x00-\x08\x0E-\x1F\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]+/u', '', $text ) );

			foreach ( $variants as $variant ) {
				if ( 1 === preg_match( '/\$\s*variable\s*\(|@ET-DC@/i', $variant ) ) {
					return true;
				}
			}

			$decoded = self::decode_once( $text );

			if ( $decoded === $text ) {
				return false;
			}

			$text = $decoded;
		}

		return true;
	}

	/**
	 * Una capa de decodificación: entidades de `$`, entidades HTML, escapes `\uXXXX`/`\xXX` y `%XX`.
	 *
	 * @param string $text Texto.
	 */
	private static function decode_once( string $text ): string {
		$text = (string) preg_replace( array( '/&#0*36(?!\d);?/', '/&#x0*24(?![0-9a-f]);?/i', '/&dollar;?/i' ), '$', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace_callback(
			'/\\\\+(?:u([0-9a-f]{4})|x([0-9a-f]{2}))/i',
			static function ( array $m ): string {
				$code = hexdec( '' !== $m[1] ? $m[1] : $m[2] );

				return $code < 128 ? chr( (int) $code ) : "\u{FFFD}";
			},
			$text
		);

		return rawurldecode( $text );
	}

	/**
	 * Error de contenido dinámico no habilitado.
	 *
	 * @param string $path Ruta del valor.
	 */
	public function dynamic_error( string $path ): \WP_Error {
		return $this->error( 'nexo_mcp_divi_dynamic_denied', $path, self::dynamic_reason() );
	}

	/**
	 * Motivo del rechazo de un token dinámico.
	 */
	public static function dynamic_reason(): string {
		return __( 'el contenido dinámico de Divi ($variable(…)$) no está habilitado: el valor no puede llevar tokens dinámicos.', 'nexo-mcp' );
	}

	/**
	 * Error de shortcode.
	 *
	 * @param string $path Ruta del valor.
	 * @param string $name Shortcode.
	 */
	private function shortcode_error( string $path, string $name ): \WP_Error {
		return $this->error(
			'nexo_mcp_divi_shortcode_denied',
			$path,
			sprintf(
				/* translators: %s: nombre del shortcode. */
				__( 'el texto contiene el shortcode «[%s]»: los shortcodes ejecutan código y no se admiten.', 'nexo-mcp' ),
				$name
			)
		);
	}

	/**
	 * Motivo por el que un módulo no se admite.
	 *
	 * @param string $type Tipo recibido.
	 */
	private function denied_reason( string $type ): string {
		return sprintf(
			/* translators: 1: tipo, 2: módulos admitidos. */
			__( '«%1$s» no está en el catálogo de módulos de Divi 5 admitidos (el código, el HTML crudo y los shortcodes nunca se crean). Admitidos: %2$s.', 'nexo-mcp' ),
			$type,
			implode( ', ', DiviModuleCatalog::modules( $this->layout ) )
		);
	}

	/**
	 * Error de valor.
	 *
	 * @param string $path   Ruta del valor.
	 * @param string $reason Motivo.
	 */
	private function invalid( string $path, string $reason ): \WP_Error {
		return $this->error( 'nexo_mcp_divi_value_invalid', $path, $reason );
	}

	/**
	 * Construye un error con la ruta en el mensaje y en los datos.
	 *
	 * @param string $code   Código de la regla.
	 * @param string $path   Ruta del nodo o atributo.
	 * @param string $reason Motivo.
	 */
	private function error( string $code, string $path, string $reason ): \WP_Error {
		return new \WP_Error( $code, $path . ': ' . $reason, array( 'path' => $path ) );
	}
}
