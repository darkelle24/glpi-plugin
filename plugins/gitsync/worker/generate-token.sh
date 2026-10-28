#!/bin/sh
# Depuis /mnt/Apps/GLPI/git-sync, APRÈS la construction de local/git-sync:2.0.0.
set -eu
umask 077
mkdir -p secrets
if [ -e secrets/token ] || [ -L secrets/token ]; then
    echo 'Un secret existe déjà : aucune modification.' >&2
    exit 1
fi
set -C
docker run --rm --pull never --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges:true --entrypoint python local/git-sync:2.0.0 \
  -c 'import secrets; print(secrets.token_hex(32))' > secrets/token.new
[ "$(wc -c < secrets/token.new | tr -d ' ')" = 65 ]
mv -n secrets/token.new secrets/token
echo 'Secret créé. Adapter son propriétaire/GID pour le worker et PHP (README).'
