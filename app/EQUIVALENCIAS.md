# Tabla de equivalencias

Clase del plugin original (MCP Prisma 0.34.1, `referencia-original/src`, que no se incluye en este repositorio) → clase nueva de Nexo MCP (`nexo-mcp/src`) → estado.

Estados: **completa** = portada con la misma funcionalidad (y, cuando se indica, ampliada); **parcial** = portada con un cambio de comportamiento pedido; **pendiente** = falta; **no portada** = se omitió a propósito.

Verificación: las reglas de permisos tienen pruebas PHPUnit (`nexo-mcp/tests`). El flujo conectar → OAuth → herramientas se probó contra WordPress 6.9.4 + WooCommerce 11.0.1 reales con `tools/prueba_punta_a_punta.py`. Los adaptadores de constructores (Elementor, Divi, Flatsome, WPBakery), los proveedores SEO de terceros y ACF se portaron íntegros pero no se probaron con esos plugins instalados.

| Módulo | Clase original | Clase nueva | Estado | Nota |
|---|---|---|---|---|
| Activation | `SICS\MCPConnector\Activation\Activator` | `NexoDev\NexoMCP\Activation\Activator` | completa |  |
| Activation | `SICS\MCPConnector\Activation\Deactivator` | `NexoDev\NexoMCP\Activation\Deactivator` | completa |  |
| Admin | `SICS\MCPConnector\Admin\AdminPage` | `NexoDev\NexoMCP\Admin\AdminPage` | completa | Muestra «Local sin HTTPS» en entornos locales permitidos. |
| Admin | `SICS\MCPConnector\Admin\CredentialController` | `NexoDev\NexoMCP\Admin\CredentialController` | completa |  |
| Admin | `SICS\MCPConnector\Admin\LicenseController` | `NexoDev\NexoMCP\Admin\LicenseController` | completa | Responde «servicio no configurado» sin NEXO_MCP_API_URL. |
| Admin | `SICS\MCPConnector\Admin\OAuthConnectionsController` | `NexoDev\NexoMCP\Admin\OAuthConnectionsController` | completa | Ampliada: editar alcances de cada conexión. |
| Admin | `SICS\MCPConnector\Admin\RegistrationController` | `NexoDev\NexoMCP\Admin\RegistrationController` | completa |  |
| Admin | `SICS\MCPConnector\Admin\ReportController` | `NexoDev\NexoMCP\Admin\ReportController` | completa | La pestaña Soporte avisa si no hay servicio configurado. |
| Admin | `SICS\MCPConnector\Admin\SettingsController` | `NexoDev\NexoMCP\Admin\SettingsController` | completa |  |
| Admin | `SICS\MCPConnector\Admin\WorkingCopyController` | `NexoDev\NexoMCP\Admin\WorkingCopyController` | completa |  |
| Admin | `vista Admin/views/activacion.php` | `vista Admin/views/activacion.php` | completa | Sólo aparece si NEXO_MCP_API_URL está configurada y falta la licencia. |
| Admin | `vista Admin/views/dashboard.php` | `vista Admin/views/dashboard.php` | completa | 9 pestañas; nuevos interruptores y editor de alcances; avisos sin servicio. |
| Admin | `vista Admin/views/onboarding.php` | — | no portada | El original no la usaba (ninguna clase la incluye). |
| Audit | `SICS\MCPConnector\Audit\AuditLogger` | `NexoDev\NexoMCP\Audit\AuditLogger` | completa |  |
| Audit | `SICS\MCPConnector\Audit\AuditRepository` | `NexoDev\NexoMCP\Audit\AuditRepository` | completa |  |
| Authentication | `SICS\MCPConnector\Authentication\ApplicationPasswordManager` | `NexoDev\NexoMCP\Authentication\ApplicationPasswordManager` | completa |  |
| Authentication | `SICS\MCPConnector\Authentication\AuthenticationContext` | `NexoDev\NexoMCP\Authentication\AuthenticationContext` | completa |  |
| Authorization | `SICS\MCPConnector\Authorization\Capabilities` | `NexoDev\NexoMCP\Authorization\Capabilities` | completa |  |
| Authorization | `SICS\MCPConnector\Authorization\DenialExplainer` | `NexoDev\NexoMCP\Authorization\DenialExplainer` | completa | Ampliada: explica el alcance que le falta a la conexión. |
| Authorization | `SICS\MCPConnector\Authorization\PermissionPolicy` | `NexoDev\NexoMCP\Authorization\PermissionPolicy` | completa | Ampliada: alcances por conexión (enforce_connection_scopes). |
| Builders | `SICS\MCPConnector\Builders\AttributeValue` | `NexoDev\NexoMCP\Builders\AttributeValue` | completa |  |
| Builders | `SICS\MCPConnector\Builders\BuilderAdapter` | `NexoDev\NexoMCP\Builders\BuilderAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\BuilderBackup` | `NexoDev\NexoMCP\Builders\BuilderBackup` | completa |  |
| Builders | `SICS\MCPConnector\Builders\BuilderGateway` | `NexoDev\NexoMCP\Builders\BuilderGateway` | completa | Ampliada: recibe `Settings` (para la copia de trabajo) y `edit_elementor_section()`; `update_node_text()`, `update_node_attribute()`, `edit_structure()` y `restore_backup()` editan la copia de trabajo de una página publicada (valida, luego `WorkingCopy::obtain()`; respuesta con `draft_of`) y `BuilderBackup::restore()` admite el respaldo de otro contenido. |
| Builders | `SICS\MCPConnector\Builders\BuilderNode` | `NexoDev\NexoMCP\Builders\BuilderNode` | completa |  |
| Builders | `SICS\MCPConnector\Builders\BuilderRegistry` | `NexoDev\NexoMCP\Builders\BuilderRegistry` | completa |  |
| Builders | `SICS\MCPConnector\Builders\DiviAdapter` | `NexoDev\NexoMCP\Builders\DiviAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\EditableAttributes` | `NexoDev\NexoMCP\Builders\EditableAttributes` | completa |  |
| Builders | `SICS\MCPConnector\Builders\EditableTypes` | `NexoDev\NexoMCP\Builders\EditableTypes` | completa |  |
| Builders | `SICS\MCPConnector\Builders\ElementorAdapter` | `NexoDev\NexoMCP\Builders\ElementorAdapter` | completa | Ampliada: `tree()` público e `invalidate_post()` (CSS y caché de una sola página); localizar/ids/hijos pasaron a `ElementorTree` sin cambiar el duplicado de elementos. |
| Builders | `SICS\MCPConnector\Builders\FlatsomeAdapter` | `NexoDev\NexoMCP\Builders\FlatsomeAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\ForeignBuilderDetector` | `NexoDev\NexoMCP\Builders\ForeignBuilderDetector` | completa |  |
| Builders | `SICS\MCPConnector\Builders\GutenbergAdapter` | `NexoDev\NexoMCP\Builders\GutenbergAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\ShortcodeBuilderAdapter` | `NexoDev\NexoMCP\Builders\ShortcodeBuilderAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\StructureAdapter` | `NexoDev\NexoMCP\Builders\StructureAdapter` | completa |  |
| Builders | `SICS\MCPConnector\Builders\WpBakeryAdapter` | `NexoDev\NexoMCP\Builders\WpBakeryAdapter` | completa |  |
| Cache | `SICS\MCPConnector\Cache\CacheProvider` | `NexoDev\NexoMCP\Cache\CacheProvider` | completa |  |
| Cache | `SICS\MCPConnector\Cache\CachePurger` | `NexoDev\NexoMCP\Cache\CachePurger` | completa |  |
| Cache | `SICS\MCPConnector\Cache\CallableCacheProvider` | `NexoDev\NexoMCP\Cache\CallableCacheProvider` | completa |  |
| Cache | `SICS\MCPConnector\Cache\KnownCacheProviders` | `NexoDev\NexoMCP\Cache\KnownCacheProviders` | completa |  |
| Fields | `SICS\MCPConnector\Fields\AcfProvider` | `NexoDev\NexoMCP\Fields\AcfProvider` | completa |  |
| Fields | `SICS\MCPConnector\Fields\FieldGateway` | `NexoDev\NexoMCP\Fields\FieldGateway` | completa |  |
| Fields | `SICS\MCPConnector\Fields\FieldProvider` | `NexoDev\NexoMCP\Fields\FieldProvider` | completa |  |
| Fields | `SICS\MCPConnector\Fields\FieldSanitizer` | `NexoDev\NexoMCP\Fields\FieldSanitizer` | completa |  |
| Fields | `SICS\MCPConnector\Fields\FieldTypes` | `NexoDev\NexoMCP\Fields\FieldTypes` | completa |  |
| Fields | `SICS\MCPConnector\Fields\FieldValue` | `NexoDev\NexoMCP\Fields\FieldValue` | completa |  |
| Installation | `SICS\MCPConnector\Installation\Installer` | `NexoDev\NexoMCP\Installation\Installer` | completa |  |
| Licensing | `SICS\MCPConnector\Licensing\ApiEndpoint` | `NexoDev\NexoMCP\Licensing\ApiEndpoint` | parcial | Sin servidor de fábrica: lee NEXO_MCP_API_URL; sin ella el plugin funciona completo. |
| Licensing | `SICS\MCPConnector\Licensing\LicenseClient` | `NexoDev\NexoMCP\Licensing\LicenseClient` | completa | Misma estructura; sólo opera con NEXO_MCP_API_URL configurada. |
| MCP | `SICS\MCPConnector\MCP\AbilityRegistrar` | `NexoDev\NexoMCP\MCP\AbilityRegistrar` | completa |  |
| MCP | `SICS\MCPConnector\MCP\CodexInitializedCompatibility` | `NexoDev\NexoMCP\MCP\CodexInitializedCompatibility` | completa |  |
| MCP | `SICS\MCPConnector\MCP\ExtendedAbilityRegistrar` | `NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar` | completa | Ampliada: confirmación DELETE/RESTORE verificada en el servidor; herramientas de Elementor 4 (`get-design-system`, `create-elementor-page`, `edit-elementor-section`). |
| MCP | `SICS\MCPConnector\MCP\ServerRegistrar` | `NexoDev\NexoMCP\MCP\ServerRegistrar` | completa |  |
| MCP | `SICS\MCPConnector\MCP\ToolExecutor` | `NexoDev\NexoMCP\MCP\ToolExecutor` | completa |  |
| MCP | `SICS\MCPConnector\MCP\TransportGuard` | `NexoDev\NexoMCP\MCP\TransportGuard` | completa |  |
| Media | `SICS\MCPConnector\Media\UploadController` | `NexoDev\NexoMCP\Media\UploadController` | completa |  |
| Media | `SICS\MCPConnector\Media\UploadThrottle` | `NexoDev\NexoMCP\Media\UploadThrottle` | completa |  |
| Media | `SICS\MCPConnector\Media\UploadTicket` | `NexoDev\NexoMCP\Media\UploadTicket` | completa |  |
| Media | `SICS\MCPConnector\Media\UploadValidator` | `NexoDev\NexoMCP\Media\UploadValidator` | completa |  |
| Media | `SICS\MCPConnector\Media\ValidatedUpload` | `NexoDev\NexoMCP\Media\ValidatedUpload` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\AuthorizationController` | `NexoDev\NexoMCP\OAuth\AuthorizationController` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\BearerTokenGuard` | `NexoDev\NexoMCP\OAuth\BearerTokenGuard` | completa | Sin API pública para fijar el contexto de autenticación: las pruebas lo fijan por reflexión. |
| OAuth | `SICS\MCPConnector\OAuth\ClientRegistry` | `NexoDev\NexoMCP\OAuth\ClientRegistry` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\DiscoveryController` | `NexoDev\NexoMCP\OAuth\DiscoveryController` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\PersonalTokenIssuer` | `NexoDev\NexoMCP\OAuth\PersonalTokenIssuer` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\Pkce` | `NexoDev\NexoMCP\OAuth\Pkce` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\ScopeMap` | `NexoDev\NexoMCP\OAuth\ScopeMap` | completa | Ampliada: scopes_for_capability() y satisfies_any(). |
| OAuth | `SICS\MCPConnector\OAuth\TokenController` | `NexoDev\NexoMCP\OAuth\TokenController` | completa |  |
| OAuth | `SICS\MCPConnector\OAuth\TokenRepository` | `NexoDev\NexoMCP\OAuth\TokenRepository` | completa | Ampliada: update_connection_scope(). |
| Plugin.php | `SICS\MCPConnector\Plugin` | `NexoDev\NexoMCP\Plugin` | completa |  |
| Registration | `SICS\MCPConnector\Registration\RegistrationClient` | `NexoDev\NexoMCP\Registration\RegistrationClient` | completa | Misma estructura; sólo opera con NEXO_MCP_API_URL configurada. |
| Registration | `SICS\MCPConnector\Registration\RegistrationState` | `NexoDev\NexoMCP\Registration\RegistrationState` | completa | Sin servicio configurado, el requisito de registro se da por cumplido. |
| Security | `SICS\MCPConnector\Security\ClientIpResolver` | `NexoDev\NexoMCP\Security\ClientIpResolver` | completa |  |
| Security | `SICS\MCPConnector\Security\ContentSanitizer` | `NexoDev\NexoMCP\Security\ContentSanitizer` | completa |  |
| Security | `SICS\MCPConnector\Security\HttpsPolicy` | `NexoDev\NexoMCP\Security\HttpsPolicy` | completa |  |
| Security | `SICS\MCPConnector\Security\LocalEnvironment` | `NexoDev\NexoMCP\Security\LocalEnvironment` | completa |  |
| Security | `SICS\MCPConnector\Security\RateLimitResult` | `NexoDev\NexoMCP\Security\RateLimitResult` | completa |  |
| Security | `SICS\MCPConnector\Security\RateLimiter` | `NexoDev\NexoMCP\Security\RateLimiter` | completa |  |
| Security | `SICS\MCPConnector\Security\SensitiveDataRedactor` | `NexoDev\NexoMCP\Security\SensitiveDataRedactor` | completa |  |
| Seo | `SICS\MCPConnector\Seo\AioseoProvider` | `NexoDev\NexoMCP\Seo\AioseoProvider` | completa |  |
| Seo | `SICS\MCPConnector\Seo\NativeProvider` | `NexoDev\NexoMCP\Seo\NativeProvider` | completa |  |
| Seo | `SICS\MCPConnector\Seo\RankMathProvider` | `NexoDev\NexoMCP\Seo\RankMathProvider` | completa |  |
| Seo | `SICS\MCPConnector\Seo\SeoPressProvider` | `NexoDev\NexoMCP\Seo\SeoPressProvider` | completa |  |
| Seo | `SICS\MCPConnector\Seo\SeoProvider` | `NexoDev\NexoMCP\Seo\SeoProvider` | completa |  |
| Seo | `SICS\MCPConnector\Seo\SeoProviderRegistry` | `NexoDev\NexoMCP\Seo\SeoProviderRegistry` | completa |  |
| Seo | `SICS\MCPConnector\Seo\YoastProvider` | `NexoDev\NexoMCP\Seo\YoastProvider` | completa |  |
| Support | `SICS\MCPConnector\Support\Requirements` | `NexoDev\NexoMCP\Support\Requirements` | completa |  |
| Support | `SICS\MCPConnector\Support\Settings` | `NexoDev\NexoMCP\Support\Settings` | completa |  |
| Updates | `SICS\MCPConnector\Updates\UpdateChecker` | `NexoDev\NexoMCP\Updates\UpdateChecker` | completa | No ofrece actualizaciones si no hay NEXO_MCP_API_URL. |
| Updates | `SICS\MCPConnector\Updates\UpdateState` | `NexoDev\NexoMCP\Updates\UpdateState` | completa |  |
| WooCommerce | `SICS\MCPConnector\WooCommerce\ProductGateway` | `NexoDev\NexoMCP\WooCommerce\ProductGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\AppearanceGateway` | `NexoDev\NexoMCP\WordPress\AppearanceGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\ContentAdminGateway` | `NexoDev\NexoMCP\WordPress\ContentAdminGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\MaintenanceGateway` | `NexoDev\NexoMCP\WordPress\MaintenanceGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\ReadGateway` | `NexoDev\NexoMCP\WordPress\ReadGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\SeoGateway` | `NexoDev\NexoMCP\WordPress\SeoGateway` | completa |  |
| WordPress | `SICS\MCPConnector\WordPress\WorkingCopy` | `NexoDev\NexoMCP\WordPress\WorkingCopy` | completa | Ampliada: should_stage(); copia de trabajo por defecto (working_copy_default); live_original(), is_stale() y travelling_meta() compartidos con la herramienta `get-working-copy-diff` (nueva). |
| WordPress | `SICS\MCPConnector\WordPress\WriteGateway` | `NexoDev\NexoMCP\WordPress\WriteGateway` | completa |  |

