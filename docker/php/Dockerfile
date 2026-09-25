# syntax=docker/dockerfile:1.7
# Backend image (php-fpm) — used by the app, queue and scheduler services.
# Stages: base (extensions) → vendor (composer deps) → dev (docker-compose, code mounted) → production.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-alpine AS base
RUN apk add --no-cache icu-libs libpq libzip libpng libjpeg-turbo libwebp freetype fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl gd zip opcache bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
WORKDIR /var/www/backend

FROM composer:2 AS composer

FROM base AS vendor
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS dev
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Code is bind-mounted from ./backend by docker-compose.
CMD ["php-fpm"]

FROM base AS production
ENV APP_ENV=production PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/backend/vendor ./vendor
COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm /usr/bin/composer
USER www-data
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache && exec php-fpm"]
