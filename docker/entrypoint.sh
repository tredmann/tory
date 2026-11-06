#!/bin/sh
set -e

cd /app

if [ "${APP_ENV:-local}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY is not set; refusing to start" >&2
    exit 1
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    # A bind mount on storage/ starts empty and hides the directories from the image.
    mkdir -p storage/app/private storage/app/public storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views
fi

if [ "${APP_ENV:-local}" != "production" ]; then
    if [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
        echo "[entrypoint] vendor/ missing, running composer install"
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ] && [ -f .env.example ]; then
        echo "[entrypoint] creating .env from .env.example"
        cp .env.example .env
    fi
fi

if [ -f artisan ]; then
    if [ -f .env ] && grep -qE '^APP_KEY=$' .env; then
        echo "[entrypoint] generating APP_KEY"
        php artisan key:generate --force --no-interaction
    fi

    if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
        echo "[entrypoint] running migrations"
        php artisan migrate --force --no-interaction
    fi

    if [ "${APP_ENV:-local}" = "production" ]; then
        php artisan optimize --no-interaction
    else
        php artisan optimize:clear --no-interaction
    fi
fi

exec supervisord -n -c /etc/supervisor/supervisord.conf
