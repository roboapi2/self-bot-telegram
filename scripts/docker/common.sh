#!/usr/bin/env bash
set -euo pipefail
set -E
umask 077
ROOT=/opt/ubcc
[[ $EUID -eq 0 ]] || { echo 'این فرمان به sudo نیاز دارد.' >&2; exit 1; }
[[ -f "$ROOT/settings.env" && -f "$ROOT/compose.production.yml" ]] || { echo 'ابتدا setup را اجرا کنید.' >&2; exit 1; }
# Root-owned settings contain only validated domain/email and paths, not credentials.
set -a
source "$ROOT/settings.env"
set +a
[[ "$UBCC_ROOT" == "$ROOT" && "$DOMAIN" =~ ^[A-Za-z0-9][A-Za-z0-9.-]+$ ]]
export UBCC_IMAGE="${UBCC_IMAGE:-ghcr.io/roboapi2/self-bot-telegram@sha256:$(printf '0%.0s' {1..64})}"
export UBCC_GATEWAY_IMAGE="${UBCC_GATEWAY_IMAGE:-ghcr.io/roboapi2/self-bot-telegram-gateway@sha256:$(printf '0%.0s' {1..64})}"
dc() { docker compose --project-name ubcc --env-file "$ROOT/settings.env" -f "$ROOT/compose.production.yml" "$@"; }
load_current() { [[ -f "$ROOT/state/current.env" ]] || { echo 'هنوز استقرار موفقی ثبت نشده است.' >&2; return 1; }; unset UBCC_GATEWAY_IMAGE; source "$ROOT/state/current.env"; valid_image "$UBCC_IMAGE"; valid_gateway "${UBCC_GATEWAY_IMAGE:-}" || { echo 'digest gateway در state ثبت نشده؛ راهنمای مهاجرت را ببینید.' >&2; return 1; }; export UBCC_IMAGE UBCC_GATEWAY_IMAGE; }
lock() { exec 9>"$ROOT/state/maintenance.lock"; flock -n 9 || { echo 'فرمان نگهداری دیگری فعال است.' >&2; exit 1; }; }
valid_image() { [[ "$1" =~ ^ghcr.io/roboapi2/self-bot-telegram@sha256:[0-9a-f]{64}$ ]]; }
valid_gateway() { [[ "$1" =~ ^ghcr.io/roboapi2/self-bot-telegram-gateway@sha256:[0-9a-f]{64}$ ]]; }
health() {
    dc exec -T supervisor php /opt/app/console.php doctor
    for attempt in {1..36}; do
        if curl --silent --fail --connect-timeout 5 --max-time 5 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/healthz" >/dev/null; then return 0; fi
        sleep 5
    done
    return 1
}
snapshot() {
    mkdir -p "$ROOT/backups"
    SNAPSHOT="$ROOT/backups/data-$(date -u +%Y%m%dT%H%M%SZ)-$$.tar.gz"
    tar -C "$ROOT" -czf "$SNAPSHOT" data secrets settings.env compose.production.yml docker/Caddyfile state
    chmod 0600 "$SNAPSHOT"
    echo "پشتیبان خصوصی: $SNAPSHOT" >&2
}
