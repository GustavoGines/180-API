#!/usr/bin/env bash
set -e

# Generar APP_KEY si falta (Render setea env; no se usa .env)
if [ -z "${APP_KEY}" ] || [ "${APP_KEY}" = "" ]; then
  php artisan key:generate --force || true
fi

# Caches (si es primer deploy y da error, no rompas)
php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true

# Migraciones/seeders (idempotentes)
php artisan migrate --force || true
php artisan db:seed --force || true

# Permisos por las dudas
chown -R www-data:www-data storage bootstrap/cache

# Levantar Apache en primer plano
apache2-foreground
