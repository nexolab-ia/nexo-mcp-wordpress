# Herramienta para comparar la copia de trabajo con el original

Nueva herramienta MCP de lectura `nexo-mcp/get-working-copy-diff`: muestra qué cambió entre una
copia de trabajo (borrador de un contenido publicado, `src/WordPress/WorkingCopy.php`) y su original,
antes de publicarla (set-content-status publish) o descartarla (trash).

## Specs

- S1. Propuesta aceptada: «comparar la copia de trabajo con el original (`get-working-copy-diff`). Hoy la IA edita en un borrador, pero no hay forma de ver qué cambió antes de publicar.»
- S2. Contenido: «Diff unificado por líneas (Recomendado)»: «Como `git diff`: líneas con - y +, más algo de contexto, con un tope de tamaño. La IA puede explicarle a la persona exactamente qué cambió.» Ejemplo elegido:
  ```
  {
    "changed": ["title", "content"],
    "title": {"before": "Inicio", "after": "Inicio 2027"},
    "content_diff": "@@ -3,2 +3,2 @@\n-<p>Precio: $10</p>\n+<p>Precio: $12</p>"
  }
  ```
- S3. Metadatos: «Listar cambios con valores cortos (Recomendado)»: «Por cada clave cambiada, agregada o quitada muestra el antes y el después si es corto. Si es largo, como `_elementor_data`, solo dice que cambió y cuánto mide. Las categorías y etiquetas aparecen como agregadas o quitadas.»
- S4. Diseño (del padre, a partir de S1-S3):
  - Entrada `{ "id": <int> }`: id del original **o** de la copia. Original sin copia → error claro («no tiene copia de trabajo»); contenido que no es copia ni original con copia → error; copia huérfana (original borrado, en la papelera o enlazado a otra copia) → error que sugiere descartarla.
  - Salida: `original_id`, `copy_id`, `preview_url`, `stale` (true si el original cambió desde que se creó la copia, o sea si publicarla se rechazaría; misma regla que `WorkingCopy::merge()`), `changed` (lista de nombres de campos cambiados entre `title`, `excerpt`, `content`, `meta`, `terms`), `title` / `excerpt` como `{before, after}` sólo si cambiaron, `content_diff` (diff unificado por líneas, 3 líneas de contexto, encabezados `@@ -a,b +c,d @@`) sólo si cambió, con tope de tamaño y `content_diff_truncated: true` si se cortó.
  - `meta`: sólo las claves que la fusión copiaría (las mismas que `WorkingCopy::copy_meta()`: sin SKIP_META ni prefijos omitidos). `{changed: [...], added: [...], kept_on_publish: [...]}` (ver L3: una clave que existe en el original y falta en la copia se conserva al publicar, así que va en `kept_on_publish` con `after` = valor que conserva); cada entrada `{key, before, after}` cuando ambos valores son escalares cortos (≤ 200 caracteres), y si no `{key, before_bytes, after_bytes}`.
  - `terms`: por taxonomía (las que copia la fusión) `{taxonomy: {added: [slugs], removed: [slugs]}}`, sólo taxonomías con cambios.
  - Sin cambios: `changed: []` y nada más que los ids, `preview_url` y `stale`.
  - Permiso: lectura del módulo de contenido (`content_tools_enabled`, `Capabilities::CONTENT_ADVANCED`, scope `mcp:content` + `mcp:read`), con `edit_post` sobre el original (una copia es un borrador: leerla exige poder editar).
  - El diff es PHP puro (sin depender de `wp_text_diff`) para poder probarlo con PHPUnit.
- S5. Versión 1.1.0 con su entrada en el changelog de `readme.txt` (herramienta nueva); `empaquetar.sh` exige la entrada.

## Tasks

- [x] T1 (S1-S5) Implementar la herramienta (test primero: PHPUnit del diff y del gateway con stubs; ampliar la prueba de punta a punta paso 7 para pedir el diff de la copia creada), docs y versión. Ruta: worker. Commit: —

## Log

- L1 (2026-11-16): «Continuemos con la herramienta de comparar»
- L2 (2026-11-16): respuestas: contenido «Diff unificado por líneas (Recomendado)»; metadatos «Listar cambios con valores cortos (Recomendado)».
- L3 (2026-11-17): verificación independiente (Risk: item 3, refactor de merge()): merge() idéntico a HEAD, probado publicando copias en WordPress real (incluye el caso stale). B1 corregido con test primero: con el original borrado, original_id_for() devolvía su id y la herramienta respondía con una denegación genérica; ahora devuelve la copia y la herramienta responde nexo_mcp_draft_orphan. A1: merge() nunca borra meta, así que «removed» engañaba. Decisión del usuario: «Que el diff diga la verdad (Recomendado)»: «Esos metadatos pasan a `meta.kept_on_publish`: «existe en el original, falta en la copia, se conserva al publicar». No cambia qué pasa al publicar, solo el informe.» Aplicado con test primero. PHPUnit 87 OK, phpcs/phpstan OK, prueba_reglas.sh OK.
