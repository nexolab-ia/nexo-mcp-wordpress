# wp-cli del docker para los scripts de tools/. Se incluye con `source tools/wpcli.sh` desde app/.
#
# Sin NEXO_WPCLI, cada llamada levanta un contenedor nuevo del servicio `setup` (`docker compose run --rm`, ~1,9 s).
# Con NEXO_WPCLI, el id de un contenedor vivo del mismo servicio, cada llamada es un `docker exec` (~0,4 s).
# wpcli_levantar crea ese contenedor y exporta NEXO_WPCLI, así lo usan también los procesos hijos (los subcomandos que
# lanza Python); wpcli_bajar lo quita sólo si lo creó este proceso, nunca uno que llegó de afuera. El contenedor vive
# como máximo 2 h (`sleep 7200`): uno que quedó perdido porque la corrida murió sin limpiar se va solo.
#
#   wpcli [-e CLAVE=valor]… <argumentos de wp>   las -e pasan como variables de entorno; stderr sale tal cual

wpcli() {
	local -a entorno=()
	while [ "${1:-}" = "-e" ] && [ "$#" -ge 2 ]; do entorno+=(-e "$2"); shift 2; done
	if [ -n "${NEXO_WPCLI:-}" ]; then
		docker exec -i -w /var/www/html ${entorno[@]+"${entorno[@]}"} "$NEXO_WPCLI" wp "$@"
	else
		docker compose run --rm -T ${entorno[@]+"${entorno[@]}"} --entrypoint wp setup "$@"
	fi
}

wpcli_propio=""
wpcli_levantar() {
	[ -n "${NEXO_WPCLI:-}" ] && return 0
	local id
	id="$(docker compose run -d --rm --entrypoint sleep setup 7200 2>/dev/null)" && [ -n "$id" ] || return 1
	NEXO_WPCLI="$id"
	wpcli_propio="$id"
	export NEXO_WPCLI
}
wpcli_bajar() {
	[ -n "$wpcli_propio" ] || return 0
	docker rm -f "$wpcli_propio" >/dev/null 2>&1
	unset NEXO_WPCLI
	wpcli_propio=""
}
