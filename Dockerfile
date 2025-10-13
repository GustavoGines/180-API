# -------- Etapa 1: vendor con Composer (sin dev, sin scripts) --------
FROM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Sin scripts porque en esta etapa aún no existe 'artisan'
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# -------- Etapa 2: runtime PHP + Apache --------
FROM php:8.3-apache

# Paquetes y extensiones necesarias para Laravel + Postgres
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Opcional: habilitar opcache para mejor perf (sano por defecto)
RUN docker-php-ext-enable opcache || true

# DocumentRoot a /public (sin tocar $PORT acá)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copiá el código (aquí ya está artisan)
COPY . .

# Copiá vendor desde la etapa composer
COPY --from=vendor /app/vendor /var/www/html/vendor

# Copiá el binario de Composer al runtime
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Permisos requeridos por Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# (Opcional) Solo optimizar autoload en build
RUN composer dump-autoload --optimize --no-interaction --no-ansi

# Script de arranque
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Entrypoint (Render provee $PORT en runtime)
CMD ["bash", "/usr/local/bin/start.sh"]
