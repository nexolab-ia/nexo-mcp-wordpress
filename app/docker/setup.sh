#!/bin/sh
# Instalación inicial del WordPress de prueba de Nexo MCP.
# Es idempotente: si WordPress ya está instalado, sólo se asegura de que los
# plugins estén activos.
set -e

cd /var/www/html

echo "Esperando a que WordPress copie sus archivos…"
i=0
until [ -f wp-config.php ]; do
	i=$((i + 1))
	if [ "$i" -gt 90 ]; then
		echo "wp-config.php no apareció a tiempo" >&2
		exit 1
	fi
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	echo "Instalando WordPress…"
	wp core install \
		--url="${NEXO_WP_URL:-http://localhost:8080}" \
		--title="Nexo MCP (pruebas)" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.test \
		--skip-email
	wp language core install es_CL --activate || true
fi

wp rewrite structure '/%postname%/' --hard

# Las versiones más nuevas de WooCommerce exigen WordPress 7.0; se prueba de
# la más reciente a la más antigua hasta dar con una que acepte WordPress 6.9.
if ! wp plugin is-installed woocommerce; then
	for version in ${NEXO_WC_VERSIONS:-latest 11.0.1 10.9.4 10.8.1 10.7.0}; do
		echo "Probando WooCommerce ${version}…"
		if [ "$version" = "latest" ]; then
			wp plugin install woocommerce --activate && break || true
		else
			wp plugin install woocommerce --version="$version" --activate && break || true
		fi
		wp plugin delete woocommerce >/dev/null 2>&1 || true
	done
else
	wp plugin activate woocommerce || true
fi

if wp plugin is-active woocommerce; then
	echo "WooCommerce $(wp plugin get woocommerce --field=version) activo."
else
	echo "Aviso: no se pudo activar WooCommerce; Nexo MCP funciona igual sin él." >&2
fi

wp plugin activate nexo-mcp

echo "Listo: ${NEXO_WP_URL:-http://localhost:8080}/wp-admin/admin.php?page=nexo-mcp (admin / admin)"
