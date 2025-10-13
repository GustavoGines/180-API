# -------- Etapa 1: vendor con Composer (sin dev) --------
FROM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# 👇 Desactiva scripts acá (no está artisan en esta etapa)
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

# -------- Etapa 2: runtime PHP + Apache --------
FROM php:8.3-apache

# Extensiones necesarias para Laravel + Postgres
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Apache -> servir /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copiá el código de la app (aquí sí está artisan)
COPY . .

# Copiá vendor desde la etapa de composer
COPY --from=vendor /app/vendor /var/www/html/vendor

# Traer composer al runtime desde la imagen composer:2 (tu stage vendor)
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

# Permisos requeridos por Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# 👇 Ahora sí: autoload optimizado + descubrimiento de paquetes/caches
RUN composer dump-autoload --optimize --no-interaction --no-ansi \
 && php artisan package:discover --ansi \
 && php artisan config:cache --no-ansi \
 && php artisan route:cache --no-ansi

# Script de arranque
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Importante en Render: no intentes sustituir el puerto en build.
# Dejá que start.sh escriba el Listen con $PORT en runtime.
CMD ["bash", "/usr/local/bin/start.sh"]
