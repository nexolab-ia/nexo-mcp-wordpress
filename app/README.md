# Nexo MCP

Plugin de WordPress que conecta tu sitio (y WooCommerce, si lo usas) con
asistentes de IA compatibles con **MCP** —Claude, Cursor, VS Code, Windsurf,
Gemini CLI, Codex y otros— mediante herramientas explícitas, **OAuth 2.1 con
PKCE** y permisos que tú controlas desde el panel.

Nexo MCP es una obra derivada del plugin GPL «MCP Prisma» de SICS y se
distribuye bajo **GPL-2.0-or-later** (ver `nexo-mcp/NOTICE.md`).

## Qué hay en esta carpeta

| Ruta | Qué es |
|---|---|
| `nexo-mcp/` | El plugin, listo para comprimir en un ZIP e instalar. Incluye `vendor/` con `wordpress/mcp-adapter` y `wordpress/php-mcp-schema`. |
| `docker-compose.yml` | WordPress 6.9 + MySQL 8 + WooCommerce con el plugin montado. Funciona con Docker Desktop y OrbStack. |
| `docker/setup.sh` | Instalación inicial automática del WordPress de prueba. |
| `tools/prueba_punta_a_punta.py` | Prueba real de conectar → autorizar OAuth → usar herramientas. |
| `empaquetar.sh` | Genera `dist/nexo-mcp-<versión>.zip` y comprueba versión, changelog y contenido. |
| `EQUIVALENCIAS.md` | Tabla clase original → clase nueva → estado. |
| `shots/` | Capturas del panel. |

## 1. Levantar el WordPress de prueba

