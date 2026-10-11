"""Execute the real publish shell with fake registry clients and isolated artifacts."""
import argparse
import hashlib
import os
import pathlib
import shutil
import subprocess
import tempfile
import yaml

parser = argparse.ArgumentParser()
parser.add_argument('--bash', default='bash')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
flow = yaml.safe_load((root / '.github/workflows/cicd.yml').read_text())
body = next(s['run'] for s in flow['jobs']['publish']['steps'] if s.get('id') == 'publish')
checks = 0
for mismatch in ['none', 'runtime', 'gateway']:
    with tempfile.TemporaryDirectory(prefix='ubcc-publish-pair-') as temporary:
        stage = pathlib.Path(temporary)
        binary = stage / 'bin'
        binary.mkdir()
        scripts = stage / 'scripts/docker'
        scripts.mkdir(parents=True)
        shutil.copyfile(root / 'scripts/docker/ci-common.sh', scripts / 'ci-common.sh')
        (scripts / 'ci-smoke.sh').write_text('#!/usr/bin/env bash\necho "SMOKE $*" >> "$TRACE"\n', newline='\n')
        candidate = stage / 'candidate'
        candidate.mkdir()
        (candidate / 'image.tar').write_bytes(b'isolated public fixture')
        digest = hashlib.sha256((candidate / 'image.tar').read_bytes()).hexdigest()
        (candidate / 'image.tar.sha256').write_text(digest + '  image.tar\n', newline='\n')
        for name, letter in [('IMAGE_ID.txt', 'e'), ('GATEWAY_ID.txt', 'f')]:
            (candidate / name).write_text('sha256:' + letter * 64 + '\n', newline='\n')
        (stage / 'publish.sh').write_text(body, newline='\n')
        (binary / 'jq').write_text('#!/usr/bin/env bash\ncat >/dev/null\nprintf "sha256:%s\\n" "$(printf a%.0s {1..64})"\n', newline='\n')
        # Publish preflight checks these tools; this fixture never uses privileges/API.
        for command in ['gh', 'sudo']:
            (binary / command).write_text('#!/usr/bin/env bash\nexit 0\n', newline='\n')
        (binary / 'docker').write_text('''#!/usr/bin/env bash
echo "$*" >> "$TRACE"
case "$*" in
    'buildx imagetools inspect '*) echo '{"digest":"fixture"}' ;;
    'image inspect '*)
        letter=e
        [[ "$*" != *gateway* ]] || letter=f
        if [[ "$*" == *'ghcr.io/'* && "$MISMATCH" != none ]]; then
            if [[ "$MISMATCH" == gateway && "$*" == *gateway* || "$MISMATCH" == runtime && "$*" != *gateway* ]]; then letter=c; fi
        fi
        printf 'sha256:'; printf "$letter%.0s" {1..64}; echo ;;
    'inspect '*) echo "$GITHUB_SHA" ;;
    'run '*) echo SCAN >> "$TRACE" ;;
esac
exit 0
''', newline='\n')
        for script in binary.iterdir():
            script.chmod(0o755)
        env = os.environ.copy()
        env.update(TEST_STAGE=stage.as_posix(), MISMATCH=mismatch,
                   IMAGE='ghcr.io/roboapi2/self-bot-telegram',
                   GATEWAY_IMAGE='ghcr.io/roboapi2/self-bot-telegram-gateway',
                   GITHUB_SHA='1' * 40, GITHUB_REF='refs/heads/main', TRIVY_IMAGE='fixture-scanner')
        command = ('if command -v cygpath >/dev/null; then TEST_STAGE=$(cygpath -u "$TEST_STAGE"); fi; '
                   'export PATH="$TEST_STAGE/bin:$PATH" RUNNER_TEMP="$TEST_STAGE" '
                   'TRACE="$TEST_STAGE/trace" GITHUB_OUTPUT="$TEST_STAGE/output"; bash publish.sh')
        result = subprocess.run([args.bash, '-c', command], cwd=stage, env=env,
                                capture_output=True, text=True, timeout=30)
        assert result.returncode == 0, result.stdout + result.stderr
        trace = (stage / 'trace').read_text()
        expected = 0 if mismatch == 'none' else 2
        assert trace.splitlines().count('SCAN') == expected, 'Image pair did not receive required security scans'
        assert trace.count('SMOKE ') == (0 if mismatch == 'none' else 1), 'Pair smoke omitted/repeated incorrectly'
        for name in ['IMAGE_DIGEST.txt', 'GATEWAY_IMAGE_DIGEST.txt']:
            assert '@sha256:' in (candidate / name).read_text(), 'Release companion digest missing'
        assert 'gateway_digest=sha256:' in (stage / 'output').read_text(), 'Deployment gateway output missing'
        checks += 6
print(f'{checks} publish artifact/pair checks passed (mock registry only, no Docker execution)')
