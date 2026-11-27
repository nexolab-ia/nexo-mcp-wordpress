<?php
/**
 * Descripciones y esquemas JSON de las herramientas de la biblioteca de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

use NexoDev\NexoMCP\Builders\Divi5SectionEditor;
use NexoDev\NexoMCP\Builders\DiviLibraryInserter;
use NexoDev\NexoMCP\Builders\DiviLibrarySchema;

/**
 * Textos y esquemas de `list-divi-library`, `get-divi-library-item`, `save-divi-library-item`, `delete-divi-library-item` y
 * `restore-divi-library-item` (T9). Viven aparte para no engordar {@see ExtendedAbilityRegistrar}.
 */
final class DiviLibraryToolSpec {

	/**
	 * Permiso y requisitos comunes a todas las herramientas.
	 */
	private static function access(): string {
		return 'Requiere el scope mcp:divi_library (interruptor encendido por el administrador) y los permisos de biblioteca de Divi (divi_library, y save_library para crear; edit_global_library para ítems globales): nexo_mcp_divi_library_denied sin ellos, antes de mirar la entrada. Requiere Divi 5 activo (nexo_mcp_divi5_required). Un ítem en la papelera o en borrador automático no se lee ni se cambia (nexo_mcp_divi_library_trashed / nexo_mcp_divi_library_not_found).';
	}

	/**
	 * Descripción de list-divi-library.
	 */
	public static function list_description(): string {
		return implode(
			"\n",
			array(
				'Lista los ítems de la biblioteca de Divi 5 (layouts, secciones, filas y módulos guardados, globales o no) sin su contenido; sólo lee. ' . self::access(),
				'',
				'ENTRADA (todo opcional): type (layout, section, row o module), scope (global o non_global), category (nombre de categoría), search (texto del título) y limit (1 a 100, 50 por omisión). Se listan los ítems publicados, en borrador, privados, pendientes o programados; los de la papelera no salen.',
				'',
				'SALIDA: items [{id, title, status, type, scope, categories, module_type, modified}] del más reciente al más antiguo, count, truncated (true si hay más de los que caben en limit o se llegó al tope de 300 ítems revisados) y limits.',
				'',
				'ERRORES: nexo_mcp_divi_library_denied, nexo_mcp_divi5_required y nexo_mcp_divi_library_invalid (con data.path). Nunca cambia nada.',
			)
		);
	}

	/**
	 * Esquema de list-divi-library.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'     => array(
					'type'        => 'string',
					'enum'        => DiviLibrarySchema::TYPES,
					'description' => 'Tipo de ítem.',
				),
				'scope'    => array(
					'type'        => 'string',
					'enum'        => DiviLibrarySchema::SCOPES,
					'description' => 'global: lo que cambia en todas las páginas que lo usan; non_global: se copia.',
				),
				'category' => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Nombre de la categoría de la biblioteca.',
				),
				'search'   => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Texto que debe contener el título.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => DiviLibrarySchema::MAX_LIST,
					'description' => 'Cuántos ítems devolver (50 por omisión).',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de get-divi-library-item.
	 */
	public static function get_description(): string {
		return implode(
			"\n",
			array(
				'Lee UN ítem de la biblioteca de Divi 5 con su resumen y la lista plana de sus nodos (ruta, tipo y huella); sólo lee. ' . self::access(),
				'',
				'ENTRADA: id (de nexo-mcp-list-divi-library).',
				'',
				'SALIDA: id, title, status, type, scope, categories, module_type, modified, global (true si cambiarlo cambiaría todas las páginas que lo usan; los globales sólo se leen), foreign (los tipos de bloque que el conector no sabe editar, o legacy si el contenido no es de Divi 5: reemplazar el contenido exige replace_foreign), backup ({operation, captured_at} del respaldo que se restaura con nexo-mcp-restore-divi-library-item, o null) y nodes [{path, type, fingerprint}].',
				'',
				'ERRORES: nexo_mcp_divi_library_denied (también un id que no es un ítem de la biblioteca), nexo_mcp_divi5_required, nexo_mcp_divi_library_invalid (con data.path), nexo_mcp_divi_library_trashed y nexo_mcp_divi_library_not_found. Nunca cambia nada.',
			)
		);
	}

