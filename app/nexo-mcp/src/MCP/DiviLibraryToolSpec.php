<?php
/**
 * Descripciones y esquemas JSON de las herramientas de la biblioteca de Divi 5.
 *
 * @package NexoDev\NexoMCP
 */

declare(strict_types=1);

namespace NexoDev\NexoMCP\MCP;

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
}
