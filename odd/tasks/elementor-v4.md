# Elementor 4 atómico: lectura del sistema de diseño y creación de páginas (F1 + F2)

Primera entrega del soporte de Elementor v4 (widgets atómicos) en el MCP. Fases acordadas: F1 leer v4 y sistema
de diseño, F2 crear página atómica. Fases posteriores (no abiertas aún): F3 reconstruir secciones, F4 escribir
clases/variables/estilos globales, F5 migrar v3 → v4, F6 datos dinámicos, F7 componentes como datos.

## Specs

- S1. Pedido: «Crea una página completa en Elementor a partir de una descripción, lista para editar».
- S2. Pedido (parte de lectura, F1): «Gestiona clases, variables y estilos globales».
- S3. Decisión: primera fase «F1 + F2: leer v4 y crear página (Recomendado)»: «Base de lectura y la primera capacidad visible: crear una página atómica en borrador desde una descripción.»
- S4. Decisión para «Crea nuevos widgets atómicos registrados en Elementor»: «Componentes como datos, sin PHP (Recomendado)»: «Bloques reutilizables guardados como datos (Components de la v4 o plantillas). Nada ejecuta código nuevo.» (fase F7, fuera de esta entrega; ninguna herramienta escribe ni activa PHP).
- S5. Decisión de destino de escrituras v4: «Copia de trabajo y publicar después (Recomendado)»: «Como el resto del contenido: revisás con get-working-copy-diff y la vista previa antes de publicar.» En esta entrega: la página creada nace como borrador.
- S6. Diseño (del padre, a partir de S1-S5, se ajusta con la evidencia de T1):
  - La IA interpreta la descripción y arma el árbol atómico; el plugin lo valida y lo guarda. Sin generación de contenido en el servidor.
  - F1: `get-page-structure` reconoce los elementos atómicos (`e-*`): tipo, id, texto legible y clases aplicadas. Herramienta nueva de lectura del sistema de diseño (clases globales, variables, colores y tipografía del Kit activo).
  - F2: herramienta nueva que crea una página Elementor en borrador a partir de un árbol atómico. Lista cerrada de elementos y de `$$type` permitidos; nunca HTML/shortcode/código (lista `NEVER`); enlaces con los esquemas permitidos; imágenes sólo por ID de biblioteca; ids nuevos generados por el plugin; tope de nodos y bytes; CSS regenerado. Permisos del módulo de constructores (`builders_tools_enabled`, `mcp:builders`) más `edit_pages`/creación.
  - Si los elementos atómicos (`e_atomic_elements`, que activa `e_opt_in_v4`) no están activos, la creación se rechaza con un error que explica cómo activarlo.

- S7. Diseño de T2 (del padre, con la evidencia de L4):
  - Lectura atómica en `ElementorAdapter::read_nodes()`: un nodo cuyo tipo (`widgetType`, o `elType` si no es `widget`) empieza por `e-` sale con `editable: false`, `reason: "Elemento atómico de Elementor 4: por ahora sólo lectura."`, `text` desde la prop de texto del tipo (`e-heading` → `title`, `e-paragraph` → `paragraph`, `e-button` → `text`; valor `{$$type, value}` con `value` string, o `html-v3` con `value.content.value` string; cualquier otra forma = sin `text`) y `classes` (lista de strings de `settings.classes.value`; si no es lista de strings, sin `classes`). `classes` sólo viaja si no está vacía. Los widgets v3 y contenedores v3 conservan su forma. Contrato fijado por `app/nexo-mcp/tests/ElementorAtomicReadTest.php`.
  - Herramienta nueva de lectura `nexo-mcp/get-design-system` (sin entrada). Salida: `atomic_enabled` (bool, `Plugin::$instance->experiments->is_feature_active('e_atomic_elements')`), `kit_id`, `colors` (system + custom del Kit: `{id, title, color}`), `typography` (system + custom: `{id, title, font_family, font_weight}` con lo que haya), `classes` (clases globales `{id, label}` en su orden, de `Global_Classes_Repository`), `variables` (`{id, type, label, value}` sin las borradas, de `Variables_Repository`). Cada bloque que no exista en la versión instalada (clase ausente) sale como lista vacía, sin error fatal; sin Elementor activo la herramienta responde un error claro. Permiso: lectura del módulo de constructores (mismo toggle/capability/scope que `get-page-structure`).

## Tasks

