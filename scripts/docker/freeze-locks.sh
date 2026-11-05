#!/usr/bin/env bash
# Freeze the actual dependency resolution from a validated Docker build.
set -euo pipefail
cd "$(dirname "$0")/../.."
if [[ -e composer.lock || -e package-lock.json ]]; then
  echo 'Ya existe algún archivo lock. Revisa y protege las versiones existentes; no sobrescribiré nada.' >&2
  exit 2
fi
docker image inspect osg-nodo-control:local >/dev/null 2>&1 || { echo 'Construye primero la imagen app: bash scripts/docker/build-check.sh' >&2; exit 1; }
docker build --target frontend -t osg-nodo-control-frontend:lock .
APP_ID=$(docker create osg-nodo-control:local)
UI_ID=$(docker create osg-nodo-control-frontend:lock)
cleanup() { docker rm -f "$APP_ID" "$UI_ID" >/dev/null 2>&1 || true; }
trap cleanup EXIT
TMP=$(mktemp -d)
trap 'cleanup; rm -rf "$TMP"' EXIT
docker cp "$APP_ID:/var/www/html/composer.lock" "$TMP/composer.lock"
docker cp "$UI_ID:/build/package-lock.json" "$TMP/package-lock.json"
python3 - "$TMP" <<'PY'
import json,sys
from pathlib import Path
p=Path(sys.argv[1]); c=json.loads((p/'composer.lock').read_text()); n=json.loads((p/'package-lock.json').read_text())
assert c.get('packages') and any(x['name']=='laravel/framework' for x in c['packages'])
assert 'packages' in n and 'vite' in n['packages'].get('',{}).get('devDependencies',{})
PY
mv "$TMP/composer.lock" ./composer.lock
mv "$TMP/package-lock.json" ./package-lock.json
printf 'Locks exportados. Revisa dependencias, ejecuta tests y vuelve a construir las imágenes.\n'
