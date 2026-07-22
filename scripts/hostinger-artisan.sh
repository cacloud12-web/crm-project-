#!/usr/bin/env bash
# Run Laravel artisan with PHP 8.3+ on Hostinger / multi-PHP hosts.
# Usage (from public_html):
#   bash scripts/hostinger-artisan.sh optimize:clear
#   bash scripts/hostinger-artisan.sh route:list --path=employee-imports

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

pick_php() {
  local candidates=(
    "${PHP_BIN:-}"
    /opt/alt/php83/usr/bin/php
    /opt/alt/php84/usr/bin/php
    /opt/alt/php85/usr/bin/php
    /usr/bin/php83
    /usr/local/bin/php83
    "$(command -v php83 2>/dev/null || true)"
    "$(command -v php8.3 2>/dev/null || true)"
    "$(command -v ea-php83 2>/dev/null || true)"
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
  echo "ERROR: PHP 8.3+ CLI not found."
  echo "Default 'php' is $(php -v 2>/dev/null | head -1 || echo unknown)"
  echo
  echo "Fix in Hostinger hPanel:"
  echo "  Advanced → PHP Configuration → select PHP 8.3 (or 8.4) for crm.caclouddesk.com"
  echo "Then re-open SSH and run:"
  echo "  ls /opt/alt/php*/usr/bin/php"
  echo "  /opt/alt/php83/usr/bin/php -v"
  exit 1
fi

echo "Using: $PHP_CLI ($("$PHP_CLI" -r 'echo PHP_VERSION;'))"
exec "$PHP_CLI" "$ROOT/artisan" "$@"
