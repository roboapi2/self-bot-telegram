"""Offline CI contracts and real shell timeout tests; no Docker engine required."""
import argparse
import pathlib
import re
import subprocess
import tempfile
import time
import yaml

parser = argparse.ArgumentParser()
parser.add_argument('--bash', default='bash')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
checks = 0

def check(condition, message):
    global checks
    assert condition, message
    checks += 1

smoke = (root / 'scripts/docker/ci-smoke.sh').read_text()
cleanup = (root / 'scripts/docker/ci-cleanup.sh').read_text()
common = (root / 'scripts/docker/ci-common.sh').read_text()
flow = yaml.safe_load((root / '.github/workflows/cicd.yml').read_text())
tests = re.findall(r'^\s*dc run[^\n]*nginx[^\n]*-t[^\n]*$', smoke, re.M)
check(len(tests) == 2, 'Expected main and diagnostic Nginx configuration tests')
for line in tests:
    check('--rm --no-deps --entrypoint nginx nginx -t' in line,
          'Nginx test invokes foreground application entrypoint')
check('timeout --signal=TERM --kill-after="${CI_KILL_AFTER:-5}s"' in common, 'Timeout lacks forced termination')
check('ci_run "$limit"' in smoke and 'run) limit=30' in smoke, 'One-off Compose container unbounded')
check('up) limit=100' in smoke and 'ps|logs) limit=5' in smoke, 'Health/log operations unbounded')
check('|| limit=8' in smoke and 'trap cleanup EXIT' in smoke, 'Independent diagnostic/cleanup budget missing')
check('bash scripts/docker/ci-cleanup.sh "$PROJECT"' in smoke, 'Orphan fallback missing')
check('^ubcc-ci-[0-9]+-[0-9]+$' in cleanup and 'docker rm --force' in cleanup,
      'Cleanup must scope forced removal to disposable CI projects')
check('docker volume' not in cleanup and 'down -v' not in smoke, 'Cleanup deletes persistent volumes')
for source in [smoke, cleanup]:
    for line in source.splitlines():
        if re.search(r'\bdocker (compose|run|inspect|ps|rm|network)\b', line):
            check('ci_run ' in line, 'Unbounded Docker command: ' + line)
validate = flow['jobs']['validate']
check(validate['timeout-minutes'] == 15, 'Validate timeout regressed')
steps = validate['steps']
smoke_step = next(s for s in steps if s.get('name') == 'Runtime Compose smoke and privacy')
check(smoke_step['timeout-minutes'] == 7 and '120s 300s' in smoke_step['run'], 'Smoke budget regressed')
check(steps[-1]['if'] == 'always()' and 'ci-cleanup.sh' in steps[-1]['run'], 'External timeout fallback missing')
for job in flow['jobs'].values():
    for step in job['steps']:
        for line in step.get('run', '').splitlines():
            if re.search(r'\bdocker (run|pull|push|save|load|login|tag|inspect|image|buildx)\b', line):
                check('ci_run ' in line, 'Unbounded workflow Docker command: ' + line)
check(sum('docker/build-push-action@' in s.get('uses', '') for s in steps) == 3,
      'Redundant independent build reintroduced')
workflow = (root / '.github/workflows/cicd.yml').read_text()
for gate in ['Gitleaks', 'Trivy', 'composer audit --locked --no-dev', 'bash scripts/test.sh',
             'bash tests/docker_deploy.sh', 'tests/ignore_release.py', 'REMOTE_ID', 'VERIFIED_ID']:
    check(gate in workflow, 'Required gate/artifact proof removed: ' + gate)

gateway_build = (root / 'docker/gateway/Dockerfile').read_text()
for image in re.findall(r'^FROM (\S+)', gateway_build, re.M):
    check('@sha256:' in image and len(image.split('@sha256:')[1]) == 64, 'Unpinned gateway base')
check('golang:1.26.9-' in gateway_build and 'GOTOOLCHAIN=local' in gateway_build,
      'Patched Go compiler must not silently change')
check('-mod=readonly' in gateway_build and 'go mod verify' in gateway_build,
      'Gateway lockfile verification missing')
check('sha256:' + (root / 'docker/gateway/source.sha256').read_text().strip() in gateway_build,
      'Gateway source archive checksum differs from reviewed lock')
check('github.com/caddyserver/caddy/v2 v2.11.7' in (root / 'docker/gateway/go.mod').read_text(),
      'Stable Caddy dependency version missing from binary metadata')
check('golang.org/x/net v0.60.0' in (root / 'docker/gateway/go.mod').read_text(), 'HTTP/2 patch missing')
check('USER 10001:10001' in gateway_build and 'apk add' not in gateway_build,
      'Gateway needs non-root execution and fixed base contents')
compose = (root / 'compose.production.yml').read_text()
check('${UBCC_GATEWAY_IMAGE:?' in compose and 'image: caddy:' not in compose,
      'Production must use the exact verified gateway')
check('vars.PRODUCTION_DEPLOY_ENABLED == \'true\'' in flow['jobs']['deploy']['if'],
      'Production deployment must default to disabled')
