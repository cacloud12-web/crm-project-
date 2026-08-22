#!/bin/bash
# Hostinger Git deploy build script — run from project root on the server.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

pick_php() {
  local candidates=(
    "${PHP_BIN:-}"
    /opt/alt/php83/usr/bin/php
    /opt/alt/php84/usr/bin/php
    /opt/alt/php85/usr/bin/php
    "$(command -v php83 2>/dev/null || true)"
    "$(command -v php 2>/dev/null || true)"
  )
  local bin
  for bin in "${candidates[@]}"; do
    if [[ -n "$bin" && -x "$bin" ]]; then
      if "$bin" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' 2>/dev/null; then
        echo "$bin"
        return 0
      fi
    fi
  done
  return 1
}

if ! PHP_CLI="$(pick_php)"; then
  echo "ERROR: PHP 8.3+ CLI not found. Use: ls /opt/alt/php*/usr/bin/php"
  exit 1
fi

echo "Using PHP: $PHP_CLI ($("$PHP_CLI" -r 'echo PHP_VERSION;'))"

pick_composer() {
  local candidates=(
    "${COMPOSER_BIN:-}"
    "$(command -v composer 2>/dev/null || true)"
    /usr/local/bin/composer
    "$HOME/.composer/vendor/bin/composer"
  )
  local bin
  for bin in "${candidates[@]}"; do
    if [[ -n "$bin" && -x "$bin" ]]; then
      echo "$bin"
      return 0
    fi
  done
  return 1
}

if ! COMPOSER_BIN="$(pick_composer)"; then
  echo "ERROR: composer not found in PATH"
  exit 1
fi

echo "Using Composer: $COMPOSER_BIN"

# Stale package discovery cache references dev providers (Pail, Collision) after --no-dev.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

"$PHP_CLI" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

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
  "$PHP_CLI" artisan key:generate --force
fi

"$PHP_CLI" artisan optimize:clear
"$PHP_CLI" artisan migrate --force

STATE_COUNT="$("$PHP_CLI" -r "require 'vendor/autoload.php'; \$app=require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\State::query()->count();" 2>/dev/null || echo 0)"
if [ "${STATE_COUNT}" = "0" ] || [ -z "${STATE_COUNT}" ]; then
  echo "Seeding India states and cities..."
  "$PHP_CLI" artisan db:seed --class=IndiaStatesCitiesSeeder --force
else
  echo "States already present (${STATE_COUNT}); skipping IndiaStatesCitiesSeeder."
fi

if [ ! -L public/storage ]; then
  "$PHP_CLI" artisan storage:link || true
fi

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

"$PHP_CLI" artisan config:cache
"$PHP_CLI" artisan route:cache
"$PHP_CLI" artisan view:cache

echo "Hostinger build finished."
