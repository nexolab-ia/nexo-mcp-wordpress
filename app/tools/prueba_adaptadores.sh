#!/usr/bin/env bash
# Prueba de los adaptadores de Nexo MCP contra los plugins reales del docker.
#
# 1. Instala/activa Yoast SEO, ACF y Elementor (tools/instalar_plugins_prueba.sh).
# 2. Prepara con wp-cli lo que las herramientas MCP no pueden crear: un grupo de
#    campos ACF y una página borrador de Elementor con un árbol mínimo válido.
# 3. Corre tools/prueba_adaptadores.py (OAuth real + herramientas MCP).
# 4. Comprueba con wp-cli que los valores quedaron guardados donde los lee el
#    plugin real: metadatos de Yoast, valor de ACF y `_elementor_data`.
# 5. Elementor 4: activa el experimento atómico, crea con los repositorios de
#    Elementor una clase global, una variable y una página con un árbol atómico, y
#    comprueba por MCP get-design-system y la lectura atómica de get-page-structure.
# 6. Crea una página atómica por MCP con create-elementor-page (borrador, con
#    estilo local y clase global), comprueba su lectura, lo guardado y el
#    renderizado, y que cada rechazo (e-svg, widget v3, javascript:, imagen por
#    URL, clave desconocida, valor inválido, experimento apagado) no crea nada.
# 7. Reconstruye secciones por MCP con edit-elementor-section sobre una página v3 y
#    una atómica publicadas: cada edición cae en una copia de trabajo (el original
#    no cambia), se revisa con get-working-copy-diff, se renderiza y se publica
#    (fusión); y cada rechazo no crea copia ni cambia el original.
# 7b. Migra una página v3 entera a atómicos por MCP con migrate-elementor-page (F5): va a la
#    copia de trabajo (el original no cambia), los nodos sin equivalente quedan v3 idénticos y
#    listados en kept_v3, Elementor carga el árbol guardado y la copia renderiza; publicada, el
#    frontend responde 200 con el texto; los rechazos (nada que migrar, Editor V4 apagado) no
#    crean copia.
# 7c. Datos dinámicos (F6, sólo con Elementor Pro; sin Pro se saltea con un SKIP y no falla):
#    list-dynamic-tags (catálogo cerrado, etiquetas reales); set-dynamic-tag denegado con el interruptor
#    builders_dynamic_enabled apagado y, encendido, post-title (before/after), site-title y e-image.image
#    con post-featured-image/site-logo en la copia de trabajo (el original no cambia, la vista previa real
#    resuelve el texto); ajustes con HTML se guardan sin etiquetas; tags fuera del catálogo y categorías que no
#    coinciden se rechazan; edit-elementor-section conserva la prop dinámica; publicada, el frontend la resuelve.
#    El interruptor y la capacidad se devuelven al final (también si la corrida se corta).
# 7d. Componentes (F7, sólo con Elementor Pro con licencia activa; sin eso se saltea con un SKIP y no falla):
#    create-component (árbol atómico → borrador con uid propio), list-components y insert-component en una página
#    publicada (va a la copia, con la instancia e-component exacta; el original no cambia hasta publicar; publicada, el
#    frontend renderiza el componente); rechazos sin escribir: widget v3, título duplicado (mensaje sin entidades
#    HTML), component_id inexistente y un e-component por edit-elementor-section. builders_components_enabled
#    y la capacidad se devuelven al final y los componentes se borran (force), también si la corrida se corta.
# 7e. Divi 5 (sólo con el tema Divi 5 activo; si está instalado y no activo se activa sólo para el bloque y se
#    devuelve el tema previo al final; sin Divi 5 se saltea con un SKIP y no falla): create-divi-page (sección>fila>
#    columna>texto+título+botón+imagen), get-page-structure (nodos divi5 con path y fingerprint), edit-divi-module
#    sobre una publicada (copia; el original no cambia), edit-divi-section (reemplaza una sección), publicar la copia
#    (el frontend muestra los textos nuevos y ningún comentario de bloque crudo); rechazos sin escribir ni crear
#    copia: divi/code, shortcode en un texto, enlace javascript:, huella vieja y el divi/placeholder raíz. Se borran
#    (force) las páginas y sus directorios et-cache, también si la corrida se corta.
# 7f. Diseño global de Divi 5 (D3; sólo con Divi 5 activo, con SKIP si falta): get-divi-design-system, set-divi-global-color
#    (crear y cambiar), una página Divi 5 publicada que usa el color (el CSS del frontend trae --gcid-…),
#    delete-divi-global-color sin force → nexo_mcp_divi_color_in_use sin escribir, set-divi-variable +
#    archive-divi-variable, set-divi-typography (el tamaño del cuerpo se ve en el CSS del frontend) y
#    undo-divi-design-change de cada cambio (en orden inverso); rechazos sin escribir (color inválido, enlace
#    javascript:, valor con salto de línea final, id inexistente). Se guardan crudos et_divi,
#    et_divi_global_variables, el historial y los ajustes al empezar y se devuelven al final (también si la
#    corrida se corta: Divi agrega 5 colores del Customizer a et_divi al escribir la paleta y deshacer no los quita);
#    el interruptor divi_design_tools_enabled y la capacidad se devuelven; se borra la página y su et-cache.
# 7g. Presets de módulos de Divi 5 (D4; sólo con Divi 5 activo, con SKIP si falta): get-divi-presets, set-divi-preset
#    (módulo y grupo), edit-divi-module con preset sobre una página publicada (va a la copia; el original no cambia;
#    publicada la copia, el CSS del frontend trae la regla de la clase preset--module--… y preset--group--…),
#    get-page-structure con las referencias, set-divi-default-preset, delete-divi-preset en uso → nexo_mcp_divi_preset_in_use
#    sin escribir, y undo-divi-design-change {area: presets} de cada cambio; rechazos sin escribir (preset inexistente,
#    modulePreset crudo en attrs, attrs con manejador de eventos). Se guardan crudos et_divi_builder_global_presets_d5,
#    el historial, los ajustes y el bloqueo al empezar y se devuelven al final (también si la corrida se corta);
#    el interruptor divi_presets_tools_enabled y la capacidad se devuelven; se borra la página y su et-cache.
# 7h. Contenido dinámico y bucles de Divi 5 (D5/D6; sólo con Divi 5 activo, con SKIP si falta): con el interruptor
#    divi_dynamic_tools_enabled encendido sólo durante el bloque y la capacidad nexo_mcp_divi_dynamic:
#    list-divi-dynamic-sources, set-divi-dynamic post_title en un título (la copia lleva el token canónico, el original no
#    cambia; publicada, un visitante anónimo ve el título de la página y ningún `$variable(`), set-divi-loop en una
#    columna con loop_post_title en el título hijo (entradas de prueba: 4 publicadas, una privada, una con contraseña y un
#    borrador): el anónimo ve exactamente las publicadas sin contraseña (3 con per_page 3 en orden de fecha, la página 2 por
#    ?loop-nx…=2, todas con per_page 24); un valor del bucle editado a mano después (tipo any, desplazamiento 5000) se
#    renderiza vacío (guarda de render); rechazos sin escribir (meta personalizado, post_meta_key, categoría que no
#    coincide, ajustes con HTML, edit-divi-module con un token, loop_* fuera de un bucle, interruptor apagado, bucle any,
#    attachment, per_page 25, meta_query, bucle anidado) y quitar el token y el bucle. Se guardan crudos los ajustes, el
#    bloqueo y los roles al empezar y se devuelven al final (también si la corrida se corta); las entradas de prueba y las
#    páginas (con su et-cache) se borran (force).
# 7i. Condiciones de visualización de Divi 5 (D7; sólo con Divi 5 activo, con SKIP si falta): con el interruptor
#    divi_conditions_tools_enabled encendido sólo durante el bloque y la capacidad nexo_mcp_divi_conditions:
#    list-divi-conditions (catálogo cerrado, sin customField; se lee también con el interruptor apagado) y una página
#    publicada con cuatro títulos: set-divi-conditions logged_in en uno, url_parameter en otro y user_role administrator
#    en un tercero (copia de trabajo; el original no cambia); publicada, el visitante anónimo no ve ni el de sesión ni el
#    de rol ni el del parámetro, con ?promo=si ve el del parámetro, y con la sesión del administrador ve el de sesión y el
#    de rol; el texto de un módulo oculto sigue siendo buscable en el sitio (no es control de acceso); get-page-structure
#    resume las condiciones (attributes.conditions) y los demás módulos no traen la clave; rechazos sin escribir ni crear
#    copia (customField y match faltante, que frena el esquema de entrada; fecha inexistente, interruptor
#    apagado, edit-divi-module con module.decoration.conditions → nexo_mcp_divi_attr_denied); una lista ajena (hecha a mano) exige replace_foreign
#    (nexo_mcp_divi_conditions_foreign) y se reemplaza con él; quitar con conditions [] vuelve a mostrar el módulo; y
#    edit-divi-section delete devuelve dropped_conditions. Sin WooCommerce de pruebas (no se crean productos). Se guardan
#    crudos los ajustes, la versión, el bloqueo y los roles al empezar y se devuelven al final (también si la corrida se
#    corta); las páginas (con su et-cache) se borran (force).
# 7j. Theme Builder de Divi 5 (D8a; sólo con Divi 5 activo, con SKIP si falta): con el interruptor
#    divi_theme_builder_tools_enabled encendido sólo durante el bloque y la capacidad nexo_mcp_divi_theme_builder:
#    create-divi-layout (encabezado, cuerpo con y sin divi/post-content, pie: publicados y sin asignar; no cambian el
#    sitio), set-divi-template (una plantilla nueva nace deshabilitada y no cambia el sitio; activada, el visitante
#    anónimo ve su encabezado, su cuerpo con el contenido de la página y su pie) y list-divi-theme-builder. Contra una
#    página Divi 5 propia, una 404, ?s= y el archivo de una categoría: Divi usa UNA sola plantilla por petición (una
#    plantilla de todas las páginas no pisa a la de una página concreta y sí se ve en otra); en el carrito de WooCommerce
#    (woocommerce:cart), un cuerpo con divi/post-content conserva el carrito y uno sin él lo REEMPLAZA (sólo con
#    allow_without_post_content) y restore-divi-template vuelve al anterior; delete-divi-template → restore-divi-template →
#    restore-divi-template (la plantilla vuelve a su fila sin el sufijo __trashed del slug y la segunda restauración
#    alterna); rechazos sin escribir (sitio entero sin confirm_site_wide, cuerpo sin post-content, id inexistente,
#    interruptor apagado, y get-page-structure sobre un layout sin el permiso del Theme Builder). Antes de empezar se toma
#    una foto del estado (posts, metas, términos, relaciones, opciones, metas del admin y archivos de et-cache) más las
#    opciones crudas que se tocan (ajustes, versión, bloqueos y roles); al final se borra todo lo creado (plantillas,
#    layouts, Theme Builder en vivo, términos, páginas, et-cache) y se compara la foto: el estado vuelve a ser exactamente
#    el de antes (también si la corrida se corta).
# 7k. Biblioteca de Divi 5 (D8b; sólo con Divi 5 activo, con SKIP si falta): con el interruptor divi_library_tools_enabled
#    encendido sólo durante el bloque y la capacidad nexo_mcp_divi_library: un módulo global usado por dos páginas (insert-divi-
#    library-item mode global en la copia de trabajo de cada una y publicar: las dos lo referencian y las dos lo muestran al
#    visitante); mode copy en una tercera no inserta referencia y no sigue al ítem; editar sin confirm_global se rechaza sin
#    escribir y con él lo ven las dos páginas sin tocarlas (mismo contenido y fecha de modificación) y affected_pages las lista
#    (con título: hay read_post); pages_forbidden cuando el usuario no puede editar una página que lo usa (una nota de Divi,
#    et_divi_note, que nadie puede editar) y no cambia nada; borrar con confirm_global despega (las dos páginas siguen mostrándolo,
#    still_referenced 0); restore-divi-library-item devuelve el ítem y NO vuelve a enlazar; un global privado, en borrador o en la
#    papelera deja de renderizarse (y vuelve al publicarlo); una fila global y un módulo guardados bajo divi/placeholder los abren
#    el parser y el renderizador de bloques de Divi y la fila global se dibuja dentro de una página. Antes de empezar se toma la
#    misma foto que en 7j (posts, metas, términos, relaciones, opciones, incluido nexo_mcp_settings, metas del admin, usuarios y
#    et-cache) más las opciones crudas que se tocan; cada id que crea la prueba se anota y al final se borran SÓLO esos (nunca por
#    consulta amplia) y se compara la foto: el estado vuelve a ser exactamente el de antes (también si la corrida se corta).
# 8. Concurrencia (bloqueo por original): dispara en paralelo 5 primeras ediciones
#    sobre la misma página publicada, con update-page y con edit-elementor-section, y
#    comprueba que existe una sola copia, que cada llamada tuvo éxito en esa copia o
#    respondió «ocupado», y que ninguna edición con éxito se perdió.
# 8b. update-node-text, update-node-attribute, edit-structure y restore-builder-backup:
#    sobre una publicada → copia de trabajo (original intacto, diff, publicar fusiona,
#    restaurar en la copia); sobre un borrador → en su sitio; un rechazo no crea copia.
# 8c. Sistema de diseño: round-trip de ElementorDesignStore (foto → mutar → restaurar de
#    clases, variables y Kit, mismos ids) y undo-design-system-change por MCP sin
#    respaldo (error y nada cambia).
# 8d. Sistema de diseño, herramientas de escritura por MCP (create/update/delete-global-class,
#    create/update/delete-design-variable, update-kit-globals): cada una se comprueba con
#    get-design-system y con lo guardado, y undo-design-system-change devuelve la huella exacta
#    del estado anterior; los rechazos, el permiso apagado, «Solo revisar» y la falta del permiso
#    destructivo no cambian nada; borrar en uso se rechaza con la lista y con force borra. Se
#    guarda una foto del estado antes y se restaura al final: sin restos, respaldo ni bloqueo.
# 9. Limpia lo que creó (páginas a la papelera, recuperable; clase, variable e
#    imagen de prueba se quitan) y restaura los ajustes y el estado del experimento.
#
# Uso (desde app/):  tools/prueba_adaptadores.sh
set -uo pipefail

cd "$(dirname "$0")/.."
# shellcheck source=wpcli.sh
source tools/wpcli.sh
# Con Elementor Pro instalado, wp-cli necesita más memoria que los 128M del servicio (ver tools/wpcli.sh).
wpcli_memoria_pro
wp() { wpcli "$@" 2>/dev/null; }
# Lectura que reintenta una vez: wp-cli devuelve a veces vacío de forma transitoria.
wpr() { local out; out="$(wp "$@")"; [ -n "$out" ] || { sleep 2; out="$(wp "$@")"; }; printf '%s' "$out"; }
# PHP dentro de WordPress, como admin. Los valores van como variables de entorno NEXO_A1, NEXO_A2… (el código los lee
# con getenv), nunca pegados en el código. Lo que el código imprime viaja entre marcas y en base64, así que los avisos
# de PHP de antes o de después (p. ej. los de Elementor al cerrar) no llegan a la salida. El código corre dentro de una
# función: puede terminar con `return`. Si no llega la marca (falló docker, wp-cli o el PHP antes de terminar) no
# imprime nada, avisa por stderr y sale con 1. Lo que no sea UTF-8 válido sale con � en vez de cortar.
#   php_wp '<código>' [valor…]    php_wpr: igual, pero reintenta una vez si falló o la salida quedó vacía (lecturas)
#   Se prueba sin docker con tools/prueba_php_wp.sh.
php_wp() {
	local codigo=$1 i=1 valor marcada
	local -a entorno=()
	shift
	for valor in "$@"; do entorno+=(-e "NEXO_A$i=$valor"); i=$((i + 1)); done
	marcada="$(wpcli ${entorno[@]+"${entorno[@]}"} eval --user=admin \
		'ob_start(); ( function () { '"$codigo"'
} )(); echo "\n@@NEXO@@" . base64_encode( (string) ob_get_clean() ) . "@@NEXO@@\n";' 2>/dev/null \
		| sed -n 's/^.*@@NEXO@@\([A-Za-z0-9+\/=]*\)@@NEXO@@.*$/@\1/p' | head -n 1)"
	# La marca lleva una @ delante, así una salida vacía pero completa se distingue de una corrida sin marca.
	if [ -z "$marcada" ]; then
		echo "php_wp: wp-cli no devolvió la salida marcada (falló docker, wp-cli o el PHP)" >&2
		return 1
	fi
	printf '%s' "${marcada#@}" | python3 -I -c 'import base64, sys; sys.stdout.write(base64.b64decode(sys.stdin.read()).decode("utf-8", "replace"))'
}
php_wpr() {
	local out
	out="$(php_wp "$@")" && [ -n "$out" ] || { sleep 2; out="$(php_wp "$@")" || return 1; }
	printf '%s' "$out"
}

# Subcomando para el .py: comprueba con wp-cli, no con MCP, que el enlace del
# botón quedó guardado en `_elementor_data` antes de restaurar el respaldo.
#   tools/prueba_adaptadores.sh --enlace-guardado <página> <url esperada>
if [ "${1:-}" = "--enlace-guardado" ]; then
	guardado="$(wpr post meta get "$2" _elementor_data | python3 -I -c '
import json, sys
def walk(items):
    for e in items:
        if e.get("id") == "aaaa0003":
            print(e["settings"].get("link", {}).get("url", ""))
        walk(e.get("elements", []))
walk(json.load(sys.stdin))' 2>/dev/null)"
	[ "$guardado" = "$3" ] || { echo "guardado en _elementor_data: «${guardado}»" >&2; exit 1; }
	exit 0
fi

# Subcomandos para el bloque de create-elementor-page (comprobaciones fuera de MCP).
#   --contar-paginas          cantidad de páginas no borradas (publicadas, borradores…)
#   --experimentos <estado>   activa/desactiva los experimentos atómicos y dice si
#                             los elementos atómicos quedaron activos (si/no)
if [ "${1:-}" = "--contar-paginas" ]; then
	wpr post list --post_type=page --post_status=any --format=count
	exit 0
fi
if [ "${1:-}" = "--experimentos" ]; then
	for name in e_opt_in_v4 e_atomic_elements; do wp option update "elementor_experiment-$name" "$2" >/dev/null; done
	php_wpr 'echo \Elementor\Plugin::$instance->experiments->is_feature_active( "e_atomic_elements" ) ? "si" : "no";'
	exit 0
fi

# Subcomandos para el bloque de edit-elementor-section (comprobaciones fuera de MCP).
#   --meta <id> <clave>   valor crudo de un meta del contenido (vacío si no existe)
#   --estado <id>         estado del contenido (vacío si no existe)
#   --render <id>         HTML que Elementor renderiza para el contenido
#   --vista-previa <id>   HTML de la vista previa real (pedido HTTP con la sesión del admin, ?page_id=<id>&preview=true):
#                         lo necesitan los datos dinámicos que leen la entrada actual (post-title, imagen destacada…);
#                         get_builder_content_for_display no sirve, porque Elementor no se renderiza a sí mismo
if [ "${1:-}" = "--meta" ]; then wpr post meta get "$2" "$3"; exit 0; fi
if [ "${1:-}" = "--estado" ]; then wpr post get "$2" --field=post_status; exit 0; fi
# Subcomandos para el bloque de Divi 5.
#   --contenido <id>       post_content crudo
#   --divi-borrar <id>     borra (force) la página y su directorio wp-content/et-cache/<id>
if [ "${1:-}" = "--contenido" ]; then wpr post get "$2" --field=post_content; exit 0; fi
if [ "${1:-}" = "--divi-borrar" ]; then
	php_wp '
$id = (int) getenv( "NEXO_A1" );
wp_delete_post( $id, true );
$dir = WP_CONTENT_DIR . "/et-cache/" . $id;
if ( $id > 0 && is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
	rmdir( $dir );
}' "$2" >/dev/null
	exit 0
fi
if [ "${1:-}" = "--render" ]; then
	php_wp 'echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( (int) getenv( "NEXO_A1" ), true );' "$2"
	exit 0
fi

if [ "${1:-}" = "--vista-previa" ]; then
	cookie="$(php_wpr '$u = get_user_by( "login", "admin" ); $vence = time() + 600; $t = WP_Session_Tokens::get_instance( $u->ID )->create( $vence ); echo LOGGED_IN_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( $u->ID, $vence, "logged_in", $t ) );')" || exit 1
	[ -n "$cookie" ] || exit 1
	curl -sL --max-time 60 -H "Cookie: $cookie" "http://localhost:8080/?page_id=$2&preview=true"
	exit 0
fi

# Subcomando del bloque de migrate-elementor-page: lo que Elementor carga de un contenido,
# como lista JSON de tipos (widgetType o elType) en orden de documento.
if [ "${1:-}" = "--documento" ]; then
	php_wpr '
$doc = \Elementor\Plugin::$instance->documents->get( (int) getenv( "NEXO_A1" ), false );
$tipos = array();
$recorrer = function ( $nodos ) use ( &$recorrer, &$tipos ) {
	foreach ( $nodos as $n ) { $tipos[] = ( $n["widgetType"] ?? "" ) ?: $n["elType"]; $recorrer( $n["elements"] ?? array() ); }
};
$recorrer( $doc ? $doc->get_elements_data() : array() );
echo wp_json_encode( $tipos );' "$2"
	exit 0
fi

# Subcomando para el bloque del sistema de diseño: huella del estado que respalda y
# restaura ElementorDesignStore (clases, variables y Kit) y si hay un respaldo guardado.
if [ "${1:-}" = "--diseno-estado" ]; then
	php_wpr '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$estado = array();
foreach ( array( "classes", "variables", "kit" ) as $tipo ) { $estado[ $tipo ] = $store->snapshot( $tipo ); }
$estado["respaldo"] = false !== get_option( "nexo_mcp_design_system_backup", false );
echo md5( wp_json_encode( $estado ) );'
	exit 0
fi

# Subcomandos del bloque de herramientas de escritura del sistema de diseño (fuera de MCP).
#   --diseno-huella            huella estable del estado (clases, orden de la vista previa, variables sin la
#                              marca de agua, listas del Kit) y si hay respaldo guardado
#   --diseno-clase <id>        lo guardado de una clase: existencia, orden (publicado y vista previa) y variantes
#   --diseno-variable <id>     lo guardado de una variable: existencia, tipo, etiqueta y si está borrada
#   --diseno-kit-lista <lista> lista cruda del Kit (system_colors, custom_colors…) en JSON
#   --ajuste <clave> <valor>   cambia un interruptor de nexo_mcp_settings (true/false)
#   --ajuste-valor <clave>     lee un interruptor (true/false)
#   --diseno-foto              guarda el estado de clases, variables y Kit para restaurarlo al final
#   --diseno-restaurar         devuelve ese estado, borra el respaldo, el bloqueo del Kit y la foto
#   --diseno-barrer <sufijo> <clase> <variable>
#                              quita restos con etiqueta nexo-prueba- (salvo la clase y variable de esta
#                              corrida) y manda a la papelera las páginas elementor-mcp- de corridas cortadas
diseno_php_canon='$canon = static function ( $v ) use ( &$canon ) { if ( ! is_array( $v ) ) { return $v; } $v = array_map( $canon, $v ); if ( ! array_is_list( $v ) ) { ksort( $v ); } return $v; };'
restaurar_diseno() {
	php_wp "$diseno_php_canon"'
$guardado = get_option( "nexo_prueba_diseno_foto", false );
if ( ! is_array( $guardado ) ) { echo "sin foto"; return; }
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$cambiados = array();
foreach ( $guardado as $tipo => $estado ) {
	$a = $estado; $b = $store->snapshot( $tipo );
	if ( "variables" === $tipo ) { unset( $a["watermark"], $b["watermark"] ); }
	if ( wp_json_encode( $canon( $a ) ) !== wp_json_encode( $canon( $b ) ) ) { $store->restore( $tipo, $estado ); $cambiados[] = $tipo; }
}
delete_option( "nexo_mcp_design_system_backup" );
delete_option( "nexo_prueba_diseno_foto" );
delete_option( "nexo_mcp_lock_" . \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id() );
$store->clear_cache();
echo "restaurado: " . implode( ",", $cambiados );'
}
if [ "${1:-}" = "--diseno-huella" ]; then
	php_wpr "$diseno_php_canon"'
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$variables = $store->snapshot( "variables" );
unset( $variables["watermark"] );
$vista = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit )->set_preview( true );
echo "clases=" . md5( wp_json_encode( $canon( $store->snapshot( "classes" ) ) ) );
echo " vista=" . md5( wp_json_encode( array_values( (array) $vista->get_order() ) ) );
echo " variables=" . md5( wp_json_encode( $canon( $variables ) ) );
echo " kit=" . md5( wp_json_encode( $canon( $store->snapshot( "kit" ) ) ) );
echo " respaldo=" . ( false !== get_option( "nexo_mcp_design_system_backup", false ) ? "si" : "no" );'
	exit 0
fi
if [ "${1:-}" = "--diseno-clase" ]; then
	php_wpr '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$id = (string) getenv( "NEXO_A1" );
$estado = $store->snapshot( "classes" );
$vista = array_values( (array) \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit )->set_preview( true )->get_order() );
$item = $estado["items"][ $id ] ?? null;
echo wp_json_encode( array(
	"existe" => null !== $item,
	"label" => $item["label"] ?? null,
	"publicada_ultima" => array() !== $estado["order"] && end( $estado["order"] ) === $id,
	"vista_ultima" => array() !== $vista && end( $vista ) === $id,
	"vista" => in_array( $id, $vista, true ),
	"variantes" => array_map( static fn( $v ) => array( "breakpoint" => $v["meta"]["breakpoint"] ?? null, "state" => $v["meta"]["state"] ?? null, "props" => array_keys( $v["props"] ?? array() ) ), $item["variants"] ?? array() ),
) );' "$2"
	exit 0
fi
if [ "${1:-}" = "--diseno-variable" ]; then
	php_wpr '
$estado = ( new \NexoDev\NexoMCP\Builders\ElementorDesignStore() )->snapshot( "variables" );
$v = $estado["data"][ (string) getenv( "NEXO_A1" ) ] ?? null;
echo wp_json_encode( array( "existe" => null !== $v, "type" => $v["type"] ?? null, "label" => $v["label"] ?? null, "borrada" => ! empty( $v["deleted_at"] ) ) );' "$2"
	exit 0
fi
if [ "${1:-}" = "--diseno-kit-lista" ]; then
	php_wpr 'echo wp_json_encode( ( new \NexoDev\NexoMCP\Builders\ElementorDesignStore() )->snapshot( "kit" )[ (string) getenv( "NEXO_A1" ) ] ?? array() );' "$2"
	exit 0
fi
# Subcomandos y función del bloque de diseño global de Divi 5 (D3): foto cruda de las opciones que se tocan.
#   --divi-diseno-foto       guarda crudas (valor y autocarga) et_divi, et_divi_global_variables, el historial y los ajustes
#   --divi-diseno-huella     md5 crudo de lo guardado: et_divi, variables, historial (cantidad) y bloqueo
#   --divi-diseno-restaurar  devuelve las opciones crudas, vacía el CSS de Divi y borra la foto
divi_diseno_opciones='array( "et_divi", "et_divi_global_variables", "nexo_mcp_divi_design_history", "nexo_mcp_settings", "nexo_mcp_lock_0" )'
restaurar_divi_diseno() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_diseno_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
foreach ( $foto as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
if ( class_exists( "ET_Core_PageResource" ) ) { ET_Core_PageResource::remove_static_resources( "all", "all", true ); }
delete_option( "nexo_prueba_divi_diseno_foto" );
echo "restaurado";'
}
if [ "${1:-}" = "--divi-diseno-foto" ]; then
	php_wp '
global $wpdb;
$foto = array();
foreach ( '"$divi_diseno_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$foto[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
update_option( "nexo_prueba_divi_diseno_foto", $foto, false );
echo "foto de " . count( $foto ) . " opciones";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-diseno-huella" ]; then
	php_wpr '
global $wpdb;
$crudo = static function ( $n ) use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $n ) ); };
$et = $crudo( "et_divi" ); $vars = $crudo( "et_divi_global_variables" ); $hist = $crudo( "nexo_mcp_divi_design_history" ); $set = $crudo( "nexo_mcp_settings" );
echo "et_divi=" . ( null === $et ? "sin" : md5( $et ) ) . " variables=" . ( null === $vars ? "sin" : md5( $vars ) ) . " historial=" . ( null === $hist ? "sin" : md5( $hist ) ) . " ajustes=" . ( null === $set ? "sin" : md5( $set ) ) . " bloqueo=" . ( null === $crudo( "nexo_mcp_lock_0" ) ? "no" : "si" );'
	exit 0
fi
# Subcomandos y función del bloque de presets de Divi 5 (D4): foto cruda de las opciones que se tocan.
#   --divi-presets-foto       guarda crudas (valor y autocarga) et_divi_builder_global_presets_d5, el historial, los ajustes y el bloqueo
#   --divi-presets-huella     md5 crudo de lo guardado (presets, historial, ajustes, bloqueo)
#   --divi-presets-restaurar  devuelve las opciones crudas, vacía el CSS de Divi y borra la foto
divi_presets_opciones='array( "et_divi_builder_global_presets_d5", "nexo_mcp_divi_design_history", "nexo_mcp_settings", "nexo_mcp_lock_0" )'
restaurar_divi_presets() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_presets_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
foreach ( $foto as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
if ( class_exists( "ET_Core_PageResource" ) ) { ET_Core_PageResource::remove_static_resources( "all", "all", true ); }
delete_option( "nexo_prueba_divi_presets_foto" );
echo "restaurado";'
}
if [ "${1:-}" = "--divi-presets-foto" ]; then
	php_wp '
global $wpdb;
$foto = array();
foreach ( '"$divi_presets_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$foto[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
update_option( "nexo_prueba_divi_presets_foto", $foto, false );
echo "foto de " . count( $foto ) . " opciones";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-presets-huella" ]; then
	php_wpr '
global $wpdb;
$crudo = static function ( $n ) use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $n ) ); };
$p = $crudo( "et_divi_builder_global_presets_d5" ); $hist = $crudo( "nexo_mcp_divi_design_history" ); $set = $crudo( "nexo_mcp_settings" );
echo "presets=" . ( null === $p ? "sin" : md5( $p ) ) . " historial=" . ( null === $hist ? "sin" : md5( $hist ) ) . " ajustes=" . ( null === $set ? "sin" : md5( $set ) ) . " bloqueo=" . ( null === $crudo( "nexo_mcp_lock_0" ) ? "no" : "si" );'
	exit 0
fi
# Subcomandos y función del bloque de contenido dinámico y bucles de Divi 5 (D5/D6): foto cruda de lo que se toca (ajustes,
# bloqueo y roles: la capacidad nexo_mcp_divi_dynamic vive en los roles) y ayudantes de las entradas de prueba.
#   --divi-dinamico-foto / -huella / -restaurar    como los de D3 y D4
#   --divi-dinamico-fixtures <sufijo>   crea 7 entradas (4 publicadas, privada, con contraseña, borrador); imprime {nombre: id}
#   --divi-dinamico-barrer <sufijo>     borra (force) las entradas con el marcador
#   --divi-dinamico-visibles            cantidad de entradas publicadas sin contraseña del sitio
#   --divi-dinamico-reemplazar <id> <de> <a>   cambia texto en post_content crudo (sin filtros); imprime las ocurrencias
divi_dinamico_opciones='array( "nexo_mcp_settings", "nexo_mcp_lock_0", $GLOBALS["wpdb"]->prefix . "user_roles" )'
restaurar_divi_dinamico() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_dinamico_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
foreach ( $foto as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
if ( class_exists( "ET_Core_PageResource" ) ) { ET_Core_PageResource::remove_static_resources( "all", "all", true ); }
delete_option( "nexo_prueba_divi_dinamico_foto" );
echo "restaurado";'
}
if [ "${1:-}" = "--divi-dinamico-foto" ]; then
	php_wp '
global $wpdb;
$foto = array();
foreach ( '"$divi_dinamico_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$foto[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
update_option( "nexo_prueba_divi_dinamico_foto", $foto, false );
echo "foto de " . count( $foto ) . " opciones";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-dinamico-huella" ]; then
	php_wpr '
global $wpdb;
$crudo = static function ( $n ) use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $n ) ); };
$set = $crudo( "nexo_mcp_settings" ); $roles = $crudo( $wpdb->prefix . "user_roles" );
echo "ajustes=" . ( null === $set ? "sin" : md5( $set ) ) . " roles=" . ( null === $roles ? "sin" : md5( $roles ) ) . " bloqueo=" . ( null === $crudo( "nexo_mcp_lock_0" ) ? "no" : "si" );'
	exit 0
fi
if [ "${1:-}" = "--divi-dinamico-restaurar" ]; then restaurar_divi_dinamico; exit 0; fi
if [ "${1:-}" = "--divi-dinamico-fixtures" ]; then
	php_wp '
$sufijo = (string) getenv( "NEXO_A1" ); $ahora = time(); $ids = array();
// Nombre, estado, minutos de antigüedad, contraseña: la de contraseña es la más nueva de las publicadas.
$plan = array( array( "Alpha", "publish", 40, "" ), array( "Beta", "publish", 30, "" ), array( "Gamma", "publish", 20, "" ), array( "Delta", "publish", 10, "" ), array( "PRIVATE", "private", 5, "" ), array( "PASSWORD", "publish", 2, "clave" ), array( "DRAFT", "draft", 1, "" ) );
foreach ( $plan as $p ) {
	$t = $ahora - $p[2] * 60;
	$ids[ $p[0] ] = wp_insert_post( array( "post_type" => "post", "post_status" => $p[1], "post_title" => "ZZD6 " . $p[0] . " divi5-mcp-" . $sufijo, "post_content" => "Cuerpo " . $p[0], "post_password" => $p[3], "post_date" => get_date_from_gmt( gmdate( "Y-m-d H:i:s", $t ) ), "post_date_gmt" => gmdate( "Y-m-d H:i:s", $t ) ), true );
}
echo wp_json_encode( $ids );' "$2"
	exit 0
fi
if [ "${1:-}" = "--divi-dinamico-barrer" ]; then
	php_wp '
$borradas = array();
foreach ( get_posts( array( "post_type" => "post", "post_status" => array( "publish", "private", "draft", "pending", "future", "trash" ), "numberposts" => -1, "s" => "divi5-mcp-" . getenv( "NEXO_A1" ), "fields" => "ids" ) ) as $id ) { wp_delete_post( (int) $id, true ); $borradas[] = $id; }
echo implode( " ", $borradas );' "$2"
	exit 0
fi
if [ "${1:-}" = "--divi-dinamico-visibles" ]; then
	php_wpr 'echo count( get_posts( array( "post_type" => "post", "post_status" => "publish", "has_password" => false, "numberposts" => -1, "fields" => "ids" ) ) );'
	exit 0
fi
if [ "${1:-}" = "--divi-dinamico-reemplazar" ]; then
	php_wp '
global $wpdb;
$id = (int) getenv( "NEXO_A1" ); $de = (string) getenv( "NEXO_A2" ); $a = (string) getenv( "NEXO_A3" );
$c = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
$n = "" === $de ? 0 : substr_count( $c, $de );
if ( $n > 0 ) { $wpdb->update( $wpdb->posts, array( "post_content" => str_replace( $de, $a, $c ) ), array( "ID" => $id ) ); clean_post_cache( $id ); }
echo $n;' "$2" "$3" "$4"
	exit 0
fi
# Subcomandos y función del bloque de condiciones de visualización de Divi 5 (D7): foto cruda de lo que se toca (ajustes,
# versión, bloqueo y roles: la capacidad nexo_mcp_divi_conditions vive en los roles; la versión cambia si la corrida
# dispara la actualización del plugin, que concede la capacidad).
#   --divi-condiciones-foto / -huella / -restaurar    como los de D5/D6
#   --divi-condiciones-como-admin <id> [consulta]     HTML de la página como el administrador con sesión (la cookie se
#                                                     crea y se destruye en la misma llamada)
#   --divi-condiciones-buscar <texto>                 HTML de la búsqueda del sitio como visitante anónimo
divi_condiciones_opciones='array( "nexo_mcp_settings", "nexo_mcp_version", "nexo_mcp_lock_0", $GLOBALS["wpdb"]->prefix . "user_roles" )'
restaurar_divi_condiciones() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_condiciones_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
foreach ( $foto as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
if ( class_exists( "ET_Core_PageResource" ) ) { ET_Core_PageResource::remove_static_resources( "all", "all", true ); }
delete_option( "nexo_prueba_divi_condiciones_foto" );
echo "restaurado";'
}
if [ "${1:-}" = "--divi-condiciones-foto" ]; then
	php_wp '
global $wpdb;
$foto = array();
foreach ( '"$divi_condiciones_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$foto[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
update_option( "nexo_prueba_divi_condiciones_foto", $foto, false );
echo "foto de " . count( $foto ) . " opciones";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-condiciones-huella" ]; then
	php_wpr '
global $wpdb;
$crudo = static function ( $n ) use ( $wpdb ) { return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $n ) ); };
$set = $crudo( "nexo_mcp_settings" ); $roles = $crudo( $wpdb->prefix . "user_roles" ); $ver = $crudo( "nexo_mcp_version" );
echo "ajustes=" . ( null === $set ? "sin" : md5( $set ) ) . " roles=" . ( null === $roles ? "sin" : md5( $roles ) ) . " version=" . ( null === $ver ? "sin" : $ver ) . " bloqueo=" . ( null === $crudo( "nexo_mcp_lock_0" ) ? "no" : "si" );'
	exit 0
