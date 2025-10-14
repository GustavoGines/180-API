# -------- Etapa 1: vendor con Composer (sin dev, sin scripts) --------
FROM composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Optimizado y sin scripts (no hay artisan en esta etapa)
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

# -------- Etapa 2: runtime PHP + Apache --------
FROM php:8.3-apache

# Paquetes + extensiones necesarias
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql intl zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Opcional: habilitar opcache
RUN docker-php-ext-enable opcache || true

# DocumentRoot en /public y permitir .htaccess
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
# Ajustar vhost a /public
RUN sed -ri -e 's#DocumentRoot /var/www/html#DocumentRoot ${APACHE_DOCUMENT_ROOT}#' /etc/apache2/sites-available/000-default.conf
# Apuntar el bloque <Directory> al nuevo docroot y permitir Override
RUN sed -ri -e 's#<Directory /var/www/>#<Directory ${APACHE_DOCUMENT_ROOT}/>#' /etc/apache2/apache2.conf \
 && sed -ri -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
# Forzar DirectoryIndex index.php
RUN printf "\n<Directory ${APACHE_DOCUMENT_ROOT}>\n    DirectoryIndex index.php\n</Directory>\n" \
      > /etc/apache2/conf-available/laravel.conf \
 && a2enconf laravel
# (Opcional) evitar warning FQDN
RUN echo "ServerName localhost" > /etc/apache2/conf-available/fqdn.conf && a2enconf fqdn

WORKDIR /var/www/html

# Código (aquí sí está artisan)
COPY . .

# Vendor y composer desde la etapa anterior
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Crear carpetas que Laravel necesita (por si no vinieron en el repo) y permisos
RUN mkdir -p \
    storage/app \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

# Script de arranque
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["bash", "/usr/local/bin/start.sh"]