- [x] T1 (S6) Extraer el código de Elementor 4.4.1 del docker y mapear: widgets atómicos y sus esquemas de props, estilos, clases globales, variables, Kit, guardado de documentos y regeneración de CSS, experimento V4. Ruta: explore. Commit: —
- [x] T2 (S2, S6) F1: lectura de nodos atómicos en `get-page-structure` y herramienta de sistema de diseño. Ruta: inline. Commit: —
- [ ] T3 (S1, S3-S6) F2: crear página atómica en borrador con validación. Ruta: inline. Commit: —

## Log

- L1 (2026-11-17): «Quiero que con elementor pueda hacer esto igual Crea una página completa en Elementor a partir de una descripción, lista para editar
Migra páginas de la versión 3 a los widgets atómicos de la versión 4
Crea nuevos widgets atómicos registrados en Elementor
Gestiona clases, variables y estilos globales
Reconstruye secciones utilizando widgets atómicos de la versión 4
Asocia datos dinámicos a elementos y widgets»
- L2 (2026-11-17): exploración. Código: el adaptador sólo entiende v3 (TEXT_KEY), `edit-structure` sólo duplica, sin Kit/clases/variables/dinámicos/creación; las ediciones de constructor escriben directo con respaldo. Web: la lista coincide con Elementor MCP oficial (Angie); formato atómico documentado (`$$type`, `styles` hermano de `settings`); clases globales en posts `e_global_class` con repositorio PHP y REST (escribir meta a mano pierde clases); sin convertidor v3→v4 oficial; widgets nuevos exigen PHP; `e_opt_in_v4` activo por defecto sólo en instalaciones nuevas 4.0+. Sin verificar: variables, Kit, dinámicos.
- L3 (2026-11-17): respuestas: S3, S4, S5.
- L4 (2026-11-17): T1 hecha (explorador sobre .referencia/elementor-4.4.1). Elementor 4.4.1 gratis trae su propio MCP: módulo `modules/mcp` registra con la Abilities API (`wp_register_ability`, la misma que usa nuestro plugin) unas 40 abilities `elementor/*`: `build-composition` (arma árboles atómicos), `manage-elements`, `manage-classes`, `manage-variable`, `global-classes-resource`, `global-variables-resource`, `list-widget-schemas`, `get-widget-schema`, `list-dynamic-tags`, `manage-component`, `list-components`, `create-page` (vacía), `publish-document`, `manage-revisions`. Exposición pública por MCP sujeta a la opción `McpSettingsController::is_enabled()` (vendor/elementor/elementor-mcp-composer); las atómicas exigen el experimento `e_atomic_elements` (no `e_opt_in_v4`, que es el interruptor que lo activa). APIs PHP verificadas para una implementación propia: `Props_Parser`/`Style_Parser` (validación), `Document::save()` (valida settings atómicos, descarta en silencio tipos no registrados, invalida CSS), `Global_Classes_Repository`, `Variables_Repository` (meta del Kit `_elementor_global_variables`), Kit `elementor_active_kit` con `system_colors`/`system_typography`. Decisión de producto abierta: envolver las abilities de Elementor o implementar con sus servicios PHP.
- L5 (2026-11-17): decisión: «Propio, con los servicios PHP de Elementor (Recomendado)»: «Herramientas nexo-mcp/* nuestras que usan el validador, el guardado y los repositorios de Elementor. Pasan por nuestro OAuth, permisos, copia de trabajo y respaldo. Funcionan sin activar Elementor MCP. Más código y hay que seguir los cambios internos de Elementor.»
- L6 (2026-11-17): T2 hecha (S2, S6, S7). Lectura atómica en `ElementorAdapter` (`atomic_type`/`atomic_node`) y `BuilderNode::$classes`; herramienta `nexo-mcp/get-design-system` (`ElementorDesignSystem`, vía `BuilderGateway::get_design_system()`, mismo toggle `builders_tools_enabled` y capability `Capabilities::BUILDERS` que `get-page-structure`, con `edit_pages` por no recibir id). Evidencia: PHPUnit completo 93 tests OK (incl. `ElementorAtomicReadTest` 3/3 y `ElementorDesignSystemTest`); PHPCS 0; PHPStan sin errores; `tools/prueba_adaptadores.sh` OK (10/10 + 2/2 atómicas: `get-design-system` devuelve atomic_enabled=true, clase, variable, colores y tipografía; `get-page-structure` devuelve e-heading con texto y clases) y restaura experimentos/variables/clases; `tools/prueba_reglas.sh` OK.
