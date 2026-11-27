# Aviso de derivación

**Nexo MCP** es una obra derivada del plugin de WordPress **«MCP Prisma»**,
desarrollado por **SICS** y distribuido bajo la licencia **GPL-2.0-or-later**.

Nexo MCP se redistribuye modificado bajo la misma licencia (GNU General Public
License, versión 2 o, a tu elección, cualquier versión posterior). El texto
completo está en el archivo `LICENSE`. Nexo MCP no está afiliado a SICS ni
respaldado por SICS.

## Cambios principales respecto del original

- Nueva marca, logo, espacio de nombres (`NexoDev\NexoMCP`), text domain
  (`nexo-mcp`), prefijos (`nexo_mcp`) y nombres de herramientas MCP
  (`nexo-mcp/...`).
- Se quitaron las URLs, servidores y credenciales del autor original.
  Licencia, registro, reportes y actualizaciones apuntan a la constante
  `NEXO_MCP_API_URL`, sin valor por defecto. Sin ella, el plugin funciona
  completo y sin pedir licencia.
- Alcances por conexión: cada token OAuth sólo ejerce los scopes que se le
  concedieron (ajuste `enforce_connection_scopes`, editable por conexión en la
  pestaña «Accesos (OAuth)»).
- Las ediciones a contenido publicado van por defecto a una copia de trabajo
  (ajuste `working_copy_default`).
- Confirmación obligatoria en el servidor para acciones irreversibles
  (`delete-product` → `DELETE`, `restore-revision` → `RESTORE`).
- Pruebas PHPUnit de las reglas de permisos.

## Componentes de terceros

- `wordpress/mcp-adapter` y `wordpress/php-mcp-schema` (GPL-2.0-or-later),
  instalados con Composer en `vendor/`.
