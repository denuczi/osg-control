#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
command -v docker >/dev/null || { echo 'Docker no instalado' >&2; exit 1; }
bash scripts/docker/check-engine.sh
docker compose config --quiet
if [[ ! -f composer.lock || ! -f package-lock.json ]]; then
  echo 'AVISO: faltan archivos de bloqueo de dependencias. La primera imagen resolverá versiones compatibles, pero la compilación no será totalmente reproducible.' >&2
  echo 'Fija y revisa composer.lock y package-lock.json antes de publicar imágenes de distribución.' >&2
fi
# Build sequentially to avoid memory spikes on WSL2 with a 5 GB RAM cap.
docker compose build app
docker compose build web
