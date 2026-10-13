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

smoke = (root / 'scripts/docker/ci-smoke.sh').read_text(encoding='utf-8')
cleanup = (root / 'scripts/docker/ci-cleanup.sh').read_text(encoding='utf-8')
common = (root / 'scripts/docker/ci-common.sh').read_text(encoding='utf-8')
flow = yaml.safe_load((root / '.github/workflows/cicd.yml').read_text(encoding='utf-8'))
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
package_step = next(s for s in steps if s.get('name') == 'Create and verify clean deployment package')
package_index = steps.index(package_step)
check(next(i for i, s in enumerate(steps) if s.get('name') == 'Build CI and shared runtime layers once') < package_index,
      'Package verifier runs before its CI image exists')
for name in ['Build patched stable Caddy gateway', 'Runtime Compose smoke and privacy']:
    check(package_index < next(i for i, s in enumerate(steps) if s.get('name') == name),
          'Packaging errors must fail before expensive gateway/smoke gates')
verification = next(line for line in package_step['run'].splitlines() if 'tests/ignore_release.py' in line)
for required in ['docker run', 'ubcc-ci bash -ec', '-v "$PWD:/workspace:ro"', '-w /workspace',
                 'ci_require python3 rg', '--archive "releases/ubcc-$PACKAGE_SHA.tar.gz"']:
    check(required in verification, 'Archive verifier environment contract missing: ' + required)
check(sum('tests/ignore_release.py' in s.get('run', '') for s in steps) == 1,
      'Host/duplicate archive verifier invocation reintroduced')
contracts_step = next(s for s in steps if s.get('name') == 'Contracts and tracked private files')
check('ci_require bash python3 git timeout date docker sha256sum' in contracts_step['run'],
      'Host test prerequisites not checked')
check('python3 ripgrep git' in (root / 'Dockerfile').read_text(encoding='utf-8'), 'CI image lost packaging tools')
publish_body = next(s['run'] for s in flow['jobs']['publish']['steps'] if s.get('id') == 'publish')
rolling_body = next(s['run'] for s in flow['jobs']['rolling']['steps'] if 'run' in s)
for body, required in [(publish_body, ['jq', 'gh', 'sha256sum', 'find', 'grep', 'sudo', 'curl']),
                       (rolling_body, ['gh', 'find'])]:
    tools = next(line.strip().split()[1:] for line in body.splitlines() if line.strip().startswith('ci_require '))
    for command in required:
        check(command in tools, 'Release job missing executable preflight: ' + command)
    check(body.index('ci_require ') < body.index('ci_run '), 'Release preflight occurs after Docker operations')
    check("'verify Docker Buildx plugin' docker buildx version" in body, 'Buildx availability not checked')
check("'verify Docker Compose plugin' docker compose version" in publish_body, 'Publish smoke needs Compose plugin')
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
workflow = (root / '.github/workflows/cicd.yml').read_text(encoding='utf-8')
for gate in ['Gitleaks', 'Trivy', 'composer audit --locked --no-dev', 'bash scripts/test.sh',
             'bash tests/docker_deploy.sh', 'tests/ignore_release.py', 'REMOTE_ID', 'VERIFIED_ID']:
    check(gate in workflow, 'Required gate/artifact proof removed: ' + gate)

gateway_build = (root / 'docker/gateway/Dockerfile').read_text(encoding='utf-8')
for image in re.findall(r'^FROM (\S+)', gateway_build, re.M):
    check('@sha256:' in image and len(image.split('@sha256:')[1]) == 64, 'Unpinned gateway base')
check('golang:1.26.9-' in gateway_build and 'GOTOOLCHAIN=local' in gateway_build,
      'Patched Go compiler must not silently change')
check('-mod=readonly' in gateway_build and 'go mod verify' in gateway_build,
      'Gateway lockfile verification missing')
check('sha256:' + (root / 'docker/gateway/source.sha256').read_text(encoding='utf-8').strip() in gateway_build,
      'Gateway source archive checksum differs from reviewed lock')
check('github.com/caddyserver/caddy/v2 v2.11.7' in (root / 'docker/gateway/go.mod').read_text(encoding='utf-8'),
      'Stable Caddy dependency version missing from binary metadata')
check('golang.org/x/net v0.60.0' in (root / 'docker/gateway/go.mod').read_text(encoding='utf-8'), 'HTTP/2 patch missing')
check('USER 10001:10001' in gateway_build and 'apk add' not in gateway_build,
      'Gateway needs non-root execution and fixed base contents')
compose = (root / 'compose.production.yml').read_text(encoding='utf-8')
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
check('auto_https off' not in (root / 'docker/Caddyfile').read_text(encoding='utf-8') and
      'bind 127.0.0.1' in (root / 'docker/Caddyfile').read_text(encoding='utf-8'),
      'Production HTTPS disabled or health endpoint exposed')

