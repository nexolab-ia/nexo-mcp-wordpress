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
    args = parser.parse_args()

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

    def call(name, arguments, rid):
        status, out = rpc("tools/call", {"name": name, "arguments": arguments}, rid)
        result = out.get("result", out.get("error"))
        text = json.dumps(result, ensure_ascii=False)
        print(f"{name}: {text[:300]}")
        return result

    tool = lambda short: next(n for n in names if n.endswith(short))  # noqa: E731
    info = call(tool("get-site-info"), {}, 3)
    assert not info.get("isError"), "get-site-info falló"
    call(tool("list-pages"), {"per_page": 3}, 4)
    write = call(tool("create-post"), {"title": "Escrito por la IA"}, 5)
    granted = token.get("scope", "").split()

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
        original = json.loads(call(tool("get-page"), {"id": page["id"]}, 8)["content"][0]["text"])
        assert original["title"] == page["title"], "El original publicado no debe cambiar"
        print(f"La página publicada {page['id']} sigue igual; el cambio quedó en el borrador {staged_data['id']}.")
        step("8. Acción irreversible sin confirmación")
        result = call(tool("restore-revision"), {"id": page["id"]}, 9)
        assert result.get("isError") or "error" in json.dumps(result), "restore-revision sin confirmación debe rechazarse"
    print("\nOK: conectar → autorizar OAuth → usar herramientas funciona de punta a punta.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
