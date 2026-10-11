#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'با sudo bash scripts/control.sh COMMAND اجرا کنید' >&2; exit 1; }
source "$ROOT/scripts/common.sh"
require_project_service
case "${1:-status}" in
    start) systemctl start "php$PHP_VERSION-fpm" userbot-supervisor ;;
    stop) systemctl stop userbot-supervisor "php$PHP_VERSION-fpm" ;;
    restart) systemctl restart "php$PHP_VERSION-fpm" userbot-supervisor ;;
    status) systemctl --no-pager status "php$PHP_VERSION-fpm" userbot-supervisor ;;
    logs) journalctl -u userbot-supervisor -n 100 --no-pager ;;
    doctor) runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php doctor ;;
    accounts) runuser -u ubcc -- env UBCC_PHP_BIN="$PHP_BIN" UBCC_PROCESS_MODE=supervisor "$PHP_BIN" console.php status ;;
    *) echo 'فرمان‌ها: start stop restart status logs doctor accounts' >&2; exit 1 ;;
esac