patch = (root / 'scripts/patch-madeline.php').read_text(encoding='utf-8')
check("hash('sha256', $original) !== $hash" in patch, 'Vendor compatibility patch must fail closed on source changes')
check('php scripts/patch-madeline.php' in (root / 'Dockerfile').read_text(encoding='utf-8'), 'Runtime dependency patch missing from build')
check('auto_prepend_file' in patch and "? null : Magic::getcwd()" in patch, 'IPC bootstrap/private cwd protection missing')
check('Magic::$isIpcWorker = true' in (root / 'madeline_bootstrap.php').read_text(encoding='utf-8'),
      'Early bootstrap lost IPC worker identity')
check('Closure::fromCallable' in patch and 'ubcc_madeline_log' in patch, 'Restored logger bypasses redaction callback')
readonly_test = (root / 'tests/madeline_readonly.php').read_text(encoding='utf-8')
for required in ['new \\danog\\MadelineProto\\API', 'python_single_alt', 'getQRSvg', 'unserialize(serialize']:
    check(required in readonly_test, 'Readonly regression stage missing: ' + required)
check('--network none --user 10001:10001 --read-only --cap-drop ALL --security-opt no-new-privileges' in smoke,
      'Readonly regression must use runtime isolation without Telegram network')
check('getenv()' in patch and 'array_merge' in patch, 'IPC child lost putenv account environment')
check("'src/EventHandler.php'" in patch and 'startedInternal = true' in patch and 'ubcc_event_handler_ready' in patch,
      'Readiness must follow vendor handler validation')
check('tests/fixtures/bot_worker.php' in smoke and 'tests/compose_lifecycle.php' in smoke,
      'Production Compose lifecycle fixtures missing')
check('verify_recreate' in smoke and 'multi-account Compose lifecycle' in smoke,
      'Real-process or recreation gate removed')
check('verify_supervisor' in smoke and 'dc kill -s KILL supervisor' in smoke, 'Supervisor crash recovery gate removed')
check('UBCC_WORKER_RUNNER' in (root / 'bot_template.php').read_text(encoding='utf-8'), 'Handler class inclusion executes launcher')
check("require_once __DIR__ . '/bot_template.php'" in (root / 'madeline_bootstrap.php').read_text(encoding='utf-8'),
      'IPC restoration cannot find UserBot class')
runtime = (root / 'runtime.php').read_text(encoding='utf-8')
supervisor = (root / 'supervisor.php').read_text(encoding='utf-8')
check(supervisor.index("'/supervisor.health.json'") > supervisor.index('foreach ($accounts'),
      'Supervisor heartbeat must follow completed account processing')
check('log_unwritable' in runtime and 'worker_bootstrap_failure' in (root / 'worker.php').read_text(encoding='utf-8'),
      'Failures before PHP/PID startup must be observable')
check('process_identity' in runtime and 'attempts >= 3' in runtime and 'heartbeat_timeout' in runtime,
      'Lifecycle readiness or bounded recovery removed')
check("['start', 'stop', 'restart', 'start_all', 'stop_all', 'restart_all', 'qr_start', 'remove']" in (root / 'api.php').read_text(encoding='utf-8'),
      'Lifecycle API again holds data lock during worker shutdown')

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
    for command, expected in [('bash date', 0), ('__ubcc_missing_executable__', 1)]:
        result = subprocess.run([args.bash, '-c', prefix + 'ci_require ' + command],
            cwd=root, env=env, capture_output=True, text=True, timeout=5)
        check(result.returncode == expected, 'Required-command check accepted missing/rejected available tools')
        if expected:
            check('required command not found: __ubcc_missing_executable__' in result.stderr,
                  'Missing prerequisite lacks actionable error')
    started = time.monotonic()
    result = subprocess.run([args.bash, '-c', prefix +
        "ci_run 1 'old entrypoint reproduction' bash docker/entrypoint.sh nginx -t"],
        cwd=root, env=env, capture_output=True, text=True, timeout=12)
    check(result.returncode == 124 and time.monotonic() - started < 10,
          'Foreground entrypoint regression did not time out quickly')
    trace = (stage / 'trace').read_text(encoding='utf-8')
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
          'run --label com.docker.compose.project=ubcc-ci-123-456 --rm fixture-image' in (stage / 'trace').read_text(encoding='utf-8'),
          'Timed-out workflow one-offs cannot be found by cleanup')
    result = subprocess.run([args.bash, '-c',
        'bash scripts/docker/ci-cleanup.sh production'], cwd=root,
        capture_output=True, text=True, timeout=5)
    check(result.returncode == 1 and 'Refusing non-CI project' in result.stderr,
          'Cleanup accepted production project')
print(f'{checks} CI regression checks passed; mocked Nginx and real GNU timeout, no Docker execution')
