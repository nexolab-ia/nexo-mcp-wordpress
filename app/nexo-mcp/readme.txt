=== Nexo MCP ===
Contributors: nexodev
Tags: mcp, ai, abilities-api, woocommerce, oauth
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta WordPress y WooCommerce con clientes MCP (Claude, Cursor, VS Code y otros) mediante herramientas explícitas, OAuth 2.1 y permisos seguros.

== Description ==

Nexo MCP expone un servidor MCP en `/wp-json/nexo-mcp/v1/mcp` construido sobre la Abilities API de WordPress 6.9 y el adaptador oficial `wordpress/mcp-adapter`.

* Servidor de autorización OAuth 2.1 propio con PKCE (S256), descubrimiento (`/.well-known/oauth-protected-resource` y `/.well-known/oauth-authorization-server`), registro dinámico de clientes (RFC 7591), tokens con alcances, tokens personales y Application Passwords.
* Permisos acumulativos: capacidad de WordPress + módulo encendido + modo sólo lectura + alcances de cada conexión. Cada rechazo explica su causa.
* Herramientas de lectura, escritura, constructores visuales (Gutenberg, Elementor, Flatsome, Divi, WPBakery y shortcodes, con respaldo antes de editar), SEO (Yoast, Rank Math, AIOSEO, SEOPress o nativo), campos ACF, medios, menús, apariencia, mantenimiento de plugins en dos pasos y WooCommerce opcional.
* Las ediciones a contenido publicado van primero a una copia de trabajo.
* Datos dinámicos de Elementor (requiere Elementor Pro): `list-dynamic-tags` y `set-dynamic-tag` asignan, de una lista cerrada de tags sin datos sensibles, un dato dinámico (título del sitio, título de la entrada, imagen destacada…) a un título, párrafo, botón o imagen atómico, siempre en la copia de trabajo. Permiso propio, apagado por defecto: un tag se evalúa para todo visitante.
* Componentes de Elementor 4 (requiere Elementor Pro con licencia activa): `list-components`, `create-component` (borrador, árbol atómico validado) e `insert-component` (instancia en la copia de trabajo de una página). Permiso propio, apagado por defecto; sin overrides.
* Acciones irreversibles con confirmación explícita.
* Límites de tasa y de subidas, auditoría con redacción de datos sensibles, sanitización de contenido y política HTTPS.
* Purga de caché de los proveedores conocidos.

Nexo MCP es una obra derivada del plugin «MCP Prisma» de SICS (GPL-2.0-or-later). Consulta NOTICE.md.

== Installation ==

1. Comprime la carpeta `nexo-mcp` (incluida `vendor/`) en un ZIP e instálalo desde Plugins › Añadir plugin › Subir plugin.
2. Activa Nexo MCP. Aparece el menú «Nexo MCP».
3. En la pestaña Conectar elige tu cliente y copia la instrucción.
4. Autoriza la conexión en el navegador cuando el cliente te lo pida.

Para instalar desde el código fuente: `composer install --no-dev --no-scripts --no-plugins --optimize-autoloader`.

= Servicio de licencias (opcional) =

Nexo MCP no trae ningún servidor de licencias. Si quieres usar uno, define en `wp-config.php`:

`define( 'NEXO_MCP_API_URL', 'https://tu-servidor.example/api' );`

Sin esa constante el plugin funciona completo y las pestañas Licencia y sitio, Actualizaciones y Soporte lo indican.

= Desarrollo local sin HTTPS =

El endpoint exige HTTPS. En un entorno local (dirección `localhost`) puedes permitir HTTP con:

`define( 'NEXO_MCP_ALLOW_INSECURE_LOCAL', true );`
`define( 'WP_ENVIRONMENT_TYPE', 'local' );`

== Frequently Asked Questions ==

= ¿Qué puede hacer la IA recién conectada? =

Sólo revisar. El sitio empieza en modo sólo lectura; las acciones de borrar contenido y de instalar o activar plugins quedan apagadas hasta que las enciendas escribiendo una palabra de confirmación.

= ¿Puedo limitar una conexión concreta? =

Sí. En Accesos (OAuth) cada conexión muestra sus alcances y puedes ampliarlos o reducirlos sin reconectar el cliente.

= ¿WooCommerce es obligatorio? =

No. Su módulo sólo se habilita si WooCommerce está activo.

== Changelog ==

