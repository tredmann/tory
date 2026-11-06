# syntax=docker/dockerfile:1

############################
# base: FrankenPHP + PHP 8.5
############################
FROM dunglas/frankenphp:1-php8.5-trixie AS base

RUN install-php-extensions pdo_pgsql pgsql intl zip gd pcntl bcmath exif

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

# Plain HTTP on port 80 (no automatic TLS). Override SERVER_NAME for real domains.
ENV SERVER_NAME=:80

ENTRYPOINT ["entrypoint"]

############################
# dev: code is bind-mounted
############################
FROM base AS dev

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/supervisor/dev.conf /etc/supervisor/conf.d/app.conf

############################
# assets: build Vite bundle
############################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc* ./
RUN npm ci
COPY vite.config.js ./
COPY resources resources
COPY public public
RUN npm run build

############################
# prod: self-contained image
############################
FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY docker/supervisor/prod.conf /etc/supervisor/conf.d/app.conf

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction

COPY . .
COPY --from=assets /app/public/build public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction

# The server runs as www-data and only binds ports 80/443 through this capability.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

USER www-data
