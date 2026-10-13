#!/usr/bin/env bash
source /opt/ubcc/scripts/docker/common.sh
load_current
case "${1:-status}" in
    start) lock; dc up -d --wait --wait-timeout 240; health ;;
    stop) lock; dc stop ;;
    restart) lock; dc stop; dc up -d --force-recreate --wait --wait-timeout 240; health ;;
    status) dc ps ;;
    logs) dc logs --tail 100 "${2:-supervisor}" ;;
    doctor) dc exec -T supervisor php /opt/app/console.php doctor ;;
    diagnose) dc ps; dc exec -T supervisor php /opt/app/console.php diagnose ;;
    rollback)
        [[ -f "$ROOT/state/previous.env" ]] || { echo 'نسخه قبلی ثبت نشده است.' >&2; exit 1; }
        unset UBCC_GATEWAY_IMAGE
        source "$ROOT/state/previous.env"
        valid_gateway "${UBCC_GATEWAY_IMAGE:-}"
        exec bash "$ROOT/scripts/docker/deploy.sh" "$UBCC_IMAGE" 0 "$UBCC_GATEWAY_IMAGE" ;;
    backup)
        lock; RUNNING=$(dc ps --status running -q); dc stop
        trap 'if [[ -n "$RUNNING" ]]; then dc up -d --wait --wait-timeout 240; fi' EXIT
        snapshot ;;
    *) echo 'فرمان‌ها: start stop restart status logs doctor diagnose rollback backup' >&2; exit 1 ;;
esac
