#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env
# Gate on the autoloader, not the directory: a composer run interrupted mid-install leaves a
# partial vendor/ that a directory check would skip forever (S10-01 audit r2). Retry once.
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist || composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  until php artisan migrate --force; do
    echo "Waiting for database..."
    sleep 2
  done
fi

exec "$@"
