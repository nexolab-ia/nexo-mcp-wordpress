"""Prueba sin docker de Cliente.rpc (tools/prueba_adaptadores.py) frente al límite de pedidos del transporte MCP.

El plugin limita el transporte a 240 pedidos por minuto por identidad (TransportGuard; desde 1.4.3 una unidad por
pedido y 429 con `Retry-After` al pasarlo). El cliente de prueba no debe chocar con el límite: mantiene un pedido cada
`INTERVALO_MINIMO` segundos como máximo. Una respuesta que no es JSON-RPC (p. ej. el 429 o un 403) da un error claro
con el cuerpo. `request`, el reloj y la espera se reemplazan: no hay red ni demora.

    python3 -I tools/prueba_cliente_limite.py      (desde app/)
"""
import importlib.util
import json
import pathlib
import sys

spec = importlib.util.spec_from_file_location("adaptadores", pathlib.Path("tools/prueba_adaptadores.py").resolve())
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

BIEN = (200, {}, json.dumps({"jsonrpc": "2.0", "id": 1, "result": {"content": [{"type": "text", "text": "{\"ok\": true}"}]}}))
PROHIBIDO = (403, {}, json.dumps({"code": "rest_forbidden", "message": "Lo siento, no estás autorizado a realizar esto.", "data": {"status": 403}}))

fallas = []


def cliente(respuestas, avance=0.0):
    """Cliente sin conectar, con `request`, reloj y espera simulados. `avance`: segundos que pasan en cada pedido."""
    cli = object.__new__(mod.Cliente)
    cli.endpoint, cli.plain, cli.session, cli.names, cli.next_id, cli.access = "http://x/mcp", None, {}, [], 100, "t"
    tiempo, esperas, pedidos = [1000.0], [], []

    def request(_opener, _metodo, _url, cuerpo, _headers):
        pedidos.append(json.loads(cuerpo))
        tiempo[0] += avance
        return respuestas.pop(0)

    def esperar(segundos):
        esperas.append(round(segundos, 3))
        tiempo[0] += segundos

    mod.request, mod.reloj, mod.esperar = request, (lambda: tiempo[0]), esperar
    mod._ultimo_pedido[0] = None
    return cli, pedidos, esperas


def comprobar(nombre, fn):
    try:
        fn()
        print(f"OK   {nombre}")
    except Exception as err:  # noqa: BLE001 - una falla no debe ocultar las demás
        print(f"FALLA {nombre}: {err!r}")
        fallas.append(nombre)


def ritmo():
    cli, pedidos, esperas = cliente([BIEN, BIEN, BIEN])
    for _ in range(3):
        cli.call("x", {})
    assert len(pedidos) == 3 and esperas == [mod.INTERVALO_MINIMO] * 2, esperas
    assert 60 / mod.INTERVALO_MINIMO < 240, f"{60 / mod.INTERVALO_MINIMO:.0f} pedidos por minuto no queda bajo 240"


def sin_espera_si_ya_paso():
    cli, pedidos, esperas = cliente([BIEN, BIEN], avance=1.0)
    cli.call("x", {})
    cli.call("x", {})
    assert esperas == [], esperas


def respuesta_inesperada():
    cli, _, _ = cliente([PROHIBIDO])
    try:
        cli.call("x", {})
    except AssertionError as err:
        assert "rest_forbidden" in str(err) and "respuesta" in str(err), err
    else:
        raise AssertionError("una respuesta que no es JSON-RPC debía dar un error claro")


comprobar("ritmo: como máximo un pedido cada INTERVALO_MINIMO (bajo 240 por minuto)", ritmo)
comprobar("ritmo: no espera si ya pasó el intervalo", sin_espera_si_ya_paso)
comprobar("respuesta que no es JSON-RPC (p. ej. 403 del límite): error claro con el cuerpo", respuesta_inesperada)
print("OK: cliente frente al límite" if not fallas else f"FALLA: {len(fallas)} comprobación(es) del cliente fallaron.")
sys.exit(1 if fallas else 0)