= 1.8.0 =
* Nuevo: diseño global de Divi 5 con scope propio `mcp:divi_design` (capacidad `nexo_mcp_divi_design`, interruptor apagado por defecto): `get-divi-design-system`, `set-divi-global-color`, `delete-divi-global-color`, `set-divi-variable`, `archive-divi-variable`, `set-divi-typography` y `undo-divi-design-change`. Cambian el sitio en vivo con respaldo (últimos 20 cambios) y deshacer; deshacer se niega si el área cambió después (`nexo_mcp_divi_design_changed_since`) y `force` exige además el permiso destructivo.
* Un color en uso en páginas de Divi 5 no se borra sin `force` (`nexo_mcp_divi_color_in_use`); la búsqueda de uso recorre todo por lotes dentro del bloqueo. Las variables se archivan, no se borran; los gradientes son sólo de lectura.
* Nota: al escribir la paleta, Divi agrega a `et_divi` los 5 colores del Customizer con sus valores por defecto; deshacer no los quita.
* Corrección: `undo-divi-design-change` y `set-divi-typography` aceptan una entrada ausente sin error de tipo; si Divi falla al leer el estado al deshacer, responde `nexo_mcp_divi_design_undo_failed` (auditado) en vez de lanzar una excepción.

= 1.7.0 =
* Nuevo: Divi 5 como módulos reales (el contenido son bloques `wp:divi/…`; las páginas de Divi 4 con shortcodes siguen con el adaptador de siempre). `get-page-structure` lee una página de Divi 5 como árbol de nodos con `path` (índices desde la raíz) y `fingerprint` (hash del nodo): Divi 5 no tiene ids de nodo, así que toda escritura exige el `fingerprint` leído y, si la página cambió, responde `nexo_mcp_divi_node_changed` sin escribir.
* Nuevo: `edit-divi-module` (contenido y diseño de escritorio de un módulo; tablet, teléfono y hover se conservan), `create-divi-page` (borrador con secciones, filas, columnas y módulos) y `edit-divi-section` (reemplazar, insertar o borrar por ruta; el `divi/placeholder` raíz sólo admite insertar dentro). Escriben en la copia de trabajo de una página publicada (el original no cambia hasta publicar), respaldan antes de guardar, vacían el CSS de Divi y nunca publican. Permiso de constructores, sin scope nuevo.
* Catálogo cerrado: sección, fila, columna, texto, título, botón, imagen, divisor, blurb, ícono, video y lista de íconos. Se rechazan sin escribir `divi/code`, `divi/fullwidth-code`, `divi/shortcode-module` y cualquier módulo fuera del catálogo, shortcodes en el texto, enlaces `javascript:`, `htmlBefore`/`htmlAfter`, CSS propio y los valores de tablet, teléfono y hover. Requiere el tema Divi 5 (`nexo_mcp_divi5_required` si no está activo).
* Corrección (revisión): el título de `create-divi-page` conserva las barras invertidas; `nexo_mcp_divi_save_failed` ya no incluye el texto crudo de excepciones; si el borrador a medias no se puede borrar, el error lo avisa (`nexo_mcp_builder_copy_orphan`).

= 1.6.1 =
* Corrección: `list-components` respondía una lista vacía si Elementor no podía leer los componentes, igual que si no hubiera ninguno; ahora responde `nexo_mcp_components_unreadable` con el motivo. Sin componentes sigue devolviendo una lista vacía.
* Corrección: `create-component` daba por creado el componente aunque Elementor no devolviera un id válido; ahora responde `nexo_mcp_component_create_failed`.
* Documentación: `insert-component` acepta cualquier componente utilizable, también uno en borrador o de otro autor, igual que el editor de Elementor.

= 1.6.0 =
* Nuevo: componentes de Elementor 4 como datos (requiere **Elementor Pro con licencia activa** y el Editor V4). Tres herramientas: `list-components` (los componentes no archivados: `id`, `uid`, `title`, `status`, `modified`), `create-component` (crea un componente en **borrador** a partir de un árbol atómico validado, con los validadores de Elementor: límite de 100, título y uid duplicados, sólo elementos atómicos, sin ciclos) e `insert-component` (inserta en una página una instancia `e-component` que apunta al componente, en la copia de trabajo: el original no cambia hasta publicar). Sin Pro con licencia, crear e insertar responden `nexo_mcp_components_unavailable` sin escribir; un `component_id` que no existe, está archivado o en la papelera responde `nexo_mcp_component_not_found`.
* Nuevo: interruptor «Componentes de Elementor» (`builders_components_enabled`, **apagado por defecto**, marcado como sensible), capacidad `nexo_mcp_builders_components` (administrador) y scope OAuth `mcp:builders:components`. Crear un componente cambia el sitio, no sólo una página.
* Sin overrides: las instancias usan el componente tal cual. No se editan, publican, archivan ni borran componentes por MCP (se hace en el editor de Elementor). `create-elementor-page`, `edit-elementor-section` y `migrate-elementor-page` siguen rechazando `e-component`.
* Corrección: los mensajes de los validadores de Elementor llegan como texto, sin entidades HTML (`&#039;`).