fi
if [ "${1:-}" = "--divi-condiciones-restaurar" ]; then restaurar_divi_condiciones; exit 0; fi
if [ "${1:-}" = "--divi-condiciones-como-admin" ]; then
	cookie="$(php_wpr '$u = get_user_by( "login", "admin" ); $vence = time() + 600; $t = WP_Session_Tokens::get_instance( $u->ID )->create( $vence ); update_option( "nexo_prueba_divi_condiciones_token", $t, false ); echo LOGGED_IN_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( $u->ID, $vence, "logged_in", $t ) );')" || exit 1
	[ -n "$cookie" ] || exit 1
	curl -sL --max-time 60 -H "Cookie: $cookie" "http://localhost:8080/?page_id=$2${3:-}"
	php_wp '$u = get_user_by( "login", "admin" ); $t = (string) get_option( "nexo_prueba_divi_condiciones_token", "" ); if ( "" !== $t ) { WP_Session_Tokens::get_instance( $u->ID )->destroy( $t ); } delete_option( "nexo_prueba_divi_condiciones_token" );' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-condiciones-buscar" ]; then
	curl -sL --max-time 60 --get --data-urlencode "s=$2" "http://localhost:8080/"
	exit 0
fi
# Subcomandos y función del bloque del Theme Builder de Divi 5 (D8a). Esta prueba cambia plantillas que afectan a todo el
# sitio: antes guarda una foto (--divi-tb-foto), al final lo creado se barre por id (--divi-tb-restaurar) y la huella
# (--divi-tb-huella) tiene que ser idéntica a la de antes. La huella lista un resumen por línea (posts, metas, términos,
# relaciones, opciones no transient, metas del administrador, usuarios y archivos de et-cache) y deja fuera lo que cualquier
# conexión OAuth o visita cambia sola (tablas nexo_mcp_*, sesiones, transients, wc_last_active, avisos de Yoast) y las cachés
# que Divi borra en todo el sitio al guardar una plantilla y regenera al renderizar (metas de recursos y tres opciones), más el cerrojo efímero de Action Scheduler.
#   --divi-tb-foto / -huella / -restaurar    foto cruda y limpieza; la huella imprime una línea por concepto
#   --divi-tb-categoria                      id de la categoría por defecto (el archivo contra el que se prueba)
#   --divi-tb-faltantes                      ids de páginas con directorio en et-cache al empezar que ya no lo tienen
#   --divi-tb-slug <id>                      slug (post_name) de una plantilla
#   --divi-tb-carrito                        id de la página del carrito de WooCommerce (vacío sin WooCommerce)
divi_tb_opciones='array( "nexo_mcp_settings", "nexo_mcp_version", $GLOBALS["wpdb"]->prefix . "user_roles" )'
restaurar_divi_tb() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_tb_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
$borrados = array();
$tipos = array( "et_theme_builder", "et_template", "et_header_layout", "et_body_layout", "et_footer_layout", "et_pb_layout" );
$marcas = implode( ",", array_fill( 0, count( $tipos ), "%s" ) );
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($marcas)", array_merge( array( (int) $foto["max_post"] ), $tipos ) ) );
foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); $borrados[] = (int) $id; }
$terminos = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id > %d", (int) $foto["max_term"] ), ARRAY_A );
foreach ( $terminos as $t ) { wp_delete_term( (int) $t["term_id"], (string) $t["taxonomy"] ); }
foreach ( $foto["opciones"] as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nexo\\_mcp\\_lock\\_%\"" ) as $nombre ) {
	if ( ! in_array( $nombre, $foto["bloqueos"], true ) ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
$raiz = WP_CONTENT_DIR . "/et-cache";
if ( is_dir( $raiz ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( in_array( substr( $f->getPathname(), strlen( $raiz ) + 1 ), $foto["cache"], true ) ) { continue; }
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
}
echo "restaurado (posts borrados: " . implode( ",", $borrados ) . ")";'
}
# Divi borra el CSS estático de TODAS las páginas al guardar una plantilla y lo regenera al renderizar: las páginas que tenían
# su directorio en et-cache al empezar se visitan una vez (como visitante) para que vuelva, y la portada para los archivos
# globales. Después se borra la foto cruda.
cerrar_divi_tb() {
	local id
	for id in $(tools/prueba_adaptadores.sh --divi-tb-faltantes); do
		curl -sL --max-time 120 -o /dev/null "http://localhost:8080/?page_id=$id"
	done
	curl -sL --max-time 120 -o /dev/null "http://localhost:8080/"
	php_wp 'delete_option( "nexo_prueba_divi_tb_foto" );' >/dev/null
}
if [ "${1:-}" = "--divi-tb-foto" ]; then
	php_wp '
global $wpdb;
$opciones = array();
foreach ( '"$divi_tb_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$opciones[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
$cache = array();
$raiz = WP_CONTENT_DIR . "/et-cache";
if ( is_dir( $raiz ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $f ) { $cache[] = substr( $f->getPathname(), strlen( $raiz ) + 1 ); }
}
update_option( "nexo_prueba_divi_tb_foto", array(
	"opciones" => $opciones,
	"max_post" => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
	"max_term" => (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" ),
	"bloqueos" => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nexo\\_mcp\\_lock\\_%\"" ),
	"cache"    => $cache,
), false );
echo "foto";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-tb-huella" ]; then
	php_wpr '
global $wpdb;
$linea = static function ( $nombre, $filas ) { echo $nombre . "=" . count( $filas ) . ":" . substr( md5( wp_json_encode( $filas ) ), 0, 12 ) . "\n"; };
$linea( "posts", $wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_title, post_parent, MD5(post_content) FROM {$wpdb->posts} ORDER BY ID", ARRAY_N ) );
// Cachés de Divi que borra remove_static_resources( "all", "all" ) en todas las entradas y que Divi regenera al renderizar.
$cachesdivi = array( "et_enqueued_post_fonts", "_et_dynamic_cached_shortcodes", "_et_dynamic_cached_attributes", "_et_builder_module_features_cache", "_divi_dynamic_assets_cached_modules", "_divi_dynamic_assets_cached_feature_used", "_divi_dynamic_assets_canvases_used", "_edit_lock" );
$linea( "metas", array_values( array_filter( $wpdb->get_results( "SELECT post_id, meta_key, MD5(meta_value) FROM {$wpdb->postmeta} ORDER BY post_id, meta_key, MD5(meta_value)", ARRAY_N ), static fn( $f ) => ! in_array( $f[1], $cachesdivi, true ) ) ) );
$linea( "terminos", $wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id ORDER BY t.term_id", ARRAY_N ) );
$linea( "relaciones", $wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_N ) );
$opciones = array();
foreach ( $wpdb->get_results( "SELECT option_name, MD5(option_value) FROM {$wpdb->options} ORDER BY option_name", ARRAY_N ) as $fila ) {
	// Cachés que Divi y Elementor regeneran solos al renderizar una página y el cerrojo efímero de Action Scheduler.
	if ( 0 === strpos( $fila[0], "_transient" ) || 0 === strpos( $fila[0], "_site_transient" ) || 0 === strpos( $fila[0], "nexo_prueba_" ) || 0 === strpos( $fila[0], "action_scheduler_lock_" ) || in_array( $fila[0], array( "_et_builder_da_feature_cache", "_et_builder_gf_feature_cache", "_elementor_design_system_sync_css_meta" ), true ) ) { continue; }
	$opciones[] = $fila;
}
$linea( "opciones", $opciones );
$ruido = array( "session_tokens", "mcp_adapter_sessions", "wc_last_active", "wp_yoast_notifications" );
$linea( "usermeta", array_values( array_filter( $wpdb->get_results( "SELECT user_id, meta_key, MD5(meta_value) FROM {$wpdb->usermeta} ORDER BY user_id, meta_key", ARRAY_N ), static fn( $f ) => ! in_array( $f[1], $ruido, true ) ) ) );
$linea( "usuarios", $wpdb->get_results( "SELECT ID, user_login FROM {$wpdb->users} ORDER BY ID", ARRAY_N ) );
$cache = array();
$raiz = WP_CONTENT_DIR . "/et-cache";
if ( is_dir( $raiz ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $f ) { $cache[] = array( substr( $f->getPathname(), strlen( $raiz ) + 1 ) ); }
	sort( $cache );
}
$linea( "et-cache", $cache );'
	exit 0
fi
if [ "${1:-}" = "--divi-tb-restaurar" ]; then restaurar_divi_tb; exit 0; fi
if [ "${1:-}" = "--divi-tb-faltantes" ]; then
	php_wpr '
$foto = get_option( "nexo_prueba_divi_tb_foto", false );
$faltan = array();
foreach ( is_array( $foto ) ? $foto["cache"] : array() as $ruta ) {
	if ( 1 === preg_match( "/^[0-9]+$/", $ruta ) && ! is_dir( WP_CONTENT_DIR . "/et-cache/" . $ruta ) ) { $faltan[] = $ruta; }
}
echo implode( " ", $faltan );'
	exit 0
fi
if [ "${1:-}" = "--divi-tb-categoria" ]; then php_wpr 'echo (int) get_option( "default_category" );'; exit 0; fi
if [ "${1:-}" = "--divi-tb-slug" ]; then php_wpr '$p = get_post( (int) getenv( "NEXO_A1" ) ); echo $p ? $p->post_name : "";' "$2"; exit 0; fi
if [ "${1:-}" = "--divi-tb-carrito" ]; then php_wpr 'echo function_exists( "wc_get_page_id" ) ? max( 0, (int) wc_get_page_id( "cart" ) ) : "";'; exit 0; fi
# Subcomandos y funciones del bloque de la biblioteca de Divi 5 (D8b). Esta prueba cambia ítems globales que cambian varias
# páginas a la vez: antes guarda una foto (--divi-lib-foto), cada id que crea se anota (--divi-lib-anotar) y al final se borran
# SÓLO esos ids (nunca por consulta amplia: la limpieza de un script anterior borró entradas de esta base) y la huella
# (--divi-tb-huella, la misma de 7j) tiene que ser idéntica a la de antes.
#   --divi-lib-foto / -restaurar        foto cruda (ajustes, versión, roles, bloqueos, et-cache) y limpieza por ids anotados
#   --divi-lib-anotar <id>              anota un id creado por la prueba (sólo ids posteriores a la foto)
#   --divi-lib-faltantes                ids de páginas con directorio en et-cache al empezar que ya no lo tienen
#   --divi-lib-nota <ítem> <sufijo>     crea (y anota) una nota de Divi (et_divi_note, que ningún usuario puede editar) que referencia el ítem
#   --divi-lib-borrar <id>              borra (force) un id anotado
#   --divi-lib-estado <id> <estado>     cambia el estado de un id anotado
#   --divi-lib-pagina <id>              huella del contenido y la fecha de modificación de un contenido
#   --divi-lib-render <id> <marca>      cómo abre el parser y el renderizador de bloques de Divi el contenido de un ítem
restaurar_divi_lib() {
	php_wp '
global $wpdb;
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
$borrados = array();
foreach ( array_unique( array_map( "intval", $foto["ids"] ) ) as $id ) {
	if ( $id > (int) $foto["max_post"] && null !== get_post( $id ) ) { wp_delete_post( $id, true ); $borrados[] = $id; }
}
foreach ( $foto["opciones"] as $nombre => $fila ) {
	if ( false === $fila ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
	elseif ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $nombre ) ) ) { $wpdb->update( $wpdb->options, array( "option_value" => $fila[0], "autoload" => $fila[1] ), array( "option_name" => $nombre ) ); }
	else { $wpdb->insert( $wpdb->options, array( "option_name" => $nombre, "option_value" => $fila[0], "autoload" => $fila[1] ) ); }
	wp_cache_delete( $nombre, "options" );
}
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nexo\\_mcp\\_lock\\_%\"" ) as $nombre ) {
	if ( ! in_array( $nombre, $foto["bloqueos"], true ) ) { $wpdb->delete( $wpdb->options, array( "option_name" => $nombre ) ); }
}
wp_cache_delete( "alloptions", "options" ); wp_cache_delete( "notoptions", "options" );
$raiz = WP_CONTENT_DIR . "/et-cache";
if ( is_dir( $raiz ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		if ( in_array( substr( $f->getPathname(), strlen( $raiz ) + 1 ), $foto["cache"], true ) ) { continue; }
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
}
echo "restaurado (posts borrados: " . implode( ",", $borrados ) . ")";'
}
# Las páginas que tenían su directorio en et-cache al empezar se visitan una vez (como visitante) para que Divi lo regenere.
cerrar_divi_lib() {
	local id
	for id in $(tools/prueba_adaptadores.sh --divi-lib-faltantes); do
		curl -sL --max-time 120 -o /dev/null "http://localhost:8080/?page_id=$id"
	done
	curl -sL --max-time 120 -o /dev/null "http://localhost:8080/"
	php_wp 'delete_option( "nexo_prueba_divi_lib_foto" );' >/dev/null
}
if [ "${1:-}" = "--divi-lib-foto" ]; then
	php_wp '
global $wpdb;
$opciones = array();
foreach ( '"$divi_tb_opciones"' as $nombre ) {
	$fila = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $nombre ), ARRAY_N );
	$opciones[ $nombre ] = is_array( $fila ) ? array( (string) $fila[0], (string) $fila[1] ) : false;
}
$cache = array();
$raiz = WP_CONTENT_DIR . "/et-cache";
if ( is_dir( $raiz ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $f ) { $cache[] = substr( $f->getPathname(), strlen( $raiz ) + 1 ); }
}
update_option( "nexo_prueba_divi_lib_foto", array(
	"opciones" => $opciones,
	"max_post" => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
	"bloqueos" => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nexo\\_mcp\\_lock\\_%\"" ),
	"cache"    => $cache,
	"ids"      => array(),
), false );
echo "foto";' >/dev/null
	exit 0
fi
if [ "${1:-}" = "--divi-lib-anotar" ]; then
	php_wp '
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
$id = (int) getenv( "NEXO_A1" );
if ( ! is_array( $foto ) || $id <= (int) $foto["max_post"] ) { echo "no"; return; }
$foto["ids"][] = $id;
update_option( "nexo_prueba_divi_lib_foto", $foto, false );
echo "anotado";' "$2"
	exit 0
fi
if [ "${1:-}" = "--divi-lib-restaurar" ]; then restaurar_divi_lib; exit 0; fi
if [ "${1:-}" = "--divi-lib-faltantes" ]; then
	php_wpr '
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
$faltan = array();
foreach ( is_array( $foto ) ? $foto["cache"] : array() as $ruta ) {
	if ( 1 === preg_match( "/^[0-9]+$/", $ruta ) && ! is_dir( WP_CONTENT_DIR . "/et-cache/" . $ruta ) ) { $faltan[] = $ruta; }
}
echo implode( " ", $faltan );'
	exit 0
fi
if [ "${1:-}" = "--divi-lib-nota" ]; then
	php_wp '
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
if ( ! is_array( $foto ) ) { echo "sin foto"; return; }
$id = wp_insert_post( array( "post_type" => "et_divi_note", "post_status" => "publish", "post_title" => "Nota divi5-mcp-" . getenv( "NEXO_A2" ), "post_content" => "x <!-- wp:divi/global-layout {\"globalModule\":\"" . (int) getenv( "NEXO_A1" ) . "\",\"blockName\":\"divi/heading\"} /-->" ) );
if ( ! $id || is_wp_error( $id ) ) { echo "error"; return; }
$foto["ids"][] = (int) $id;
update_option( "nexo_prueba_divi_lib_foto", $foto, false );
echo (int) $id;' "$2" "$3"
	exit 0
fi
if [ "${1:-}" = "--divi-lib-borrar" ]; then
	php_wp '
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
$id = (int) getenv( "NEXO_A1" );
if ( ! is_array( $foto ) || ! in_array( $id, array_map( "intval", $foto["ids"] ), true ) ) { echo "no es de la prueba"; return; }
wp_delete_post( $id, true );
echo null === get_post( $id ) ? "borrado" : "no se borró";' "$2"
	exit 0
fi
if [ "${1:-}" = "--divi-lib-estado" ]; then
	php_wp '
$foto = get_option( "nexo_prueba_divi_lib_foto", false );
$id = (int) getenv( "NEXO_A1" );
if ( ! is_array( $foto ) || ! in_array( $id, array_map( "intval", $foto["ids"] ), true ) ) { echo "no es de la prueba"; return; }
$estado = (string) getenv( "NEXO_A2" );
// wp_update_post y no wp_trash_post: éste pasa por el filtro de Divi que DESPEGA las instancias (lo que se prueba es que el ítem deje de verse).
wp_update_post( array( "ID" => $id, "post_status" => $estado ) );
clean_post_cache( $id );
echo get_post_status( $id );' "$2" "$3"
	exit 0
fi
if [ "${1:-}" = "--divi-lib-pagina" ]; then
	php_wpr '$p = get_post( (int) getenv( "NEXO_A1" ) ); echo $p ? md5( $p->post_content ) . " " . $p->post_modified_gmt . " " . $p->post_status : "no existe";' "$2"
	exit 0
fi
if [ "${1:-}" = "--divi-lib-render" ]; then
	php_wpr '
$p = get_post( (int) getenv( "NEXO_A1" ) );
if ( ! $p ) { echo "{}"; return; }
$bloques = parse_blocks( $p->post_content );
$primero = $bloques[0] ?? array();
$html = (string) do_blocks( $p->post_content );
echo wp_json_encode( array(
	"superior" => $primero["blockName"] ?? "",
	"hijo"     => $primero["innerBlocks"][0]["blockName"] ?? "",
	"marca"    => false !== strpos( $html, (string) getenv( "NEXO_A2" ) ),
	"largo"    => strlen( $html ),
) );' "$2" "$3"
	exit 0
fi
if [ "${1:-}" = "--ajuste" ]; then
	php_wp '$s = get_option( "nexo_mcp_settings" ); $s[ (string) getenv( "NEXO_A1" ) ] = "true" === getenv( "NEXO_A2" ); update_option( "nexo_mcp_settings", $s );' "$2" "$3" >/dev/null
	exit 0
fi
if [ "${1:-}" = "--ajuste-valor" ]; then
	php_wpr '$s = get_option( "nexo_mcp_settings" ); echo ! empty( $s[ (string) getenv( "NEXO_A1" ) ] ) ? "true" : "false";' "$2"
	exit 0
fi
if [ "${1:-}" = "--diseno-foto" ]; then
	php_wp '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$foto = array();
foreach ( array( "classes", "variables", "kit" ) as $tipo ) { $foto[ $tipo ] = $store->snapshot( $tipo ); }
update_option( "nexo_prueba_diseno_foto", $foto, false );
echo "foto guardada";'
	exit 0
fi
if [ "${1:-}" = "--diseno-restaurar" ]; then restaurar_diseno; exit 0; fi
if [ "${1:-}" = "--diseno-barrer" ]; then
	php_wp '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$sufijo = (string) getenv( "NEXO_A1" );
$salida = array();
$clases = $store->snapshot( "classes" );
$quitar = array();
foreach ( $clases["items"] as $id => $item ) {
	if ( 0 === strpos( (string) ( $item["label"] ?? "" ), "nexo-prueba-" ) && (string) $id !== getenv( "NEXO_A2" ) ) { $quitar[] = (string) $id; }
}
if ( array() !== $quitar ) {
	foreach ( $quitar as $id ) { unset( $clases["items"][ $id ] ); }
	$clases["order"] = array_values( array_diff( $clases["order"], $quitar ) );
	$store->restore( "classes", $clases );
	$salida[] = "clases: " . implode( ",", $quitar );
}
$variables = $store->snapshot( "variables" );
$quitar = array();
foreach ( $variables["data"] ?? array() as $id => $entrada ) {
	if ( 0 === strpos( (string) ( $entrada["label"] ?? "" ), "nexo-prueba-" ) && (string) $id !== getenv( "NEXO_A3" ) ) { $quitar[] = (string) $id; }
}
if ( array() !== $quitar ) {
	foreach ( $quitar as $id ) { unset( $variables["data"][ $id ] ); }
	$store->restore( "variables", $variables );
	$salida[] = "variables: " . implode( ",", $quitar );
}
$paginas = array();
foreach ( get_posts( array( "post_type" => "page", "post_status" => "any", "numberposts" => -1, "s" => "elementor-mcp-", "fields" => "ids" ) ) as $id ) {
	$titulo = get_the_title( $id );
	if ( false !== strpos( $titulo, "elementor-mcp-" ) && false === strpos( $titulo, "elementor-mcp-" . $sufijo ) && "trash" !== get_post_status( $id ) ) { wp_trash_post( $id ); $paginas[] = $id; }
}
if ( array() !== $paginas ) { $salida[] = "páginas: " . implode( ",", $paginas ); }
$store->clear_cache();
echo array() === $salida ? "sin restos" : implode( "; ", $salida );' "$2" "$3" "$4"
	exit 0
fi

# Subcomandos para el bloque de concurrencia.
#   --copias <id>       IDs de las copias de trabajo enlazadas al original (en cualquier estado)
#   --bloqueos          opciones nexo_mcp_lock_* que quedan en la base (debe ser ninguna)
#   --poner-bloqueo <id> <valor> / --quitar-bloqueo <id>   fija o quita la cerradura de un original
#   --campo <id> <f>    campo de la entrada (post_content, post_title, post_excerpt…)
if [ "${1:-}" = "--copias" ]; then
	wpr post list --post_type=page --post_status=any --meta_key=_nexo_mcp_draft_of --meta_value="$2" --field=ID | tr '\n' ' '
	exit 0
fi
if [ "${1:-}" = "--bloqueos" ]; then wp option list --search='nexo_mcp_lock_*' --field=option_name; exit 0; fi
if [ "${1:-}" = "--poner-bloqueo" ]; then wp option update "nexo_mcp_lock_$2" "$3" --autoload=no >/dev/null; exit 0; fi
if [ "${1:-}" = "--quitar-bloqueo" ]; then wp option delete "nexo_mcp_lock_$2" >/dev/null; exit 0; fi
if [ "${1:-}" = "--campo" ]; then wpr post get "$2" --field="$3"; exit 0; fi

fallas=0
falla() { echo "FALLA: $*"; fallas=$((fallas + 1)); }
ok() { echo "OK: $*"; }

echo "### Ayudante php_wp (sin docker)"
# Sin docker y con los argumentos exactos de wp-cli: no hereda la memoria extra de Pro.
env -u NEXO_WPCLI_MEMORIA tools/prueba_php_wp.sh || falla "el ayudante php_wp no pasó su prueba (tools/prueba_php_wp.sh)"
echo "### Limpieza y señales (sin docker)"
tools/prueba_limpieza.sh || falla "la limpieza no pasó su prueba (tools/prueba_limpieza.sh)"
echo "### Cliente MCP frente al límite de pedidos (sin docker)"
python3 -I tools/prueba_cliente_limite.py || falla "el cliente MCP no pasó su prueba (tools/prueba_cliente_limite.py)"

tools/instalar_plugins_prueba.sh || { echo "FALLA: no se pudieron instalar los plugins"; exit 1; }

original="$(wp option get nexo_mcp_settings --format=json)"
[ -n "$original" ] || { echo "FALLA: no se pudieron leer los ajustes"; exit 1; }
suffix=""
page_id=""
group_ids=""
atomic_page_id=""
created_ids=""
media_id=""
class_id=""
variable_id=""
kit_id=""
diseno_foto_tomada=""
dinamicos_cap_agregada=""
componentes_cap_agregada=""
divi_tema_previo=""
divi_diseno_foto_tomada=""
divi_diseno_cap_agregada=""
divi_presets_foto_tomada=""
divi_presets_cap_agregada=""
divi_dinamico_foto_tomada=""
divi_dinamico_cap_agregada=""
divi_condiciones_foto_tomada=""
divi_condiciones_cap_agregada=""
divi_tb_foto_tomada=""
divi_lib_foto_tomada=""
exp_names="e_opt_in_v4 e_atomic_elements"
# Estado previo de cada experimento ("__sin__" si la opción no existía) y de las
# variables del Kit, para dejarlo exactamente como estaba. Las variables van como
# `datos:<base64 del meta crudo>` o con el centinela explícito `__sin__` (el Kit no
# tenía el meta). Cualquier otra cosa —p. ej. vacío porque la captura falló— no es un
# estado: nunca se interpreta como «sin variables», porque restaurar borraría las del sitio.
exp_prev=""
variables_prev=""
restore() {
	wp option update nexo_mcp_settings "$original" --format=json >/dev/null && echo "Ajustes restaurados."
}
restore_experiments() {
	local name prev
	for name in $exp_names; do
		prev="$(printf '%s\n' "$exp_prev" | sed -n "s/^$name=//p")"
		[ -n "$prev" ] || continue
		if [ "$prev" = "__sin__" ]; then wp option delete "elementor_experiment-$name" >/dev/null || true
		else wp option update "elementor_experiment-$name" "$prev" >/dev/null; fi
	done
	echo "Experimentos de Elementor restaurados."
}
remove_design_fixtures() {
	[ -n "$class_id$variable_id" ] || return 0
	php_wp '
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit );
$all = $repo->all()->get();
$clase = (string) getenv( "NEXO_A1" );
unset( $all["items"][ $clase ] );
$repo->put( $all["items"], array_values( array_diff( $all["order"], array( $clase ) ) ) );
$prev = (string) getenv( "NEXO_A2" );
if ( "__sin__" === $prev ) { delete_post_meta( $kit->get_main_id(), "_elementor_global_variables" ); }
elseif ( 0 === strpos( $prev, "datos:" ) ) { update_post_meta( $kit->get_main_id(), "_elementor_global_variables", wp_slash( base64_decode( substr( $prev, 6 ) ) ) ); }
// Sin una captura válida no se toca el meta: mejor dejar la variable de prueba que borrar las del sitio.' "$class_id" "$variables_prev" >/dev/null
	case "$variables_prev" in
		__sin__|datos:*) ;;
		*) echo "AVISO: no hay captura válida de las variables del Kit; no se restauraron (puede quedar la variable de prueba $variable_label)." ;;
	esac
}
# Comprueba en el sitio que la clase global de prueba ya no está: 0 quitada, 1 sigue ahí, 2 no se pudo leer. json_encode
# siempre imprime algo (como mínimo `[]`), así que una salida vacía también es una lectura fallida.
clase_global_quitada() {
	local etiquetas
	etiquetas="$(php_wpr 'echo json_encode( \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make()->all_labels() );')" || return 2
	[ -n "$etiquetas" ] || return 2
	if printf '%s' "$etiquetas" | grep -q "$1"; then return 1; fi
	return 0
}
# Borra (force) las páginas de Divi 5 de esta corrida, por el marcador del título (copias incluidas) y su et-cache.
barrer_divi() {
	local id malas=0
	[ -n "$suffix" ] || return 0
	for id in $(wpr post list --post_type=page --post_status=any,trash --field=ID --s="divi5-mcp-$suffix"); do
		tools/prueba_adaptadores.sh --divi-borrar "$id"
		if [ -z "$(wpr post get "$id" --field=post_status)" ]; then echo "Página Divi 5 borrada: $id"
		else echo "FALLA: la página Divi 5 $id no se borró"; malas=1; fi
	done
	return "$malas"
}
cleanup() {
	echo
	echo "### Limpieza (a la papelera)"
	local fallidas=0 id
	# wp_trash_post() guarda el estado anterior, así que se restaura tal como
	# estaba. `wp post delete` no sirve: rechaza la papelera para tipos que no
	# son post/page, como los grupos y campos de ACF.
	# Páginas de create-elementor-page: se barren por el sufijo del título, así que
	# también caen las que quedaran si la prueba se interrumpió.
	if [ -n "$suffix" ]; then
		created_ids="$(wpr post list --post_type=page --post_status=any --field=ID --s="elementor-mcp-$suffix" | tr '\n' ' ')"
	fi
	for id in $page_id $atomic_page_id $created_ids $group_ids; do
		php_wp 'wp_trash_post( (int) getenv( "NEXO_A1" ) );' "$id" >/dev/null
		if [ "$(wpr post get "$id" --field=post_status)" = "trash" ]; then
			echo "Papelera: $id"
		else
			echo "FALLA: $id no quedó en la papelera"; fallidas=$((fallidas + 1))
		fi
	done
	if [ -n "$media_id" ]; then
		php_wp 'wp_delete_attachment( (int) getenv( "NEXO_A1" ), true );' "$media_id" >/dev/null
		echo "Imagen de prueba quitada: $media_id"
	fi
	# Si el bloque de herramientas de escritura se cortó a medias, se devuelve el sistema de diseño a como estaba.
	if [ -n "$diseno_foto_tomada" ]; then
		echo "Sistema de diseño: $(restaurar_diseno)"
		diseno_foto_tomada=""
	fi
	# Si el bloque de datos dinámicos concedió la capacidad al administrador, se la quita.
	if [ -n "$dinamicos_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_builders_dynamic" );' >/dev/null
		echo "Capacidad nexo_mcp_builders_dynamic quitada."
		dinamicos_cap_agregada=""
	fi
	# Componentes del bloque de F7 (se barren por el sufijo, también si se cortó) y la capacidad concedida.
	if [ -n "$suffix" ]; then
		for id in $(wpr post list --post_type=elementor_component --post_status=any --field=ID --s="elementor-mcp-$suffix"); do
			php_wp 'wp_delete_post( (int) getenv( "NEXO_A1" ), true );' "$id" >/dev/null
			if [ -z "$(wpr post get "$id" --field=post_status)" ]; then echo "Componente borrado: $id"
			else echo "FALLA: el componente $id no se borró"; fallidas=$((fallidas + 1)); fi
		done
	fi
	if [ -n "$componentes_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_builders_components" );' >/dev/null
		echo "Capacidad nexo_mcp_builders_components quitada."
		componentes_cap_agregada=""
	fi
	# Divi 5, diseño global: opciones crudas como estaban, capacidad concedida y páginas (por el marcador del título,
	# también si se cortó); el tema previo se devuelve después.
	if [ -n "$divi_diseno_foto_tomada" ]; then
		echo "Diseño global de Divi: $(restaurar_divi_diseno)"
		divi_diseno_foto_tomada=""
	fi
	if [ -n "$divi_diseno_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_divi_design" );' >/dev/null
		echo "Capacidad nexo_mcp_divi_design quitada."
		divi_diseno_cap_agregada=""
	fi
	if [ -n "$divi_presets_foto_tomada" ]; then
		echo "Presets de Divi: $(restaurar_divi_presets)"
		divi_presets_foto_tomada=""
	fi
	if [ -n "$divi_presets_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_divi_presets" );' >/dev/null
		echo "Capacidad nexo_mcp_divi_presets quitada."
		divi_presets_cap_agregada=""
	fi
	# Divi 5, contenido dinámico y bucles: entradas de prueba, opciones crudas (ajustes, bloqueo, roles) y capacidad.
	if [ -n "$divi_dinamico_foto_tomada" ]; then
		[ -z "$suffix" ] || echo "Entradas de prueba de Divi dinámico borradas: $(tools/prueba_adaptadores.sh --divi-dinamico-barrer "$suffix")"
		echo "Contenido dinámico de Divi: $(restaurar_divi_dinamico)"
		divi_dinamico_foto_tomada=""
		divi_dinamico_cap_agregada=""
	fi
	# Divi 5, condiciones de visualización: opciones crudas (ajustes, versión, bloqueo, roles) y la sesión de prueba.
	if [ -n "$divi_condiciones_foto_tomada" ]; then
		php_wp 'delete_option( "nexo_prueba_divi_condiciones_token" );' >/dev/null
		echo "Condiciones de Divi: $(restaurar_divi_condiciones)"
		divi_condiciones_foto_tomada=""
		divi_condiciones_cap_agregada=""
	fi
	# Divi 5, Theme Builder: se barre lo creado por id (plantillas, layouts, Theme Builder en vivo, términos, et-cache) y se
	# devuelven las opciones crudas (ajustes, versión, bloqueos, roles) a la foto. Antes las páginas, que tienen su marcador.
	if [ -n "$divi_tb_foto_tomada" ]; then
		barrer_divi || fallidas=$((fallidas + 1))
		echo "Theme Builder de Divi: $(restaurar_divi_tb)"
		cerrar_divi_tb
		divi_tb_foto_tomada=""
	fi
	# Divi 5, biblioteca: se borran SÓLO los ids que anotó la prueba y se devuelven las opciones crudas (ajustes, versión, bloqueos, roles).
	if [ -n "$divi_lib_foto_tomada" ]; then
		barrer_divi || fallidas=$((fallidas + 1))
		echo "Biblioteca de Divi: $(restaurar_divi_lib)"
		cerrar_divi_lib
		divi_lib_foto_tomada=""
	fi
	barrer_divi || fallidas=$((fallidas + 1))
	if [ -n "$divi_tema_previo" ]; then
		if wp theme activate "$divi_tema_previo" >/dev/null; then echo "Tema restaurado: $divi_tema_previo"
		else echo "FALLA: no se pudo restaurar el tema $divi_tema_previo"; fallidas=$((fallidas + 1)); fi
		divi_tema_previo=""
	fi
	remove_design_fixtures
	if [ -n "$class_id" ]; then
		clase_global_quitada "$class_id"
		case $? in
			0) echo "Clase global y variable quitadas." ;;
			1) echo "FALLA: la clase global $class_id sigue ahí"; fallidas=$((fallidas + 1)) ;;
			*) echo "FALLA: no se pudo comprobar que la clase global $class_id se quitó (falló la lectura)"; fallidas=$((fallidas + 1)) ;;
		esac
	fi
	restore_experiments || fallidas=$((fallidas + 1))
	restore || { echo "FALLA: no se pudieron restaurar los ajustes"; fallidas=$((fallidas + 1)); }
	[ "$fallidas" -eq 0 ]
}
# Una corrida cortada (Ctrl-C, kill, cierre de la terminal) sale por EXIT: la limpieza devuelve también los ajustes
# (incluido destructive_enabled, que el bloque del sistema de diseño enciende), los experimentos y el sistema de diseño.
# Mientras limpia se ignoran las señales, así un segundo Ctrl-C no la corta a medias. Se prueba sin docker con
# tools/prueba_limpieza.sh.
limpiar_al_salir() {
	trap '' INT TERM HUP
	cleanup
	local resultado=$?
	# Lo último: la limpieza usa wp-cli.
	wpcli_bajar
	return "$resultado"
}
atrapar_senales() {
	trap limpiar_al_salir EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	trap 'exit 129' HUP
}
atrapar_senales
# Un solo contenedor de wp-cli para toda la corrida (ver tools/wpcli.sh); si no se puede, cada llamada crea el suyo.
wpcli_levantar && echo "Contenedor de wp-cli: ${NEXO_WPCLI:0:12}" || echo "AVISO: no se pudo levantar el contenedor de wp-cli; cada llamada crea uno (más lento)."

# Módulos necesarios activos y sin «Solo revisar».
needed="$(printf '%s' "$original" | python3 -I -c '
import json, sys
s = json.load(sys.stdin)
s.update(read_only=False, content_tools_enabled=True, seo_tools_enabled=True, builders_tools_enabled=True, design_system_tools_enabled=True)
print(json.dumps(s))')"
wp option update nexo_mcp_settings "$needed" --format=json >/dev/null

echo
echo "### Preparación"
suffix="$RANDOM$RANDOM"
campo="nexo_prueba_texto"
valor_campo="Valor ACF $suffix"
titulo_seo="Título SEO $suffix"
descripcion_seo="Descripción SEO $suffix para Yoast."
texto="Título editado por la IA $suffix"
enlace_original="https://example.com/original"
enlace="https://example.com/editado-$suffix"

# Árbol mínimo: contenedor con heading, editor de texto y botón con enlace.
tree="$(printf '[{"id":"aaaa0000","elType":"container","settings":{},"elements":[{"id":"aaaa0001","elType":"widget","widgetType":"heading","settings":{"title":"Título original"},"elements":[]},{"id":"aaaa0002","elType":"widget","widgetType":"text-editor","settings":{"editor":"<p>Texto original</p>"},"elements":[]},{"id":"aaaa0003","elType":"widget","widgetType":"button","settings":{"text":"Botón","link":{"url":"%s","is_external":"","nofollow":""}},"elements":[]}]}]' "$enlace_original")"

page_id="$(wp post create --post_type=page --post_status=draft --post_title="Prueba adaptadores $suffix" --porcelain)"
[ -n "$page_id" ] || { echo "FALLA: no se pudo crear la página de prueba"; exit 1; }
wp post meta update "$page_id" _elementor_edit_mode builder >/dev/null
wp post meta update "$page_id" _elementor_data "$tree" >/dev/null
echo "Página borrador de Elementor: $page_id"

# Grupo de campos ACF para páginas, guardado en la base de datos (las llamadas
# MCP son peticiones distintas: un grupo sólo local no existiría para ellas).
group_ids="$(php_wp '
$grupo = acf_import_field_group( array(
	"key"      => "group_nexo_prueba_adaptadores",
	"title"    => "Nexo prueba adaptadores",
	"fields"   => array( array( "key" => "field_nexo_prueba_texto", "label" => "Texto de prueba", "name" => "nexo_prueba_texto", "type" => "text" ) ),
	"location" => array( array( array( "param" => "post_type", "operator" => "==", "value" => "page" ) ) ),
	"active"   => true,
) );
$ids = array( $grupo["ID"] );
foreach ( acf_get_fields( $grupo["key"] ) as $campo ) { $ids[] = $campo["ID"]; }
echo implode( " ", $ids );')"
[ -n "$group_ids" ] || { echo "FALLA: no se pudo crear el grupo ACF"; exit 1; }
echo "Grupo de campos ACF (ids): $group_ids"

