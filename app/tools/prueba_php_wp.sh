#!/usr/bin/env bash
# Prueba del ayudante php_wp/php_wpr de tools/prueba_adaptadores.sh, sin docker: `docker` se reemplaza por una
# función que imprime lo que wp-cli imprimiría. Comprueba que sólo vuelve lo marcado, que los valores van como
# NEXO_A<n>, que sin marca (wp-cli o el PHP fallaron) sale con error y avisa, y que php_wpr reintenta una vez.
#
# Uso (desde app/):  tools/prueba_php_wp.sh
set -uo pipefail

cd "$(dirname "$0")/.."
# shellcheck source=wpcli.sh
source tools/wpcli.sh
# Desde `php_wp() {` hasta la llave que cierra `php_wpr`.
eval "$(awk '/^php_wp\(\) \{/ { dentro = 1 } dentro { print } /^php_wpr\(\)/ { ultima = 1 } ultima && /^\}/ { exit }' tools/prueba_adaptadores.sh)"
for f in php_wp php_wpr wpcli wpcli_levantar wpcli_bajar; do declare -F "$f" >/dev/null || { echo "FALLA: no se encontró $f"; exit 1; }; done
unset NEXO_WPCLI

fallas=0
falla() { echo "FALLA: $*"; fallas=$((fallas + 1)); }
ok() { echo "OK: $*"; }
sleep() { :; }
dir="$(mktemp -d)"
argumentos="$dir/argumentos"
error="$dir/error"
trap 'rm -rf "$dir"' EXIT

# Respuestas simuladas de wp-cli como «código|salida», una por llamada y en orden. Van en archivos porque `docker`
# corre en un subshell (primer paso del pipeline) y no puede consumir una lista de la shell.
responder() {
	local i=1 r
	rm -f "$dir"/r.*
	echo 0 >"$dir/n"
	for r in "$@"; do printf '%s' "$r" >"$dir/r.$i"; i=$((i + 1)); done
}
marcada() { printf 'PHP Notice: antes @@NEXO@@\n\n@@NEXO@@%s@@NEXO@@\nPHP Notice: al cerrar\n' "$(printf '%s' "$1" | base64 | tr -d '\n')"; }
docker() {
	printf '<%s>\n' "$@" >>"$argumentos"
	local n r="0|"
	n=$(($(cat "$dir/n") + 1))
	echo "$n" >"$dir/n"
	[ -f "$dir/r.$n" ] && r="$(cat "$dir/r.$n")"
	printf '%s' "${r#*|}"
	return "${r%%|*}"
}

valor=$'a"b$c d\nlínea 2'
responder "0|$(marcada "$valor")"
salida="$(php_wp 'echo 1;' 2>"$error")"; codigo=$?
[ "$salida" = "$valor" ] && [ "$codigo" -eq 0 ] && ok "sólo vuelve lo marcado (avisos antes y después, varias líneas)" \
	|| falla "salida marcada: «${salida}» (código $codigo)"

: >"$argumentos"
responder "0|$(marcada '')"
php_wp 'X' 'a"b$c d' '' "it's" >/dev/null
grep -qx '<NEXO_A1=a"b$c d>' "$argumentos" && grep -qx '<NEXO_A2=>' "$argumentos" && grep -qx "<NEXO_A3=it's>" "$argumentos" \
	&& ok "los valores van como NEXO_A1… sin tocar el código" || falla "argumentos de docker: $(tr '\n' ' ' <"$argumentos")"

: >"$argumentos"
responder "0|$(marcada 'x')"
php_wp 'X' >/dev/null
grep -q '^<-e>$' "$argumentos" && falla "sin valores no debía pasar -e" || ok "sin valores no pasa -e (bash 3.2 con set -u)"

responder "255|PHP Fatal error: algo"
salida="$(php_wp 'X' 2>"$error")"; codigo=$?
[ -z "$salida" ] && [ "$codigo" -ne 0 ] && grep -q "php_wp" "$error" && ok "sin marca (PHP fatal): vacío, código $codigo y aviso" \
	|| falla "sin marca: «${salida}», código $codigo, error «$(cat "$error")»"

responder "0|"
salida="$(php_wp 'X' 2>"$error")"; codigo=$?
[ -z "$salida" ] && [ "$codigo" -ne 0 ] && grep -q "php_wp" "$error" && ok "sin salida y código 0: igual falla" \
	|| falla "sin salida: «${salida}», código $codigo, error «$(cat "$error")»"

