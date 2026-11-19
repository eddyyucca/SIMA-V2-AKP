# syntax=docker/dockerfile:1
#
# Dockerfile MANDIRI: konteks build = folder repositori ini saja (paket akp/portal-sso ada di packages/).
#
#   docker build -t ghcr.io/eddyyucca/sima-v2-akp:latest .
#
# Satu image, beberapa peran lewat perintah (CMD): web (bawaan), "php artisan queue:work", "php artisan schedule:work".

# ---- tahap 1: aset frontend (Vite + Tailwind). Butuh internet (font Bunny diunduh saat build). ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc* ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- tahap 2: aplikasi ----
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libonig-dev libgmp-dev libpng-dev libjpeg-dev libfreetype6-dev unzip curl git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql pgsql intl zip bcmath gmp gd opcache pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && { echo 'ServerName localhost'; echo 'ServerTokens Prod'; echo 'ServerSignature Off'; } > /etc/apache2/conf-available/akp.conf \
    && a2enconf akp

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-akp.ini

WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MIRROR_PATH_REPOS=1

COPY . /var/www/html/
COPY --from=assets /app/public/build /var/www/html/public/build

# Unduhan paket kadang terputus: ulangi sampai 4 kali; paket yang sudah terunduh dipakai ulang.
RUN ok=0; for i in 1 2 3 4; do composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts && ok=1 && break; echo "composer install gagal (percobaan $i dari 4), mengulang..." >&2; sleep 5; done; [ "$ok" = 1 ] \
    && php artisan package:discover --ansi \
    && rm -rf /root/.composer /root/.cache \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/private storage/app/public \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/[[:cntrl:]]$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