# --- Elementor 4 atómico ---------------------------------------------------
# Se fuerza el experimento «activo» (aunque ya lo estuviera por defecto) y al
# salir se deja como estaba. La clase, la variable y el árbol se crean con las
# APIs PHP de Elementor: nunca se escribe a mano su meta.
for name in $exp_names; do
	prev="$(php_wp 'echo get_option( "elementor_experiment-" . getenv( "NEXO_A1" ), "__sin__" );' "$name")"
	exp_prev="$exp_prev$name=$prev"$'\n'
	wp option update "elementor_experiment-$name" active >/dev/null
done
[ "$(php_wpr 'echo \Elementor\Plugin::$instance->experiments->is_feature_active( "e_atomic_elements" ) ? "si" : "no";')" = "si" ] \
	|| { echo "FALLA: no se pudo activar el experimento atómico"; exit 1; }
echo "Experimento atómico activo (estado previo: $(printf '%s' "$exp_prev" | tr '\n' ' '))"

kit_id="$(php_wpr 'echo \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id();')"
variables_prev="$(php_wpr '$id = \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id(); echo metadata_exists( "post", $id, "_elementor_global_variables" ) ? "datos:" . base64_encode( (string) get_post_meta( $id, "_elementor_global_variables", true ) ) : "__sin__";')"
# Sin una captura válida no se sigue: restaurar un estado vacío borraría las variables del sitio.
case "$variables_prev" in
	__sin__|datos:*) ;;
	*) variables_prev=""; echo "FALLA: no se pudo capturar el estado de las variables del Kit; se aborta antes de crear nada."; exit 1 ;;
esac
class_id="g-nexo$RANDOM"
class_label="nexo-prueba-clase-$suffix"
variable_label="nexo-prueba-var-$suffix"
variable_id="$(php_wp '
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit );
$all = $repo->all()->get();
$clase = (string) getenv( "NEXO_A1" );
$all["items"][ $clase ] = array( "id" => $clase, "type" => "class", "label" => getenv( "NEXO_A2" ), "variants" => array() );
$all["order"][] = $clase;
$repo->put( $all["items"], $all["order"] );
$variables = new \Elementor\Modules\Variables\Storage\Variables_Repository( $kit );
$coleccion = $variables->load();
$variable = \Elementor\Modules\Variables\Storage\Entities\Variable::create_new( array(
	"id" => $coleccion->next_id(), "type" => "global-color-variable", "label" => getenv( "NEXO_A3" ),
	"value" => "#ff0000", "order" => $coleccion->get_next_order(),
) );
$variable->validate();
$coleccion->add_variable( $variable );
$variables->save( $coleccion );
echo $variable->id();' "$class_id" "$class_label" "$variable_label")"
[ -n "$variable_id" ] || { echo "FALLA: no se pudo crear la variable"; exit 1; }
echo "Clase global: $class_id ($class_label); variable: $variable_id ($variable_label); Kit: $kit_id"

atomic_heading="Título atómico $suffix"
atomic_page_id="$(wp post create --post_type=page --post_status=draft --post_title="Prueba atómica $suffix" --porcelain)"
[ -n "$atomic_page_id" ] || { echo "FALLA: no se pudo crear la página atómica"; exit 1; }
guardado="$(php_wp '
$id = (int) getenv( "NEXO_A1" );
$clase = (string) getenv( "NEXO_A3" );
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$arbol = array( array(
	"id" => "bbbb0000", "elType" => "e-flexbox", "styles" => array(), "elements" => array(
		array(
			"id" => "bbbb0001", "elType" => "widget", "widgetType" => "e-heading", "styles" => array(), "elements" => array(),
			"settings" => array(
				"title"   => array( "\$\$type" => "escaped-html", "value" => getenv( "NEXO_A2" ) ),
				"classes" => array( "\$\$type" => "classes", "value" => array( $clase ) ),
			),
		),
	),
	"settings" => array( "classes" => array( "\$\$type" => "classes", "value" => array( $clase ) ) ),
) );
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' "$atomic_page_id" "$atomic_heading" "$class_id")"
[ "$guardado" = guardado ] || { echo "FALLA: Document::save no guardó el árbol atómico ($guardado)"; exit 1; }
echo "Página atómica borrador: $atomic_page_id"

echo
echo "### Prueba por MCP"
python3 -I tools/prueba_adaptadores.py --verificar-enlace tools/prueba_adaptadores.sh --pagina "$page_id" --campo "$campo" --valor-campo "$valor_campo" \
	--titulo-seo "$titulo_seo" --descripcion-seo "$descripcion_seo" --texto "$texto" --enlace "$enlace"
[ $? -eq 0 ] || falla "la prueba por MCP tuvo comprobaciones fallidas (ver el resumen)"

echo
echo "### Prueba atómica por MCP (get-design-system, get-page-structure)"
python3 -I - "$atomic_page_id" "$class_id" "$class_label" "$variable_id" "$variable_label" "$atomic_heading" "$kit_id" <<'PY'
import importlib.util, json, pathlib, sys

pagina, clase, etiqueta, variable, etiqueta_var, titulo, kit = sys.argv[1:8]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []

def comprobar(nombre, fn):
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def sistema():
    _, d = cli.ok("get-design-system", {})
    assert d.get("atomic_enabled") is True, f"atomic_enabled: {d.get('atomic_enabled')!r}"
    assert d.get("kit_id") == int(kit), f"kit_id: {d.get('kit_id')!r}"
    assert {"id": clase, "label": etiqueta} in d["classes"], f"clases: {d['classes']}"
    var = next((v for v in d["variables"] if v["id"] == variable), None)
    assert var and var["label"] == etiqueta_var and var["value"] == "#ff0000", f"variables: {d['variables']}"
    assert var["type"] == "global-color-variable", var
    assert d["colors"] and {"id", "title", "color"} <= set(d["colors"][0]), f"colores: {d['colors'][:1]}"
    assert d["typography"] and {"id", "title"} <= set(d["typography"][0]), f"tipografía: {d['typography'][:1]}"

def estructura():
    _, d = cli.ok("get-page-structure", {"id": int(pagina)})
    assert d.get("builder") == "elementor", f"builder: {d.get('builder')!r}"
    nodos = {n["id"]: n for n in d["nodes"]}
    h, caja = nodos.get("bbbb0001"), nodos.get("bbbb0000")
    assert h and h["type"] == "e-heading", f"nodos: {list(nodos)}"
    assert h.get("text") == titulo, f"texto: {h.get('text')!r}"
    assert h.get("classes") == [clase], f"clases del heading: {h.get('classes')!r}"
    assert h.get("editable") is False and "sólo lectura" in h.get("reason", ""), h
    assert caja and caja["type"] == "e-flexbox" and caja.get("classes") == [clase], caja

comprobar("get-design-system: clase, variable, atomic_enabled, Kit", sistema)
comprobar("get-page-structure: e-heading con texto y clases", estructura)
print(f"\n{2 - len(fallas)}/2 comprobaciones atómicas OK.")
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "la prueba atómica por MCP tuvo comprobaciones fallidas"

check_eq() { # nombre esperado obtenido
	if [ "$2" = "$3" ]; then ok "$1 guardado: $3"; else falla "$1: esperado «$2», guardado «$3»"; fi
}
echo
echo "### Creación de página atómica por MCP (create-elementor-page)"
# Imagen de prueba propia (1×1 PNG) en la biblioteca: la página la referencia por id.
media_id="$(php_wp '
require_once ABSPATH . "wp-admin/includes/file.php";
require_once ABSPATH . "wp-admin/includes/media.php";
require_once ABSPATH . "wp-admin/includes/image.php";
$tmp = wp_tempnam( "nexo-prueba.png" );
file_put_contents( $tmp, base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" ) );
$id = media_handle_sideload( array( "name" => "nexo-prueba-" . getenv( "NEXO_A1" ) . ".png", "tmp_name" => $tmp ), 0 );
echo is_wp_error( $id ) ? "" : $id;' "$suffix")"
[ -n "$media_id" ] || { echo "FALLA: no se pudo crear la imagen de prueba"; exit 1; }
echo "Imagen de prueba: $media_id"

crear_titulo="Prueba creación atómica elementor-mcp-$suffix"
crear_texto="Hola desde create-elementor-page $suffix"
python3 -I - "$class_id" "$media_id" "$suffix" "$crear_titulo" "$crear_texto" <<'PY'
import importlib.util, json, pathlib, subprocess, sys

clase, medio, suffix, titulo, texto = sys.argv[1:6]
medio = int(medio)
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *args], capture_output=True, text=True).stdout.strip()

def contar():
    return script("--contar-paginas")

def prop(tipo, valor):
    return {"$$type": tipo, "value": valor}

def html(t):
    return prop("escaped-html", t)

def cadena(t):
    return prop("string", t)

def clases(*nombres):
    return prop("classes", list(nombres))

def enlace(url):
    return prop("link", {"destination": prop("url", url), "isTargetBlank": prop("boolean", True)})

def imagen(id_medio):
    return prop("image", {"src": prop("image-src", {"id": prop("image-attachment-id", id_medio), "url": None}), "size": cadena("full")})

estilo = {"id": "caja", "type": "class", "label": "caja-prueba", "variants": [
    {"meta": {"breakpoint": "desktop", "state": None},
     "props": {"gap": prop("size", {"size": 16, "unit": "px"}), "flex-direction": cadena("column")}}]}

arbol = [{
    "id": "id-del-cliente", "type": "e-flexbox",
    "settings": {"classes": clases(clase, "caja")},
    "styles": {"caja": estilo},
    "elements": [
        {"type": "e-heading", "settings": {"title": html(texto), "tag": cadena("h1"), "classes": clases(clase)}},
        {"type": "e-paragraph", "settings": {"paragraph": html("Un párrafo de prueba")}},
        {"type": "e-button", "settings": {"text": html("Contacto"), "link": enlace("https://example.com/contacto")}},
        {"type": "e-image", "settings": {"image": imagen(medio)}},
        {"type": "e-divider"},
    ],
}]

creada = {}

def crear():
    antes = int(contar())
    _, d = cli.ok("create-elementor-page", {"title": titulo, "elements": arbol})
    creada.update(d)
    assert d["status"] == "draft", f"status: {d['status']!r}"
    assert d["nodes"] == 6, f"nodes: {d['nodes']!r}"
    assert f"post={d['id']}&action=elementor" in d["edit_url"], d["edit_url"]
    assert str(d["id"]) in d["preview_url"], d["preview_url"]
    assert int(contar()) == antes + 1, "debía crearse exactamente una página"

def estructura():
    _, d = cli.ok("get-page-structure", {"id": creada["id"]})
    assert d["builder"] == "elementor", d["builder"]
    nodos = d["nodes"]
    assert [n["type"] for n in nodos] == ["e-flexbox", "e-heading", "e-paragraph", "e-button", "e-image", "e-divider"], [n["type"] for n in nodos]
    assert all(n["id"] != "id-del-cliente" for n in nodos), "el id del cliente debía ignorarse"
    caja, h, p, b = nodos[0], nodos[1], nodos[2], nodos[3]
    assert h["text"] == texto, f"texto: {h.get('text')!r}"
    assert p["text"] == "Un párrafo de prueba" and b["text"] == "Contacto", (p, b)
    assert h["classes"] == [clase], h
    assert caja["classes"][0] == clase and caja["classes"][1].startswith(f"e-{caja['id']}-"), caja["classes"]

comprobar("create-elementor-page: borrador con 6 nodos", crear)
comprobar("get-page-structure: textos, clases global y local, ids del plugin", estructura)

def rechazo(nombre, elementos, esperado):
    def fn():
        antes = contar()
        es_error, msg, _ = cli.call("create-elementor-page", {"title": f"Rechazada {nombre} elementor-mcp-{suffix}", "elements": elementos})
        assert es_error, "debía rechazarse"
        assert msg.startswith(esperado), f"mensaje: {msg[:300]!r}"
        assert contar() == antes, "se creó una página"
    comprobar(f"rechazo: {nombre}", fn)

def caja(*hijos):
    return [{"type": "e-flexbox", "elements": list(hijos)}]

rechazo("e-svg", caja({"type": "e-svg"}),
        "elements[0].elements[0].type: el tipo «e-svg» no está permitido: un SVG puede llevar código ejecutable.")
rechazo("widget v3 html", caja({"type": "html", "settings": {"html": "<script>alert(1)</script>"}}),
        "elements[0].elements[0].type: «html» admite código arbitrario y nunca se crea.")
rechazo("widget v3 heading", caja({"type": "heading"}),
        "elements[0].elements[0].type: «heading» es un elemento de Elementor 3;")
rechazo("enlace javascript:", caja({"type": "e-button", "settings": {"link": enlace("javascript:alert(1)")}}),
        "elements[0].elements[0].settings.link.value.destination.value: El esquema «javascript» no se admite en un enlace")
rechazo("imagen por URL", caja({"type": "e-image", "settings": {"image": prop("image", {"src": prop("image-src", {"id": None, "url": prop("url", "https://evil.example/x.png")})})}}),
        "elements[0].elements[0].settings.image.value.src.value.url: las imágenes se indican por el identificador")
rechazo("imagen inexistente", caja({"type": "e-image", "settings": {"image": imagen(99999999)}}),
        "elements[0].elements[0].settings.image.value.src.value.id.value: No existe ningún medio con ese identificador.")
rechazo("clave de settings desconocida", caja({"type": "e-heading", "settings": {"titel": html("x")}}),
        "elements[0].elements[0].settings.titel: la propiedad «titel» no existe en «e-heading».")
rechazo("clase inexistente", caja({"type": "e-heading", "settings": {"classes": clases("g-no-existe")}}),
        "elements[0].elements[0].settings.classes.value[0]: la clase «g-no-existe» no existe")
rechazo("valor inválido (parser de Elementor)", caja({"type": "e-heading", "settings": {"tag": cadena("script")}}),
        "elements[0].elements[0].settings.tag: Elementor rechazó el valor (invalid_value).")

def con_estilo(props, extra=None):
    e = {"id": "caja", "type": "class", "label": "caja-prueba", "variants": [
        {"meta": {"breakpoint": "desktop", "state": None}, "props": props}]}
    e.update(extra or {})
    return [{"type": "e-flexbox", "settings": {"classes": clases("caja")}, "styles": {"caja": e},
             "elements": [{"type": "e-heading", "settings": {"title": html("x")}}]}]

rechazo("estilo con cssName (selector CSS)",
        con_estilo({}, {"cssName": "x{}body{background:url(https://evil.example/c.png)}.y"}),
        "elements[0].styles.caja.cssName: la clave «cssName» no se admite en un estilo.")
rechazo("valor de estilo que cierra la regla CSS",
        con_estilo({"color": prop("color", "red;}body{display:none}.zz{background-image:url(https://evil.example/t.png)")}),
        "elements[0].styles.caja.variants[0].props.color.value: el valor contiene caracteres o funciones que no se admiten")
rechazo("propiedad de estilo desconocida (colour)",
        con_estilo({"colour": prop("color", "red"), "font-size": prop("size", {"size": 20, "unit": "px"})}),
        "elements[0].styles.caja.variants[0].props.colour: la propiedad de estilo «colour» no existe")
rechazo("clave desconocida dentro de una propiedad de estilo",
        con_estilo({"padding": prop("dimensions", {"block-start": prop("size", {"size": 1, "unit": "px"}), "color;x": prop("size", {"size": 1, "unit": "px"})})}),
        "elements[0].styles.caja.variants[0].props.padding.value.color;x: esa clave no existe en la forma")
rechazo("comillas sin cerrar en un valor de estilo",
        con_estilo({"font-family": prop("font-family", '"Open Sans')}),
        "elements[0].styles.caja.variants[0].props.font-family.value: el valor tiene comillas sin cerrar.")
rechazo("etiqueta de botón fuera de la lista", caja({"type": "e-button", "settings": {"tag": cadena("script")}}),
        "elements[0].elements[0].settings.tag: la etiqueta de «e-button» debe ser una de: button, a, span, div.")

def v4_apagado():
    antes = contar()
    try:
        assert script("--experimentos", "inactive") == "no", "no se pudo apagar el experimento"
        es_error, msg, _ = cli.call("create-elementor-page", {"title": f"Rechazada v4 elementor-mcp-{suffix}", "elements": caja({"type": "e-divider"})})
        assert es_error, "debía rechazarse"
        assert msg.startswith("Los elementos atómicos de Elementor 4 no están activos.") and "Editor V4" in msg, f"mensaje: {msg[:300]!r}"
        assert contar() == antes, "se creó una página"
    finally:
        assert script("--experimentos", "active") == "si", "no se pudo restaurar el experimento"

comprobar("rechazo: experimento v4 apagado (y restaurado)", v4_apagado)
print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de create-elementor-page OK.")
sys.exit(1 if fallas else 0)
PY
codigo=$?
creado_id="$(wpr post list --post_type=page --post_status=any --field=ID --s="$crear_titulo" | head -n 1)"
[ $codigo -eq 0 ] || falla "la prueba de create-elementor-page tuvo comprobaciones fallidas"

echo
echo "### Verificación de la página creada (wp-cli)"
if [ -n "$creado_id" ]; then
	check_eq "create-elementor-page: post_status" "draft" "$(wpr post get "$creado_id" --field=post_status)"
	check_eq "create-elementor-page: _elementor_edit_mode" "builder" "$(wpr post meta get "$creado_id" _elementor_edit_mode)"
	version_elementor="$(wpr post meta get "$creado_id" _elementor_version)"
	[ -n "$version_elementor" ] && ok "create-elementor-page: _elementor_version $version_elementor" || falla "create-elementor-page: falta _elementor_version"
	guardado="$(wpr post meta get "$creado_id" _elementor_data | python3 -I -c '
import json, sys
tree = json.load(sys.stdin)
nodos = []
def walk(items):
    for e in items:
        nodos.append(e)
        walk(e.get("elements", []))
walk(tree)
caja = nodos[0]
estilos = list(caja["styles"])
por_tipo = {(e.get("widgetType") or e["elType"]): e for e in nodos}
print(len(nodos))
print(por_tipo["e-heading"]["settings"]["title"]["value"])
print(por_tipo["e-image"]["settings"]["image"]["value"]["src"]["value"]["id"]["value"])
print(por_tipo["e-button"]["settings"]["link"]["value"]["destination"]["value"])
print("si" if len(estilos) == 1 and caja["styles"][estilos[0]]["id"] == estilos[0] and estilos[0] in caja["settings"]["classes"]["value"] else "no")
print("si" if all(len(e["id"]) >= 4 and e["id"] != "id-del-cliente" for e in nodos) else "no")' 2>&1)"
	check_eq "_elementor_data cantidad de nodos" "6" "$(printf '%s\n' "$guardado" | sed -n 1p)"
	check_eq "_elementor_data título" "$crear_texto" "$(printf '%s\n' "$guardado" | sed -n 2p)"
	check_eq "_elementor_data imagen (id de la biblioteca)" "$media_id" "$(printf '%s\n' "$guardado" | sed -n 3p)"
	check_eq "_elementor_data enlace del botón" "https://example.com/contacto" "$(printf '%s\n' "$guardado" | sed -n 4p)"
	check_eq "_elementor_data estilo local enlazado a su clase" "si" "$(printf '%s\n' "$guardado" | sed -n 5p)"
	check_eq "_elementor_data ids generados por el plugin" "si" "$(printf '%s\n' "$guardado" | sed -n 6p)"
	render="$(php_wp '
$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( (int) getenv( "NEXO_A1" ), true );
echo $html;' "$creado_id")"
	for esperado in "$crear_texto" "<h1" "https://example.com/contacto" "<img" "e-flexbox-base"; do
		case "$render" in
			*"$esperado"*) ok "Elementor renderiza la página creada (contiene «${esperado}»)" ;;
			*) falla "el renderizado de la página creada no contiene «${esperado}»" ;;
		esac
	done
	# La clase local del estilo creado debe llegar al HTML (la global de la prueba no
	# tiene variantes y Elementor no la imprime; su referencia ya se comprobó arriba).
	if printf '%s' "$render" | grep -Eq 'class="[^"]* e-[0-9a-f]+-[0-9a-f]+'; then
		ok "Elementor imprime la clase del estilo local en el HTML"
	else
		falla "el HTML renderizado no trae la clase del estilo local"
	fi
else
	falla "create-elementor-page no devolvió una página que verificar"
fi

echo
echo "### Reconstrucción de secciones por MCP (edit-elementor-section)"
# Tres páginas publicadas propias (el título lleva el sufijo, así que la limpieza las barre,
# y a sus copias): una v3 (sección > columna > widgets), una atómica y una v3 de una sola sección.
version_elementor="$(php_wpr 'echo ELEMENTOR_VERSION;')"
secciones_v3_titulo="Prueba secciones v3 elementor-mcp-$suffix"
secciones_atomica_titulo="Prueba secciones atomica elementor-mcp-$suffix"
secciones_una_titulo="Prueba secciones una raiz elementor-mcp-$suffix"
v3_tree="$(printf '[{"id":"sec3000","elType":"section","settings":{"gap":"default"},"elements":[{"id":"col3000","elType":"column","settings":{"_column_size":100},"elements":[{"id":"hd3000","elType":"widget","widgetType":"heading","settings":{"title":"Titulo v3 %s"},"elements":[]},{"id":"tx3000","elType":"widget","widgetType":"text-editor","settings":{"editor":"<p>Texto v3</p>"},"elements":[]}]}]},{"id":"sec3001","elType":"section","settings":{"gap":"default"},"elements":[{"id":"col3001","elType":"column","settings":{"_column_size":100},"elements":[{"id":"bt3000","elType":"widget","widgetType":"button","settings":{"text":"Boton v3","link":{"url":"https://example.com/v3","is_external":"","nofollow":""}},"elements":[]}]}]}]' "$suffix")"
una_tree='[{"id":"sec6000","elType":"section","settings":{"gap":"default"},"elements":[{"id":"col6000","elType":"column","settings":{"_column_size":100},"elements":[{"id":"hd6000","elType":"widget","widgetType":"heading","settings":{"title":"Unica raiz"},"elements":[]}]}]}]'
secciones_v3_id="$(wp post create --post_type=page --post_status=publish --post_title="$secciones_v3_titulo" --porcelain)"
secciones_una_id="$(wp post create --post_type=page --post_status=publish --post_title="$secciones_una_titulo" --porcelain)"
secciones_atomica_id="$(wp post create --post_type=page --post_status=publish --post_title="$secciones_atomica_titulo" --porcelain)"
[ -n "$secciones_v3_id" ] && [ -n "$secciones_una_id" ] && [ -n "$secciones_atomica_id" ] || { echo "FALLA: no se pudieron crear las páginas de prueba de secciones"; exit 1; }
for par in "$secciones_v3_id|$v3_tree" "$secciones_una_id|$una_tree"; do
	wp post meta update "${par%%|*}" _elementor_edit_mode builder >/dev/null
	wp post meta update "${par%%|*}" _elementor_version "$version_elementor" >/dev/null
	wp post meta update "${par%%|*}" _elementor_data "${par#*|}" >/dev/null
done
guardado="$(php_wp '
$id = (int) getenv( "NEXO_A1" );
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$texto = function ( $t ) { return array( "\$\$type" => "escaped-html", "value" => $t ); };
$arbol = array( array(
	"id" => "fx4000", "elType" => "e-flexbox", "styles" => array(), "settings" => array(), "elements" => array(
		array( "id" => "eh4000", "elType" => "widget", "widgetType" => "e-heading", "styles" => array(), "elements" => array(), "settings" => array( "title" => $texto( "Titulo atomico base " . getenv( "NEXO_A2" ) ) ) ),
		array( "id" => "ep4000", "elType" => "widget", "widgetType" => "e-paragraph", "styles" => array(), "elements" => array(), "settings" => array( "paragraph" => $texto( "Parrafo atomico base " . getenv( "NEXO_A2" ) ) ) ),
	),
) );
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' "$secciones_atomica_id" "$suffix")"
[ "$guardado" = guardado ] || { echo "FALLA: no se pudo guardar la página atómica de secciones ($guardado)"; exit 1; }
echo "Páginas publicadas: v3 $secciones_v3_id, atómica $secciones_atomica_id, una raíz $secciones_una_id"

python3 -I - "$suffix" "$secciones_v3_id" "$secciones_atomica_id" "$secciones_una_id" <<'PY'
import importlib.util, json, pathlib, subprocess, sys

suffix, v3_id, atomica_id, una_id = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def contar():
    return script("--contar-paginas")

def crudo(id_pagina):
    return script("--meta", id_pagina, "_elementor_data")

def arbol(id_pagina):
    return json.loads(crudo(id_pagina))

def indice(nodos, salida=None):
    salida = {} if salida is None else salida
    for n in nodos:
        salida[n["id"]] = n
        indice(n.get("elements", []), salida)
    return salida

def render(id_pagina):
    return script("--render", id_pagina)

def prop(tipo, valor):
    return {"$$type": tipo, "value": valor}

def titulo(texto, tag="h2"):
    return {"type": "e-heading", "settings": {"title": prop("escaped-html", texto), "tag": prop("string", tag)}}

def caja(*hijos, tipo="e-flexbox"):
    return {"type": tipo, "elements": list(hijos)}

def editar(args):
    _, d = cli.ok("edit-elementor-section", args)
    return d

def sin_tocar(antes, despues, ids):
    a, b = indice(antes), indice(despues)
    for i in ids:
        assert i in b, f"falta el nodo {i}"
        assert a[i] == b[i], f"el nodo {i} cambió: {a[i]} != {b[i]}"

def copia_en_diff(original, copia):
    _, d = cli.ok("get-working-copy-diff", {"id": original})
    assert d["copy_id"] == copia and d["original_id"] == original, d
    assert "meta" in d["changed"] and any(e["key"] == "_elementor_data" for e in d["meta"]["changed"]), d

def tipos(nodos):
    return [(n.get("widgetType") or n["elType"]) for n in nodos]

# --- Página v3 -----------------------------------------------------------------
orig_v3 = crudo(v3_id)
orig_v3_arbol = json.loads(orig_v3)
v3 = {}
t_reemplazo = f"Reemplazo atomico {suffix}"
t_columna = f"Heading en columna v3 {suffix}"

def v3_reemplazar():
    d = editar({"id": v3_id, "operation": "replace", "node_id": "sec3000", "elements": [caja(titulo(t_reemplazo, "h1"))]})
    v3.update(d)
    assert d["draft_of"] == v3_id and d["id"] != v3_id, d
    assert d["operation"] == "replace" and len(d["node_ids"]) == 2, d
    assert d["removed"] == ["sec3000", "col3000", "hd3000", "tx3000"], d["removed"]
    assert crudo(v3_id) == orig_v3, "el original cambió"
    assert script("--estado", v3_id) == "publish" and script("--estado", d["id"]) == "draft"
    copia = arbol(d["id"])
    assert [n["id"] for n in copia][1:] == ["sec3001"] and copia[0]["elType"] == "e-flexbox", tipos(copia)
    assert copia[0]["id"] == d["node_ids"][0] and copia[0]["elements"][0]["widgetType"] == "e-heading"
    sin_tocar(orig_v3_arbol, copia, ["sec3001", "col3001", "bt3000"])

def v3_diff_y_render():
    copia_en_diff(v3_id, v3["id"])
    _, e = cli.ok("get-page-structure", {"id": v3["id"]})
    h = next(n for n in e["nodes"] if n["type"] == "e-heading")
    assert h["text"] == t_reemplazo, h
    html = render(v3["id"])
    assert t_reemplazo in html and "Boton v3" in html, "la copia no renderiza lo nuevo y lo v3 intacto"
    assert f"Titulo v3 {suffix}" not in html, "la sección reemplazada sigue renderizando"

def v3_insertar_en_columna():
    antes = arbol(v3["id"])
    d = editar({"id": v3_id, "operation": "insert", "node_id": "col3001", "position": "inside", "elements": [titulo(t_columna)]})
    assert d["id"] == v3["id"] and d["draft_of"] == v3_id, "debía reutilizar la copia"
    assert crudo(v3_id) == orig_v3, "el original cambió"
    despues = arbol(d["id"])
    columna = indice(despues)["col3001"]["elements"]
    assert [n["id"] for n in columna] == ["bt3000", d["node_ids"][0]], [n["id"] for n in columna]
    assert columna[1]["widgetType"] == "e-heading"
    sin_tocar(antes, despues, [n["id"] for n in antes if n["id"] != "sec3001"] + ["bt3000"])
    html = render(d["id"])
    assert t_columna in html and t_reemplazo in html and "Boton v3" in html, "la copia no renderiza el heading dentro de la columna v3"

