#!/bin/sh
# Crea dist/nexo-mcp.zip listo para subir en Plugins › Añadir plugin.
#
# Instala las dependencias de producción (sin las de pruebas y sin ejecutar
# scripts de Composer) en una copia temporal y la comprime. La carpeta
# nexo-mcp/ original no se modifica.
#
# Requiere Docker (Docker Desktop u OrbStack) y el comando zip.
set -e

cd "$(dirname "$0")"
ROOT="$(pwd)"
BUILD="$(mktemp -d)"

echo "Copiando el plugin…"
mkdir -p "$BUILD/nexo-mcp"
tar -C nexo-mcp \
	--exclude ./vendor \
	--exclude ./tests \
	--exclude ./.phpunit.cache \
	--exclude ./phpunit.xml.dist \
	-cf - . | tar -C "$BUILD/nexo-mcp" -xf -

echo "Instalando dependencias de producción (composer install --no-dev --no-scripts)…"
docker run --rm -v "$BUILD/nexo-mcp":/app -w /app composer:2 \
	install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader

mkdir -p dist
rm -f dist/nexo-mcp.zip
(cd "$BUILD" && zip -qr "$ROOT/dist/nexo-mcp.zip" nexo-mcp)

echo "Listo: $ROOT/dist/nexo-mcp.zip"
