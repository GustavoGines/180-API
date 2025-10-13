# ---- Build (Composer) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

# ---- Runtime (PHP + Apache) ----
FROM php:8.3-apache

# Extensiones y utilidades necesarias
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql intl zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Configurar Apache para que escuche el puerto que Render define en $PORT
# y para servir Laravel desde /public
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf \
 && sed -ri -e 's!/var/www/html! /var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copiamos el código
COPY . /var/www/html

# Copiamos vendor desde la etapa de build
COPY --from=vendor /app/vendor /var/www/html/vendor

# Permisos para Laravel
RUN chown -R www-data:www-data storage bootstrap/cache

# Script de arranque: genera APP_KEY si falta, migra y levanta Apache
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Render expone el puerto en $PORT; Apache ya fue configurado para usarlo
CMD ["bash", "/usr/local/bin/start.sh"]
