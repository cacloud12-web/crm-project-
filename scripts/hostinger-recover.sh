#!/bin/bash
# One-shot recovery when live site shows 500 after a failed Hostinger deploy.
# Run from project root: bash scripts/hostinger-recover.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

echo "Recovering CRM on Hostinger..."
bash "$SCRIPT_DIR/hostinger-build.sh"
echo "Done. Reload https://crm.caclouddesk.com"
