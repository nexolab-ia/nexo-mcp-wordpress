#!/usr/bin/env bash
# Prueba de la limpieza de tools/prueba_adaptadores.sh, sin docker: usa las funciones reales del script con lo que
# tocan wp-cli reemplazado.
#   - atrapar_senales/limpiar_al_salir: una corrida cortada con INT, TERM o HUP sale con 130/143/129 pasando por la
#     limpieza completa, y una segunda señal mientras limpia no la corta.
#   - clase_global_quitada: distingue clase quitada (0), clase que sigue (1) y lectura fallida (2).
#
# Uso (desde app/):  tools/prueba_limpieza.sh
set -uo pipefail

cd "$(dirname "$0")/.."
# Cada función, desde `nombre() {` hasta la primera llave que cierra en la columna 0.
extraer() { awk -v f="$1" '$0 ~ "^" f "\\(\\) \\{" { dentro = 1 } dentro { print } dentro && /^\}/ { exit }' tools/prueba_adaptadores.sh; }
funciones="$(extraer limpiar_al_salir; extraer atrapar_senales; extraer clase_global_quitada)"
eval "$funciones"
for f in limpiar_al_salir atrapar_senales clase_global_quitada; do
	declare -F "$f" >/dev/null || { echo "FALLA: no se encontró $f en tools/prueba_adaptadores.sh"; exit 1; }
done

fallas=0
falla() { echo "FALLA: $*"; fallas=$((fallas + 1)); }
ok() { echo "OK: $*"; }
dir="$(mktemp -d)"
trap 'rm -rf "$dir"' EXIT

# Corrida simulada: la limpieza anota su inicio, espera (ahí llega la segunda señal) y anota el final.
cat >"$dir/corrida.sh" <<'EOF'
set -uo pipefail
eval "$FUNCIONES"
cleanup() { echo inicio >>"$MARCAS"; sleep 2; echo fin >>"$MARCAS"; }
wpcli_bajar() { echo bajado >>"$MARCAS"; }
atrapar_senales
echo listo >>"$MARCAS"
sleep 30 &
wait $!
EOF

esperar() { # archivo texto: hasta 10 s a que aparezca la línea
	local i
	for i in $(seq 1 100); do grep -qx "$2" "$1" 2>/dev/null && return 0; sleep 0.1; done
	return 1
}

for par in INT:130 TERM:143 HUP:129; do
	senal="${par%%:*}"; esperado="${par#*:}"
	marcas="$dir/marcas-$senal"
	: >"$marcas"
	# Grupo de procesos propio (set -m): así INT no llega ignorado, como en una terminal.
	set -m
	FUNCIONES="$funciones" MARCAS="$marcas" bash "$dir/corrida.sh" &
	pid=$!
	set +m
	if ! esperar "$marcas" listo; then falla "$senal: la corrida simulada no arrancó"; kill -KILL -- "-$pid" 2>/dev/null; continue; fi
	kill "-$senal" -- "-$pid"
	esperar "$marcas" inicio && kill "-$senal" -- "-$pid" 2>/dev/null
	wait "$pid"; codigo=$?
	kill -KILL -- "-$pid" 2>/dev/null
	[ "$codigo" -eq "$esperado" ] && ok "$senal: sale con $esperado" || falla "$senal: salió con $codigo, se esperaba $esperado"
	[ "$(tr '\n' ' ' <"$marcas")" = "listo inicio fin bajado " ] && ok "$senal: la limpieza corrió entera aunque llegó otra $senal, y después se bajó el contenedor de wp-cli" \
		|| falla "$senal: marcas «$(tr '\n' ' ' <"$marcas")»"
done

# Final normal: un Ctrl-C durante la limpieza del final no la corta y el código refleja la limpieza (la falla que
# importa en la práctica: el INT del bucle de arriba ya llega con la limpieza corriendo dentro de su propio trap).
cat >"$dir/final.sh" <<'EOF2'
set -uo pipefail
eval "$FUNCIONES"
cleanup() { echo inicio >>"$MARCAS"; sleep 2; echo fin >>"$MARCAS"; return 1; }
wpcli_bajar() { echo bajado >>"$MARCAS"; }
atrapar_senales
echo listo >>"$MARCAS"
exit_code=0
trap - EXIT
limpiar_al_salir || exit_code=1
exit "$exit_code"
EOF2
marcas="$dir/marcas-final"
: >"$marcas"
set -m
FUNCIONES="$funciones" MARCAS="$marcas" bash "$dir/final.sh" &
pid=$!
set +m
esperar "$marcas" inicio && kill -INT -- "-$pid" 2>/dev/null
wait "$pid"; codigo=$?
kill -KILL -- "-$pid" 2>/dev/null
[ "$codigo" -eq 1 ] && ok "final: sale con 1 si la limpieza falla" || falla "final: salió con $codigo, se esperaba 1"
[ "$(tr '\n' ' ' <"$marcas")" = "listo inicio fin bajado " ] && ok "final: un Ctrl-C no corta la limpieza" \
	|| falla "final: marcas «$(tr '\n' ' ' <"$marcas")»"

# clase_global_quitada con php_wpr simulado (código|salida).
probar_clase() { # nombre respuesta esperado
	RESPUESTA="$2"
	php_wpr() { printf '%s' "${RESPUESTA#*|}"; return "${RESPUESTA%%|*}"; }
	clase_global_quitada g-nexo123 >/dev/null 2>&1; codigo=$?
	[ "$codigo" -eq "$3" ] && ok "clase_global_quitada: $1 → $3" || falla "clase_global_quitada: $1 → $codigo, se esperaba $3"
}
probar_clase "quitada" '0|{"g-otra":"x"}' 0
probar_clase "sigue" '0|{"g-nexo123":"nexo-prueba-clase"}' 1
probar_clase "lectura fallida" '1|' 2
probar_clase "lectura vacía (json_encode siempre imprime algo)" '0|' 2

[ "$fallas" -eq 0 ] && echo "OK: limpieza" || echo "FALLA: $fallas comprobación(es) de la limpieza fallaron."
[ "$fallas" -eq 0 ]
