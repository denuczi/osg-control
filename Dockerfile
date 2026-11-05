# syntax=docker/dockerfile:1.7
# Production Linux images, for Docker Engine on Debian/Linux and on Windows via WSL2.
FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json* ./
COPY docker/frontend/ensure-rolldown.mjs /usr/local/lib/ensure-rolldown.mjs
RUN if [ -f package-lock.json ]; then npm ci --include=optional --no-audit --no-fund; else npm install --include=optional --no-audit --no-fund; fi \
 && node /usr/local/lib/ensure-rolldown.mjs
COPY tsconfig.json vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
RUN NODE_OPTIONS="--max-old-space-size=1536" npm run build

FROM php:8.4-fpm-bookworm AS phpbase
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libcurl4-openssl-dev libxml2-dev libsqlite3-dev \
    weasyprint fonts-dejavu-core ca-certificates gosu unzip git \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j1 bcmath exif gd intl pcntl zip \
 && php -r 'foreach (["bcmath", "exif", "gd", "intl", "mbstring", "Zend OPcache", "pcntl", "pdo_sqlite", "sqlite3", "zip"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Falta la extensión PHP: $ext\n"); exit(1); } }' \
 && rm -rf /var/lib/apt/lists/*
COPY docker/php/zz-nodo.ini /usr/local/etc/php/conf.d/zz-nodo.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-nodo.conf
WORKDIR /var/www/html

FROM phpbase AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader --no-progress

FROM phpbase AS app
COPY . ./
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=dependencies /var/www/html/composer.lock ./composer.lock
COPY --from=frontend /build/public/build ./public/build
RUN mkdir -p storage/app/private/reports/fleet storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    /data/sqlite \
 && chmod -R u+rwX,go-rwx storage \
 && chown -R www-data:www-data storage bootstrap/cache /data/sqlite \
 && php artisan package:discover --no-ansi \
 && chown -R www-data:www-data bootstrap/cache \
 && test -f public/build/manifest.json \
 && test -f vendor/autoload.php
USER www-data
CMD ["php-fpm", "-F"]

FROM nginxinc/nginx-unprivileged:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public/ /var/www/html/public/
USER 101:101