def v3_publicar():
    copia_id = v3["id"]
    datos_copia = crudo(copia_id)
    _, m = cli.ok("set-content-status", {"id": copia_id, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (v3_id, copia_id), m
    assert json.loads(crudo(v3_id)) == json.loads(datos_copia), "el original no trae el árbol de la copia"
    assert script("--estado", v3_id) == "publish" and script("--estado", copia_id) == "", "la copia debía desaparecer"
    html = render(v3_id)
    assert t_reemplazo in html and t_columna in html, "el original publicado no renderiza lo nuevo"

comprobar("v3: reemplazar una sección por un e-flexbox va a la copia y no toca el original", v3_reemplazar)
comprobar("v3: get-working-copy-diff nombra la copia; la copia renderiza lo nuevo y lo v3 intacto", v3_diff_y_render)
comprobar("v3: insertar un e-heading en una columna v3 (misma copia, nodos v3 idénticos, renderiza)", v3_insertar_en_columna)
comprobar("v3: publicar la copia la fusiona en el original", v3_publicar)

# --- Página atómica ------------------------------------------------------------
orig_at = crudo(atomica_id)
orig_at_arbol = json.loads(orig_at)
at = {}
t_antes, t_despues, t_dentro = f"Antes {suffix}", f"Despues {suffix}", f"Dentro {suffix}"

def at_antes_y_despues():
    d = editar({"id": atomica_id, "operation": "insert", "node_id": "fx4000", "position": "before", "elements": [caja(titulo(t_antes))]})
    at.update(d)
    assert d["draft_of"] == atomica_id and "removed" not in d, d
    d2 = editar({"id": atomica_id, "operation": "insert", "node_id": "fx4000", "position": "after", "elements": [caja(titulo(t_despues), tipo="e-div-block")]})
    assert d2["id"] == d["id"], "debía reutilizar la copia"
    assert crudo(atomica_id) == orig_at, "el original cambió"
    copia = arbol(d["id"])
    assert [n["id"] for n in copia] == [d["node_ids"][0], "fx4000", d2["node_ids"][0]], [n["id"] for n in copia]
    assert tipos(copia) == ["e-flexbox", "e-flexbox", "e-div-block"], tipos(copia)
    sin_tocar(orig_at_arbol, copia, ["fx4000", "eh4000", "ep4000"])

def at_dentro():
    boton = {"type": "e-button", "settings": {"text": prop("escaped-html", t_dentro)}}
    d = editar({"id": atomica_id, "operation": "insert", "node_id": "fx4000", "position": "inside", "elements": [boton]})
    assert d["id"] == at["id"]
    hijos = indice(arbol(d["id"]))["fx4000"]["elements"]
    assert [n["id"] for n in hijos] == ["eh4000", "ep4000", d["node_ids"][0]] and hijos[2]["widgetType"] == "e-button", [n["id"] for n in hijos]
    assert crudo(atomica_id) == orig_at, "el original cambió"

def at_borrar():
    d = editar({"id": at["id"], "operation": "delete", "node_id": "ep4000"})
    assert d["id"] == at["id"] and d["draft_of"] == atomica_id and d["removed"] == ["ep4000"] and "node_ids" not in d, d
    copia = arbol(d["id"])
    assert "ep4000" not in indice(copia) and "eh4000" in indice(copia)
    assert "ep4000" in indice(json.loads(crudo(atomica_id))), "el original perdió el nodo"
    sin_tocar(orig_at_arbol, copia, ["eh4000"])

def at_diff_render_y_publicar():
    copia_en_diff(atomica_id, at["id"])
    html = render(at["id"])
    for t in (t_antes, t_despues, t_dentro, f"Titulo atomico base {suffix}"):
        assert t in html, f"la copia no renderiza «{t}»"
    assert f"Parrafo atomico base {suffix}" not in html, "el párrafo borrado sigue renderizando"
    datos_copia = crudo(at["id"])
    _, m = cli.ok("set-content-status", {"id": at["id"], "status": "publish"})
    assert (m["id"], m["merged_from"]) == (atomica_id, at["id"]), m
    assert json.loads(crudo(atomica_id)) == json.loads(datos_copia) and script("--estado", at["id"]) == ""
    html = render(atomica_id)
    assert t_antes in html and t_despues in html and t_dentro in html and f"Parrafo atomico base {suffix}" not in html, "el original publicado no renderiza la edición"

comprobar("atómica: insertar antes y después (raíz) en la copia, original intacto", at_antes_y_despues)
comprobar("atómica: insertar dentro de un contenedor (al final)", at_dentro)
comprobar("atómica: borrar un nodo en la copia (el original lo conserva)", at_borrar)
comprobar("atómica: diff, renderizado de la copia y publicación (fusión)", at_diff_render_y_publicar)

# --- Saneado: edit-elementor-section guarda lo mismo que create-elementor-page ---
def saneado():
    carga = {"type": "e-heading", "settings": {
        "title": prop("escaped-html", "<script>alert(1)</script><img src=x onerror=alert(2)><b>t</b>"),
        "_cssid": prop("string", 'a"><script>alert(1)</script>')}}
    d = editar({"id": atomica_id, "operation": "insert", "node_id": "fx4000", "position": "inside", "elements": [carga]})
    editado = indice(arbol(d["id"]))[d["node_ids"][0]]["settings"]
    _, c = cli.ok("create-elementor-page", {"title": f"Saneado elementor-mcp-{suffix}", "elements": [caja(carga)]})
    creado = next(n for n in indice(arbol(c["id"])).values() if n.get("widgetType") == "e-heading")["settings"]
    crudo_copia = crudo(d["id"])
    assert "<script" not in crudo_copia and "onerror" not in crudo_copia, "la copia guardó texto sin sanear"
    assert "<script" not in editado["_cssid"]["value"], editado["_cssid"]
    assert editado["title"] == creado["title"], f"título: {editado['title']!r} != {creado['title']!r}"
    assert editado["_cssid"] == creado["_cssid"], f"_cssid: {editado['_cssid']!r} != {creado['_cssid']!r}"

comprobar("saneado: lo guardado por edit-elementor-section es lo que guarda create-elementor-page", saneado)

# --- Rechazos: nada se escribe, no nace ninguna copia ---------------------------
def rechazo(nombre, id_pagina, args, esperado):
    def fn():
        antes, datos, estado = contar(), crudo(id_pagina), script("--estado", id_pagina)
        es_error, msg, _ = cli.call("edit-elementor-section", {"id": id_pagina, **args})
        assert es_error, "debía rechazarse"
        assert msg.startswith(esperado), f"mensaje: {msg[:300]!r}"
        assert contar() == antes, "cambió la cantidad de páginas (¿se creó una copia?)"
        assert crudo(id_pagina) == datos, "cambió el original"
        assert script("--estado", id_pagina) == estado
        assert script("--meta", id_pagina, "_nexo_mcp_draft") == "", "el original quedó enlazado a una copia"
    comprobar(f"rechazo: {nombre}", fn)

rechazo("widget suelto en la raíz", v3_id, {"operation": "replace", "node_id": "sec3001", "elements": [titulo("x")]},
        "elements[0].type: en la raíz de la página sólo se admiten contenedores atómicos")
rechazo("contenedor atómico dentro de una columna v3", v3_id, {"operation": "insert", "node_id": "col3001", "position": "inside", "elements": [caja(titulo("x"))]},
        "elements[0].type: una columna de Elementor 3 descarta en silencio los contenedores atómicos")
rechazo("insertar dentro de una sección v3", v3_id, {"operation": "insert", "node_id": "sec3001", "position": "inside", "elements": [titulo("x")]},
        "elements[0].type: una sección de Elementor 3 sólo admite columnas")
rechazo("dentro de un widget", v3_id, {"operation": "insert", "node_id": "bt3000", "position": "inside", "elements": [titulo("x")]},
        "elements[0].type: un widget no admite hijos")
rechazo("borrar la última raíz", una_id, {"operation": "delete", "node_id": "sec6000"},
        "Ese es el único elemento de la raíz")
rechazo("subárbol inválido (propiedad desconocida)", v3_id, {"operation": "replace", "node_id": "sec3001",
        "elements": [caja({"type": "e-heading", "settings": {"titel": prop("escaped-html", "x")}})]},
        "elements[0].elements[0].settings.titel: la propiedad «titel» no existe en «e-heading».")
rechazo("tipo vedado (e-svg)", v3_id, {"operation": "replace", "node_id": "sec3001", "elements": [caja({"type": "e-svg"})]},
        "elements[0].elements[0].type: el tipo «e-svg» no está permitido")
rechazo("nodo inexistente", v3_id, {"operation": "delete", "node_id": "no-existe"},
        "El elemento indicado no existe en esta página.")
rechazo("delete con elements", v3_id, {"operation": "delete", "node_id": "sec3001", "elements": [caja(titulo("x"))]},
        "delete no recibe «elements»")

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de edit-elementor-section OK.")
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "la prueba de edit-elementor-section tuvo comprobaciones fallidas"

echo
echo "### Migración v3 → atómico por MCP (migrate-elementor-page)"
migrar_titulo="Prueba migrar v3 elementor-mcp-$suffix"
migrar_id="$(wp post create --post_type=page --post_status=publish --post_title="$migrar_titulo" --porcelain)"
[ -n "$migrar_id" ] || { echo "FALLA: no se pudo crear la página de prueba de migración"; exit 1; }
migrar_tree="$(python3 -I - "$suffix" "$media_id" "$version_elementor" <<'PY'
import json, sys
suffix, media, _ = sys.argv[1], int(sys.argv[2]), sys.argv[3]
def w(id_, tipo, ajustes):
    return {"id": id_, "elType": "widget", "widgetType": tipo, "settings": ajustes, "elements": []}
caja4 = lambda: {"unit": "px", "top": "20", "right": "20", "bottom": "20", "left": "20", "isLinked": True}
arbol = [
    {"id": "sec7000", "elType": "section", "settings": {"background_background": "classic", "background_color": "#f5f5f5", "padding": caja4()}, "elements": [
        {"id": "col7000", "elType": "column", "settings": {"_column_size": 50, "_inline_size": 50}, "elements": [
            w("hd7000", "heading", {"title": f"Migrar titulo {suffix}", "header_size": "h1", "align": "center", "title_color": "#112233",
                                     "typography_typography": "custom", "typography_font_size": {"unit": "px", "size": 32}, "typography_font_size_tablet": {"unit": "px", "size": 24}}),
            w("tx7000", "text-editor", {"editor": f"<p>Primer parrafo plano {suffix}</p><p>Segundo parrafo plano</p>"}),
            w("tx7001", "text-editor", {"editor": "<p>Texto con <strong>negrita</strong> y formato</p>"}),
            w("bt7000", "button", {"text": f"Boton migrado {suffix}", "link": {"url": "https://example.com/migrar", "is_external": "", "nofollow": ""}, "button_text_color": "#ffffff"}),
        ]},
        {"id": "col7001", "elType": "column", "settings": {"_column_size": 50, "_inline_size": 50}, "elements": [
            w("im7000", "image", {"image": {"id": media, "url": ""}, "image_size": "full"}),
            w("dv7000", "divider", {"style": "solid", "weight": {"unit": "px", "size": 2}, "color": "#cccccc"}),
            w("sp7000", "spacer", {"space": {"unit": "px", "size": 40}}),
        ]},
    ]},
    {"id": "cn7000", "elType": "container", "settings": {"container_type": "flex", "flex_direction": "row", "flex_justify_content": "center", "flex_align_items": "center", "flex_gap": {"unit": "px", "column": "12", "row": "12", "isLinked": True}}, "elements": [
        w("hd7001", "heading", {"title": f"Heading en contenedor flex {suffix}", "header_size": "h3",
                                 "__globals__": {"title_color": "globals/colors?id=primary", "typography_typography": "globals/typography?id=primary"}}),
    ]},
]
print(json.dumps(arbol))
PY
)"
wp post meta update "$migrar_id" _elementor_edit_mode builder >/dev/null
wp post meta update "$migrar_id" _elementor_version "$version_elementor" >/dev/null
wp post meta update "$migrar_id" _elementor_data "$migrar_tree" >/dev/null
echo "Página v3 publicada: $migrar_id (imagen $media_id)"

python3 -I - "$suffix" "$migrar_id" "$secciones_atomica_id" "$media_id" <<'PY'
import importlib.util, json, pathlib, subprocess, sys, urllib.request

suffix, pagina, atomica_id, media = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def crudo(i):
    return script("--meta", i, "_elementor_data")

def arbol(i):
    return json.loads(crudo(i))

def indice(nodos, salida=None):
    salida = {} if salida is None else salida
    for n in nodos:
        salida[n["id"]] = n
        indice(n.get("elements", []), salida)
    return salida

def tipo(n):
    return n.get("widgetType") or n["elType"]

orig = crudo(pagina)
orig_arbol = json.loads(orig)
orig_idx = indice(orig_arbol)
res = {}
copias_antes = script("--contar-paginas")

def migrar():
    _, d = cli.ok("migrate-elementor-page", {"id": pagina})
    res.update(d)
    assert set(d) == {"id", "draft_of", "preview_url", "migrated", "kept_v3", "unmapped"}, sorted(d)
    assert d["draft_of"] == pagina and d["id"] != pagina, d
    assert d["preview_url"], d
    assert d["migrated"] == 10, f"migrated={d['migrated']}"
    assert int(script("--contar-paginas")) == int(copias_antes) + 1, "debía nacer una sola copia"

def original_intacto():
    assert crudo(pagina) == orig, "el original cambió"
    assert script("--estado", pagina) == "publish" and script("--estado", res["id"]) == "draft"

def copia_atomica():
    copia = arbol(res["id"])
    idx = indice(copia)
    por_tipo = {}
    for n in idx.values():
        por_tipo.setdefault(tipo(n), []).append(n)
    assert [tipo(n) for n in copia] == ["e-flexbox", "e-flexbox"], [tipo(n) for n in copia]
    assert len(por_tipo["e-div-block"]) == 2, por_tipo.keys()
    assert len(por_tipo["e-heading"]) == 2 and len(por_tipo["e-paragraph"]) == 2, {k: len(v) for k, v in por_tipo.items()}
    assert len(por_tipo["e-button"]) == 1 and len(por_tipo["e-image"]) == 1 and len(por_tipo["e-divider"]) == 1
    assert "heading" not in por_tipo and "button" not in por_tipo and "section" not in por_tipo and "column" not in por_tipo
    titulo = next(n for n in por_tipo["e-heading"] if f"Migrar titulo {suffix}" in json.dumps(n["settings"]))
    assert titulo["settings"]["tag"]["value"] == "h1", titulo["settings"]
    estilos = [v for st in titulo["styles"].values() for v in st["variants"]]
    props = {k for v in estilos for k in v["props"]}
    assert {"color", "font-size", "text-align"} <= props, props
    assert {v["meta"]["breakpoint"] for v in estilos} >= {"desktop", "tablet"}, estilos
    imagen = por_tipo["e-image"][0]["settings"]["image"]["value"]["src"]["value"]
    assert imagen["id"]["value"] == media, imagen
    glob = next(n for n in por_tipo["e-heading"] if "contenedor flex" in json.dumps(n["settings"]))
    gprops = {k for st in glob["styles"].values() for v in st["variants"] for k in v["props"]}
    assert "color" in gprops, f"el color global no se trasladó: {gprops}"
    flex = copia[1]
    fprops = {k for st in flex["styles"].values() for v in st["variants"] for k in v["props"]}
    assert {"flex-direction", "justify-content", "align-items", "gap"} <= fprops, fprops

def kept_v3():
    ids = sorted(k["id"] for k in res["kept_v3"])
    assert ids == ["sp7000", "tx7001"], res["kept_v3"]
    assert all(set(k) == {"id", "type", "reason"} and k["reason"] for k in res["kept_v3"]), res["kept_v3"]
    copia = indice(arbol(res["id"]))
    for i in ids:
        assert copia[i] == orig_idx[i], f"el nodo v3 conservado {i} cambió"
    assert tipo(copia["sp7000"]) == "spacer" and tipo(copia["tx7001"]) == "text-editor"

def elementor_carga():
    copia = arbol(res["id"])
    esperado = []
    def rec(nodos):
        for n in nodos:
            esperado.append(tipo(n))
            rec(n.get("elements", []))
    rec(copia)
    cargado = json.loads(script("--documento", res["id"]))
    assert cargado == esperado, f"Elementor cargó {cargado} y se guardó {esperado}"
    assert len(cargado) == len(indice(copia)) and "spacer" in cargado and "e-heading" in cargado, cargado

def copia_renderiza():
    html = script("--render", res["id"])
    for t in (f"Migrar titulo {suffix}", f"Primer parrafo plano {suffix}", f"Boton migrado {suffix}", f"Heading en contenedor flex {suffix}", "negrita"):
        assert t in html, f"la copia no renderiza «{t}»"
    assert "e-heading" in html or "e-con" in html or "e-flexbox" in html, "el render no trae elementos atómicos"

def diff_y_publicar():
    _, d = cli.ok("get-working-copy-diff", {"id": pagina})
    assert d["copy_id"] == res["id"] and any(e["key"] == "_elementor_data" for e in d["meta"]["changed"]), d
    datos = crudo(res["id"])
    _, m = cli.ok("set-content-status", {"id": res["id"], "status": "publish"})
    assert (m["id"], m["merged_from"]) == (pagina, res["id"]), m
    assert json.loads(crudo(pagina)) == json.loads(datos), "el original no trae el árbol migrado"
    with urllib.request.urlopen(f"http://localhost:8080/?page_id={pagina}") as r:
        cuerpo = r.read().decode("utf-8", "replace")
        assert r.status == 200, r.status
    assert f"Migrar titulo {suffix}" in cuerpo and f"Boton migrado {suffix}" in cuerpo, "el frontend no muestra el contenido migrado"

def rechazo(nombre, id_, esperado, antes_fn=None, despues_fn=None):
    def fn():
        datos, cuenta = crudo(id_), script("--contar-paginas")
        if antes_fn:
            antes_fn()
        try:
            es_error, msg, _ = cli.call("migrate-elementor-page", {"id": id_})
        finally:
            if despues_fn:
                despues_fn()
        assert es_error and msg.startswith(esperado), f"mensaje: {msg[:300]!r}"
        assert crudo(id_) == datos, "cambió el original"
        assert script("--contar-paginas") == cuenta, "cambió la cantidad de páginas (¿se creó una copia?)"
    comprobar(f"rechazo: {nombre}", fn)

comprobar("migrar: va a la copia con la respuesta acordada", migrar)
comprobar("migrar: el original publicado no cambia", original_intacto)
comprobar("migrar: la copia es atómica donde corresponde (tipos, estilos, imagen, global, flex)", copia_atomica)
comprobar("migrar: kept_v3 lista el spacer y el text-editor con formato, idénticos al original", kept_v3)
comprobar("migrar: Elementor carga el árbol guardado (Document::get_elements_data)", elementor_carga)
comprobar("migrar: la copia renderiza el contenido", copia_renderiza)
comprobar("migrar: diff, publicar (fusión) y el frontend responde 200 con el texto", diff_y_publicar)
rechazo("una página atómica no tiene nada que migrar", atomica_id, "La página no tiene elementos de Elementor 3 que se puedan convertir")
rechazo("la página ya migrada no tiene nada que convertir", pagina, "La página no tiene elementos de Elementor 3 que se puedan convertir")
rechazo("sin elementos atómicos activos", pagina, "Los elementos atómicos de Elementor 4 no están activos.",
        antes_fn=lambda: script("--experimentos", "inactive"), despues_fn=lambda: script("--experimentos", "active"))

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de migrate-elementor-page OK.")
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "la prueba de migrate-elementor-page tuvo comprobaciones fallidas"
echo
echo "### Datos dinámicos por MCP (list-dynamic-tags, set-dynamic-tag; F6, sólo con Elementor Pro)"
# Los dynamic tags son de Elementor Pro (CI no lo tiene): sin Pro se saltea con aviso y no falla. Con Pro se enciende
# builders_dynamic_enabled sólo para este bloque; nexo_mcp_settings se restaura completo en la limpieza (también al cortar).
version_pro="$(php_wpr 'echo defined( "ELEMENTOR_PRO_VERSION" ) ? ELEMENTOR_PRO_VERSION : "";')"
if [ -z "$version_pro" ]; then
	echo "SKIP: Elementor Pro no está activo; la prueba de datos dinámicos (F6) se saltea (no cuenta como falla)."
else
	echo "Elementor Pro $version_pro activo."
	dinamicos_titulo="Prueba dinamicos elementor-mcp-$suffix"
	dinamicos_id="$(wp post create --post_type=page --post_status=publish --post_title="$dinamicos_titulo" --porcelain)"
	[ -n "$dinamicos_id" ] || { echo "FALLA: no se pudo crear la página de prueba de datos dinámicos"; exit 1; }
	wp post meta update "$dinamicos_id" _thumbnail_id "$media_id" >/dev/null
	guardado="$(php_wp '
$id = (int) getenv( "NEXO_A1" );
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$texto = function ( $t ) { return array( "\$\$type" => "escaped-html", "value" => $t ); };
$imagen = array( "\$\$type" => "image", "value" => array( "src" => array( "\$\$type" => "image-src", "value" => array( "id" => array( "\$\$type" => "image-attachment-id", "value" => (int) getenv( "NEXO_A2" ) ), "url" => null ) ), "size" => array( "\$\$type" => "string", "value" => "full" ) ) );
$nodo = function ( $id, $tipo, $ajustes ) { return array( "id" => $id, "elType" => "widget", "widgetType" => $tipo, "styles" => array(), "elements" => array(), "settings" => $ajustes ); };
$arbol = array(
	array( "id" => "fx9000", "elType" => "e-flexbox", "styles" => array(), "settings" => array(), "elements" => array(
		$nodo( "eh9000", "e-heading", array( "title" => $texto( "Titulo dinamico base" ) ) ),
		$nodo( "eh9001", "e-heading", array( "title" => $texto( "Sitio base" ) ) ),
		$nodo( "eh9002", "e-heading", array( "title" => $texto( "Saneado base" ) ) ),
		$nodo( "ei9000", "e-image", array( "image" => $imagen ) ),
		$nodo( "ei9001", "e-image", array( "image" => $imagen ) ),
	) ),
	array( "id" => "fx9001", "elType" => "e-flexbox", "styles" => array(), "settings" => array(), "elements" => array(
		$nodo( "eh9003", "e-heading", array( "title" => $texto( "Otra seccion base" ) ) ),
	) ),
);
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' "$dinamicos_id" "$media_id")"
	[ "$guardado" = guardado ] || { echo "FALLA: no se pudo guardar la página de datos dinámicos ($guardado)"; exit 1; }
	nombre_sitio="$(wpr option get blogname)"
	archivo_medio="$(wpr post get "$media_id" --field=guid)"
	echo "Página publicada: $dinamicos_id (imagen $media_id)"
	# La capacidad nexo_mcp_builders_dynamic se concede al activar el plugin: un sitio que sólo se actualizó (como el
	# docker local) no la tiene. Se concede aquí, para que el interruptor sea lo único que decide, y se quita al final.
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_builders_dynamic" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_builders_dynamic" );' >/dev/null
		dinamicos_cap_agregada=1
		echo "Capacidad nexo_mcp_builders_dynamic concedida al administrador (se quita al final)."
	fi
	tools/prueba_adaptadores.sh --ajuste builders_dynamic_enabled false

	python3 -I - "$suffix" "$dinamicos_id" "$dinamicos_titulo" "$nombre_sitio" "$archivo_medio" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, urllib.request

suffix, pagina, titulo_pagina, nombre_sitio, archivo_medio = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def crudo(i):
    return script("--meta", i, "_elementor_data")

def arbol(i):
    return json.loads(crudo(i))

def indice(nodos, salida=None):
    salida = {} if salida is None else salida
    for n in nodos:
        salida[n["id"]] = n
        indice(n.get("elements", []), salida)
    return salida

def render(i):
    return script("--vista-previa", i)

def encabezados(html):
    """Texto de cada <h1>-<h6> atómico (e-heading-base) de la vista previa."""
    return [re.sub(r"<[^>]+>", "", t) for t in re.findall(r"<h[1-6][^>]*e-heading-base[^>]*>(.*?)</h[1-6]>", html, re.S)]

def dinamica(nombre, grupo, ajustes=None):
    return {"$$type": "dynamic", "value": {"name": nombre, "group": grupo, "settings": ajustes if ajustes is not None else []}}

def asignar(nodo, prop, tag, **extra):
    return cli.call("set-dynamic-tag", {"id": pagina, "node_id": nodo, "prop": prop, "tag": tag, **extra})

orig = crudo(pagina)
res = {}
lista = {}
antes_y_despues = ("ANTES-", "-DESPUES")

def listar():
    _, d = cli.ok("list-dynamic-tags", {})
    assert isinstance(d, dict) and isinstance(d.get("result"), list) and d["result"], d
    lista.update({t["name"]: t for t in d["result"]})
    for n in ("post-title", "site-title", "site-tagline", "post-featured-image", "site-logo", "post-url"):
        assert n in lista, f"falta {n}: {sorted(lista)}"
    for n in ("shortcode", "request-arg", "user-info", "post-custom-field", "author-meta"):
        assert n not in lista, f"{n} no puede estar en la lista"
    for t in lista.values():
        assert set(t) == {"name", "group", "label", "categories", "settings", "targets"}, sorted(t)
        assert t["label"] != t["name"] and re.fullmatch(r"[^<>\n]+", t["label"]) and re.search(r"[A-Za-zÁ-ú]{3}", t["label"]), f"etiqueta sin texto real: {t}"
    assert lista["post-title"]["categories"] == ["text"] and "e-heading.title" in lista["post-title"]["targets"], lista["post-title"]
    assert lista["post-featured-image"]["categories"] == ["image"] and lista["post-featured-image"]["targets"] == ["e-image.image"], lista["post-featured-image"]
    print("  etiquetas:", {n: lista[n]["label"] for n in ("post-title", "site-title", "post-featured-image", "site-logo")})

def interruptor_apagado():
    global cli
    cuenta = script("--contar-paginas")
    es_error, msg, _ = asignar("eh9000", "title", {"name": "post-title"})
    assert es_error, msg
    assert crudo(pagina) == orig, "cambió el original"
    assert script("--contar-paginas") == cuenta, "nació una copia con el interruptor apagado"
    print("  mensaje:", msg[:200])
    script("--ajuste", "builders_dynamic_enabled", "true")
    assert script("--ajuste-valor", "builders_dynamic_enabled") == "true"
    # El scope mcp:builders:dynamic sólo se ofrece con el interruptor encendido: la conexión nueva lo trae.
    cli = mod.Cliente("http://localhost:8080", "admin", "admin")

def asignar_post_title():
    cuenta = script("--contar-paginas")
    es_error, msg, d = asignar("eh9000", "title", {"name": "post-title", "settings": {"before": antes_y_despues[0], "after": antes_y_despues[1]}})
    assert not es_error, msg
    res.update(d)
    assert d["draft_of"] == pagina and d["id"] != pagina and d["node_id"] == "eh9000" and d["prop"] == "title", d
    assert d["tag"]["name"] == "post-title" and d["tag"]["group"] == "post", d
    assert d["tag"]["settings"] == {"before": "ANTES-", "after": "-DESPUES"}, d["tag"]
    assert int(script("--contar-paginas")) == int(cuenta) + 1, "debía nacer una sola copia"
    assert crudo(pagina) == orig, "el original cambió"
    assert script("--estado", pagina) == "publish" and script("--estado", d["id"]) == "draft"
    guardado = indice(arbol(d["id"]))["eh9000"]["settings"]["title"]
    assert guardado == dinamica("post-title", "post", {"before": "ANTES-", "after": "-DESPUES"}), guardado

def render_post_title():
    # La prueba real de L9: save_tree sin los analizadores de Elementor y el render sí resuelve el tag.
    html = render(res["id"])
    textos = encabezados(html)
    m = next((re.fullmatch(r"ANTES-(.*)-DESPUES", t.strip(), re.S) for t in textos if t.strip().startswith("ANTES-")), None)
    assert m, f"la vista previa no resuelve post-title con before/after: {textos}"
    assert m.group(1) == titulo_pagina and "Titulo dinamico base" not in textos, textos
    print("  vista previa:", textos)

def asignar_site_title():
    es_error, msg, d = asignar("eh9001", "title", {"name": "site-title"})
    assert not es_error, msg
    assert d["id"] == res["id"], "debía reutilizar la copia"
    html = render(res["id"])
    textos = [t.strip() for t in encabezados(html)]
    assert nombre_sitio in textos and "Sitio base" not in textos, f"la vista previa no muestra el título del sitio «{nombre_sitio}»: {textos}"
    assert crudo(pagina) == orig, "el original cambió"

def imagen_destacada():
    es_error, msg, d = asignar("ei9000", "image", {"name": "post-featured-image"})
    assert not es_error, msg
    assert indice(arbol(res["id"]))["ei9000"]["settings"]["image"] == dinamica("post-featured-image", "post"), d
    html = render(res["id"])
    nombre = re.sub(r"\.\w+$", "", archivo_medio.rsplit("/", 1)[-1])
    assert "<img" in html and nombre in html, f"el render no muestra la imagen destacada «{nombre}»"

def logo_del_sitio():
    es_error, msg, d = asignar("ei9001", "image", {"name": "site-logo"})
    assert not es_error, msg
    assert indice(arbol(res["id"]))["ei9001"]["settings"]["image"] == dinamica("site-logo", "site"), d
    assert d["tag"] == dinamica("site-logo", "site")["value"], d["tag"]

def html_saneado():
    es_error, msg, d = asignar("eh9002", "title", {"name": "post-title", "settings": {"before": "<b>X</b><script>alert(1)</script>Y-"}})
    assert not es_error, msg
    guardado = indice(arbol(res["id"]))["eh9002"]["settings"]["title"]["value"]["settings"]["before"]
    assert "<" not in guardado and ">" not in guardado and "<" not in d["tag"]["settings"]["before"], (guardado, d["tag"])
    textos = encabezados(render(res["id"]))
    assert any(t.strip() == "XY-" + titulo_pagina for t in textos), f"la vista previa no muestra el texto saneado: {textos}"
    assert "alert(1)" not in "".join(textos), "la vista previa trae el script"
    print("  guardado:", repr(guardado))

def rechazo(nombre, nodo, prop, tag, codigo):
    def fn():
        datos, cuenta = crudo(res["id"]), script("--contar-paginas")
        es_error, msg, _ = asignar(nodo, prop, tag)
        assert es_error and codigo in msg, f"mensaje: {msg[:300]!r} (se esperaba «{codigo}»)"
        assert crudo(res["id"]) == datos, "cambió la copia"
        assert crudo(pagina) == orig, "cambió el original"
        assert script("--contar-paginas") == cuenta, "cambió la cantidad de páginas"
    comprobar(f"rechazo: {nombre}", fn)

def edit_otra_seccion():
    antes = indice(arbol(res["id"]))
    prop_antes = antes["eh9000"]["settings"]["title"]
    _, d = cli.ok("edit-elementor-section", {"id": pagina, "operation": "replace", "node_id": "fx9001", "elements": [
        {"type": "e-flexbox", "elements": [{"type": "e-heading", "settings": {"title": {"$$type": "escaped-html", "value": f"Seccion nueva {suffix}"}}}]}]})
    assert d["id"] == res["id"], "debía reutilizar la copia"
    despues = indice(arbol(d["id"]))
    assert despues["eh9000"]["settings"]["title"] == prop_antes, "la edición de otra sección tocó la prop dinámica"
    assert "eh9003" not in despues and f"Seccion nueva {suffix}" in render(d["id"])
    assert any(re.fullmatch(r"ANTES-.*-DESPUES", t.strip(), re.S) for t in encabezados(render(d["id"]))), "se perdió la resolución del tag"

def edit_conserva_en_subarbol():
    antes = arbol(res["id"])
    prop_antes = indice(antes)["eh9000"]["settings"]["title"]
    _, d = cli.ok("edit-elementor-section", {"id": pagina, "operation": "replace", "node_id": "fx9000", "elements": [
        {"type": "e-flexbox", "elements": [{"id": "eh9000", "type": "e-heading", "settings": {"title": prop_antes}}]}]})
    nuevo = indice(arbol(d["id"]))[d["node_ids"][1]]
    assert nuevo["widgetType"] == "e-heading" and nuevo["settings"]["title"] == prop_antes, nuevo
    assert any(re.fullmatch(r"ANTES-.*-DESPUES", t.strip(), re.S) for t in encabezados(render(d["id"]))), "la vista previa no resuelve el tag conservado"
    es_error, msg, _ = cli.call("edit-elementor-section", {"id": pagina, "operation": "replace", "node_id": d["node_ids"][0], "elements": [
        {"type": "e-flexbox", "elements": [{"type": "e-heading", "settings": {"title": dinamica("site-title", "site")}}]}]})
    assert es_error, f"una prop dinámica nueva por edit-section debía rechazarse: {msg[:300]}"
    print("  rechazo:", msg[:200])
    assert True or "x", f"una prop dinámica nueva por edit-section debía rechazarse: {msg[:300]}"

def diff_publicar_y_frontend():
    _, d = cli.ok("get-working-copy-diff", {"id": pagina})
    assert d["copy_id"] == res["id"] and any(e["key"] == "_elementor_data" for e in d["meta"]["changed"]), d
    datos = crudo(res["id"])
    _, m = cli.ok("set-content-status", {"id": res["id"], "status": "publish"})
    assert (m["id"], m["merged_from"]) == (pagina, res["id"]), m
    assert json.loads(crudo(pagina)) == json.loads(datos), "el original no trae el árbol de la copia"
    with urllib.request.urlopen(f"http://localhost:8080/?page_id={pagina}") as r:
        cuerpo = r.read().decode("utf-8", "replace")
        assert r.status == 200, r.status
    textos = [t.strip() for t in encabezados(cuerpo)]
    assert f"ANTES-{titulo_pagina}-DESPUES" in textos, f"el frontend público no resuelve el tag: {textos}"

comprobar("list-dynamic-tags: tags del catálogo presentes, ninguno sensible, etiquetas reales", listar)
comprobar("set-dynamic-tag: con el interruptor apagado se deniega y no crea copia", interruptor_apagado)
comprobar("set-dynamic-tag: post-title con before/after va a la copia; el original no cambia", asignar_post_title)
comprobar("render: la copia resuelve ANTES-<título>-DESPUES (save_tree sin parsers de Elementor)", render_post_title)
comprobar("set-dynamic-tag: site-title se asigna y se renderiza", asignar_site_title)
comprobar("set-dynamic-tag: e-image.image con post-featured-image se guarda y se renderiza", imagen_destacada)
comprobar("set-dynamic-tag: e-image.image con site-logo se guarda y se devuelve normalizado", logo_del_sitio)
comprobar("set-dynamic-tag: el HTML de un ajuste se guarda sin etiquetas", html_saneado)
rechazo("tag fuera del catálogo (shortcode)", "eh9003", "title", {"name": "shortcode"}, "«shortcode» no está permitido")
rechazo("categoría que no coincide (imagen en un título)", "eh9003", "title", {"name": "post-featured-image"}, "es de categoría «image» y «e-heading.title» necesita la categoría «text»")
rechazo("categoría que no coincide (texto en una imagen)", "ei9000", "image", {"name": "post-title"}, "es de categoría «text» y «e-image.image» necesita la categoría «image»")
rechazo("ajuste fuera del catálogo", "eh9003", "title", {"name": "post-title", "settings": {"custom_field": "x"}}, "el ajuste «custom_field» no existe en «post-title»")
rechazo("prop que no admite datos dinámicos", "eh9003", "tag", {"name": "post-title"}, "«e-heading.tag» no admite datos dinámicos")
comprobar("edit-elementor-section sobre otra sección conserva la prop dinámica", edit_otra_seccion)
comprobar("edit-elementor-section: el nodo reenviado con su id conserva la prop; una nueva se rechaza", edit_conserva_en_subarbol)
comprobar("diff, publicar (fusión) y el frontend público resuelve el tag", diff_publicar_y_frontend)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de datos dinámicos (F6) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de datos dinámicos (F6) tuvo comprobaciones fallidas"
	tools/prueba_adaptadores.sh --ajuste builders_dynamic_enabled false
fi
echo
echo "### Componentes por MCP (list-components, create-component, insert-component; F7, sólo con Elementor Pro con licencia)"
# Los componentes exigen Pro con licencia activa (CI no lo tiene): sin eso se saltea con aviso y no falla. Con Pro se
# enciende builders_components_enabled (y la capacidad, si falta) sólo para este bloque; nexo_mcp_settings se restaura
# completo en la limpieza (también al cortar), que además borra (force) los componentes del sufijo.
componentes_listos="$(php_wpr '$c = "\\Elementor\\Modules\\Components\\Components_Access_Controller"; echo post_type_exists( "elementor_component" ) && class_exists( $c ) && $c::can_create() && $c::can_add_to_page() ? "si" : "no";')"
if [ "$componentes_listos" != si ]; then
	echo "SKIP: Elementor Pro con licencia activa y el módulo de componentes no están disponibles; la prueba de componentes (F7) se saltea (no cuenta como falla)."
else
	echo "Elementor Pro ${version_pro:-?} con licencia activa y módulo de componentes presentes."
	componentes_titulo="Prueba componentes elementor-mcp-$suffix"
	componentes_id="$(wp post create --post_type=page --post_status=publish --post_title="$componentes_titulo" --porcelain)"
	[ -n "$componentes_id" ] || { echo "FALLA: no se pudo crear la página de prueba de componentes"; exit 1; }
	guardado="$(php_wp '
$id = (int) getenv( "NEXO_A1" );
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$texto = function ( $t ) { return array( "\$\$type" => "escaped-html", "value" => $t ); };
$arbol = array(
	array( "id" => "fx9500", "elType" => "e-flexbox", "styles" => array(), "settings" => array(), "elements" => array(
		array( "id" => "eh9500", "elType" => "widget", "widgetType" => "e-heading", "styles" => array(), "elements" => array(), "settings" => array( "title" => $texto( "Base componentes" ) ) ),
	) ),
);
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' "$componentes_id")"
	[ "$guardado" = guardado ] || { echo "FALLA: no se pudo guardar la página de componentes ($guardado)"; exit 1; }
	echo "Página publicada: $componentes_id"
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_builders_components" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_builders_components" );' >/dev/null
		componentes_cap_agregada=1
		echo "Capacidad nexo_mcp_builders_components concedida al administrador (se quita al final)."
	fi
	tools/prueba_adaptadores.sh --ajuste builders_components_enabled false

	python3 -I - "$suffix" "$componentes_id" "$componentes_titulo" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, urllib.request

suffix, pagina, titulo_pagina = sys.argv[1], int(sys.argv[2]), sys.argv[3]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def crudo(i):
    return script("--meta", i, "_elementor_data")

def arbol(i):
    return json.loads(crudo(i))

def indice(nodos, salida=None):
    salida = {} if salida is None else salida
    for n in nodos:
        salida[n["id"]] = n
        indice(n.get("elements", []), salida)
    return salida

def encabezados(html):
    return [re.sub(r"<[^>]+>", "", t).strip() for t in re.findall(r"<h[1-6][^>]*e-heading-base[^>]*>(.*?)</h[1-6]>", html, re.S)]

def frontend(i):
    with urllib.request.urlopen(f"http://localhost:8080/?page_id={i}") as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def prop(tipo, valor):
    return {"$$type": tipo, "value": valor}

def componentes():
    _, d = cli.ok("list-components", {})
    assert isinstance(d, dict) and isinstance(d.get("result"), list), d
    return d["result"]

def propio(lista, titulo):
    return [c for c in lista if c["title"] == titulo]

titulo_comp = f"Componente F7 d'prueba elementor-mcp-{suffix}"
texto_comp = f"Texto del componente {suffix}"
orig = crudo(pagina)
comp = {}
res = {}

def caja(*hijos):
    return [{"type": "e-flexbox", "elements": list(hijos)}]

def encabezado(texto):
    return {"type": "e-heading", "settings": {"title": prop("escaped-html", texto)}}

def interruptor_apagado():
    global cli
    antes = len(componentes())
    es_error, msg, _ = cli.call("create-component", {"title": titulo_comp, "elements": caja(encabezado(texto_comp))})
    assert es_error, msg
    assert len(componentes()) == antes, "se creó un componente con el interruptor apagado"
    script("--ajuste", "builders_components_enabled", "true")
    assert script("--ajuste-valor", "builders_components_enabled") == "true"
    # El scope mcp:builders:components sólo se ofrece con el interruptor encendido: la conexión nueva lo trae.
    cli = mod.Cliente("http://localhost:8080", "admin", "admin")

def crear():
    antes = len(componentes())
    _, d = cli.ok("create-component", {"title": titulo_comp, "elements": caja(encabezado(texto_comp))})
    comp.update(d)
    assert set(d) == {"id", "uid", "title", "status"}, d
    assert d["title"] == titulo_comp and d["status"] == "draft" and re.fullmatch(r"nexo-[a-z0-9]{12}", d["uid"]), d
    assert script("--estado", d["id"]) == "draft"
    assert len(componentes()) == antes + 1

def listar():
    filas = propio(componentes(), titulo_comp)
    assert len(filas) == 1, filas
    f = filas[0]
    assert f["id"] == comp["id"] and f["uid"] == comp["uid"] and f["status"] == "draft", f
    assert set(f) == {"id", "uid", "title", "status", "modified"}, sorted(f)

def insertar():
    cuenta = script("--contar-paginas")
    _, d = cli.ok("insert-component", {"id": pagina, "node_id": "fx9500", "position": "inside", "component_id": comp["id"]})
    res.update(d)
    assert d["draft_of"] == pagina and d["id"] != pagina and len(d["node_ids"]) == 1, d
    assert int(script("--contar-paginas")) == int(cuenta) + 1, "debía nacer una sola copia"
    assert crudo(pagina) == orig, "el original cambió"
    assert script("--estado", pagina) == "publish" and script("--estado", d["id"]) == "draft"
    nodo = indice(arbol(d["id"]))[d["node_ids"][0]]
    assert nodo["elType"] == "widget" and nodo["widgetType"] == "e-component", nodo
    esperado = prop("component-instance", {"component_id": prop("number", comp["id"])})
    assert nodo["settings"] == {"component_instance": esperado}, nodo["settings"]

def publicar_y_frontend():
    assert texto_comp not in encabezados(frontend(pagina)), "el original ya mostraba el componente antes de publicar"
    _, m = cli.ok("set-content-status", {"id": res["id"], "status": "publish"})
    assert (m["id"], m["merged_from"]) == (pagina, res["id"]), m
    assert any(n.get("widgetType") == "e-component" for n in indice(arbol(pagina)).values())
    textos = encabezados(frontend(pagina))
    assert texto_comp in textos and "Base componentes" in textos, f"el frontend no renderiza el componente: {textos}"

def sin_escribir(nombre, llamar, esperado_en_msg, ver_sin_entidad=False):
    def fn():
        n_comp, n_pag, datos = len(componentes()), script("--contar-paginas"), crudo(pagina)
        es_error, msg, _ = llamar()
        assert es_error, "debía rechazarse"
        for e in esperado_en_msg:
            assert e in msg, f"falta «{e}» en: {msg[:300]!r}"
        if ver_sin_entidad:
            assert "&#039;" not in msg and "&amp;" not in msg, f"entidades HTML sin decodificar: {msg[:300]!r}"
        assert len(componentes()) == n_comp, "cambió la cantidad de componentes"
        assert script("--contar-paginas") == n_pag, "cambió la cantidad de páginas (¿copia?)"
        assert crudo(pagina) == datos, "cambió el original"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

comprobar("create-component: con el interruptor apagado se deniega y no crea nada", interruptor_apagado)
comprobar("create-component: árbol atómico → borrador con uid propio", crear)
comprobar("list-components: lo muestra con uid, título y borrador, sin árbol", listar)
comprobar("insert-component: la copia lleva la instancia e-component exacta; el original no cambia", insertar)
comprobar("publicar la copia: el frontend renderiza el componente", publicar_y_frontend)
sin_escribir("widget v3 (heading)", lambda: cli.call("create-component", {"title": f"V3 elementor-mcp-{suffix}", "elements": caja({"type": "heading", "settings": {"title": "x"}})}), ["«heading» es un elemento de Elementor 3"])
sin_escribir("título duplicado (mensaje sin entidades HTML)", lambda: cli.call("create-component", {"title": titulo_comp, "elements": caja(encabezado("otro"))}), ["duplicado", "d'prueba"], True)
sin_escribir("component_id inexistente", lambda: cli.call("insert-component", {"id": pagina, "node_id": "fx9500", "position": "inside", "component_id": 99999999}), ["no existe, está archivado"])
sin_escribir("edit-elementor-section con un e-component", lambda: cli.call("edit-elementor-section", {"id": pagina, "operation": "insert", "node_id": "fx9500", "position": "inside", "elements": caja({"type": "e-component"})}), ["e-component", "no está permitido"])

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de componentes (F7) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de componentes (F7) tuvo comprobaciones fallidas"
	tools/prueba_adaptadores.sh --ajuste builders_components_enabled false
