#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
echo '== Docker Compose =='
docker compose version
echo '== Configuración =='
docker compose config -q
echo '== Servicios =='
docker compose ps
echo '== PHP y SQLite =='
docker compose exec -T app php -r 'echo PHP_VERSION,"\n"; echo "sqlite3:", extension_loaded("sqlite3") ? "ok" : "missing", "\n";'
echo '== Migraciones =='
docker compose exec -T app php artisan migrate:status --no-ansi
echo '== Archivos compilados =='
docker compose exec -T app test -f public/build/manifest.json
echo '== WeasyPrint (worker) =='
docker compose exec -T worker weasyprint --version
echo '== Generación real de PDF (worker) =='
docker compose exec -T worker php <<'PHP'
<?php
require 'vendor/autoload.php';
$pdf = tempnam(sys_get_temp_dir(), 'nodo-pdf-check-');
if ($pdf === false) { throw new RuntimeException('No se pudo crear el PDF temporal'); }
try {
    $process = new Symfony\Component\Process\Process(['weasyprint', '-', $pdf]);
    $process->setInput('<!doctype html><meta charset="utf-8"><h1>OSG Nodo Control</h1><p>Verificación PDF: áéíóú ñ</p>');
    $process->setTimeout(35);
    $process->mustRun();
    if (file_get_contents($pdf, false, null, 0, 5) !== '%PDF-' || filesize($pdf) < 500) {
        throw new RuntimeException('WeasyPrint no produjo un PDF válido');
    }
    echo "PDF generado correctamente.\n";
} finally {
    unlink($pdf);
}
PHP
echo '== Salud HTTP =='
BIND_IP=$(sed -n 's/^OSG_BIND_IP=//p' .env | head -1)
PORT=$(sed -n 's/^OSG_PORT=//p' .env | head -1)
if [[ "$BIND_IP" == 0.0.0.0 ]]; then BIND_IP=127.0.0.1; fi
curl --fail --silent --show-error "http://$BIND_IP:$PORT/up" >/dev/null
curl --fail --silent --show-error "http://$BIND_IP:$PORT/login" >/dev/null
printf '%s\n' 'Verificaciones HTTP /up y /login correctas.'
