#!/usr/bin/env bash
# Use SQLite when MySQL is not available on this Mac (local preview only).
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"
SQLITE_FILE="$PROJECT_ROOT/database/database.sqlite"

mysql_reachable() {
  if command -v nc >/dev/null 2>&1; then
    nc -z 127.0.0.1 3306 2>/dev/null
    return $?
  fi
  return 1
}

if [[ -f .env ]] && grep -q '^DB_CONNECTION=mysql' .env && ! mysql_reachable; then
  if [[ ! -f .env.mysql.bak ]]; then
    echo "Backing up MySQL .env settings to .env.mysql.bak"
    grep -E '^DB_(CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)=' .env > .env.mysql.bak || true
  fi

  echo "MySQL is not running locally — switching .env to SQLite for local dev."
  python3 - <<'PY'
from pathlib import Path
import re

root = Path(".")
env = root / ".env"
text = env.read_text()
sqlite = root / "database" / "database.sqlite"

replacements = {
    "APP_DEBUG": "true",
    "APP_URL": "http://127.0.0.1:8000",
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": f'"{sqlite.resolve()}"',
}
for key, value in replacements.items():
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.M)
    if pattern.search(text):
        text = pattern.sub(f"{key}={value}", text, count=1)
    else:
        text += f"\n{key}={value}"

for key in ("DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD"):
    text = re.sub(rf"^{key}=.*\n?", "", text, flags=re.M)

env.write_text(text)
PY

  mkdir -p database
  touch "$SQLITE_FILE"
fi
