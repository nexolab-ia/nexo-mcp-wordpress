# Mejoras del e2e `app/tools/prueba_adaptadores.sh`

Avisos pendientes de la review del e2e de F4 (sesión del 2026-11-19), sólo sobre el script de prueba; no cambia el plugin.
Rama `chore/e2e-robusto` desde `main` (c2a61c4, v1.4.2).

## Specs

- S1. «Restore `destructive_enabled` when the e2e is cut short.»
- S2. «Parse `wp eval` output tolerantly of Elementor shutdown notices.»
- S3. «Stop interpolating arguments into the `wp eval` PHP.»
- S4. «Add a `--` label rejection case, or drop the README claim.»
- S5. «Move the Python heredoc into its own file.»
- S7. Avisos de la review del rango completo (#19): «R3-cleanup-readback-masks-failure»: «the pipeline discards that signal»; «R3-signal-traps-untested»: «A small docker-free check that sources the trap and cleanup setup with stubbed functions, sends a signal and asserts the exit code and that cleanup ran would cover it cheaply.»
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

- [x] T5 (S7) `clase_global_quitada` (0/1/2) en la limpieza; `atrapar_senales`/`limpiar_al_salir` + `tools/prueba_limpieza.sh` sin docker. Ruta: inline. Rama `fix/e2e-avisos-review`. Commit: 0e7d438 y el siguiente (L8)

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
- L5 (2026-11-20): verificación independiente (Risk: item 6): S1-S6 PASS, sin bloqueantes; sondas reales con comillas,
  `$` y espacios en los valores, payload multilínea con avisos antes y después, bash 3.2 sin argumentos. Review nativa
  (resiliencia, fiabilidad) aprobada, lineage review-ac6d27b04904de8b. Alcance de S6: el `wp eval` que queda dentro del
  heredoc de concurrencia (Python, lee `LOCKED` como flujo y sólo recibe enteros con `%d`) no pasa por `php_wp` porque
  necesita la salida en vivo. Avisos aparte: R3-php-wp-failure-opaque (sin marca, `php_wp` devuelve vacío con código 0)
  y A4 (con `| tee` y Ctrl-C, un SIGPIPE en la limpieza; sin reproducir).
- L6 (2026-11-20): «Empuja rama y arregla el aviso». Rama empujada (`origin/chore/e2e-robusto`, assess: ya revisado).
  Aviso R3-php-wp-failure-opaque: `php_wp` sin marca no imprime nada, avisa por stderr y sale con 1; una salida vacía
  pero completa sigue saliendo con 0 (la marca va con `@` delante); lo que no es UTF-8 sale con � sin traceback;
  `php_wpr` reintenta una vez también si falló y sale con 1 si falla dos veces. Prueba nueva sin docker
  `app/tools/prueba_php_wp.sh` (9 casos; RED: 4 fallas; GREEN en bash 3.2), que el e2e corre al empezar, así CI la
  ejecuta sin tocar el workflow. e2e completo: exit 0, 155 OK, sin FALLA ni AVISO.
- L7 (2026-11-20): #19 mergeado (d4ba817) con CI verde. Decisión del usuario: «Arreglarlos en un PR chico» (avisos de la
  review del rango completo). T5: la comprobación de la clase pasa a `clase_global_quitada` (0 quitada, 1 sigue, 2
  lectura fallida → FALLA en vez de «quitadas»); traps en `atrapar_senales` y `limpiar_al_salir` (ignora señales y
  limpia; también al final normal). `tools/prueba_limpieza.sh`: INT/TERM/HUP con segunda señal durante la limpieza y los
  tres casos de la clase; RED (funciones ausentes), GREEN 9/9 en bash 3.2; mutación (sin `trap ''` y TERM → 1) detectada
  en TERM y HUP. El e2e la corre al empezar. e2e completo: exit 0, 165 OK, sin FALLA ni AVISO.
- L8 (2026-11-20): T5 verificada (verificador independiente: 4/4 PASS, mutantes detectados; review nativa aprobada,
  lineage review-af00f2560afdf0d2). Ajustes por sus avisos: R3-class-readback-empty-ok → salida vacía también es
  lectura fallida (2); prueba del final normal con Ctrl-C durante la limpieza (el mutante sin `trap ''` sale con 130).
  RED: 1 falla (vacía → 0); GREEN 12/12 en bash 3.2; en docker una clase inexistente da 0. El e2e completo de este
  ajuste lo corre CI.
