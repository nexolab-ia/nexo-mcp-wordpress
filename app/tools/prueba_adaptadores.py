#!/usr/bin/env python3
"""Prueba de los adaptadores de Nexo MCP con los plugins reales activos.

Usa la misma conexión OAuth real que prueba_punta_a_punta.py (registro
dinámico, consentimiento, PKCE) y ejercita, sobre una página que prepara
prueba_adaptadores.sh:

- Yoast SEO:  get-seo-settings, get-post-seo, update-post-seo
- ACF:        list-fields, update-field
- Elementor:  get-page-structure, update-node-text, update-node-attribute,
              restore-builder-backup
- get-site-info reporta «elementor» entre los constructores.

Cada comprobación corre aunque otra falle; al final se imprime un resumen
OK/FALLA y el código de salida es distinto de cero si alguna falló. Que el
valor quedó guardado en la base de datos lo verifica el .sh con wp-cli.

Uso:  python3 tools/prueba_adaptadores.py --pagina ID [--base http://localhost:8080]
      [--user admin] [--password admin] [--campo nombre] [--valor-campo V]
      [--titulo-seo T] [--descripcion-seo D] [--texto T] [--enlace URL]

Sólo usa la biblioteca estándar de Python.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import html
import http.cookiejar
import importlib.util
import json
import pathlib
import re
import secrets
import subprocess
import sys
import time
import traceback
import urllib.parse

# Se reutilizan los ayudantes HTTP de la prueba de punta a punta.
_spec = importlib.util.spec_from_file_location(
    "prueba_punta_a_punta", pathlib.Path(__file__).resolve().parent / "prueba_punta_a_punta.py"
)
base_test = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(base_test)
request, build_opener, REDIRECT_URI = base_test.request, base_test.build_opener, base_test.REDIRECT_URI

# El plugin limita el transporte MCP a 240 pedidos por minuto por identidad (TransportGuard; una unidad por pedido
# desde 1.4.3, que además responde 429 con `Retry-After` al pasarlo). La prueba no debe chocar con el límite: el cliente
# queda por debajo, con un pedido cada INTERVALO_MINIMO segundos como máximo (~230 por minuto). Con wp-cli lento la
# prueba nunca llegaba al límite; con el contenedor único (tools/wpcli.sh) sí. Si el límite baja de 240, hay que
# ajustar INTERVALO_MINIMO. `reloj` y `esperar` se reemplazan en tools/prueba_cliente_limite.py.
INTERVALO_MINIMO = 0.26
reloj = time.monotonic
esperar = time.sleep
_ultimo_pedido = [None]


def _respetar_ritmo():
    if _ultimo_pedido[0] is not None:
        falta = INTERVALO_MINIMO - (reloj() - _ultimo_pedido[0])
        if falta > 0:
            esperar(falta)
    _ultimo_pedido[0] = reloj()


class Cliente:
    """Cliente MCP autenticado por OAuth (mismo recorrido que prueba_punta_a_punta.py)."""

    def __init__(self, base, user, password):
        self.endpoint = f"{base}/wp-json/nexo-mcp/v1/mcp"
        self.plain = build_opener(http.cookiejar.CookieJar())
        self.session = {}
        self.names = []
        self.next_id = 100
        self._conectar(base, user, password)

    def _conectar(self, base, user, password):
        jar = http.cookiejar.CookieJar()
        web, raw = build_opener(jar), build_opener(jar, follow=False)
        status, headers, _ = request(self.plain, "POST", self.endpoint, "{}", {"Content-Type": "application/json"})
        challenge = headers.get("WWW-Authenticate", "")
        assert status == 401 and "resource_metadata=" in challenge, "Falta el desafío OAuth"
        _, _, body = request(self.plain, "GET", re.search(r'resource_metadata="([^"]+)"', challenge).group(1))
        prm = json.loads(body)
        _, _, body = request(self.plain, "GET", f"{prm['authorization_servers'][0]}/.well-known/oauth-authorization-server")
        meta = json.loads(body)
        scope = " ".join(prm.get("scopes_supported", ["mcp:connect"]))
        _, _, body = request(
            self.plain,
            "POST",
            meta["registration_endpoint"],
            json.dumps(
                {
                    "client_name": "Prueba de adaptadores",
                    "redirect_uris": [REDIRECT_URI],
                    "grant_types": ["authorization_code", "refresh_token"],
                    "response_types": ["code"],
                    "token_endpoint_auth_method": "none",
                }
            ),
            {"Content-Type": "application/json"},
        )
        client_id = json.loads(body)["client_id"]
        request(web, "GET", f"{base}/wp-login.php")
        request(web, "POST", f"{base}/wp-login.php", {"log": user, "pwd": password, "wp-submit": "Acceder", "testcookie": "1"})
        verifier = secrets.token_urlsafe(48)
        challenge_s256 = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
        state = secrets.token_urlsafe(12)
        query = urllib.parse.urlencode(
            {
                "response_type": "code",
                "client_id": client_id,
                "redirect_uri": REDIRECT_URI,
                "code_challenge": challenge_s256,
                "code_challenge_method": "S256",
                "state": state,
                "scope": scope,
                "resource": prm["resource"],
            }
        )
        authorize_url = f"{meta['authorization_endpoint']}?{query}"
        _, _, page = request(web, "GET", authorize_url)
        fields = {
            html.unescape(n): html.unescape(v)
            for n, v in re.findall(r'<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', page)
        }
        for n, v in re.findall(r'<input type="hidden" id="[^"]*" name="([^"]+)" value="([^"]*)"', page):
            fields[html.unescape(n)] = html.unescape(v)
        fields["nexo_mcp_decision"] = "allow"
        _, headers, _ = request(raw, "POST", authorize_url, fields)
        params = urllib.parse.parse_qs(urllib.parse.urlparse(headers.get("Location", "")).query)
        assert params.get("state", [""])[0] == state, "state no coincide"
        _, _, body = request(
            self.plain,
            "POST",
            meta["token_endpoint"],
            {
                "grant_type": "authorization_code",
                "code": params["code"][0],
                "redirect_uri": REDIRECT_URI,
                "client_id": client_id,
                "code_verifier": verifier,
                "resource": prm["resource"],
            },
        )
        self.access = json.loads(body)["access_token"]
        self.rpc("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "prueba", "version": "1"}})
        self.rpc("notifications/initialized", notify=True)
        _, listing = self.rpc("tools/list")
        self.names = [t["name"] for t in listing["result"]["tools"]]

    def rpc(self, method, params=None, notify=False):
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": f"Bearer {self.access}",
        }
        if "id" in self.session:
            headers["Mcp-Session-Id"] = self.session["id"]
        payload = {"jsonrpc": "2.0", "method": method, "params": params or {}}
        if not notify:
            self.next_id += 1
            payload["id"] = self.next_id
        _respetar_ritmo()
        _, out, body = request(self.plain, "POST", self.endpoint, json.dumps(payload), headers)[0:3]
        if "Mcp-Session-Id" in out:
            self.session["id"] = out["Mcp-Session-Id"]
        return None, (json.loads(body) if body.strip() else None)

    def tool(self, short):
        return next((n for n in self.names if n.endswith("-" + short)), "nexo-mcp-" + short)

    def call(self, short, arguments):
        """Llama a una herramienta; devuelve (es_error, texto, datos_json_o_None)."""
        _, out = self.rpc("tools/call", {"name": self.tool(short), "arguments": arguments})
        result = out.get("result", out.get("error")) if isinstance(out, dict) else None
        assert isinstance(result, dict), f"{short}: respuesta del transporte que no es JSON-RPC: {json.dumps(out, ensure_ascii=False)[:300]}"
        if "content" in result:
            text = "".join(c.get("text", "") for c in result["content"])
        else:
            text = json.dumps(result, ensure_ascii=False)
        try:
            data = json.loads(text)
        except ValueError:
            data = None
        is_error = bool(result.get("isError")) or "content" not in result
        print(f"  {short} {json.dumps(arguments, ensure_ascii=False)[:160]}\n    -> {'ERROR ' if is_error else ''}{text[:400]}")
        return is_error, text, data

    def ok(self, short, arguments):
        is_error, text, data = self.call(short, arguments)
        assert not is_error, f"{short} {json.dumps(arguments, ensure_ascii=False)} devolvió error: {text[:300]}"
        return text, data


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:8080")
    parser.add_argument("--user", default="admin")
    parser.add_argument("--password", default="admin")
    parser.add_argument("--pagina", type=int, required=True, help="Página borrador con Elementor y el grupo ACF")
    parser.add_argument("--campo", default="nexo_prueba_texto")
    parser.add_argument("--valor-campo", default="Valor de prueba ACF")
    parser.add_argument("--titulo-seo", default="Título SEO de prueba")
    parser.add_argument("--descripcion-seo", default="Descripción SEO de prueba para Yoast.")
    parser.add_argument("--nodo-texto", default="aaaa0001", help="Id del widget heading")
    parser.add_argument("--nodo-enlace", default="aaaa0003", help="Id del widget button (con enlace)")
    parser.add_argument("--texto", default="Título editado por la IA")
    parser.add_argument("--enlace", default="https://example.com/editado")
    parser.add_argument(
        "--verificar-enlace",
        default="",
        help="Script que comprueba fuera de MCP (wp-cli) que el enlace editado quedó guardado: "
        "se llama con --enlace-guardado <página> <url>",
    )
    args = parser.parse_args()
    pid = args.pagina

    print("Conectando por OAuth…")
    cli = Cliente(args.base.rstrip("/"), args.user, args.password)
    print(f"Conectado: {len(cli.names)} herramientas.")

    def nodes_of(data):
        return {n["id"]: n for n in data["nodes"]}

    def c_site_info():
        _, data = cli.ok("get-site-info", {})
        assert "elementor" in data.get("builders", []), f"builders no incluye elementor: {data.get('builders')}"

    def c_get_seo_settings():
        _, data = cli.ok("get-seo-settings", {})
        assert isinstance(data, dict) and "sitemaps_enabled" in data, "sin ajustes SEO"

    def c_get_post_seo():
        _, data = cli.ok("get-post-seo", {"id": pid})
        assert data.get("id") == pid, "id incorrecto"
        assert "Yoast" in str(data.get("managed_by")), f"managed_by debe ser Yoast: {data.get('managed_by')}"

    def c_update_post_seo():
        cli.ok("update-post-seo", {"id": pid, "title": args.titulo_seo, "description": args.descripcion_seo})
        _, data = cli.ok("get-post-seo", {"id": pid})
        assert data.get("title") == args.titulo_seo, f"title leído: {data.get('title')!r}"
        assert data.get("description") == args.descripcion_seo, f"description leída: {data.get('description')!r}"

    def c_list_fields():
        text, _ = cli.ok("list-fields", {"id": pid})
        assert args.campo in text, f"el campo {args.campo} no aparece en list-fields"

    def c_update_field():
        cli.ok("update-field", {"id": pid, "name": args.campo, "value": args.valor_campo})
        text, _ = cli.ok("list-fields", {"id": pid})
        assert args.valor_campo in text, "list-fields no refleja el valor escrito"

    def c_structure():
        _, data = cli.ok("get-page-structure", {"id": pid})
        assert data.get("builder") == "elementor", f"builder: {data.get('builder')!r}"
        nodes = nodes_of(data)
        assert args.nodo_texto in nodes and nodes[args.nodo_texto].get("text"), "falta el heading editable"
        assert args.nodo_enlace in nodes, "falta el botón"

    def c_update_text():
        cli.ok("update-node-text", {"id": pid, "node_id": args.nodo_texto, "text": args.texto})
        _, data = cli.ok("get-page-structure", {"id": pid})
        got = nodes_of(data)[args.nodo_texto].get("text")
        assert got == args.texto, f"texto leído: {got!r}"

    def c_update_attr():
        cli.ok("update-node-attribute", {"id": pid, "node_id": args.nodo_enlace, "attribute": "link", "value": args.enlace})
        _, data = cli.ok("get-page-structure", {"id": pid})
        assert args.enlace in json.dumps(nodes_of(data)[args.nodo_enlace], ensure_ascii=False), "el enlace no aparece en la estructura"
        if args.verificar_enlace:
            # Antes de restaurar: así la prueba del respaldo parte de un cambio
            # comprobado en la base de datos, no sólo leído por la misma herramienta.
            check = subprocess.run([args.verificar_enlace, "--enlace-guardado", str(pid), args.enlace])
            assert check.returncode == 0, "el enlace editado no quedó guardado en _elementor_data"

    def c_restore():
        # El respaldo guarda el estado previo a la última edición (el enlace):
        # al restaurar vuelve el enlace original y se conserva el texto editado.
        cli.ok("restore-builder-backup", {"id": pid})
        _, data = cli.ok("get-page-structure", {"id": pid})
        nodes = nodes_of(data)
        assert args.enlace not in json.dumps(nodes[args.nodo_enlace], ensure_ascii=False), "el enlace editado sigue ahí"
        assert nodes[args.nodo_texto].get("text") == args.texto, "se perdió el texto editado antes del respaldo"

    checks = [
        ("get-site-info reporta elementor", c_site_info),
        ("Yoast: get-seo-settings", c_get_seo_settings),
        ("Yoast: get-post-seo", c_get_post_seo),
        ("Yoast: update-post-seo", c_update_post_seo),
        ("ACF: list-fields", c_list_fields),
        ("ACF: update-field", c_update_field),
        ("Elementor: get-page-structure", c_structure),
        ("Elementor: update-node-text", c_update_text),
        ("Elementor: update-node-attribute", c_update_attr),
        ("Elementor: restore-builder-backup", c_restore),
    ]
    results = []
    for name, fn in checks:
        print(f"\n== {name}")
        try:
            fn()
            results.append((name, True, ""))
        except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
            if not isinstance(err, AssertionError):
                traceback.print_exc()
            results.append((name, False, str(err) or type(err).__name__))

    print("\n== Resumen")
    for name, passed, detail in results:
        print(f"{'OK   ' if passed else 'FALLA'} {name}" + ("" if passed else f"\n        {detail}"))
    failed = [r for r in results if not r[1]]
    print(f"\n{len(results) - len(failed)}/{len(results)} comprobaciones OK.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
