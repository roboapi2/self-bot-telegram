#!/usr/bin/env bash
# Explicit restore: current data is first saved in a private recovery backup.
set -euo pipefail
umask 077
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ $EUID -eq 0 && $# -eq 1 ]] || { echo 'sudo bash scripts/restore.sh /absolute/backup.tar.gz' >&2; exit 1; }
source "$ROOT/scripts/common.sh"
require_project_service
ARCHIVE=$(realpath -- "$1")
[[ -f "$ARCHIVE" ]] || exit 1
exec 9>/run/lock/ubcc-maintenance.lock
flock -n 9 || { echo 'فرمان نگهداری دیگری در حال اجرا است.' >&2; exit 1; }
STAGE=$(mktemp -d /var/tmp/ubcc-restore.XXXXXXXX)
trap 'rm -rf -- "$STAGE"' EXIT
# Extract only regular files/directories; no traversal, device, symlink or hard link.
python3 - "$ARCHIVE" "$STAGE" <<'PY'
import pathlib, sys, tarfile
destination = pathlib.Path(sys.argv[2]).resolve()
with tarfile.open(sys.argv[1], 'r:gz') as archive:
    for member in archive.getmembers():
        target = (destination / member.name).resolve()
        if not target.is_relative_to(destination) or not (member.isfile() or member.isdir()):
            raise SystemExit('Unsafe backup member; nothing restored')
    archive.extractall(destination, filter='data')
PY
[[ -f "$STAGE/config.php" && -f "$STAGE/composer.lock" && -f "$STAGE/.panel/accounts.json" ]] || { echo 'پشتیبان کاملِ نصب جدید لازم است.' >&2; exit 1; }
BACKUP=$(UBCC_MAINTENANCE_LOCK_HELD=1 bash "$ROOT/scripts/backup.sh")
echo "پشتیبان وضعیت فعلی: $BACKUP"
systemctl stop "php$PHP_VERSION-fpm" userbot-supervisor
trap 'echo "بازیابی شکست خورد؛ سرویس‌ها را تا رفع خطا روشن نکنید." >&2' ERR
# Backups remain protected; browser login sessions are regenerated.
rsync -a --exclude=vendor --exclude=.panel/backups --exclude=releases --exclude=.git --exclude=.claude --exclude=graphify-out "$STAGE/" "$ROOT/"
rsync -a --delete "$STAGE/accounts/" "$ROOT/accounts/"
rsync -a --delete --exclude=backups --exclude=sessions "$STAGE/.panel/" "$ROOT/.panel/"
chown -R ubcc:ubcc "$ROOT/accounts" "$ROOT/.panel"
find "$ROOT/accounts" "$ROOT/.panel" -type d -exec chmod 0700 {} +
find "$ROOT/accounts" "$ROOT/.panel" -type f -exec chmod 0600 {} +
chown root:ubcc "$ROOT/config.php"; chmod 0640 "$ROOT/config.php"
for private in accounts.json MadelineProto.log; do
    if [[ -f "$ROOT/$private" ]]; then chown root:ubcc "$ROOT/$private"; chmod 0640 "$ROOT/$private"; fi
done
find "$ROOT" -maxdepth 1 -type f \( -name '*.php' -o -name '*.sh' -o -name 'composer.*' \) -exec chown root:ubcc {} +
find "$ROOT" -maxdepth 1 -type f -name '*.php' -exec chmod 0640 {} +
for dir in scripts tests deploy docs; do
    chown -R root:ubcc "$ROOT/$dir"
    find "$ROOT/$dir" -type d -exec chmod 0755 {} +
    find "$ROOT/$dir" -type f -exec chmod 0640 {} +
done
cd "$ROOT"
COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" /usr/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
"$PHP_BIN" /usr/bin/composer check-platform-reqs --no-dev
find "$ROOT/vendor" "$ROOT/assets" -type d -exec chmod 0755 {} +
find "$ROOT/vendor" "$ROOT/assets" -type f -exec chmod 0644 {} +
chown -R root:ubcc "$ROOT/vendor" "$ROOT/assets"
TEST_PHP="$PHP_BIN" bash scripts/test.sh
runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php doctor
systemctl start "php$PHP_VERSION-fpm" userbot-supervisor
trap - ERR
echo 'بازیابی انجام شد؛ اعتبار نشست Telegram و قابلیت‌ها را از پنل بررسی کنید.'
