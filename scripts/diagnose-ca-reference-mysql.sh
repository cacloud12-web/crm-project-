#!/usr/bin/env bash
# Safe CA Reference MySQL 1045 diagnosis for LIVE Hostinger.
# Does NOT print passwords. Does NOT modify data.
# Run from: ~/domains/crm.caclouddesk.com/public_html
set -euo pipefail

PHP="/opt/alt/php83/usr/bin/php"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "=== 1) Live project root ==="
pwd
test -f artisan && echo "artisan: present" || echo "artisan: MISSING"
test -f .env && echo ".env: present" || echo ".env: MISSING"

echo
echo "=== 2) CA_REFERENCE_DB_* keys (values redacted) ==="
python3 - <<'PY'
from pathlib import Path
import re
p = Path(".env")
text = p.read_text(encoding="utf-8", errors="replace")
keys = [
    "CA_REFERENCE_DB_HOST",
    "CA_REFERENCE_DB_PORT",
    "CA_REFERENCE_DB_DATABASE",
    "CA_REFERENCE_DB_USERNAME",
    "CA_REFERENCE_DB_PASSWORD",
    "CA_REFERENCE_DB_DRIVER",
    "CA_REFERENCE_DB_SOCKET",
]
seen = {k: [] for k in keys}
for i, line in enumerate(text.splitlines(), 1):
    raw = line
    if raw.startswith("\ufeff"):
        raw = raw.lstrip("\ufeff")
    m = re.match(r"^([A-Z0-9_]+)\s*=\s*(.*)$", raw)
    if not m:
        continue
    k, v = m.group(1), m.group(2)
    if k in seen:
        seen[k].append((i, v, raw))

for k in keys:
    entries = seen[k]
    print(f"{k}:")
    if not entries:
        print("  present: no")
        continue
    if len(entries) > 1:
        print(f"  DUPLICATE_COUNT: {len(entries)} (lines {[e[0] for e in entries]})")
    line_no, v, raw = entries[-1]  # last wins in dotenv
    has_cr = "\r" in raw or v.endswith("\r")
    v_clean = v.strip().strip("\r")
    quoted = (v_clean.startswith('"') and v_clean.endswith('"')) or (v_clean.startswith("'") and v_clean.endswith("'"))
    inner = v_clean[1:-1] if quoted else v_clean
    # dotenv unquoted: strip inline comments after space+#
    if not quoted and " #" in inner:
        parse_note = "possible_inline_comment"
    else:
        parse_note = "ok"
    leading_ws = len(v) > 0 and v[0].isspace()
    trailing_ws = len(v.rstrip("\r")) > 0 and v.rstrip("\r")[-1].isspace()
    special = any(ch in inner for ch in ['#', ' ', '"', "'", '$', '\\', '`'])
    at_sign = "@" in inner
    print(f"  present: yes (line {line_no})")
    if k == "CA_REFERENCE_DB_PASSWORD":
        print(f"  password_length: {len(inner)}")
        print(f"  leading_whitespace: {'yes' if leading_ws else 'no'}")
        print(f"  trailing_whitespace: {'yes' if trailing_ws else 'no'}")
        print(f"  quoted: {'yes' if quoted else 'no'}")
        print(f"  quoting_recommended: {'yes' if special or at_sign else 'optional'}")
        print(f"  contains_dotenv_sensitive_chars: {'yes' if special else 'no'}")
        print(f"  contains_at_sign: {'yes' if at_sign else 'no'}")
        print(f"  carriage_return: {'yes' if has_cr else 'no'}")
        print(f"  parse_note: {parse_note}")
    else:
        print(f"  value: {inner}")
        print(f"  quoted: {'yes' if quoted else 'no'}")
        print(f"  leading_whitespace: {'yes' if leading_ws else 'no'}")
        print(f"  trailing_whitespace: {'yes' if trailing_ws else 'no'}")
        print(f"  carriage_return: {'yes' if has_cr else 'no'}")
PY

echo
echo "=== 3) Environment override check ==="
env | awk -F= '/^CA_REFERENCE_DB_/ {print $1"=(set, length="length($2)")"}' || true

echo
echo "=== 4) optimize:clear ==="
"$PHP" artisan optimize:clear

