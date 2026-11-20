"""Herramientas de escritura del sistema de diseño por MCP contra el Elementor real del docker.

Lo corre tools/prueba_adaptadores.sh (bloque «Sistema de diseño: herramientas de escritura») desde app/, con el
permiso destructivo encendido y una foto del estado tomada; el script restaura todo al final.
    python3 -I tools/prueba_diseno_escritura.py <sufijo>
Sale con 1 si alguna comprobación falla.
"""
import importlib.util, json, pathlib, subprocess, sys

suffix = sys.argv[1]
spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
cli = mod.Cliente("http://localhost:8080", "admin", "admin")
fallas = []
total = [0]
etq = f"nexo-prueba-dd-{suffix}"

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

def huella():
    out = script("--diseno-huella")
    partes = dict(p.split("=", 1) for p in out.split())
    assert set(partes) == {"clases", "vista", "variables", "kit", "respaldo"}, f"huella: {out!r}"
    return partes

def prop(tipo, valor):
    return {"$$type": tipo, "value": valor}

def sistema():
    _, d = cli.ok("get-design-system", {})
    return d

def deshacer():
    _, d = cli.ok("undo-design-system-change", {"confirmation": "UNDO"})
    return d

def rechaza(short, args, fragmento, antes):
    es_error, texto, _ = cli.call(short, args)
    assert es_error, f"{short} debía rechazarse: {texto[:200]}"
    assert fragmento in texto, f"mensaje: {texto[:400]!r} (se esperaba «{fragmento}»)"
    assert huella() == antes, f"{short}: el rechazo cambió el estado"

def restaurado(antes, mensaje="deshacer no devolvió la huella exacta"):
    """Tras deshacer: misma huella que `antes` (sin contar el respaldo) y ya sin respaldo guardado."""
    ahora = huella()
    assert ahora["respaldo"] == "no", f"quedó un respaldo tras deshacer: {ahora}"
    assert {k: v for k, v in antes.items() if k != "respaldo"} == {k: v for k, v in ahora.items() if k != "respaldo"}, f"{mensaje}: {antes} / {ahora}"

def clase_guardada(id_):
    return json.loads(script("--diseno-clase", id_))

def variable_guardada(id_):
    return json.loads(script("--diseno-variable", id_))

def lista_kit(nombre):
    return json.loads(script("--diseno-kit-lista", nombre))

# ------------------------------------------------------------------ Clases
variantes = [
    {"meta": {"breakpoint": "desktop", "state": None}, "props": {"color": prop("color", "#112233"), "gap": prop("size", {"size": 8, "unit": "px"})}},
    {"meta": {"breakpoint": "desktop", "state": "hover"}, "props": {"color": prop("color", "#445566")}},
    {"meta": {"breakpoint": "tablet", "state": None}, "props": {"gap": prop("size", {"size": 4, "unit": "px"})}},
]
clase = {}

def clase_crear_y_deshacer():
    h0 = huella()
    assert h0["respaldo"] == "no", f"había un respaldo antes de empezar: {h0}"
    _, d = cli.ok("create-global-class", {"label": f"{etq}-a", "variants": variantes})
    assert d["id"].startswith("g-") and d["label"] == f"{etq}-a" and d["undo_available"] is True, d
    g = clase_guardada(d["id"])
    assert g["existe"] and g["label"] == f"{etq}-a", g
    assert [(v["breakpoint"], v["state"]) for v in g["variantes"]] == [("desktop", None), ("desktop", "hover"), ("tablet", None)], g["variantes"]
    assert g["publicada_ultima"], "la clase debía quedar al final del orden"
    assert g["vista"] and g["vista_ultima"], f"la clase debía estar al final del orden de la vista previa del editor: {g}"
    assert {"id": d["id"], "label": f"{etq}-a"} in sistema()["classes"], "get-design-system no la muestra"
    assert huella()["clases"] != h0["clases"] and huella()["respaldo"] == "si"
    u = deshacer()
    assert u["kind"] == "classes" and u["operation"] == "create-class", u
    restaurado(h0, "deshacer no devolvió la huella exacta")
    g = clase_guardada(d["id"])
    assert not g["existe"] and not g["vista"], f"la clase debía desaparecer también de la vista previa: {g}"
    assert not any(c["id"] == d["id"] for c in sistema()["classes"])
    es_error, texto, _ = cli.call("undo-design-system-change", {"confirmation": "UNDO"})
    assert es_error and "No hay ningún cambio del sistema de diseño para deshacer." in texto, texto[:200]
    restaurado(h0, "deshacer dos veces cambió el estado")
    _, d = cli.ok("create-global-class", {"label": f"{etq}-a", "variants": variantes})
    clase["id"] = d["id"]

