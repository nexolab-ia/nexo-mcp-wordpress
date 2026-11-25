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
| Builders | `SICS\MCPConnector\Builders\BuilderGateway` | `NexoDev\NexoMCP\Builders\BuilderGateway` | completa | Ampliada: recibe `Settings` (para la copia de trabajo), `edit_elementor_section()` y `migrate_elementor_page()`; `update_node_text()`, `update_node_attribute()`, `edit_structure()` y `restore_backup()` editan la copia de trabajo de una página publicada (valida, luego `WorkingCopy::obtain()`; respuesta con `draft_of`) y `BuilderBackup::restore()` admite el respaldo de otro contenido; `restore_backup()` con la copia (o la publicada con copia) restaura el respaldo propio de la copia sin capturar antes, y con la publicada sin copia el del original en una copia nueva. |
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
| MCP | `SICS\MCPConnector\MCP\ExtendedAbilityRegistrar` | `NexoDev\NexoMCP\MCP\ExtendedAbilityRegistrar` | completa | Ampliada: confirmación DELETE/RESTORE/UNDO verificada en el servidor; herramientas de Elementor 4 (`get-design-system`, `create-elementor-page`, `edit-elementor-section`, `migrate-elementor-page`, `list-dynamic-tags`, `set-dynamic-tag`, `list-components`, `create-component`, `insert-component`, `undo-design-system-change`) y de escritura del sistema de diseño (`create-global-class`, `update-global-class`, `delete-global-class`, `create-design-variable`, `update-design-variable`, `delete-design-variable`, `update-kit-globals`), con el permiso `design_system_tools_enabled` (`set-dynamic-tag` con `builders_dynamic_enabled`; `create-component` e `insert-component` con `builders_components_enabled`) y los borrados con el destructivo. |
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
| WordPress | `SICS\MCPConnector\WordPress\WorkingCopy` | `NexoDev\NexoMCP\WordPress\WorkingCopy` | completa | Ampliada: should_stage(); copia de trabajo por defecto (working_copy_default); live_original(), is_stale() y travelling_meta() compartidos con la herramienta `get-working-copy-diff` (nueva); with_lock() y ensure_lock() (bloqueo por original y su comprobación antes de escribir, ver `Support\PostLock`). |
| WordPress | `SICS\MCPConnector\WordPress\WriteGateway` | `NexoDev\NexoMCP\WordPress\WriteGateway` | completa |  |

## Clases nuevas (sin equivalente en el original)

