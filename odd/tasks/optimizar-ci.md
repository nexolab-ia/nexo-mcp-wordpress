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
  corrida de `main` (la que da el .zip de la release) no se cancela. Sin protección de rama en `main`: `paths-ignore` no
  deja checks requeridos colgados.
- S3. Contenedor único de wp-cli (punto 2): medido en docker local, `docker compose run --rm` ~1,9 s por llamada contra
  `docker exec` ~0,4 s. Ayudante compartido `app/tools/wpcli.sh`: con `NEXO_WPCLI` (contenedor vivo) cada llamada es un
  `docker exec`; sin él, como hoy. `prueba_adaptadores.sh` lo levanta al empezar (exportado: los subcomandos que lanza
  Python lo heredan), lo baja al final y también si la corrida se corta, y no baja uno que le pasaron de afuera. El
  contenedor vive como máximo 2 h (`sleep 7200`), así uno perdido (SIGKILL) se va solo. Medir el e2e antes y después.

## Tasks

- [x] T1 (S2) `ci.yml`: disparadores, `paths-ignore`, concurrencia. Ruta: inline + verify (Risk: item 5). Commit: abacaa2
- [ ] T2 (S3) `tools/wpcli.sh` + los 4 scripts + prueba sin docker + e2e medido. Ruta: inline. Commit: —

## Log

- L1 (2026-11-20): «Optimicemos el CI». Exploración: `main` sin protección ni rulesets; release v1.4.2 con el .zip de la
  corrida de `main` (c2a61c4); CI de #20: quality < 1 min, e2e 32 min; wp-cli 1,9 s (`run --rm`) contra 0,4 s (`exec`);
  `prueba_adaptadores.sh` concentra las llamadas (los otros scripts 4-8 cada uno).
- L2 (2026-11-20): T1 en abacaa2; actionlint sin errores. Verificador independiente (Risk: item 5): S2 PASS en los 6
  puntos, sin bloqueantes. Notas: con tres merges a `main` en menos de ~33 min, la corrida del medio (en espera) se
  cancela igual (una sola en espera por grupo) — antes era peor, se acepta; un cambio sólo a `app/nexo-mcp/NOTICE.md`
  (va en el .zip) no corre CI, inofensivo porque toda release cambia la versión en `.php`/`readme.txt`.