fi
echo
echo "### Divi 5 por MCP (create-divi-page, get-page-structure, edit-divi-module, edit-divi-section; sólo con el tema Divi 5)"
# Sin Divi 5 activo (CI no lo tiene) se saltea con aviso y no falla. Si el tema Divi 5 está instalado pero no activo, se
# activa sólo para este bloque y la limpieza (también al cortar) devuelve el tema previo.
divi_listo="$(php_wpr 'echo defined( "ET_BUILDER_5_DIR" ) ? "si" : "no";')"
if [ "$divi_listo" != si ] && [ "$(php_wpr 'echo is_dir( get_theme_root() . "/Divi/includes/builder-5" ) ? "si" : "no";')" = si ]; then
	divi_tema_previo="$(wpr option get stylesheet)"
	if [ -n "$divi_tema_previo" ] && wp theme activate Divi >/dev/null; then
		echo "Tema Divi 5 activado para el bloque (tema previo: $divi_tema_previo)."
		divi_listo="$(php_wpr 'echo defined( "ET_BUILDER_5_DIR" ) ? "si" : "no";')"
	else
		divi_tema_previo=""
	fi
fi
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba de Divi 5 se saltea (no cuenta como falla)."
else
	echo "Divi 5 activo: $(php_wpr 'echo defined( "ET_BUILDER_VERSION" ) ? ET_BUILDER_VERSION : "?";')."
	python3 -I - "$suffix" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def contenido(i):
    return script("--contenido", i)

def frontend(i):
    with urllib.request.urlopen(f"http://localhost:8080/?page_id={i}") as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def huellas(i):
    _, d = cli.ok("get-page-structure", {"id": i})
    assert d["builder"] == "divi5", d.get("builder")
    return d["nodes"]

def por_ruta(nodos):
    return {n["id"]: n for n in nodos}

def valor(texto):
    return {"desktop": {"value": texto}}

def modulo(tipo, **attrs):
    return {"type": tipo, "attrs": attrs}

def seccion(*modulos):
    return {"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": list(modulos)}]}]}

def texto(t):
    return modulo("divi/text", content={"innerContent": valor(f"<p>{t}</p>")})

def parche_texto(t):
    return {"content": {"innerContent": valor(t)}}

T_ORIG, T_EDIT = f"Texto original {suffix}", f"Texto editado {suffix}"
S_ORIG, S_NUEVA = f"Segunda original {suffix}", f"Segunda reconstruida {suffix}"
H_ORIG, H_NUEVO = f"Titulo original {suffix}", f"Titulo reconstruido {suffix}"
B_TEXTO = f"Boton original {suffix}"
titulo = f"Prueba divi5 divi5-mcp-{suffix}"
est = {}

def crear():
    n0 = int(script("--contar-paginas"))
    secciones = [
        seccion(
            texto(T_ORIG),
            modulo("divi/heading", title={"innerContent": valor(H_ORIG)}),
            modulo("divi/button", button={"innerContent": valor({"text": B_TEXTO, "linkUrl": "https://example.com/original"})}),
            modulo("divi/image", image={"innerContent": valor({"src": "https://example.com/a.png", "alt": "A"})}),
        ),
        seccion(texto(S_ORIG)),
    ]
    _, d = cli.ok("create-divi-page", {"title": titulo, "sections": secciones})
    est["pagina"] = d["id"]
    assert d["status"] == "draft" and script("--estado", d["id"]) == "draft", d
    assert [n["path"] for n in d["nodes"]][:2] == ["0", "0.0"] and "0.0.0.0.3" in [n["path"] for n in d["nodes"]] and "0.1" in [n["path"] for n in d["nodes"]], d["nodes"]
    assert all(re.fullmatch(r"[0-9a-f]{64}", n["fingerprint"]) for n in d["nodes"]), d["nodes"]
    crudo = contenido(d["id"])
    assert "<!-- wp:divi/placeholder" in crudo and "<!-- wp:divi/text " in crudo and "<!-- wp:divi/image " in crudo, crudo[:300]
    assert script("--meta", d["id"], "_et_pb_use_divi_5") == "on" and script("--meta", d["id"], "_et_pb_use_builder") == "on"
    assert int(script("--contar-paginas")) == n0 + 1
    _, p = cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    assert script("--estado", d["id"]) == "publish", p
    assert T_ORIG in frontend(d["id"]) and S_ORIG in frontend(d["id"])

def leer():
    nodos = por_ruta(huellas(est["pagina"]))
    tipos = {n["type"] for n in nodos.values()}
    assert {"divi/placeholder", "divi/section", "divi/row", "divi/column", "divi/text", "divi/heading", "divi/button", "divi/image"} <= tipos, tipos
    assert all(re.fullmatch(r"[0-9a-f]{64}", n["attributes"]["fingerprint"]) for n in nodos.values()), "fingerprint"
    assert nodos["0.0.0.0.0"]["type"] == "divi/text" and nodos["0.0.0.0.0"]["text"] == T_ORIG, nodos["0.0.0.0.0"]
    assert nodos["0.0.0.0.1"]["type"] == "divi/heading" and nodos["0.0.0.0.1"]["text"] == H_ORIG
    assert nodos["0.0.0.0.2"]["type"] == "divi/button" and nodos["0.1.0.0.0"]["text"] == S_ORIG
    est["huella_vieja"] = nodos["0.0.0.0.0"]["attributes"]["fingerprint"]
    est["original"] = contenido(est["pagina"])
    est["paginas"] = int(script("--contar-paginas"))

def editar_modulo():
    p = est["pagina"]
    nodos = por_ruta(huellas(p))
    _, d = cli.ok("edit-divi-module", {"id": p, "path": "0.0.0.0.0", "fingerprint": nodos["0.0.0.0.0"]["attributes"]["fingerprint"], "content": parche_texto(f"<p>{T_EDIT}</p>")})
    assert d["draft_of"] == p and d["id"] != p and d["path"] == "0.0.0.0.0", d
    est["copia"] = d["id"]
    assert contenido(p) == est["original"], "el original cambió"
    assert script("--estado", p) == "publish" and script("--estado", d["id"]) == "draft"
    assert int(script("--contar-paginas")) == est["paginas"] + 1, "debía nacer una sola copia"
    assert T_EDIT in contenido(d["id"]) and T_ORIG not in contenido(d["id"]) and S_ORIG in contenido(d["id"])
    releida = por_ruta(huellas(d["id"]))["0.0.0.0.0"]
    assert releida["text"] == T_EDIT and releida["attributes"]["fingerprint"] == d["fingerprint"], (releida, d["fingerprint"])

def reemplazar_seccion():
    p, copia = est["pagina"], est["copia"]
    nodos = por_ruta(huellas(copia))
    assert nodos["0.1"]["type"] == "divi/section"
    nueva = seccion(texto(S_NUEVA), modulo("divi/heading", title={"innerContent": valor(H_NUEVO)}))
    _, d = cli.ok("edit-divi-section", {"id": p, "operation": "replace", "path": "0.1", "fingerprint": nodos["0.1"]["attributes"]["fingerprint"], "sections": [nueva]})
    assert d["id"] == copia and d["draft_of"] == p, d
    assert contenido(p) == est["original"], "el original cambió"
    assert int(script("--contar-paginas")) == est["paginas"] + 1, "no debía nacer otra copia"
    crudo = contenido(copia)
    assert S_NUEVA in crudo and H_NUEVO in crudo and S_ORIG not in crudo and T_EDIT in crudo, "la copia no tiene lo reconstruido"
    assert [n["path"] for n in d["nodes"]][:1] == ["0.1"] or "0.1" in [n["path"] for n in d["nodes"]], d["nodes"]

def publicar_y_frontend():
    p, copia = est["pagina"], est["copia"]
    assert T_EDIT not in frontend(p), "el original ya mostraba el texto editado antes de publicar"
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (p, copia), m
    assert int(script("--contar-paginas")) == est["paginas"], "la copia no se borró al publicar"
    html = frontend(p)
    for esperado in (T_EDIT, S_NUEVA, H_NUEVO, B_TEXTO):
        assert esperado in html, f"el frontend no muestra «{esperado}»"
    for viejo in (T_ORIG, S_ORIG):
        assert viejo not in html, f"el frontend sigue mostrando «{viejo}»"
    assert "wp:divi/" not in html and "<!-- wp:" not in html, "comentarios de bloque crudos en el frontend"
    est["publicado"] = contenido(p)

def sin_escribir(nombre, llamar, esperado_en_msg):
    def fn():
        n_pag, datos = script("--contar-paginas"), contenido(est["pagina"])
        es_error, msg, _ = llamar()
        assert es_error, "debía rechazarse"
        for e in esperado_en_msg:
            assert e in msg, f"falta «{e}» en: {msg[:300]!r}"
        assert script("--contar-paginas") == n_pag, "cambió la cantidad de páginas (¿copia?)"
        assert contenido(est["pagina"]) == datos == est["publicado"], "cambió el original"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

comprobar("create-divi-page: sección>fila>columna>texto+título+botón+imagen; borrador con bloques y metas; se publica", crear)
comprobar("get-page-structure: nodos divi5 con path, tipo, texto y fingerprint", leer)
comprobar("edit-divi-module: el texto va a la copia de trabajo; el original no cambia", editar_modulo)
comprobar("edit-divi-section: reemplaza una sección en la misma copia; el original no cambia", reemplazar_seccion)
comprobar("publicar la copia: el frontend muestra los textos nuevos y ningún comentario de bloque crudo", publicar_y_frontend)

nodos_pub = por_ruta(huellas(est["pagina"])) if not fallas else {}
def huella(ruta):
    return nodos_pub[ruta]["attributes"]["fingerprint"]

if nodos_pub:
    p = est["pagina"]
    sin_escribir("módulo divi/code (nexo_mcp_divi_module_denied)", lambda: cli.call("edit-divi-section", {"id": p, "operation": "insert", "path": "0.0.0.0", "position": "inside", "fingerprint": huella("0.0.0.0"), "sections": [{"type": "divi/code"}]}), ["divi/code", "no está en el catálogo"])
    sin_escribir("shortcode en un texto (nexo_mcp_divi_shortcode_denied)", lambda: cli.call("edit-divi-module", {"id": p, "path": "0.0.0.0.0", "fingerprint": huella("0.0.0.0.0"), "content": parche_texto('<p>[gallery ids="1"]</p>')}), ["shortcode", "[gallery]"])
    sin_escribir("enlace javascript: (nexo_mcp_divi_value_invalid)", lambda: cli.call("edit-divi-module", {"id": p, "path": "0.0.0.0.2", "fingerprint": huella("0.0.0.0.2"), "content": {"button": {"innerContent": valor({"text": "x", "linkUrl": "javascript:alert(1)"})}}}), ["linkUrl", "http, https, mailto, tel"])
    sin_escribir("fingerprint viejo (nexo_mcp_divi_node_changed)", lambda: cli.call("edit-divi-module", {"id": p, "path": "0.0.0.0.0", "fingerprint": est["huella_vieja"], "content": parche_texto("<p>x</p>")}), ["la huella no coincide", "no se escribió nada"])
    sin_escribir("divi/placeholder raíz (nexo_mcp_divi_operation_invalid)", lambda: cli.call("edit-divi-section", {"id": p, "operation": "replace", "path": "0", "fingerprint": huella("0"), "sections": [seccion(texto("x"))]}), ["divi/placeholder", "No se escribió nada"])

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de Divi 5 OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de Divi 5 tuvo comprobaciones fallidas"
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
fi
echo
echo "### Diseño global de Divi 5 (D3: get-divi-design-system, set-divi-global-color, delete-divi-global-color, set-divi-variable, archive-divi-variable, set-divi-typography, undo-divi-design-change)"
# Mismo criterio que 7e: sin Divi 5 activo se saltea con aviso y no falla (la limpieza devuelve el tema previo).
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba del diseño global de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto cruda de lo que se toca (la limpieza la devuelve también si la corrida se corta).
	tools/prueba_adaptadores.sh --divi-diseno-foto
	divi_diseno_foto_tomada=1
	divi_huella_inicial="$(tools/prueba_adaptadores.sh --divi-diseno-huella)"
	echo "Huella cruda inicial: $divi_huella_inicial"
	# La capacidad nexo_mcp_divi_design la da el instalador; un sitio que sólo se actualizó no la tiene: se concede y se quita.
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_design" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_design" );' >/dev/null
		divi_diseno_cap_agregada=1
		echo "Capacidad nexo_mcp_divi_design concedida al administrador (se quita al final)."
	fi
	tools/prueba_adaptadores.sh --ajuste divi_design_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, time, urllib.parse, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=180).stdout.strip()

def huella():
    return script("--divi-diseno-huella")

def descargar(url):
    with urllib.request.urlopen(url, timeout=20) as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def css_de(i):
    """HTML de la página más las hojas de et-cache que enlaza (donde Divi deja las variables --gcid-… y --et_global_…)."""
    base = f"http://localhost:8080/?page_id={i}"
    html = descargar(base)
    partes = [html]
    for href in re.findall(r"""href=["']([^"']*et-cache[^"']*\.css[^"']*)["']""", html):
        partes.append(descargar(urllib.parse.urljoin(base, href)))
    return "\n".join(partes)

def css_con(i, patron, presente=True):
    """El CSS se regenera en la primera visita tras el flush: se reintenta unos segundos."""
    ultimo = ""
    for _ in range(6):
        ultimo = css_de(i)
        if bool(re.search(patron, ultimo)) == presente:
            return ultimo
        time.sleep(1)
    raise AssertionError(f"el CSS del frontend {'no trae' if presente else 'sigue trayendo'} /{patron}/")

def estado():
    _, d = cli.ok("get-divi-design-system", {})
    return d

def color(d, i):
    return next((c for c in d["colors"] if c["id"] == i), None)

def variable(d, tipo, i):
    return next((v for v in d["variables"][tipo] if v["id"] == i), None)

def valor(texto):
    return {"desktop": {"value": texto}}

st = {}
titulo = f"Prueba diseno divi5-mcp-{suffix}"

def leer_diseno():
    d = estado()
    assert set(d) >= {"colors", "variables", "customizer_colors", "typography", "history_count"}, list(d)
    assert list(d["variables"]) == ["numbers", "strings", "images", "links", "fonts", "gradients"], list(d["variables"])
    assert list(d["customizer_colors"]) == ["accent", "secondary_accent", "header", "font", "link"]
    assert set(d["typography"]) == {"heading_font", "body_font", "heading_font_weight", "body_font_weight", "body_font_size", "body_line_height"}
    st["base"] = d
    st["historial0"] = d["history_count"]
    st["colores0"] = len(d["colors"])
    st["tam0"] = d["typography"]["body_font_size"]

def crear_color():
    _, d = cli.ok("set-divi-global-color", {"label": f"Prueba divi-diseno-mcp {suffix}", "color": "#ff0000"})
    assert re.fullmatch(r"gcid-[a-z0-9]{10}", d["id"]) and d["created"] is True and d["undo_available"] is True, d
    st["color"] = d["id"]
    g = estado()
    c = color(g, d["id"])
    assert c and c["color"] == "#ff0000" and c["status"] == "active", c
    assert len(g["colors"]) == st["colores0"] + 1 and g["history_count"] == st["historial0"] + 1

def pagina_con_color():
    ref = "var(--" + st["color"] + ")"
    texto = {"type": "divi/text", "attrs": {"content": {"innerContent": valor(f"<p>Color global {suffix}</p>")}, "module": {"decoration": {"background": valor({"color": ref})}}}}
    seccion = {"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [texto]}]}]}
    _, d = cli.ok("create-divi-page", {"title": titulo, "sections": [seccion]})
    st["pagina"] = d["id"]
    assert st["color"] in script("--contenido", d["id"]), "la página no lleva el color global"
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    assert script("--estado", d["id"]) == "publish"
    css_con(d["id"], r"--" + re.escape(st["color"]) + r"\s*:")

def cambiar_color():
    time.sleep(1.2)  # Divi marca el CSS como vacío con el segundo entero: una hoja del mismo segundo se toma por vigente
    _, d = cli.ok("set-divi-global-color", {"id": st["color"], "label": f"Prueba divi-diseno-mcp {suffix}", "color": "#00ff00"})
    assert d["created"] is False and d["id"] == st["color"], d
    assert color(estado(), st["color"])["color"] == "#00ff00"
    css_con(st["pagina"], r"--" + re.escape(st["color"]) + r"\s*:\s*#00ff00")

def borrar_en_uso():
    antes = huella()
    es_error, texto, datos = cli.call("delete-divi-global-color", {"id": st["color"]})
    assert es_error and "está en uso" in texto, texto[:300]  # nexo_mcp_divi_color_in_use
    assert str(st["pagina"]) in texto, "no lista la entrada que lo usa"
    assert color(estado(), st["color"]) is not None, "el color se borró"
    assert huella() == antes, "cambió lo guardado"

def variable_y_archivo():
    _, d = cli.ok("set-divi-variable", {"type": "numbers", "label": f"Espacio divi-diseno-mcp {suffix}", "value": "24px"})
    assert re.fullmatch(r"gvid-[a-z0-9]{10}", d["id"]) and d["created"] is True, d
    st["variable"] = d["id"]
    v = variable(estado(), "numbers", d["id"])
    assert v and v["value"] == "24px" and v["status"] == "active", v
    _, a = cli.ok("archive-divi-variable", {"type": "numbers", "id": d["id"], "archived": True})
    assert a["status"] == "archived" and a["changed"] is True, a
    assert variable(estado(), "numbers", d["id"])["status"] == "archived"

def tipografia():
    time.sleep(1.2)  # Divi marca el CSS como vacío con el segundo entero: una hoja del mismo segundo se toma por vigente
    nuevo = "21" if st["tam0"] == "19" else "19"
    st["tam_nuevo"] = nuevo
    st["css0"] = css_de(st["pagina"])
    _, d = cli.ok("set-divi-typography", {"body_font_size": int(nuevo)})
    assert d["changed"] == {"body_font_size": nuevo} and d["undo_available"] is True, d
    assert estado()["typography"]["body_font_size"] == nuevo
    css_con(st["pagina"], r"--et_global_body_font_size:\s*" + nuevo + r"px")

def deshacer_todo():
    time.sleep(1.2)  # Divi marca el CSS como vacío con el segundo entero: una hoja del mismo segundo se toma por vigente
    n = st["historial0"]
    # Orden inverso: tipografía, archivo, variable, cambio de color, color creado.
    _, u = cli.ok("undo-divi-design-change", {})
    assert u["area"] == "theme_options" and u["tool"] == "set-divi-typography", u
    assert estado()["typography"]["body_font_size"] == st["tam0"]
    css_con(st["pagina"], r"--et_global_body_font_size:\s*" + re.escape(st["tam0"] or "14") + r"px")
    _, u = cli.ok("undo-divi-design-change", {})
    assert u["area"] == "variables:numbers" and u["tool"] == "archive-divi-variable", u
    assert variable(estado(), "numbers", st["variable"])["status"] == "active"
    _, u = cli.ok("undo-divi-design-change", {})
    assert u["area"] == "variables:numbers" and u["tool"] == "set-divi-variable", u
    # Lo creado después se archiva, no se borra (Divi no admite borrar).
    assert variable(estado(), "numbers", st["variable"])["status"] == "archived"
    _, u = cli.ok("undo-divi-design-change", {})
    assert u["area"] == "colors" and color(estado(), st["color"])["color"] == "#ff0000", u
    _, u = cli.ok("undo-divi-design-change", {})
    assert u["area"] == "colors" and u["remaining"] == n, u
    g = estado()
    assert color(g, st["color"]) is None and len(g["colors"]) == st["colores0"] and g["history_count"] == n, g["history_count"]
    css_con(st["pagina"], r"--" + re.escape(st["color"]) + r"\s*:", presente=False)

def sin_nada_que_deshacer():
    if st["historial0"] != 0:
        return
    antes = huella()
    es_error, texto, _ = cli.call("undo-divi-design-change", {})
    assert es_error and "No hay ningún cambio" in texto, texto[:300]  # nexo_mcp_divi_design_nothing_to_undo
    assert huella() == antes

def sin_escribir(nombre, llamar, esperado):
    def fn():
        antes = huella()
        es_error, texto, _ = llamar()
        assert es_error, "debía rechazarse"
        assert esperado in texto, f"falta «{esperado}» en: {texto[:300]!r}"
        assert huella() == antes, "cambió lo guardado"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

comprobar("get-divi-design-system: colores, variables por tipo, Customizer, tipografía y cantidad de cambios", leer_diseno)
comprobar("set-divi-global-color: crea un gcid-… y get-divi-design-system lo muestra", crear_color)
comprobar("create-divi-page con el color global: publicada, el CSS del frontend define --gcid-…", pagina_con_color)
comprobar("set-divi-global-color: cambia el color y el CSS del frontend lo refleja", cambiar_color)
comprobar("delete-divi-global-color sin force: en uso → nexo_mcp_divi_color_in_use y no escribe nada", borrar_en_uso)
comprobar("set-divi-variable + archive-divi-variable: la variable se crea y se archiva", variable_y_archivo)
comprobar("set-divi-typography: el tamaño del cuerpo se ve en el CSS del frontend", tipografia)
comprobar("undo-divi-design-change: cada cambio vuelve atrás en orden inverso (tipografía, archivo, variable, color)", deshacer_todo)
comprobar("undo-divi-design-change sin historial: nexo_mcp_divi_design_nothing_to_undo y no escribe", sin_nada_que_deshacer)
sin_escribir("color inválido (nexo_mcp_divi_design_invalid)", lambda: cli.call("set-divi-global-color", {"label": "x", "color": "rojo"}), "color: debe ser un color")
sin_escribir("variable de enlace javascript: (nexo_mcp_divi_design_invalid)", lambda: cli.call("set-divi-variable", {"type": "links", "label": "x", "value": "javascript:alert(1)"}), "value: debe ser un enlace")
sin_escribir("valor con salto de línea final (nexo_mcp_divi_design_invalid)", lambda: cli.call("set-divi-variable", {"type": "numbers", "label": "x", "value": "12px\n"}), "value: debe ser un número")
sin_escribir("color con salto de línea final (nexo_mcp_divi_design_invalid)", lambda: cli.call("set-divi-global-color", {"label": "x", "color": "#ff0000\n"}), "color: debe ser un color")
sin_escribir("id de color inexistente (nexo_mcp_divi_design_not_found)", lambda: cli.call("delete-divi-global-color", {"id": "gcid-zzzzzzzzzz"}), "No existe «gcid-zzzzzzzzzz»")
sin_escribir("id de variable inexistente (nexo_mcp_divi_design_not_found)", lambda: cli.call("archive-divi-variable", {"type": "numbers", "id": "gvid-zzzzzzzzzz", "archived": True}), "No existe «gvid-zzzzzzzzzz»")
sin_escribir("tamaño del cuerpo fuera de rango (nexo_mcp_divi_design_invalid)", lambda: cli.call("set-divi-typography", {"body_font_size": 500}), "body_font_size: debe ser un tamaño en px")

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones del diseño global de Divi 5 (D3) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba del diseño global de Divi 5 (D3) tuvo comprobaciones fallidas"
	tools/prueba_adaptadores.sh --ajuste divi_design_tools_enabled false
	# El deshacer deja los 5 colores del Customizer que Divi agregó a et_divi: se devuelven las opciones crudas.
	echo "Diseño global de Divi: $(restaurar_divi_diseno)"
	divi_diseno_foto_tomada=""
	check_eq "Divi D3: lo guardado quedó byte a byte como al empezar (et_divi, variables, historial, ajustes)" "$divi_huella_inicial" "$(tools/prueba_adaptadores.sh --divi-diseno-huella)"
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
	if [ -n "$divi_diseno_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_divi_design" );' >/dev/null
		divi_diseno_cap_agregada=""
	fi
fi
echo
echo "### Presets de módulos de Divi 5 (D4: get-divi-presets, set-divi-preset, set-divi-default-preset, delete-divi-preset, preset en edit-divi-module, undo-divi-design-change)"
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba de los presets de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto cruda de lo que se toca (la limpieza la devuelve también si la corrida se corta).
	tools/prueba_adaptadores.sh --divi-presets-foto
	divi_presets_foto_tomada=1
	divi_presets_huella_inicial="$(tools/prueba_adaptadores.sh --divi-presets-huella)"
	echo "Huella cruda inicial: $divi_presets_huella_inicial"
	# La capacidad nexo_mcp_divi_presets la da el instalador al subir de versión; si falta se concede y se quita.
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_presets" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_presets" );' >/dev/null
		divi_presets_cap_agregada=1
		echo "Capacidad nexo_mcp_divi_presets concedida al administrador (se quita al final)."
	fi
	tools/prueba_adaptadores.sh --ajuste divi_presets_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, time, urllib.parse, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
BG = "module.decoration.background"

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=180).stdout.strip()

def huella():
    return script("--divi-presets-huella")

def descargar(url):
    with urllib.request.urlopen(url, timeout=20) as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def css_de(i):
    """HTML de la página más las hojas de et-cache que enlaza (donde Divi deja las reglas de las clases preset--…)."""
    base = f"http://localhost:8080/?page_id={i}"
    html = descargar(base)
    partes = [html]
    for href in re.findall(r"""href=["']([^"']*et-cache[^"']*\.css[^"']*)["']""", html):
        partes.append(descargar(urllib.parse.urljoin(base, href)))
    return "\n".join(partes)

def css_con(i, patron, presente=True):
    """El CSS se regenera en la primera visita tras el flush: se reintenta unos segundos."""
    ultimo = ""
    for _ in range(6):
        ultimo = css_de(i)
        hallado = re.search(patron, ultimo)
        if bool(hallado) == presente:
            return ultimo
        time.sleep(1)
    raise AssertionError(f"el CSS del frontend {'no trae' if presente else 'sigue trayendo'} /{patron}/" + ("" if presente else f": «{hallado.group(0)[:200]}»"))

def leer(arg=None):
    _, d = cli.ok("get-divi-presets", arg or {})
    return d

def estructura(i):
    _, d = cli.ok("get-page-structure", {"id": i})
    assert d["builder"] == "divi5", d.get("builder")
    return {n["id"]: n for n in d["nodes"]}

def valor(v):
    return {"desktop": {"value": v}}

def fondo(color):
    return {"module": {"decoration": {"background": valor({"color": color})}}}

st = {}
titulo = f"Prueba presets divi5-mcp-{suffix}"
T = f"Texto presets {suffix}"

def leer_presets():
    d = leer()
    assert set(d) >= {"modules", "groups", "available_groups", "history_count"}, list(d)
    texto = next((m for m in d["available_groups"] if m["module"] == "divi/text"), None)
    assert texto and BG in [g["id"] for g in texto["groups"]], d["available_groups"]
    st["historial0"] = d["history_count"]
    st["modulos0"] = {m["module"]: len(m["presets"]) for m in d["modules"]}

def crear_preset_modulo():
    _, d = cli.ok("set-divi-preset", {"kind": "module", "name": "divi/text", "label": f"Preset divi5-mcp-{suffix}", "attrs": fondo("#ff0000")})
    assert re.fullmatch(r"[0-9a-f-]{36}", d["id"]) and d["created"] is True and d["area"] == "presets" and d["undo_available"] is True, d
    st["preset"] = d["id"]
    g = leer({"module": "divi/text"})
    p = next((x for x in next(m for m in g["modules"] if m["module"] == "divi/text")["presets"] if x["id"] == d["id"]), None)
    assert p and p["name"] == f"Preset divi5-mcp-{suffix}" and p["attrs"] == fondo("#ff0000"), p
    assert g["history_count"] == st["historial0"] + 1

def crear_preset_grupo():
    _, d = cli.ok("set-divi-preset", {"kind": "group", "name": "divi/background", "module": "divi/text", "group_id": BG, "label": f"Grupo divi5-mcp-{suffix}", "attrs": fondo("#00ff00")})
    assert d["created"] is True and d["undo_available"] is True, d
    st["grupo"] = d["id"]
    g = leer({"module": "divi/text"})
    assert any(x["id"] == d["id"] and x["group_id"] == BG for gr in g["groups"] for x in gr["presets"]), g["groups"]
    assert g["history_count"] == st["historial0"] + 2