Requisitos: [OrbStack](https://orbstack.dev) o Docker Desktop.

```sh
cd app
docker compose up -d
docker compose logs -f setup     # espera a ver «Listo: …» y sal con Ctrl+C
```

La primera vez instala WordPress (en español de Chile), activa los enlaces
permanentes, instala la última versión de WooCommerce compatible con
WordPress 6.9 y activa Nexo MCP.

- Sitio: <http://localhost:8080>
- Administración: <http://localhost:8080/wp-admin> — usuario `admin`, clave `admin`
- Panel del plugin: menú **Nexo MCP**
- Endpoint MCP: `http://localhost:8080/wp-json/nexo-mcp/v1/mcp`

Para detenerlo: `docker compose down` (los datos quedan guardados).
Para empezar de cero: `docker compose down -v`.

> El entorno de prueba usa HTTP en `localhost`. Para eso `docker-compose.yml`
> define `WP_ENVIRONMENT_TYPE=local` y `NEXO_MCP_ALLOW_INSECURE_LOCAL`.
> **Nunca** uses esas constantes en un sitio público: allí el plugin exige HTTPS.

Si el puerto 8080 está ocupado: `NEXO_WP_PORT=8090 docker compose up -d`
(la primera instalación toma ese puerto como dirección del sitio).

## 2. Conectar tu IA

En el panel, pestaña **Conectar**, elige tu cliente y copia la instrucción.
Las más comunes:

**Claude Code** (terminal):

```sh
claude mcp add --transport http nexo-wordpress http://localhost:8080/wp-json/nexo-mcp/v1/mcp
```

Luego, dentro de Claude Code, escribe `/mcp`, elige `nexo-wordpress` y
**Authenticate**. Se abre el navegador: inicia sesión en WordPress y pulsa
**Autorizar acceso**.

**Cursor** — agrega a `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "nexo-wordpress": {
      "url": "http://localhost:8080/wp-json/nexo-mcp/v1/mcp"
    }
  }
}
```

Cursor detecta que el servidor pide autorización y abre el navegador.

**Claude Desktop / claude.ai (conectores personalizados)**: estos clientes
se conectan desde internet, así que necesitan una dirección **HTTPS pública**
(un sitio real o un túnel). Si usas un túnel, actívalo en **Módulos y
límites › «El sitio se publica por un túnel o proxy»** y escribe la
dirección pública, para que los tokens se emitan para esa dirección.

En ningún caso se copia una contraseña: sólo la dirección del sitio. Cada
conexión aparece en **Accesos (OAuth)**, desde donde puedes revocarla o
cambiar sus alcances.

## 3. Qué puede hacer la IA

Un sitio recién instalado empieza en **«Solo revisar»**: la IA puede leer,
no escribir. Para permitir ediciones ve a **Módulos y límites** y apaga
«Sólo lectura»; el panel te pide escribir **lectura** para confirmar. Las
opciones riesgosas piden siempre una palabra:

| Opción | Palabra |
|---|---|
| Apagar «Sólo lectura» / elegir «Revisar y editar» | `lectura` / `editar` |
| Dejar de limitar a borradores | `borradores` |
| Editar lo publicado directamente (sin copia de trabajo) | `directo` |
| Dejar de respetar los alcances de cada conexión | `alcances` |
| Publicación remota | `publicar` |
| Mantenimiento de plugins | `plugins` |
| Borrar plugins y temas | `borrar` |
| Acciones destructivas | `eliminar` |

Reglas que siempre se cumplen:

- **Módulos on/off**: un módulo apagado bloquea sus herramientas para todas
  las conexiones.
- **Alcances por conexión**: cada conexión sólo puede usar los permisos que
  se le concedieron (por ejemplo, una conexión autorizada en «Solo revisar»
  no escribe aunque después actives la edición). Puedes ampliarlos o
  reducirlos en **Accesos (OAuth) › Editar alcances**.
- **Copia de trabajo**: editar una página o entrada publicada crea un
  borrador enlazado; el original no cambia hasta que publiques la copia
  (desde la lista de páginas/entradas o con `set-content-status`).
  Antes de publicarla, `get-working-copy-diff` muestra qué cambió respecto al
  original (título, extracto, diff del contenido, metadatos, términos) y si
  el original cambió desde que se creó la copia. Las escrituras que leen y
  reescriben un contenido (crear la copia, `update-page`/`update-post`,
  `edit-elementor-section`, las herramientas de constructor, publicar y
  descartar) corren bajo un bloqueo por original en la base de datos
  (`nexo_mcp_lock_<id>` en `wp_options`, con vencimiento de 30 s): dos
  ediciones simultáneas nunca crean dos copias ni se pisan. Si el bloqueo no
  se obtiene tras ~5 s, la herramienta responde «Otra edición de este
  contenido está en curso. Reintentá en unos segundos.» y no escribe nada. Justo
  antes de su primera escritura cada ruta comprueba que el bloqueo siga siendo
  suyo (y lo renueva): si venció y otro lo tomó, no escribe y responde también
  «ocupado». Cada toma de un bloqueo vencido queda en **Actividad**
  (`lock-takeover`).
- **Elementor 4 (atómicos)**: `get-page-structure` lee los elementos atómicos
  (`e-heading`, `e-paragraph`, `e-button`, `e-flexbox`…) con su texto y sus
  clases; `get-design-system` lista las clases globales, las variables y los
  colores y tipografías del Kit (si la lectura de las clases o de las variables
  falla, la respuesta trae `warnings: [{source, message}]` y conserva lo ya leído,
  en vez de una lista vacía sin explicación); `create-elementor-page` crea una página
  atómica **en borrador** a partir de un árbol que arma la IA. El árbol se
  valida antes de guardar: lista cerrada de elementos, enlaces https/mailto/tel,
  imágenes sólo por ID de la biblioteca, sin CSS propio ni valores de estilo
  que puedan escaparse de su declaración (comillas o paréntesis sin cerrar
  incluidos), y `meta.breakpoint` / `meta.state` de cada variante de estilo
  deben ser de la lista cerrada de Elementor 4.4.1 (breakpoints `desktop`,
  `widescreen`, `laptop`, `tablet_extra`, `tablet`, `mobile_extra`, `mobile`;
  estados `hover`, `active`, `focus`, `focus-visible`, `checked`,
  `e--selected`, `e--disabled`, `e--playing`, `e--paused` o `null`). Exige Editor
  V4 activo en Elementor.
  `edit-elementor-section` reconstruye una parte de una página existente (también
  v3): `replace` reemplaza un nodo (p. ej. una sección) por un árbol atómico
  nuevo, `insert` agrega uno antes, después o dentro de un nodo, y `delete`
  quita un nodo. Si la página está publicada el cambio va a su **copia de
  trabajo** (revisa con `get-working-copy-diff` y publica con
  `set-content-status`); nunca publica. Respeta el anidado de Elementor: en la
  raíz sólo contenedores atómicos, en una columna v3 sólo widgets atómicos, nada
  nuevo dentro de una sección v3 ni de un widget. Lo no tocado queda idéntico
  (se comprueba antes de guardar), guarda los ajustes ya saneados por Elementor (lo mismo que `create-elementor-page`), se respalda y se invalida el CSS de esa
  página. Para insertar y reemplazar exige Editor V4 activo. El tope es de 200
  elementos para la página resultante; una página v3 que ya los pasa admite
  `replace` e `insert` mientras el documento resultante no supere
  `max(200, elementos actuales)` (se puede reemplazar sin agrandarla).
  `migrate-elementor-page` migra una página de Elementor 3 **entera** a
  elementos atómicos, en una sola operación y con el mismo camino de escritura
  que `edit-elementor-section` (misma capacidad, copia de trabajo, respaldo,
  bloqueo y CSS invalidado; nunca publica). Con la copia de trabajo por defecto
  (ajuste de fábrica) o `draft: true`, el original publicado no cambia; si el
  sitio apagó la copia de trabajo por defecto y no se pide `draft`, migra la
  página en vivo con respaldo, igual que `edit-elementor-section`.
  Se migran `section` → `e-flexbox`, `column` → `e-div-block` (con su ancho),
  `container` flex → `e-flexbox`, `heading` → `e-heading`, `text-editor` → un
  `e-paragraph` por párrafo (sólo si el HTML tiene párrafos, enlaces y spans),
  `button` → `e-button`, `image` → `e-image` y `divider` → `e-divider`, con un
  estilo local por nodo (tipografía, color, fondo, margen y relleno, borde, con
  variantes `tablet` y `mobile`; los colores y tipografías globales del Kit pasan
  a la variable de color con el mismo nombre o a su valor literal). Lo demás
  (spacer, compuestos, text-editor con formato, container grid, etc.) se
  conserva como v3 idéntico y la respuesta lo lista en `kept_v3`; los ajustes
  v3 que no se trasladan van en `unmapped`. La respuesta es `{id, draft_of,
  preview_url, migrated, kept_v3, unmapped}`. Exige Editor V4 activo
  (`nexo_mcp_elementor_v4_required`); una página sin nada que convertir responde
  `nexo_mcp_nothing_to_migrate` sin escribir. Antes de guardar, el árbol migrado
  pasa la validación de siempre en cada nodo atómico nuevo, y sólo se aceptan
  nodos v3 idénticos (id, tipo y ajustes) a los de la página original leída bajo
  el bloqueo; cualquier otro v3 se rechaza sin escribir. Mismo tope de
  elementos: `max(200, elementos actuales)`. Parámetro `id` (como en
  `edit-elementor-section`) y `draft` opcional.
- **Datos dinámicos de Elementor (permiso propio, requiere Elementor Pro)**:
  `list-dynamic-tags` lista los tags permitidos que el sitio tiene registrados
  (`name`, `group`, `label` —el título que les da Elementor—, `categories`,
  `settings` y `targets`; sin Pro responde una lista vacía) y `set-dynamic-tag`
  `{id, draft?, node_id, prop, tag: {name, settings?}}` asigna uno a una prop de
  un elemento atómico. Escribe en la **copia de trabajo** por el camino de
  `edit-elementor-section` (bloqueo por original, respaldo, `save_tree`, CSS
  invalidado; el original publicado no cambia; nunca publica) y exige el Editor
  V4 activo. La respuesta es `{id, draft_of, preview_url, node_id, prop, tag}`
  con el tag ya normalizado: lo que se guarda es ese valor, nunca el del
  cliente. **Lista cerrada** (constante, no configurable) de 19 tags sin datos
  sensibles. Texto: `site-title`, `site-tagline`, `page-title`, `post-title`,
  `post-excerpt`, `post-date`, `post-time`, `post-terms`, `post-id`,
  `archive-title`, `author-name`, `current-date-time`, `comments-number`; url:
  `site-url`, `post-url`, `archive-url`, `comments-url`; imagen: `site-logo`,
  `post-featured-image`. Todo lo demás (`shortcode`, `request-arg`,
  `user-info`, `author-meta`, campos personalizados, ACF, WooCommerce…) se
  rechaza con `nexo_mcp_dynamic_tag_unknown`; uno de la lista que el sitio no
  tiene registrado (sin Pro) con `nexo_mcp_dynamic_tag_unavailable`. **Destinos**:
  `e-heading.title`, `e-paragraph.paragraph` y `e-button.text` (tags de texto) y
  `e-image.image` (tags de imagen); la categoría del tag tiene que ser la de la
  prop (`nexo_mcp_dynamic_category_mismatch`) y cualquier otra prop,
  incluido `link`, responde `nexo_mcp_dynamic_target_unsupported`. Los ajustes
  de cada tag (`before`/`after`/`fallback` y los propios: selects con opciones
  cerradas, textos de hasta 200 caracteres, números y switches `yes`/vacío) se
  validan contra el catálogo y se guardan como **texto plano**, sin HTML; una
  clave fuera del catálogo responde `nexo_mcp_dynamic_setting_unknown`.
  **Permiso**: además de builders y `edit_post` sobre el original, el interruptor
  «Datos dinámicos de Elementor» (`builders_dynamic_enabled`, **apagado por
  defecto**, marcado como sensible), la capacidad `nexo_mcp_builders_dynamic`
  (administrador; también al actualizar) y el scope OAuth
  `mcp:builders:dynamic`. Está aparte porque un tag se evalúa en cada render
  **para todo visitante**, sin ningún chequeo de permisos. `list-dynamic-tags`
  sólo pide el permiso de lectura de constructores. **Regla de
  `edit-elementor-section`**: una prop dinámica que la página ya tiene no se
  pierde ni rompe la edición. Lo no tocado queda idéntico; dentro de un nodo
  reemplazado, el cliente reenvía el nodo con su `id` original y la prop dinámica
  idéntica para conservarla (el nodo recibe un id nuevo). Una prop dinámica nueva
  o cambiada se rechaza (`nexo_mcp_elementor_prop_type_denied`): se asigna con
  `set-dynamic-tag`. `create-elementor-page` y `migrate-elementor-page` no
  cambian (la primera sigue rechazando `dynamic`). Se probó con Elementor Pro
  4.4.1: el render resuelve el tag sin pasar por los analizadores del editor
  (`tools/prueba_adaptadores.sh`, sección F6, sólo con Pro; sin Pro se saltea).
- **Sistema de diseño de Elementor (permiso propio)**: los cambios a las clases
  globales, las variables y los colores y tipografía del Kit afectan a todo el
  sitio en vivo, así que tienen su propio interruptor en el panel («Sistema de
  diseño de Elementor», **apagado por defecto**, marcado como sensible), su
  capacidad `nexo_mcp_design_system` (administrador; se agrega también al
  actualizar un sitio ya instalado) y su scope OAuth `mcp:design_system`
  («Cambiar clases, variables, colores y tipografía globales de Elementor
  (afecta a todo el sitio)»). Exigen además `manage_options` y respetan «Solo
  revisar». Toda escritura de este grupo valida antes de escribir, corre bajo el
  bloqueo del Kit activo, guarda como único «último cambio» el estado previo de
  lo que toca (opción `nexo_mcp_design_system_backup`, sin autocarga), limpia el
  CSS generado de Elementor y queda en **Actividad** (`design-system-<operación>`).
  Si la escritura falla a mitad, se restaura ese estado y se responde el error.
  `undo-design-system-change` restaura el último cambio con los mismos ids y
  borra el respaldo. Restaura la colección completa que el cambio tocó (todas las
  clases, todas las variables, o las listas de colores y tipografía del Kit):
  lo que se haya cambiado después en ella desde el editor de Elementor también
  se pierde. Un respaldo con forma inválida se trata como «sin respaldo» y no se
  restaura; si el respaldo no se puede guardar, la escritura se rechaza con
  `nexo_mcp_design_backup_failed` y no se escribe nada (un solo nivel: sin cambio que deshacer responde
  `nexo_mcp_design_no_backup` y no cambia nada). Clases y variables exigen el
  Editor V4; los colores y la tipografía del Kit sólo requieren Elementor.
  El respaldo guarda el id del Kit: si el Kit activo cambió, deshacer responde
  `nexo_mcp_design_kit_changed` y no restaura nada. Antes de escribir, el estado
  real que se va a respaldar tiene que poder restaurarse (si no, no se escribe:
  `nexo_mcp_design_backup_failed`); Elementor deja a veces ids en el orden de las
  clases sin su item o un registro de variables sin `watermark`, y eso se
  normaliza (se descartan esos ids; `watermark` pasa a 0).
  `undo-design-system-change` se marca destructiva y pide
  `"confirmation": "UNDO"` (en el servidor, como las demás acciones irreversibles).
  Siete herramientas escriben en vivo, todas con el permiso del sistema de diseño
  y descritas para que la IA llame antes a `get-design-system`:
  - `create-global-class`, `update-global-class`, `delete-global-class`: clases
    globales con variantes por breakpoint y estado, con la misma validación de
    valores de estilo que `create-elementor-page` (F2/F3) y el analizador de
    Elementor. El `label` (`/^[a-z][a-z-_0-9]*$/i`, hasta 50 caracteres) es único
    sin distinguir mayúsculas: uno repetido se rechaza con
    `nexo_mcp_design_label_taken` (no se renombra solo); máximo 1000 clases
    (`nexo_mcp_design_limit_reached`). `update-global-class` reemplaza las
    variantes si se mandan.
  - `create-design-variable`, `update-design-variable`, `delete-design-variable`:
    variables de color, fuente, tamaño y tamaño personalizado, cada valor validado
    según su tipo (el tamaño personalizado aplica las mismas restricciones de
    hojas CSS que las clases). Label de letras sin tildes, números, `-` y `_` (Elementor lo escribe como nombre de propiedad CSS `--label` en el CSS del Kit, así que no admite espacios ni otros símbolos ni empezar con `--`), hasta 50 caracteres, único
    entre las no borradas; hasta 1000 activas. El tipo sólo se cambia entre los
    dos de tamaño. Borrar es el borrado lógico de Elementor.
  - `update-kit-globals`: modifica, crea y borra colores y tipografías globales
    del Kit activo (`system` y `custom`); con `id` modifica, sin `id` crea uno
    `custom`; los `system` no se borran (`nexo_mcp_design_system_item`). Sólo
    admite las claves `typography_*` del control de tipografía de Elementor; la familia de fuente y el tamaño `custom` (que llegan tal cual al CSS) pasan la misma regla de valores CSS que las clases.
  - Los errores de validación tienen todos el código `nexo_mcp_design_invalid`,
    con la ruta en `data.path` y el mensaje `ruta: motivo`; no se escribe nada.
    Los demás códigos son específicos (`nexo_mcp_design_label_taken`,
    `nexo_mcp_design_limit_reached`, `nexo_mcp_design_not_found`,
    `nexo_mcp_design_in_use`, `nexo_mcp_design_system_item`,
    `nexo_mcp_design_nothing_to_change`, `nexo_mcp_design_backup_failed`).
  - Borrar exige además el permiso destructivo («Acciones destructivas» y la
    capacidad `nexo_mcp_destructive`) y `"confirmation": "DELETE"`. Si la clase o
    variable está en uso se rechaza con `nexo_mcp_design_in_use` y
    `data.used_in: [{id, title}]`; con `force: true` se borra igual y la respuesta
    trae `{deleted, pages_affected: [{id, title}]}`. Forzar una clase deja que
    Elementor la quite de esas páginas: deshacer la devuelve a la lista pero no
    vuelve a ponerla en ellas (lo avisa en `warnings`). `update-kit-globals` pide
    el permiso destructivo y `DELETE` sólo si `delete` trae ids.
- **Acciones irreversibles**: `delete-product`, `delete-global-class`,
  `delete-design-variable` y `update-kit-globals` (si `delete` trae ids) exigen
  `"confirmation": "DELETE"`; `restore-revision` exige `"confirmation": "RESTORE"`
  y `undo-design-system-change` exige `"confirmation": "UNDO"`; los cambios de
  plugins van en dos pasos (`prepare-plugin-action` → `execute-plugin-action`).
- **Constructores visuales** (Gutenberg, Elementor, Flatsome, Divi,
  WPBakery, shortcodes): se respalda antes de cada edición y un intento
  rechazado nunca borra ese respaldo (`restore-builder-backup` lo recupera).
  `update-node-text`, `update-node-attribute`, `edit-structure` y
  `restore-builder-backup` siguen la misma regla que `update-page`: una página
  **publicada** se edita en su **copia de trabajo** (se crea o se reutiliza,
  sólo tras validar la entrada; la respuesta trae el `id` de la copia y
  `draft_of`); revisa con `get-working-copy-diff` y publica con
  `set-content-status`. Un borrador se edita en su sitio. El respaldo se toma
  sobre lo que se edita (la copia). `restore-builder-backup` con el id de la copia,
  o con el de una página publicada que ya tiene copia, deshace la última edición
  de la copia (restaura el respaldo propio de la copia, sin capturar antes de
  escribir: restaurar dos veces deja el mismo estado y no pierde nada). Sólo si la
  página publicada no tiene copia usa el respaldo del original (estado previo a su
  última edición en vivo o publicación) y lo restaura en una copia nueva, nunca en
  vivo; sin respaldo del original no crea la copia. Si falla el respaldo o el
  guardado tras crear la copia, la copia se manda a la papelera y no queda huérfana.
  Con «Editar lo publicado directamente» las ediciones vuelven a ir en vivo salvo
  `draft: true`.
- Cada llamada —también las rechazadas— queda en **Actividad**, sin datos
  sensibles.

## 4. Servicio de licencias (opcional)

Nexo MCP no trae ningún servidor de licencias, registro ni actualizaciones.
Funciona completo sin él, y las pestañas **Licencia y sitio**,
**Actualizaciones** y **Soporte** lo indican. Si algún día tienes un servicio
compatible, agrega a `wp-config.php`:

```php
define( 'NEXO_MCP_API_URL', 'https://tu-servidor.example/api' );
```

## 5. Pruebas

**Reglas de permisos (PHPUnit, 56 pruebas)**: sólo lectura, módulos
desactivados, alcances por conexión, acciones irreversibles con
confirmación, edición de publicado → borrador, que un rechazo no borre el
respaldo de deshacer, que ningún código pueda fijar el contexto de
autenticación sin un token, y que las herramientas registradas coincidan
con las listas `ABILITIES` que publica el servidor.

```sh
cd app
docker run --rm -v "$PWD/nexo-mcp":/app -w /app composer:2 sh -c \
  "composer install --no-scripts --no-plugins -q && vendor/bin/phpunit; composer install --no-dev --no-scripts --no-plugins -q"
```

(La última parte deja `vendor/` otra vez sin las dependencias de prueba.)

**Calidad de código (PHPCS)**: estándar WordPress + PHPCompatibilityWP
(PHP 8.1+), configurado en `nexo-mcp/phpcs.xml.dist`. Sale con 0 si no hay
hallazgos.

```sh
cd app
docker run --rm -v "$PWD/nexo-mcp":/app -w /app composer:2 sh -c \
  "composer install --no-scripts --no-plugins -q && vendor/bin/phpcs; composer install --no-dev --no-scripts --no-plugins -q"
```

Para corregir automáticamente el formato, usar `vendor/bin/phpcbf` en lugar
de `vendor/bin/phpcs` (también existen `composer lint` y `composer lint:fix`).

**Análisis estático (PHPStan)**: nivel 5 con las extensiones de WordPress y
los stubs de WooCommerce y ACF, configurado en `nexo-mcp/phpstan.neon.dist`.
Sale con 0 si no hay hallazgos nuevos.

```sh
cd app
docker run --rm -v "$PWD/nexo-mcp":/app -w /app php:8.3-cli sh -c \
  "php -d memory_limit=1G vendor/bin/phpstan analyse"
```

(Requiere las dependencias de desarrollo en `vendor/`, como en PHPCS; también
existe `composer analyse`.) `nexo-mcp/phpstan-baseline.neon` es el baseline: los
hallazgos de nivel 1 a 5 que ya existían quedan congelados ahí. El código nuevo
debe pasar el nivel 5 sin añadir entradas al baseline; sólo se regenera
(`phpstan analyse --generate-baseline`) al corregir hallazgos antiguos, para
reducirlo.

**Plugin Check (wordpress.org)**: corre el verificador oficial dentro del
docker (con el plugin activo y el sitio de prueba levantado) y sale con 1 ante
cualquier error que no sea una exclusión aceptada.

```sh
cd app
tools/plugin_check.sh
```

Como el plugin se distribuye por `.zip` y no por wordpress.org, estos errores
son propios del directorio y se aceptan (están en la lista del script):

- `application_detected`: el ZIP/carpeta incluye `phpstan.neon.dist`,
  `phpcs.xml.dist` y `phpunit.xml.dist` (configuración de desarrollo).
- `plugin_updater_detected`: `UpdateChecker` actualiza desde nuestro servidor.
- `readme_short_description_non_official_language` y
  `readme_description_non_official_language`: el `readme.txt` está en español.
- `outdated_tested_upto_header`: sólo afecta a las búsquedas del directorio.

Los avisos que quedan tampoco cambian el comportamiento: `NOTICE.md`
(`unexpected_markdown_file`) y las variables de las vistas del panel
(`PrefixAllGlobals`, que sólo existen dentro del ámbito de la plantilla). Las
consultas con tabla interpolada llevan un `phpcs:ignore` con el motivo: sólo se
interpola `$wpdb->prefix` más una constante y los valores van por `prepare()`.

**Punta a punta** (con el WordPress de prueba levantado):

```sh
python3 tools/prueba_punta_a_punta.py
python3 tools/prueba_punta_a_punta.py --scope "mcp:connect mcp:read"   # conexión limitada a lectura
```

Registra un cliente OAuth, aprueba el consentimiento como `admin`, canjea el
código con PKCE y llama a `initialize`, `tools/list`, `get-site-info`,
`list-pages` y `create-post`. Con el sitio en modo edición también comprueba
que editar una página publicada crea una copia de trabajo y que
`restore-revision` sin confirmación se rechaza.

**Adaptadores** (`tools/prueba_adaptadores.sh`, unos 12-16 minutos): corre contra
Yoast, ACF y Elementor reales del docker. La sección de datos dinámicos (F6)
necesita Elementor Pro: sin él imprime `SKIP` y no falla (CI no lo tiene). Con
Pro enciende `builders_dynamic_enabled` sólo durante la sección y lo restaura
al final, también si la corrida se corta. Con Pro instalado, wp-cli necesita más
memoria que los 128M del servicio `setup`: `tools/wpcli.sh` usa
`php -d memory_limit=1G` cuando la variable `NEXO_WPCLI_MEMORIA` trae un límite,
y `prueba_adaptadores.sh` la fija solo si detecta `elementor-pro`; sin Pro el
comando no cambia.

## 6. Crear el ZIP

```sh
cd app
./empaquetar.sh        # deja dist/nexo-mcp-<versión>.zip
```

El script se detiene si la versión no coincide entre la cabecera `Version:`,
la constante `NEXO_MCP_VERSION` y el `Stable tag:` de `readme.txt`, o si
`readme.txt` no tiene la entrada de esa versión en `== Changelog ==`. El zip
no lleva pruebas ni configuración de desarrollo (PHPUnit, PHPCS, PHPStan), y
el script lo revisa antes de terminar.

**Publicar una versión nueva:** sube el número en los tres lugares, agrega su
entrada al changelog de `readme.txt` y corre `./empaquetar.sh`.

No conviene comprimir la carpeta `nexo-mcp/` a mano: llevaría los archivos de
desarrollo. Se instala desde **Plugins › Añadir plugin › Subir plugin**.

Requisitos del sitio: WordPress 6.9 o superior (Abilities API), PHP 8.1 o
superior, HTTPS. WooCommerce es opcional.
