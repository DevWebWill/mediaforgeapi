#!/bin/sh
set -e

# Solo el contenedor principal (CONTAINER_ROLE=app) prepara la aplicación;
# los workers/scheduler esperan a que esté sano (depends_on: service_healthy).
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    if [ ! -f vendor/autoload.php ] && command -v composer >/dev/null 2>&1; then
        composer install --no-interaction --prefer-dist
    fi

    if [ -f .env ] && grep -qE '^APP_KEY=\s*$' .env; then
        php artisan key:generate --force
    fi

    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        php artisan migrate --force
    fi

    if [ "${APP_ENV}" = "production" ]; then
        php artisan optimize
    fi
fi

exec "$@"
