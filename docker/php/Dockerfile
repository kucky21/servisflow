# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base image: PHP-FPM 8.4 + rozšíření potřebná pro ServisHub
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql intl gd zip bcmath opcache \
    && apk add --no-cache postgresql-client tzdata fcgi \
    && cp /usr/share/zoneinfo/Europe/Prague /etc/localtime \
    && echo "Europe/Prague" > /etc/timezone

# Healthcheck endpoint FPM (ping.path v pool konfiguraci)
COPY docker/php/zz-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-servishub.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-servishub.ini
COPY docker/php/entrypoint.sh /usr/local/bin/servishub-entrypoint
RUN chmod +x /usr/local/bin/servishub-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["servishub-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Dev: zdrojové kódy jsou připojeny jako volume, vendor se instaluje při startu
# ---------------------------------------------------------------------------
FROM base AS dev
ENV APP_ENV=dev

# ---------------------------------------------------------------------------
# Prod: kód a závislosti jsou součástí image
# ---------------------------------------------------------------------------
FROM base AS prod
ENV APP_ENV=production
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-servishub-prod.ini
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer run-script post-install-cmd \
    && mkdir -p temp log && chown -R www-data:www-data temp log
