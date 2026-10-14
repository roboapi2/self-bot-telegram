"""Run against an isolated fixture. Never reads or alters real credentials/accounts."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
php = os.environ.get('TEST_PHP', 'php')
checks = 0

def check(value, label):
    global checks
    if not value:
        raise AssertionError(label)
    checks += 1

with tempfile.TemporaryDirectory(prefix='ubcc-http-') as work:
    fixture = Path(work)
    for src in root.glob('*.php'):
        if src.name != 'config.php':
            shutil.copyfile(src, fixture / src.name)
    shutil.copytree(root / 'assets', fixture / 'assets')
    (fixture / 'accounts').mkdir()
    (fixture / 'accounts.json').write_text('[]')
    (fixture / 'config.php').write_text("<?php return ['admin_password'=>'fixture-strong-password','base_dir'=>__DIR__];")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', 'index.php'], cwd=fixture, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f'http://127.0.0.1:{port}'
    def client():
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(opener, path, data=None):
        body = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            with opener.open(base + path, body, timeout=10) as result:
                return result.status, result.read().decode('utf-8'), result.headers
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode('utf-8'), error.headers
    def token(body):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
    try:
        c = client()
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError('Test server exited')
            try:
                _, login, _ = request(c, '/')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('Test server did not start')
        # Execute the exact PHP client used by Compose against the real login/API.
        shutil.copyfile(root / 'tests/http_client.php', fixture / 'http_client.php')
        regression = r'''<?php
ini_set('zend.exception_ignore_args', '1');
require __DIR__.'/http_client.php';
$base = getenv('FIXTURE_HTTP_BASE');
function require_fixture(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); }
$cookie = ''; $login = fixture_http($base, '/', $cookie);
$old = $cookie; $csrf = fixture_token($login['body'], false);
// Reproduce the old PHP stream client: the redirect retains its pre-login cookie.
$opts = ['http'=>['method'=>'POST','timeout'=>10,'ignore_errors'=>true,
 'header'=>"Cookie: $old\r\nContent-Type: application/x-www-form-urlencoded\r\n",
 'content'=>http_build_query(['panel_password'=>'fixture-strong-password','csrf'=>$csrf])]];
$body = file_get_contents($base.'/', false, stream_context_create($opts));
require_fixture(preg_match('/csrf\s*:\s*"([a-f0-9]+)"/', $body) === 0, 'old redirect defect not reproduced');
fixture_http($base, '/api.php?action=accounts', $old, null, 401);
echo "Old PHP redirect flow reproduced; old session is unauthenticated\n";
$cookie = ''; $csrf = fixture_login($base, $cookie, 'fixture-strong-password');
$created = fixture_json(fixture_http($base, '/api.php?action=create_account', $cookie,
 ['key'=>'php-client','name'=>'fixture','csrf'=>$csrf])['body']);
require_fixture($created['ok'] && ($created['account']['key']??'') === 'php-client', 'authenticated first account creation failed');
fixture_http($base, '/api.php?action=create_account', $cookie, ['key'=>'blocked','name'=>'fixture','csrf'=>'invalid'], 403);
require_fixture(fixture_json(fixture_http($base, '/api.php?action=remove', $cookie, ['key'=>'php-client','csrf'=>$csrf])['body'])['ok'], 'fixture cleanup failed');
fixture_http($base, '/api.php?action=logout', $cookie, ['csrf'=>$csrf]);
fixture_http($base, '/api.php?action=accounts', $cookie, null, 401);
foreach ([['body'=>'PRIVATE_BODY_CANARY','token'=>true], ['body'=>'PRIVATE_BODY_CANARY','token'=>false]] as $case) {
 try { fixture_token($case['body'], $case['token']); throw new LogicException('missing token accepted'); }
 catch (RuntimeException $e) { require_fixture(!str_contains($e->getMessage(), 'PRIVATE_BODY_CANARY'), 'diagnostic leaked body'); }
}
try { fixture_json('PRIVATE_BODY_CANARY'); throw new LogicException('bad JSON accepted'); }
catch (RuntimeException $e) { require_fixture(!str_contains($e->getMessage(), 'PRIVATE_BODY_CANARY'), 'JSON diagnostic leaked body'); }
echo "Shared PHP client regeneration, redirect, authenticated API, CSRF and safe errors passed\n";
'''
        runner = fixture / 'http_regression.php'
        runner.write_text(regression, encoding='utf-8')
        result = subprocess.run([php, str(runner)], cwd=fixture,
                                env={**os.environ, 'FIXTURE_HTTP_BASE':base},
                                capture_output=True, text=True, timeout=45)
        check(result.returncode == 0, 'PHP session redirect regression failed (response contents withheld)')
        check('Old PHP redirect flow reproduced' in result.stdout, 'original PHP redirect defect reproduced')
        check('Shared PHP client regeneration' in result.stdout, 'Compose client authenticated API regression passed')
        for path in ['/accounts.json', '/config.php', '/lib.php', '/bot_template.php', '/qr_worker.php', '/composer.lock', '/.panel/accounts.json', '/MadelineProto.log', '/assets/../accounts.json', '/%2e%2e/config.php']:
            check(request(c, path)[0] == 404, 'private route ' + path)
        check(request(c, '/assets/app.js')[0] == 200, 'public JS')
        check(request(c, '/api.php?action=accounts')[0] == 401, 'authentication required')
        _, dashboard, _ = request(c, '/', {'panel_password': 'fixture-strong-password', 'csrf': token(login)})
        csrf = re.search(r'csrf\s*:\s*"([a-f0-9]+)"', dashboard).group(1)
        check('lang="fa" dir="rtl"' in dashboard, 'Persian panel retained')
        for action in ['start', 'stop', 'remove', 'logout', 'archive_clear', 'qr_start', 'create_account']:
            check(request(c, '/api.php?action=' + action)[0] == 405, 'GET mutation blocked: ' + action)
        check(request(c, '/api.php?action=create_account', {'name': 'test', 'key': 'test'})[0] == 403, 'CSRF required')
        code, body, _ = request(c, '/api.php?action=create_account', {'name': 'تست', 'key': 'test', 'csrf': csrf})
        check(code == 200 and body.startswith('{'), 'create JSON response invalid (body withheld)')
        check(json.loads(body)['ok'], 'create account with CSRF')
        code, body, _ = request(c, '/api.php?action=accounts')
        check(len(json.loads(body)['accounts']) == 1, 'account persisted')
        status = json.loads(body)['accounts'][0]['status']
        check(status['state'] == 'stopped' and not status['running'], 'API distinguishes readiness from request')
        (fixture / 'accounts/test/session.madeline').write_text('SYNTHETIC_SESSION')
        (fixture / 'accounts/test/qr_state.json').write_text('{"logged_in":true}')
        _, invalid_start, _ = request(c, '/api.php?action=start', {'key':'test', 'csrf':csrf})
        check(not json.loads(invalid_start)['ok'] and not (fixture / 'accounts/test/enabled').exists(), 'invalid API configuration cannot queue a worker')
        code, _, _ = request(c, '/api.php?action=account&key=../test')
        check(code == 400, 'account traversal blocked')
        request(c, '/api.php?action=logout', {'csrf': csrf})
        check(request(c, '/api.php?action=accounts')[0] == 401, 'logout invalidates auth')
        # Each failure has a fresh cookie jar. Lockout must persist outside sessions.
        for _ in range(5):
            c = client()
            _, login, _ = request(c, '/')
            request(c, '/', {'csrf': token(login), 'panel_password': 'wrong'})
        c = client()
        _, login, _ = request(c, '/')
        _, body, _ = request(c, '/', {'csrf': token(login), 'panel_password': 'fixture-strong-password'})
        check('تلاش زیاد' in body, 'cookie reset cannot bypass throttle')
        print(f'{checks} HTTP checks passed')
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
