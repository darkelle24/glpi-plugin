#!/bin/sh
set -eu

echo "=== Déploiement GLPI ==="

SOURCE_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

: "${PLUGINS_PATH:?PLUGINS_PATH manquant}"
: "${MARKETPLACE_PATH:?MARKETPLACE_PATH manquant}"

mkdir -p "$PLUGINS_PATH" "$MARKETPLACE_PATH"

if [ -d "$SOURCE_DIR/plugins" ]; then
    echo "Installation des plugins..."
    cp -R "$SOURCE_DIR/plugins/." "$PLUGINS_PATH/"
fi

if [ -d "$SOURCE_DIR/marketplace" ]; then
    echo "Installation du marketplace..."
    cp -R "$SOURCE_DIR/marketplace/." "$MARKETPLACE_PATH/"
fi

echo "=== Déploiement terminé ==="
