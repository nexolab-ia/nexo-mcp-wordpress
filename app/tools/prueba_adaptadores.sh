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
# 8. Concurrencia (bloqueo por original): dispara en paralelo 5 primeras ediciones
#    sobre la misma página publicada, con update-page y con edit-elementor-section, y
#    comprueba que existe una sola copia, que cada llamada tuvo éxito en esa copia o
#    respondió «ocupado», y que ninguna edición con éxito se perdió.
# 8b. update-node-text, update-node-attribute, edit-structure y restore-builder-backup:
#    sobre una publicada → copia de trabajo (original intacto, diff, publicar fusiona,
#    restaurar en la copia); sobre un borrador → en su sitio; un rechazo no crea copia.
# 9. Limpia lo que creó (páginas a la papelera, recuperable; clase, variable e
#    imagen de prueba se quitan) y restaura los ajustes y el estado del experimento.
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
	wpr eval 'echo \Elementor\Plugin::$instance->experiments->is_feature_active( "e_atomic_elements" ) ? "si" : "no";'
	exit 0
fi

# Subcomandos para el bloque de edit-elementor-section (comprobaciones fuera de MCP).
#   --meta <id> <clave>   valor crudo de un meta del contenido (vacío si no existe)
#   --estado <id>         estado del contenido (vacío si no existe)
#   --render <id>         HTML que Elementor renderiza para el contenido
if [ "${1:-}" = "--meta" ]; then wpr post meta get "$2" "$3"; exit 0; fi
if [ "${1:-}" = "--estado" ]; then wpr post get "$2" --field=post_status; exit 0; fi
if [ "${1:-}" = "--render" ]; then
	wp eval 'echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( '"$2"', true );' --user=admin
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
	# Páginas de create-elementor-page: se barren por el sufijo del título, así que
	# también caen las que quedaran si la prueba se interrumpió.
	if [ -n "$suffix" ]; then
		created_ids="$(wpr post list --post_type=page --post_status=any --field=ID --s="elementor-mcp-$suffix" | tr '\n' ' ')"
	fi
	for id in $page_id $atomic_page_id $created_ids $group_ids; do
		wp eval "wp_trash_post( $id );" >/dev/null
		if [ "$(wpr post get "$id" --field=post_status)" = "trash" ]; then
			echo "Papelera: $id"
		else
			echo "FALLA: $id no quedó en la papelera"; fallidas=$((fallidas + 1))
		fi
	done
	if [ -n "$media_id" ]; then
		wp eval "wp_delete_attachment( $media_id, true );" >/dev/null
		echo "Imagen de prueba quitada: $media_id"
	fi
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

check_eq() { # nombre esperado obtenido
	if [ "$2" = "$3" ]; then ok "$1 guardado: $3"; else falla "$1: esperado «$2», guardado «$3»"; fi
}
echo
echo "### Creación de página atómica por MCP (create-elementor-page)"
# Imagen de prueba propia (1×1 PNG) en la biblioteca: la página la referencia por id.
media_id="$(wp eval '
require_once ABSPATH . "wp-admin/includes/file.php";
require_once ABSPATH . "wp-admin/includes/media.php";
require_once ABSPATH . "wp-admin/includes/image.php";
$tmp = wp_tempnam( "nexo-prueba.png" );
file_put_contents( $tmp, base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" ) );
$id = media_handle_sideload( array( "name" => "nexo-prueba-'"$suffix"'.png", "tmp_name" => $tmp ), 0 );
echo is_wp_error( $id ) ? "" : $id;' --user=admin)"
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
	render="$(wp eval '
$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( '"$creado_id"', true );
echo $html;' --user=admin)"
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
version_elementor="$(wpr eval 'echo ELEMENTOR_VERSION;')"
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
guardado="$(wp eval '
$id = '"$secciones_atomica_id"';
update_post_meta( $id, "_elementor_edit_mode", "builder" );
$texto = function ( $t ) { return array( "\$\$type" => "escaped-html", "value" => $t ); };
$arbol = array( array(
	"id" => "fx4000", "elType" => "e-flexbox", "styles" => array(), "settings" => array(), "elements" => array(
		array( "id" => "eh4000", "elType" => "widget", "widgetType" => "e-heading", "styles" => array(), "elements" => array(), "settings" => array( "title" => $texto( "Titulo atomico base '"$suffix"'" ) ) ),
		array( "id" => "ep4000", "elType" => "widget", "widgetType" => "e-paragraph", "styles" => array(), "elements" => array(), "settings" => array( "paragraph" => $texto( "Parrafo atomico base '"$suffix"'" ) ) ),
	),
) );
$documento = \Elementor\Plugin::$instance->documents->get( $id );
echo $documento && $documento->save( array( "elements" => $arbol, "settings" => array() ) ) ? "guardado" : "error";' --user=admin)"
[ "$guardado" = "guardado" ] || { echo "FALLA: no se pudo guardar la página atómica de secciones ($guardado)"; exit 1; }
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
import importlib.util, json, pathlib, select, subprocess, sys, threading, time

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
    proc = subprocess.Popen(["docker", "compose", "run", "--rm", "-T", "--entrypoint", "wp", "setup", "eval", php],
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
echo "### Verificación con wp-cli"
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
