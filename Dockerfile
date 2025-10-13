# ============ Etapa 1: Build de assets con Node (Vite) ============
FROM node:20 AS build-assets
WORKDIR /app

COPY package*.json vite.config.js ./
RUN npm ci

COPY resources ./resources
COPY public ./public

ENV NODE_ENV=production
RUN npm run build
RUN echo ">> Archivos en /app/public/build:" && ls -la /app/public/build && \
    test -f /app/public/build/manifest.json || (echo "❌ Falta manifest.json. Revisá Vite/Laravel." && exit 1)


# ============ Etapa 2: Vendor PHP (Composer) ============
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./

# 👇 Clave: ignorar platform-reqs porque esta imagen no tiene extensiones PHP
RUN composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts \
    --ignore-platform-reqs


# ============ Etapa 3: PHP + Apache (runtime) ============
FROM php:8.2-apache

# Paquetes del sistema necesarios para extensiones
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip libzip-dev libonig-dev libpq-dev libicu-dev \
  && docker-php-ext-configure zip \
  && docker-php-ext-install -j$(nproc) \
      pdo_mysql pdo_pgsql zip intl mbstring bcmath \
  && docker-php-ext-enable opcache \
  && rm -rf /var/lib/apt/lists/*

# Apache
RUN a2enmod rewrite
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/../!g' /etc/apache2/apache2.conf \
 && printf "\n<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n" >> /etc/apache2/apache2.conf

# Opcache recomendado
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.jit=1255'; \
      echo 'opcache.jit_buffer_size=128M'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Código de la app
WORKDIR /var/www/html
COPY . .

# Vendor desde etapa composer
COPY --from=vendor /app/vendor /var/www/html/vendor

# Assets compilados
COPY --from=build-assets /app/public/build /var/www/html/public/build
RUN echo ">> Archivos en /var/www/html/public/build:" && ls -la /var/www/html/public/build

# Permisos
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Entrypoint
COPY start-server.sh /usr/local/bin/start-server.sh
RUN chmod +x /usr/local/bin/start-server.sh

EXPOSE 80
CMD ["start.sh"]