def pagina_base():
    seccion = {"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [{"type": "divi/text", "attrs": {"content": {"innerContent": valor(f"<p>{T}</p>")}}}]}]}]}
    _, d = cli.ok("create-divi-page", {"title": titulo, "sections": [seccion]})
    st["pagina"] = d["id"]
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    assert script("--estado", d["id"]) == "publish"
    assert T in descargar(f"http://localhost:8080/?page_id={d['id']}")
    st["original"] = script("--contenido", d["id"])
    st["paginas"] = int(script("--contar-paginas"))
    assert "modulePreset" not in st["original"]

def aplicar():
    p = st["pagina"]
    nodo = estructura(p)["0.0.0.0.0"]
    assert "preset" not in nodo["attributes"], nodo["attributes"]
    _, d = cli.ok("edit-divi-module", {"id": p, "path": "0.0.0.0.0", "fingerprint": nodo["attributes"]["fingerprint"], "preset": {"module": st["preset"], "groups": {BG: st["grupo"]}}})
    assert d["draft_of"] == p and d["id"] != p and d["path"] == "0.0.0.0.0", d
    st["copia"] = d["id"]
    assert script("--contenido", p) == st["original"], "el original cambió"
    assert int(script("--contar-paginas")) == st["paginas"] + 1, "debía nacer una sola copia"
    crudo = script("--contenido", d["id"])
    assert f'"modulePreset":["{st["preset"]}"]' in crudo and st["grupo"] in crudo, crudo[:400]
    releido = estructura(d["id"])["0.0.0.0.0"]
    assert releido["attributes"]["preset"] == {"module": [st["preset"]], "groups": {BG: [st["grupo"]]}}, releido["attributes"]
    assert releido["attributes"]["fingerprint"] == d["fingerprint"]
    assert "preset" not in estructura(p)["0.0.0.0.0"]["attributes"], "el original no debía llevar el preset"

def publicar_y_css():
    p, copia = st["pagina"], st["copia"]
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (p, copia), m
    assert int(script("--contar-paginas")) == st["paginas"], "la copia no se borró al publicar"
    assert estructura(p)["0.0.0.0.0"]["attributes"]["preset"]["module"] == [st["preset"]]
    html = css_con(p, r"\.preset--module--divi-text--" + re.escape(st["preset"]) + r"[^{}<>\"]*\{[^}]*background-color:\s*#ff0000")
    assert "preset--module--divi-text--" + st["preset"] in html, "la clase del preset no está en el HTML"
    css_con(p, r"\.preset--group--divi-text--divi-background--" + re.escape(st["grupo"]) + r"[^{}<>\"]*\{[^}]*background-color:\s*#00ff00")

def predeterminado():
    _, d = cli.ok("set-divi-default-preset", {"kind": "module", "name": "divi/text", "id": st["preset"]})
    assert d["changed"] is True and d["undo_available"] is True, d
    m = next(m for m in leer()["modules"] if m["module"] == "divi/text")
    assert m["default"] == st["preset"], m
    assert leer()["history_count"] == st["historial0"] + 3
    _, otra = cli.ok("set-divi-default-preset", {"kind": "module", "name": "divi/text", "id": st["preset"]})
    assert otra["changed"] is False, otra  # mismo valor: no escribe

def borrar_en_uso():
    antes = huella()
    es_error, texto, datos = cli.call("delete-divi-preset", {"kind": "module", "name": "divi/text", "id": st["preset"]})
    assert es_error and "está en uso" in texto and str(st["pagina"]) in texto, texto[:300]  # nexo_mcp_divi_preset_in_use
    assert huella() == antes, "cambió lo guardado"
    assert any(x["id"] == st["preset"] for m in leer()["modules"] if m["module"] == "divi/text" for x in m["presets"]), "el preset se borró"

def deshacer():
    # Divi marca el CSS como vacío con el segundo entero (.cache-cleared-at): una hoja generada en el mismo segundo que
    # el vaciado se toma por vigente. Entre la visita que la generó y el deshacer pasa más de un segundo, como en un uso real.
    time.sleep(1.2)
    return cli.ok("undo-divi-design-change", {"area": "presets"})

def deshacer_todo():
    n = st["historial0"]
    # Orden inverso: predeterminado, grupo, módulo.
    _, u = deshacer()
    assert u["area"] == "presets" and u["tool"] == "set-divi-default-preset", u
    assert next(m for m in leer()["modules"] if m["module"] == "divi/text")["default"] in (None, ""), "el predeterminado sigue puesto"
    _, u = deshacer()
    assert u["area"] == "presets" and u["tool"] == "set-divi-preset", u
    assert not any(x["id"] == st["grupo"] for gr in leer()["groups"] for x in gr["presets"]), "el preset de grupo sigue"
    css_con(st["pagina"], r"\.preset--group--divi-text--divi-background--" + re.escape(st["grupo"]) + r"[^{}<>\"]*\{", presente=False)
    _, u = deshacer()
    assert u["area"] == "presets" and u["tool"] == "set-divi-preset" and u["remaining"] == n, u
    g = leer()
    assert g["history_count"] == n and not any(x["id"] == st["preset"] for m in g["modules"] for x in m["presets"]), g["history_count"]
    css_con(st["pagina"], r"\.preset--module--divi-text--" + re.escape(st["preset"]) + r"[^{}<>\"]*\{", presente=False)

def sin_escribir(nombre, llamar, esperado):
    def fn():
        antes, paginas, contenido = huella(), script("--contar-paginas"), script("--contenido", st["pagina"])
        es_error, texto, _ = llamar()
        assert es_error, "debía rechazarse"
        assert esperado in texto, f"falta «{esperado}» en: {texto[:300]!r}"
        assert huella() == antes, "cambió lo guardado"
        assert script("--contar-paginas") == paginas, "cambió la cantidad de páginas (¿copia?)"
        assert script("--contenido", st["pagina"]) == contenido, "cambió el original"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

def huella_nodo():
    return estructura(st["pagina"])["0.0.0.0.0"]["attributes"]["fingerprint"]

comprobar("get-divi-presets: presets, predeterminados, grupos disponibles de divi/text y cantidad de cambios", leer_presets)
comprobar("set-divi-preset: crea un preset de módulo (UUID) y get-divi-presets lo muestra con sus attrs", crear_preset_modulo)
comprobar("set-divi-preset: crea un preset de grupo (fondo) para divi/text", crear_preset_grupo)
comprobar("create-divi-page: página publicada con un texto sin preset", pagina_base)
comprobar("edit-divi-module con preset: la copia lleva modulePreset y groupPreset, get-page-structure los expone y el original no cambia", aplicar)
comprobar("publicar la copia: el CSS del frontend trae la regla de la clase del preset de módulo y del de grupo", publicar_y_css)
comprobar("set-divi-default-preset: fija el predeterminado del módulo y repetirlo no escribe", predeterminado)
comprobar("delete-divi-preset sin force: en uso → nexo_mcp_divi_preset_in_use y no escribe nada", borrar_en_uso)
comprobar("undo-divi-design-change {area: presets}: cada cambio vuelve atrás en orden inverso y la regla CSS desaparece", deshacer_todo)
if "pagina" in st:
    sin_escribir("preset inexistente en edit-divi-module (nexo_mcp_divi_preset_unknown)", lambda: cli.call("edit-divi-module", {"id": st["pagina"], "path": "0.0.0.0.0", "fingerprint": huella_nodo(), "preset": {"module": "no-existe"}}), "preset.module")
    sin_escribir("preset inexistente en un nodo de create-divi-page (nexo_mcp_divi_preset_unknown)", lambda: cli.call("create-divi-page", {"title": titulo + " x", "sections": [{"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [{"type": "divi/text", "preset": {"module": "no-existe"}}]}]}]}]}), "preset.module")
    sin_escribir("modulePreset crudo dentro de attrs (nexo_mcp_divi_attr_denied)", lambda: cli.call("create-divi-page", {"title": titulo + " y", "sections": [{"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [{"type": "divi/text", "attrs": {"modulePreset": ["x"]}}]}]}]}]}), "modulePreset")
    sin_escribir("attrs con manejador de eventos en un preset (nexo_mcp_divi_preset_invalid)", lambda: cli.call("set-divi-preset", {"kind": "module", "name": "divi/text", "label": "x", "attrs": {"attributes": {"onclick": "alert(1)"}}}), "attrs.attributes")
    sin_escribir("preset inexistente en delete-divi-preset (nexo_mcp_divi_preset_not_found)", lambda: cli.call("delete-divi-preset", {"kind": "module", "name": "divi/text", "id": "no-existe"}), "no-existe")

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de los presets de Divi 5 (D4) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de los presets de Divi 5 (D4) tuvo comprobaciones fallidas"
	tools/prueba_adaptadores.sh --ajuste divi_presets_tools_enabled false
	echo "Presets de Divi: $(restaurar_divi_presets)"
	divi_presets_foto_tomada=""
	check_eq "Divi D4: lo guardado quedó byte a byte como al empezar (presets, historial, ajustes, bloqueo)" "$divi_presets_huella_inicial" "$(tools/prueba_adaptadores.sh --divi-presets-huella)"
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
	if [ -n "$divi_presets_cap_agregada" ]; then
		php_wp 'get_role( "administrator" )->remove_cap( "nexo_mcp_divi_presets" );' >/dev/null
		divi_presets_cap_agregada=""
	fi
fi
echo
echo "### Contenido dinámico y bucles de Divi 5 (D5/D6: list-divi-dynamic-sources, set-divi-dynamic, set-divi-loop)"
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba del contenido dinámico y los bucles de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto cruda de lo que se toca (la limpieza la devuelve también si la corrida se corta).
	tools/prueba_adaptadores.sh --divi-dinamico-foto
	divi_dinamico_foto_tomada=1
	divi_dinamico_huella_inicial="$(tools/prueba_adaptadores.sh --divi-dinamico-huella)"
	echo "Huella cruda inicial: $divi_dinamico_huella_inicial"
	# La capacidad nexo_mcp_divi_dynamic la da el instalador al subir de versión; si falta se concede (los roles se devuelven crudos).
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_dynamic" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_dynamic" );' >/dev/null
		divi_dinamico_cap_agregada=1
		echo "Capacidad nexo_mcp_divi_dynamic concedida al administrador (los roles se devuelven crudos al final)."
	fi
	# Interruptor apagado por omisión: sólo durante el bloque (se enciende antes de conectar para que la lista de herramientas lo vea).
	tools/prueba_adaptadores.sh --ajuste divi_dynamic_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import html as htmllib, importlib.util, json, pathlib, re, subprocess, sys, time, urllib.parse, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
BASE = "http://localhost:8080"
TOKEN_TITULO = '$variable({"type":"content","value":{"name":"post_title","settings":{"enable_html":"off"}}})$'
TOKEN_LOOP = '$variable({"type":"content","value":{"name":"loop_post_title","settings":{"enable_html":"off"}}})$'

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=180).stdout.strip()

def huella():
    return script("--divi-dinamico-huella")

def anonimo(url):
    """GET sin cookies ni cabeceras de sesión: lo que ve un visitante."""
    with urllib.request.urlopen(url, timeout=30) as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def titulos(html):
    """Texto de cada título de módulo (h1-h6 con la clase et_pb_module_header)."""
    return [htmllib.unescape(re.sub(r"<[^>]+>", "", t)).strip() for t in re.findall(r"<h[1-6][^>]*et_pb_module_header[^>]*>(.*?)</h[1-6]>", html, re.S)]

def pagina_en(i, extra=""):
    return anonimo(f"{BASE}/?page_id={i}{extra}")

def valor(v):
    return {"desktop": {"value": v}}

def estructura(i):
    _, d = cli.ok("get-page-structure", {"id": i})
    assert d["builder"] == "divi5", d.get("builder")
    return {n["id"]: n for n in d["nodes"]}

def huella_nodo(i, ruta):
    return estructura(i)[ruta]["attributes"]["fingerprint"]

def crear_pagina(titulo, texto):
    seccion = {"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [{"type": "divi/heading", "attrs": {"title": {"innerContent": valor(texto)}}}]}]}]}
    _, d = cli.ok("create-divi-page", {"title": titulo, "sections": [seccion]})
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    assert script("--estado", d["id"]) == "publish"
    return d["id"]

def publicar(copia, original):
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (original, copia), m
    time.sleep(1.2)  # Divi regenera su CSS y sus cachés con la marca de un segundo: la primera visita llega después

def fx(nombre):
    return f"ZZD6 {nombre} divi5-mcp-{suffix}"

st = {}
HEADING = "0.0.0.0.0"
COLUMNA = "0.0.0.0"
T_DIN = f"Prueba dinamico divi5-mcp-{suffix}"
T_BUC = f"Prueba bucle divi5-mcp-{suffix}"

def preparar():
    st["fx"] = json.loads(script("--divi-dinamico-fixtures", suffix))
    assert set(st["fx"]) == {"Alpha", "Beta", "Gamma", "Delta", "PRIVATE", "PASSWORD", "DRAFT"} and all(isinstance(v, int) and v > 0 for v in st["fx"].values()), st["fx"]
    assert script("--estado", st["fx"]["PRIVATE"]) == "private" and script("--estado", st["fx"]["DRAFT"]) == "draft"
    st["din"] = crear_pagina(T_DIN, f"PLAIN_{suffix}")
    st["buc"] = crear_pagina(T_BUC, f"PLANTILLA_{suffix}")
    st["din0"], st["buc0"] = script("--contenido", st["din"]), script("--contenido", st["buc"])
    st["paginas"] = int(script("--contar-paginas"))
    assert f"PLAIN_{suffix}" in pagina_en(st["din"]) and "$variable(" not in st["din0"]
    st["huella0"] = huella()

def listar():
    _, d = cli.ok("list-divi-dynamic-sources", {})
    nombres = [s["name"] for s in d["sources"]]
    assert {"post_title", "post_excerpt", "post_date", "post_author", "post_link", "post_link_url", "post_featured_image", "site_title", "site_tagline", "site_logo", "home_url", "current_date"} <= set(nombres), nombres
    assert not [n for n in nombres if n.startswith("loop_") or n == "post_meta_key" or n.startswith("custom_meta__")], nombres
    assert "title.innerContent" in json.dumps(d["targets"]["divi/heading"]), d["targets"].get("divi/heading")
    assert {"loop_post_title", "loop_post_link", "loop_post_featured_image"} <= {s["name"] for s in d["loop_sources"]}, d["loop_sources"]
    assert all(s["category"] in ("text", "url", "image") for s in d["sources"] + d["loop_sources"])
    _, e = cli.ok("list-divi-dynamic-sources", {"post_id": st["din"]})
    assert e["post_id"] == st["din"] and e["post_type"] == "page", {k: e.get(k) for k in ("post_id", "post_type")}
    assert "post_categories" not in {s["name"] for s in e["sources"]}, "una página no tiene categorías"
    assert "product_price" not in {s["name"] for s in e["sources"]}, "una página no es un producto"

def asignar_titulo():
    p = st["din"]
    _, d = cli.ok("set-divi-dynamic", {"id": p, "path": HEADING, "fingerprint": huella_nodo(p, HEADING), "field": "title.innerContent", "source": {"name": "post_title"}})
    assert d["draft_of"] == p and d["id"] != p and d["path"] == HEADING and d["field"] == "title.innerContent", d
    assert d["token"] == TOKEN_TITULO, d["token"]
    st["copia_din"] = d["id"]
    assert script("--contenido", p) == st["din0"], "el original cambió"
    assert int(script("--contar-paginas")) == st["paginas"] + 1, "debía nacer una sola copia"
    assert "post_title" in script("--contenido", d["id"]) and f"PLAIN_{suffix}" not in script("--contenido", d["id"])
    assert f"PLAIN_{suffix}" in pagina_en(p), "el visitante aún debía ver el original"
    publicar(d["id"], p)
    st["paginas"] = int(script("--contar-paginas"))
    html = pagina_en(p)
    assert titulos(html) == [T_DIN], titulos(html)
    assert "$variable(" not in html and f"PLAIN_{suffix}" not in html, "quedó el token o el texto viejo"
    st["din1"] = script("--contenido", p)
    assert "post_title" in st["din1"] and "$variable(" in st["din1"], "el token no quedó guardado"

def sin_escribir(nombre, pagina, llamar, esperado, huella_comparar=True):
    def fn():
        antes, paginas, contenido = huella(), script("--contar-paginas"), script("--contenido", pagina)
        es_error, texto, _ = llamar()
        assert es_error, "debía rechazarse"
        for e in ([esperado] if isinstance(esperado, str) else esperado):
            assert e in texto, f"falta «{e}» en: {texto[:400]!r}"
        assert huella() == antes, "cambió lo guardado (ajustes, bloqueo o roles)"
        assert script("--contar-paginas") == paginas, "cambió la cantidad de páginas (¿copia?)"
        assert script("--contenido", pagina) == contenido, "cambió el original"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

def dinamico(pagina, fuente, campo="title.innerContent", ruta=HEADING):
    return lambda: cli.call("set-divi-dynamic", {"id": pagina, "path": ruta, "fingerprint": huella_nodo(pagina, ruta), "field": campo, "source": fuente})

def interruptor_apagado():
    p = st["din"]
    fp = huella_nodo(p, HEADING)
    script("--ajuste", "divi_dynamic_tools_enabled", "false")
    try:
        antes, paginas = huella(), script("--contar-paginas")
        es_error, texto, _ = cli.call("set-divi-dynamic", {"id": p, "path": HEADING, "fingerprint": fp, "field": "title.innerContent", "source": {"name": "site_title"}})
        assert es_error, "con el interruptor apagado debía rechazarse"
        assert huella() == antes and script("--contar-paginas") == paginas and script("--contenido", p) == st["din1"], "cambió algo"
        es_error, texto, _ = cli.call("set-divi-loop", {"id": p, "path": COLUMNA, "fingerprint": huella_nodo(p, COLUMNA), "loop": {"post_type": "post", "per_page": 3, "order_by": "date", "order": "desc"}})
        assert es_error and script("--contenido", p) == st["din1"], "set-divi-loop con el interruptor apagado debía rechazarse"
        _, listado = cli.ok("list-divi-dynamic-sources", {})  # leer el catálogo no pide el interruptor
        assert listado["sources"]
    finally:
        script("--ajuste", "divi_dynamic_tools_enabled", "true")

def preparar_bucle():
    p = st["buc"]
    _, d = cli.ok("set-divi-loop", {"id": p, "path": COLUMNA, "fingerprint": huella_nodo(p, COLUMNA), "loop": {"post_type": "post", "per_page": 3, "order_by": "date", "order": "desc"}})
    assert d["draft_of"] == p and d["id"] != p and d["path"] == COLUMNA and d["loop"]["per_page"] == 3, d
    assert re.fullmatch(r"loop-nx[0-9a-z]{8}", d["loop"]["loop_id"]), d["loop"]
    st["loop_id"], st["copia_buc"] = d["loop"]["loop_id"], d["id"]
    assert script("--contenido", p) == st["buc0"], "el original cambió"
    crudo = script("--contenido", d["id"])
    assert '"queryType":"post_types"' in crudo and '"ignoreStickysPost":"on"' in crudo and st["loop_id"] in crudo and "metaQuery" not in crudo, crudo[:600]
    c = st["copia_buc"]
    _, t = cli.ok("set-divi-dynamic", {"id": c, "path": HEADING, "fingerprint": huella_nodo(c, HEADING), "field": "title.innerContent", "source": {"name": "loop_post_title"}})
    assert t["token"] == TOKEN_LOOP and t["id"] == c, t
    publicar(c, p)
    st["paginas"] = int(script("--contar-paginas"))

def visitante_ve_tres():
    html = pagina_en(st["buc"])
    esperado = [fx("Delta"), fx("Gamma"), fx("Beta")]
    assert titulos(html) == esperado, titulos(html)
    for oculto in ("PRIVATE", "PASSWORD", "DRAFT"):
        assert fx(oculto) not in html, f"el visitante ve {oculto}"
    assert "Protegido" not in html and "$variable(" not in html and f"PLANTILLA_{suffix}" not in html
    # Paginación por URL del bucle: la página 2 sigue con las más antiguas.
    pagina2 = titulos(pagina_en(st["buc"], f"&{st['loop_id']}=2"))
    assert pagina2 and pagina2[0] == fx("Alpha") and fx("Delta") not in pagina2, pagina2

def visitante_ve_todas():
    p = st["buc"]
    _, d = cli.ok("set-divi-loop", {"id": p, "path": COLUMNA, "fingerprint": huella_nodo(p, COLUMNA), "loop": {"post_type": "post", "per_page": 24, "order_by": "date", "order": "desc"}})
    assert d["loop"]["loop_id"] == st["loop_id"] and d["loop"]["per_page"] == 24, d["loop"]  # actualizar conserva el loopId
    publicar(d["id"], p)
    visibles = int(script("--divi-dinamico-visibles"))
    html = pagina_en(p)
    lista = titulos(html)
    assert len(lista) == visibles and visibles >= 4, (len(lista), visibles)
    for nombre in ("Alpha", "Beta", "Gamma", "Delta"):
        assert lista.count(fx(nombre)) == 1, (nombre, lista)
    for oculto in ("PRIVATE", "PASSWORD", "DRAFT"):
        assert fx(oculto) not in html, f"el visitante ve {oculto}"
    assert "Protegido" not in html

def guarda_de_render():
    p = st["buc"]
    base = len(titulos(pagina_en(p)))
    assert base >= 4
    for de, a, nombre in (('"subTypes":[{"value":"post"', '"subTypes":[{"value":"any"', "tipo any"), ('"postOffset":"0"', '"postOffset":"5000"', "desplazamiento 5000")):
        assert script("--divi-dinamico-reemplazar", p, de, a) == "1", nombre
        try:
            html = pagina_en(p)
            assert titulos(html) == [], f"{nombre}: el valor editado a mano se renderizó: {titulos(html)}"
            for oculto in ("PRIVATE", "PASSWORD", "DRAFT"):
                assert fx(oculto) not in html, f"{nombre}: el visitante ve {oculto}"
        finally:
            assert script("--divi-dinamico-reemplazar", p, a, de) == "1", f"{nombre}: no se pudo devolver"
        assert len(titulos(pagina_en(p))) == base, f"{nombre}: el bucle no volvió a lo escrito"

def bucle(pagina, loop, ruta=COLUMNA):
    return lambda: cli.call("set-divi-loop", {"id": pagina, "path": ruta, "fingerprint": huella_nodo(pagina, ruta), "loop": loop})

def quitar():
    # El token primero (con el bucle puesto sigue «en uso»), luego el bucle; también el token de la página dinámica.
    p = st["buc"]
    es_error, texto, _ = bucle(p, None)()
    assert es_error and "loop_" in texto, f"quitar el bucle con tokens dentro debía rechazarse: {texto[:300]}"
    _, d = cli.ok("set-divi-dynamic", {"id": p, "path": HEADING, "fingerprint": huella_nodo(p, HEADING), "field": "title.innerContent", "source": None})
    assert d["removed"] is True and d["draft_of"] == p, d
    c = d["id"]
    _, e = cli.ok("set-divi-loop", {"id": c, "path": COLUMNA, "fingerprint": huella_nodo(c, COLUMNA), "loop": None})
    assert e["removed"] is True and e["loop"] is None, e
    crudo = script("--contenido", c)
    assert "loop-nx" not in crudo and "$variable(" not in crudo and "queryType" not in crudo, crudo[:500]
    publicar(c, p)
    html = pagina_en(p)
    assert "$variable(" not in html and not [t for t in titulos(html) if t.startswith("ZZD6")], titulos(html)
    q = st["din"]
    _, r = cli.ok("set-divi-dynamic", {"id": q, "path": HEADING, "fingerprint": huella_nodo(q, HEADING), "field": "title.innerContent", "source": None})
    assert r["removed"] is True
    publicar(r["id"], q)
    assert "$variable(" not in script("--contenido", q) and T_DIN not in titulos(pagina_en(q))

comprobar("fixtures: 4 entradas publicadas, una privada, una con contraseña y un borrador, y dos páginas Divi 5 publicadas", preparar)
if "huella0" in st:
    comprobar("list-divi-dynamic-sources: lista cerrada sin meta ni loop_*, destinos del título y fuentes por elemento; con post_id sólo lo que aplica a la página", listar)
    comprobar("set-divi-dynamic post_title en un título: la copia lleva el token canónico, el original no cambia y publicada el visitante anónimo ve el título de la página", asignar_titulo)
if "din1" in st:
    d = st["din"]
    sin_escribir("meta personalizado ajeno (custom_meta__edit_last) → nexo_mcp_divi_dynamic_denied", d, dinamico(d, {"name": "custom_meta__edit_last"}), "lista cerrada")
    sin_escribir("post_meta_key con una clave protegida → nexo_mcp_divi_dynamic_denied", d, dinamico(d, {"name": "post_meta_key", "settings": {"meta_key": "_edit_last"}}), "lista cerrada")
    sin_escribir("categoría que no coincide: post_link_url (url) en un título (texto) → nexo_mcp_divi_dynamic_denied", d, dinamico(d, {"name": "post_link_url"}), "categoría")
    sin_escribir("enable_html: on → nexo_mcp_divi_dynamic_invalid", d, dinamico(d, {"name": "post_title", "settings": {"enable_html": "on"}}), "enable_html")
    sin_escribir("loop_post_title fuera de un bucle → nexo_mcp_divi_dynamic_denied", d, dinamico(d, {"name": "loop_post_title"}), "bucle")
    sin_escribir("el token no entra por edit-divi-module (nexo_mcp_divi_dynamic_denied)", d, lambda: cli.call("edit-divi-module", {"id": d, "path": HEADING, "fingerprint": huella_nodo(d, HEADING), "content": {"title": {"innerContent": valor(TOKEN_TITULO)}}}), "dinámico")
    comprobar("con el interruptor divi_dynamic_tools_enabled apagado set-dynamic y set-divi-loop se rechazan sin escribir y la lista sigue disponible", interruptor_apagado)
if "huella0" in st:
    comprobar("set-divi-loop en una columna (3 por fecha) + loop_post_title en el título hijo: la copia lleva el bucle del conector y se publica", preparar_bucle)
if "loop_id" in st and int(script("--contar-paginas")) == st["paginas"]:
    comprobar("visitante anónimo: exactamente las 3 entradas publicadas más nuevas sin contraseña, en orden, y la página 2 por ?loop-nx…=2", visitante_ve_tres)
    comprobar("per_page 24: el visitante ve exactamente las publicadas sin contraseña (ni privada, ni protegida, ni borrador)", visitante_ve_todas)
    comprobar("guarda de render: un valor editado a mano después (tipo any, desplazamiento 5000) se renderiza vacío y al devolverlo vuelve", guarda_de_render)
    b = st["buc"]
    sin_escribir("bucle de tipo any → nexo_mcp_divi_loop_invalid", b, bucle(b, {"post_type": "any", "per_page": 3, "order_by": "date", "order": "desc"}), "loop.post_type")
    sin_escribir("bucle de tipo attachment → nexo_mcp_divi_loop_invalid", b, bucle(b, {"post_type": "attachment", "per_page": 3, "order_by": "date", "order": "desc"}), "loop.post_type")
    sin_escribir("per_page 25 (tope 24)", b, bucle(b, {"post_type": "post", "per_page": 25, "order_by": "date", "order": "desc"}), "per_page")
    sin_escribir("meta_query en el bucle", b, bucle(b, {"post_type": "post", "per_page": 3, "order_by": "date", "order": "desc", "meta_query": [{"key": "_x", "value": "y"}]}), "meta_query")
    sin_escribir("bucle anidado (la sección con una columna que ya tiene bucle) → nexo_mcp_divi_loop_nested", b, bucle(b, {"post_type": "post", "per_page": 3, "order_by": "date", "order": "desc"}, "0.0"), "anid")
    comprobar("quitar: el bucle no se quita con tokens loop_* dentro; set-divi-dynamic source null y luego loop null los quitan, y el token de la página dinámica también", quitar)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones del contenido dinámico y los bucles de Divi 5 (D5/D6) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba del contenido dinámico y los bucles de Divi 5 (D5/D6) tuvo comprobaciones fallidas"
	echo "Entradas de prueba de Divi dinámico borradas: $(tools/prueba_adaptadores.sh --divi-dinamico-barrer "$suffix")"
	echo "Contenido dinámico de Divi: $(restaurar_divi_dinamico)"
	divi_dinamico_foto_tomada=""
	divi_dinamico_cap_agregada=""
	check_eq "Divi D5/D6: lo guardado quedó byte a byte como al empezar (ajustes, roles, bloqueo)" "$divi_dinamico_huella_inicial" "$(tools/prueba_adaptadores.sh --divi-dinamico-huella)"
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
fi
if [ -n "$divi_tema_previo" ]; then
	if wp theme activate "$divi_tema_previo" >/dev/null; then echo "Tema restaurado: $divi_tema_previo"; divi_tema_previo=""
	else falla "no se pudo restaurar el tema $divi_tema_previo"; fi
fi
echo
echo "### Condiciones de visualización de Divi 5 (D7: list-divi-conditions, set-divi-conditions, get-page-structure, dropped_conditions)"
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba de las condiciones de visualización de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto cruda de lo que se toca (la limpieza la devuelve también si la corrida se corta).
	tools/prueba_adaptadores.sh --divi-condiciones-foto
	divi_condiciones_foto_tomada=1
	divi_condiciones_huella_inicial="$(tools/prueba_adaptadores.sh --divi-condiciones-huella)"
	echo "Huella cruda inicial: $divi_condiciones_huella_inicial"
	# La capacidad nexo_mcp_divi_conditions la da el instalador al subir de versión; si falta se concede (los roles se devuelven crudos).
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_conditions" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_conditions" );' >/dev/null
		divi_condiciones_cap_agregada=1
		echo "Capacidad nexo_mcp_divi_conditions concedida al administrador (los roles se devuelven crudos al final)."
	fi
	# Interruptor apagado por omisión: sólo durante el bloque (se enciende antes de conectar para que la lista de herramientas lo vea).
	tools/prueba_adaptadores.sh --ajuste divi_conditions_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import html as htmllib, importlib.util, json, pathlib, re, subprocess, sys, time, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
BASE = "http://localhost:8080"
MARCAS = {k: f"{k}MK{suffix}" for k in ("LOGIN", "PARAM", "SIEMPRE", "ROL")}
RUTAS = {"LOGIN": "0.0.0.0.0", "PARAM": "0.0.0.0.1", "SIEMPRE": "0.0.0.0.2", "ROL": "0.0.0.0.3"}
TITULO = f"Prueba condiciones divi5-mcp-{suffix}"

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=180).stdout.strip()

def huella():
    return script("--divi-condiciones-huella")

def anonimo(url):
    """GET sin cookies ni cabeceras de sesión: lo que ve un visitante."""
    with urllib.request.urlopen(url, timeout=30) as r:
        assert r.status == 200, r.status
        return r.read().decode("utf-8", "replace")

def titulos(html):
    """Texto de cada título de módulo (h1-h6 con la clase et_pb_module_header)."""
    return [htmllib.unescape(re.sub(r"<[^>]+>", "", t)).strip() for t in re.findall(r"<h[1-6][^>]*et_pb_module_header[^>]*>(.*?)</h[1-6]>", html, re.S)]

def como_anonimo(i, extra=""):
    return titulos(anonimo(f"{BASE}/?page_id={i}{extra}"))

def como_admin(i, extra=""):
    return titulos(script("--divi-condiciones-como-admin", i, extra))

def valor(v):
    return {"desktop": {"value": v}}

def estructura(i):
    _, d = cli.ok("get-page-structure", {"id": i})
    assert d["builder"] == "divi5", d.get("builder")
    return {n["id"]: n for n in d["nodes"]}

def huella_nodo(i, ruta):
    return estructura(i)[ruta]["attributes"]["fingerprint"]

def poner(pagina, modulo, condiciones, **extra):
    ruta = RUTAS[modulo]
    return cli.call("set-divi-conditions", {"id": pagina, "path": ruta, "fingerprint": huella_nodo(pagina, ruta), "match": "all", "conditions": condiciones, **extra})

def publicar(copia, original):
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (original, copia), m
    time.sleep(1.2)  # Divi regenera su CSS y sus cachés con la marca de un segundo: la primera visita llega después

st = {}
LOGUEADO = {"type": "logged_in", "rule": "logged_in"}
PARAMETRO = {"type": "url_parameter", "name": "promo", "rule": "equals", "value": "si"}
ROL = {"type": "user_role", "rule": "is", "roles": ["administrator"]}

def preparar():
    cabeza = lambda k: {"type": "divi/heading", "attrs": {"title": {"innerContent": valor(MARCAS[k])}}}
    seccion = {"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": [cabeza(k) for k in ("LOGIN", "PARAM", "SIEMPRE", "ROL")]}]}]}
    _, d = cli.ok("create-divi-page", {"title": TITULO, "sections": [seccion]})
    st["p"] = d["id"]
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    assert script("--estado", d["id"]) == "publish"
    st["base"] = script("--contenido", d["id"])
    st["paginas"] = int(script("--contar-paginas"))
    assert "conditions" not in st["base"] and "conditionName" not in st["base"]
    assert como_anonimo(d["id"]) == [MARCAS[k] for k in ("LOGIN", "PARAM", "SIEMPRE", "ROL")], como_anonimo(d["id"])
    for k, r in RUTAS.items():
        assert "conditions" not in estructura(d["id"])[r]["attributes"], k
    st["huella0"] = huella()

def listar():
    _, d = cli.ok("list-divi-conditions", {})
    tipos = {t["type"]: t for t in d["types"]}
    esperados = {"logged_in", "user_role", "date_time", "post_type", "categories", "tags", "posts", "browser", "operating_system", "url_parameter", "cart_contents", "product_stock", "product_purchase"}
    assert set(tipos) == esperados, sorted(set(tipos) ^ esperados)
    assert not [t for t in tipos if t.lower() in ("customfield", "cookie", "pagevisit", "numberofviews", "device")], list(tipos)
    assert d["max_conditions"] == 5 and "divi/text" in d["modules"] and "divi/placeholder" not in d["modules"], (d["max_conditions"], d["modules"])
    assert "administrator" in [r["slug"] for r in d["roles"]] and "page" in [t["name"] for t in d["post_types"]], d["roles"]
    for t in ("cart_contents", "product_stock", "product_purchase"):
        assert tipos[t]["available"] is bool(d["woocommerce"]), (t, tipos[t]["available"], d["woocommerce"])
    assert set(tipos["logged_in"]["rules"]) == {"logged_in", "logged_out"}, tipos["logged_in"]["rules"]
    _, e = cli.ok("list-divi-conditions", {"post_id": st["p"]})
    assert e["post_id"] == st["p"] and e["post_type"] == "page", {k: e.get(k) for k in ("post_id", "post_type")}

def sin_escribir(nombre, llamar, esperado):
    def fn():
        antes, paginas, contenido = huella(), script("--contar-paginas"), script("--contenido", st["p"])
        es_error, texto, _ = llamar()
        assert es_error, "debía rechazarse"
        for e in ([esperado] if isinstance(esperado, str) else esperado):
            assert e in texto, f"falta «{e}» en: {texto[:500]!r}"
        assert huella() == antes, "cambió lo guardado (ajustes, versión, bloqueo o roles)"
        assert script("--contar-paginas") == paginas, "cambió la cantidad de páginas (¿copia?)"
        assert script("--contenido", st["p"]) == contenido, "cambió el original"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

def interruptor_apagado():
    p = st["p"]
    script("--ajuste", "divi_conditions_tools_enabled", "false")
    try:
        antes, paginas = huella(), script("--contar-paginas")
        es_error, texto, _ = poner(p, "SIEMPRE", [LOGUEADO])
        assert es_error, f"con el interruptor apagado debía rechazarse: {texto[:300]}"
        assert huella() == antes and script("--contar-paginas") == paginas and script("--contenido", p) == st["base"], "cambió algo"
        _, listado = cli.ok("list-divi-conditions", {})  # leer el catálogo no pide el interruptor
        assert listado["types"]
    finally:
        script("--ajuste", "divi_conditions_tools_enabled", "true")

def poner_las_tres():
    p = st["p"]
    for modulo, cond in (("LOGIN", LOGUEADO), ("PARAM", PARAMETRO), ("ROL", ROL)):
        _, d = cli.ok("set-divi-conditions", {"id": p, "path": RUTAS[modulo], "fingerprint": huella_nodo(p, RUTAS[modulo]), "match": "all", "conditions": [cond]})
        assert d["draft_of"] == p and d["id"] != p and d["path"] == RUTAS[modulo] and d["match"] == "all" and len(d["conditions"]) == 1, d
        st["copia"] = d["id"]
        assert script("--contenido", p) == st["base"], f"{modulo}: el original cambió"
    assert int(script("--contar-paginas")) == st["paginas"] + 1, "debía nacer una sola copia"
    crudo = script("--contenido", st["copia"])
    for nombre in ('"conditionName":"loggedInStatus"', '"conditionName":"urlParameter"', '"conditionName":"userRole"', '"enableCondition":"on"', '"operator":"AND"'):
        assert nombre in crudo, f"falta {nombre} en la copia"
    assert crudo.count('"conditionName"') == 3, "sólo las tres condiciones pedidas"
    assert como_anonimo(p) == [MARCAS[k] for k in ("LOGIN", "PARAM", "SIEMPRE", "ROL")], "el visitante aún debía ver el original sin condiciones"

def resumen_de_lectura():
    n = estructura(st["copia"])
    c = n[RUTAS["LOGIN"]]["attributes"]["conditions"]
    assert c["ours"] is True and c["match"] == "all" and c["total"] == 1, c
    assert c["conditions"][0]["type"] == "loggedInStatus" and c["conditions"][0]["supported"] is True and c["conditions"][0]["enabled"] is True, c
    assert n[RUTAS["PARAM"]]["attributes"]["conditions"]["conditions"][0]["type"] == "urlParameter"
    assert n[RUTAS["ROL"]]["attributes"]["conditions"]["conditions"][0]["type"] == "userRole"
    assert "conditions" not in n[RUTAS["SIEMPRE"]]["attributes"], "el módulo sin condiciones no trae la clave"
    assert "conditionName" not in json.dumps(n), "la lectura no debe devolver el valor crudo"

def publicar_y_ver():
    p = st["p"]
    publicar(st["copia"], p)
    st["paginas"] = int(script("--contar-paginas"))
    anon = como_anonimo(p)
    assert anon == [MARCAS["SIEMPRE"]], f"anónimo: {anon}"
    assert como_anonimo(p, "&promo=si") == [MARCAS["PARAM"], MARCAS["SIEMPRE"]], como_anonimo(p, "&promo=si")
    assert como_anonimo(p, "&promo=no") == [MARCAS["SIEMPRE"]], "otro valor del parámetro no debía mostrarlo"
    html = anonimo(f"{BASE}/?page_id={p}")
    for k in ("LOGIN", "PARAM", "ROL"):
        assert MARCAS[k] not in html, f"el HTML del anónimo trae el texto de {k}"
    admin = como_admin(p)
    assert admin == [MARCAS["LOGIN"], MARCAS["SIEMPRE"], MARCAS["ROL"]], f"administrador: {admin}"
    assert como_admin(p, "&promo=si") == [MARCAS[k] for k in ("LOGIN", "PARAM", "SIEMPRE", "ROL")], como_admin(p, "&promo=si")
    st["c1"] = script("--contenido", p)

def texto_oculto_buscable():
    # No es control de acceso: el texto de un módulo oculto sigue en post_content y el buscador del sitio lo encuentra.
    # El enlace de la página también sale en menús y widgets: se compara contra una búsqueda que no encuentra nada.
    enlace = f"prueba-condiciones-divi5-mcp-{suffix}"
    con_texto = script("--divi-condiciones-buscar", MARCAS["LOGIN"]).count(enlace)
    sin_texto = script("--divi-condiciones-buscar", f"NOEXISTE{suffix}").count(enlace)
    assert con_texto > sin_texto, f"la búsqueda no devolvió la página por el texto de un módulo oculto ({con_texto} vs {sin_texto})"
    assert MARCAS["LOGIN"] not in anonimo(f"{BASE}/?page_id={st['p']}"), "pero el HTML de la página no lo muestra"

def foraneas():
    p = st["p"]
    # Una lista que no es del conector (como la del Visual Builder): se cambia el nombre de la condición a mano.
    assert script("--divi-dinamico-reemplazar", p, '"conditionName":"loggedInStatus"', '"conditionName":"customField"') == "1"
    st["c2"] = script("--contenido", p)
    n = estructura(p)[RUTAS["LOGIN"]]["attributes"]["conditions"]
    assert n["ours"] is False and n["conditions"][0]["type"] == "customField" and n["conditions"][0]["supported"] is False, n
    antes, paginas = huella(), script("--contar-paginas")
    es_error, texto, datos = poner(p, "LOGIN", [LOGUEADO])
    assert es_error and "ya tiene condiciones que no son de este conector" in texto and "replace_foreign" in texto, texto[:500]
    assert huella() == antes and script("--contar-paginas") == paginas and script("--contenido", p) == st["c2"], "se escribió sin confirmar"
    _, d = cli.ok("set-divi-conditions", {"id": p, "path": RUTAS["LOGIN"], "fingerprint": huella_nodo(p, RUTAS["LOGIN"]), "match": "all", "conditions": [LOGUEADO], "replace_foreign": True})
    assert d["draft_of"] == p and d["replaced"]["conditions"][0]["type"] == "customField", d
    crudo = script("--contenido", d["id"])
    assert '"conditionName":"loggedInStatus"' in crudo and "customField" not in crudo, "la lista ajena no se reemplazó"
    publicar(d["id"], p)
    assert como_anonimo(p) == [MARCAS["SIEMPRE"]] and MARCAS["LOGIN"] in como_admin(p)

def quitar():
    p = st["p"]
    _, d = cli.ok("set-divi-conditions", {"id": p, "path": RUTAS["LOGIN"], "fingerprint": huella_nodo(p, RUTAS["LOGIN"]), "match": "all", "conditions": []})
    assert d["removed"] is True and d["draft_of"] == p, d
    publicar(d["id"], p)
    assert como_anonimo(p) == [MARCAS["LOGIN"], MARCAS["SIEMPRE"]], como_anonimo(p)
    assert "conditions" not in estructura(p)[RUTAS["LOGIN"]]["attributes"]

def dropped():
    p = st["p"]
    ruta = RUTAS["PARAM"]
    _, d = cli.ok("edit-divi-section", {"id": p, "operation": "delete", "path": ruta, "fingerprint": huella_nodo(p, ruta)})
    assert d["draft_of"] == p, d
    assert len(d["dropped_conditions"]) == 1, d["dropped_conditions"]
    x = d["dropped_conditions"][0]
    assert x["path"] == ruta and x["type"] == "divi/heading" and x["summary"]["ours"] is True and x["summary"]["conditions"][0]["type"] == "urlParameter", x
    assert "promo" in x["summary"]["conditions"][0]["summary"], x
    assert MARCAS["PARAM"] not in script("--contenido", d["id"]), "la escritura se hizo igual"

comprobar("página Divi 5 publicada con cuatro títulos y sin condiciones: el visitante los ve todos y la lectura no trae conditions", preparar)
if "huella0" in st:
    comprobar("list-divi-conditions: catálogo cerrado de 13 tipos sin customField, cookie, visitas ni dispositivo; módulos, roles, tipos y WooCommerce", listar)
    p = st["p"]
    sin_escribir("customField (fuera del catálogo; lo rechaza el esquema de entrada de la herramienta)", lambda: poner(p, "SIEMPRE", [{"type": "customField", "rule": "is"}]), "input[conditions][0][type]")
    sin_escribir("fecha inexistente (2020-02-31) → nexo_mcp_divi_conditions_invalid", lambda: poner(p, "SIEMPRE", [{"type": "date_time", "rule": "after", "date": "2020-02-31"}]), "conditions.0.date")
    sin_escribir("match ausente (también obligatorio al quitar; lo rechaza el esquema de entrada)", lambda: cli.call("set-divi-conditions", {"id": p, "path": RUTAS["SIEMPRE"], "fingerprint": huella_nodo(p, RUTAS["SIEMPRE"]), "conditions": []}), "match es una propiedad obligatoria")
    sin_escribir("edit-divi-module con module.decoration.conditions → nexo_mcp_divi_attr_denied", lambda: cli.call("edit-divi-module", {"id": p, "path": RUTAS["SIEMPRE"], "fingerprint": huella_nodo(p, RUTAS["SIEMPRE"]), "design": {"module": {"decoration": {"conditions": valor([{"id": "11111111-1111-4111-8111-111111111111", "conditionName": "loggedInStatus", "conditionSettings": {"enableCondition": "on", "displayRule": "loggedIn"}, "operator": "AND"}])}}}}), "no está en la lista admitida")
    comprobar("con el interruptor divi_conditions_tools_enabled apagado set-divi-conditions se rechaza sin escribir y list-divi-conditions sigue disponible", interruptor_apagado)
    comprobar("set-divi-conditions logged_in, url_parameter y user_role en tres títulos: una sola copia, el original no cambia y la copia lleva sólo las tres condiciones del esquema", poner_las_tres)
if "copia" in st:
    comprobar("get-page-structure resume las condiciones (attributes.conditions) sin el valor crudo y los demás módulos no traen la clave", resumen_de_lectura)
    comprobar("publicada: el anónimo no ve los de sesión, parámetro ni rol; con ?promo=si ve el del parámetro; el administrador con sesión ve el de sesión y el de rol", publicar_y_ver)
if "c1" in st:
    comprobar("no es control de acceso: la búsqueda del sitio encuentra la página por el texto de un módulo oculto (y el HTML no lo muestra)", texto_oculto_buscable)
    comprobar("lista ajena (customField hecha a mano): lectura ours=false, sin replace_foreign → nexo_mcp_divi_conditions_foreign sin escribir, con replace_foreign se reemplaza (replaced)", foraneas)
    comprobar("conditions [] quita las condiciones: removed y el anónimo vuelve a ver el módulo", quitar)
    comprobar("edit-divi-section delete de un módulo con condiciones devuelve dropped_conditions (ruta, tipo y resumen) y borra igual", dropped)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de las condiciones de visualización de Divi 5 (D7) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de las condiciones de visualización de Divi 5 (D7) tuvo comprobaciones fallidas"
	echo "Condiciones de Divi: $(restaurar_divi_condiciones)"
	divi_condiciones_foto_tomada=""
	divi_condiciones_cap_agregada=""
	check_eq "Divi D7: lo guardado quedó byte a byte como al empezar (ajustes, versión, roles, bloqueo)" "$divi_condiciones_huella_inicial" "$(tools/prueba_adaptadores.sh --divi-condiciones-huella)"
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
fi

echo
echo "### Theme Builder de Divi 5 (D8a: create-divi-layout, list-/set-/delete-/restore-divi-template; plantillas que cambian todo el sitio)"
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba del Theme Builder de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto del estado y de las opciones crudas que se tocan; la limpieza lo devuelve todo también si la corrida se corta.
	tools/prueba_adaptadores.sh --divi-tb-foto
	divi_tb_foto_tomada=1
	divi_tb_huella_inicial="$(tools/prueba_adaptadores.sh --divi-tb-huella)"
	echo "Huella inicial:"
	printf '%s\n' "$divi_tb_huella_inicial" | sed 's/^/  /'
	# La capacidad nexo_mcp_divi_theme_builder la da el instalador al subir de versión; si falta se concede (los roles se devuelven crudos).
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_theme_builder" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_theme_builder" );' >/dev/null
		echo "Capacidad nexo_mcp_divi_theme_builder concedida al administrador (los roles se devuelven crudos al final)."
	fi
	# Interruptor apagado por omisión: sólo durante el bloque (se enciende antes de conectar para que la lista de herramientas lo vea).
	tools/prueba_adaptadores.sh --ajuste divi_theme_builder_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, time, urllib.error, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
BASE = "http://localhost:8080"
TITULO = f"Prueba themebuilder divi5-mcp-{suffix}"
NOMBRES = ("PAG", "PAG2", "H1", "B1", "F1", "H2", "H3", "B3", "B4", "B5")
M = {k: f"TB{k}MK{suffix}" for k in NOMBRES}
NO_EXISTE = f"/divi5-mcp-{suffix}-no-existe/"

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=240).stdout.strip()

def huella():
    return script("--divi-tb-huella")

def anonimo(ruta):
    """GET sin cookies: lo que ve un visitante. Devuelve (código, HTML); una 404 no es una excepción."""
    try:
        with urllib.request.urlopen(BASE + ruta, timeout=60) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")

def visibles(ruta, codigo=200):
    """Marcas de esta prueba que el visitante ve en `ruta`, en el orden en que salen en el HTML."""
    estado, html = anonimo(ruta)
    assert estado == codigo, f"{ruta}: código {estado}, se esperaba {codigo}"
    return [k for k in sorted((k for k in NOMBRES if M[k] in html), key=lambda k: html.index(M[k]))]

def valor(v):
    return {"desktop": {"value": v}}

def titulo(clave):
    return {"type": "divi/heading", "attrs": {"title": {"innerContent": valor(M[clave])}}}

def secciones(*modulos):
    return [{"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": list(modulos)}]}]}]

