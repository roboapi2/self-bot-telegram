#!/usr/bin/env bash
# Only run inside the disposable CI image, never on the VPS.
set -euo pipefail
[[ ${UBCC_ISOLATED_DEPLOY_TEST:-} == 1 && $EUID -eq 0 && $PWD == /opt/ci && ! -e /opt/ubcc ]]
ROOT=/opt/ubcc
mkdir -p "$ROOT"/{scripts/docker,state,data/accounts,data/panel,secrets,docker,backups} /tmp/ubcc-mock
cp scripts/docker/*.sh "$ROOT/scripts/docker/"
cp compose.production.yml "$ROOT/compose.production.yml"
cp docker/Caddyfile "$ROOT/docker/Caddyfile"
printf 'UBCC_ROOT=/opt/ubcc\nDOMAIN=ci.example.invalid\nACME_EMAIL=ci@example.invalid\n' > "$ROOT/settings.env"
printf 'private-fixture\n' > "$ROOT/data/accounts/canary"
OLD="ghcr.io/roboapi2/self-bot-telegram@sha256:$(printf 'a%.0s' {1..64})"
NEW="ghcr.io/roboapi2/self-bot-telegram@sha256:$(printf 'b%.0s' {1..64})"
printf 'UBCC_IMAGE=%s\n' "$OLD" > "$ROOT/state/current.env"
printf '10\n' > "$ROOT/state/sequence"
cat > /tmp/ubcc-mock/docker <<'MOCK'
#!/usr/bin/env bash
printf '%s %s\n' "${UBCC_IMAGE:-none}" "$*" >> /tmp/ubcc-mock/commands
if [[ "$*" == *'--force-recreate'* && ${UBCC_IMAGE:-} == *bbbbbbbb && -f /tmp/ubcc-mock/fail ]]; then
    rm /tmp/ubcc-mock/fail
    exit 1
fi
exit 0
MOCK
printf '#!/usr/bin/env bash\nexit 0\n' > /tmp/ubcc-mock/curl
chmod 0700 /tmp/ubcc-mock/docker /tmp/ubcc-mock/curl
export PATH="/tmp/ubcc-mock:$PATH"
bash "$ROOT/scripts/docker/deploy.sh" "$NEW" 9
[[ ! -f /tmp/ubcc-mock/commands ]]
touch /tmp/ubcc-mock/fail
if bash "$ROOT/scripts/docker/deploy.sh" "$NEW" 11; then echo 'expected deployment failure' >&2; exit 1; fi
grep -q "$OLD" "$ROOT/state/current.env"
[[ $(cat "$ROOT/state/sequence") == 10 && $(cat "$ROOT/data/accounts/canary") == private-fixture ]]
grep -q "$OLD compose.*--force-recreate" /tmp/ubcc-mock/commands
[[ $(find "$ROOT/backups" -name '*.tar.gz' | wc -l) -ge 1 ]]
bash "$ROOT/scripts/docker/deploy.sh" "$NEW" 11
grep -q "$NEW" "$ROOT/state/current.env"
grep -q "$OLD" "$ROOT/state/previous.env"
[[ $(cat "$ROOT/state/sequence") == 11 && $(cat "$ROOT/data/accounts/canary") == private-fixture ]]
bash "$ROOT/scripts/docker/control.sh" rollback
grep -q "$OLD" "$ROOT/state/current.env"
echo 'Isolated host deployment: stale run, backup, failed update, rollback, state and data preservation passed.'
