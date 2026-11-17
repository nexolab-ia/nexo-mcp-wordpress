# Bloque 1: calidad y entrega

CI en GitHub Actions, análisis estático (PHPStan, PHPCS, Plugin Check), release limpio del .zip,
eliminar el verificador Rust y publicar el repo privado en GitHub.

## Specs

- S1. Bloque 1 tal como se propuso: «CI en GitHub Actions: correr PHPUnit y la prueba de punta a punta (`prueba_reglas.sh` y `prueba_adaptadores.sh`) contra el docker en cada push. Hoy todo se corre a mano.»
- S2. «Análisis estático: PHPStan, PHPCS con los estándares de WordPress y el Plugin Check oficial de wordpress.org.»
- S3. PHPStan: «Nivel 5 con baseline (Recomendado)»: «Corrijo los 7 de nivel 0 (suelen ser errores reales). Los demás quedan congelados en un baseline y el CI exige nivel 5 al código nuevo.»
- S4. wordpress.org: «No, se distribuye por .zip»: «Corrijo los errores reales (seguridad, escapado y acceso directo a archivos) y dejo documentados como excluidos los propios del directorio.»
- S5. «Release: que `empaquetar.sh` genere un `.zip` limpio, con versionado y changelog.»
- S6. Verificador: «Sí, eliminarlo»: «Lo borro del repo y del disco. Queda en el historial de git (commit base).» Nota: con S7 el historial se reescribe, así que no queda en el historial publicado.
- S7. GitHub: «crea el repo en github para subirlo». Visibilidad: «Privado (Recomendado)». Nombre: «nexo-mcp-wordpress». Contenido: «Solo el plugin y su tooling (Recomendado)»: «Saco del repo referencia-original/ y los artefactos del craft (siguen en tu disco, ignorados por git). El historial se reescribe antes del primer push para que no queden en él.»

## Tasks

- [x] T1 (S6, S7 contenido) Respaldo del repo (git bundle), quitar del historial referencia-original/ y artefactos del craft, ignorarlos; eliminar verificador Rust (app/src, Cargo.*, app/target, app/shots). Ruta: inline. Commit: —
- [x] T2 (S2) PHPCS: phpcs.xml.dist (WordPress + PHPCompatibilityWP 8.1+, excluir sniffs de nombre de archivo por PSR-4), dev deps en composer.json, phpcbf + arreglos. Ruta: worker. Commit: —
- [x] T3 (S2, S3) PHPStan nivel 5 + baseline; corregir los errores de nivel 0. Ruta: worker. Commit: —
- [x] T4 (S2, S4) Plugin Check: corregir errores reales; documentar exclusiones propias del directorio. Ruta: worker. Commit: —
- [x] T5 (S5) empaquetar.sh: versión coherente, zip limpio, CHANGELOG. Ruta: inline. Commit: —
- [ ] T6 (S1) Workflow de GitHub Actions: PHPUnit, PHPCS, PHPStan, e2e en docker, artefacto .zip. Ruta: inline. Commit: —
- [ ] T7 (S7) Crear repo privado nexolab-ia/nexo-mcp-wordpress, push, CI en verde. Ruta: inline. Commit: —

## Log

- L1 (2026-11-16): «Realicemos el bloque 1»
- L2 (2026-11-16): respuestas: PHPStan «Nivel 5 con baseline (Recomendado)»; wordpress.org «No, se distribuye por .zip»; GitHub «crea el repo en github para subirlo»; verificador «Sí, eliminarlo»; visibilidad «Privado (Recomendado)»; nombre «nexo-mcp-wordpress»; contenido «Solo el plugin y su tooling (Recomendado)».
- L3 (2026-11-16): medición inicial. PHPStan (src, con stubs WP+WooCommerce): nivel 0 = 7, 3 = 77, 5 = 104, 6 = 114, 8 = 177. PHPCS WordPress: 258 (187 por nombres de archivo PSR-4, 68 alineación auto-corregible, 2 otros). PHPCompatibilityWP 8.1+: 0. Plugin Check 2.2.0: 9 errores (application_detected, plugin_updater_detected, readme no en inglés x2, outdated_tested_upto_header, missing_direct_file_access_protection, OutputNotEscaped, parse_url, strip_tags) y 119 avisos (muchos en tests/bootstrap.php; 17 UnescapedDBParameter).
- L4 (2026-11-16): T1. Respaldo `.respaldo/antes-de-limpiar-historial.bundle` (verificado, HEAD 2ded9ad). `git filter-branch --index-filter` quitó referencia-original/ y los artefactos del craft de los 7 commits (.git 2,8M -> 528K). Incidente: al reponer el árbol, filter-branch borró del disco esos archivos; se restauraron exactos desde el bundle (413 archivos; el árbol estaba limpio antes). Ahora están en .gitignore. Verificador Rust eliminado (app/src, Cargo.*, app/target, app/shots).
- L5 (2026-11-16): T2. phpcs.xml.dist (WordPress + PHPCompatibilityWP 8.1-, sin WordPress.Files.FileName por PSR-4; variables de vistas excluidas de PrefixAllGlobals; 3 phpcs:ignore en hooks de terceros/núcleo). phpcbf + arreglos manuales; finfo_close() quitada (obsoleta, no-op desde PHP 8.0). phpcs exit 0, PHPUnit OK 56, prueba_reglas.sh exit 0. composer.lock: packages de producción idénticos.
- L6 (2026-11-16): T3. PHPStan 2.3.6 nivel 5 + baseline (62 errores congelados; L3 contaba 104 porque escaneaba vendor/). Los 7 de nivel 0 no eran bugs: 5 funciones ACF resueltas con php-stubs/acf-pro-stubs; 2 avisos WP_ADMIN/DOING_AJAX en BearerTokenGuard::requested_route(). El worker los cambió a is_admin()/wp_doing_ajax(); el padre lo revirtió (wp_doing_ajax() es filtrable y la función decide el confinamiento del token) y los ignoró en phpstan.neon.dist con el motivo. phpstan/phpcs exit 0, PHPUnit OK 56.
- L7 (2026-11-16): T4. Los errores reales (acceso directo, escapado, parse_url, strip_tags) ya los había resuelto T2. Quedan 7 errores solo-directorio, en la allowlist de tools/plugin_check.sh: application_detected x3 (phpstan/phpcs/phpunit .dist en la raíz), plugin_updater_detected, readme en español x2, outdated_tested_upto_header. Los 17 UnescapedDBParameter (TokenRepository) son seguros: solo se interpola $wpdb->prefix + nombre fijo; los valores van por prepare(). Se anotaron con phpcs:ignore y el motivo. Se quitó el `Domain Path: /languages` (no existe la carpeta). plugin_check.sh/phpstan/phpcs/phpunit exit 0. Pendiente para T5: sacar los .dist del zip.
- L8 (2026-11-16): T5. empaquetar.sh valida que Version:, NEXO_MCP_VERSION y Stable tag coincidan y que haya entrada en el changelog de readme.txt (ambos rechazos probados en una copia temporal, exit 1). Genera dist/nexo-mcp-<versión>.zip sin tests ni .dist/.neon y revisa su contenido. Versión subida a 1.0.1 con el changelog de esta sesión (el changelog vive en readme.txt, estándar de WordPress). Zip 1.0.1: 494 archivos, exit 0.