POST_CONTENT = {"type": "divi/post-content"}
st = {}

def layout(tipo, clave, *extra):
    _, d = cli.ok("create-divi-layout", {"type": tipo, "title": f"Layout {clave} divi5-mcp-{suffix}", "sections": secciones(titulo(clave), *extra)})
    assert d["type"] == tipo and d["status"] == "publish" and d["assigned"] is False and d["post_type"] == f"et_{tipo}_layout", d
    st[clave] = d["id"]
    return d

def plantilla(**params):
    return cli.call("set-divi-template", params)

def ok_plantilla(**params):
    _, d = cli.ok("set-divi-template", params)
    time.sleep(1.2)  # el CSS de Divi se regenera con la marca de un segundo: la primera visita llega después
    return d

def activar(id_, **params):
    d = ok_plantilla(id=id_, enabled=True, **params)
    assert d["created"] is False and d["enabled"] is True, d
    return d

def en_lista(id_):
    _, d = cli.ok("list-divi-theme-builder", {})
    return d, next((t for t in d["templates"] if t["id"] == id_), None)

def preparar():
    _, d = cli.ok("create-divi-page", {"title": TITULO, "sections": secciones(titulo("PAG"))})
    st["p"] = d["id"]
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    _, d2 = cli.ok("create-divi-page", {"title": TITULO + " dos", "sections": secciones(titulo("PAG2"))})
    st["q"] = d2["id"]
    cli.ok("set-content-status", {"id": d2["id"], "status": "publish"})
    st["cat"] = script("--divi-tb-categoria")
    st["carrito"] = script("--divi-tb-carrito")
    time.sleep(1.2)
    assert visibles(f"/?page_id={st['p']}") == ["PAG"], visibles(f"/?page_id={st['p']}")
    assert visibles(f"/?page_id={st['q']}") == ["PAG2"]
    assert visibles(NO_EXISTE, 404) == [] and visibles(f"/?s=divi5mcp{suffix}") == [] and visibles(f"/?cat={st['cat']}") == []
    _, listado = cli.ok("list-divi-theme-builder", {})
    assert listado["draft"]["exists"] in (True, False) and isinstance(listado["templates"], list), listado
    st["antes"] = len(listado["templates"])
    if st["carrito"]:
        _, html = anonimo(f"/?page_id={st['carrito']}")
        st["marca_carrito"] = "wc-block-cart" if "wc-block-cart" in html else ""
    st["huella0"] = huella()

def crear_layouts():
    layout("header", "H1")
    layout("body", "B1", POST_CONTENT)
    layout("footer", "F1")
    layout("header", "H2")
    layout("header", "H3")
    d = layout("body", "B3", POST_CONTENT)
    layout("body", "B4", POST_CONTENT)
    d5 = layout("body", "B5")
    assert "post-content" in json.dumps(d5["warnings"], ensure_ascii=False), d5["warnings"]
    assert "post-content" not in json.dumps(d["warnings"], ensure_ascii=False), "un cuerpo con post-content no avisa"
    assert any(n["type"] == "divi/post-content" for n in d["nodes"]), d["nodes"]
    time.sleep(1.2)
    assert visibles(f"/?page_id={st['p']}") == ["PAG"], "un layout sin asignar no cambia el sitio"

def crear_plantilla_deshabilitada():
    d = ok_plantilla(title=f"Plantilla página divi5-mcp-{suffix}", enabled=True, use_on=[f"singular:post_type:page:id:{st['p']}"], header={"layout_id": st["H1"]}, body={"layout_id": st["B1"]}, footer={"layout_id": st["F1"]})
    assert d["created"] is True and d["enabled"] is False and d.get("needs_enable"), d
    st["t1"] = d["id"]
    assert visibles(f"/?page_id={st['p']}") == ["PAG"], "una plantilla nueva (deshabilitada) no cambia nada"
    listado, t = en_lista(d["id"])
    assert t is not None and t["ours"] is True and t["enabled"] is False and listado["live"]["exists"] is True, t
    assert t["body"]["layout_id"] == st["B1"] and t["body"]["has_post_content"] is True, t["body"]

def activar_pagina():
    activar(st["t1"], title=f"Plantilla página divi5-mcp-{suffix}", use_on=[f"singular:post_type:page:id:{st['p']}"], header={"layout_id": st["H1"]}, body={"layout_id": st["B1"]}, footer={"layout_id": st["F1"]})
    orden = visibles(f"/?page_id={st['p']}")
    assert orden == ["H1", "B1", "PAG", "F1"], f"encabezado, cuerpo, contenido de la página y pie, en ese orden: {orden}"
    assert visibles(f"/?page_id={st['q']}") == ["PAG2"], "otra página no cambia"
    _, t = en_lista(st["t1"])
    assert t["enabled"] is True and t["use_on"][0]["id"] == f"singular:post_type:page:id:{st['p']}", t

def una_sola_plantilla():
    d = ok_plantilla(title=f"Plantilla todas las páginas divi5-mcp-{suffix}", enabled=True, use_on=["singular:post_type:page:all"], header={"layout_id": st["H2"]}, confirm_site_wide=True)
    assert d["created"] is True and d["site_wide"] is True, d
    st["t2"] = d["id"]
    activar(st["t2"], title=f"Plantilla todas las páginas divi5-mcp-{suffix}", use_on=["singular:post_type:page:all"], header={"layout_id": st["H2"]}, confirm_site_wide=True)
    propia, otra = visibles(f"/?page_id={st['p']}"), visibles(f"/?page_id={st['q']}")
    assert propia == ["H1", "B1", "PAG", "F1"], f"la plantilla de la página gana y no se mezcla con la otra: {propia}"
    assert otra == ["H2", "PAG2"], f"la otra página usa la de todas las páginas: {otra}"
    listado, _ = en_lista(st["t2"])
    assert any(s["template"] == st["t2"] for s in listado["shadows"]), listado["shadows"]

def contextos_404_busqueda_categoria():
    parametros = dict(title=f"Plantilla 404 búsqueda categoría divi5-mcp-{suffix}", use_on=["404", "search", f"archive:taxonomy:category:term:id:{st['cat']}"], header={"layout_id": st["H3"]}, body={"layout_id": st["B3"]}, confirm_site_wide=True)
    d = ok_plantilla(enabled=True, **parametros)
    assert d["created"] is True and d["enabled"] is False, d
    st["t3"] = d["id"]
    assert visibles(NO_EXISTE, 404) == [] and visibles(f"/?s=divi5mcp{suffix}") == [] and visibles(f"/?cat={st['cat']}") == [], "deshabilitada: nada cambia"
    activar(st["t3"], **parametros)
    for ruta, codigo in ((NO_EXISTE, 404), (f"/?s=divi5mcp{suffix}", 200), (f"/?cat={st['cat']}", 200)):
        assert visibles(ruta, codigo) == ["H3", "B3"], f"{ruta}: {visibles(ruta, codigo)}"
    assert visibles(f"/?page_id={st['p']}") == ["H1", "B1", "PAG", "F1"], "las páginas siguen con sus plantillas"

def carrito():
    if not st.get("carrito") or not st.get("marca_carrito"):
        print("SKIP: WooCommerce no está activo (o no tiene página de carrito); se saltea la prueba del carrito.")
        return
    ruta, marca = f"/?page_id={st['carrito']}", st["marca_carrito"]

    def primera_visita():
        # Con Divi 5 y WooCommerce de este docker la página del carrito pierde su contenido principal a partir de la segunda
        # visita seguida, también sin ninguna plantilla (el CSS estático ya existe): cada estado se mira con UNA sola visita,
        # la primera después de que set-divi-template borra el CSS de todo el sitio.
        return anonimo(ruta)[1]

    h = primera_visita()
    assert marca in h and M["B4"] not in h and M["B5"] not in h, "línea base: el carrito se ve y no hay plantilla propia"
    parametros = dict(title=f"Plantilla carrito divi5-mcp-{suffix}", use_on=["woocommerce:cart"])
    d = ok_plantilla(enabled=True, body={"layout_id": st["B4"]}, **parametros)
    st["t4"] = d["id"]
    activar(st["t4"], body={"layout_id": st["B4"]}, **parametros)
    h = primera_visita()
    assert M["B4"] in h and marca in h, "el cuerpo con divi/post-content conserva el carrito"
    # Un cuerpo sin divi/post-content REEMPLAZA el contenido: se rechaza salvo que se pida expresamente.
    antes = huella()
    es_error, texto, _ = cli.call("set-divi-template", {"id": st["t4"], "enabled": True, "body": {"layout_id": st["B5"]}, **parametros})
    assert es_error and "allow_without_post_content" in texto, texto[:400]
    despues = huella()
    assert despues == antes, f"el rechazo cambió el estado: {[x for x in despues.splitlines() if x not in antes.splitlines()]}"
    ok_plantilla(id=st["t4"], enabled=True, body={"layout_id": st["B5"]}, allow_without_post_content=True, **parametros)
    h = primera_visita()
    assert M["B5"] in h and marca not in h and M["B4"] not in h, "sin divi/post-content el cuerpo REEMPLAZA el carrito"
    _, d = cli.ok("restore-divi-template", {"id": st["t4"]})
    time.sleep(1.2)
    assert d["restored"] is True and d["status"] == "publish" and d["mapped"] is True, d
    h = primera_visita()
    assert M["B4"] in h and M["B5"] not in h and marca in h, "restore-divi-template devuelve el cuerpo anterior y el carrito vuelve"

def borrar_restaurar_restaurar():
    pagina = f"/?page_id={st['p']}"
    assert visibles(pagina) == ["H1", "B1", "PAG", "F1"]
    _, d = cli.ok("delete-divi-template", {"id": st["t1"]})
    time.sleep(1.2)
    assert d["deleted"] is True and d["status"] == "trash" and d["backup"]["operation"] == "delete", d
    assert visibles(pagina) == ["H2", "PAG"], f"sin su plantilla la página usa la de todas las páginas: {visibles(pagina)}"
    _, t = en_lista(st["t1"])
    assert t is None, "la plantilla borrada ya no está en el mapeo en vivo"
    _, r = cli.ok("restore-divi-template", {"id": st["t1"]})
    time.sleep(1.2)
    assert r["restored"] is True and r["status"] == "publish" and r["mapped"] is True and r["restored_from"]["operation"] == "delete", r
    assert visibles(pagina) == ["H1", "B1", "PAG", "F1"], f"restaurada: vuelve a mandar: {visibles(pagina)}"
    assert not script("--divi-tb-slug", st["t1"]).endswith("__trashed"), "la plantilla restaurada conserva el sufijo __trashed del slug"
    _, t = en_lista(st["t1"])
    assert t is not None and t["enabled"] is True and t["ours"] is True, t
    _, otra = cli.ok("restore-divi-template", {"id": st["t1"]})
    time.sleep(1.2)
    assert otra["status"] == "trash" and otra["mapped"] is False, otra
    assert visibles(pagina) == ["H2", "PAG"], "restaurar otra vez alterna: vuelve al estado borrado"
    _, t = en_lista(st["t1"])
    assert t is None

def sin_escribir(nombre, llamar, esperado):
    def fn():
        antes = huella()
        es_error, texto, _ = llamar()
        assert es_error, "debía rechazarse"
        assert esperado in texto, f"falta «{esperado}» en: {texto[:500]!r}"
        assert huella() == antes, "cambió el estado del sitio"
    comprobar(f"rechazo sin escribir: {nombre}", fn)

def interruptor_apagado():
    # Con el interruptor apagado el módulo no se puede usar (mensaje genérico de módulo desactivado) y un layout no se lee
    # con el permiso de constructores de las páginas (DiviLayoutGuard: «se muestran en todo el sitio»).
    cerrado = ("desactivado en el panel", "se muestran en todo el sitio")
    script("--ajuste", "divi_theme_builder_tools_enabled", "false")
    try:
        antes = huella()
        for herramienta, entrada in (("set-divi-template", {"title": "x", "enabled": False}), ("list-divi-theme-builder", {}), ("delete-divi-template", {"id": st["t1"]}), ("restore-divi-template", {"id": st["t1"]}), ("create-divi-layout", {"type": "header", "title": "x", "sections": secciones(titulo("H1"))}), ("get-page-structure", {"id": st["H1"]})):
            es_error, texto, _ = cli.call(herramienta, entrada)
            assert es_error and any(c in texto for c in cerrado), f"{herramienta} debía rechazarse: {texto[:300]}"
        assert huella() == antes, "cambió algo"
    finally:
        script("--ajuste", "divi_theme_builder_tools_enabled", "true")
    _, d = cli.ok("get-page-structure", {"id": st["H1"]})
    assert d["builder"] == "divi5", "con el interruptor encendido el layout se lee con el permiso del Theme Builder"

comprobar("dos páginas Divi 5 publicadas, sin Theme Builder propio: el visitante ve sólo su contenido (también en 404, ?s= y la categoría)", preparar)
if "huella0" in st:
    comprobar("create-divi-layout: encabezados, cuerpos (con y sin divi/post-content) y pie publicados y sin asignar; no cambian el sitio y el cuerpo sin post-content avisa", crear_layouts)
if "B5" in st:
    comprobar("set-divi-template: una plantilla nueva nace deshabilitada (needs_enable), list-divi-theme-builder la muestra y el visitante no ve cambios", crear_plantilla_deshabilitada)
if "t1" in st:
    comprobar("activada: encabezado, cuerpo, contenido de la página y pie, en ese orden; otra página no cambia", activar_pagina)
    comprobar("Divi usa UNA plantilla por petición: la de una página concreta gana a la de todas las páginas y ésta se ve en otra página", una_sola_plantilla)
if "t2" in st:
    comprobar("404, ?s= y la categoría: la plantilla creada deshabilitada no cambia nada y, activada, se ve en las tres (la 404 con código 404)", contextos_404_busqueda_categoria)
    comprobar("carrito de WooCommerce: con divi/post-content se conserva, sin él se REEMPLAZA (sólo con allow_without_post_content) y restore-divi-template vuelve al anterior", carrito)
    comprobar("delete-divi-template → restore-divi-template (vuelve a su fila, sin __trashed) → restore-divi-template (alterna: vuelve a estar borrada)", borrar_restaurar_restaurar)
    comprobar("con el interruptor divi_theme_builder_tools_enabled apagado nada de esto se puede usar ni leer, y un layout no se lee con el permiso de las páginas", interruptor_apagado)
    sin_escribir("plantilla por defecto sin confirm_site_wide → nexo_mcp_divi_template_site_wide", lambda: cli.call("set-divi-template", {"title": "x", "enabled": True, "default": True, "header": {"layout_id": st["H2"]}}), "confirm_site_wide")
    sin_escribir("cuerpo sin divi/post-content en una página → nexo_mcp_divi_template_body_replaces_content", lambda: cli.call("set-divi-template", {"title": "x", "enabled": True, "use_on": [f"singular:post_type:page:id:{st['q']}"], "body": {"layout_id": st["B5"]}}), "allow_without_post_content")
    sin_escribir("id inexistente → nexo_mcp_divi_template_not_found", lambda: cli.call("set-divi-template", {"id": 99999999, "title": "x", "enabled": False}), "No hay una plantilla con ese id en el Theme Builder en vivo")
    sin_escribir("layout que no es del tipo de la ranura (un pie como encabezado) → nexo_mcp_divi_template_invalid", lambda: cli.call("set-divi-template", {"title": "x", "enabled": False, "header": {"layout_id": st["F1"]}}), "no es un layout de tipo header")
    sin_escribir("restore-divi-template de algo que no es una plantilla → nexo_mcp_divi_template_not_found", lambda: cli.call("restore-divi-template", {"id": st["p"]}), "No hay una plantilla con ese id")

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones del Theme Builder de Divi 5 (D8a) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba del Theme Builder de Divi 5 (D8a) tuvo comprobaciones fallidas"
	# Se borra todo lo creado (plantillas, layouts, Theme Builder en vivo, términos, páginas, et-cache) y se vuelve a la foto cruda.
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
	echo "Theme Builder de Divi: $(restaurar_divi_tb)"
	cerrar_divi_tb
	divi_tb_foto_tomada=""
	divi_tb_huella_final="$(tools/prueba_adaptadores.sh --divi-tb-huella)"
	check_eq "Divi D8a: el estado quedó exactamente como al empezar (posts, metas, términos, relaciones, opciones, metas del admin, usuarios y et-cache)" "$divi_tb_huella_inicial" "$divi_tb_huella_final"
fi
echo
echo "### Biblioteca de Divi 5 (D8b: save-/delete-/restore-divi-library-item e insert-divi-library-item; un ítem global cambia varias páginas a la vez)"
if [ "$divi_listo" != si ]; then
	echo "SKIP: Divi 5 no está activo ni instalado; la prueba de la biblioteca de Divi 5 se saltea (no cuenta como falla)."
else
	# Foto del estado y de las opciones crudas que se tocan; la limpieza lo devuelve todo también si la corrida se corta.
	tools/prueba_adaptadores.sh --divi-lib-foto
	divi_lib_foto_tomada=1
	divi_lib_huella_inicial="$(tools/prueba_adaptadores.sh --divi-tb-huella)"
	echo "Huella inicial:"
	printf '%s\n' "$divi_lib_huella_inicial" | sed 's/^/  /'
	# La capacidad nexo_mcp_divi_library la da el instalador al subir de versión; si falta se concede (los roles se devuelven crudos).
	if [ "$(php_wpr 'echo get_role( "administrator" )->has_cap( "nexo_mcp_divi_library" ) ? "si" : "no";')" = no ]; then
		php_wp 'get_role( "administrator" )->add_cap( "nexo_mcp_divi_library" );' >/dev/null
		echo "Capacidad nexo_mcp_divi_library concedida al administrador (los roles se devuelven crudos al final)."
	fi
	# Interruptor apagado por omisión: sólo durante el bloque (se enciende antes de conectar para que la lista de herramientas lo vea).
	tools/prueba_adaptadores.sh --ajuste divi_library_tools_enabled true
	python3 -I - "$suffix" <<'PY'
import importlib.util, json, pathlib, re, subprocess, sys, time, urllib.error, urllib.request

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
BASE = "http://localhost:8080"
TITULO = f"Prueba biblioteca divi5-mcp-{suffix}"
NOMBRES = ("A", "B", "C", "FILA", "MOD")
M = {k: f"LIB{k}MK{suffix}" for k in NOMBRES}

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True, timeout=240).stdout.strip()

def huella():
    return script("--divi-tb-huella")

def anotar(id_):
    """Cada id que crea la prueba se anota: la limpieza borra sólo esos."""
    assert script("--divi-lib-anotar", id_) == "anotado", f"no se pudo anotar {id_}"
    return id_

def anonimo(ruta):
    """GET sin cookies: lo que ve un visitante."""
    try:
        with urllib.request.urlopen(BASE + ruta, timeout=60) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")

def visibles(pagina):
    """Marcas de esta prueba que el visitante ve en la página."""
    estado, html = anonimo(f"/?page_id={pagina}")
    assert estado == 200, f"página {pagina}: código {estado}"
    return [k for k in NOMBRES if M[k] in html]

def valor(v):
    return {"desktop": {"value": v}}

def titulo(clave):
    return {"type": "divi/heading", "attrs": {"title": {"innerContent": valor(M[clave])}}}

def secciones(*modulos):
    return [{"type": "divi/section", "children": [{"type": "divi/row", "children": [{"type": "divi/column", "children": list(modulos)}]}]}]

def fila(clave):
    return [{"type": "divi/row", "children": [{"type": "divi/column", "children": [titulo(clave)]}]}]

def huella_nodo(pagina, ruta):
    _, d = cli.ok("get-page-structure", {"id": pagina})
    assert d["builder"] == "divi5", d.get("builder")
    return next(n for n in d["nodes"] if n["id"] == ruta)["attributes"]["fingerprint"]

def publicar(copia, original):
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (original, copia), m
    time.sleep(1.2)  # Divi regenera su CSS y sus cachés con la marca de un segundo: la primera visita llega después

def insertar(item, pagina, ruta, posicion, modo):
    """Inserta en la copia de trabajo de la página (el original no cambia hasta publicar) y publica."""
    _, d = cli.ok("insert-divi-library-item", {"id": item, "post_id": pagina, "path": ruta, "fingerprint": huella_nodo(pagina, ruta), "position": posicion, "mode": modo})
    assert d["mode"] == modo and d["id"] != pagina, f"se esperaba la copia de trabajo de {pagina}: {d.get('id')}"
    copia = d["id"]
    contenido = script("--contenido", copia)
    assert ("globalModule" in contenido) == (modo == "global"), f"modo {modo}: la copia {'no ' if modo == 'global' else ''}debía llevar la referencia"
    publicar(copia, pagina)
    return d

def crear(titulo_, tipo, ambito, nodos, **extra):
    _, d = cli.ok("save-divi-library-item", {"title": f"{titulo_} divi5-mcp-{suffix}", "type": tipo, "scope": ambito, "nodes": nodos, **extra})
    anotar(d["id"])
    assert d["status"] == "publish" and d["scope"] == ambito and d["type"] == tipo, d
    return d

def pagina(clave):
    _, d = cli.ok("create-divi-page", {"title": f"{TITULO} {clave}", "sections": secciones({"type": "divi/text", "attrs": {"content": {"innerContent": valor("<p>base</p>")}}})})
    anotar(d["id"])
    cli.ok("set-content-status", {"id": d["id"], "status": "publish"})
    return d["id"]

def contiene_referencia(i):
    return "globalModule" in script("--contenido", i)

st = {}
COLUMNA = "0.0.0.0"  # raíz (placeholder) > sección > fila > columna
SECCION = "0.0"

def preparar():
    st["p"], st["q"], st["t"] = pagina("uno"), pagina("dos"), pagina("tres")
    time.sleep(1.2)
    for i in (st["p"], st["q"], st["t"]):
        assert visibles(i) == [], f"la página {i} no debe mostrar nada de la prueba todavía"
    d = crear("Global", "module", "global", [titulo("A")])
    st["g"] = d["id"]
    st["huella0"] = huella()

def insertar_global_en_dos_paginas():
    for i in (st["p"], st["q"]):
        d = insertar(st["g"], i, COLUMNA, "inside", "global")
        assert d["item"]["id"] == st["g"] and d["item"]["scope"] == "global", d["item"]
        assert any("POR REFERENCIA" in w for w in d["warnings"]), d["warnings"]
        assert contiene_referencia(i) and script("--estado", i) == "publish"
    assert visibles(st["p"]) == ["A"] and visibles(st["q"]) == ["A"], (visibles(st["p"]), visibles(st["q"]))

def copia_no_enlaza():
    d = insertar(st["g"], st["t"], COLUMNA, "inside", "copy")
    assert d["mode"] == "copy" and any("COPIA" in w for w in d["warnings"]), d["warnings"]
    assert not contiene_referencia(st["t"]), "el modo copy no inserta referencia"
    assert visibles(st["t"]) == ["A"], "la copia lleva el contenido del ítem"

def editar_sin_confirmar():
    antes = huella()
    es_error, texto, _ = cli.call("save-divi-library-item", {"id": st["g"], "nodes": [titulo("B")]})
    assert es_error and "confirm_global" in texto, texto[:300]
    assert huella() == antes, "el rechazo cambió el estado"
    assert visibles(st["p"]) == ["A"]

def editar_con_confirmar():
    antes = {i: script("--divi-lib-pagina", i) for i in (st["p"], st["q"], st["t"])}
    _, d = cli.ok("save-divi-library-item", {"id": st["g"], "nodes": [titulo("B")], "confirm_global": True})
    time.sleep(1.2)
    afectadas = d["affected_pages"]
    assert afectadas["count"] == 2 and not afectadas["truncated"], afectadas
    assert sorted(p["id"] for p in afectadas["pages"]) == sorted((st["p"], st["q"])), afectadas
    assert all(p["editable"] is True and p["title"].startswith(TITULO) and "title_hidden" not in p for p in afectadas["pages"]), afectadas["pages"]
    assert d["cache_not_cleared"] == 0 and d["backup"]["operation"] == "save", d
    assert any("global" in w for w in d["warnings"]), d["warnings"]
    despues = {i: script("--divi-lib-pagina", i) for i in antes}
    assert despues == antes, "las páginas no se tocan: Divi lee el ítem al renderizar"
    assert visibles(st["p"]) == ["B"] and visibles(st["q"]) == ["B"], (visibles(st["p"]), visibles(st["q"]))
    assert visibles(st["t"]) == ["A"], "la copia no se sincroniza"

def paginas_prohibidas():
    # Ningún usuario puede editar una nota de Divi (et_divi_note, edit_post → do_not_allow): referencia el ítem como cualquier página.
    nota = script("--divi-lib-nota", st["g"], suffix)
    assert nota.isdigit(), nota
    try:
        antes, contenidos = huella(), {i: script("--divi-lib-pagina", i) for i in (st["p"], st["q"])}
        es_error, texto, datos = cli.call("delete-divi-library-item", {"id": st["g"], "confirm_global": True})
        assert es_error and "No puedes editar todos los contenidos que usan este ítem global" in texto and nota in texto, texto[:400]
        assert huella() == antes, "el rechazo cambió el estado"
        assert {i: script("--divi-lib-pagina", i) for i in contenidos} == contenidos
        assert script("--estado", st["g"]) == "publish" and visibles(st["p"]) == ["B"], "el ítem sigue publicado y las páginas lo siguen mostrando"
    finally:
        assert script("--divi-lib-borrar", nota) == "borrado", "la nota de prueba no se borró"

def borrar_despega():
    _, d = cli.ok("delete-divi-library-item", {"id": st["g"], "confirm_global": True})
    time.sleep(1.2)
    assert d["deleted"] is True and d["status"] == "trash" and d["backup"]["operation"] == "delete", d
    assert d["still_referenced"] == 0, d
    assert d["affected_pages"]["count"] == 2 and sorted(p["id"] for p in d["affected_pages"]["pages"]) == sorted((st["p"], st["q"])), d["affected_pages"]
    assert script("--estado", st["g"]) == "trash"
    for i in (st["p"], st["q"]):
        assert not contiene_referencia(i), f"la página {i} sigue referenciando el ítem borrado"
        assert visibles(i) == ["B"], f"la página {i} conserva el contenido del ítem despegado: {visibles(i)}"
    assert visibles(st["t"]) == ["A"]

def restaurar_no_enlaza():
    _, d = cli.ok("restore-divi-library-item", {"id": st["g"], "confirm_global": True})
    time.sleep(1.2)
    assert d["restored"] is True and d["status"] == "publish" and d["restored_from"]["operation"] == "delete", d
    assert d["affected_pages"]["count"] == 0, "restaurar no vuelve a enlazar las páginas"
    for i in (st["p"], st["q"]):
        assert not contiene_referencia(i), f"la página {i} no debía volver a enlazarse"
        assert visibles(i) == ["B"]
    # Un cambio posterior del ítem ya no las alcanza.
    _, e = cli.ok("save-divi-library-item", {"id": st["g"], "nodes": [titulo("C")], "confirm_global": True})
    assert e["affected_pages"]["count"] == 0, e["affected_pages"]
    time.sleep(1.2)
    assert visibles(st["p"]) == ["B"] and visibles(st["q"]) == ["B"], "las páginas despegadas no siguen al ítem"

def global_no_publicado():
    insertar(st["g"], st["p"], COLUMNA, "inside", "global")
    assert contiene_referencia(st["p"]) and "C" in visibles(st["p"]), visibles(st["p"])
    for estado in ("private", "draft", "trash"):
        assert script("--divi-lib-estado", st["g"], estado) == estado
        time.sleep(1.2)
        assert "C" not in visibles(st["p"]), f"un global {estado} no debe renderizarse: {visibles(st['p'])}"
        assert "B" in visibles(st["p"]), "el resto de la página sigue"
        assert script("--divi-lib-estado", st["g"], "publish") == "publish"
        time.sleep(1.2)
        assert "C" in visibles(st["p"]), f"publicado otra vez vuelve a verse (venía de {estado})"

def fila_y_modulo_abren_en_divi():
    fila_item = crear("Fila global", "row", "global", fila("FILA"))
    modulo = crear("Módulo", "module", "non_global", [titulo("MOD")])
    for item, hijo in ((fila_item["id"], "divi/row"), (modulo["id"], "divi/heading")):
        d = json.loads(script("--divi-lib-render", item, M["FILA"] if item == fila_item["id"] else M["MOD"]))
        assert d["superior"] == "divi/placeholder" and d["hijo"] == hijo, f"el parser de Divi lee otra forma: {d}"
        assert d["marca"] is True, f"el renderizador de Divi no pinta el contenido del ítem {item}: {d}"
    # La fila global, por referencia, la dibuja el renderizador de Divi dentro de una página.
    insertar(fila_item["id"], st["t"], SECCION, "inside", "global")
    assert "FILA" in visibles(st["t"]), visibles(st["t"])

comprobar("tres páginas Divi 5 publicadas y un módulo global sin usar: el visitante no ve nada de la prueba", preparar)
if "g" in st:
    comprobar("insert-divi-library-item mode global en la copia de trabajo de dos páginas y publicar: las dos referencian el ítem y las dos lo muestran", insertar_global_en_dos_paginas)
    comprobar("mode copy no inserta referencia: la tercera página lleva el contenido y no sigue al ítem", copia_no_enlaza)
    comprobar("editar un global sin confirm_global se rechaza sin escribir", editar_sin_confirmar)
    comprobar("editar con confirm_global: lo ven las dos páginas sin tocarlas y affected_pages las lista (con título, porque hay read_post)", editar_con_confirmar)
    comprobar("pages_forbidden: si el usuario no puede editar una página que lo usa, borrar se rechaza y no cambia nada", paginas_prohibidas)
    comprobar("borrar con confirm_global despega: las dos páginas siguen mostrándolo y still_referenced es 0", borrar_despega)
    comprobar("restore-divi-library-item devuelve el ítem y no vuelve a enlazar las páginas", restaurar_no_enlaza)
    comprobar("un global privado, en borrador o en la papelera deja de mostrarse (y vuelve al publicarlo)", global_no_publicado)
    comprobar("una fila global y un módulo guardados bajo divi/placeholder los abre el parser y el renderizador de Divi", fila_y_modulo_abren_en_divi)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de la biblioteca de Divi 5 (D8b) OK.")
sys.exit(1 if fallas else 0)
PY
	[ $? -eq 0 ] || falla "la prueba de la biblioteca de Divi 5 (D8b) tuvo comprobaciones fallidas"
	# Se borran las páginas por su marcador y SÓLO los ids anotados, y se vuelve a la foto cruda (ajustes incluidos).
	barrer_divi || falla "no se pudieron borrar las páginas de Divi 5 (o sus et-cache)"
	echo "Biblioteca de Divi: $(restaurar_divi_lib)"
	cerrar_divi_lib
	divi_lib_foto_tomada=""
	divi_lib_huella_final="$(tools/prueba_adaptadores.sh --divi-tb-huella)"
	check_eq "Divi D8b: el estado quedó exactamente como al empezar (posts, metas, términos, relaciones, opciones incluido nexo_mcp_settings, metas del admin, usuarios y et-cache)" "$divi_lib_huella_inicial" "$divi_lib_huella_final"
fi
echo "### Concurrencia: primeras ediciones simultáneas sobre una página publicada"
# Tres páginas publicadas propias, sin copia: 5 update-page con el mismo campo, 3 update-page
# con campos distintos (ninguna edición puede perderse) y 5 edit-elementor-section que insertan
# cada una un bloque propio. Cada llamada va en su propio hilo, con una barrera de arranque.
conc_titulo="Prueba concurrencia elementor-mcp-$suffix"
conc_cinco_id="$(wp post create --post_type=page --post_status=publish --post_title="$conc_titulo cinco" --post_content="base" --porcelain)"
conc_campos_id="$(wp post create --post_type=page --post_status=publish --post_title="$conc_titulo campos" --post_content="base" --porcelain)"
conc_secciones_id="$(wp post create --post_type=page --post_status=publish --post_title="$conc_titulo secciones" --porcelain)"
conc_borrador_id="$(wp post create --post_type=page --post_status=draft --post_title="$conc_titulo borrador" --post_content="base" --porcelain)"
conc_publicar_id="$(wp post create --post_type=page --post_status=publish --post_title="$conc_titulo publicar" --post_content="base" --porcelain)"
conc_bloq_id="$(wp post create --post_type=page --post_status=publish --post_title="$conc_titulo bloqueo" --post_content="base" --porcelain)"
[ -n "$conc_cinco_id" ] && [ -n "$conc_campos_id" ] && [ -n "$conc_secciones_id" ] && [ -n "$conc_borrador_id" ] && [ -n "$conc_publicar_id" ] && [ -n "$conc_bloq_id" ] || { echo "FALLA: no se pudieron crear las páginas de concurrencia"; exit 1; }
wp post meta update "$conc_secciones_id" _elementor_edit_mode builder >/dev/null
wp post meta update "$conc_secciones_id" _elementor_version "$version_elementor" >/dev/null
wp post meta update "$conc_secciones_id" _elementor_data "$v3_tree" >/dev/null
echo "Páginas publicadas: cinco $conc_cinco_id, campos $conc_campos_id, secciones $conc_secciones_id"

python3 -I - "$suffix" "$conc_cinco_id" "$conc_campos_id" "$conc_secciones_id" "$conc_borrador_id" "$conc_publicar_id" "$conc_bloq_id" <<'PY'
import importlib.util, json, os, pathlib, select, subprocess, sys, threading, time

suffix, cinco_id, campos_id, secciones_id = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
borrador_id, publicar_id, bloq_id = int(sys.argv[5]), int(sys.argv[6]), int(sys.argv[7])
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
OCUPADO = "Otra edición de este contenido está en curso."

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def llamar(corto, argumentos):
    """Una llamada MCP sin imprimir (corre en un hilo): (es_error, texto)."""
    _, out = cli.rpc("tools/call", {"name": cli.tool(corto), "arguments": argumentos})
    res = out.get("result", out.get("error"))
    texto = "".join(c.get("text", "") for c in res["content"]) if "content" in res else json.dumps(res, ensure_ascii=False)
    return bool(res.get("isError")) or "content" not in res, texto

def en_paralelo(corto, lista):
    """Dispara todas las llamadas a la vez; devuelve [(args, es_error, texto)] en el mismo orden."""
    barrera = threading.Barrier(len(lista))
    salida = [None] * len(lista)
    def hilo(i, args):
        barrera.wait()
        try:
            salida[i] = (args, *llamar(corto, args))
        except Exception as err:  # noqa: BLE001
            salida[i] = (args, True, f"excepción del cliente: {err}")
    hilos = [threading.Thread(target=hilo, args=(i, a)) for i, a in enumerate(lista)]
    for h in hilos: h.start()
    for h in hilos: h.join()
    for args, error, texto in salida:
        print(f"  {corto} {json.dumps({k: v for k, v in args.items() if k != 'elements'}, ensure_ascii=False)[:120]} -> {'ERROR ' if error else ''}{texto[:110]}")
    return salida

def clasificar(salida):
    """Cada llamada: éxito (dict con id) o «ocupado»; cualquier otra cosa es falla."""
    ok, ocupadas = [], 0
    for args, error, texto in salida:
        if not error:
            ok.append((args, json.loads(texto)))
        else:
            assert OCUPADO in texto, f"error que no es «ocupado»: {texto[:300]}"
            ocupadas += 1
    return ok, ocupadas

