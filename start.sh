#!/usr/bin/env bash
set -e

# En producción los env vienen de Render. Si falta APP_KEY, lo generamos una vez.
if [ -z "${APP_KEY}" ]; then
  php artisan key:generate --force
fi

# Cache de config/routes/views (si querés comentar los cache en primeros deploys, podés)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Migraciones (forzadas)
php artisan migrate --force || true
php artisan db:seed --force || true

# Asegurar permisos (por las dudas)
chown -R www-data:www-data storage bootstrap/cache

# Arrancar Apache en primer plano
apache2-foreground
