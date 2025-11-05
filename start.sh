#!/usr/bin/env bash
set -e

# ---------- Apache: escuchar el puerto que Render expone ----------
if [ -n "${PORT:-}" ]; then
  echo "Listen ${PORT}" > /etc/apache2/ports.conf
else
  echo "WARN: \$PORT no está seteado; Apache quedará en 80"
fi

# ---- VHost runtime: forzar /public + puerto correcto ----
cat >/etc/apache2/sites-available/laravel.conf <<EOF
<VirtualHost *:${PORT}>
    ServerName localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

a2dissite 000-default >/dev/null 2>&1 || true
a2ensite laravel >/dev/null 2>&1 || true

echo "ServerName localhost" > /etc/apache2/conf-available/fqdn.conf
a2enconf fqdn >/dev/null 2>&1 || true

# ---------- Laravel runtime prep ----------
cd /var/www/html

mkdir -p storage/app storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwX storage bootstrap/cache || true

# ---------- Firebase credentials ----------
if [ -n "${FIREBASE_CREDENTIALS:-}" ]; then
  echo "Creando firebase_service_account.json desde FIREBASE_CREDENTIALS..."
  echo "$FIREBASE_CREDENTIALS" > /var/www/html/storage/app/firebase_service_account.json
  chmod 600 /var/www/html/storage/app/firebase_service_account.json
else
  echo "WARN: No FIREBASE_CREDENTIALS env var found. Firebase SDK no disponible."
fi

# APP_KEY (si no viene por env)
if [ -z "${APP_KEY:-}" ]; then
  echo "No APP_KEY env; generating ephemeral key..."
  export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

# Caches tolerantes
php artisan package:discover --ansi || echo "WARN: package:discover falló (continuo)"
php artisan config:cache --no-ansi   || echo "WARN: config:cache falló (continuo)"
php artisan route:cache --no-ansi    || echo "WARN: route:cache falló (continuo)"
php artisan view:cache --no-ansi     || echo "WARN: view:cache falló (continuo)"

# ---------- Migraciones / Seed controlados por ENV ----------
RUN_FRESH=${RUN_FRESH:-0}
RUN_SEED=${RUN_SEED:-0}
SEEDER_CLASS=${SEEDER_CLASS:-DatabaseSeeder}

if [ "$RUN_FRESH" = "1" ]; then
  echo ">> RUN_FRESH=1 → php artisan migrate:fresh --force"
  php artisan migrate:fresh --force || { echo "ERROR: migrate:fresh falló"; exit 1; }
  if [ "$RUN_SEED" = "1" ]; then
    echo ">> RUN_SEED=1 → php artisan db:seed --class=${SEEDER_CLASS} --force"
    php artisan db:seed --class="${SEEDER_CLASS}" --force || echo "WARN: seed falló (continuo)"
  fi
else
  echo ">> RUN_FRESH=0 → php artisan migrate --force"
  tries=5
  until php artisan migrate --force || [ $tries -le 1 ]; do
    echo "WARN: migrate falló, reintento en 5s..."
    tries=$((tries-1)); sleep 5
  done || echo "WARN: migrate falló definitivamente (continuo)"

  if [ "$RUN_SEED" = "1" ]; then
    echo ">> RUN_SEED=1 → php artisan db:seed --class=${SEEDER_CLASS} --force"
    php artisan db:seed --class="${SEEDER_CLASS}" --force || echo "WARN: seed falló (continuo)"
  fi
fi

# Diagnóstico útil
apache2ctl -S || true
grep -R "DocumentRoot" /etc/apache2/sites-enabled/ -n || true

# ---------- Start Apache & Scheduler ----------

echo "[Scheduler] Iniciando el bucle del planificador de Laravel (cada 60s)..."

# ✅ CAMBIO: Redirigimos toda la salida (stdout y stderr) a la consola de Render
(
  while true
  do
    # Añadimos logs para saber que el bucle corre
    echo "[Scheduler] >>> Ejecutando 'php artisan schedule:run'..."
    php artisan schedule:run
    echo "[Scheduler] <<< Esperando 60 segundos..."
    sleep 60 # ✅ CAMBIO: Correr cada 60s (Laravel se encarga de los 5 min)
  done
) &> /dev/stdout & # ✅ CAMBIO: Apuntar a stdout en lugar de /dev/null

# Iniciar Apache en primer plano (¡debe ser la última línea!)
echo "[Web] Iniciando el servidor web (Apache)..."
exec apache2-foreground