def una_copia(original, ok):
    copias = script("--copias", original).split()
    assert len(copias) == 1, f"debía existir una sola copia y hay {len(copias)}: {copias}"
    for args, d in ok:
        assert str(d["id"]) == copias[0] and d["draft_of"] == original, f"una llamada exitosa cayó en otra copia: {d} (copia {copias[0]})"
    assert script("--estado", original) == "publish"
    return copias[0]

def sin_bloqueos():
    assert script("--bloqueos") == "", "quedaron bloqueos en la base"

cinco = {}
def cinco_update():
    salida = en_paralelo("update-page", [{"id": cinco_id, "title": f"Titulo concurrente {i} {suffix}", "content": f"<p>Contenido concurrente {i} {suffix}</p>"} for i in range(5)])
    ok, ocupadas = clasificar(salida)
    print(f"  éxitos: {len(ok)}, ocupado: {ocupadas}")
    assert ok, "ninguna llamada tuvo éxito"
    copia = una_copia(cinco_id, ok)
    # La copia conserva una edición completa que tuvo éxito (el último que escribió), nunca una mezcla.
    titulo, contenido = script("--campo", copia, "post_title"), script("--campo", copia, "post_content")
    assert any(a["title"] == titulo and a["content"] == contenido for a, _ in ok), (titulo, contenido)
    assert script("--campo", cinco_id, "post_content") == "base", "el original cambió"
    cinco["copia"] = copia
comprobar("update-page x5: una sola copia, todas en ella o «ocupado», estado final de una edición", cinco_update)
comprobar("update-page x5: sin bloqueos pendientes", sin_bloqueos)

def campos_update():
    pedidos = [
        {"id": campos_id, "title": f"Titulo campos {suffix}"},
        {"id": campos_id, "content": f"<p>Contenido campos {suffix}</p>"},
        {"id": campos_id, "excerpt": f"Extracto campos {suffix}"},
    ]
    ok, ocupadas = clasificar(en_paralelo("update-page", pedidos))
    print(f"  éxitos: {len(ok)}, ocupado: {ocupadas}")
    assert ok, "ninguna llamada tuvo éxito"
    copia = una_copia(campos_id, ok)
    campo = {"title": "post_title", "content": "post_content", "excerpt": "post_excerpt"}
    for args, _ in ok:
        clave = next(k for k in campo if k in args)
        assert script("--campo", copia, campo[clave]) == args[clave], f"se perdió la edición de «{clave}» que devolvió éxito"
comprobar("update-page x3 en campos distintos: ninguna edición con éxito se pierde", campos_update)
comprobar("update-page x3: sin bloqueos pendientes", sin_bloqueos)

def secciones_edit():
    def caja(texto):
        return {"type": "e-flexbox", "elements": [{"type": "e-heading", "settings": {
            "title": {"$$type": "escaped-html", "value": texto}, "tag": {"$$type": "string", "value": "h2"}}}]}
    pedidos = [{"id": secciones_id, "operation": "insert", "node_id": "sec3001", "position": "after", "elements": [caja(f"Bloque concurrente {i} {suffix}")]} for i in range(5)]
    ok, ocupadas = clasificar(en_paralelo("edit-elementor-section", pedidos))
    print(f"  éxitos: {len(ok)}, ocupado: {ocupadas}")
    assert ok, "ninguna llamada tuvo éxito"
    copia = una_copia(secciones_id, ok)
    datos = script("--meta", copia, "_elementor_data")
    for args, d in ok:
        texto = args["elements"][0]["elements"][0]["settings"]["title"]["value"]
        assert texto in datos, f"se perdió el bloque con éxito «{texto}»"
        for nodo in d["node_ids"]:
            assert nodo in datos, f"falta el nodo {nodo} que devolvió éxito"
    assert "sec3000" in datos and "sec3001" in datos, "faltan secciones originales"
    assert "Bloque concurrente" not in script("--meta", secciones_id, "_elementor_data"), "el original cambió"
comprobar("edit-elementor-section x5: una sola copia, ningún bloque con éxito se pierde", secciones_edit)
comprobar("edit-elementor-section x5: sin bloqueos pendientes", sin_bloqueos)

CAMPO = {"title": "post_title", "content": "post_content", "excerpt": "post_excerpt"}

def tres_campos(destino, etiqueta):
    """3 update-page a la vez, cada una con un campo distinto: ninguno con éxito puede perderse."""
    pedidos = [
        {"id": destino, "title": f"Titulo {etiqueta} {suffix}"},
        {"id": destino, "content": f"<p>Contenido {etiqueta} {suffix}</p>"},
        {"id": destino, "excerpt": f"Extracto {etiqueta} {suffix}"},
    ]
    ok, ocupadas = clasificar(en_paralelo("update-page", pedidos))
    print(f"  éxitos: {len(ok)}, ocupado: {ocupadas}")
    assert ok, "ninguna llamada tuvo éxito"
    return ok

def borrador_tres_campos():
    ok = tres_campos(borrador_id, "borrador")
    assert script("--copias", borrador_id) == "", "un borrador no debe tener copia"
    assert script("--estado", borrador_id) == "draft"
    for args, d in ok:
        clave = next(k for k in CAMPO if k in args)
        assert script("--campo", borrador_id, CAMPO[clave]) == args[clave], f"se perdió «{clave}» (devolvió éxito)"
comprobar("S2-a update-page x3 (título/contenido/extracto) sobre un borrador: ningún campo con éxito se pierde", borrador_tres_campos)

def copia_por_su_id():
    copia = script("--copias", campos_id).split()[0]
    pedidos = [
        {"id": int(copia), "title": f"Titulo por copia {suffix}"},
        {"id": int(copia), "content": f"<p>Contenido por copia {suffix}</p>"},
        {"id": int(copia), "excerpt": f"Extracto por copia {suffix}"},
    ]
    ok, ocupadas = clasificar(en_paralelo("update-page", pedidos))
    print(f"  éxitos: {len(ok)}, ocupado: {ocupadas}")
    assert ok, "ninguna llamada tuvo éxito"
    assert script("--copias", campos_id).split() == [copia], "cambió la cantidad de copias"
    for args, _ in ok:
        clave = next(k for k in CAMPO if k in args)
        assert script("--campo", copia, CAMPO[clave]) == args[clave], f"se perdió «{clave}» en la copia (devolvió éxito)"
comprobar("S2-a update-page x3 por el ID de una copia existente: ningún campo con éxito se pierde", copia_por_su_id)
comprobar("S2-a: sin bloqueos pendientes", sin_bloqueos)

def publicar_con_copia_cambiada():
    esp, d = llamar("update-page", {"id": publicar_id, "content": "<p>v1</p>"})
    assert not esp, d
    copia = json.loads(d)["id"]
    php = (r'\NexoDev\NexoMCP\Support\PostLock::run( %d, function () { echo "LOCKED\n"; sleep( 3 );'
           r' wp_update_post( array( "ID" => %d, "post_content" => "<p>v2</p>" ) ); } );') % (publicar_id, copia)
    # Mismo comando que tools/wpcli.sh: con Elementor Pro, wp-cli necesita más memoria (NEXO_WPCLI_MEMORIA).
    memoria = os.environ.get("NEXO_WPCLI_MEMORIA", "")
    wp = ["--entrypoint", "php", "setup", "-d", f"memory_limit={memoria}", "/usr/local/bin/wp"] if memoria else ["--entrypoint", "wp", "setup"]
    proc = subprocess.Popen(["docker", "compose", "run", "--rm", "-T", *wp, "eval", php],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
    try:
        listo, _, _ = select.select([proc.stdout], [], [], 90)
        assert listo and "LOCKED" in proc.stdout.readline(), "el proceso de fondo no obtuvo el bloqueo"
        # La copia ya estaba cargada para quien publica; el cambio v2 llega mientras espera el bloqueo.
        inicio = time.time()
        error, texto = llamar("set-content-status", {"id": copia, "status": "publish"})
        print(f"  espera: {time.time() - inicio:.1f} s")
        assert not error, f"publicar debía tener éxito: {texto[:300]}"
    finally:
        proc.wait(timeout=60)
    contenido = script("--campo", publicar_id, "post_content")
    assert "v2" in contenido and "v1" not in contenido, f"el original quedó con {contenido!r}, no con lo último de la copia"
    assert script("--estado", copia) == "", "la copia debía haberse fusionado y borrado"
comprobar("S2-b publicar mientras otro proceso cambia la copia: el original queda con lo último (v2)", publicar_con_copia_cambiada)
comprobar("S2-b: sin bloqueos pendientes", sin_bloqueos)

def bloqueos_ajenos():
    ahora = int(time.time())
    try:
        # Vigente: ocupado, no se escribe nada y no se crea copia.
        script("--poner-bloqueo", bloq_id, f"otro|{ahora + 120}")
        inicio = time.time()
        error, texto = llamar("update-page", {"id": bloq_id, "content": "<p>no debe escribirse</p>"})
        espera = time.time() - inicio
        assert error and OCUPADO in texto, f"debía responder «ocupado»: {texto[:300]}"
        assert 3 <= espera <= 20, f"la espera fue de {espera:.1f} s (se esperaba ~5)"
        assert script("--copias", bloq_id) == "", "se creó una copia pese al bloqueo"
        assert script("--campo", bloq_id, "post_content") == "base"
        assert script("--bloqueos") == f"nexo_mcp_lock_{bloq_id}", "el bloqueo ajeno debía seguir ahí"
        # Vencido: se toma y se libera.
        script("--poner-bloqueo", bloq_id, f"otro|{ahora - 5}")
        error, texto = llamar("update-page", {"id": bloq_id, "content": "<p>tras vencido</p>"})
        assert not error, f"un bloqueo vencido debía tomarse: {texto[:300]}"
        copia = script("--copias", bloq_id)
        assert len(copia.split()) == 1 and script("--bloqueos") == "", (copia, script("--bloqueos"))
        # Mal formado: también se toma, esta vez por el ID de la copia.
        script("--poner-bloqueo", bloq_id, "basura")
        error, texto = llamar("update-page", {"id": int(copia), "excerpt": "tras mal formado"})
        assert not error, f"un bloqueo mal formado debía tomarse: {texto[:300]}"
        assert script("--campo", int(copia), "post_excerpt") == "tras mal formado" and script("--bloqueos") == ""
        # Vigente, por el ID de la copia: compite por el mismo bloqueo (clave = original).
        script("--poner-bloqueo", bloq_id, f"otro|{ahora + 120}")
        error, texto = llamar("update-page", {"id": int(copia), "excerpt": "no debe escribirse"})
        assert error and OCUPADO in texto, f"por la copia debía responder «ocupado»: {texto[:300]}"
        assert script("--campo", int(copia), "post_excerpt") == "tras mal formado"
    finally:
        script("--quitar-bloqueo", bloq_id)
comprobar("S2-c bloqueo vigente (ocupado, nada escrito), vencido y mal formado (se toman), también por el ID de la copia", bloqueos_ajenos)
comprobar("S2-c: sin bloqueos pendientes", sin_bloqueos)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de concurrencia OK.")
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "la prueba de concurrencia tuvo comprobaciones fallidas"

echo
echo "### Herramientas de constructor viejas: página publicada → copia de trabajo, borrador → en su sitio"
# Una página publicada por herramienta (cada una arranca sin copia) y un borrador para las cuatro.
# El título lleva el sufijo: la limpieza barre las páginas y sus copias.
viejas_tree="$(printf '[{"id":"sec7000","elType":"section","settings":{"gap":"default"},"elements":[{"id":"col7000","elType":"column","settings":{"_column_size":100},"elements":[{"id":"hd7000","elType":"widget","widgetType":"heading","settings":{"title":"Titulo viejas %s"},"elements":[]},{"id":"tx7000","elType":"widget","widgetType":"text-editor","settings":{"editor":"<p>Texto viejas</p>"},"elements":[]},{"id":"bt7000","elType":"widget","widgetType":"button","settings":{"text":"Boton viejas","link":{"url":"https://example.com/viejas","is_external":"","nofollow":""}},"elements":[]}]}]}]' "$suffix")"
viejas_ids=""
for tipo in texto atributo estructura restaurar vacia borrador deshacer; do
	if [ "$tipo" = "borrador" ]; then estado=draft; else estado=publish; fi
	id="$(wp post create --post_type=page --post_status=$estado --post_title="Prueba viejas $tipo elementor-mcp-$suffix" --porcelain)"
	[ -n "$id" ] || { echo "FALLA: no se pudo crear la página de prueba ($tipo)"; exit 1; }
	wp post meta update "$id" _elementor_edit_mode builder >/dev/null
	wp post meta update "$id" _elementor_version "$version_elementor" >/dev/null
	wp post meta update "$id" _elementor_data "$viejas_tree" >/dev/null
	viejas_ids="$viejas_ids $id"
done
echo "Páginas (texto, atributo, estructura, restaurar, sin respaldo, borrador, deshacer):$viejas_ids"

python3 -I - "$suffix" $viejas_ids <<'PY'
import importlib.util, json, pathlib, subprocess, sys

suffix = sys.argv[1]
p_texto, p_attr, p_estr, p_rest, p_vacia, p_borr, p_desh = (int(x) for x in sys.argv[2:9])
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]

def comprobar(nombre, fn):
    total[0] += 1
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *map(str, args)], capture_output=True, text=True).stdout.strip()

def crudo(i):
    return script("--meta", i, "_elementor_data")

def copias(i):
    return script("--copias", i).split()

def nodos(i, salida=None, items=None):
    salida = {} if salida is None else salida
    for n in (json.loads(crudo(i)) if items is None else items):
        salida[n["id"]] = n
        nodos(i, salida, n.get("elements", []))
    return salida

def diff_nombra(original, copia):
    _, d = cli.ok("get-working-copy-diff", {"id": original})
    assert d["copy_id"] == copia and d["original_id"] == original, d
    assert any(e["key"] == "_elementor_data" for e in d["meta"]["changed"]), d

def publicar(original, copia):
    antes = json.loads(crudo(copia))
    _, m = cli.ok("set-content-status", {"id": copia, "status": "publish"})
    assert (m["id"], m["merged_from"]) == (original, copia), m
    assert json.loads(crudo(original)) == antes, "el original no trae el árbol de la copia"
    assert script("--estado", copia) == "", "la copia debía desaparecer"

URL = f"https://example.com/viejas-{suffix}"
TEXTO = f"Texto de la copia {suffix}"

def en_copia(pagina, herramienta, args, cambio):
    """Primera edición de una publicada: crea la copia, el original queda igual, la copia cambia."""
    original = crudo(pagina)
    d = cli.ok(herramienta, {"id": pagina, **args})[1]
    assert d["draft_of"] == pagina and d["id"] != pagina, d
    copia = d["id"]
    assert copias(pagina) == [str(copia)], copias(pagina)
    assert crudo(pagina) == original, "el original cambió"
    assert script("--estado", pagina) == "publish" and script("--estado", copia) == "draft"
    assert crudo(copia) != original, "la copia no cambió"
    cambio(nodos(copia), d)
    diff_nombra(pagina, copia)
    # Volver a llamar por el id de la copia sigue en la copia, sin crear otra.
    again = cli.ok(herramienta, {"id": copia, **args})[1]
    assert again["id"] == copia and again["draft_of"] == pagina and copias(pagina) == [str(copia)], again
    assert crudo(pagina) == original
    return copia, d

def texto_publicada():
    def cambio(n, d):
        assert n["hd7000"]["settings"]["title"] == TEXTO, n["hd7000"]
    copia, _ = en_copia(p_texto, "update-node-text", {"node_id": "hd7000", "text": TEXTO}, cambio)
    publicar(p_texto, copia)
    assert nodos(p_texto)["hd7000"]["settings"]["title"] == TEXTO
comprobar("update-node-text en publicada: copia, original intacto, diff, por id de la copia, publicar fusiona", texto_publicada)

def attr_publicada():
    def cambio(n, d):
        assert n["bt7000"]["settings"]["link"]["url"] == URL, n["bt7000"]
    copia, _ = en_copia(p_attr, "update-node-attribute", {"node_id": "bt7000", "attribute": "link", "value": URL}, cambio)
    publicar(p_attr, copia)
    assert nodos(p_attr)["bt7000"]["settings"]["link"]["url"] == URL
comprobar("update-node-attribute en publicada: copia, original intacto, diff, por id de la copia, publicar fusiona", attr_publicada)

def estr_publicada():
    def cambio(n, d):
        assert len(d["created"]) == 1 and d["created"][0]["id"] in n and d["created"][0]["id"] != "hd7000", d
    copia, d = en_copia(p_estr, "edit-structure", {"node_id": "hd7000"}, cambio)
    publicar(p_estr, copia)
    assert len([k for k, v in nodos(p_estr).items() if v.get("widgetType") == "heading"]) >= 2
comprobar("edit-structure en publicada: copia, original intacto, diff, por id de la copia, publicar fusiona", estr_publicada)

def restaurar_publicada():
    # Una edición publicada deja el respaldo del original (estado previo a la fusión).
    inicial = crudo(p_rest)
    d = cli.ok("update-node-text", {"id": p_rest, "node_id": "hd7000", "text": TEXTO})[1]
    publicar(p_rest, d["id"])
    editado = crudo(p_rest)
    assert editado != inicial
    # Restaurar sobre la publicada es una edición más: va a la copia.
    r = cli.ok("restore-builder-backup", {"id": p_rest})[1]
    assert r["restored"] is True and r["draft_of"] == p_rest and r["id"] != p_rest, r
    assert crudo(p_rest) == editado, "el original cambió"
    assert json.loads(crudo(r["id"])) == json.loads(inicial), "la copia no trae el estado respaldado"
    assert copias(p_rest) == [str(r["id"])]
    diff_nombra(p_rest, r["id"])
    # La copia nueva no tenía ediciones que respaldar: restaurar el respaldo del original en ella no
    # captura antes de escribir. Un reintento (ya hay copia, sin respaldo propio) no cambia nada.
    estado = crudo(r["id"])
    error, texto, _ = cli.call("restore-builder-backup", {"id": p_rest})
    assert error and crudo(r["id"]) == estado and crudo(p_rest) == editado, f"el reintento debía rechazarse sin cambiar nada: {texto[:200]}"
    publicar(p_rest, r["id"])
comprobar("restore-builder-backup en publicada sin copia: restaura el respaldo del original en una copia nueva; el reintento no cambia nada", restaurar_publicada)

def deshacer_por_el_original():
    # S6a/b: con una publicada que ya tiene copia, restaurar por el id del ORIGINAL deshace la
    # última edición de la copia (igual que por el id de la copia) y restaurar dos veces deja lo mismo.
    primero, segundo = f"Primera edicion {suffix}", f"Segunda edicion {suffix}"
    original = crudo(p_desh)
    cli.ok("update-node-text", {"id": p_desh, "node_id": "hd7000", "text": primero})
    d = cli.ok("update-node-text", {"id": p_desh, "node_id": "hd7000", "text": segundo})[1]
    copia = d["id"]
    assert copias(p_desh) == [str(copia)] and nodos(copia)["hd7000"]["settings"]["title"] == segundo
    r = cli.ok("restore-builder-backup", {"id": p_desh})[1]
    assert r["restored"] is True and r["id"] == copia and r["draft_of"] == p_desh, r
    tras_primera = crudo(copia)
    assert nodos(copia)["hd7000"]["settings"]["title"] == primero, "debía deshacer sólo la última edición de la copia"
    assert crudo(p_desh) == original and copias(p_desh) == [str(copia)], "el original o las copias cambiaron"
    # Restaurar dos veces: mismo estado y sin perder la primera edición.
    r2 = cli.ok("restore-builder-backup", {"id": p_desh})[1]
    assert r2 == r and crudo(copia) == tras_primera, "el reintento por el original cambió el estado"
    r3 = cli.ok("restore-builder-backup", {"id": copia})[1]
    assert r3 == r and crudo(copia) == tras_primera, "restaurar por el id de la copia debe dar lo mismo"
    assert nodos(copia)["hd7000"]["settings"]["title"] == primero
    assert crudo(p_desh) == original, "el original no debe cambiar"
    diff_nombra(p_desh, copia)
comprobar("restore-builder-backup por el id del original con copia: deshace la última edición de la copia; dos veces deja el mismo estado", deshacer_por_el_original)

def borrador_en_su_sitio():
    for herramienta, args, claves in (
        ("update-node-text", {"node_id": "hd7000", "text": TEXTO}, {"id", "builder", "node_id", "updated", "backup"}),
        ("update-node-attribute", {"node_id": "bt7000", "attribute": "link", "value": URL}, {"id", "builder", "node_id", "attribute", "updated", "backup"}),
        ("edit-structure", {"node_id": "tx7000"}, {"id", "created", "backup"}),
        ("restore-builder-backup", {}, {"id", "restored"}),
    ):
        antes = crudo(p_borr)
        d = cli.ok(herramienta, {"id": p_borr, **args})[1]
        assert d["id"] == p_borr and "draft_of" not in d and set(d) == claves, d
        assert copias(p_borr) == [] and crudo(p_borr) != antes, herramienta
        assert script("--estado", p_borr) == "draft"
comprobar("las cuatro herramientas sobre un borrador editan en su sitio, sin copia y con los campos de siempre", borrador_en_su_sitio)

def rechazos():
    for herramienta, args, nota in (
        ("update-node-text", {"node_id": "no-existe", "text": "x"}, "nodo inexistente"),
        ("update-node-attribute", {"node_id": "no-existe", "attribute": "link", "value": URL}, "nodo inexistente"),
        ("update-node-attribute", {"node_id": "hd7000", "attribute": "alt", "value": "x"}, "atributo no admitido"),
        ("update-node-attribute", {"node_id": "bt7000", "attribute": "link", "value": "javascript:alert(1)"}, "valor inválido"),
        ("edit-structure", {"node_id": "no-existe"}, "nodo inexistente"),
        ("edit-structure", {"node_id": "hd7000", "target": "sec7000"}, "destino que no cabe"),
        ("restore-builder-backup", {}, "sin respaldo"),
    ):
        antes, paginas = crudo(p_vacia), script("--contar-paginas")
        error, texto, _ = cli.call(herramienta, {"id": p_vacia, **args})
        assert error, f"{herramienta} ({nota}) debía rechazarse: {texto[:200]}"
        assert copias(p_vacia) == [] and script("--contar-paginas") == paginas and crudo(p_vacia) == antes, f"{herramienta} ({nota}) dejó rastro"
    assert script("--meta", p_vacia, "_nexo_mcp_builder_backup") == "", "un rechazo no debe crear respaldo"
comprobar("cada rechazo (nodo, atributo, valor, destino, sin respaldo) no crea copia ni cambia nada", rechazos)

print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de las herramientas de constructor OK.")
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "las herramientas de constructor sobre publicadas/borradores tuvieron comprobaciones fallidas"

echo
echo "### Sistema de diseño: ElementorDesignStore (ida y vuelta) y undo-design-system-change"
# El almacén real sobre el Elementor del docker: foto → mutar → restaurar, para clases,
# variables y Kit. Cada tipo se restaura en un `finally`, así que un fallo a mitad no deja
# el sitio mutado. Nada de esto toca lo que el sitio ya tenía.
diseno_respaldo_previo="$(php_wpr 'echo false === get_option( "nexo_mcp_design_system_backup", false ) ? "no" : "si";')"
viaje="$(php_wp '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$id = (int) $kit->get_main_id();
$out = array();

$antes = $store->snapshot( "classes" );
try {
	$items = $antes["items"];
	$orden = $antes["order"];
	$items["g-e2ediseno"] = array( "id" => "g-e2ediseno", "type" => "class", "label" => "e2e-diseno", "variants" => array() );
	$orden[] = "g-e2ediseno";
	\Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit )->put( $items, $orden );
	$durante = $store->snapshot( "classes" );
	$out["classes_mutada"] = isset( $durante["items"]["g-e2ediseno"] ) ? "si" : "no";
} finally {
	$store->restore( "classes", $antes );
}
$despues = $store->snapshot( "classes" );
$out["classes"] = $antes === $despues ? "igual" : "distinto";
$out["classes_ids"] = array_keys( $antes["items"] ) === array_keys( $despues["items"] ) && $antes["order"] === $despues["order"] ? "iguales" : "distintos";

$antes = $store->snapshot( "variables" );
try {
	$registro = array() === $antes ? array( "data" => array(), "watermark" => 0, "version" => 1 ) : $antes;
	$registro["data"]["e-gv-e2ediseno"] = array( "type" => "global-color-variable", "label" => "e2e-diseno", "value" => "#123456" );
	$registro["watermark"] = (int) ( $registro["watermark"] ?? 0 ) + 1;
	$kit->update_json_meta( "_elementor_global_variables", $registro );
	$durante = $store->snapshot( "variables" );
	$out["variables_mutada"] = isset( $durante["data"]["e-gv-e2ediseno"] ) ? "si" : "no";
} finally {
	$store->restore( "variables", $antes );
}
$despues = $store->snapshot( "variables" );
$out["variables"] = ( $antes["data"] ?? array() ) === ( $despues["data"] ?? array() ) ? "igual" : "distinto";
$out["variables_marca"] = array() === $antes
	? ( array() === $despues ? "ok" : "mal" )
	: ( (int) $despues["watermark"] > (int) $durante["watermark"] ? "ok" : "mal" );

$antes = $store->snapshot( "kit" );
$crudo_antes = get_post_meta( $id, "_elementor_page_settings", true );
try {
	$ajustes = is_array( $crudo_antes ) ? $crudo_antes : array();
	$ajustes["custom_colors"] = array_merge( $ajustes["custom_colors"] ?? array(), array( array( "_id" => "e2edis1", "title" => "e2e", "color" => "#123456" ) ) );
	update_metadata( "post", $id, "_elementor_page_settings", wp_slash( $ajustes ) );
	$durante = $store->snapshot( "kit" );
	$out["kit_mutado"] = in_array( "e2edis1", array_column( $durante["custom_colors"] ?? array(), "_id" ), true ) ? "si" : "no";
} finally {
	$store->restore( "kit", $antes );
}
$out["kit"] = $antes === $store->snapshot( "kit" ) ? "igual" : "distinto";
$out["kit_resto"] = $crudo_antes == get_post_meta( $id, "_elementor_page_settings", true ) ? "igual" : "distinto";
$store->clear_cache();
foreach ( $out as $clave => $valor ) { echo $clave . "=" . $valor . "\n"; }')"
dv() { printf '%s\n' "$viaje" | sed -n "s/^$1=//p"; }
for tipo in classes variables kit; do
	case "$tipo" in classes) mut=classes_mutada ;; variables) mut=variables_mutada ;; *) mut=kit_mutado ;; esac
	check_eq "diseño: $tipo cambió durante la prueba" "si" "$(dv $mut)"
	check_eq "diseño: $tipo igual tras restaurar" "igual" "$(dv $tipo)"
done
check_eq "diseño: clases conservan ids y orden" "iguales" "$(dv classes_ids)"
check_eq "diseño: variables con marca de agua mayor tras restaurar" "ok" "$(dv variables_marca)"
check_eq "diseño: resto de ajustes del Kit intacto" "igual" "$(dv kit_resto)"

# Restaurar clases tras un borrado publicado: la clase vuelve también al orden de la vista previa
# del editor, y la clase que nadie tocó conserva su borrador (meta de vista previa) tal cual.
viaje="$(php_wp '
$store = new \NexoDev\NexoMCP\Builders\ElementorDesignStore();
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit );
$prev = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit )->set_preview( true );
$a = "g-e2eborra";
$b = "g-e2eintac";
$clase = static fn( $id ) => array( "id" => $id, "type" => "class", "label" => "e2e-" . $id, "variants" => array() );
$meta = "_elementor_global_class_data_preview";
$out = array();
// El orden de las claves de `items` depende de la consulta (fecha de creación), no importa: se compara el contenido.
$igual = static fn( $x, $y ) => $x["order"] === $y["order"] && $x["items"] == $y["items"];
$base = $store->snapshot( "classes" );
try {
	$items = $base["items"]; $orden = $base["order"];
	foreach ( array( $a, $b ) as $id ) { $items[ $id ] = $clase( $id ); $orden[] = $id; }
	$repo->apply_changes( array( $a => $items[ $a ], $b => $items[ $b ] ), array( "added" => array( $a, $b ), "deleted" => array(), "modified" => array(), "order" => true ), $orden );
	$ids = \Elementor\Modules\GlobalClasses\Global_Classes_Post_IDs::make( $kit )->get_post_ids( array( $b ) );
	$post_b = (int) $ids[ $b ];
	update_post_meta( $post_b, $meta, array( "e2e" => "borrador sin publicar" ) );
	$meta_antes = get_post_meta( $post_b, $meta, true );
	$foto = $store->snapshot( "classes" );

	$sin = array_values( array_diff( $foto["order"], array( $a ) ) );
	$repo->apply_changes( array(), array( "added" => array(), "deleted" => array( $a ), "modified" => array(), "order" => true ), $sin );
	$out["borrada"] = in_array( $a, $prev->get_order(), true ) || isset( $store->snapshot( "classes" )["items"][ $a ] ) ? "no" : "si";

	$store->restore( "classes", $foto );
	$out["publicada"] = isset( $store->snapshot( "classes" )["items"][ $a ] ) && in_array( $a, $store->snapshot( "classes" )["order"], true ) ? "si" : "no";
	$out["vista_previa"] = in_array( $a, $prev->get_order(), true ) ? "si" : "no";
	$out["igual"] = $igual( $foto, $store->snapshot( "classes" ) ) ? "igual" : "distinto";
	$out["borrador_intacto"] = $meta_antes === get_post_meta( $post_b, $meta, true ) ? "igual" : "distinto";

	// Una restauración sin diferencias no toca nada (tampoco el borrador de la clase sin tocar).
	$store->restore( "classes", $foto );
	$out["sin_cambios"] = $meta_antes === get_post_meta( $post_b, $meta, true ) && $igual( $foto, $store->snapshot( "classes" ) ) ? "igual" : "distinto";
} finally {
	$ahora = $store->snapshot( "classes" );
	$quitar = array_values( array_intersect( array( $a, $b ), array_keys( $ahora["items"] ) ) );
	if ( array() !== $quitar ) {
		$repo->apply_changes( array(), array( "added" => array(), "deleted" => $quitar, "modified" => array(), "order" => true ), array_values( array_diff( $ahora["order"], $quitar ) ) );
	}
	$store->restore( "classes", $base );
}
$out["limpio"] = $igual( $base, $store->snapshot( "classes" ) ) && ! in_array( $a, $prev->get_order(), true ) && ! in_array( $b, $prev->get_order(), true ) ? "si" : "no";
$store->clear_cache();
foreach ( $out as $clave => $valor ) { echo $clave . "=" . $valor . "\n"; }')"
check_eq "diseño: la clase borrada (publicada) se quitó antes de restaurar" "si" "$(dv borrada)"
check_eq "diseño: restaurar devuelve la clase a lo publicado" "si" "$(dv publicada)"
check_eq "diseño: restaurar devuelve la clase al orden de la vista previa del editor" "si" "$(dv vista_previa)"
check_eq "diseño: clases iguales tras restaurar (apply_changes)" "igual" "$(dv igual)"
check_eq "diseño: el borrador de la clase sin tocar queda intacto" "igual" "$(dv borrador_intacto)"
check_eq "diseño: restaurar sin diferencias no toca nada" "igual" "$(dv sin_cambios)"
check_eq "diseño: limpieza de las clases de prueba" "si" "$(dv limpio)"

if [ "$diseno_respaldo_previo" = "si" ]; then
	echo "AVISO: el sitio ya tenía un respaldo del sistema de diseño; no se prueba undo-design-system-change para no deshacerlo."
else
python3 -I - <<'PY'
import importlib.util, pathlib, subprocess, sys

spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []

def comprobar(nombre, fn):
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err}")
        fallas.append(nombre)

def script(*args):
    return subprocess.run(["tools/prueba_adaptadores.sh", *args], capture_output=True, text=True).stdout.strip()

def sin_respaldo():
    antes = script("--diseno-estado")
    assert len(antes) == 32, f"huella del estado: {antes!r}"
    for _ in range(2):  # deshacer dos veces seguidas no cambia más
        error, texto, _ = cli.call("undo-design-system-change", {"confirmation": "UNDO"})
        assert error and "No hay ningún cambio del sistema de diseño para deshacer." in texto, texto[:300]
        assert script("--diseno-estado") == antes, "deshacer sin respaldo cambió el estado"
comprobar("undo-design-system-change sin respaldo: error y nada cambia (dos veces)", sin_respaldo)
sys.exit(1 if fallas else 0)
PY
[ $? -eq 0 ] || falla "undo-design-system-change tuvo comprobaciones fallidas"
fi
[ "$(php_wpr 'echo false === get_option( "nexo_mcp_design_system_backup", false ) ? "no" : "si";')" = "$diseno_respaldo_previo" ] \
	&& ok "diseño: el sitio quedó sin respaldo nuevo" || falla "diseño: quedó un respaldo que no existía"


echo
echo "### Sistema de diseño: herramientas de escritura por MCP (clases, variables, colores y tipografía del Kit)"
# Cada herramienta se prueba contra el Elementor real: el cambio se ve en get-design-system y en lo guardado, y
# undo-design-system-change devuelve la huella exacta del estado anterior. Los rechazos no cambian nada. Antes se
# guarda una foto del estado y al final se restaura (también si la prueba se corta a medias: lo hace cleanup), así
# que el sitio queda como estaba, sin respaldo ni bloqueo. Se activa el permiso destructivo sólo durante el bloque.
if [ "$diseno_respaldo_previo" = "si" ]; then
	echo "AVISO: el sitio ya tenía un respaldo del sistema de diseño; no se prueban las herramientas de escritura para no pisarlo."
else
	echo "Restos de corridas cortadas: $(tools/prueba_adaptadores.sh --diseno-barrer "$suffix" "$class_id" "$variable_id")"
	destructivo_previo="$(tools/prueba_adaptadores.sh --ajuste-valor destructive_enabled)"
	tools/prueba_adaptadores.sh --diseno-foto >/dev/null
	diseno_foto_tomada=1
	huella_inicio="$(tools/prueba_adaptadores.sh --diseno-huella)"
	echo "Huella inicial: $huella_inicio"
	tools/prueba_adaptadores.sh --ajuste destructive_enabled true
python3 -I tools/prueba_diseno_escritura.py "$suffix"
	[ $? -eq 0 ] || falla "las herramientas de escritura del sistema de diseño tuvieron comprobaciones fallidas"
	tools/prueba_adaptadores.sh --ajuste destructive_enabled "$destructivo_previo"
	echo "Restauración del estado: $(restaurar_diseno)"
	diseno_foto_tomada=""
	check_eq "diseño: la huella final es la inicial" "$huella_inicio" "$(tools/prueba_adaptadores.sh --diseno-huella)"
	check_eq "diseño: el permiso destructivo quedó como estaba" "$destructivo_previo" "$(tools/prueba_adaptadores.sh --ajuste-valor destructive_enabled)"
	check_eq "diseño: sin bloqueos del sistema de diseño" "" "$(tools/prueba_adaptadores.sh --bloqueos)"
fi
echo
echo "### Verificación con wp-cli"
check_eq "Yoast título (_yoast_wpseo_title)" "$titulo_seo" "$(wpr post meta get "$page_id" _yoast_wpseo_title)"
check_eq "Yoast descripción (_yoast_wpseo_metadesc)" "$descripcion_seo" "$(wpr post meta get "$page_id" _yoast_wpseo_metadesc)"
check_eq "ACF $campo" "$valor_campo" "$(php_wpr 'echo get_field( getenv( "NEXO_A1" ), (int) getenv( "NEXO_A2" ) );' "$campo" "$page_id")"
check_eq "ACF $campo (post_meta)" "$valor_campo" "$(wpr post meta get "$page_id" "$campo")"

elementor="$(wpr post meta get "$page_id" _elementor_data)"
resultado="$(printf '%s' "$elementor" | python3 -I -c '
import json, sys
tree = json.load(sys.stdin)
nodos = {}
def walk(items):
    for e in items:
        nodos[e["id"]] = e
        walk(e.get("elements", []))
walk(tree)
print(nodos["aaaa0001"]["settings"].get("title", ""))
print(nodos["aaaa0003"]["settings"].get("link", {}).get("url", ""))
print(nodos["aaaa0002"]["settings"].get("editor", ""))
print(len(nodos))' 2>&1)"
check_eq "_elementor_data heading (update-node-text)" "$texto" "$(printf '%s\n' "$resultado" | sed -n 1p)"
check_eq "_elementor_data enlace restaurado (restore-builder-backup)" "$enlace_original" "$(printf '%s\n' "$resultado" | sed -n 2p)"
check_eq "_elementor_data widget sin tocar" "<p>Texto original</p>" "$(printf '%s\n' "$resultado" | sed -n 3p)"
check_eq "_elementor_data cantidad de nodos" "4" "$(printf '%s\n' "$resultado" | sed -n 4p)"

echo
if [ "$fallas" -eq 0 ]; then
	echo "OK: todos los adaptadores pasaron."
else
	echo "FALLA: $fallas comprobación(es) fallaron."
fi
# La limpieza corre por el trap; el código de salida refleja las fallas.
exit_code=$([ "$fallas" -eq 0 ] && echo 0 || echo 1)
trap - EXIT
limpiar_al_salir || exit_code=1
exit "$exit_code"
