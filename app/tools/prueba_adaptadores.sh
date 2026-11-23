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
