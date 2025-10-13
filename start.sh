#!/usr/bin/env bash
set -e

# ---------- Apache: escuchar el puerto que Render expone ----------
# Sobrescribimos ports.conf en runtime para usar $PORT
if [ -n "${PORT:-}" ]; then
  echo "Listen ${PORT}" > /etc/apache2/ports.conf
else
  echo "WARN: \$PORT no está seteado; Apache quedará en 80"
fi

# ---------- Laravel runtime prep ----------
cd /var/www/html

# Si usás SQLite en dev, creá el archivo si falta
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

# (Opcional) migraciones automáticas en prod
 php artisan migrate --force || echo "WARN: migrate falló (continuo)"

# Permisos por las dudas (cuando se montan volúmenes)
chown -R www-data:www-data storage bootstrap/cache || true

# ---------- Start Apache ----------
exec apache2-foreground
