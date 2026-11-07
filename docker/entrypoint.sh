#!/bin/sh
set -e

# Only the web container runs migrations; the queue and scheduler containers
# set CONTAINER_ROLE and start straight away.
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force

    # First boot of a demo stack: seed demo data if there are no users yet.
    if [ "${DEMO_ENABLED:-false}" = "true" ] && [ "$(php artisan tinker --execute='echo \App\Models\User::count();')" = "0" ]; then
        php artisan db:seed --class='Database\Seeders\DemoSeeder' --force
    fi

    php artisan optimize
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
