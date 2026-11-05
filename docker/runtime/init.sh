#!/bin/sh
set -eu
cd /var/www/html
if [ ! -r .env ]; then echo 'ERROR: falta secrets/laravel.env. Ejecuta scripts/docker/setup.sh primero.' >&2; exit 2; fi
if ! grep -q '^APP_KEY=base64:' .env; then echo 'ERROR: APP_KEY no inicializada.' >&2; exit 2; fi
mkdir -p /data/sqlite storage/app/private/reports/fleet storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data /data/sqlite storage
if [ ! -f /data/sqlite/database.sqlite ]; then
    gosu www-data php -r '$d=new SQLite3("/data/sqlite/database.sqlite");$d->close();'
fi
gosu www-data php artisan migrate --force --no-interaction
printf '%s\n' 'OSG Nodo Control: migraciones completadas.'
