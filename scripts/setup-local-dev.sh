#!/usr/bin/env bash
# Bootstrap local PHP + Composer inside the project (no Homebrew required).
# Run: bash scripts/setup-local-dev.sh
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"
TOOLS="$PROJECT_ROOT/.tools"
ARCH="$(uname -m)"
PHP_BIN_PATH="$TOOLS/php-bulk/php"
BULK_URL="https://dl.static-php.dev/static-php-cli/bulk/php-8.3.32-cli-macos-aarch64.tar.gz"

echo ""
echo "=========================================="
echo " CA Cloud Desk CRM — Local Dev Setup"
echo "=========================================="
echo ""

if [[ "$ARCH" != "arm64" ]]; then
  echo "Portable PHP auto-install supports Apple Silicon (arm64) only."
  echo "On Intel Mac, install Homebrew + PHP 8.3:"
  echo "  /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\""
  echo "  brew install php@8.3 composer"
  exit 1
fi

mkdir -p "$TOOLS"

if [[ ! -x "$PHP_BIN_PATH" ]]; then
  echo "Downloading portable PHP 8.3 (~34 MB, one-time)..."
  curl -fsSL -o "$TOOLS/php-bulk.tar.gz" "$BULK_URL"
  rm -rf "$TOOLS/php-bulk" "$TOOLS/php-pmmp" "$TOOLS/php-static"
  mkdir -p "$TOOLS/php-bulk"
  tar -xzf "$TOOLS/php-bulk.tar.gz" -C "$TOOLS/php-bulk"
  rm -f "$TOOLS/php-bulk.tar.gz"
  echo "PHP ready at .tools/php-bulk/php"
else
  echo "Portable PHP already installed."
fi

if [[ ! -f "$TOOLS/composer.phar" ]]; then
  echo "Downloading Composer..."
  curl -fsSL -o "$TOOLS/composer.phar" https://getcomposer.org/download/latest-stable/composer.phar
fi

# shellcheck source=/dev/null
source "$PROJECT_ROOT/scripts/lib/resolve-php.sh"
resolve_project_php

echo ""
"$PHP_BIN" -v | head -1
"$PHP_BIN" "$TOOLS/composer.phar" --version 2>/dev/null | head -1 || true

if [[ ! -d vendor ]]; then
  echo ""
  echo "Installing PHP dependencies..."
  "$PHP_BIN" "$TOOLS/composer.phar" install --no-interaction --prefer-dist
else
  echo ""
  echo "vendor/ already exists — skipping composer install."
fi

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "Generating APP_KEY..."
  "$PHP_BIN" artisan key:generate --force
fi

bash "$PROJECT_ROOT/scripts/configure-local-database.sh"

if [[ -f database/database.sqlite ]] && [[ "$(grep '^DB_CONNECTION=' .env || true)" == "DB_CONNECTION=sqlite" ]]; then
  PENDING=$("$PHP_BIN" artisan migrate:status --no-ansi 2>/dev/null | grep -c Pending || true)
  if [[ "${PENDING:-0}" -gt 0 ]]; then
    echo ""
    echo "Creating local SQLite database..."
    "$PHP_BIN" artisan migrate --force --no-ansi
  fi
  USER_COUNT=$("$PHP_BIN" artisan tinker --execute="echo \\App\\Models\\User::count();" 2>/dev/null | tail -1 || echo 0)
  if [[ "${USER_COUNT:-0}" == "0" ]]; then
    echo "Seeding local users and master data..."
    "$PHP_BIN" artisan db:seed --class=IndiaStatesCitiesSeeder --force --no-ansi || true
    "$PHP_BIN" artisan db:seed --class=CrmMasterDataSeeder --force --no-ansi || true
    "$PHP_BIN" artisan db:seed --class=CrmUserSeeder --force --no-ansi || true
    "$PHP_BIN" artisan db:seed --class=RbacPermissionSeeder --force --no-ansi || true
    echo ""
    echo "Local login: superadmin@ca.local / password"
  else
    echo ""
    echo "SQLite database ready (${USER_COUNT} users)."
  fi
fi

echo ""
echo "Clearing Laravel caches..."
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan cache:clear

echo ""
echo "=========================================="
echo " Setup complete!"
echo "=========================================="
echo ""
echo "Start the server:"
echo "  bash scripts/run-local.sh"
echo ""
echo "Run artisan commands:"
echo "  bash scripts/artisan view:clear"
echo "  bash scripts/artisan migrate"
echo ""
