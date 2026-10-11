"""Exercise the smoke failure trap with fake Docker; never starts a container."""
import argparse
import os
import pathlib
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--bash', default='bash')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='ubcc-smoke-diagnostics-') as temporary:
    stage = pathlib.Path(temporary)
    binary = stage / 'bin'
    binary.mkdir()
    docker = binary / 'docker'
    docker.write_bytes(b'''#!/usr/bin/env bash
echo "MOCK: $*" >&2
echo "$*" >> "$TMPDIR/commands"
case "$*" in
    *'ps --all --quiet'*) echo fixture-container; exit 0 ;;
    *'ps --all'*) echo STATUS_DIAGNOSTIC; exit 9 ;;
    *'logs --no-color --tail 150'*) echo LOG_DIAGNOSTIC; exit 17 ;;
    inspect*) echo HEALTH_DIAGNOSTIC; exit 1 ;;
    *'nginx nginx -t'*) echo NGINX_TEST_DIAGNOSTIC; exit 7 ;;
    *'down --remove-orphans'*) touch "$TMPDIR/cleanup-marker"; exit 0 ;;
    *'up -d --wait --wait-timeout 60 runtime'*) exit 23 ;;
esac
exit 0
''')
    sudo = binary / 'sudo'
    # No privileged command is executed by this test. TemporaryDirectory owns cleanup.
    sudo.write_bytes(b'#!/usr/bin/env bash\nexit 0\n')
    docker.chmod(0o755)
    sudo.chmod(0o755)
    environment = os.environ.copy()
    environment['UBCC_DIAGNOSTICS_BIN'] = binary.as_posix()
    environment['TMPDIR'] = stage.as_posix()
    result = subprocess.run([args.bash, '-c',
        'test_bin="$UBCC_DIAGNOSTICS_BIN"; '
        'if command -v cygpath >/dev/null; then test_bin=$(cygpath -u "$test_bin"); export TMPDIR=$(cygpath -u "$TMPDIR"); fi; '
        'export PATH="$test_bin:$PATH"; bash scripts/docker/ci-smoke.sh fixture-image'],
        cwd=root, env=environment, capture_output=True, text=True)
    output = result.stdout + result.stderr
    assert result.returncode == 23, 'Diagnostics/cleanup masked the original smoke failure'
    for marker in ['STATUS_DIAGNOSTIC', 'LOG_DIAGNOSTIC', 'HEALTH_DIAGNOSTIC',
                   'NGINX_TEST_DIAGNOSTIC']:
        assert marker in output, 'Missing failure output: ' + marker
    assert (stage / 'cleanup-marker').is_file(), 'Cleanup was not attempted'
    commands = (stage / 'commands').read_text()
    assert commands.index('logs --no-color') < commands.index('down --remove-orphans'), 'Containers removed before diagnostics'
    assert '.Config.Env' not in output, 'Full environment leaked through inspect'
    print('8 smoke failure diagnostic checks passed (mock only; Docker not executed)')