def clase_modificar():
    id_ = clase["id"]
    h1 = huella()
    nuevas = variantes + [{"meta": {"breakpoint": "mobile", "state": None}, "props": {"gap": prop("size", {"size": 2, "unit": "px"})}}]
    _, d = cli.ok("update-global-class", {"id": id_, "label": f"{etq}-b", "variants": nuevas})
    assert d["id"] == id_ and d["label"] == f"{etq}-b", d
    g = clase_guardada(id_)
    assert g["label"] == f"{etq}-b" and len(g["variantes"]) == 4 and g["vista"], g
    assert {"id": id_, "label": f"{etq}-b"} in sistema()["classes"]
    deshacer()
    restaurado(h1, "deshacer la modificación no devolvió la huella exacta")
    # Sólo el label: las variantes se conservan.
    cli.ok("update-global-class", {"id": id_, "label": f"{etq}-c"})
    g = clase_guardada(id_)
    assert g["label"] == f"{etq}-c" and len(g["variantes"]) == 3, g
    deshacer()
    restaurado(h1)

def clase_rechazos():
    h = huella()
    id_ = clase["id"]
    rechaza("create-global-class", {"label": f"{etq}-A", "variants": []}, "ya existe una clase", h)
    rechaza("create-global-class", {"label": "1mala", "variants": []}, "label:", h)
    rechaza("create-global-class", {"label": "--x", "variants": []}, "label:", h)
    rechaza("create-global-class", {"label": f"{etq}-x", "variants": [{"meta": {"breakpoint": "desktop", "state": None}, "props": {"color": prop("color", "url(http://evil.example/x)")}}]}, "variants[0].props.color.value", h)
    rechaza("create-global-class", {"label": f"{etq}-x", "variants": [{"meta": {"breakpoint": "enorme", "state": None}, "props": {}}]}, "breakpoint", h)
    rechaza("create-global-class", {"label": f"{etq}-x", "variants": [{"meta": {"breakpoint": "desktop", "state": None}, "props": {"colour": prop("color", "red")}}]}, "colour", h)
    rechaza("create-global-class", {"label": f"{etq}-x", "variants": [], "extra": 1}, "extra", h)
    rechaza("update-global-class", {"id": "g-noexiste", "label": f"{etq}-x"}, "No existe ninguna clase global", h)
    rechaza("update-global-class", {"id": id_}, "nada que modificar", h)

def clase_borrar_sin_uso():
    id_ = clase["id"]
    h = huella()
    es_error, texto, _ = cli.call("delete-global-class", {"id": id_})
    assert es_error and "confirmation" in texto, texto[:300]
    assert huella() == h, "borrar sin confirmación cambió el estado"
    _, d = cli.ok("delete-global-class", {"id": id_, "confirmation": "DELETE"})
    assert d["deleted"] == id_ and d["pages_affected"] == [], d
    assert not clase_guardada(id_)["existe"] and not clase_guardada(id_)["vista"]
    assert not any(c["id"] == id_ for c in sistema()["classes"])
    deshacer()
    restaurado(h, "deshacer el borrado no devolvió la huella exacta")
    g = clase_guardada(id_)
    assert g["existe"] and g["vista"] and len(g["variantes"]) == 3, g

def clase_borrar_en_uso():
    id_ = clase["id"]
    titulo = f"Prueba diseño clase elementor-mcp-{suffix}"
    arbol = [{"type": "e-flexbox", "settings": {"classes": prop("classes", [id_])},
              "elements": [{"type": "e-heading", "settings": {"title": prop("escaped-html", "Usa la clase")}}]}]
    _, pagina = cli.ok("create-elementor-page", {"title": titulo, "elements": arbol})
    h = huella()
    es_error, texto, _ = cli.call("delete-global-class", {"id": id_, "confirmation": "DELETE"})
    assert es_error and "en uso" in texto and titulo in texto, texto[:400]
    assert huella() == h and clase_guardada(id_)["existe"], "el rechazo por uso cambió el estado"
    _, d = cli.ok("delete-global-class", {"id": id_, "force": True, "confirmation": "DELETE"})
    assert d["deleted"] == id_ and {"id": pagina["id"], "title": titulo} in d["pages_affected"], d
    assert not clase_guardada(id_)["existe"]
    assert id_ not in script("--meta", str(pagina["id"]), "_elementor_data"), "Elementor debía quitar la clase de la página"
    u = deshacer()
    assert u.get("warnings") and "no vuelve a ponerla" in u["warnings"][0], u
    after = huella()
    assert after["clases"] == h["clases"] and after["vista"] == h["vista"] and after["respaldo"] == "no", (after, h)

