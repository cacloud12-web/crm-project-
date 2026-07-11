#!/bin/bash
# Hostinger Git deploy build script — run from project root on the server.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found in PATH"
  exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  if [ -f .env.hostinger.example ]; then
    cp .env.hostinger.example .env
    echo "Created .env from .env.hostinger.example"
  else
    echo "Missing .env — copy .env.hostinger.example to .env and set APP_URL + database credentials."
    exit 1
  fi
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

php artisan optimize:clear
php artisan migrate --force

# Ensure India state/city master data exists (required for lead forms)
STATE_COUNT="$(php -r "require 'vendor/autoload.php'; \$app=require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\State::query()->count();" 2>/dev/null || echo 0)"
if [ "${STATE_COUNT}" = "0" ] || [ -z "${STATE_COUNT}" ]; then
  echo "Seeding India states and cities..."
  php artisan db:seed --class=IndiaStatesCitiesSeeder --force
else
  echo "States already present (${STATE_COUNT}); skipping IndiaStatesCitiesSeeder."
fi

if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Hostinger build finished."
