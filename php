#!/usr/bin/env bash
# Local Mac / project PHP wrapper.
# Hostinger path /opt/alt/php83/usr/bin/php does NOT exist on your Mac.
# Usage from project root:
#   ./php -v
#   ./php artisan sales-list:import-all
#   ./php artisan route:list --path=employee-imports

set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "$ROOT/scripts/lib/resolve-php.sh"

if ! resolve_project_php; then
  echo "PHP not found. Run: bash scripts/setup-local-dev.sh" >&2
  exit 127
fi

exec "$PHP_BIN" "$@"
