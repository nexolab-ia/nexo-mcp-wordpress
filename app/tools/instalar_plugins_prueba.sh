#!/usr/bin/env bash
# Instala y activa los plugins reales con los que se prueban los adaptadores:
# Yoast SEO, Advanced Custom Fields y Elementor (versiones gratuitas de
# wordpress.org). Es idempotente: si ya están activos no hace nada.
#
# Uso (desde app/):  tools/instalar_plugins_prueba.sh
set -euo pipefail

cd "$(dirname "$0")/.."
# shellcheck source=wpcli.sh
source tools/wpcli.sh
wp() { wpcli "$@" 2>/dev/null; }

compatible() { case "$1" in wordpress-seo) echo 28.7 ;; esac; }

for slug in wordpress-seo advanced-custom-fields elementor; do
	if [ "$(wp plugin is-active "$slug" >/dev/null && echo si || echo no)" = "si" ]; then
		echo "Ya activo: $slug"
	else
		echo "Instalando y activando: $slug"
		if ! wp plugin install "$slug" --activate >/dev/null; then
			# La última versión puede exigir un WordPress más nuevo que el del docker
			# (Yoast 28.8 pide 7.0): se usa la última compatible conocida.
			fallback="$(compatible "$slug")"
			[ -n "$fallback" ] || { echo "FALLA: no se pudo instalar $slug"; exit 1; }
			echo "  La última versión no es compatible con este WordPress; usando $slug $fallback"
			wp plugin install "$slug" --version="$fallback" --activate >/dev/null
		fi
	fi
done

echo
echo "Versiones instaladas:"
for slug in wordpress-seo advanced-custom-fields elementor; do
	echo "  $slug $(wp plugin get "$slug" --field=version) ($(wp plugin get "$slug" --field=status))"
done