echo
echo "=== 5) Laravel resolved ca_reference (no password) ==="
"$PHP" artisan tinker --execute="
\$c = config('database.connections.ca_reference');
echo 'connection=ca_reference'.PHP_EOL;
echo 'driver='.(\$c['driver'] ?? '').PHP_EOL;
echo 'host='.(\$c['host'] ?? '').PHP_EOL;
echo 'port='.(\$c['port'] ?? '').PHP_EOL;
echo 'database='.(\$c['database'] ?? '').PHP_EOL;
echo 'username='.(\$c['username'] ?? '').PHP_EOL;
echo 'unix_socket='.(\$c['unix_socket'] ?? '').PHP_EOL;
\$pw = (string) (\$c['password'] ?? '');
echo 'password_present='.(\$pw !== '' ? 'yes' : 'no').PHP_EOL;
echo 'password_length='.strlen(\$pw).PHP_EOL;
"

echo
echo "=== 6) DNS for srv1999.hstgr.io ==="
(getent ahosts srv1999.hstgr.io || true)
(getent hosts srv1999.hstgr.io || true)
(host srv1999.hstgr.io || true)

echo
echo "=== 7) This server addresses ==="
(hostname -I 2>/dev/null || true)
(ip -6 addr show scope global 2>/dev/null | awk '/inet6/ {print \$2}' || true)
(curl -4 -s ifconfig.me 2>/dev/null | awk '{print "egress_ipv4="$0}' || true)
(curl -6 -s ifconfig.me 2>/dev/null | awk '{print "egress_ipv6="$0}' || true)

echo
echo "=== 8) Direct MySQL auth tests (password via MYSQL_PWD from .env; not echoed) ==="
# Load password into env without printing.
eval "$(python3 - <<'PY'
from pathlib import Path
import re, shlex
text = Path('.env').read_text(encoding='utf-8', errors='replace')
vals = {}
for line in text.splitlines():
    m = re.match(r'^([A-Z0-9_]+)\s*=\s*(.*)$', line.strip('\r'))
    if not m: continue
    k,v=m.group(1),m.group(2)
    if k.startswith('CA_REFERENCE_DB_'):
        if (v.startswith('"') and v.endswith('"')) or (v.startswith("'") and v.endswith("'")):
            v=v[1:-1]
        vals[k]=v
host=vals.get('CA_REFERENCE_DB_HOST','')
port=vals.get('CA_REFERENCE_DB_PORT','3306')
db=vals.get('CA_REFERENCE_DB_DATABASE','')
user=vals.get('CA_REFERENCE_DB_USERNAME','')
pw=vals.get('CA_REFERENCE_DB_PASSWORD','')
print(f"export CA_HOST={shlex.quote(host)}")
print(f"export CA_PORT={shlex.quote(port)}")
print(f"export CA_DB={shlex.quote(db)}")
print(f"export CA_USER={shlex.quote(user)}")
print(f"export MYSQL_PWD={shlex.quote(pw)}")
PY
)"

mysql_try() {
  local label="$1"; shift
  echo "-- try: $label"
  set +e
  out=$(mysql --connect-timeout=8 "$@" -e "SELECT 1 AS ok;" 2>&1)
  code=$?
  set -e
  # redact any accidental password leakage
  out=$(printf '%s\n' "$out" | sed -E 's/password[=:].*$/password=[REDACTED]/Ig')
  if [[ $code -eq 0 ]]; then
    echo "RESULT: SUCCESS"
  else
    echo "RESULT: FAIL ($code)"
    echo "$out" | head -5
  fi
}

mysql_try "configured_host_tcp" -h "$CA_HOST" -P "$CA_PORT" -u "$CA_USER" "$CA_DB"
mysql_try "force_ipv4_protocol" -h "$CA_HOST" -P "$CA_PORT" -u "$CA_USER" --protocol=TCP "$CA_DB"
mysql_try "localhost" -h localhost -P "$CA_PORT" -u "$CA_USER" "$CA_DB"
mysql_try "127.0.0.1" -h 127.0.0.1 -P "$CA_PORT" -u "$CA_USER" "$CA_DB"

unset MYSQL_PWD

echo
echo "=== 9) Laravel connection probe ==="
set +e
"$PHP" artisan tinker --execute="
try {
  \$n = DB::connection('ca_reference')->table('ca_firms')->count();
  echo 'LARAVEL_CA_REFERENCE: SUCCESS ca_firms='.\$n.PHP_EOL;
} catch (Throwable \$e) {
  \$msg = \$e->getMessage();
  \$msg = preg_replace('/password[^\\s]*/i', 'password=[REDACTED]', \$msg);
  echo 'LARAVEL_CA_REFERENCE: FAIL '.\$msg.PHP_EOL;
}
"
set -e

echo
echo "=== DONE ==="
echo "If all MySQL tries FAIL with 1045: Hostinger user/password/host-grant issue (not Laravel code)."
echo "Password was previously exposed in chat/terminal — reset it in hPanel before retrying."
