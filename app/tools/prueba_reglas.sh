#!/usr/bin/env bash
# Prueba de reglas de Nexo MCP contra el WordPress de docker-compose.
#
# 1. Modo «Solo revisar»: activa read_only, exige que la escritura se rechace
#    y que no quede ningún post creado. Restaura los ajustes al terminar.
# 2. Modo edición: corre la prueba de punta a punta completa (copia de trabajo,
#    confirmaciones inválidas) y comprueba que la limpieza dejó en la papelera
#    lo que creó la prueba (el post y la copia de trabajo descartada).
#
# Uso (desde app/):  tools/prueba_reglas.sh
set -euo pipefail

cd "$(dirname "$0")/.."
wp() { docker compose run --rm -T --entrypoint wp setup "$@" 2>/dev/null; }
count_live() {
	# Posts con ese título fuera de la papelera.
	wp post list --post_type=post --post_status=draft,pending,private,publish,future \
		--title="$1" --format=count
}

original="$(wp option get nexo_mcp_settings --format=json)"
[ -n "$original" ] || { echo "FALLA: no se pudieron leer los ajustes"; exit 1; }
# Ajustes de partida con cambios puntuales (un sitio recién instalado arranca
# en «Solo revisar»: cada modo fija lo que necesita y al final se restaura).
with_settings() { printf '%s' "$original" | python3 -I -c "import json,sys; s=json.load(sys.stdin); s.update($1); print(json.dumps(s))"; }
restore() { wp option update nexo_mcp_settings "$original" --format=json >/dev/null; echo "Ajustes restaurados."; }
trap restore EXIT

echo "### 1. Modo «Solo revisar»"
title_ro="Prueba solo lectura $RANDOM$RANDOM"
wp option update nexo_mcp_settings "$(with_settings 'read_only=True')" --format=json >/dev/null
python3 -I tools/prueba_punta_a_punta.py --solo-lectura --titulo "$title_ro"
live="$(count_live "$title_ro")"
[ "$live" = "0" ] || { echo "FALLA: «Solo revisar» dejó $live post(s) creados"; exit 1; }
echo "OK: no se creó ningún post en «Solo revisar»."

echo
echo "### 2. Modo edición con limpieza"
edit="read_only=False, drafts_only=False, content_tools_enabled=True, publishing_enabled=True, working_copy_default=True"
wp option update nexo_mcp_settings "$(with_settings "$edit")" --format=json >/dev/null
title_rw="Prueba edición $RANDOM$RANDOM"
output="$(python3 -I tools/prueba_punta_a_punta.py --titulo "$title_rw")" || { printf '%s\n' "$output"; exit 1; }
printf '%s\n' "$output"
copy_id="$(printf '%s\n' "$output" | sed -n 's/^copia de trabajo: //p' | tail -1)"
live="$(count_live "$title_rw")"
trashed="$(wp post list --post_type=post --post_status=trash --title="$title_rw" --format=count)"
[ "$live" = "0" ] && [ "$trashed" = "1" ] || {
	echo "FALLA: limpieza incompleta (fuera de la papelera: $live, en la papelera: $trashed)"
	exit 1
}
echo "OK: el post de prueba quedó en la papelera."
copy_status="$(wp post get "$copy_id" --field=post_status || echo borrada)"
[ "$copy_status" = "trash" ] || { echo "FALLA: la copia de trabajo ${copy_id} descartada quedó como «${copy_status}», no en la papelera"; exit 1; }
echo "OK: la copia de trabajo $copy_id descartada quedó en la papelera."
echo
echo "OK: todas las reglas pasaron."