= 1.5.0 =
* Nuevo: datos dinámicos de Elementor (requiere **Elementor Pro** y Elementor 4 con el Editor V4 activo). Dos herramientas: `list-dynamic-tags` (los tags permitidos que el sitio tiene registrados, con `name`, `group`, `label`, `categories`, `settings` y destinos) y `set-dynamic-tag` (asigna un tag a una prop de un elemento atómico en la copia de trabajo de la página, con el mismo camino que `edit-elementor-section`: el original publicado no cambia, respalda antes de guardar y nunca publica). Destinos: `e-heading.title`, `e-paragraph.paragraph` y `e-button.text` (tags de texto) y `e-image.image` (tags de imagen); el tag debe ser de la categoría de la prop. Los enlaces (`link`) quedan fuera.
* Seguridad: lista cerrada y no configurable de 19 tags sin datos sensibles (título, lema, logo y URL del sitio; título, extracto, fecha, hora, id, URL, imagen destacada y términos de la entrada; título y URL de archivo; nombre del autor; fecha actual; número y URL de comentarios). `shortcode`, `request-arg`, `user-info`, campos personalizados, ACF, WooCommerce y el resto se rechazan. Los ajustes de cada tag tienen claves y valores acotados y se guardan como texto plano (sin HTML). Un tag se evalúa para todo visitante de la página, sin chequeo de permisos: por eso tiene su propio permiso.
* Nuevo: interruptor «Datos dinámicos de Elementor» (`builders_dynamic_enabled`, **apagado por defecto**, marcado como sensible), capacidad `nexo_mcp_builders_dynamic` (administrador, también al actualizar) y scope OAuth `mcp:builders:dynamic`. `set-dynamic-tag` exige además el permiso de constructores y `edit_post` sobre el original; `list-dynamic-tags` sólo el de lectura de constructores (sin Pro devuelve una lista vacía).
* Cambio: `edit-elementor-section` acepta una prop dinámica que la página ya tiene: dentro de un nodo reemplazado, el cliente reenvía el nodo con su `id` original y la prop idéntica para conservarla (el nodo recibe un id nuevo); una prop dinámica nueva o cambiada se rechaza con `nexo_mcp_elementor_prop_type_denied` (se asigna con `set-dynamic-tag`). Lo no tocado se conserva solo. `create-elementor-page` y `migrate-elementor-page` no cambian.

= 1.4.3 =
* Corrección: el límite de solicitudes del transporte (240 por minuto por usuario e IP) contaba cada pedido MCP 4 veces, porque WordPress evalúa el permiso de la ruta una vez al despachar y otra por cada método al armar la cabecera `Allow`; el límite real quedaba en unos 60 pedidos por minuto y cada denegación se auditaba 4 veces. Ahora cada pedido consume una sola unidad. Además, al pasar el límite el endpoint responde `429` con el código `nexo_mcp_rate_limit_exceeded`, `data.retry_after` y la cabecera `Retry-After` con los segundos de espera, en vez de un `403 rest_forbidden` sin indicación. El resto de las denegaciones del transporte no cambia.

= 1.4.2 =
* Corrección: `update-node-text`, `update-node-attribute`, `edit-structure` y `restore-builder-backup` llamadas con el id de la copia de trabajo pedían también permiso de edición sobre la copia, además del original, aunque su permiso de entrada ya lo pedía sólo sobre el original. Ahora el permiso se pide sólo sobre el original: quien puede editar la página puede trabajar con su copia vigente aunque la haya creado otro usuario. Un borrador que el original no reconoce como su copia sigue pidiendo permiso sobre sí mismo. `get-page-structure` no cambia: es de lectura y pide permiso sobre el contenido indicado.

