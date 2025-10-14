#!/usr/bin/env bash
set -e

# ---------- Apache: escuchar el puerto que Render expone ----------
if [ -n "${PORT:-}" ]; then
  echo "Listen ${PORT}" > /etc/apache2/ports.conf
else
  echo "WARN: \$PORT no está seteado; Apache quedará en 80"
fi

# ---- VHost runtime: forzar /public sí o sí ----
cat >/etc/apache2/sites-available/laravel.conf <<'CONF'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
CONF

# Deshabilitar default y habilitar nuestro vhost
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite laravel >/dev/null 2>&1 || true

# (Opcional) FQDN warning off
echo "ServerName localhost" > /etc/apache2/conf-available/fqdn.conf
a2enconf fqdn >/dev/null 2>&1 || true

# ---------- Laravel runtime prep ----------
cd /var/www/html

# Estructura y permisos ANTES de Artisan
mkdir -p storage/app storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwX storage bootstrap/cache || true

# SQLite solo si corresponde
if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
  php -r "if(!file_exists('database/database.sqlite')){ @mkdir('database', 0775, true); touch('database/database.sqlite'); }"
fi

# APP_KEY sin .env: generarlo en memoria si no viene por env (no usar key:generate)
if [ -z "${APP_KEY:-}" ]; then
  echo "No APP_KEY env; generating ephemeral key for this runtime..."
  export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

# Caches tolerantes
php artisan package:discover --ansi || echo "WARN: package:discover falló (continuo)"
php artisan config:cache --no-ansi   || echo "WARN: config:cache falló (continuo)"
php artisan route:cache --no-ansi    || echo "WARN: route:cache falló (continuo)"

# Migraciones con retry simple (opcional)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  tries=5
  until php artisan migrate --force || [ $tries -le 1 ]; do
    echo "WARN: migrate falló, reintento en 5s..."
    tries=$((tries-1))
    sleep 5
  done || echo "WARN: migrate falló definitivamente (continuo)"
fi

# (Opcional) Diagnóstico útil: ver qué vhost quedó activo
apache2ctl -S || true
grep -R "DocumentRoot" /etc/apache2/sites-enabled/ -n || true

# ---------- Start Apache ----------
exec apache2-foreground
# ---------- FIN ----------
