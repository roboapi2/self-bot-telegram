"""Build a source-only release from an explicit allowlist; never read private data."""
import hashlib
import pathlib
import shutil
import tarfile
import sys
import os
import gzip

root = pathlib.Path(__file__).resolve().parent.parent
name = sys.argv[1] if len(sys.argv) > 1 else 'ubcc-20261011'
if not name.startswith('ubcc-') or any(c not in 'abcdefghijklmnopqrstuvwxyz0123456789-' for c in name):
    raise SystemExit('Invalid release name')
destination = root / 'releases' / name
if destination.exists():
    raise SystemExit('Release already exists; use a new name or review/remove the generated release manually')
files = ['index.php', 'api.php', 'lib.php', 'runtime.php', 'worker.php', 'supervisor.php',
         'console.php', 'madeline_tools.php', 'qr_worker.php', 'bot_template.php',
         'config.example.php', 'composer.json', 'composer.lock', 'install.sh', 'run.sh',
         'README_FA.md', 'AUDIT_FA.md', '.gitignore', '.ignore', 'CHANGELOG.md',
         'assets/app.js', 'assets/styles.css',
         'scripts/backup.sh', 'scripts/build-release.py', 'scripts/common.sh',
         'scripts/control.sh', 'scripts/install-vps.sh', 'scripts/migrate-storage.php',
         'scripts/restore.sh', 'scripts/test.sh', 'scripts/update.sh', 'scripts/validate-vps.sh',
         'deploy/logrotate.conf.in', 'deploy/nginx.conf.in', 'deploy/php-fpm.conf.in',
         'deploy/userbot-supervisor.service.in',
         'tests/clean_install.py', 'tests/concurrency.php', 'tests/linux_lifecycle.php',
         'tests/migration.php', 'tests/security_http.py', 'tests/unit.php', 'tests/ignore_release.py',
         'docs/INSTALL_FA.md', 'docs/OPERATIONS_FA.md', 'docs/FINAL_AUDIT_FA.md', 'docs/IGNORE_AUDIT_FA.md',
         'Dockerfile', '.dockerignore', 'compose.production.yml', '.github/workflows/cicd.yml',
         'docker/config.php', 'docker/entrypoint.sh', 'docker/health.php', 'docker/nginx.conf',
         'docker/php-fpm.conf', 'docker/Caddyfile', 'docker/ubcc-docker.service',
         'scripts/docker/common.sh', 'scripts/docker/setup.sh', 'scripts/docker/deploy.sh',
         'scripts/docker/control.sh', 'scripts/docker/restore.sh', 'scripts/docker/ssh-deploy.sh',
         'scripts/docker/ci-smoke.sh', 'tests/docker_contracts.py', 'tests/docker_deploy.sh',
         'docs/DOCKER_INSTALL_FA.md', 'docs/CICD_FA.md', 'docs/PRODUCTION_DEPLOY_FA.md', 'docs/ROLLBACK_FA.md',
         '.gitattributes', 'docker/php.ini', 'tests/docker_config.php', 'docs/DOCKER_AUDIT_FA.md', 'docs/LIFECYCLE_AUDIT_FA.md',
         'tests/smoke_diagnostics.py', 'tests/ci_pipeline.py',
         'scripts/docker/ci-common.sh', 'scripts/docker/ci-cleanup.sh',
         'docker/gateway/Dockerfile', 'docker/gateway/go.mod', 'docker/gateway/go.sum',
         'docker/gateway/source.sha256', 'docker/gateway/main.go', 'tests/gateway_publish.py',
         'tests/madeline_logger.php', 'tests/madeline_readonly.php',
         'madeline_bootstrap.php', 'scripts/patch-madeline.php', 'tests/bot_contract.php',
         'tests/compose_lifecycle.php', 'tests/fixtures/bot_worker.php', 'tests/frontend_lifecycle.js']
# Optional reviewed configuration example; private .env files are never included.
if (root / '.env.example').is_file():
    files.append('.env.example')
for relative in files:
    source = root / relative
    if not source.is_file() or source.is_symlink():
        raise SystemExit('Missing/unsafe source: ' + relative)
    if relative in {'config.php', 'accounts.json'} or source.suffix in {'.log', '.pid'}:
        raise SystemExit('Private file in allowlist')
    if source.suffix in {'.sh', '.in'} and b'\r\n' in source.read_bytes():
        raise SystemExit('Use LF line endings before releasing: ' + relative)
destination.mkdir(parents=True)
lines = []
for relative in files:
    target = destination / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(root / relative, target)
    lines.append(hashlib.sha256(target.read_bytes()).hexdigest() + '  ' + relative)
(destination / 'SHA256SUMS').write_text('\n'.join(lines) + '\n', encoding='utf-8')
archive = destination.with_suffix('.tar.gz')
def normalize(item):
    item.uid = item.gid = 0
    item.uname = item.gname = 'root'
    item.mode = 0o755 if item.isdir() else 0o644
    item.mtime = int(os.environ.get('SOURCE_DATE_EPOCH', '0'))
    return item

with archive.open('wb') as stream:
    with gzip.GzipFile(filename='', mode='wb', fileobj=stream,
                       mtime=int(os.environ.get('SOURCE_DATE_EPOCH', '0'))) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as output:
            output.add(destination, arcname=name, filter=normalize)
with tarfile.open(archive, 'r:gz') as output:
    expected = {name + '/' + path for path in files} | {name + '/SHA256SUMS'}
    actual = {item.name for item in output.getmembers() if item.isfile()}
    if actual != expected:
        raise SystemExit('Release manifest mismatch')
archive.with_suffix(archive.suffix + '.sha256').write_text(
    hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n', encoding='utf-8')
print(f'{len(files)} source files; archive manifest verified: {archive}')

