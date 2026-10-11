#!/usr/bin/env bash
source /opt/ubcc/scripts/docker/common.sh
[[ $# -ge 1 && $# -le 2 ]] && valid_image "$1" || { echo 'digest معتبر GHCR لازم است.' >&2; exit 1; }
TARGET=$1
SEQUENCE=${2:-0}
[[ "$SEQUENCE" =~ ^[0-9]{1,12}$ ]]
SEQUENCE=$((10#$SEQUENCE))
lock
LAST=$(cat "$ROOT/state/sequence" 2>/dev/null || echo 0)
if [[ $SEQUENCE -gt 0 && $SEQUENCE -le $LAST ]]; then echo 'انتشار قدیمی‌تر کنار گذاشته شد.'; exit 0; fi
OLD=''
if [[ -f "$ROOT/state/current.env" ]]; then load_current; OLD=$UBCC_IMAGE; fi
docker pull "$TARGET"
export UBCC_IMAGE="$TARGET"
dc config --quiet
# No overlap: all old workers and shared PID namespace are stopped before copying data.
rollback_failure() {
    trap - ERR
    echo 'استقرار ناموفق؛ بازیابی image و پیکربندی قبلی بدون بازگردانی داده.' >&2
    dc stop || true
    if [[ -n "$OLD" ]]; then
        export UBCC_IMAGE="$OLD"
        dc up -d --force-recreate --wait --wait-timeout 240 && health || echo 'بازیابی خودکار ناموفق؛ لاگ‌ها را بررسی کنید.' >&2
    fi
    exit 1
}
trap rollback_failure ERR
dc stop
snapshot
dc up -d --wait --wait-timeout 60 runtime
dc run --rm --no-deps supervisor init
dc up -d --force-recreate --wait --wait-timeout 240
health
if [[ -n "$OLD" ]]; then cp -p "$ROOT/state/current.env" "$ROOT/state/previous.env"; fi
printf 'UBCC_IMAGE=%s\n' "$TARGET" > "$ROOT/state/current.env.tmp"
mv "$ROOT/state/current.env.tmp" "$ROOT/state/current.env"
[[ $SEQUENCE -eq 0 ]] || printf '%s\n' "$SEQUENCE" > "$ROOT/state/sequence"
trap - ERR
echo 'استقرار digest تأیید و ثبت شد؛ داده و نشست‌ها حفظ شدند.'