	/**
	 * Esquema de get-divi-library-item.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Ítem de la biblioteca.',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de save-divi-library-item.
	 */
	public static function save_description(): string {
		return implode(
			"\n",
			array(
				'Crea (sin id) o ACTUALIZA (con id) UN ítem de la biblioteca de Divi 5 a partir de un árbol de módulos validado, con los mismos términos y metas que el Visual Builder (layout_type, scope, module_width, _et_pb_template_type, _et_pb_module_type, _et_pb_use_divi_5 y _et_pb_excluded_global_options). ' . self::access(),
				'',
				'ALTA (sin id): title (1 a 100 caracteres, sin < >), type (layout: una o más secciones; section: exactamente una sección; row: exactamente una fila; module: exactamente un módulo del catálogo) y nodes (lista de nodos como en nexo-mcp-create-divi-page: {type, attrs?, children?, preset?}; el árbol se guarda bajo un divi/placeholder y pasa por el mismo validador de Divi 5 que las páginas, sin los módulos del Theme Builder). Opcionales: scope (non_global por omisión, o global), categories (hasta 10 nombres, sin comas; se crean si no existen). El ítem nace publicado.',
				'',
				'ACTUALIZACIÓN (con id): se cambia lo que se pase entre title, nodes (reemplaza TODO el contenido) y categories (la lista completa; [] las quita); type y scope no se pueden cambiar (si se pasan deben coincidir). Un ítem GLOBAL cambia todas las páginas que lo usan SIN tocarlas (Divi lo lee al renderizar): sin confirm_global: true no se actualiza (nexo_mcp_divi_library_global_blocked); con él se actualiza y la respuesta trae affected_pages ({count, pages [{id, title, post_type, status, working_copy_of?, editable}], truncated}: los publicados, borradores, privados y copias de trabajo que lo referencian), además del respaldo; hace falta también el permiso edit_global_library de Divi. Si el contenido actual trae módulos que el conector no sabe representar (nexo-mcp-get-divi-library-item los lista en foreign), reemplazarlo exige replace_foreign: true (nexo_mcp_divi_library_foreign) y queda en el respaldo.',
				'',
				'RESPALDO: antes de actualizar se guarda el estado anterior (título, estado, contenido, categorías y módulo; una sola copia, la del último cambio) en la meta _nexo_mcp_library_backup del ítem; sin respaldo no se escribe (nexo_mcp_divi_library_backup_failed). DESHACER: nexo-mcp-restore-divi-library-item con el id. Si la escritura falla a medias el ítem vuelve a su estado anterior (nexo_mcp_divi_library_save_failed); un alta a medias se borra.',
				'',
				'SALIDA: id, created, title, status, type, scope, categories, module_type, modified, nodes [{path, type, fingerprint}], previous ({title, categories} de antes; null al crear), backup ({operation, captured_at}; null al crear), warnings y, si el ítem es global, affected_pages.',
				'',
				'ERRORES (no escriben nada, salvo que se diga): nexo_mcp_divi_library_denied, nexo_mcp_divi5_required, nexo_mcp_divi_library_invalid (con data.path; también un confirm_global que no es true o false), los de forma del árbol de Divi (nexo_mcp_divi_tree_invalid, nexo_mcp_divi_nesting_invalid, nexo_mcp_divi_module_unknown…), nexo_mcp_divi_library_trashed, nexo_mcp_divi_library_not_found, nexo_mcp_divi_library_global_blocked, nexo_mcp_divi_library_foreign (con data.foreign), nexo_mcp_divi_library_backup_failed, nexo_mcp_busy (otra llamada sobre el ítem en curso: reintenta) y nexo_mcp_divi_library_save_failed.',
			)
		);
	}

