# Mejoras del e2e `app/tools/prueba_adaptadores.sh`

Avisos pendientes de la review del e2e de F4 (sesión del 2026-11-19), sólo sobre el script de prueba; no cambia el plugin.
Rama `chore/e2e-robusto` desde `main` (c2a61c4, v1.4.2).

## Specs

- S1. «Restore `destructive_enabled` when the e2e is cut short.»
- S2. «Parse `wp eval` output tolerantly of Elementor shutdown notices.»
- S3. «Stop interpolating arguments into the `wp eval` PHP.»
- S4. «Add a `--` label rejection case, or drop the README claim.»
- S5. «Move the Python heredoc into its own file.»
- S6. Diseño (padre):
  - S2+S3: un único ayudante `php_wp <código> [valores…]` reemplaza todo `wp eval`/`wpr eval` del script. Corre
    `wp eval --user=admin` con los valores como variables de entorno `NEXO_A1…NEXO_An` (el PHP los lee con `getenv`).
    Lo que el código imprime se envuelve en `@@NEXO@@<base64>@@NEXO@@`; el ayudante devuelve sólo eso, así los avisos
    de PHP de antes o después (cierre de Elementor) no llegan a la salida. `php_wpr` reintenta una vez si sale vacío,
    como `wpr`.
  - S1: `restore()` ya devuelve todos los ajustes (incluido `destructive_enabled`) desde la limpieza del trap EXIT;
    se agregan traps de INT/TERM/HUP que salen por EXIT, así una corrida cortada por señal también limpia.
  - S4: el código sí rechaza etiquetas que empiezan con `--` (`DesignVariableOperations.php`, `README.md:231`): se
    mantiene la afirmación y se agrega el caso al e2e (variable y clase).
  - S5: el heredoc del bloque de escritura del sistema de diseño (el más grande, ~410 líneas, el que agregó F4) pasa a
    `app/tools/prueba_diseno_escritura.py`.

## Tasks

- [x] T1 (S2, S3, S6) Ayudante `php_wp`/`php_wpr` y migración de todos los `wp eval`. Ruta: inline. Commit: 929b108
- [x] T2 (S1, S6) Traps de señales hacia la limpieza; prueba manual cortando la corrida. Ruta: inline. Commit: 929b108
- [x] T3 (S4, S5) Caso `--` y heredoc a archivo propio. Ruta: inline. Commit: el de L4
- [x] T4 (S1-S5) e2e completo en docker (exit 0) y corrida cortada. Ruta: inline. Commit: — (evidencia en L3, L4)

## Log

- L1 (2026-11-20): «Limpiemos ramas y terminemos las mejoras de e2e». Ramas: 21 locales y 18 remotas mergeadas borradas
  (las 3 locales sin mergear con `-D`, por decisión del usuario).
- L2 (2026-11-20): exploración: `restore()` ya cubre `destructive_enabled` salvo que la limpieza no corra; `--` sí se
  rechaza; sonda en docker: `-e NEXO_A1=…` llega intacto (comillas, `$`) y el aviso de cierre queda fuera de la marca.
- L3 (2026-11-20): T1-T3 inline. `php_wp`/`php_wpr` reemplazan los 30 `wp eval` (ninguno pega valores en el PHP); el
  `guardado*` por prefijo pasa a igualdad exacta. Casos `--x` en `create-global-class` y `create-design-variable`.
  Heredoc de escritura del sistema de diseño (409 líneas) → `app/tools/prueba_diseno_escritura.py`. e2e completo en
  docker: exit 0, 145 OK, sin FALLA ni AVISO, 22/22 escritura del sistema de diseño, huella final igual a la inicial,
  `destructive_enabled` quedó como estaba (21 min 41 s).
- L4 (2026-11-20): corrida cortada tras la preparación (ajustes ya cambiados): TERM al script → exit 143, Ctrl-C (INT al
  grupo) → exit 130; las dos corren la limpieza completa y dejan ajustes, experimentos y huella del sistema de diseño
  iguales a los de antes. Commits: T1+T2 929b108; T3 y este documento en el commit siguiente
  («test(e2e): reject `--` labels and move the design-system write checks to their own file»).
