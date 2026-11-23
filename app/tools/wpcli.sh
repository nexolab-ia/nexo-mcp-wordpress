# wp-cli del docker para los scripts de tools/. Se incluye con `source tools/wpcli.sh` desde app/.
#
# Sin NEXO_WPCLI, cada llamada levanta un contenedor nuevo del servicio `setup` (`docker compose run --rm`, ~1,9 s).
# Con NEXO_WPCLI, el id de un contenedor vivo del mismo servicio, cada llamada es un `docker exec` (~0,4 s).
# wpcli_levantar crea ese contenedor y exporta NEXO_WPCLI, así lo usan también los procesos hijos (los subcomandos que
# lanza Python); wpcli_bajar lo quita sólo si lo creó este proceso, nunca uno que llegó de afuera. El contenedor vive
# como máximo 2 h (`sleep 7200`): uno que quedó perdido porque la corrida murió sin limpiar se va solo.
#
#   wpcli [-e CLAVE=valor]… <argumentos de wp>   las -e pasan como variables de entorno; stderr sale tal cual
#
# Memoria: con Elementor Pro activo, wp-cli muere con el memory_limit de 128M del servicio `setup`. Si NEXO_WPCLI_MEMORIA
# trae un límite (p. ej. 1G), wp-cli corre como `php -d memory_limit=<límite> /usr/local/bin/wp`; sin ella, el comando es
# el de siempre. wpcli_memoria_pro la fija (y exporta) sólo si el sitio tiene elementor-pro instalado; no pisa una ya dada.

wpcli() {
	local -a entorno=()
	while [ "${1:-}" = "-e" ] && [ "$#" -ge 2 ]; do entorno+=(-e "$2"); shift 2; done
	if [ -n "${NEXO_WPCLI_MEMORIA:-}" ]; then
		if [ -n "${NEXO_WPCLI:-}" ]; then
			docker exec -i -w /var/www/html ${entorno[@]+"${entorno[@]}"} "$NEXO_WPCLI" php -d "memory_limit=$NEXO_WPCLI_MEMORIA" /usr/local/bin/wp "$@"
		else
			docker compose run --rm -T ${entorno[@]+"${entorno[@]}"} --entrypoint php setup -d "memory_limit=$NEXO_WPCLI_MEMORIA" /usr/local/bin/wp "$@"
		fi
	elif [ -n "${NEXO_WPCLI:-}" ]; then
		docker exec -i -w /var/www/html ${entorno[@]+"${entorno[@]}"} "$NEXO_WPCLI" wp "$@"
	else
		docker compose run --rm -T ${entorno[@]+"${entorno[@]}"} --entrypoint wp setup "$@"
	fi
}

wpcli_memoria_pro() {
	[ -n "${NEXO_WPCLI_MEMORIA:-}" ] && return 0
	if docker compose exec -T wordpress test -d /var/www/html/wp-content/plugins/elementor-pro >/dev/null 2>&1; then
		NEXO_WPCLI_MEMORIA=1G
		export NEXO_WPCLI_MEMORIA
	fi
	return 0
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
