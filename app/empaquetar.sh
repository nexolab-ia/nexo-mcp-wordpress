#!/bin/sh
# Crea dist/nexo-mcp-<versión>.zip listo para subir en Plugins › Añadir plugin.
#
# 1. Comprueba que la versión coincida en las tres declaraciones (cabecera
#    `Version:` y constante NEXO_MCP_VERSION de nexo-mcp.php, `Stable tag:` de
#    readme.txt) y que readme.txt tenga su entrada en `== Changelog ==`.
# 2. Copia el plugin sin los archivos de desarrollo (pruebas, configuración de
#    PHPUnit/PHPCS/PHPStan, cachés) a una carpeta temporal.
# 3. Instala ahí sólo las dependencias de producción (sin ejecutar scripts ni
#    plugins de Composer) y lo comprime. La carpeta nexo-mcp/ no se modifica.
# 4. Revisa el zip: no debe llevar archivos de desarrollo y sí el autoload.
#
# Requiere Docker (Docker Desktop u OrbStack) y los comandos zip y unzip.
# Uso (desde app/ o cualquier carpeta):  ./empaquetar.sh
set -eu

cd "$(dirname "$0")"
ROOT="$(pwd)"
PLUGIN="$ROOT/nexo-mcp"

falla() { echo "FALLA: $*" >&2; exit 1; }

header="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN/nexo-mcp.php" | tr -d '[:space:]')"
constant="$(sed -n "s/^define( 'NEXO_MCP_VERSION', '\([^']*\)' );/\1/p" "$PLUGIN/nexo-mcp.php")"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$PLUGIN/readme.txt" | tr -d '[:space:]')"

[ -n "$header" ] || falla "no se encontró la cabecera Version: en nexo-mcp.php"
[ "$header" = "$constant" ] || falla "Version: ($header) y NEXO_MCP_VERSION ($constant) no coinciden en nexo-mcp.php"
[ "$header" = "$stable" ] || falla "Version: ($header) y Stable tag: ($stable) de readme.txt no coinciden"
sed -n '/^== Changelog ==/,$p' "$PLUGIN/readme.txt" | grep -qx "= $header =" \
	|| falla "readme.txt no tiene la entrada «= $header =» en == Changelog =="

VERSION="$header"
ZIP="$ROOT/dist/nexo-mcp-$VERSION.zip"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "Versión $VERSION"
echo "Copiando el plugin sin archivos de desarrollo…"
mkdir -p "$BUILD/nexo-mcp"
tar -C "$PLUGIN" \
	--exclude ./vendor \
	--exclude ./tests \
	--exclude ./.phpunit.cache \
	--exclude ./phpunit.xml.dist \
	--exclude ./phpcs.xml.dist \
	--exclude ./phpstan.neon.dist \
	--exclude ./phpstan-baseline.neon \
	--exclude '.DS_Store' \
	-cf - . | tar -C "$BUILD/nexo-mcp" -xf -

echo "Instalando dependencias de producción (composer install --no-dev --no-scripts --no-plugins)…"
docker run --rm -v "$BUILD/nexo-mcp":/app -w /app composer:2 \
	install --no-dev --no-scripts --no-plugins --no-interaction --no-progress --optimize-autoloader

mkdir -p dist
rm -f "$ZIP"
(cd "$BUILD" && zip -qr "$ZIP" nexo-mcp)

echo "Revisando el contenido del zip…"
contents="$(unzip -Z1 "$ZIP")"
for dev in nexo-mcp/tests/ nexo-mcp/phpunit.xml.dist nexo-mcp/phpcs.xml.dist nexo-mcp/phpstan.neon.dist nexo-mcp/phpstan-baseline.neon nexo-mcp/.phpunit.cache/; do
	if printf '%s\n' "$contents" | grep -q "^$dev"; then
		falla "el zip incluye un archivo de desarrollo: $dev"
	fi
done
for required in nexo-mcp/nexo-mcp.php nexo-mcp/readme.txt nexo-mcp/vendor/autoload.php; do
	printf '%s\n' "$contents" | grep -qx "$required" || falla "al zip le falta $required"
done
if printf '%s\n' "$contents" | grep -qE '^nexo-mcp/vendor/(bin|phpunit|squizlabs|phpstan)/'; then
	falla "el zip incluye dependencias de desarrollo"
fi

echo "Listo: $ZIP ($(printf '%s\n' "$contents" | grep -c .) archivos)"
