#!/usr/bin/env bash
# Resolve PHP for this project (system PHP or bundled portable PHP).
resolve_project_php() {
  local root
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  PROJECT_ROOT="$root"
  TOOLS_DIR="$root/.tools"
  PHP_BULK="$TOOLS_DIR/php-bulk/php"
  COMPOSER_PHAR="$TOOLS_DIR/composer.phar"

  if command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
  elif [[ -x "$PHP_BULK" ]]; then
    PHP_BIN="$PHP_BULK"
  else
    PHP_BIN=""
  fi

  if [[ -n "$PHP_BIN" && -f "$PHP_BIN" ]]; then
    export PHP_BIN
    export PROJECT_ROOT
    export TOOLS_DIR
    return 0
  fi
  return 1
}

php_cmd() {
  if ! resolve_project_php; then
    echo "PHP not found. Run: bash scripts/setup-local-dev.sh" >&2
    return 127
  fi
  "$PHP_BIN" "$@"
}

composer_cmd() {
  if ! resolve_project_php; then
    echo "PHP not found. Run: bash scripts/setup-local-dev.sh" >&2
    return 127
  fi
  if [[ -f "$TOOLS_DIR/composer.phar" ]]; then
    "$PHP_BIN" "$TOOLS_DIR/composer.phar" "$@"
  elif command -v composer >/dev/null 2>&1; then
    composer "$@"
  else
    echo "Composer not found. Run: bash scripts/setup-local-dev.sh" >&2
    return 127
  fi
}
