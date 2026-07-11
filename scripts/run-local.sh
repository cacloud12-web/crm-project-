#!/usr/bin/env bash
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=/dev/null
source "$PROJECT_ROOT/scripts/lib/resolve-php.sh"

if ! resolve_project_php; then
  echo "PHP not found. Run setup first:"
  echo "  bash scripts/setup-local-dev.sh"
  exit 1
fi

cd "$PROJECT_ROOT"
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
"$PHP_BIN" artisan view:clear >/dev/null 2>&1 || true

PORT=8000
if lsof -nP -iTCP:8000 -sTCP:LISTEN >/dev/null 2>&1; then
  PORT=8001
  echo "Port 8000 is busy — using http://127.0.0.1:8001"
else
  echo "Starting server at http://127.0.0.1:8000"
fi
echo "Press Ctrl+C to stop."
"$PHP_BIN" artisan serve --host=127.0.0.1 --port="$PORT"