comprobar("clases: crear (variantes con breakpoint y estado, orden de la vista previa), ver y deshacer", clase_crear_y_deshacer)
comprobar("clases: modificar (label y variantes; sólo label) y deshacer", clase_modificar)
comprobar("clases: rechazos (label repetido/inválido o con `--`, CSS, breakpoint, propiedad, parámetro, id) no cambian nada", clase_rechazos)
comprobar("clases: borrar sin uso (pide DELETE) y deshacer", clase_borrar_sin_uso)
comprobar("clases: borrar en uso se rechaza con la lista; con force borra y deshacer avisa", clase_borrar_en_uso)

# --------------------------------------------------------------- Variables
tipos = [
    ("color", "global-color-variable", "#112233", "#445566"),
    ("fuente", "global-font-variable", "Roboto, sans-serif", "Open Sans"),
    ("tamano", "global-size-variable", "24px", "2rem"),
    ("tamano-libre", "global-custom-size-variable", "clamp(1rem, 2vw, 3rem)", "calc(100% - 2rem)"),
]
variables = {}

def variable_crear(corto, tipo, valor, _nuevo):
    def fn():
        h0 = huella()
        _, d = cli.ok("create-design-variable", {"type": tipo, "label": f"{etq}-{corto}", "value": valor})
        assert d["id"] and d["type"] == tipo and d["label"] == f"{etq}-{corto}" and d["value"] == valor and d["undo_available"] is True, d
        v = next((x for x in sistema()["variables"] if x["id"] == d["id"]), None)
        assert v and v["type"] == tipo and v["value"] == valor and v["label"] == f"{etq}-{corto}", f"get-design-system: {v}"
        g = variable_guardada(d["id"])
        # Elementor guarda el tamaño libre como tamaño con unidad `custom`: el tipo propio sale al leerlo.
        guardado = "global-size-variable" if tipo == "global-custom-size-variable" else tipo
        assert g["existe"] and g["type"] == guardado and not g["borrada"], g
        u = deshacer()
        assert u["kind"] == "variables" and u["operation"] == "create-variable", u
        restaurado(h0, "deshacer no devolvió la huella exacta")
        assert not variable_guardada(d["id"])["existe"]
        _, d = cli.ok("create-design-variable", {"type": tipo, "label": f"{etq}-{corto}", "value": valor})
        variables[corto] = d["id"]
    return fn

def variable_modificar(corto, tipo, valor, nuevo):
    def fn():
        id_ = variables[corto]
        h = huella()
        _, d = cli.ok("update-design-variable", {"id": id_, "value": nuevo})
        assert d["id"] == id_ and d["value"] == nuevo, d
        v = next(x for x in sistema()["variables"] if x["id"] == id_)
        assert v["value"] == nuevo, v
        deshacer()
        restaurado(h, "deshacer el valor no devolvió la huella exacta")
        v = next(x for x in sistema()["variables"] if x["id"] == id_)
        assert v["value"] == valor, v
        cli.ok("update-design-variable", {"id": id_, "label": f"{etq}-{corto}-2"})
        assert any(x["id"] == id_ and x["label"] == f"{etq}-{corto}-2" for x in sistema()["variables"])
        deshacer()
        restaurado(h)
    return fn

def variable_cambiar_tipo():
    id_ = variables["tamano"]
    h = huella()
    _, d = cli.ok("update-design-variable", {"id": id_, "type": "global-custom-size-variable", "value": "calc(1px + 1px)"})
    assert d["type"] == "global-custom-size-variable" and d["value"] == "calc(1px + 1px)", d
    v = next(x for x in sistema()["variables"] if x["id"] == id_)
    assert v["type"] == "global-custom-size-variable", v
    deshacer()
    restaurado(h, "deshacer el cambio de tipo no devolvió la huella exacta")
    v = next(x for x in sistema()["variables"] if x["id"] == id_)
    assert v["type"] == "global-size-variable" and v["value"] == "24px", v

