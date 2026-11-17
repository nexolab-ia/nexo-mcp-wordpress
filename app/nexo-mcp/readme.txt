=== Nexo MCP ===
Contributors: nexodev
Tags: mcp, ai, abilities-api, woocommerce, oauth
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta WordPress y WooCommerce con clientes MCP (Claude, Cursor, VS Code y otros) mediante herramientas explícitas, OAuth 2.1 y permisos seguros.

== Description ==

Nexo MCP expone un servidor MCP en `/wp-json/nexo-mcp/v1/mcp` construido sobre la Abilities API de WordPress 6.9 y el adaptador oficial `wordpress/mcp-adapter`.

* Servidor de autorización OAuth 2.1 propio con PKCE (S256), descubrimiento (`/.well-known/oauth-protected-resource` y `/.well-known/oauth-authorization-server`), registro dinámico de clientes (RFC 7591), tokens con alcances, tokens personales y Application Passwords.
* Permisos acumulativos: capacidad de WordPress + módulo encendido + modo sólo lectura + alcances de cada conexión. Cada rechazo explica su causa.
* Herramientas de lectura, escritura, constructores visuales (Gutenberg, Elementor, Flatsome, Divi, WPBakery y shortcodes, con respaldo antes de editar), SEO (Yoast, Rank Math, AIOSEO, SEOPress o nativo), campos ACF, medios, menús, apariencia, mantenimiento de plugins en dos pasos y WooCommerce opcional.
* Las ediciones a contenido publicado van primero a una copia de trabajo.
* Acciones irreversibles con confirmación explícita.
* Límites de tasa y de subidas, auditoría con redacción de datos sensibles, sanitización de contenido y política HTTPS.
* Purga de caché de los proveedores conocidos.

Nexo MCP es una obra derivada del plugin «MCP Prisma» de SICS (GPL-2.0-or-later). Consulta NOTICE.md.

== Installation ==

1. Comprime la carpeta `nexo-mcp` (incluida `vendor/`) en un ZIP e instálalo desde Plugins › Añadir plugin › Subir plugin.
2. Activa Nexo MCP. Aparece el menú «Nexo MCP».
3. En la pestaña Conectar elige tu cliente y copia la instrucción.
4. Autoriza la conexión en el navegador cuando el cliente te lo pida.

Para instalar desde el código fuente: `composer install --no-dev --no-scripts --optimize-autoloader`.

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

= 1.0.0 =
* Primera versión de Nexo MCP, derivada de MCP Prisma 0.34.1.
