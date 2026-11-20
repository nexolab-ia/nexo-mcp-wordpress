# Límite del transporte MCP: una unidad por pedido y 429 con Retry-After

El límite del transporte (240 pedidos/min por usuario+IP) cuenta 4 veces cada pedido MCP, así que corta a ~60/min, y al
pasarlo el cliente recibe `403 rest_forbidden` («no autorizado») sin saber cuánto esperar. Lo encontró la optimización
del CI (`odd/tasks/optimizar-ci.md`, L4-L5). Rama `fix/limite-transporte` desde `main` (b17380c, v1.4.2).

## Specs

- S1. Decisión del usuario: «Arreglar el plugin primero (Recomendado)»: «PR aparte: contar una vez por pedido HTTP y
  responder 429 + Retry-After; release 1.4.3. Después el e2e rápido entra con el ritmo de 0,26 s.» Vista previa elegida:
  «- TransportGuard: una unidad por pedido HTTP (memo por request) - rechazo del límite: 429 + Retry-After (hoy 403
  rest_forbidden) - PHPUnit + e2e + verificador - release 1.4.3».
- S2. Causa (medida en vivo con una traza en el docker): WordPress evalúa el `permission_callback` de la ruta
  `/nexo-mcp/v1/mcp` en `WP_REST_Server::respond_to_request` y otra vez por cada método (POST, GET, DELETE) en
  `rest_send_allow_header` (filtro `rest_post_dispatch`), así que `TransportGuard::check()` consume 4 unidades de
  `RateLimiter` por pedido (10 `tools/list` = 40 unidades; 10 `tools/call` = 40). Cada denegación también se audita 4
  veces. El adaptador (`vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:85-99`) convierte el
  `WP_Error` del guardia en `false`, y WordPress responde `rest_forbidden` 403 sin el código ni `retry_after`.
- S3. Una evaluación por pedido: `TransportGuard::authorize()` acepta el `WP_REST_Request` que le pasa el adaptador; si es
  el mismo objeto (`===`) que el de la evaluación anterior, devuelve el mismo resultado sin volver a consumir el límite ni
  volver a auditar. Sin pedido (p. ej. `UploadController`, `authorize_upload_ticket()`), se comporta como hoy.
- S4. Rechazo por límite = 429: en la ruta `/nexo-mcp/v1/mcp`, cuando el guardia rechazó ese pedido con
  `nexo_mcp_rate_limit_exceeded`, la respuesta pasa de `403 rest_forbidden` a HTTP 429 con cuerpo
  `{"code":"nexo_mcp_rate_limit_exceeded","message":<el mismo mensaje>,"data":{"status":429,"retry_after":N}}` y
  encabezado `Retry-After: N`. Las demás denegaciones del transporte quedan exactamente como hoy (mismo estado, cuerpo
  y desafío `WWW-Authenticate` de `BearerTokenGuard::add_challenge_header`).
- S5. Pruebas PHPUnit (RED primero): una unidad por pedido aunque `authorize()` se llame 4 veces con el mismo pedido;
  pedidos distintos cuentan aparte; la denegación se audita una vez por pedido; el 429 con `retry_after` y
  `Retry-After`; una denegación que no es de límite no cambia; `authorize()` sin pedido y `authorize_upload_ticket()`
  siguen consumiendo como antes.
- S6. Comprobación en vivo en el docker (`app/`): el contador `wp_nexo_mcp_rate_limits` sube 1 por pedido (10
  `tools/list` = 10) y pasar el límite da 429 + `Retry-After`. `wp db query` falla por TLS contra MySQL del docker:
  medir con `$wpdb` dentro de `wp eval` (`source tools/wpcli.sh; wpcli eval '…'`).
- S7. Versión 1.4.3: `nexo-mcp.php` (`Version` y `NEXO_MCP_VERSION`), `readme.txt` (`Stable tag` y entrada de changelog
  en inglés, como la de 1.4.2), y la documentación que describa el límite del transporte si existe.

## Tasks

- [ ] T1 (S2-S7) Guardia con una evaluación por pedido, 429 + Retry-After, PHPUnit, comprobación en vivo, 1.4.3. Ruta:
  worker + verify (Risk: item 2, un guardia de seguridad). Commit: —

## Log

- L1 (2026-11-20): decisión del usuario (S1). Traza temporal en el docker (mu-plugin en el volumen, ya borrado):
  `respond_to_request` → `HttpTransport::check_permission` → `TransportGuard::authorize`, y tres veces más desde
  `rest_post_dispatch` → `rest_send_allow_header` → `check_permission`.
- L2 (2026-11-20): T1 (S2-S7) implementado, sin commit. `TransportGuard::authorize( $request = null )` recuerda el último
  `WP_REST_Request` (`===`) y su resultado (una unidad y una auditoría por pedido; sin pedido o `authorize_upload_ticket()`
  consumen como antes); `rate_limit_error()` expone el rechazo por límite y `ServerRegistrar::limit_response()`
  (`rest_post_dispatch`, prioridad 9, sólo `/nexo-mcp/v1/mcp`) lo convierte en 429 + `Retry-After`. 1.4.3 en
  `nexo-mcp.php` y `readme.txt` (entrada en español, como las de 1.4.x); `app/README.md` no documenta el límite, sin cambio.
  PHPUnit en el contenedor (`docker exec … php vendor/bin/phpunit`, el host no tiene PHP): `tests/TransportGuardTest.php`
  9 pruebas; RED 6 fallan (3 failures, 3 errors: 4 unidades en vez de 1, 4 auditorías, `limit_response()` inexistente) y 3
  pasan ya (PRESERVE: sin pedido, ticket, pedidos distintos); GREEN 9/9. Suite completa 883 pruebas OK; `phpcs -q` sin
  salida (0); `phpstan analyse` «No errors». En vivo (docker, `wp eval` con `$wpdb`): 10 `tools/list` = +10 en
  `wp_nexo_mcp_rate_limits` (antes 40). Con un mu-plugin temporal (`nexo_mcp_transport_rate_limit` = 6, ya borrado): 3 respuestas
  200 y luego `429`, `Retry-After: 58`, cuerpo
  `{"code":"nexo_mcp_rate_limit_exceeded","message":"Demasiadas solicitudes. Intenta nuevamente más tarde.","data":{"status":429,"retry_after":58}}`.
- L3 (2026-11-20): el changelog de `readme.txt` está en español («Corrección:»); el worker siguió la convención del
  archivo y no el «en inglés» de S7, que era un error de la especificación. `tools/prueba_reglas.sh` (OAuth, 401/403,
  solo lectura, confirmaciones) contra el docker con el cambio: exit 0, sin FALLA.
