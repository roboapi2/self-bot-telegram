#!/usr/bin/env bash
# Read-only server checks plus isolated synthetic application tests. No Telegram login.
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'sudo bash scripts/validate-vps.sh' >&2; exit 1; }
source scripts/common.sh
require_project_service
grep -Fxq "ExecStart=$PHP_BIN $ROOT/supervisor.php" /etc/systemd/system/userbot-supervisor.service
grep -Fxq "env[UBCC_PHP_BIN] = $PHP_BIN" "/etc/php/$PHP_VERSION/fpm/pool.d/ubcc.conf"
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 ]]
"$PHP_BIN" /usr/bin/composer validate --no-check-publish
"$PHP_BIN" /usr/bin/composer check-platform-reqs --no-dev
"$PHP_BIN" /usr/bin/composer audit --locked --no-dev
nginx -t
"php-fpm$PHP_VERSION" -t
systemd-analyze verify /etc/systemd/system/userbot-supervisor.service
systemctl is-active --quiet nginx "php$PHP_VERSION-fpm" userbot-supervisor
systemctl is-enabled --quiet userbot-supervisor
runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php doctor
runuser -u ubcc -- env TEST_PHP="$PHP_BIN" TEST_PYTHON=python3 bash scripts/test.sh
for path in config.php .panel/accounts.json accounts.json MadelineProto.log; do
    [[ -f "$ROOT/$path" ]] || continue
    runuser -u ubcc -- test -r "$ROOT/$path"
    if runuser -u www-data -- test -r "$ROOT/$path"; then echo "داده خصوصی برای www-data خواندنی است: $path" >&2; exit 1; fi
done
runuser -u ubcc -- test ! -w "$ROOT/lib.php"
runuser -u ubcc -- test -w "$ROOT/.panel"
runuser -u ubcc -- test -w "$ROOT/accounts"
runuser -u www-data -- test -r "$ROOT/assets/app.js"
python3 - <<'PY'
import urllib.request, urllib.error
base = 'http://127.0.0.1:8080'
checks = {'/':200, '/assets/app.js':200, '/assets/styles.css':200,
          '/api.php?action=list_accounts':401}
for path in ['/config.php','/accounts.json','/.panel/accounts.json','/composer.lock',
             '/lib.php','/worker.php','/supervisor.php','/vendor/autoload.php',
             '/accounts/test/session.madeline','/MadelineProto.log','/docs/INSTALL_FA.md',
             '/scripts/install-vps.sh','/releases/release.tar.gz']:
    checks[path] = 404
for path, expected in checks.items():
    try:
        with urllib.request.urlopen(base+path, timeout=15) as response:
            actual = response.status
            response.read(1)
    except urllib.error.HTTPError as error:
        actual = error.code
    if actual != expected:
        raise SystemExit(f'FAIL {path}: HTTP {actual}, expected {expected}')
print(f'{len(checks)} Nginx/FPM route checks passed')
PY
logrotate --debug /etc/logrotate.d/ubcc
echo 'بررسی‌های این اجرا موفق بود؛ QR، 2FA، امکانات Telegram و بازیابی پس از reboot را طبق راهنما جداگانه ثبت کنید.'
