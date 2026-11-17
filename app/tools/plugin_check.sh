#!/usr/bin/env bash
# Plugin Check oficial (wordpress.org) sobre nexo-mcp, dentro del docker.
# Sale con 1 ante cualquier ERROR que no esté en la lista de exclusiones
# propias del directorio de wordpress.org (el plugin se distribuye por .zip).
# Imprime cuántos avisos (WARNING) quedan.
#
# Requiere el WordPress de prueba levantado con el plugin activo. Instala y
# activa Plugin Check si falta (también en el CI, que parte de cero).
# Uso (desde app/):  tools/plugin_check.sh
set -euo pipefail

cd "$(dirname "$0")/.."

# Códigos de ERROR aceptados: sólo aplican al directorio de wordpress.org.
ALLOWED=(
	# La carpeta de desarrollo lleva phpstan/phpcs/phpunit .dist (el zip no); el directorio no admite aplicaciones.
	application_detected
	# UpdateChecker actualiza el plugin desde nuestro servidor; el directorio lo prohíbe.
	plugin_updater_detected
	# El readme está en español a propósito; el directorio exige inglés estándar.
	readme_short_description_non_official_language
	readme_description_non_official_language
	# Sólo importa para aparecer en las búsquedas del directorio.
	outdated_tested_upto_header
)

wp() { docker compose run --rm -T --entrypoint wp setup "$@" 2>/dev/null; }

if ! wp plugin is-active plugin-check; then
	wp plugin install plugin-check --activate >/dev/null
fi

check() {
	wp plugin check nexo-mcp --exclude-directories=tests,vendor --format=csv \
		--fields=line,column,type,code,message
}
out="$(check)"
# wp-cli devuelve a veces vacío de forma transitoria. Vacío no es «sin
# hallazgos»: siempre quedan los errores aceptados de arriba.
[ -n "$out" ] || { sleep 2; out="$(check)"; }
[ -n "$out" ] || { echo "FALLA: Plugin Check no devolvió resultados"; exit 1; }

# Líneas CSV: line,column,type,code,message (el mensaje puede traer comas).
errors="$(printf '%s\n' "$out" | awk -F, '$3 == "ERROR" { print $4 }')"
warnings="$(printf '%s\n' "$out" | awk -F, '$3 == "WARNING" { n++ } END { print n + 0 }')"

failed=0
while IFS= read -r code; do
	[ -z "$code" ] && continue
	skip=0
	for ok in "${ALLOWED[@]}"; do
		[ "$code" = "$ok" ] && skip=1
	done
	if [ "$skip" -eq 0 ]; then
		echo "ERROR no permitido: $code" >&2
		failed=1
	fi
done <<<"$errors"

echo "Errores aceptados (solo directorio): $(printf '%s\n' "$errors" | grep -c . || true)"
echo "Avisos restantes: $warnings"
exit "$failed"
