"""Optional network test: Composer install and isolated suite from public source only."""
import argparse
import os
import pathlib
import shutil
import subprocess
import tempfile
import sys
import ast

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--composer', default='/usr/bin/composer')
parser.add_argument('--bash', default='bash')
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='ubcc-clean-install-') as temporary:
    stage = pathlib.Path(temporary).resolve()
    if not stage.is_relative_to(pathlib.Path(tempfile.gettempdir()).resolve()):
        raise SystemExit('Unsafe temporary path')
    tree = ast.parse((root / 'scripts/build-release.py').read_text(encoding='utf-8'))
    files = next(ast.literal_eval(node.value) for node in tree.body if isinstance(node, ast.Assign)
                 and any(isinstance(target, ast.Name) and target.id == 'files' for target in node.targets))
    for relative in files:
        path = root / relative
        if path.is_symlink():
            raise SystemExit('Unsafe source link')
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
    environment = os.environ.copy()
    environment.update(TEST_PHP=args.php, TEST_PYTHON=sys.executable)
    for command in [
        [args.php, args.composer, 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--no-plugins'],
        [args.php, args.composer, 'check-platform-reqs', '--no-dev'],
        [args.bash, 'scripts/test.sh'],
    ]:
        subprocess.run(command, cwd=stage, env=environment, check=True)
    print('Fresh temporary source/dependency installation and suite passed; host=' + sys.platform)