def variable_rechazos():
    h = huella()
    ids = variables
    rechaza("create-design-variable", {"type": "global-color-variable", "label": f"{etq}-nuevo", "value": "rojo"}, "value:", h)
    rechaza("create-design-variable", {"type": "global-color-variable", "label": f"{etq}-nuevo", "value": "url(http://evil.example/x)"}, "value:", h)
    rechaza("create-design-variable", {"type": "global-size-variable", "label": f"{etq}-nuevo", "value": "calc(1px + 1px)"}, "value:", h)
    rechaza("create-design-variable", {"type": "global-custom-size-variable", "label": f"{etq}-nuevo", "value": "calc(1px"}, "paréntesis", h)
    rechaza("create-design-variable", {"type": "global-font-variable", "label": f"{etq}-nuevo", "value": "a;b"}, "value:", h)
    rechaza("create-design-variable", {"type": "global-color-variable", "label": "con espacio", "value": "#fff"}, "label:", h)
    # Elementor escribe el label como propiedad CSS `--label`: uno que ya empiece con `--` se rechaza.
    rechaza("create-design-variable", {"type": "global-color-variable", "label": "--x", "value": "#fff"}, "label:", h)
    rechaza("create-design-variable", {"type": "global-color-variable", "label": "x:red;}body{display:none;--y", "value": "#fff"}, "label:", h)
    rechaza("update-design-variable", {"id": ids["color"], "label": "a{}"}, "label:", h)
    rechaza("create-design-variable", {"type": "global-color-variable", "label": f"{etq}-COLOR", "value": "#fff"}, "ya existe una variable", h)
    rechaza("create-design-variable", {"type": "otra", "label": f"{etq}-nuevo", "value": "#fff"}, "type", h)
    rechaza("update-design-variable", {"id": ids["color"], "type": "global-custom-size-variable"}, "type:", h)
    rechaza("update-design-variable", {"id": ids["color"], "value": "10px"}, "value:", h)
    rechaza("update-design-variable", {"id": "e-gv-noexiste", "value": "#fff"}, "No existe una variable", h)
    rechaza("update-design-variable", {"id": ids["color"]}, "indicá qué cambiar", h)

def variable_borrar_sin_uso():
    id_ = variables["fuente"]
    h = huella()
    es_error, texto, _ = cli.call("delete-design-variable", {"id": id_})
    assert es_error and "confirmation" in texto, texto[:300]
    assert huella() == h
    _, d = cli.ok("delete-design-variable", {"id": id_, "confirmation": "DELETE"})
    assert d["deleted"] == id_ and d["pages_affected"] == [], d
    assert variable_guardada(id_)["borrada"]
    assert not any(x["id"] == id_ for x in sistema()["variables"])
    deshacer()
    restaurado(h, "deshacer el borrado no devolvió la huella exacta")
    assert not variable_guardada(id_)["borrada"] and any(x["id"] == id_ for x in sistema()["variables"])

def variable_borrar_en_uso():
    id_ = variables["color"]
    titulo = f"Prueba diseño variable elementor-mcp-{suffix}"
    estilo = {"id": "caja", "type": "class", "label": "caja-variable", "variants": [
        {"meta": {"breakpoint": "desktop", "state": None}, "props": {"color": prop("global-color-variable", id_)}}]}
    arbol = [{"type": "e-flexbox", "settings": {"classes": prop("classes", ["caja"])}, "styles": {"caja": estilo},
              "elements": [{"type": "e-heading", "settings": {"title": prop("escaped-html", "Usa la variable")}}]}]
    _, pagina = cli.ok("create-elementor-page", {"title": titulo, "elements": arbol})
    h = huella()
    es_error, texto, _ = cli.call("delete-design-variable", {"id": id_, "confirmation": "DELETE"})
    assert es_error and "se usa en" in texto, texto[:400]
    assert huella() == h and not variable_guardada(id_)["borrada"], "el rechazo por uso cambió el estado"
    _, d = cli.ok("delete-design-variable", {"id": id_, "force": True, "confirmation": "DELETE"})
    assert d["deleted"] == id_ and {"id": pagina["id"], "title": titulo} in d["pages_affected"], d
    assert variable_guardada(id_)["borrada"]
    u = deshacer()
    assert u.get("warnings"), u
    restaurado(h, "deshacer el borrado forzado no devolvió la huella exacta")

for corto, tipo, valor, nuevo in tipos:
    comprobar(f"variables ({tipo}): crear, ver y deshacer", variable_crear(corto, tipo, valor, nuevo))
for corto, tipo, valor, nuevo in tipos:
    comprobar(f"variables ({tipo}): modificar valor y label y deshacer", variable_modificar(corto, tipo, valor, nuevo))
