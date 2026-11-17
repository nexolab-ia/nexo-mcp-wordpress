# Adaptadores con plugins reales

Probar los adaptadores de Nexo MCP (SEO, campos, constructores) contra los plugins reales
instalados en el WordPress de docker (`app/docker-compose.yml`, http://localhost:8080, admin/admin),
y corregir lo que falle. Plugins gratuitos: Yoast SEO, Advanced Custom Fields, Elementor.

## Specs

- S1. Instalar y activar en el docker Yoast SEO, Advanced Custom Fields (ACF) y Elementor (versiones gratuitas de wordpress.org), de forma reproducible (script, no a mano).
- S2. Una prueba automática de punta a punta (misma conexión OAuth real que `app/tools/prueba_punta_a_punta.py`) que ejercite, con los plugins reales activos:
  - SEO (Yoast): `get-seo-settings`, `get-post-seo`, `update-post-seo` (y comprobar que el valor quedó guardado en los metadatos de Yoast).
  - Campos (ACF): `list-fields`, `update-field` sobre un grupo de campos ACF creado por la prueba (y comprobar el valor en ACF).
  - Constructores (Elementor): `get-page-structure`, `update-node-text`, `update-node-attribute`, `restore-builder-backup` sobre una página Elementor creada por la prueba (y comprobar `_elementor_data`).
  - `get-site-info` debe reportar `elementor` entre los constructores.
- S3. La prueba limpia lo que crea (a la papelera, recuperable), igual que `prueba_reglas.sh`.
- S4. Cada falla encontrada se reproduce, se corrige en el plugin con un test que la fije, y no rompe la suite PHPUnit ni `app/tools/prueba_reglas.sh`.

## Tasks

- [x] T1 (S1-S3) Script de instalación + prueba de adaptadores; correrla y reportar fallas sin tocar `app/nexo-mcp/src`. Ruta: worker + verify. Commit: ver L2
- [—] T2 (S4) Corregir las fallas que reporte T1. Descartada: T1 no encontró fallas (L2).

## Log

- L1 (pedido original, 2026-11-16): «Avanza con lo que indicas» — en respuesta a: «Siguiente: probar los adaptadores con los plugins reales. Puedo instalar Yoast SEO, ACF y Elementor (los tres gratis) en el docker y probar las herramientas de SEO, campos y constructores, primero con la prueba automática y después vos con Claude Code.»
- L2 (2026-11-16): T1 hecha. Plugins: wordpress-seo 28.7 (28.8 exige WP 7.0; el instalador cae a 28.7), advanced-custom-fields 6.8.11, elementor 4.4.1. `tools/prueba_adaptadores.sh`: 10/10 comprobaciones MCP y 8/8 verificaciones con wp-cli, exit 0; `prueba_reglas.sh` exit 0. El verificador independiente aprobó S1-S3; se corrigieron sus avisos: el enlace editado se comprueba con wp-cli antes del restore, la limpieza usa wp_trash_post() (wp post delete no manda a la papelera los tipos de ACF) y se comprueba. Sin cubrir: update-node-attribute de image/alt, la ruta de copia de trabajo con builders/SEO/ACF en contenido publicado, la instalación desde cero del instalador. T2 descartada: no hubo fallas de adaptador.
