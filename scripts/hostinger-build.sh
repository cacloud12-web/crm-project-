#!/bin/bash
# Hostinger Git deploy build script — run from project root on the server.
set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found in PATH"
  exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  if [ -f .env.hostinger.example ]; then
    cp .env.hostinger.example .env
    php artisan key:generate --force
    echo "Created .env from .env.hostinger.example — update APP_URL and DB_* in hPanel File Manager."
  else
    echo "Missing .env — copy .env.hostinger.example to .env and set APP_URL + database credentials."
    exit 1
  fi
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "Hostinger build finished."
