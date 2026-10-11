#!/usr/bin/env bash
# Runs only against isolated fake data; no Telegram sessions and no real API requests.
set -euo pipefail
IMAGE=${1:?image required}
ROOT=$(mktemp -d)
PROJECT="ubcc-ci-${RANDOM}-$$"
export UBCC_ROOT="$ROOT" UBCC_IMAGE="$IMAGE" DOMAIN=ci.example.invalid ACME_EMAIL=ci@example.invalid
cleanup() {
    docker compose -p "$PROJECT" -f compose.production.yml down --remove-orphans >/dev/null 2>&1 || true
    [[ "$ROOT" == /tmp/tmp.* && -d "$ROOT" && ! -L "$ROOT" ]] && sudo rm -rf -- "$ROOT"
}
trap cleanup EXIT
mkdir -p "$ROOT/data/"{accounts,panel,caddy,caddy-config} "$ROOT/data/panel/"{sessions,throttle} "$ROOT/secrets" "$ROOT/docker"
cp docker/Caddyfile "$ROOT/docker/Caddyfile"
printf '<?php return ["api_id"=>1,"api_hash"=>"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","admin_password"=>"CI_FIXTURE_ONLY_PASSWORD"];\n' > "$ROOT/secrets/config.php"
printf '# no secrets\n' > "$ROOT/secrets/app.env"
sudo chown -R 10001:10001 "$ROOT/data"
sudo chmod -R u+rwX,go-rwx "$ROOT/data"
# Compose CLI reads app.env as the runner; only the mounted PHP secret needs app ownership.
sudo chown 10001:10001 "$ROOT/secrets/config.php"
sudo chmod 0400 "$ROOT/secrets/config.php"
chmod 0700 "$ROOT/secrets"
chmod 0600 "$ROOT/secrets/app.env"
dc() { docker compose -p "$PROJECT" -f compose.production.yml "$@"; }
dc config --quiet
dc up -d --wait --wait-timeout 60 runtime
dc run --rm --no-deps supervisor init
dc up -d --wait --wait-timeout 180 nginx
dc exec -T web php /opt/app/docker/health.php web
dc exec -T supervisor php /opt/app/docker/health.php supervisor
dc exec -T web php -r 'require "/opt/app/lib.php"; $h=read_json_file("/opt/app/.panel/supervisor.health.json"); exit(is_file("/proc/".$h["pid"]."/cmdline") ? 0 : 1);'
for route in config.php accounts.json .panel/accounts.json vendor/autoload.php docker/config.php; do
    [[ $(dc exec -T nginx curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080/$route") == 404 ]]
done
[[ $(dc exec -T nginx curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8080/api.php?action=list_accounts') == 401 ]]
dc exec -T supervisor php -r 'file_put_contents("/opt/app/.panel/persistence-fixture", "preserve");'
dc restart supervisor
for attempt in {1..30}; do dc exec -T supervisor php /opt/app/docker/health.php supervisor && break; sleep 2; done
dc exec -T web php -r 'exit(file_get_contents("/opt/app/.panel/persistence-fixture")==="preserve" ? 0:1);'
dc exec -T web php /opt/app/docker/health.php supervisor
dc stop
dc up -d --force-recreate --wait --wait-timeout 180 nginx
dc exec -T web php -r 'exit(file_get_contents("/opt/app/.panel/persistence-fixture")==="preserve" ? 0:1);'
docker run --rm --entrypoint php "$IMAGE" -r 'exit(is_file("/opt/app/accounts.json") || count(glob("/opt/app/accounts/*")) || count(glob("/opt/app/.panel/*")) ? 1:0);'
echo 'Docker health, shared PID/IPC namespace, isolated persistence and private routes passed.'