= 1.4.1 =
* Corrección: `edit-elementor-section` y `migrate-elementor-page` llamadas con el id de la copia de trabajo pedían también permiso de edición sobre la copia, además del original. Ahora el permiso se pide sólo sobre el original, como ya decía su descripción: quien puede editar la página puede trabajar con su copia vigente aunque la haya creado otro usuario. Un borrador que el original no reconoce como su copia sigue pidiendo permiso sobre sí mismo.
* Interno: un solo chequeo de permiso para las dos herramientas, y el conteo de `migrated` ya no se corrige después de convertir un text-editor en varios párrafos.

= 1.4.0 =
* Nueva herramienta `migrate-elementor-page`: migra una página de Elementor 3 entera a elementos atómicos de Elementor 4, en una sola operación y con el mismo camino que `edit-elementor-section`. Escribe en la copia de trabajo de la página (el original publicado no cambia hasta que revises y publiques la copia; si el sitio apagó la copia de trabajo por defecto, hay que pedir `draft: true` o se migra en vivo con respaldo), respalda antes de guardar, invalida el CSS de esa página y nunca publica. Convierte `section`, `column` y `container` flex en `e-flexbox`/`e-div-block`, `heading`, `text-editor` (un párrafo por `e-paragraph`, sólo si el HTML tiene párrafos, enlaces y spans), `button`, `image` y `divider`, con un estilo local por nodo (tipografía, color, fondo, margen y relleno, borde, con variantes tablet y móvil; los colores y tipografías globales del Kit pasan a variable de color o a su valor literal). Lo que no tiene equivalente (spacer, widgets compuestos, text-editor con formato, container grid…) se conserva como v3, idéntico, y la respuesta lo lista en `kept_v3`; los ajustes no trasladados van en `unmapped`.
* Seguridad: el árbol migrado pasa la validación de siempre en cada nodo atómico nuevo, y sólo se aceptan nodos v3 idénticos a los de la página original leída bajo el bloqueo; cualquier otro v3 se rechaza sin escribir. `create-elementor-page` y `edit-elementor-section` siguen rechazando todo v3 nuevo. Exige el Editor V4 (`nexo_mcp_elementor_v4_required`); una página sin nada que convertir responde `nexo_mcp_nothing_to_migrate` sin escribir.

= 1.3.0 =
* Nuevo: escritura del sistema de diseño de Elementor 4 en vivo, con permiso propio. Siete herramientas: `create-global-class`, `update-global-class` y `delete-global-class` (clases globales con variantes por breakpoint y estado), `create-design-variable`, `update-design-variable` y `delete-design-variable` (variables de color, fuente, tamaño y tamaño personalizado) y `update-kit-globals` (colores y tipografía del Kit, system y custom). Cambian todo el sitio al instante: se validan antes de escribir (la misma validación de estilos que `create-elementor-page`), corren bajo el bloqueo del Kit, guardan un respaldo y quedan en Actividad.
* Nuevo: interruptor «Sistema de diseño de Elementor» (apagado por defecto), capacidad `nexo_mcp_design_system` (administrador, también al actualizar) y scope OAuth `mcp:design_system`. Las escrituras respetan «Solo revisar».
* Nuevo: borrar una clase o variable exige además el permiso destructivo y la confirmación `DELETE`; si está en uso se rechaza con la lista de dónde (`nexo_mcp_design_in_use`) salvo `force: true`. `update-kit-globals` pide ambas sólo si borra.
* Nuevo: `undo-design-system-change` deshace el último cambio con los mismos ids (un nivel); ahora se marca destructiva y exige la confirmación `UNDO`, y si el Kit activo cambió desde el cambio responde `nexo_mcp_design_kit_changed` sin restaurar nada.
* Fix: el respaldo se comprueba antes de escribir (si el estado real no se puede restaurar no se escribe) y tolera lo que Elementor guarda de verdad (ids de orden sin clase, registro de variables sin marca de agua); restaurar clases verifica orden y contenido.
* Seguridad: la etiqueta de una variable es un identificador CSS seguro (letras, números, guiones y guiones bajos), porque Elementor la escribe como `--etiqueta` en el CSS del Kit; la familia de fuente y el tamaño `custom` de la tipografía del Kit pasan la misma regla de valores CSS que las clases.
* Cambio: un valor de estilo CSS inválido en una variable de tamaño personalizado se rechaza con la misma regla que las clases y las páginas atómicas. Todos los errores de validación del sistema de diseño usan el código `nexo_mcp_design_invalid` con `data.path`.

