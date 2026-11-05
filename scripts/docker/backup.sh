#!/usr/bin/env bash
# Online SQLite backup via SQLite3::backup; storage copy is not atomic with report writes.
set -euo pipefail
cd "$(dirname "$0")/../.."
test -f secrets/laravel.env || { echo 'Falta secrets/laravel.env' >&2; exit 1; }
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BASE="$(pwd)/backups"
mkdir -p "$BASE"
chmod 700 "$BASE"
DEST="$BASE/backup-$TS-$$"
mkdir -m 700 "$DEST"
FILE=".backup-$TS-$$.sqlite"
cleanup() {
  docker compose exec -T app rm -f "/data/sqlite/$FILE" >/dev/null 2>&1 || true
}
trap cleanup EXIT
# No stop: consistent SQLite snapshot; reports/storage are only best effort.
docker compose exec -T app php /var/www/html/docker/runtime/sqlite-backup.php "/data/sqlite/$FILE"
docker compose cp "app:/data/sqlite/$FILE" "$DEST/database.sqlite"
docker compose exec -T app tar -czf - -C /var/www/html/storage . > "$DEST/storage.tar.gz"
cp secrets/laravel.env "$DEST/laravel.env"
cp .env "$DEST/compose.env"
chmod 600 "$DEST"/*
(cd "$DEST" && sha256sum database.sqlite storage.tar.gz laravel.env compose.env > SHA256SUMS)
chmod 600 "$DEST/SHA256SUMS"
echo "Respaldo en: $DEST (contiene credenciales; protégelo y cópialo fuera del equipo)."
echo 'Si había generación de informes en curso, valida la coherencia de los archivos de informes antes de restaurar.'
