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
# 7. Limpia lo que creó (páginas a la papelera, recuperable; clase, variable e
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
