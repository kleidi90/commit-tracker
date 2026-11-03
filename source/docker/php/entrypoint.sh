#!/bin/sh
set -e

# Only the php service prepares the application. The worker and web services wait
# for its healthcheck, which passes once var/.ready exists.
if [ "$1" = "php-fpm" ]; then
    rm -f var/.ready

    if [ ! -f vendor/autoload_runtime.php ]; then
        echo "Installing PHP dependencies..."
        composer install --prefer-dist --no-progress --no-interaction
    fi

    echo "Waiting for the database..."
    until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
        sleep 1
    done

    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console messenger:setup-transports --no-interaction

    # A separate database for the test suite, so tests never touch your data.
    php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
    php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

    # PHP-FPM runs as www-data, console commands as root: both need to write var/.
    # Never fatal: some host file systems (for example Windows drives) ignore permissions.
    mkdir -p var
    setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null \
        && setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null \
        || chmod -R a+rwX var 2>/dev/null \
        || true

    touch var/.ready
    echo "Backend ready."
fi

exec docker-php-entrypoint "$@"
