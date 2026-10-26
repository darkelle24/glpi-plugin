#!/bin/sh
# Copy only this plugin; retain the previous version as a recoverable backup.
set -eu
for utility in dirname mkdir mktemp cp find chmod mv date; do
    command -v "$utility" >/dev/null 2>&1 || { printf 'Required utility missing: %s\n' "$utility" >&2; exit 1; }
done
source_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd -P)
: "${PLUGINS_DIR:?Set PLUGINS_DIR to the existing host directory mounted as GLPI plugins}"
case "$PLUGINS_DIR" in /*) ;; *) echo 'PLUGINS_DIR must be absolute.' >&2; exit 1 ;; esac
[ -d "$PLUGINS_DIR" ] || { echo 'Create the plugins directory/dataset first.' >&2; exit 1; }
plugins_dir=$(CDPATH='' cd -- "$PLUGINS_DIR" && pwd -P)
[ "$plugins_dir" != / ] || { echo 'Refusing the filesystem root.' >&2; exit 1; }
case "$plugins_dir/" in "$source_dir/"*) echo 'Target must be outside the source plugin.' >&2; exit 1 ;; esac
target="$plugins_dir/mailsignaturecleaner"
[ ! -L "$target" ] || { echo 'Refusing a symlink as destination.' >&2; exit 1; }
[ -f "$source_dir/setup.php" ] && [ -f "$source_dir/src/Cleaner.php" ]
if [ -e "$target" ] && [ ! -f "$target/setup.php" ]; then
    echo 'Destination exists but is not a recognizable plugin. Nothing changed.' >&2
    exit 1
fi
backup_dir="${BACKUP_DIR:-$(dirname -- "$plugins_dir")/plugin-backups/mailsignaturecleaner}"
case "$backup_dir" in /*) ;; *) echo 'BACKUP_DIR must be absolute.' >&2; exit 1 ;; esac
mkdir -p -- "$backup_dir"
backup_dir=$(CDPATH='' cd -- "$backup_dir" && pwd -P)
case "$backup_dir/" in "$plugins_dir/"*) echo 'Keep backups outside the plugins directory.' >&2; exit 1 ;; esac
stage=$(mktemp -d "$plugins_dir/.msc-stage-XXXXXX")
cp -R "$source_dir/." "$stage/"
find "$stage" -type d -exec chmod 0755 {} +
find "$stage" -type f -exec chmod 0644 {} +
backup=''
if [ -e "$target" ]; then
    backup="$backup_dir/$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mv -- "$target" "$backup"
fi
if ! mv -- "$stage" "$target"; then
    if [ -n "$backup" ]; then mv -- "$backup" "$target"; fi
    echo 'Deployment failed; previous directory restored if present.' >&2
    exit 1
fi
printf 'Installed: %s\n' "$target"
if [ -n "$backup" ]; then printf 'Backup: %s\n' "$backup"; fi
printf '%s\n' 'Restart the GLPI container, then install/activate the plugin in GLPI.'
