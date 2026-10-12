#!/bin/sh
set -e

cd /var/www/html

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    # First boot: install dependencies, create .env and an application key.
    if [ ! -f vendor/autoload.php ]; then
        echo "Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if ! grep -q '^APP_KEY=base64:' .env; then
        php artisan key:generate --force
    fi

    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
        tries=0
        until php artisan migrate --force; do
            tries=$((tries + 1))
            if [ "$tries" -ge 20 ]; then
                echo "Database still unavailable; giving up on migrations." >&2
                break
            fi
            echo "Waiting for the database..."
            sleep 3
        done
    fi

    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
else
    # queue / scheduler containers wait for the app container to finish setting up.
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64:' .env 2>/dev/null; do
        echo "Waiting for the app container to finish setup..."
        sleep 3
    done
fi

exec "$@"
