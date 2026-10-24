# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5

###############################################################################
# base: PHP-FPM + extensiones + FFmpeg (común a dev y prod)
###############################################################################
FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache ffmpeg fcgi \
    && install-php-extensions bcmath exif intl opcache pcntl pdo_mysql redis zip

ENV PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILESIZE=2048M \
    PHP_POST_MAX_SIZE=2048M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    PHP_FPM_PM=dynamic \
    PHP_FPM_PM_MAX_CHILDREN=20 \
    PHP_FPM_PM_START_SERVERS=4 \
    PHP_FPM_PM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_PM_MAX_SPARE_SERVERS=8 \
    PHP_FPM_PM_MAX_REQUESTS=500

COPY docker/php/conf.d/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=755 docker/php/init-permissions.sh /usr/local/bin/init-permissions

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

###############################################################################
# dev: código montado como volumen, Composer incluido
###############################################################################
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && apk add --no-cache --virtual .user-deps shadow \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data \
    && apk del .user-deps \
    && chown -R www-data:www-data /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

USER www-data

###############################################################################
# vendor: dependencias de producción
###############################################################################
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --no-scripts --optimize --classmap-authoritative

###############################################################################
# prod: imagen inmutable lista para desplegar
###############################################################################
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --chown=www-data:www-data --from=vendor /app /var/www/html

USER www-data

RUN php artisan package:discover --ansi
