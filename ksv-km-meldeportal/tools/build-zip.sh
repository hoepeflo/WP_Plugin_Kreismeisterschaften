#!/usr/bin/env bash
# Erzeugt ein installierbares Plugin-ZIP nur mit den nötigen Dateien.
#
# Aufruf (im Plugin-Ordner):   bash tools/build-zip.sh
# Ergebnis:                    build/ksv-km-meldeportal-<version>.zip
#
# Ablauf: Kopie nach build/ksv-km-meldeportal/, dort "composer install --no-dev"
# (Laufzeit-Abhängigkeiten werden mit ausgeliefert, auf dem Server läuft kein
# Composer), docs/, tools/, tests/ und Entwicklungsdateien entfallen.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="ksv-km-meldeportal"
VERSION="$(grep -E '^\s*\*\s*Version:' "$PLUGIN_DIR/$SLUG.php" | head -1 | sed -E 's/.*Version:\s*//; s/\s+$//')"
BUILD_DIR="$PLUGIN_DIR/build"
STAGE="$BUILD_DIR/$SLUG"
ZIP="$BUILD_DIR/$SLUG-$VERSION.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

rsync -a "$PLUGIN_DIR/" "$STAGE/" \
	--exclude '/build' \
	--exclude '/docs' \
	--exclude '/tools' \
	--exclude '/tests' \
	--exclude '/vendor' \
	--exclude '/.git*' \
	--exclude '/.phpunit*' \
	--exclude '/phpunit.xml*' \
	--exclude '/phpstan.neon*' \
	--exclude '*.zip' \
	--exclude '.DS_Store'

if grep -q '"require"' "$STAGE/composer.json" && grep -qE '"[a-z0-9_.-]+/[a-z0-9_.-]+"\s*:' <(sed -n '/"require"/,/}/p' "$STAGE/composer.json"); then
	# Laufzeit-Abhängigkeiten vorhanden: vendor/ ohne Dev-Pakete erzeugen.
	(cd "$STAGE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative)
	rm -f "$STAGE/composer.lock"
else
	# Keine Laufzeit-Abhängigkeiten: der eingebaute PSR-4-Autoloader reicht.
	rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
fi

mkdir -p "$BUILD_DIR"
(cd "$BUILD_DIR" && zip -qr "$ZIP" "$SLUG")
rm -rf "$STAGE"

echo "Fertig: $ZIP"
unzip -l "$ZIP" | tail -1