comprobar("variables: cambiar el tipo entre tamaño y tamaño libre y deshacer", variable_cambiar_tipo)
comprobar("variables: rechazos (valor por tipo, CSS, label con espacio o `--`, tipo, id) no cambian nada", variable_rechazos)
comprobar("variables: borrar sin uso (pide DELETE) y deshacer", variable_borrar_sin_uso)
comprobar("variables: borrar en uso se rechaza con la lista; con force borra y deshacer avisa", variable_borrar_en_uso)

# --------------------------------------------------------------------- Kit
kit = {}

def item(lista, id_):
    return next(x for x in lista if x["id"] == id_)

def kit_colores():
    h = huella()
    d0 = sistema()
    primario = item(d0["colors"], "primary")
    _, r = cli.ok("update-kit-globals", {"colors": [{"id": "primary", "color": "#123456"}]})
    assert item(r["colors"], "primary")["color"].lower() == "#123456", r["colors"]
    assert item(sistema()["colors"], "primary")["color"].lower() == "#123456"
    assert any(x.get("_id") == "primary" and x.get("color", "").lower() == "#123456" for x in lista_kit("system_colors"))
    u = deshacer()
    assert u["kind"] == "kit" and u["operation"] == "update", u
    restaurado(h, "deshacer el color system no devolvió la huella exacta")
    assert item(sistema()["colors"], "primary")["color"] == primario["color"]
    _, r = cli.ok("update-kit-globals", {"colors": [{"title": f"{etq}-color", "color": "#abcdef"}]})
    nuevo = next(x for x in r["colors"] if x["title"] == f"{etq}-color")
    assert nuevo["color"].lower() == "#abcdef" and nuevo["id"], nuevo
    assert any(x.get("_id") == nuevo["id"] for x in lista_kit("custom_colors")), "el color nuevo debía quedar en custom_colors"
    deshacer()
    restaurado(h, "deshacer el color nuevo no devolvió la huella exacta")
    _, r = cli.ok("update-kit-globals", {"colors": [{"title": f"{etq}-color", "color": "#abcdef"}]})
    kit["color"] = next(x for x in r["colors"] if x["title"] == f"{etq}-color")["id"]

def kit_tipografia():
    h = huella()
    d0 = sistema()
    primaria = item(d0["typography"], "primary")
    _, r = cli.ok("update-kit-globals", {"typography": [{"id": "primary", "typography_font_family": "Lora", "typography_font_weight": "700"}]})
    t = item(r["typography"], "primary")
    assert t.get("font_family") == "Lora" and t.get("font_weight") == "700", t
    t = item(sistema()["typography"], "primary")
    assert t.get("font_family") == "Lora" and t.get("font_weight") == "700", t
    deshacer()
    restaurado(h, "deshacer la tipografía system no devolvió la huella exacta")
    assert item(sistema()["typography"], "primary").get("font_family") == primaria.get("font_family")
    nueva = {"title": f"{etq}-tipo", "typography_typography": "custom", "typography_font_family": "Lora",
             "typography_font_size": {"unit": "px", "size": 30}, "typography_font_size_mobile": {"unit": "px", "size": 22}}
    _, r = cli.ok("update-kit-globals", {"typography": [nueva]})
    creada = next(x for x in r["typography"] if x["title"] == f"{etq}-tipo")
    guardada = next(x for x in lista_kit("custom_typography") if x.get("_id") == creada["id"])
    assert guardada["typography_font_family"] == "Lora" and guardada["typography_font_size"]["size"] == 30 and guardada["typography_font_size_mobile"]["size"] == 22, guardada
    deshacer()
    restaurado(h, "deshacer la tipografía nueva no devolvió la huella exacta")
    _, r = cli.ok("update-kit-globals", {"typography": [nueva]})
    kit["tipo"] = next(x for x in r["typography"] if x["title"] == f"{etq}-tipo")["id"]

def kit_borrar():
    for clave, lista in (("color", "colors"), ("tipo", "typography")):
        h = huella()
        id_ = kit[clave]
        es_error, texto, _ = cli.call("update-kit-globals", {"delete": [id_]})
        assert es_error and "DELETE" in texto, texto[:300]
        assert huella() == h, "borrar sin confirmación cambió el estado"
        _, r = cli.ok("update-kit-globals", {"delete": [id_], "confirmation": "DELETE"})
        assert not any(x["id"] == id_ for x in r[lista]), r[lista]
        assert not any(x["id"] == id_ for x in sistema()[lista])
        deshacer()
        restaurado(h, f"deshacer el borrado de {lista} no devolvió la huella exacta")
        assert any(x["id"] == id_ for x in sistema()[lista])

