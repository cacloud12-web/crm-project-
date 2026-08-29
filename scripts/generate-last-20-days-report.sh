#!/bin/bash
# Generate downloadable last-N-days CRM Excel report on production.
# Usage:
#   bash scripts/generate-last-20-days-report.sh
#   bash scripts/generate-last-20-days-report.sh 20
#   bash scripts/generate-last-20-days-report.sh 20 soniya,simran,dev

set -euo pipefail
cd "$(dirname "$0")/.."

DAYS="${1:-20}"
NAMES="${2:-all}"
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

echo "=== CRM report export (${DAYS} days) ==="
"$PHP_BIN" scripts/employee-activity-audit-export.php "$NAMES" "$DAYS"

if [[ -f scripts/demo-full-report-export.php ]]; then
  "$PHP_BIN" scripts/demo-full-report-export.php "$DAYS"
else
  echo "Note: demo-full-report-export.php not found — skipping demo-only workbook."
fi

if command -v python3 >/dev/null 2>&1; then
  if ! python3 -c "import openpyxl" 2>/dev/null; then
    echo "Installing openpyxl for Excel export..."
    pip3 install --user openpyxl
  fi
  python3 scripts/employee-activity-audit-to-excel.py
  if [[ -f scripts/demo-full-report-to-excel.py ]] && [[ -f storage/app/audits/demo-full-report.json ]]; then
    python3 scripts/demo-full-report-to-excel.py
  fi
else
  echo "python3 not found — install openpyxl: pip3 install openpyxl"
  echo "JSON exported; run python scripts on a machine with openpyxl to get Excel."
  exit 1
fi

echo ""
echo "Download these files:"
ls -lh storage/app/audits/CRM_Full_Report_Last_"${DAYS}"_Days_*.xlsx 2>/dev/null || true
ls -lh storage/app/audits/Demo_Full_Employee_Report.xlsx 2>/dev/null || true
echo ""
echo "Hostinger path: ~/domains/crm.caclouddesk.com/public_html/storage/app/audits/"
