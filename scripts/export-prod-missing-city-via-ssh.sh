#!/usr/bin/env bash
# READ-ONLY production export via SSH (SELECT → CSV). No app deploy. No DB writes.
set -euo pipefail

HOST=82.25.83.189
PORT=65002
USER=u636438798
REMOTE_ROOT=/home/u636438798/domains/crm.caclouddesk.com/public_html
LOCAL_DIR="/Users/CAcloudDesk/Downloads/crm_project 2/crm_project/crm-project/storage/app/audits"
SCRIPT_LOCAL="/Users/CAcloudDesk/Downloads/crm_project 2/crm_project/crm-project/scripts/export-prod-missing-city-onserver.php"

PASS="$(osascript -e 'display dialog "Hostinger SSH password — READ-ONLY export (SELECT only, no DB writes)." with title "Production export" default answer "" with hidden answer' -e 'text returned of result')"
if [[ -z "${PASS}" ]]; then
  echo "Aborted: empty password" >&2
  exit 1
fi

export SSHPASS="$PASS"

run_ssh() {
  expect <<EOF
set timeout 120
spawn ssh -p $PORT -o StrictHostKeyChecking=accept-new ${USER}@${HOST} {*}\$argv
expect {
  -re "(?i)password:" { send "\$env(SSHPASS)\r"; exp_continue }
  eof
}
catch wait result
exit [lindex \$result 3]
EOF
}

# simpler: use expect wrapper for each command
ssh_cmd() {
  local cmd="$1"
  expect <<EOF
set timeout 600
spawn ssh -p $PORT -o StrictHostKeyChecking=accept-new ${USER}@${HOST}
expect {
  -re "(?i)password:" { send "$PASS\r" }
  timeout { exit 1 }
}
expect -re {[$#] }
send "cd $REMOTE_ROOT && $cmd\r"
expect {
  -re {EXPORT_OK} {}
  -re {Fatal|SQLSTATE|Error Exception} { puts "FAILED"; exit 1 }
  timeout { puts "TIMEOUT"; exit 1 }
}
expect -re {[$#] }
send "exit\r"
expect eof
EOF
}

scp_to() {
  local src="$1" dest="$2"
  expect <<EOF
set timeout 120
spawn scp -P $PORT -o StrictHostKeyChecking=accept-new "$src" ${USER}@${HOST}:"$dest"
expect {
  -re "(?i)password:" { send "$PASS\r"; exp_continue }
  eof
}
EOF
}

scp_from() {
  local src="$1" dest="$2"
  expect <<EOF
set timeout 180
spawn scp -P $PORT -o StrictHostKeyChecking=accept-new ${USER}@${HOST}:"$src" "$dest"
expect {
  -re "(?i)password:" { send "$PASS\r"; exp_continue }
  eof
}
EOF
}

mkdir -p "$LOCAL_DIR"
echo "Uploading export script to /tmp (not app/)..."
scp_to "$SCRIPT_LOCAL" "/tmp/export-prod-missing-city-onserver.php"

echo "Running READ-ONLY export on server..."
ssh_cmd "/opt/alt/php83/usr/bin/php -d memory_limit=512M /tmp/export-prod-missing-city-onserver.php '$REMOTE_ROOT' && rm -f /tmp/export-prod-missing-city-onserver.php && echo EXPORT_OK"

echo "Downloading CSVs..."
scp_from "$REMOTE_ROOT/storage/app/audits/prod-ocr-linked-missing-masters.csv" "$LOCAL_DIR/"
scp_from "$REMOTE_ROOT/storage/app/audits/prod-cities.csv" "$LOCAL_DIR/"

wc -l "$LOCAL_DIR/prod-ocr-linked-missing-masters.csv" "$LOCAL_DIR/prod-cities.csv"
echo DONE
