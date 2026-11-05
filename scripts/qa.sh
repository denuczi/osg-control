#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

command -v php >/dev/null || { echo 'Falta PHP' >&2; exit 1; }
command -v python3 >/dev/null || { echo 'Falta Python 3' >&2; exit 1; }
command -v npm >/dev/null || { echo 'Falta npm' >&2; exit 1; }
[[ -f vendor/autoload.php ]] || { echo 'Falta vendor/: ejecutar composer install' >&2; exit 1; }
[[ -d node_modules ]] || { echo 'Falta node_modules/: ejecutar npm install' >&2; exit 1; }

echo '[1/5] Pruebas Laravel'
php artisan test
echo '[2/5] Pint (solo verificación, sin reformatear)'
./vendor/bin/pint --test
echo '[3/5] TypeScript/Vue'
npm run typecheck
echo '[4/5] Vite'
npm run build
echo '[5/5] Agente Python (sin acceso a Windows ni a LAN)'
python3 -m unittest discover -s agent/tests -v
