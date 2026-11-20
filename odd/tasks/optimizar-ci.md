# Optimizar el CI

Bajar cuántas veces corre el CI y cuánto tarda el e2e, que es casi todo el tiempo (~32 min de ~33 por corrida). El repo es
público desde el 2026-11-20, así que los minutos ya no se cobran: se optimiza la espera. Rama `ci/optimizar` desde `main`
(d9fc16f).

## Specs

- S1. Pedido: «Optimicemos el CI», sobre la propuesta: «Primero el punto 1, ahora: son 5 líneas, no tiene riesgo y
  elimina la mitad de las corridas. Después el punto 2, en su propio PR, midiendo antes y después.»
- S2. Disparadores (punto 1 de la propuesta): `push` sólo en `main`, `pull_request` y `workflow_dispatch`; los commits que
  sólo tocan `odd/**` o `**/*.md` no corren el CI (`readme.txt` sí, va en el .zip); el push de un tag no corre nada (la
  release usa el .zip de la corrida de `main` del mismo commit, como v1.4.2); `cancel-in-progress` sólo para PRs, así una
  corrida de `main` en curso (la que da el .zip de la release) no se cancela (una en espera sí puede: ver L2). Sin protección de rama en `main`: `paths-ignore` no
  deja checks requeridos colgados.
- S3. Contenedor único de wp-cli (punto 2): medido en docker local, `docker compose run --rm` ~1,9 s por llamada contra
  `docker exec` ~0,4 s. Ayudante compartido `app/tools/wpcli.sh`: con `NEXO_WPCLI` (contenedor vivo) cada llamada es un
  `docker exec`; sin él, como hoy. `prueba_adaptadores.sh` lo levanta al empezar (exportado: los subcomandos que lanza
  Python lo heredan), lo baja al final y también si la corrida se corta, y no baja uno que le pasaron de afuera. El
  contenedor vive como máximo 2 h (`sleep 7200`), así uno perdido (SIGKILL) se va solo. Medir el e2e antes y después.

## Tasks

- [x] T1 (S2) `ci.yml`: disparadores, `paths-ignore`, concurrencia. Ruta: inline + verify (Risk: item 5). Commit: abacaa2
- [x] T2 (S3) `tools/wpcli.sh` + los 4 scripts + prueba sin docker + e2e medido. Ruta: inline. Commit: ver L6

## Log

- L1 (2026-11-20): «Optimicemos el CI». Exploración: `main` sin protección ni rulesets; release v1.4.2 con el .zip de la
  corrida de `main` (c2a61c4); CI de #20: quality < 1 min, e2e 32 min; wp-cli 1,9 s (`run --rm`) contra 0,4 s (`exec`);
  `prueba_adaptadores.sh` concentra las llamadas (los otros scripts 4-8 cada uno).
- L2 (2026-11-20): T1 en abacaa2; actionlint sin errores. Verificador independiente (Risk: item 5): S2 PASS en los 6
  puntos, sin bloqueantes. Notas: con tres merges a `main` en menos de ~33 min, la corrida del medio (en espera) se
  cancela igual (una sola en espera por grupo) — antes era peor, se acepta; un cambio sólo a `app/nexo-mcp/NOTICE.md`
  (va en el .zip) no corre CI, inofensivo porque toda release cambia la versión en `.php`/`readme.txt`.
- L3 (2026-11-20): review nativa (riesgo, fiabilidad) aprobada, lineage review-a3bbc8c4ed3453f6; su aviso
  R3-pending-main-run-cancelled es la nota ya aceptada de L2; S2 aclara «en curso».
- L4 (2026-11-20): T2 primera corrida e2e con el contenedor único: 6 min 40 s (antes ~22 min), sin contenedor
  perdido, pero 12 FALLA en la escritura del sistema de diseño (`argument of type 'NoneType' is not iterable`). Causa:
  el límite del transporte MCP (`TransportGuard.php:181`, 240 pedidos/min por identidad); con wp-cli rápido la prueba lo
  pasa. Sonda en vivo (300 `tools/list` seguidos): al pasarlo el plugin responde `403 rest_forbidden` («no estás
  autorizado»), no 429 ni `retry_after` (el `WP_Error` de `TransportGuard.php:190-193` no llega al cliente). Arreglo de
  la prueba: el cliente mantiene un pedido cada 0,26 s como máximo (~230/min) y una respuesta que no es JSON-RPC da un
  error claro con el cuerpo; `tools/prueba_cliente_limite.py` (3 casos, RED 3, GREEN) corre al empezar el e2e.
  Hallazgo del plugin, fuera de este alcance: el límite del transporte se ve como falta de permiso, sin `retry_after`.
- L5 (2026-11-20): segunda corrida con el ritmo de 0,26 s: 7 min 14 s, 5 FALLA (403 en el bloque de diseño). Causa raíz
  medida en vivo (auditoría `wp_nexo_mcp_audit_log`: 64 `limited`/`nexo_mcp_rate_limit_exceeded`; contador
  `wp_nexo_mcp_rate_limits`): cada pedido MCP consume 4 unidades del límite (10 `tools/list` = 40; 10 `tools/call` = 40),
  así que el techo real es ~60 pedidos/min, no 240. El chequeo de `TransportGuard` corre varias veces por pedido (el
  adaptador MCP y WordPress evalúan el permiso más de una vez; `vendor/wordpress/mcp-adapter/.../HttpTransport.php:85-99`
  además convierte el `WP_Error` en `false` → `rest_forbidden` 403 sin `retry_after`). `wp db query` falla por TLS contra
  MySQL en el docker (por eso la primera medición daba 0): se mide con `$wpdb` en `wp eval`. Decisión pendiente del usuario.
- L6 (2026-11-20): el límite del transporte se arregló aparte (`odd/tasks/limite-transporte.md`, PR #22, 1.4.3: una
  unidad por pedido, 429 + `Retry-After`). T2 rebasado sobre `main` (c9150b4). e2e completo local con el contenedor
  único y el cliente a 0,26 s: exit 0, 178 OK, sin FALLA ni AVISO, **7 min 37 s** (antes ~22 min); el contenedor de
  wp-cli no queda vivo. Pruebas sin docker: `prueba_php_wp.sh` (modo `exec`/`compose run`, levantar/bajar),
  `prueba_limpieza.sh` (baja el contenedor tras la limpieza, también con señales), `prueba_cliente_limite.py` (ritmo).
- L7 (2026-11-20): verificador independiente (Risk: item 6): 5/5 PASS, sin bloqueantes (corte en vivo con TERM: exit
  143, sin contenedor `sleep 7200` vivo, ajustes idénticos). Review nativa (resiliencia, fiabilidad) aprobada, lineage
  review-5cef7f5037708776. Corregido: los comentarios del ritmo decían «403 sin retry_after» (desde 1.4.3 es 429 +
  `Retry-After`). Aceptado sin cambio: con `NEXO_WPCLI` apuntando a un contenedor muerto cada llamada falla en silencio
  (improbable en ~8 min); una señal dentro de una función con salida a `/dev/null` oculta el texto de la limpieza (que
  igual corre; viene del diseño anterior); una señal en los 1-2 s de `wpcli_levantar` puede dejar un contenedor sin
  registrar (se va solo a las 2 h).
