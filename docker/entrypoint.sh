#!/bin/sh
# Start of the container: waits for MariaDB (its first start creates the database), brings the schema up to
# date (Doctrine migrations), warms the cache.
set -e
cd /app
tries=0
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then echo "database unreachable" >&2; exit 1; fi
    sleep 2
done
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:warmup
exec "$@"