responder "0|$(marcada '')"
salida="$(php_wp 'X' 2>"$error")"; codigo=$?
[ -z "$salida" ] && [ "$codigo" -eq 0 ] && [ ! -s "$error" ] && ok "salida vacía marcada: vacío y código 0" \
	|| falla "vacío marcado: «${salida}», código $codigo, error «$(cat "$error")»"

responder "0|PHP Notice: x
@@NEXO@@$(printf '\377ok' | base64)@@NEXO@@"
salida="$(php_wp 'X' 2>"$error")"; codigo=$?
[ "$codigo" -eq 0 ] && ! grep -q "Traceback" "$error" && case "$salida" in *ok) true ;; *) false ;; esac \
	&& ok "bytes que no son UTF-8: sin traceback" || falla "no UTF-8: «${salida}», código $codigo, error «$(cat "$error")»"

responder "255|PHP Fatal error: transitorio" "0|$(marcada 'segunda')"
salida="$(php_wpr 'X' 2>/dev/null)"; codigo=$?
[ "$salida" = "segunda" ] && [ "$codigo" -eq 0 ] && ok "php_wpr reintenta una vez si falló" || falla "php_wpr: «${salida}» (código $codigo)"

responder "255|x" "255|x" "0|$(marcada 'tercera')"
salida="$(php_wpr 'X' 2>/dev/null)"; codigo=$?
[ -z "$salida" ] && [ "$codigo" -ne 0 ] && ok "php_wpr no reintenta más de una vez" || falla "php_wpr dos fallas: «${salida}» (código $codigo)"

# Cómo llega a docker: sin NEXO_WPCLI, un contenedor nuevo por llamada; con él, un exec en el contenedor vivo.
linea() { tr '\n' ' ' <"$argumentos"; }
: >"$argumentos"
responder "0|$(marcada '')"
php_wp 'X' 'v1' >/dev/null
case "$(linea)" in
	"<compose> <run> <--rm> <-T> <-e> <NEXO_A1=v1> <--entrypoint> <wp> <setup> <eval> <--user=admin> "*) ok "sin NEXO_WPCLI: docker compose run por llamada" ;;
	*) falla "sin NEXO_WPCLI: $(linea)" ;;
esac
: >"$argumentos"
responder "0|$(marcada '')"
NEXO_WPCLI=c123 php_wp 'X' 'v1' >/dev/null
case "$(linea)" in
	"<exec> <-i> <-w> </var/www/html> <-e> <NEXO_A1=v1> <c123> <wp> <eval> <--user=admin> "*) ok "con NEXO_WPCLI: docker exec en ese contenedor" ;;
	*) falla "con NEXO_WPCLI: $(linea)" ;;
esac

# wpcli_levantar/wpcli_bajar: crea y exporta el contenedor; sólo baja el propio.
: >"$argumentos"
responder "0|c-nuevo"
wpcli_levantar
[ "${NEXO_WPCLI:-}" = c-nuevo ] && [ "$(linea)" = "<compose> <run> <-d> <--rm> <--entrypoint> <sleep> <setup> <7200> " ] \
	&& bash -c '[ "$NEXO_WPCLI" = c-nuevo ]' && ok "wpcli_levantar crea el contenedor (2 h como máximo) y lo exporta" \
	|| falla "wpcli_levantar: NEXO_WPCLI=«${NEXO_WPCLI:-}», docker: $(linea)"
: >"$argumentos"
wpcli_bajar
[ -z "${NEXO_WPCLI:-}" ] && [ "$(linea)" = "<rm> <-f> <c-nuevo> " ] && ok "wpcli_bajar quita el contenedor propio" \
	|| falla "wpcli_bajar: NEXO_WPCLI=«${NEXO_WPCLI:-}», docker: $(linea)"
: >"$argumentos"
NEXO_WPCLI=c-ajeno
wpcli_levantar
wpcli_bajar
[ "$NEXO_WPCLI" = c-ajeno ] && [ ! -s "$argumentos" ] && ok "uno que llegó de afuera no se crea ni se baja" \
	|| falla "contenedor ajeno: NEXO_WPCLI=«$NEXO_WPCLI», docker: $(linea)"
unset NEXO_WPCLI
: >"$argumentos"
responder "1|"
wpcli_levantar; codigo=$?
[ "$codigo" -ne 0 ] && [ -z "${NEXO_WPCLI:-}" ] && ok "si no se puede levantar, sale con error y sigue sin contenedor" \
	|| falla "levantar fallido: código $codigo, NEXO_WPCLI=«${NEXO_WPCLI:-}»"

[ "$fallas" -eq 0 ] && echo "OK: php_wp" || echo "FALLA: $fallas comprobación(es) de php_wp fallaron."
[ "$fallas" -eq 0 ]