= 1.2.1 =
* Fix: dos ediciones simultáneas sobre una página publicada ya no crean dos copias de trabajo ni pierden una edición. Las escrituras que leen y reescriben un contenido (copia de trabajo, `update-page`/`update-post`, `edit-elementor-section`, herramientas de constructor, publicar y descartar una copia) corren bajo un bloqueo por original con vencimiento, releen el contenido dentro del bloqueo y comprueban que el bloqueo sigue siendo suyo antes de escribir. Si está ocupado responden «Otra edición de este contenido está en curso» sin escribir; las tomas de bloqueos vencidos quedan en Actividad.
* Cambio: `update-node-text`, `update-node-attribute`, `edit-structure` y `restore-builder-backup` ya no escriben en vivo sobre una página publicada: editan su copia de trabajo (revísala con `get-working-copy-diff` y publícala con `set-content-status`); un borrador se edita en su sitio. La respuesta agrega `id` y `draft_of`.
* Cambio: `restore-builder-backup` sobre una página publicada que ya tiene copia deshace la última edición de la copia; restaurar dos veces deja el mismo estado. Sin copia, restaura el respaldo del original en una copia nueva.
* Fix: `get-design-system` informa `warnings` si falla la lectura de clases globales o variables, en vez de devolver una lista vacía como si no hubiera nada.
* Fix: los valores de estilo con paréntesis sin cerrar se rechazan, y `meta.breakpoint` y `meta.state` se validan contra las listas cerradas de Elementor 4.4.1.
* Fix: las páginas v3 de más de 200 elementos aceptan `replace` e `insert` mientras el resultado no supere `max(200, elementos actuales)`.
* Fix: si falla la vuelta atrás de una edición y la copia recién creada no puede descartarse, el error lo dice (`nexo_mcp_builder_copy_orphan`) en lugar de callarlo.

= 1.2.0 =
* Elementor 4 (widgets atómicos): `get-page-structure` muestra el texto y las clases de los elementos atómicos.
* Nueva herramienta `get-design-system`: clases globales, variables, colores y tipografía del Kit de Elementor, y si Editor V4 está activo.
* Nueva herramienta `create-elementor-page`: crea una página atómica de Elementor en borrador a partir de un árbol de elementos. Lista cerrada de elementos (`e-flexbox`, `e-div-block`, `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-divider`), enlaces sólo https/mailto/tel, imágenes sólo por ID de la biblioteca, sin CSS propio; cualquier error indica la ruta del elemento y no crea nada. Nunca publica.
* Nueva herramienta `edit-elementor-section`: reconstruye una sección de una página de Elementor (también de la versión 3) con elementos atómicos. `replace` reemplaza un nodo por un árbol nuevo, `insert` agrega uno antes, después o dentro de un nodo y `delete` quita un nodo. Si la página está publicada el cambio va a su copia de trabajo y nunca se publica; se revisa con `get-working-copy-diff`. Respeta el anidado de Elementor (en la raíz sólo contenedores atómicos, en una columna v3 sólo widgets, nada nuevo dentro de una sección v3), deja idéntico lo que no toca, respalda antes de guardar e invalida el CSS de esa página. `create-elementor-page` ahora también rechaza un widget suelto en la raíz.

= 1.1.0 =
* Nueva herramienta `get-working-copy-diff`: compara una copia de trabajo con su original antes de publicarla o descartarla. Muestra el título y el extracto antes y después, un diff del contenido por líneas (como `git diff`, con tope de tamaño), los metadatos cambiados, agregados o quitados, las categorías y etiquetas agregadas o quitadas, y si el original cambió desde que se creó la copia (en ese caso publicarla se rechazaría). Acepta el ID del original o el de la copia y exige poder editar el original.

= 1.0.1 =
* Al descartar una copia de trabajo, ahora va a la papelera y se puede recuperar (antes se borraba de forma permanente).
* Seguridad: se eliminó un método público que permitía fijar el contexto de autenticación sin un token.
* Calidad: PHPCS (estándares de WordPress), PHPStan nivel 5 y Plugin Check; nuevas pruebas de punta a punta con Yoast SEO, ACF y Elementor.
* Se quitó la cabecera Domain Path, que apuntaba a una carpeta inexistente.

= 1.0.0 =
* Primera versión de Nexo MCP, derivada de MCP Prisma 0.34.1.
