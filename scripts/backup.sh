#!/usr/bin/env bash
# Consistent local snapshot: stop PHP-FPM and account workers while copying sessions.
set -euo pipefail
umask 077
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
[[ $EUID -eq 0 ]] || { echo 'با sudo bash scripts/backup.sh اجرا کنید' >&2; exit 1; }
if [[ "${UBCC_MAINTENANCE_LOCK_HELD:-0}" != 1 ]]; then
    exec 9>/run/lock/ubcc-maintenance.lock
    flock -n 9 || { echo 'فرمان نگهداری دیگری در حال اجرا است.' >&2; exit 1; }
fi
cd "$ROOT"
source "$ROOT/scripts/common.sh"
require_project_service
FPM="php$PHP_VERSION-fpm"
WAS_FPM=0; WAS_SUPERVISOR=0
systemctl is-active --quiet "$FPM" && WAS_FPM=1
systemctl is-active --quiet userbot-supervisor && WAS_SUPERVISOR=1
resume() {
    [[ $WAS_FPM -eq 0 ]] || systemctl start "$FPM"
    [[ $WAS_SUPERVISOR -eq 0 ]] || systemctl start userbot-supervisor
    return 0
}
trap resume EXIT
systemctl stop "$FPM" userbot-supervisor
mkdir -p .panel/backups
BACKUP="$ROOT/.panel/backups/ubcc-$(date -u +%Y%m%dT%H%M%SZ)-$$.tar.gz"
tar --exclude='./vendor' --exclude='./.panel/backups' --exclude='./.panel/sessions' \
    --exclude='./.git' --exclude='./.claude' --exclude='./graphify-out' --exclude='./releases' \
    -czf "$BACKUP" .
chmod 0600 "$BACKUP"
echo "$BACKUP"
