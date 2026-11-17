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
# 6. Limpia lo que creó (páginas a la papelera, recuperable; clase y variable
#    se quitan) y restaura los ajustes y el estado del experimento.
#
# Uso (desde app/):  tools/prueba_adaptadores.sh
set -uo pipefail

cd "$(dirname "$0")/.."
wp() { docker compose run --rm -T --entrypoint wp setup "$@" 2>/dev/null; }
# Lectura que reintenta una vez: wp-cli devuelve a veces vacío de forma transitoria.
wpr() { local out; out="$(wp "$@")"; [ -n "$out" ] || { sleep 2; out="$(wp "$@")"; }; printf '%s' "$out"; }

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

fallas=0
falla() { echo "FALLA: $*"; fallas=$((fallas + 1)); }
ok() { echo "OK: $*"; }

tools/instalar_plugins_prueba.sh || { echo "FALLA: no se pudieron instalar los plugins"; exit 1; }

original="$(wp option get nexo_mcp_settings --format=json)"
[ -n "$original" ] || { echo "FALLA: no se pudieron leer los ajustes"; exit 1; }
page_id=""
group_ids=""
atomic_page_id=""
class_id=""
variable_id=""
kit_id=""
exp_names="e_opt_in_v4 e_atomic_elements"
# Estado previo de cada experimento ("__sin__" si la opción no existía) y de las
# variables del Kit (base64 del meta crudo), para dejarlo exactamente como estaba.
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
	wp eval '
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit );
$all = $repo->all()->get();
unset( $all["items"]["'"$class_id"'"] );
$repo->put( $all["items"], array_values( array_diff( $all["order"], array( "'"$class_id"'" ) ) ) );
$prev = base64_decode( "'"$variables_prev"'" );
if ( "" === $prev ) { delete_post_meta( $kit->get_main_id(), "_elementor_global_variables" ); }
else { update_post_meta( $kit->get_main_id(), "_elementor_global_variables", wp_slash( $prev ) ); }' >/dev/null
}
cleanup() {
	echo
	echo "### Limpieza (a la papelera)"
	local fallidas=0 id
	# wp_trash_post() guarda el estado anterior, así que se restaura tal como
	# estaba. `wp post delete` no sirve: rechaza la papelera para tipos que no
	# son post/page, como los grupos y campos de ACF.
	for id in $page_id $atomic_page_id $group_ids; do
		wp eval "wp_trash_post( $id );" >/dev/null
		if [ "$(wpr post get "$id" --field=post_status)" = "trash" ]; then
			echo "Papelera: $id"
		else
			echo "FALLA: $id no quedó en la papelera"; fallidas=$((fallidas + 1))
		fi
	done
	remove_design_fixtures
	if [ -n "$class_id" ] && wpr eval 'echo json_encode( \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make()->all_labels() );' | grep -q "$class_id"; then
		echo "FALLA: la clase global $class_id sigue ahí"; fallidas=$((fallidas + 1))
	elif [ -n "$class_id" ]; then
		echo "Clase global y variable quitadas."
	fi
	restore_experiments || fallidas=$((fallidas + 1))
	restore || { echo "FALLA: no se pudieron restaurar los ajustes"; fallidas=$((fallidas + 1)); }
	[ "$fallidas" -eq 0 ]
}
trap cleanup EXIT

# Módulos necesarios activos y sin «Solo revisar».
needed="$(printf '%s' "$original" | python3 -I -c '
import json, sys
s = json.load(sys.stdin)
s.update(read_only=False, content_tools_enabled=True, seo_tools_enabled=True, builders_tools_enabled=True)
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
group_ids="$(wp eval '
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
	prev="$(wp eval "echo get_option( 'elementor_experiment-$name', '__sin__' );")"
	exp_prev="$exp_prev$name=$prev"$'\n'
	wp option update "elementor_experiment-$name" active >/dev/null
done
[ "$(wpr eval 'echo \Elementor\Plugin::$instance->experiments->is_feature_active( "e_atomic_elements" ) ? "si" : "no";')" = "si" ] \
	|| { echo "FALLA: no se pudo activar el experimento atómico"; exit 1; }
echo "Experimento atómico activo (estado previo: $(printf '%s' "$exp_prev" | tr '\n' ' '))"

kit_id="$(wpr eval 'echo \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id();')"
variables_prev="$(wpr eval 'echo base64_encode( (string) get_post_meta( \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id(), "_elementor_global_variables", true ) );')"
class_id="g-nexo$RANDOM"
class_label="nexo-prueba-clase-$suffix"
variable_label="nexo-prueba-var-$suffix"
variable_id="$(wp eval '
$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$repo = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make( $kit );
$all = $repo->all()->get();
$all["items"]["'"$class_id"'"] = array( "id" => "'"$class_id"'", "type" => "class", "label" => "'"$class_label"'", "variants" => array() );
$all["order"][] = "'"$class_id"'";
$repo->put( $all["items"], $all["order"] );
$variables = new \Elementor\Modules\Variables\Storage\Variables_Repository( $kit );
$coleccion = $variables->load();
$variable = \Elementor\Modules\Variables\Storage\Entities\Variable::create_new( array(
	"id" => $coleccion->next_id(), "type" => "global-color-variable", "label" => "'"$variable_label"'",
	"value" => "#ff0000", "order" => $coleccion->get_next_order(),
) );
$variable->validate();
$coleccion->add_variable( $variable );
$variables->save( $coleccion );
echo $variable->id();')"
[ -n "$variable_id" ] || { echo "FALLA: no se pudo crear la variable"; exit 1; }
echo "Clase global: $class_id ($class_label); variable: $variable_id ($variable_label); Kit: $kit_id"

atomic_heading="Título atómico $suffix"
atomic_page_id="$(wp post create --post_type=page --post_status=draft --post_title="Prueba atómica $suffix" --porcelain)"
[ -n "$atomic_page_id" ] || { echo "FALLA: no se pudo crear la página atómica"; exit 1; }
guardado="$(wp eval '
$id = '"$atomic_page_id"';
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$arbol = array( array(
	"id" => "bbbb0000", "elType" => "e-flexbox", "styles" => array(), "elements" => array(
		array(
			"id" => "bbbb0001", "elType" => "widget", "widgetType" => "e-heading", "styles" => array(), "elements" => array(),
			"settings" => array(
				"title"   => array( "\$\$type" => "escaped-html", "value" => "'"$atomic_heading"'" ),
				"classes" => array( "\$\$type" => "classes", "value" => array( "'"$class_id"'" ) ),
			),
		),
	),
	"settings" => array( "classes" => array( "\$\$type" => "classes", "value" => array( "'"$class_id"'" ) ) ),
) );
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' --user=admin)"
[ "$guardado" = "guardado" ] || { echo "FALLA: Document::save no guardó el árbol atómico ($guardado)"; exit 1; }
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

echo
echo "### Verificación con wp-cli"
check_eq() { # nombre esperado obtenido
	if [ "$2" = "$3" ]; then ok "$1 guardado: $3"; else falla "$1: esperado «$2», guardado «$3»"; fi
}
check_eq "Yoast título (_yoast_wpseo_title)" "$titulo_seo" "$(wpr post meta get "$page_id" _yoast_wpseo_title)"
check_eq "Yoast descripción (_yoast_wpseo_metadesc)" "$descripcion_seo" "$(wpr post meta get "$page_id" _yoast_wpseo_metadesc)"
check_eq "ACF $campo" "$valor_campo" "$(wpr eval "echo get_field( '$campo', $page_id );")"
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
cleanup || exit_code=1
exit "$exit_code"
