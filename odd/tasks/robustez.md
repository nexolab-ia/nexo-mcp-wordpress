# Bloque de robustez

Cierra lo que dejaron abierto las reviews de Elementor 4 y de la copia de trabajo: la carrera al crear copias,
las herramientas de constructor que escriben en vivo y los avisos menores. Rama `fix/robustez` desde `main` (1c7c8f3, v1.2.0).

## Specs

- S1. Propuesta aceptada («Arranca con robustez»): «Bloque de robustez. Lo más importante: dos primeras ediciones simultáneas sobre una página publicada crean dos copias de trabajo y una edición queda perdida; pasa también con `update-page`. Además pasaría a la copia de trabajo las herramientas de constructor viejas, que hoy escriben directo en la página publicada, y cerraría los avisos menores que dejaron las reviews.»
- S2. Carrera (del padre, a partir de L15 de `odd/tasks/elementor-v4.md`, aviso W5): dos ediciones simultáneas sobre el mismo original nunca crean dos copias ni pierden una edición. Toda escritura que lee y reescribe un contenido (`WorkingCopy::obtain()` y las escrituras de constructor sobre el árbol de Elementor) se hace bajo un bloqueo por original, atómico en la base de datos (inserción única en `wp_options`, que falla si ya existe), con vencimiento para no quedar trabado si un proceso muere, y volviendo a leer el estado dentro del bloqueo. Si el bloqueo no se obtiene tras una espera corta, la herramienta responde un error claro de «otra edición en curso, reintentá» y no escribe nada.
- S3. Herramientas de constructor a la copia de trabajo (del padre, a partir de S5 de `odd/tasks/elementor-v4.md`): `update-node-text`, `update-node-attribute`, `edit-structure` y `restore-builder-backup` siguen la misma regla que `update-page` y `edit-elementor-section` (`WorkingCopy::should_stage`): página publicada → copia de trabajo (creada o reutilizada, sólo después de validar); borrador → se edita el borrador. La respuesta indica el post editado y `draft_of` cuando es copia. `restore-builder-backup` sobre una página publicada: decidir en T2 con evidencia (restaurar en la copia o en vivo) y registrarlo en el Log. Las descripciones de las herramientas y la documentación lo dicen.
- S4. Avisos menores (de las reviews de F1-F3):
  - `get-design-system`: si la lectura de clases o variables falla (no por ausencia de la clase de Elementor), la respuesta lo dice (por ejemplo `warnings: [...]`) en vez de devolver una lista vacía como si no hubiera nada; lo ya leído se conserva.
  - Valores de estilo: rechazar paréntesis sin cerrar, como ya se rechazan las comillas sin cerrar.
  - `meta.breakpoint` y `meta.state` de las variantes: validación propia (lista cerrada de breakpoints y estados de Elementor 4.4.1), no sólo la de Elementor.
  - Tests de PHPUnit de la vuelta atrás de `create-elementor-page` y `edit-elementor-section` cuando el guardado falla (no queda borrador ni copia, error esperado).
  - Páginas v3 de más de 200 nodos: `replace` e `insert` se permiten si el documento resultante no supera `max(200, nodos actuales)`; sólo se rechaza lo que lo agranda por encima de ese tope.
  - `prueba_adaptadores.sh`: guardar el estado «sin variables» con un centinela explícito y abortar si la captura falla, para no borrar las variables del sitio al restaurar.
  - `prueba_punta_a_punta.py` paso 10: avisar en la salida cuando se saltea por falta de `mcp:publish`, y comprobar `isError` del `update-post` antes de leerlo.

## Tasks

- [ ] T1 (S2) Bloqueo por original en `WorkingCopy::obtain()` y en las escrituras de constructor; test de concurrencia. Ruta: worker + verify (Risk: item 4, concurrencia). Commit propio. Commit: —
- [ ] T2 (S3) Herramientas de constructor viejas a la copia de trabajo. Ruta: worker (Risk: item 3, cambia el comportamiento de herramientas existentes). Commit: —
- [ ] T3 (S4) Avisos menores. Ruta: worker. Commit: —

## Log

- L1 (2026-11-17): «Arranca con robustez»
