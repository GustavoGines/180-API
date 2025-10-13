# -------- Etapa 1: vendor con Composer (sin dev) --------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# -------- Etapa 2: runtime PHP + Apache --------
FROM php:8.3-apache

# Extensiones necesarias para Laravel + Postgres
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libicu-dev \
 && docker-php-ext-install pdo pdo_pgsql intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Apache -> servir /public y escuchar el puerto que Render inyecta en $PORT
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

WORKDIR /var/www/html

# Copiá el código de la app
COPY . .

# Copiá vendor desde la etapa de composer
COPY --from=vendor /app/vendor /var/www/html/vendor

# Permisos requeridos por Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Script de arranque
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Render expone $PORT, Apache ya fue configurado para usarlo
CMD ["bash", "/usr/local/bin/start.sh"]