## Clases nuevas (sin equivalente en el original)

| Clase nueva | Para qué |
|---|---|
| `NexoDev\NexoMCP\Authorization\IrreversibleActions` | Exige `confirmation` exacta (`DELETE`, `RESTORE`) en el servidor antes de ejecutar acciones irreversibles, y lo audita. |
| `NexoDev\NexoMCP\Builders\ElementorDesignSystem` | Lee el sistema de diseño de Elementor 4 (clases globales, variables, colores y tipografía del Kit) para `get-design-system`. |
| `NexoDev\NexoMCP\Builders\ElementorTreeValidator` | Valida en PHP puro el árbol atómico que recibe `create-elementor-page` (tipos, límites, enlaces, imágenes, clases, valores de estilo y regla de raíz). Valida también un subárbol que cuelga de un padre existente (`edit-elementor-section`). |
| `NexoDev\NexoMCP\Builders\ElementorTreeBuilder` | Convierte el árbol validado al formato de `_elementor_data` con ids generados por el plugin. |
| `NexoDev\NexoMCP\Builders\ElementorPageCreator` | Crea la página en borrador con los validadores y el guardado de documentos de Elementor; deshace el borrador si algo falla. |
| `NexoDev\NexoMCP\Builders\ElementorSchemaGuard` | Requisitos de Elementor 4 y validación con `Props_Parser`/`Style_Parser`; compartida por `create-elementor-page` y `edit-elementor-section`. |
| `NexoDev\NexoMCP\Builders\ElementorNesting` | Reglas de anidado de los elementos atómicos nuevos (raíz, columna, sección, contenedor, widget) en PHP puro. |
| `NexoDev\NexoMCP\Builders\ElementorTree` | Ayudantes puros sobre el árbol decodificado (localizar, listar ids, contar, recortar); los usan el duplicado de elementos y `edit-elementor-section`. |
| `NexoDev\NexoMCP\Builders\ElementorSectionSplicer` | Planifica y aplica `replace`/`insert`/`delete` sobre el árbol decodificado y comprueba que lo no tocado quede idéntico y que los ids no choquen. |
| `NexoDev\NexoMCP\Builders\ElementorSectionEditor` | Orquesta `edit-elementor-section`: valida, crea o reutiliza la copia de trabajo, respalda y guarda con `save_tree` (nunca con `Document::save`). |
| `NexoDev\NexoMCP\Support\LineDiff` | Diff unificado por líneas en PHP puro; lo usa la herramienta nueva `get-working-copy-diff` (comparar una copia de trabajo con su original). |

## Otros archivos

| Original | Nuevo | Estado |
|---|---|---|
| `sics-mcp-connector.php` | `nexo-mcp.php` | completa |
| `uninstall.php` | `uninstall.php` (opciones `nexo_mcp_*`, constante `NEXO_MCP_REMOVE_DATA`) | completa |
| `assets/css/admin.css`, `assets/js/admin.js` | mismos archivos con prefijos `nexo-mcp` y paleta propia | completa |
| `assets/images/logo-prisma-white.png` | `assets/images/logo-nexo.svg` (logo propio) | reemplazada |
| Logos de clientes (Claude, Cursor, etc.) | Iniciales en texto (CL, CU, VS…) | reemplazada |
| `composer.json` | `composer.json` con `wordpress/mcp-adapter` ^0.5 y `wordpress/php-mcp-schema` ^0.1 | completa |
| — | `tests/` (56 pruebas PHPUnit), `phpunit.xml.dist`, `NOTICE.md` | nueva |
