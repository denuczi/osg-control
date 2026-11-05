#!/usr/bin/env bash
set -euo pipefail
if ! docker info >/dev/null; then
  cat >&2 <<'HELP'
No se pudo acceder a Docker Engine. Revisa el error anterior.
Si indica "permission denied" en /var/run/docker.sock, ejecuta en tu terminal:
  sudo groupadd --force docker
  sudo usermod -aG docker "$USER"
  newgrp docker
El grupo docker concede privilegios de administrador sobre el equipo.
Después comprueba: docker info
Y vuelve a ejecutar: bash scripts/docker/setup.sh
Si indica que no puede conectar con el daemon, comprueba el servicio:
  sudo systemctl status docker
HELP
  exit 1
fi
