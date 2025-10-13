#!/usr/bin/env bash
set -e

# ---------- Apache: escuchar el puerto que Render expone ----------
if [ -n "${PORT:-}" ]; then
  echo "Listen ${PORT}" > /etc/apache2/ports.conf
else
  echo "WARN: \$PORT no está seteado; Apache quedará en 80"
fi

# ---------- Laravel runtime prep ----------
cd /var/www/html

# Asegurar carpetas y permisos ANTES de Artisan
mkdir -p storage/app storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwX storage bootstrap/cache || true

# Si usás SQLite en dev, crear el archivo si falta
if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
  php -r "if(!file_exists('database/database.sqlite')){ @mkdir('database', 0775, true); touch('database/database.sqlite'); }"
fi

# Generar APP_KEY si no existe (útil en entornos efímeros)
if [ -z "${APP_KEY:-}" ]; then
  echo "No APP_KEY found; generating one..."
  php artisan key:generate --force || echo "WARN: key:generate falló (continuo)"
fi

# Descubrir paquetes y caches (tolerantes para no tumbar el contenedor)
php artisan package:discover --ansi || echo "WARN: package:discover falló (continuo)"
php artisan config:cache --no-ansi   || echo "WARN: config:cache falló (continuo)"

# route:cache falla si hay closures; intentarlo pero no romper
php artisan route:cache --no-ansi    || echo "WARN: route:cache falló (continuo)"

# (Opcional) migraciones automáticas en prod con retry simple (DB puede tardar en estar lista)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  tries=5
  until php artisan migrate --force || [ $tries -le 1 ]; do
    echo "WARN: migrate falló, reintento en 5s..."
    tries=$((tries-1))
    sleep 5
  done || echo "WARN: migrate falló definitivamente (continuo)"
fi

# ---------- Start Apache ----------
exec apache2-foreground
# ---------- FIN ----------