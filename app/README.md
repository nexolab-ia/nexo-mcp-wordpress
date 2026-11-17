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
- **Acciones irreversibles**: `delete-product` exige `"confirmation": "DELETE"`
  y `restore-revision` exige `"confirmation": "RESTORE"`; los cambios de
  plugins van en dos pasos (`prepare-plugin-action` → `execute-plugin-action`).
- **Constructores visuales** (Gutenberg, Elementor, Flatsome, Divi,
  WPBakery, shortcodes): se respalda antes de cada edición y un intento
  rechazado nunca borra ese respaldo (`restore-builder-backup` lo recupera).
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
  "composer install --no-scripts -q && vendor/bin/phpunit; composer install --no-dev --no-scripts -q"
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
