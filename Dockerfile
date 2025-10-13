# -------- Etapa 1: vendor con Composer (sin dev, sin scripts) --------
FROM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# ✅ Optimizado y sin scripts (no hay artisan aquí)
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

# -------- Etapa 2: runtime PHP + Apache --------
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-enable opcache || true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Código (aquí ya está artisan)
COPY . .

# Vendor y composer desde la etapa anterior
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Crear las carpetas que Laravel necesita (por si no vinieron en el repo)
RUN mkdir -p \
    storage/app \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

# ❌ Quitar este paso (era el que disparaba artisan en build)
# RUN composer dump-autoload --optimize --no-interaction --no-ansi
# (Si insistís en dejarlo, ponelo así:)
# RUN composer dump-autoload --optimize --no-interaction --no-ansi --no-scripts

# Script de arranque
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["bash", "/usr/local/bin/start.sh"]