| Clase nueva | Para qué |
|---|---|
| `NexoDev\NexoMCP\Authorization\IrreversibleActions` | Exige `confirmation` exacta (`DELETE`, `RESTORE`, `UNDO`; `update-kit-globals` sólo si `delete` trae ids) en el servidor antes de ejecutar acciones irreversibles, y lo audita. |
| `NexoDev\NexoMCP\Builders\ElementorDesignSystem` | Lee el sistema de diseño de Elementor 4 (clases globales, variables, colores y tipografía del Kit) para `get-design-system`; una lectura que falla se avisa en `warnings` y conserva lo ya leído. |
| `NexoDev\NexoMCP\Builders\DesignSystemWriter` (+ `DesignSystemStore`, `ElementorDesignStore`) | Base de las escrituras del sistema de diseño: bloqueo por el Kit, respaldo del último cambio, vuelta atrás si falla, limpieza de CSS y Actividad; `undo-design-system-change` lo deshace. Permiso propio (`design_system_tools_enabled`, `nexo_mcp_design_system`, `mcp:design_system`). |
| `NexoDev\NexoMCP\Builders\DesignClassOperations` (+ `DesignClassBackend`, `DesignClassElementorBackend`) | `create-global-class`, `update-global-class` y `delete-global-class`: label único, variantes con la validación de F2/F3 y el analizador de Elementor, uso con `Global_Classes_Relations`; escribe con `apply_changes` por `DesignSystemWriter`. |
| `NexoDev\NexoMCP\Builders\DesignVariableOperations` (+ `DesignVariableStore`, `DesignVariableElementorStore`, `DesignVariableValue`) | `create-design-variable`, `update-design-variable` y `delete-design-variable`: valor validado por tipo (el tamaño personalizado con `ElementorTreeValidator::css_text_problem()`), borrado lógico, uso recorriendo `_elementor_data`. |
| `NexoDev\NexoMCP\Builders\DesignKitOperations` (+ `DesignKitStore`, `DesignKitElementorStore`) | `update-kit-globals`: colores y tipografías globales del Kit (system y custom) con listas completas y claves `typography_*` de Elementor 4.4.1. |
| `NexoDev\NexoMCP\Builders\DynamicTagCatalog` | Catálogo cerrado y constante de 19 tags de Elementor Pro sin datos sensibles, con su grupo, categoría y ajustes permitidos; valida y normaliza la prop `{"$$type":"dynamic"}` para `e-heading.title`, `e-paragraph.paragraph`, `e-button.text` y `e-image.image` (los ajustes como texto plano) y lista los tags que el sitio tiene registrados para `list-dynamic-tags`. |
| `NexoDev\NexoMCP\Builders\DynamicTagAssigner` | `set-dynamic-tag`: mismo camino que `ElementorSectionEditor` (bloqueo, copia de trabajo, respaldo, `save_tree`) para escribir el valor normalizado del catálogo en una prop. Permiso propio (`builders_dynamic_enabled`, `nexo_mcp_builders_dynamic`, `mcp:builders:dynamic`, apagado por defecto). |
| `NexoDev\NexoMCP\Builders\ComponentStore` | Componentes de Elementor 4 como datos (`list-components`, `create-component`, `insert-component`): envuelve `Components_Repository` y los validadores de Elementor (`Save_Components_Validator`, `Circular_Dependency_Validator`, `Non_Atomic_Widget_Validator`) y `Components_Access_Controller` (Pro con licencia), todos inyectables. Crea en borrador con uid propio; sus mensajes se devuelven sin entidades HTML. La instancia `e-component` se inserta por el camino de `ElementorSectionEditor` (copia de trabajo), sin overrides. Permiso propio (`builders_components_enabled`, `nexo_mcp_builders_components`, `mcp:builders:components`, apagado por defecto). |
| `NexoDev\NexoMCP\Builders\Divi5Adapter` | Reconoce las páginas de Divi 5 (comentarios `wp:divi/`) y las lee como árbol de nodos con `path` y `fingerprint` para `get-page-structure`; va en `BuilderRegistry` antes de `DiviAdapter` (Divi 4) y de Gutenberg. Sin escrituras genéricas. |
| `NexoDev\NexoMCP\Builders\Divi5Tree` | Ayudantes puros sobre el árbol de `parse_blocks()`: construir, localizar por `path`, `fingerprint` (sha256 del bloque serializado), reemplazar y recomponer los hijos de un padre sin tocar el resto. |
| `NexoDev\NexoMCP\Builders\DiviModuleCatalog` | Catálogo cerrado de módulos de Divi 5 (estructura y contenido) con las rutas de atributos de contenido y de diseño de escritorio permitidas y su forma. |
| `NexoDev\NexoMCP\Builders\DiviTreeValidator` | Valida y sanea en PHP el árbol del cliente y los parches (catálogo, `desktop.value`, `wp_kses`, shortcodes, URLs, imágenes, anidado, 200 nodos / 3 MB); desde 1.9.0 valida también la referencia `preset` de un nodo o de `edit-divi-module` (`nexo_mcp_divi_preset_unknown`) y los `attrs` de los presets; desde 1.9.1 rechaza cualquier token `$variable(…)$` (y sus formas ofuscadas, `has_dynamic_token()`) en los valores que escribe el MCP con `nexo_mcp_divi_dynamic_denied` y `data.path`, y `DiviDesignOperations` lo rechaza en variables globales; desde 1.10.0 `with_dynamic()`/`with_loop()` aceptan sólo los tokens canónicos de `DiviDynamicCatalog` (y los `loop_*` dentro de un bucle del conector) y `stray_token_path()` reescanea el valor final. |
| `NexoDev\NexoMCP\Builders\Divi5BlockBuilder` | Convierte el árbol validado en bloques `divi/*` con `builderVersion` y el `divi/placeholder` raíz. |
| `NexoDev\NexoMCP\Builders\Divi5ContentWriter` | Camino de escritura común de Divi 5: destino (original o copia de trabajo), bloqueo, respaldo, guardado con kses quitado sólo durante el guardado, metas `_et_pb_use_builder`/`_et_pb_use_divi_5` y vaciado del CSS. |
| `NexoDev\NexoMCP\Builders\Divi5ModuleEditor` | `edit-divi-module`: cambia contenido o diseño de escritorio de un módulo por `path` y `fingerprint`, conservando tablet, teléfono y hover; con `preset` (D4, 1.9.0) pone o quita `modulePreset`/`groupPreset` validando los ids contra `DiviPresetStore` (con `preset`, bajo el bloqueo de presets de `DiviDesignStore::LOCK_ID`, tras el de la página). |
| `NexoDev\NexoMCP\Builders\Divi5SectionEditor` | `edit-divi-section`: `replace`/`insert`/`delete` por `path` y `fingerprint`, validado contra el tipo real del padre; el `divi/placeholder` raíz sólo admite `insert` con `position: inside`. |
| `NexoDev\NexoMCP\Builders\Divi5PageCreator` | `create-divi-page`: valida las secciones, crea el borrador (con título escapado), comprueba lo guardado y lo borra si algo falla (`nexo_mcp_divi_save_failed` sin texto crudo de excepciones; `nexo_mcp_builder_copy_orphan` si el borrador no se pudo borrar). |
| `NexoDev\NexoMCP\Builders\DiviDesignStore` | Diseño global de Divi 5 (D3): lectura de colores, variables y tipografía, escritura con las funciones de Divi bajo bloqueo, historial `nexo_mcp_divi_design_history` (20 cambios) y `undo-divi-design-change` con guardia de cambios posteriores; dependencias de Divi inyectables. | nuevo en 1.8.0 | |
| `NexoDev\NexoMCP\Builders\DiviDesignOperations` | `set-divi-global-color`, `delete-divi-global-color` (uso por lotes dentro del bloqueo), `set-divi-variable`, `archive-divi-variable` y `set-divi-typography` con validación completa antes de escribir. | nuevo en 1.8.0 | |
| `NexoDev\NexoMCP\Builders\DiviPresetStore` | Presets de módulos de Divi 5 (D4): lectura de la opción `et_divi_builder_global_presets_d5` sin migrar, escritura bajo bloqueo con `GlobalPreset::save_data` (vacía el CSS) y área `presets` del historial de D3 (una sola pila, 20 cambios, 2 MiB); dependencias de Divi inyectables. | nuevo en 1.9.0 | |
| `NexoDev\NexoMCP\Builders\DiviPresetOperations` | `set-divi-preset`, `set-divi-default-preset` y `delete-divi-preset`: validación de `attrs` (sólo diseño de escritorio del catálogo), uso por lotes dentro del bloqueo (con total y `post_ids_truncated`), id no escaneable → `nexo_mcp_divi_preset_usage_unknown` y quitar con `force` el enlace de `groupPresets`. | nuevo en 1.9.0 | |
| `NexoDev\NexoMCP\Builders\DiviDynamicCatalog` | Contenido dinámico de Divi 5 (D5, `list-divi-dynamic-sources`): lista cerrada de fuentes (entrada, sitio, fecha, WooCommerce básico, ACF de texto) cruzada con lo que registra `DynamicContentOptions::get_options`, categoría `text`/`url`/`image` por campo, ajustes cerrados, forma canónica del token (`enable_html` siempre `off`) y fuentes `loop_*` por elemento de un bucle del conector. Sin meta personalizado, usuarios ni claves protegidas. | nuevo en 1.10.0 | |
| `NexoDev\NexoMCP\Builders\DiviLoopSchema` | Esquema cerrado de `module.advanced.loop` (D6, `set-divi-loop`): un tipo público, 1-24 por bucle, desplazamiento ≤ 1000, términos de taxonomías públicas, sin `metaQuery`/búsqueda; `valid_stored()` reconoce un bucle como del conector sólo si es exactamente lo que se escribiría. | nuevo en 1.10.0 | |
| `NexoDev\NexoMCP\Builders\DiviLoopGuard` | Guarda de render de los bucles `loop-nx…` en el filtro `divi_loop_data_after_execution`: sólo publicado y sin contraseña, tope de 24, sin entradas fijas y consulta vacía si el valor guardado se editó a mano. | nuevo en 1.10.0 | |
| `NexoDev\NexoMCP\Builders\ElementorTreeValidator` | Valida en PHP puro el árbol atómico que recibe `create-elementor-page` (tipos, límites, enlaces, imágenes, clases, valores de estilo y regla de raíz); su comprobación de valores CSS (`css_text_problem()`) la reutilizan las variables globales. Valida también un subárbol que cuelga de un padre existente (`edit-elementor-section`). Rechaza `$$type: dynamic` salvo una prop idéntica a la del nodo original (`edit-elementor-section`) o la que aprueba el catálogo (`set-dynamic-tag`). |
| `NexoDev\NexoMCP\Builders\ElementorTreeBuilder` | Convierte el árbol validado al formato de `_elementor_data` con ids generados por el plugin. |
| `NexoDev\NexoMCP\Builders\ElementorPageCreator` | Crea la página en borrador con los validadores y el guardado de documentos de Elementor; deshace el borrador si algo falla. |
| `NexoDev\NexoMCP\Builders\ElementorSchemaGuard` | Requisitos de Elementor 4 y validación con `Props_Parser`/`Style_Parser`; compartida por `create-elementor-page` y `edit-elementor-section`. |
| `NexoDev\NexoMCP\Builders\ElementorNesting` | Reglas de anidado de los elementos atómicos nuevos (raíz, columna, sección, contenedor, widget) en PHP puro. |
| `NexoDev\NexoMCP\Builders\ElementorTree` | Ayudantes puros sobre el árbol decodificado (localizar, listar ids, contar, recortar); los usan el duplicado de elementos y `edit-elementor-section`. |
| `NexoDev\NexoMCP\Builders\ElementorSectionSplicer` | Planifica y aplica `replace`/`insert`/`delete` sobre el árbol decodificado y comprueba que lo no tocado quede idéntico y que los ids no choquen. |
| `NexoDev\NexoMCP\Builders\ElementorSectionEditor` | Orquesta `edit-elementor-section`: valida, crea o reutiliza la copia de trabajo, respalda y guarda con `save_tree` (nunca con `Document::save`). |
| `NexoDev\NexoMCP\Builders\ElementorPageMigrator` | Orquesta `migrate-elementor-page`: mismo camino que `ElementorSectionEditor` (bloqueo, copia de trabajo, validación, respaldo, `save_tree`) para el árbol que produce `ElementorV3Migrator`. |
| `NexoDev\NexoMCP\Builders\ElementorMigrationChecker` | Valida el árbol migrado: cada nodo atómico nuevo pasa el validador y el saneado de Elementor; un nodo (v3 o no) idéntico a uno del original se acepta tal cual y cualquier otro v3 se rechaza. Sólo lo usa la migración; el validador de las demás herramientas no cambia. |
| `NexoDev\NexoMCP\Builders\ElementorV3Migrator` | Clase pura: convierte el árbol v3 en un árbol mixto (atómicos nuevos, v3 conservados) con `kept_v3` y `unmapped`; los estilos los traduce un `V3StyleMapper` inyectado (`ElementorV3StyleMapper`, con los colores y tipografías del Kit por `V3StyleSource`). |
| `NexoDev\NexoMCP\Support\LineDiff` | Diff unificado por líneas en PHP puro; lo usa la herramienta nueva `get-working-copy-diff` (comparar una copia de trabajo con su original). |
| `NexoDev\NexoMCP\Support\PostLock` | Bloqueo por original en `wp_options` (inserción única, vencimiento de 30 s) para las escrituras que leen y reescriben; `verify()` comprueba antes de escribir que sigue siendo propio y registra en la auditoría (`lock-takeover`) cada toma de un bloqueo vencido. |

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