	/**
	 * Esquema de save-divi-library-item.
	 *
	 * @return array<string, mixed>
	 */
	public static function save_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Ítem que se actualiza; sin id se crea uno.',
				),
				'title'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => DiviLibrarySchema::MAX_TITLE,
					'description' => 'Título del ítem (obligatorio al crear).',
				),
				'type'            => array(
					'type'        => 'string',
					'enum'        => DiviLibrarySchema::TYPES,
					'description' => 'layout, section, row o module (obligatorio al crear; no cambia).',
				),
				'scope'           => array(
					'type'        => 'string',
					'enum'        => DiviLibrarySchema::SCOPES,
					'description' => 'non_global (por omisión) o global. No cambia en un ítem que ya existe.',
				),
				'nodes'           => array(
					'type'        => 'array',
					'minItems'    => 1,
					'items'       => array( 'type' => 'object' ),
					'description' => 'Árbol de módulos de Divi 5 (obligatorio al crear): un layout lleva secciones; una sección, una sección; una fila, una fila; un módulo, un módulo.',
				),
				'categories'      => array(
					'type'        => 'array',
					'maxItems'    => DiviLibrarySchema::MAX_CATEGORIES,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => DiviLibrarySchema::MAX_CATEGORY,
					),
					'description' => 'Nombres de las categorías de la biblioteca (la lista completa).',
				),
				'replace_foreign' => array(
					'type'        => 'boolean',
					'description' => 'Confirma reemplazar contenido que el conector no sabe representar (queda en el respaldo).',
				),
				'confirm_global'  => self::confirm_property(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Descripción de delete-divi-library-item.
	 */
	public static function delete_description(): string {
		return implode(
			"\n",
			array(
				'Manda UN ítem de la biblioteca de Divi 5 a la papelera de WordPress (no lo elimina) tras guardar un respaldo. ' . self::access(),
				'',
				'ÍTEMS GLOBALES: los usan otras páginas por referencia. Sin confirm_global: true no se borra (nexo_mcp_divi_library_global_blocked). Con él, ANTES de mandarlo a la papelera Divi despega las instancias (su filtro pre_trash_post incrusta el contenido del ítem en cada página, como en el Visual Builder), así que las páginas conservan su contenido; el conector sólo lo permite si ese filtro existe (nexo_mcp_divi_library_detach_unavailable), si puedes editar TODAS las páginas que lo usan (nexo_mcp_divi_library_pages_forbidden, con data.pages) y comprueba después que no queda ninguna referencia (si queda, devuelve el ítem y da nexo_mcp_divi_library_detach_failed con data.pages). Restaurar el ítem después NO vuelve a enlazar las páginas despegadas. Hace falta además edit_global_library. Si la papelera de WordPress está desactivada (EMPTY_TRASH_DAYS = 0) borrar sería permanente y no se hace (nexo_mcp_divi_library_trash_disabled).',
				'',
				'ENTRADA: id (de nexo-mcp-list-divi-library).',
				'',
				'RESPALDO: antes de borrar se guarda el estado (título, estado, contenido, categorías y módulo; una sola copia) en la meta _nexo_mcp_library_backup del ítem; sin respaldo no se borra (nexo_mcp_divi_library_backup_failed). DESHACER: nexo-mcp-restore-divi-library-item con el id lo saca de la papelera y lo deja como estaba. Si la papelera falla el ítem queda como estaba (nexo_mcp_divi_library_save_failed).',
				'',
				'SALIDA: id, deleted, status (trash), title, type, scope, backup ({operation, captured_at}), warnings y, si el ítem es global, affected_pages (las páginas que lo usaban, antes de despegarlas) y still_referenced (0).',
				'',
				'ERRORES (no cambian nada): nexo_mcp_divi_library_denied, nexo_mcp_divi5_required, nexo_mcp_divi_library_invalid (con data.path), nexo_mcp_divi_library_trashed, nexo_mcp_divi_library_not_found, nexo_mcp_divi_library_global_blocked, nexo_mcp_divi_library_pages_forbidden, nexo_mcp_divi_library_detach_unavailable, nexo_mcp_divi_library_detach_failed, nexo_mcp_divi_library_trash_disabled, nexo_mcp_divi_library_backup_failed, nexo_mcp_busy (otra llamada sobre el ítem en curso: reintenta) y nexo_mcp_divi_library_save_failed.',
			)
		);
	}

	/**
	 * Descripción de restore-divi-library-item.
	 */
	public static function restore_description(): string {
		return implode(
			"\n",
			array(
				'Devuelve UN ítem de la biblioteca de Divi 5 al estado de su respaldo (la meta _nexo_mcp_library_backup del propio ítem: título, estado, contenido, categorías y módulo). Sirve para deshacer nexo-mcp-save-divi-library-item y también tras nexo-mcp-delete-divi-library-item (el ítem borrado está en la papelera y se saca de ahí). Antes de escribir se guarda el estado de ahora como respaldo nuevo, así que restaurar dos veces alterna entre los dos estados. ' . self::access(),
				'',
				'Un ítem GLOBAL sólo se restaura con confirm_global: true (nexo_mcp_divi_library_global_blocked sin él): cambia todas las páginas que lo usan; la respuesta trae affected_pages. Sin respaldo, nexo_mcp_divi_library_no_backup. El tipo y el alcance no cambian.',
				'',
				'ENTRADA: id (de nexo-mcp-list-divi-library o de la salida de nexo-mcp-delete-divi-library-item).',
				'',
				'SALIDA: id, restored, title, status, type, scope, categories, module_type, modified, nodes, previous ({title, categories, status} de antes de restaurar), restored_from ({operation, captured_at} del respaldo usado), backup (el respaldo nuevo del estado anterior), warnings y, si el ítem es global, affected_pages. Si algo falla a medias el ítem vuelve a su estado anterior (nexo_mcp_divi_library_save_failed).',
				'',
				'ERRORES (no cambian nada): nexo_mcp_divi_library_denied, nexo_mcp_divi5_required, nexo_mcp_divi_library_invalid (con data.path), nexo_mcp_divi_library_not_found, nexo_mcp_divi_library_global_blocked, nexo_mcp_divi_library_no_backup, nexo_mcp_divi_library_backup_failed, nexo_mcp_busy (otra llamada sobre el ítem en curso: reintenta) y nexo_mcp_divi_library_save_failed.',
			)
		);
	}

	/**
	 * Descripción de insert-divi-library-item.
	 */
	public static function insert_description(): string {
		return implode(
			"\n",
			array(
				'Inserta UN ítem de la biblioteca de Divi 5 en una página de Divi 5, como COPIA de su contenido o, si es global, como REFERENCIA (divi/global-layout). Escribe en la copia de trabajo de la página (se crea o se reutiliza; el original publicado no cambia hasta que revises y publiques la copia; nunca publica), con el mismo camino, bloqueo y respaldo que nexo-mcp-edit-divi-section. ' . self::access() . ' Además exige el permiso de constructores sobre la página (scope mcp:builders y edit_post): las dos capas, sin una de ellas no se escribe nada.',
				'',
				'ENTRADA: id (el ítem; de nexo-mcp-list-divi-library), post_id (la página de Divi 5 o su copia de trabajo), path y fingerprint (el nodo de referencia, como los devuelve nexo-mcp-get-page-structure), position (before, after o inside: dónde queda respecto al nodo) y, opcionales, mode (copy por omisión, o global) y draft (como en edit-divi-section).',
				'',
				'MODE copy: el contenido del ítem se convierte en nodos y pasa por el mismo validador de Divi 5 que cualquier subárbol; no se sincroniza con el ítem. Un layout y una sección van entre las secciones de la página (path de una sección, before o after; o el placeholder raíz con inside), una fila dentro de una sección (path de una sección con inside, o de una fila con before/after), un módulo dentro de una columna. Un ítem con módulos que el conector no sabe representar (nexo_mcp_divi_library_foreign, con data.foreign) o con atributos que el validador no admite (nexo_mcp_divi_library_copy_invalid, con data.reason y data.path) no se copia: si es global, insértalo con mode global. Copiar un ítem global inserta un contenido propio, sin referencia.',
				'',
				'MODE global: sólo un ítem GLOBAL publicado de tipo sección, fila o módulo (nexo_mcp_divi_library_mode_invalid si no: Divi no renderiza un global que no esté publicado, y un layout global no se referencia). Se escribe `<!-- wp:divi/global-layout {"globalModule":"<id>","blockName":"<divi/text|divi/row|divi/section…>"} /-->`, la forma de Divi 5.19.1, sin localAttrs: la página muestra el contenido del ítem en cada render y cualquier cambio del ítem cambia esta página sin tocarla (editarlo pide confirm_global en nexo-mcp-save-divi-library-item). La referencia sólo cabe donde cabe su bloque (nexo_mcp_divi_nesting_invalid).',
				'',
				'SALIDA: la de nexo-mcp-edit-divi-section (id y draft_of de la copia de trabajo, operation, nodes [{path, type, fingerprint}], backup, warnings…) más mode e item ({id, title, type, scope}).',
				'',
				'ERRORES (no escriben nada): nexo_mcp_divi_library_denied (también un id que no es un ítem de la biblioteca), nexo_mcp_divi5_required, nexo_mcp_divi_library_invalid (con data.path), nexo_mcp_divi_library_trashed, nexo_mcp_divi_library_not_found, nexo_mcp_divi_library_mode_invalid, nexo_mcp_divi_library_foreign, nexo_mcp_divi_library_copy_invalid, nexo_mcp_builder_forbidden / nexo_mcp_builder_not_found / nexo_mcp_divi_post_type_denied (la página), nexo_mcp_divi_node_changed (la huella no coincide: vuelve a leer la página), nexo_mcp_builder_node_missing, nexo_mcp_divi_nesting_invalid y los demás de forma de nexo-mcp-edit-divi-section.',
			)
		);
	}

	/**
	 * Esquema de insert-divi-library-item.
	 *
	 * @return array<string, mixed>
	 */
	public static function insert_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Ítem de la biblioteca que se inserta.',
				),
				'post_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Página de Divi 5 (o su copia de trabajo) donde se inserta.',
				),
				'path'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Ruta del nodo de referencia (por ejemplo 0.1; ver get-page-structure).',
				),
				'fingerprint' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => 'Huella del nodo tal como la devolvió la lectura.',
				),
				'position'    => array(
					'type'        => 'string',
					'enum'        => Divi5SectionEditor::POSITIONS,
					'description' => 'Antes, después o dentro (al final) del nodo de referencia.',
				),
				'mode'        => array(
					'type'        => 'string',
					'enum'        => DiviLibraryInserter::MODES,
					'description' => 'copy (por omisión): copia el contenido. global: referencia a un ítem global publicado (sección, fila o módulo).',
				),
				'draft'       => array(
					'type'        => 'boolean',
					'description' => 'Si el sitio no usa copia de trabajo por defecto, pide editar la copia de una página publicada.',
				),
			),
			'required'             => array( 'id', 'post_id', 'path', 'fingerprint', 'position' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de una herramienta que sólo recibe el id de un ítem.
	 *
	 * @param string $description Descripción del id.
	 *
	 * @return array<string, mixed>
	 */
	public static function id_schema( string $description ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => $description,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Esquema de una herramienta que recibe el id de un ítem y `confirm_global` (borrar y restaurar).
	 *
	 * @param string $description Descripción del id.
	 *
	 * @return array<string, mixed>
	 */
	public static function id_confirm_schema( string $description ): array {
		$schema                                 = self::id_schema( $description );
		$schema['properties']['confirm_global'] = self::confirm_property();

		return $schema;
	}

	/**
	 * Propiedad `confirm_global`.
	 *
	 * @return array<string, mixed>
	 */
	private static function confirm_property(): array {
		return array(
			'type'        => 'boolean',
			'description' => 'Confirma cambiar un ítem GLOBAL, que cambia todas las páginas que lo usan sin tocarlas. Sin él, un ítem global se rechaza (nexo_mcp_divi_library_global_blocked).',
		);
	}
}