for name in ['validate', 'publish', 'rolling']:
    check('PRODUCTION_DEPLOY_ENABLED' not in flow['jobs'][name].get('if', ''), 'Opt-in disabled publishing/validation')
check('gateway_digest' in flow['on']['workflow_dispatch']['inputs'] and 'REQUESTED_GATEWAY' in workflow,
      'Manual runtime digest lacks validated companion gateway')
check('REMOTE_GATEWAY_ID' in workflow and 'VERIFIED_GATEWAY_ID' in workflow,
      'Gateway artifact identity is not verified')
check('"$GATEWAY_IMAGE@$GATEWAY_DIGEST"' in workflow and 'GATEWAY_IMAGE_DIGEST.txt' in workflow,
      'Gateway digest omitted from publishing/deployment')
deploy = (root / 'scripts/docker/deploy.sh').read_text(encoding='utf-8')
check('UBCC_GATEWAY_IMAGE="$OLD_GATEWAY"' in deploy and 'UBCC_GATEWAY_IMAGE=%s' in deploy,
      'Gateway rollback/state persistence missing')
check('ci-persistence' in smoke and 'verify exact gateway image ID' in smoke,
      'Gateway health/storage/identity checks missing')
check('auto_https off' not in (root / 'docker/Caddyfile').read_text() and
      'bind 127.0.0.1' in (root / 'docker/Caddyfile').read_text(),
      'Production HTTPS disabled or health endpoint exposed')

# Execute the real entrypoint with a fake Nginx: the old command must hang until
# GNU timeout interrupts it; the direct executable must return immediately.
with tempfile.TemporaryDirectory(prefix='ubcc-ci-regression-') as temporary:
    stage = pathlib.Path(temporary)
    (stage / 'nginx').write_text('#!/usr/bin/env bash\n'
        'printf "%s\\n" "$*" >> "$UBCC_TRACE"\n'
        'if [[ "$*" == *"daemon off"* ]]; then sleep 30; fi\n', newline='\n')
    (stage / 'nginx').chmod(0o755)
    (stage / 'docker').write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$*" > "$UBCC_TRACE"\n', newline='\n')
    (stage / 'docker').chmod(0o755)
    import os
    env = os.environ.copy()
    env['UBCC_STAGE'] = stage.as_posix()
    env['UBCC_TRACE'] = (stage / 'trace').as_posix()
    prefix = ('if command -v cygpath >/dev/null; then export UBCC_STAGE=$(cygpath -u "$UBCC_STAGE"); '
              'export UBCC_TRACE=$(cygpath -u "$UBCC_TRACE"); fi; export PATH="$UBCC_STAGE:$PATH"; '
              'source scripts/docker/ci-common.sh; ')
    started = time.monotonic()
    result = subprocess.run([args.bash, '-c', prefix +
        "ci_run 1 'old entrypoint reproduction' bash docker/entrypoint.sh nginx -t"],
        cwd=root, env=env, capture_output=True, text=True, timeout=12)
    check(result.returncode == 124 and time.monotonic() - started < 10,
          'Foreground entrypoint regression did not time out quickly')
    trace = (stage / 'trace').read_text()
    check('-t' in trace and "daemon off" in trace, 'Old entrypoint interaction not exercised')
    result = subprocess.run([args.bash, '-c', prefix + "ci_run 1 'direct test' nginx -t"],
        cwd=root, env=env, capture_output=True, text=True, timeout=12)
    check(result.returncode == 0, 'Direct Nginx configuration test failed')
    check(re.search(r'\[\d{4}-\d\d-\d\dT.*Z\] START', result.stderr) is not None,
          'Timestamped progress missing')
    started = time.monotonic()
    result = subprocess.run([args.bash, '-c', prefix +
        "ci_run 1 'stubborn process' bash -c 'trap \"\" TERM; sleep 30'"],
        cwd=root, env=env, capture_output=True, text=True, timeout=12)
    check(result.returncode in (124, 137) and time.monotonic() - started < 10,
          'SIGTERM-resistant process was not forcibly terminated')
    result = subprocess.run([args.bash, '-c', prefix +
        "export CI_DOCKER_PROJECT=ubcc-ci-123-456; ci_run 1 'label one-off' docker run --rm fixture-image"],
        cwd=root, env=env, capture_output=True, text=True, timeout=5)
    check(result.returncode == 0 and
          'run --label com.docker.compose.project=ubcc-ci-123-456 --rm fixture-image' in (stage / 'trace').read_text(),
          'Timed-out workflow one-offs cannot be found by cleanup')
    result = subprocess.run([args.bash, '-c',
        'bash scripts/docker/ci-cleanup.sh production'], cwd=root,
        capture_output=True, text=True, timeout=5)
    check(result.returncode == 1 and 'Refusing non-CI project' in result.stderr,
          'Cleanup accepted production project')
print(f'{checks} CI regression checks passed; mocked Nginx and real GNU timeout, no Docker execution')
