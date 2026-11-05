#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
command -v docker >/dev/null || { echo 'Falta Docker Engine. Lee DOCKER-LINUX-WINDOWS.md.' >&2; exit 1; }
docker compose version >/dev/null || { echo 'Falta el complemento Docker Compose.' >&2; exit 1; }
command -v python3 >/dev/null || { echo 'Falta Python 3 para la configuración inicial.' >&2; exit 1; }
bash scripts/docker/check-engine.sh
if [[ ! -e secrets/laravel.env && ! -e .env ]]; then
  python3 scripts/docker/configure.py init
elif [[ ! -r secrets/laravel.env || ! -r .env ]]; then
  echo 'Estado incompleto; existe solo uno de los archivos de configuración. No se sobrescribirá la APP_KEY. Revisa secrets/laravel.env y .env.' >&2
  exit 2
fi
# Detect a conflicting Linux/WSL listener before starting a fresh deployment.
if [[ -z "$(docker compose ps -q web 2>/dev/null)" ]]; then
  python3 - <<'PYPORT'
import socket
from pathlib import Path
entries = dict(line.split('=',1) for line in Path('.env').read_text().splitlines() if '=' in line and not line.startswith('#'))
addr = entries.get('OSG_BIND_IP', '127.0.0.1')
port = int(entries.get('OSG_PORT', '8088'))
with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
    try:
        sock.bind((addr, port))
    except OSError as exc:
        raise SystemExit(f'Puerto {port} ya ocupado en {addr}: {exc}. Cambia el puerto mediante configure.py network antes de continuar.')
PYPORT
fi
echo 'Compilando las imágenes Linux de Laravel/Vue y Nginx. La primera compilación requiere Internet.'
bash scripts/docker/build-check.sh
echo 'Inicializando SQLite y arrancando servicios...'
if ! docker compose up -d --no-build --wait --wait-timeout 180; then
  docker compose ps -a >&2
  docker compose logs --tail=100 init app web worker scheduler >&2
  exit 1
fi
if ! docker compose ps --format json >/dev/null; then
  echo 'ADVERTENCIA: no se pudo consultar el estado de los servicios.' >&2
fi
echo 'Crear administrador o actualizar credenciales (requiere terminal interactiva):'
docker compose exec app php artisan nodo:admin
cat <<'DONE'
Inicio solicitado. Comprueba el estado con: docker compose ps
Panel local: http://127.0.0.1:8088/login (si conservaste el puerto predeterminado)
Si se producen fallos: docker compose logs --tail=100 init app web worker scheduler
DONE
