#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
docker image inspect osg-nodo-control:local osg-nodo-control-web:local >/dev/null
mkdir -p out
docker save -o out/osg-nodo-control-linux-images.tar osg-nodo-control:local osg-nodo-control-web:local
sha256sum out/osg-nodo-control-linux-images.tar > out/osg-nodo-control-linux-images.tar.sha256
printf 'Imágenes exportadas: out/osg-nodo-control-linux-images.tar\n'
printf 'En otra máquina: docker load -i out/osg-nodo-control-linux-images.tar\n'
