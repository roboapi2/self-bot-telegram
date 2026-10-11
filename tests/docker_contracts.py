"""Offline contracts; actual Docker/Compose smoke is a separate Linux CI gate."""
import ast
import pathlib
import re
import subprocess
import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
checks = 0
def check(ok, label):
    global checks
    if not ok:
        raise AssertionError(label)
    checks += 1
def read(path):
    return (ROOT / path).read_text(encoding='utf-8')
tree = ast.parse(read('scripts/build-release.py'))
files = next(ast.literal_eval(n.value) for n in tree.body if isinstance(n, ast.Assign)
             and any(isinstance(t, ast.Name) and t.id == 'files' for t in n.targets))
check(len(files) == len(set(files)), 'duplicate release entry')
for path in files:
    check((ROOT / path).is_file() and not (ROOT / path).is_symlink(), 'missing/unsafe ' + path)
    if path.endswith(('.sh', '.yml', '.conf', '.service')):
        check(b'\r\n' not in (ROOT / path).read_bytes(), 'CRLF ' + path)
lines = [x.strip() for x in read('.dockerignore').splitlines() if x.strip() and not x.startswith('#')]
check(lines[0] == '**', 'Docker context must default deny')
allowed = {x[1:] for x in lines[1:] if x.startswith('!') and not x.endswith('/')}
check(all((x.startswith('!') and '*' not in x) or x.endswith('/**') for x in lines[1:]), 'context must use literal exceptions and directory resets')
for line in lines[1:]:
    if line.startswith('!') and line.endswith('/'):
        check(line[1:] + '**' in lines, 'directory must re-exclude unreviewed descendants')
check(allowed == set(files) | {'.env.example'}, 'Docker/release allowlists differ')
check(not ({'config.php', 'accounts.json', '.env', 'vendor', '.git'} & allowed), 'private context path')
for path in ['scripts/private.json', 'docker/private.key', 'docs/local.env', 'accounts/session.madeline']:
    check(path not in allowed, 'private canary context ' + path)
check(read('.gitignore') == read('.ignore'), 'ignore parity')
dockerfile = read('Dockerfile')
check('USER 10001:10001' in dockerfile and 'FROM runtime AS production' in dockerfile, 'non-root final stage')
for base in re.findall(r'^FROM (\S+)', dockerfile, re.M):
    check(':' not in base or '@sha256:' in base, 'unpinned base')
compose = read('compose.production.yml')
spec = yaml.safe_load(compose)
check(set(spec['services']) == {'runtime', 'supervisor', 'web', 'nginx', 'gateway'}, 'Compose service inventory')
for name, service in spec['services'].items():
    check(service['user'] == '10001:10001' and service['read_only'] is True,
          'non-root readonly ' + name)
    check(service['cap_drop'] == ['ALL'] and 'healthcheck' in service and service['restart'] == 'unless-stopped',
          'service isolation/health ' + name)
    check(not any('/opt/app/public' in v['target'] for v in service.get('volumes', [])), 'data in public root')
    check(name == 'gateway' or 'ports' not in service, 'public internal port')
check(spec['services']['gateway']['ports'] == ['80:8080', '443:8443'], 'TLS public ports')
check(compose.count('pid: service:runtime') == 2 and 'pid: host' not in compose, 'shared isolated PID namespace')
check('docker.sock' not in compose and 'privileged:' not in compose, 'privileged app')
check('create_host_path: false' in compose and '/run/secrets/config.php' in compose, 'protected binds')
check('stop_grace_period: 140s' in compose, 'graceful stop')
workflow = read('.github/workflows/cicd.yml')
flow = yaml.safe_load(workflow)
check(set(flow['on']) == {'pull_request', 'push', 'workflow_dispatch'}, 'workflow triggers')
check(flow['jobs']['deploy']['environment'] == 'production', 'protected deployment environment')
check(flow['jobs']['deploy']['needs'] == ['validate', 'publish', 'rolling'], 'release before deploy')
check(flow['jobs']['deploy']['concurrency']['cancel-in-progress'] is False, 'do not cancel an active deployment')
for action in re.findall(r'uses: ([^\s]+)', workflow):
    check(re.search(r'@[0-9a-f]{40}$', action) is not None, 'unverified action pin')
check('StrictHostKeyChecking=yes' in workflow and 'ssh-keyscan' not in workflow, 'SSH pinning')
check('release:\n' not in workflow and 'cancel-in-progress: true' not in workflow, 'deployment loop/cancellation')
deploy = read('scripts/docker/deploy.sh')
check(deploy.index('snapshot\n') < deploy.index('supervisor init'), 'backup before migration')
check('--force-recreate' in deploy and 'rsync' not in deploy and 'down -v' not in deploy, 'rollback preserves data')
check('SEQUENCE -le $LAST' in deploy and 'flock -n' in read('scripts/docker/common.sh'), 'deployment serialization')
result = subprocess.run(['git', 'ls-files', '-z'], cwd=ROOT, capture_output=True, check=True)
ignored_tracked = subprocess.run(['git', 'ls-files', '--cached', '--ignored', '--exclude-standard', '-z'],
                                cwd=ROOT, capture_output=True, check=True)
check(not ignored_tracked.stdout, 'tracked file violates ignore rules; inspect git ls-files -ci --exclude-standard')
for path in result.stdout.decode().split('\0'):
    if not path:
        continue
    check(path not in {'config.php', 'accounts.json', '.env'} and
          not path.startswith(('accounts/', '.panel/', 'secrets/', 'data/', 'backups/', 'releases/', 'vendor/')),
          'tracked private path: ' + path)
print(f'{checks} offline Docker/deployment contracts passed; no Docker execution claimed')
