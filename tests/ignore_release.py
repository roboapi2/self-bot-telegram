"""Optional developer test: Git-style ignores and source-only release contents."""
import argparse
import ast
import hashlib
import pathlib
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import uuid

ROOT = pathlib.Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--archive', action='append', default=[])
args = parser.parse_args()
rg = shutil.which('rg')
if not rg:
    raise SystemExit('ripgrep required for this optional development test')
if (ROOT / '.gitignore').read_bytes() != (ROOT / '.ignore').read_bytes():
    raise AssertionError('Keep .gitignore and .ignore rules synchronized')
tree = ast.parse((ROOT / 'scripts/build-release.py').read_text(encoding='utf-8'))
files = next(ast.literal_eval(node.value) for node in tree.body if isinstance(node, ast.Assign)
             and any(isinstance(target, ast.Name) and target.id == 'files' for target in node.targets))
if (ROOT / '.env.example').is_file():
    files.append('.env.example')
allowed = set(files) | {'.env.example'}

ignored = ['config.php', 'config.php.bak', 'accounts.json', 'accounts.json.saved', '.env', '.env.local',
           'nested/.env.production', 'accounts/legacy/bot.php', 'accounts/legacy/session.madeline/db',
           'accounts/legacy/deleted_archive.json', 'accounts/legacy/chat_media/photo.jpg',
           '.panel/accounts.json', '.panel/storage.initialized', '.panel/backups/backup.tar.gz',
           '.panel/sessions/sess_fixture', '.panel/throttle/ip.json', 'session.madeline',
           'session.madeline.lock', 'qr_2fa.secret', 'qr_state.json', 'worker.pid', 'accounts.lock',
           'state.json.tmp.123', 'state.lock.tmp', 'MadelineProto.log', 'MadelineProto.log.1.gz',
           'config.backup', 'backups/private.tar.gz', 'releases/fixture.tar.gz',
           'backup_beta/old.php', 'extracted/old.php', 'vendor/autoload.php',
           'tests/__pycache__/fixture.pyc', 'tests/fixture.pyo', '.claude/settings.local.json',
           'audit/phase2-verification.json', 'graphify-out/graph.json', 'graphify-out/.graphify_detect.json',
           'data/accounts/session.madeline', 'secrets/config.php', 'state/current.env',
           'settings.env', 'compose.override.yml', 'compose.override.yaml']
visible = files + ['.env.example', 'nested/.env.example', 'tests/fixtures/sanitized/config.php',
                   'tests/fixtures/sanitized/accounts.json', 'tests/fixtures/sanitized/archive.json',
                   'legacy-src/reviewed-bot.php', '.claude/commands/review.md',
                   'graphify-out/ARCHITECTURE_FA.md', 'graphify-out/GRAPH_REPORT.md']
canary = b'IGNORE_AUDIT_PRIVATE_' + uuid.uuid4().hex.encode('ascii')

def inspect_archive(path, secrets=()):
    with tarfile.open(path, 'r:gz') as archive:
        payload = {}
        for member in archive.getmembers():
            parts = pathlib.PurePosixPath(member.name).parts
            if not parts or '..' in parts or pathlib.PurePosixPath(member.name).is_absolute():
                raise AssertionError('Unsafe archive path')
            if not (member.isfile() or member.isdir()):
                raise AssertionError('Unexpected archive link/device')
            if member.isfile():
                relative = '/'.join(parts[1:])
                if relative not in allowed | {'SHA256SUMS'} or relative in payload:
                    raise AssertionError('Unexpected or duplicate archive member: ' + relative)
                data = archive.extractfile(member).read()
                if canary in data or any(secret in data for secret in secrets):
                    raise AssertionError('Private canary/local credential in archive; value suppressed')
                payload[relative] = data
        manifest = {}
        for line in payload['SHA256SUMS'].decode('utf-8').splitlines():
            digest, relative = line.split('  ', 1)
            manifest[relative] = digest
        if set(payload) != set(manifest) | {'SHA256SUMS'}:
            raise AssertionError('Archive manifest membership mismatch')
        for relative, digest in manifest.items():
            if hashlib.sha256(payload[relative]).hexdigest() != digest:
                raise AssertionError('Archive member checksum mismatch: ' + relative)
        for required in ['composer.json', 'composer.lock', 'config.example.php', 'index.php',
                         'scripts/install-vps.sh', 'deploy/nginx.conf.in', 'docs/INSTALL_FA.md']:
            if required not in payload:
                raise AssertionError('Missing required installation source')
    checksum = path.with_suffix(path.suffix + '.sha256')
    if hashlib.sha256(path.read_bytes()).hexdigest() != checksum.read_text().split()[0]:
        raise AssertionError('Archive SHA256 sidecar mismatch')
    return len(manifest)

with tempfile.TemporaryDirectory(prefix='ubcc-ignore-test-') as temporary:
    stage = pathlib.Path(temporary).resolve()
    if not stage.is_relative_to(pathlib.Path(tempfile.gettempdir()).resolve()):
        raise AssertionError('Unsafe temporary path')
    # Only public allowlisted source is copied; real config/accounts are never copied.
    for relative in files:
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / relative, target)
    for relative in ignored + [p for p in visible if p not in files]:
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(canary if relative in ignored else b'sanitized fixture')
    for flags in [['--no-require-git', '--no-ignore-dot'], []]:
        result = subprocess.run([rg, '--files', '--hidden'] + flags, cwd=stage,
                                check=True, capture_output=True, text=True)
        discovered = {line.replace('\\', '/') for line in result.stdout.splitlines()}
        for relative in ignored:
            if relative in discovered:
                raise AssertionError('Sensitive path is visible: ' + relative)
        for relative in visible:
            if relative not in discovered:
                raise AssertionError('Required/sanitized source is hidden: ' + relative)
    # Poison allowed directory trees: folder-based packaging would leak these files.
    for relative in ['docs/local.env', 'docs/private.tar.gz', 'scripts/qr_2fa.secret',
                     'assets/private.json', 'tests/private-config.php']:
        (stage / relative).write_bytes(canary)
    subprocess.run([sys.executable, str(stage / 'scripts/build-release.py'), 'ubcc-fixture'],
                   cwd=stage, check=True, capture_output=True, text=True)
    count = inspect_archive(stage / 'releases/ubcc-fixture.tar.gz')
    if count != len(files) + int('.env.example' not in files):
        raise AssertionError('Explicit source list mismatch')
    print(f'{len(ignored)} ignored / {len(visible)} eligible checks passed in BOTH Git-style and .ignore modes; fixture release: {count} verified files')

# Inspect known long literal credentials without printing their values or executing PHP.
secrets = []
config = ROOT / 'config.php'
if config.is_file():
    text = config.read_text(encoding='utf-8')
    for match in re.finditer(r"['\"](?:api_hash|admin_password)['\"]\s*=>\s*'((?:\\.|[^'\\])*)'", text):
        value = match.group(1).replace("\\'", "'").replace('\\\\', '\\')
        if len(value) >= 12 and value != 'CHANGE_THIS_PASSWORD':
            secrets.append(value.encode('utf-8'))
for supplied in args.archive:
    archive = pathlib.Path(supplied).resolve()
    print(f'Archive inspected: {archive.name}; {inspect_archive(archive, secrets)} file hashes and SHA256 verified; no disallowed paths/known credential matches')
