#!/usr/bin/env bash
# Runs only against isolated fake data; no Telegram sessions and no real API requests.
set -euo pipefail
source "$(dirname -- "${BASH_SOURCE[0]}")/ci-common.sh"
IMAGE=${1:?image required}
ROOT=$(mktemp -d)
PROJECT="ubcc-ci-${RANDOM}-$$"
export CI_DOCKER_PROJECT="$PROJECT"
export UBCC_ROOT="$ROOT" UBCC_IMAGE="$IMAGE" DOMAIN=ci.example.invalid ACME_EMAIL=ci@example.invalid
# A final always() workflow step can clean this project even after external cancellation.
if [[ -n ${RUNNER_TEMP:-} ]]; then printf '%s\n' "$PROJECT" >> "$RUNNER_TEMP/ubcc-smoke-projects"; fi
diagnostics() {
    echo '::group::Compose smoke failure: container status, logs and health'
    dc ps --all || true
    dc logs --no-color --tail 150 || true
    local ids=() id
    mapfile -t ids < <(dc ps --all --quiet)
    # Bound aggregate inspection time too; ps/logs still cover all containers.
    ids=("${ids[@]:0:5}")
    for id in "${ids[@]}"; do
        # Omit Config.Env and full inspect: they can contain credentials.
        ci_run 3 'inspect container state and health' docker inspect --format '{{.Name}} state={{json .State}} user={{.Config.User}} readonly={{.HostConfig.ReadonlyRootfs}} cap_drop={{json .HostConfig.CapDrop}} security_opt={{json .HostConfig.SecurityOpt}} tmpfs={{json .HostConfig.Tmpfs}}' "$id" || true
    done
    # Works even if the normal Nginx container is restarting or has exited.
    dc run --rm --no-deps --entrypoint nginx nginx -t || true
    echo '::endgroup::'
}
dc() {
    local limit=15
    case "$1" in
        up) limit=100 ;;
        run) limit=30 ;;
        restart|stop) limit=25 ;;
        down) limit=20 ;;
        ps|logs) limit=5 ;;
    esac
    # Diagnostics get an independent, shorter budget after the main operation times out.
    [[ ${IN_CLEANUP:-0} == 0 || "$1" != run ]] || limit=8
    ci_run "$limit" "Compose $1" docker compose -p "$PROJECT" -f compose.production.yml "$@"
}
cleanup() {
    local status=$?
    trap - EXIT
    trap '' TERM INT
    IN_CLEANUP=1
    export CI_KILL_AFTER=1
    set +e
    ci_progress "smoke finishing (exit $status); diagnostics and cleanup"
    if [[ $status -ne 0 ]]; then diagnostics; fi
    dc down --timeout 10 --remove-orphans || true
    # Timed-out Docker clients do not necessarily stop their one-off containers.
    bash scripts/docker/ci-cleanup.sh "$PROJECT" || { ci_progress 'ERROR CI cleanup failed'; [[ $status -ne 0 ]] || status=1; }
    if [[ "$ROOT" == /tmp/tmp.* && -d "$ROOT" && ! -L "$ROOT" ]]; then
        ci_run 10 'remove isolated fixture files' sudo -n rm -rf -- "$ROOT" || { [[ $status -ne 0 ]] || status=1; }
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 124' TERM
trap 'exit 130' INT
ci_progress 'prepare isolated fake storage'
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
dc config --quiet
dc up -d --wait --wait-timeout 30 runtime
dc run --rm --no-deps supervisor init
dc up -d --wait --wait-timeout 90 web
# Same UID, readonly root, tmpfs and network/DNS as production.
dc run --rm --no-deps --entrypoint nginx nginx -t
dc up -d --wait --wait-timeout 60 nginx
dc exec -T nginx nginx -t
dc exec -T nginx curl -fsS --max-time 3 http://127.0.0.1:8080/healthz
NGINX_ID=$(dc ps --quiet nginx)
[[ $(ci_run 8 'verify Nginx UID and readonly root' docker inspect --format '{{.Config.User}} {{.HostConfig.ReadonlyRootfs}}' "$NGINX_ID") == '10001:10001 true' ]]
dc exec -T nginx sh -ec 'for path in /tmp/client /tmp/fastcgi /tmp/proxy /tmp/uwsgi /tmp/scgi; do test -d "$path" && test -w "$path"; done; test ! -w /etc/nginx/nginx.conf'
dc exec -T web php /opt/app/docker/health.php web
dc exec -T supervisor php /opt/app/docker/health.php supervisor
dc exec -T web php -r 'require "/opt/app/lib.php"; $h=read_json_file("/opt/app/.panel/supervisor.health.json"); exit(is_file("/proc/".$h["pid"]."/cmdline") ? 0 : 1);'
for route in config.php accounts.json .panel/accounts.json vendor/autoload.php docker/config.php; do
    ci_progress "privacy check /$route"
    [[ $(dc exec -T nginx curl -s --max-time 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080/$route") == 404 ]]
done
[[ $(dc exec -T nginx curl -s --max-time 3 -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8080/api.php?action=list_accounts') == 401 ]]
dc exec -T supervisor php -r 'file_put_contents("/opt/app/.panel/persistence-fixture", "preserve");'
ci_progress 'verify persistence across supervisor restart'
dc restart --timeout 10 supervisor
deadline=$((SECONDS + 45)); healthy=0
while ((SECONDS < deadline)); do
    status=0
    dc exec -T supervisor php /opt/app/docker/health.php supervisor || status=$?
    if [[ $status -eq 0 ]]; then healthy=1; break; fi
    [[ $status -ne 124 && $status -ne 137 ]] || exit "$status"
    sleep 2
done
[[ $healthy -eq 1 ]]
dc exec -T web php -r 'exit(file_get_contents("/opt/app/.panel/persistence-fixture")==="preserve" ? 0:1);'
dc exec -T web php /opt/app/docker/health.php supervisor
ci_progress 'verify persistence across full container recreation'
dc stop --timeout 10
dc up -d --force-recreate --wait --wait-timeout 90 nginx
dc exec -T web php -r 'exit(file_get_contents("/opt/app/.panel/persistence-fixture")==="preserve" ? 0:1);'
ci_run 20 'verify image contains no runtime data' docker run --rm --label "com.docker.compose.project=$PROJECT" --entrypoint php "$IMAGE" -r 'exit(is_file("/opt/app/accounts.json") || count(glob("/opt/app/accounts/*")) || count(glob("/opt/app/.panel/*")) ? 1:0);'
echo 'Docker health, shared PID/IPC namespace, isolated persistence and private routes passed.'
