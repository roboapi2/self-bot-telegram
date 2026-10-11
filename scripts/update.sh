#!/usr/bin/env bash
# Source-only update from an already prepared directory; runtime/config are excluded.
set -euo pipefail
umask 077
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
source "$ROOT/scripts/common.sh"
require_project_service
export TEST_PHP="$PHP_BIN"
[[ $EUID -eq 0 && $# -eq 1 ]] || { echo 'sudo bash scripts/update.sh /path/to/new-source' >&2; exit 1; }
STAGE=$(realpath -- "$1")
[[ "$STAGE" != "$ROOT" && "$STAGE" != "$ROOT/"* ]] || { echo 'پوشه staging جداگانه انتخاب کنید.' >&2; exit 1; }
[[ -f "$STAGE/composer.lock" && -f "$STAGE/tests/unit.php" && -f "$STAGE/supervisor.php" ]] || { echo 'سورس به‌روزرسانی ناقص است.' >&2; exit 1; }
[[ -z $(find "$STAGE" -path "$STAGE/vendor" -prune -o -type l -print -quit) ]] || { echo 'پیوند نمادین در سورس به‌روزرسانی پشتیبانی نمی‌شود.' >&2; exit 1; }
bash "$STAGE/scripts/test.sh"
FILES=(index.php api.php lib.php runtime.php worker.php supervisor.php console.php madeline_tools.php qr_worker.php bot_template.php config.example.php composer.json composer.lock install.sh run.sh README_FA.md AUDIT_FA.md .gitignore .ignore CHANGELOG.md Dockerfile .dockerignore .gitattributes compose.production.yml)
for file in "${FILES[@]}"; do [[ -f "$STAGE/$file" ]] || { echo "Missing $file" >&2; exit 1; }; done
for dir in assets scripts deploy tests docs docker .github; do [[ -d "$STAGE/$dir" ]] || { echo "Missing $dir" >&2; exit 1; }; done
for file in scripts/common.sh scripts/migrate-storage.php docs/INSTALL_FA.md docs/OPERATIONS_FA.md docs/FINAL_AUDIT_FA.md; do
    [[ -f "$STAGE/$file" ]] || { echo "Missing $file" >&2; exit 1; }
done
exec 9>/run/lock/ubcc-maintenance.lock
flock -n 9 || { echo 'فرمان نگهداری دیگری در حال اجرا است.' >&2; exit 1; }
BACKUP=$(UBCC_MAINTENANCE_LOCK_HELD=1 bash "$ROOT/scripts/backup.sh")
echo "پشتیبان بازیابی: $BACKUP"
cd "$ROOT"
systemctl stop "php$PHP_VERSION-fpm" userbot-supervisor
trap 'echo "به‌روزرسانی شکست خورد؛ سرویس‌ها خاموش می‌مانند. راهنمای بازیابی در docs/OPERATIONS_FA.md را بخوانید." >&2' ERR
# Explicit allowlist: never config.php, accounts*, .panel, sessions, logs or backups.
for file in "${FILES[@]}"; do
    [[ -f "$STAGE/$file" ]] || { echo "Missing $file" >&2; exit 1; }
    install -m 0640 -o root -g ubcc "$STAGE/$file" "$ROOT/$file"
done
for dir in assets scripts deploy tests docs docker .github; do
    rsync -a --chown=root:ubcc "$STAGE/$dir/" "$ROOT/$dir/"
    find "$ROOT/$dir" -type d -exec chmod 0755 {} +
    find "$ROOT/$dir" -type f -exec chmod 0640 {} +
done
find assets -type d -exec chmod 0755 {} +
find assets -type f -exec chmod 0644 {} +
COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" /usr/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
"$PHP_BIN" /usr/bin/composer check-platform-reqs --no-dev
"$PHP_BIN" /usr/bin/composer audit --locked --no-dev
find vendor -type d -exec chmod 0755 {} +
find vendor -type f -exec chmod 0644 {} +
chown -R root:ubcc vendor assets
bash scripts/test.sh
runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php doctor
systemctl start "php$PHP_VERSION-fpm" userbot-supervisor
trap - ERR
echo 'به‌روزرسانی انجام شد؛ حساب‌های فعال ادامه می‌دهند. تغییر قالب‌های سرور نیازمند بررسی دستی است.'
