#!/usr/bin/env python3
"""Prueba de punta a punta de Nexo MCP contra un WordPress real.

Recorre el mismo camino que un cliente MCP (Claude, Cursor...):

1. Llama al endpoint MCP sin credenciales y lee el desafío WWW-Authenticate.
2. Descubre el servidor de autorización (.well-known).
3. Registra un cliente OAuth dinámicamente (RFC 7591).
4. Inicia sesión en WordPress como administrador y aprueba la pantalla de
   consentimiento (lo que haría la persona en el navegador).
5. Canjea el código por un token con PKCE (S256).
6. Usa el token: initialize, tools/list y algunas herramientas.

Uso:  python3 tools/prueba_punta_a_punta.py [--base http://localhost:8080]
      [--user admin] [--password admin] [--scope "mcp:connect mcp:read"]

Sólo usa la biblioteca estándar de Python.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import html
import http.cookiejar
import json
import re
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request

REDIRECT_URI = "http://127.0.0.1:33418/callback"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        return None


def build_opener(jar, follow=True):
    handlers = [urllib.request.HTTPCookieProcessor(jar)]
    if not follow:
        handlers.append(NoRedirect())
    return urllib.request.build_opener(*handlers)


def request(opener, method, url, data=None, headers=None):
    body = None
    headers = dict(headers or {})
    if isinstance(data, dict):
        body = urllib.parse.urlencode(data).encode()
        headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
    elif isinstance(data, (bytes, str)):
        body = data.encode() if isinstance(data, str) else data
    req = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        with opener.open(req, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as err:
        return err.code, dict(err.headers), err.read().decode("utf-8", "replace")


def step(title):
    print(f"\n== {title}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:8080")
    parser.add_argument("--user", default="admin")
    parser.add_argument("--password", default="admin")
    parser.add_argument("--scope", default="")
    parser.add_argument("--titulo", default="", help="Título del post de prueba (por defecto, uno único)")
    parser.add_argument(
        "--solo-lectura",
        action="store_true",
        help="El sitio está en «Solo revisar»: exige que toda escritura se rechace con su causa",
    )
    args = parser.parse_args()
    title = args.titulo or f"Escrito por la IA {secrets.token_hex(3)}"

    base = args.base.rstrip("/")
    endpoint = f"{base}/wp-json/nexo-mcp/v1/mcp"
    jar = http.cookiejar.CookieJar()
    web = build_opener(jar)
    raw = build_opener(jar, follow=False)
    plain = build_opener(http.cookiejar.CookieJar())

    step("1. Endpoint MCP sin credenciales")
    status, headers, _ = request(plain, "POST", endpoint, "{}", {"Content-Type": "application/json"})
    challenge = headers.get("WWW-Authenticate", "")
    print(status, challenge)
    assert status == 401 and "resource_metadata=" in challenge, "Falta el desafío OAuth"
    prm_url = re.search(r'resource_metadata="([^"]+)"', challenge).group(1)

    step("2. Descubrimiento")
    _, _, body = request(plain, "GET", prm_url)
    prm = json.loads(body)
    issuer = prm["authorization_servers"][0]
    _, _, body = request(plain, "GET", f"{issuer}/.well-known/oauth-authorization-server")
    meta = json.loads(body)
    scope = args.scope or " ".join(prm.get("scopes_supported", ["mcp:connect"]))
    print("recurso:", prm["resource"])
    print("autorización:", meta["authorization_endpoint"])
    print("scopes pedidos:", scope)

    step("3. Registro dinámico de cliente")
    status, _, body = request(
        plain,
        "POST",
        meta["registration_endpoint"],
        json.dumps(
            {
                "client_name": "Prueba punta a punta",
                "redirect_uris": [REDIRECT_URI],
                "grant_types": ["authorization_code", "refresh_token"],
                "response_types": ["code"],
                "token_endpoint_auth_method": "none",
            }
        ),
        {"Content-Type": "application/json"},
    )
    print(status, body[:200])
    client_id = json.loads(body)["client_id"]

    step("4. Inicio de sesión y consentimiento")
    request(web, "GET", f"{base}/wp-login.php")
    request(
        web,
        "POST",
        f"{base}/wp-login.php",
        {"log": args.user, "pwd": args.password, "wp-submit": "Acceder", "testcookie": "1"},
    )
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
    status, _, page = request(web, "GET", authorize_url)
    print("pantalla de consentimiento:", status, "Autorizar acceso" in page)
    fields = {
        html.unescape(name): html.unescape(value)
        for name, value in re.findall(r'<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', page)
    }
    for name, value in re.findall(r'<input type="hidden" id="[^"]*" name="([^"]+)" value="([^"]*)"', page):
        fields[html.unescape(name)] = html.unescape(value)
    fields["nexo_mcp_decision"] = "allow"
    status, headers, _ = request(raw, "POST", authorize_url, fields)
    location = headers.get("Location", "")
    print(status, location[:120])
    params = urllib.parse.parse_qs(urllib.parse.urlparse(location).query)
    assert params.get("state", [""])[0] == state, "state no coincide"
    code = params["code"][0]

    step("5. Token con PKCE")
    status, _, body = request(
        plain,
        "POST",
        meta["token_endpoint"],
        {
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": REDIRECT_URI,
            "client_id": client_id,
            "code_verifier": verifier,
            "resource": prm["resource"],
        },
    )
    token = json.loads(body)
    print(status, {k: (v if k not in ("access_token", "refresh_token") else "…") for k, v in token.items()})
    access = token["access_token"]

    step("6. Herramientas MCP con el token")
    session = {}

    def rpc(method, params=None, rid=1):
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": f"Bearer {access}",
        }
        if "id" in session:
            headers["Mcp-Session-Id"] = session["id"]
        payload = {"jsonrpc": "2.0", "method": method, "params": params or {}}
        if rid is not None:
            payload["id"] = rid
        status, headers_out, body = request(plain, "POST", endpoint, json.dumps(payload), headers)
        if "Mcp-Session-Id" in headers_out:
            session["id"] = headers_out["Mcp-Session-Id"]
        return status, (json.loads(body) if body.strip() else None)

    status, init = rpc(
        "initialize",
        {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "prueba", "version": "1"}},
    )
    print("initialize:", status, init["result"]["serverInfo"])
    rpc("notifications/initialized", rid=None)
    status, listing = rpc("tools/list", rid=2)
    names = [tool["name"] for tool in listing["result"]["tools"]]
    print(f"tools/list: {len(names)} herramientas")
    print("  ", ", ".join(sorted(names)))
    assert any(n.endswith("get-working-copy-diff") for n in names), "get-working-copy-diff debe aparecer en tools/list"

    def call(name, arguments, rid):
        status, out = rpc("tools/call", {"name": name, "arguments": arguments}, rid)
        result = out.get("result", out.get("error"))
        text = json.dumps(result, ensure_ascii=False)
        print(f"{name}: {text[:300]}")
        return result

    prefix = names[0].split("-")[0] + "-mcp-"
    # Las herramientas de escritura pueden no listarse en «Solo revisar»: se
    # llaman por su nombre igual, porque el rechazo es lo que se prueba.
    tool = lambda short: next((n for n in names if n.endswith(short)), prefix + short)  # noqa: E731
    info = call(tool("get-site-info"), {}, 3)
    assert not info.get("isError"), "get-site-info falló"
    call(tool("list-pages"), {"per_page": 3}, 4)
    created = []
    try:
        return run_writes(args, token, call, tool, title, created)
    finally:
        cleanup(call, tool, created)


def text_of(result):
    return json.dumps(result, ensure_ascii=False)


def cleanup(call, tool, created):
    """Limpia lo que creó la prueba.

    Todo va a la papelera y se puede restaurar desde WordPress: los posts
    nuevos y también las copias de trabajo descartadas.
    """
    if not created:
        return
    step("Limpieza")
    for rid, post_id in enumerate(created, start=50):
        result = call(tool("set-content-status"), {"id": post_id, "status": "trash"}, rid)
        if result.get("isError"):
            print(f"AVISO: no se pudo limpiar el contenido {post_id}")


def run_writes(args, token, call, tool, title, created):
    write = call(tool("create-post"), {"title": title}, 5)
    if not write.get("isError"):
        created.append(json.loads(write["content"][0]["text"])["id"])
    granted = token.get("scope", "").split()

    if args.solo_lectura:
        step("Modo «Solo revisar»: la escritura se rechaza")
        assert write.get("isError"), "En «Solo revisar» create-post debe rechazarse"
        assert "Solo revisar" in text_of(write), "El rechazo debe explicar que el sitio está en «Solo revisar»"
        page = json.loads(call(tool("list-pages"), {"per_page": 1}, 6)["content"][0]["text"])["result"][0]
        edit = call(tool("update-page"), {"id": page["id"], "title": page["title"] + " (no debe guardarse)"}, 7)
        assert edit.get("isError"), "En «Solo revisar» update-page debe rechazarse"
        assert "Solo revisar" in text_of(edit), "El rechazo de update-page debe explicar que el sitio está en «Solo revisar»"
        after = json.loads(call(tool("get-page"), {"id": page["id"]}, 8)["content"][0]["text"])
        assert after["title"] == page["title"], "Un rechazo no debe cambiar la página"
        print(f"\nOK: «Solo revisar» rechaza create-post y update-page; la página {page['id']} no cambió.")
        return 0

    if "mcp:write" not in granted:
        assert write.get("isError"), "Una conexión sin mcp:write no debería poder escribir"
        print("\n(Sin mcp:write en esta conexión, o con el sitio en «Solo revisar», la escritura se rechaza con su causa.)")
    else:
        assert not write.get("isError"), "create-post debería funcionar en modo edición"
        step("7. Editar contenido publicado → copia de trabajo")
        pages = json.loads(call(tool("list-pages"), {"per_page": 1}, 6)["content"][0]["text"])["result"]
        page = pages[0]
        staged = call(tool("update-page"), {"id": page["id"], "title": page["title"] + " (editado por IA)"}, 7)
        staged_data = json.loads(staged["content"][0]["text"])
        assert staged_data.get("draft_of") == page["id"], "La edición debería ir a una copia de trabajo"
        created.append(staged_data["id"])
        print(f"copia de trabajo: {staged_data['id']}")
        diff = json.loads(call(tool("get-working-copy-diff"), {"id": page["id"]}, 30)["content"][0]["text"])
        print("get-working-copy-diff:", json.dumps(diff, ensure_ascii=False, indent=2))
        assert diff["original_id"] == page["id"], "El diff debe nombrar al original"
        assert diff["copy_id"] == staged_data["id"], "El diff debe nombrar a la copia creada"
        assert "title" in diff["changed"], "El título cambió y debe figurar en changed"
        assert diff["title"]["after"].endswith(" (editado por IA)"), "El título nuevo debe traer el sufijo"
        assert diff["title"]["before"] == page["title"], "El título anterior es el del original"
        assert "content" not in diff["changed"], "El contenido no cambió"
        assert diff["stale"] is False, "El original no cambió: la copia no está obsoleta"
        by_copy = json.loads(call(tool("get-working-copy-diff"), {"id": staged_data["id"]}, 31)["content"][0]["text"])
        assert (by_copy["original_id"], by_copy["copy_id"]) == (page["id"], staged_data["id"]), (
            "Pedir el diff con el id de la copia debe resolver el mismo par"
        )
        original = json.loads(call(tool("get-page"), {"id": page["id"]}, 8)["content"][0]["text"])
        assert original["title"] == page["title"], "El original publicado no debe cambiar"
        print(f"La página publicada {page['id']} sigue igual; el cambio quedó en el borrador {staged_data['id']}.")
        step("8. Acción irreversible sin confirmación")
        result = call(tool("restore-revision"), {"id": page["id"]}, 9)
        assert result.get("isError") or "error" in json.dumps(result), "restore-revision sin confirmación debe rechazarse"
        step("9. Acción irreversible con confirmación inválida")
        before = json.loads(call(tool("get-page"), {"id": page["id"]}, 10)["content"][0]["text"])
        for rid, word in enumerate(("restore", "NOPE", "RESTORE ", ""), start=11):
            result = call(tool("restore-revision"), {"id": page["id"], "confirmation": word}, rid)
            assert result.get("isError"), f"restore-revision con confirmation={word!r} debe rechazarse"
            assert "RESTORE" in text_of(result), (
                f"El rechazo con confirmation={word!r} debe pedir la palabra exacta RESTORE"
            )
        after = json.loads(call(tool("get-page"), {"id": page["id"]}, 20)["content"][0]["text"])
        assert after == before, "Una confirmación inválida no debe cambiar la página"
        print(f"La página {page['id']} no cambió tras 4 confirmaciones inválidas.")
        if "mcp:publish" in granted:
            publish_working_copy(call, tool, created[0])
        else:
            # Sin publicar no hay copia de trabajo que fusionar: se avisa en vez de callar.
            step("10. Publicar una copia de trabajo → se fusiona en el original")
            print("SALTEADO: el scope concedido no incluye mcp:publish; no se prueba la fusión de la copia.")
    print("\nOK: conectar → autorizar OAuth → usar herramientas funciona de punta a punta.")
    return 0


def publish_working_copy(call, tool, post_id):
    """Publica una copia de trabajo y comprueba la fusión en el original.

    Usa el post que creó la prueba, nunca una página real del sitio. Cambia
    contenido y extracto pero no el título, que es lo que la limpieza usa para
    encontrarlo en la papelera.
    """
    step("10. Publicar una copia de trabajo → se fusiona en el original")
    published = call(tool("set-content-status"), {"id": post_id, "status": "publish"}, 40)
    assert not published.get("isError"), f"Publicar el post de prueba debe funcionar: {text_of(published)}"
    before = json.loads(call(tool("get-post"), {"id": post_id}, 41)["content"][0]["text"])
    assert before["status"] == "publish", "El post de prueba debe quedar publicado"
    content = "<p>Publicado desde la copia de trabajo</p>"
    excerpt = "Extracto desde la copia"
    staged = call(tool("update-post"), {"id": post_id, "content": content, "excerpt": excerpt}, 42)
    assert not staged.get("isError"), f"Editar el post publicado debe crear su copia de trabajo: {text_of(staged)}"
    copy_id = json.loads(staged["content"][0]["text"])["id"]
    assert json.loads(staged["content"][0]["text"]).get("draft_of") == post_id, (
        "Editar el post publicado debe ir a una copia de trabajo"
    )
    still = json.loads(call(tool("get-post"), {"id": post_id}, 43)["content"][0]["text"])
    assert still["content"] == before["content"], "Antes de publicar la copia, el original no cambia"
    diff = json.loads(call(tool("get-working-copy-diff"), {"id": post_id}, 44)["content"][0]["text"])
    assert diff["copy_id"] == copy_id, "El diff debe nombrar a la copia nueva"
    assert {"content", "excerpt"} <= set(diff["changed"]), "El diff debe listar contenido y extracto"
    assert "title" not in diff["changed"], "El título no cambió"
    assert "+" + content in diff["content_diff"], "El diff de contenido debe traer la línea nueva"
    assert diff["stale"] is False, "Nadie tocó el original: la copia no está obsoleta"

    merged = call(tool("set-content-status"), {"id": copy_id, "status": "publish"}, 45)
    assert not merged.get("isError"), f"Publicar la copia debe fusionarla: {text_of(merged)}"
    merged_data = json.loads(merged["content"][0]["text"])
    assert (merged_data["id"], merged_data["merged_from"]) == (post_id, copy_id), (
        "La fusión debe nombrar al original y a la copia"
    )
    after = json.loads(call(tool("get-post"), {"id": post_id}, 46)["content"][0]["text"])
    assert after["content"] == content, "El original debe traer el contenido de la copia"
    assert after["excerpt"] == excerpt, "El original debe traer el extracto de la copia"
    assert after["title"] == before["title"], "El título del original no cambió"
    assert after["status"] == "publish", "El original sigue publicado"
    gone = call(tool("get-post"), {"id": copy_id}, 47)
    assert gone.get("isError"), "La copia fusionada debe dejar de existir"
    no_copy = call(tool("get-working-copy-diff"), {"id": post_id}, 48)
    assert no_copy.get("isError"), "Tras la fusión el original ya no tiene copia de trabajo"
    print(f"La copia {copy_id} se fusionó en el post {post_id} y dejó de existir.")


if __name__ == "__main__":
    sys.exit(main())