def kit_rechazos():
    h = huella()
    rechaza("update-kit-globals", {"colors": [{"id": "primary", "color": "rojo"}]}, "colors[0].color", h)
    rechaza("update-kit-globals", {"colors": [{"title": "sin color"}]}, "colors[0]", h)
    rechaza("update-kit-globals", {"typography": [{"id": "primary", "typography_font_size": {"unit": "parsec", "size": 1}}]}, "typography_font_size", h)
    rechaza("update-kit-globals", {"typography": [{"id": "primary", "typography_inventada": "x"}]}, "typography_inventada", h)
    rechaza("update-kit-globals", {"typography": [{"id": "primary", "typography_font_size": {"unit": "custom", "size": "calc(1rem /* x"}}]}, "typography[0].typography_font_size", h)
    rechaza("update-kit-globals", {"colors": [{"id": "no-existe", "color": "#fff"}]}, "no-existe", h)
    rechaza("update-kit-globals", {}, "nada", h)
    rechaza("update-kit-globals", {"delete": ["primary"], "confirmation": "DELETE"}, "primary", h)

comprobar("Kit: colores (system y custom): modificar, crear y deshacer", kit_colores)
comprobar("Kit: tipografía (system y custom con tamaños por dispositivo): modificar, crear y deshacer", kit_tipografia)
comprobar("Kit: borrar custom (pide DELETE) y deshacer", kit_borrar)
comprobar("Kit: rechazos (color, tamaño, clave, id, vacío, borrar system) no cambian nada", kit_rechazos)

# ---------------------------------------------------------------- Permisos
def permisos():
    h = huella()
    id_clase, id_var = clase["id"], variables["color"]
    entradas = {
        "create-global-class": {"label": f"{etq}-p", "variants": []},
        "update-global-class": {"id": id_clase, "label": f"{etq}-p"},
        "delete-global-class": {"id": id_clase, "confirmation": "DELETE"},
        "create-design-variable": {"type": "global-color-variable", "label": f"{etq}-p", "value": "#fff"},
        "update-design-variable": {"id": id_var, "value": "#fff"},
        "delete-design-variable": {"id": id_var, "confirmation": "DELETE"},
        "update-kit-globals": {"colors": [{"id": "primary", "color": "#123456"}]},
        "undo-design-system-change": {"confirmation": "UNDO"},
    }
    try:
        script("--ajuste", "design_system_tools_enabled", "false")
        for nombre, args in entradas.items():
            es_error, texto, _ = cli.call(nombre, args)
            assert es_error, f"{nombre} con el permiso apagado debía denegarse: {texto[:200]}"
        assert huella() == h, "con el permiso apagado cambió el estado"
    finally:
        script("--ajuste", "design_system_tools_enabled", "true")
    try:
        script("--ajuste", "read_only", "true")
        for nombre, args in entradas.items():
            es_error, texto, _ = cli.call(nombre, args)
            assert es_error, f"{nombre} en «Solo revisar» debía denegarse: {texto[:200]}"
        assert huella() == h, "en «Solo revisar» cambió el estado"
    finally:
        script("--ajuste", "read_only", "false")
    try:
        script("--ajuste", "destructive_enabled", "false")
        for nombre in ("delete-global-class", "delete-design-variable"):
            es_error, texto, _ = cli.call(nombre, entradas[nombre])
            assert es_error, f"{nombre} sin el permiso destructivo debía denegarse: {texto[:200]}"
        es_error, texto, _ = cli.call("update-kit-globals", {"delete": [kit["color"]], "confirmation": "DELETE"})
        assert es_error, f"update-kit-globals con delete sin el permiso destructivo debía denegarse: {texto[:200]}"
        assert huella() == h, "sin el permiso destructivo cambió el estado"
        # Lo que no borra sigue permitido.
        cli.ok("update-kit-globals", entradas["update-kit-globals"])
        deshacer()
        restaurado(h)
    finally:
        script("--ajuste", "destructive_enabled", "true")

comprobar("permisos: apagado, «Solo revisar» y sin permiso destructivo se deniegan y no cambian nada", permisos)
print(f"\n{total[0] - len(fallas)}/{total[0]} comprobaciones de escritura del sistema de diseño OK.")
sys.exit(1 if fallas else 0)
