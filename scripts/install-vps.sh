#!/usr/bin/env bash
set -euo pipefail
umask 027
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
source "$ROOT/scripts/common.sh"
[[ $EUID -eq 0 ]] || { echo 'با sudo bash scripts/install-vps.sh اجرا کنید' >&2; exit 1; }
[[ "$ROOT" =~ ^/(opt|srv|var/www)/[A-Za-z0-9_./-]+$ && "$ROOT" != *..* ]] || { echo 'پروژه موجود را زیر /opt یا /srv یا /var/www در مسیری بدون فاصله قرار دهید.' >&2; exit 1; }
DOMAIN=${1:-localhost}
[[ "$DOMAIN" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || { echo 'نام دامنه نامعتبر است' >&2; exit 1; }
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 ]] || { echo 'هدف نصب: Ubuntu 24.04 LTS، معماری ۶۴ بیت.' >&2; exit 1; }
exec 9>/run/lock/ubcc-maintenance.lock
flock -n 9 || { echo 'فرمان نگهداری دیگری در حال اجرا است.' >&2; exit 1; }
[[ ! -L "$ROOT/accounts" && ! -L "$ROOT/.panel" && ! -L "$ROOT/config.php" ]] || { echo 'مسیر پیکربندی یا داده نباید پیوند نمادین باشد.' >&2; exit 1; }
FPM_CONFIG="/etc/php/$PHP_VERSION/fpm/pool.d/ubcc.conf"
if [[ -f /etc/systemd/system/userbot-supervisor.service ]]; then require_project_service; fi
if [[ -f "$FPM_CONFIG" ]]; then
    grep -Fxq "chdir = $ROOT" "$FPM_CONFIG" || { echo 'pool موجود متعلق به مسیر دیگری است.' >&2; exit 1; }
fi
if [[ -f /etc/nginx/sites-available/ubcc ]]; then
    grep -Fq "root $ROOT;" /etc/nginx/sites-available/ubcc || { echo 'سایت Nginx موجود متعلق به مسیر دیگری است.' >&2; exit 1; }
fi
# Snapshot before package/source/runtime changes on reinstall.
if [[ -f /etc/systemd/system/userbot-supervisor.service ]]; then
    UBCC_MAINTENANCE_LOCK_HELD=1 bash "$ROOT/scripts/backup.sh"
    systemctl stop "php$PHP_VERSION-fpm" userbot-supervisor
fi
trap 'echo "نصب متوقف شد؛ سرویس برنامه را تا رفع خطا و اجرای doctor راه‌اندازی نکنید." >&2' ERR
apt-get update
apt-get install -y nginx php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml php8.3-gmp php8.3-bcmath php8.3-curl php8.3-zip composer logrotate rsync ca-certificates python3
systemctl stop "php$PHP_VERSION-fpm"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE === 8 ? 0 : 1);'
getent group ubcc >/dev/null || groupadd --system ubcc
id -u ubcc >/dev/null 2>&1 || useradd --system --gid ubcc --home-dir "$ROOT/.panel" --shell /usr/sbin/nologin ubcc
mkdir -p "$ROOT/accounts" "$ROOT/.panel/sessions" "$ROOT/.panel/throttle" "$ROOT/.panel/backups"
[[ -f "$ROOT/accounts.json" ]] || printf '[]\n' > "$ROOT/accounts.json"
[[ -f "$ROOT/config.php" ]] || cp -n "$ROOT/config.example.php" "$ROOT/config.php"
cd "$ROOT"
"$PHP_BIN" scripts/migrate-storage.php
COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" /usr/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
"$PHP_BIN" /usr/bin/composer check-platform-reqs --no-dev
"$PHP_BIN" /usr/bin/composer audit --locked --no-dev
TEST_PHP="$PHP_BIN" bash scripts/test.sh
find "$ROOT" -maxdepth 1 -type f \( -name '*.php' -o -name '*.sh' -o -name 'composer.*' \) -exec chown root:ubcc {} +
find "$ROOT" -maxdepth 1 -type f -name '*.php' -exec chmod 0640 {} +
chmod 0755 "$ROOT"
chown root:ubcc "$ROOT"
for dir in scripts tests deploy docs; do
    chown -R root:ubcc "$ROOT/$dir"
    find "$ROOT/$dir" -type d -exec chmod 0755 {} +
    find "$ROOT/$dir" -type f -exec chmod 0640 {} +
done
chown -R root:ubcc "$ROOT/assets" "$ROOT/vendor"
find "$ROOT/assets" "$ROOT/vendor" -type d -exec chmod 0755 {} +
find "$ROOT/assets" "$ROOT/vendor" -type f -exec chmod 0644 {} +
chown -R ubcc:ubcc "$ROOT/accounts" "$ROOT/.panel"
find "$ROOT/accounts" "$ROOT/.panel" -type d -exec chmod 0700 {} +
find "$ROOT/accounts" "$ROOT/.panel" -type f -exec chmod 0600 {} +
chmod 0640 "$ROOT/config.php"
for private in accounts.json MadelineProto.log; do
    if [[ -f "$ROOT/$private" ]]; then chown root:ubcc "$ROOT/$private"; chmod 0640 "$ROOT/$private"; fi
done
render() { sed -e "s|@ROOT@|$ROOT|g" -e "s|@DOMAIN@|$DOMAIN|g" -e "s|@PHP_BIN@|$PHP_BIN|g" "$1"; }
FPM_CONFIG="/etc/php/$PHP_VERSION/fpm/pool.d/ubcc.conf"
if [[ -f /etc/systemd/system/userbot-supervisor.service ]]; then
    grep -Fxq "WorkingDirectory=$ROOT" /etc/systemd/system/userbot-supervisor.service || { echo 'سرویس موجود متعلق به مسیر دیگری است.' >&2; exit 1; }
fi
if [[ -f "$FPM_CONFIG" ]]; then
    grep -Fxq "chdir = $ROOT" "$FPM_CONFIG" || { echo 'pool موجود متعلق به مسیر دیگری است.' >&2; exit 1; }
fi
[[ -f "$FPM_CONFIG" ]] || render deploy/php-fpm.conf.in > "$FPM_CONFIG"
[[ -f /etc/nginx/sites-available/ubcc ]] || render deploy/nginx.conf.in > /etc/nginx/sites-available/ubcc
ln -sfn /etc/nginx/sites-available/ubcc /etc/nginx/sites-enabled/ubcc
[[ -f /etc/systemd/system/userbot-supervisor.service ]] || render deploy/userbot-supervisor.service.in > /etc/systemd/system/userbot-supervisor.service
[[ -f /etc/logrotate.d/ubcc ]] || render deploy/logrotate.conf.in > /etc/logrotate.d/ubcc
php-fpm"$PHP_VERSION" -t
nginx -t
systemctl daemon-reload
systemctl enable --now "php$PHP_VERSION-fpm" nginx
systemctl reload "php$PHP_VERSION-fpm" nginx
if runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php doctor; then
    systemctl enable --now userbot-supervisor
else
    echo 'config.php را تنظیم، doctor را اجرا و سپس sudo systemctl enable --now userbot-supervisor را اجرا کنید.'
fi
trap - ERR
echo 'پنل از تونل SSH به 127.0.0.1:8080 در دسترس است؛ راهنمای docs/INSTALL_FA.md را بخوانید. تنظیمات و داده موجود حفظ شده‌اند.'
