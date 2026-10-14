<?php
declare(strict_types=1);
require '/opt/app/lib.php';
require __DIR__ . '/http_client.php';
if (cfg()['admin_password'] !== 'CI_FIXTURE_ONLY_PASSWORD' || (int) cfg()['api_id'] !== 1
    || PHP_OS_FAMILY !== 'Linux' || posix_getuid() !== 10001) throw new RuntimeException('Disposable CI fixture only');
$checks = 0;
$cookie = ''; $csrf = '';
function expect_life(bool $value, string $name): void { global $checks; if (!$value) throw new RuntimeException('Lifecycle: ' . $name); ++$checks; }
function http_life(string $path, ?array $post = null): string {
    global $cookie;
    return fixture_http('http://nginx:8080', $path, $cookie, $post)['body'];
}
function action_life(string $action, string $key): array {
    global $csrf;
    $result = fixture_json(http_life('/api.php?action=' . $action, ['key' => $key, 'name' => $key, 'csrf' => $csrf]));
    if ($action === 'stop' && !$result['ok']) throw new RuntimeException('Fixture account Stop was not accepted');
    return $result;
}
function status_life(string $key): array {
    $result = fixture_json(http_life('/api.php?action=account&key=' . $key));
    $status = $result['account']['status'] ?? null;
    if (!$result['ok'] || !is_array($status)) throw new RuntimeException('Fixture account status missing');
    foreach (['state', 'enabled', 'process_alive', 'running', 'lifecycle'] as $field) {
        if (!array_key_exists($field, $status)) throw new RuntimeException('Fixture account status fields missing');
    }
    if (!is_array($status['lifecycle']) || !is_string($status['state'])
        || !is_bool($status['enabled']) || !is_bool($status['process_alive']) || !is_bool($status['running'])) throw new RuntimeException('Fixture account status types invalid');
    return $status;
}
function await_life(string $key, string $state, int $seconds = 20): array {
    $deadline = hrtime(true) + $seconds * 1000000000;
    do { $s = status_life($key); if ($s['state'] === $state) return $s; usleep(200000); } while (hrtime(true) < $deadline);
    throw new RuntimeException('Lifecycle timeout: ' . json_encode(['key' => $key, 'expected' => $state,
        'state' => $s['state'], 'enabled' => $s['enabled'], 'alive' => $s['process_alive'], 'ready' => $s['running'],
        'stage' => $s['lifecycle']['stage'] ?? null, 'attempts' => $s['lifecycle']['attempts'] ?? 0,
        'retry_at' => $s['lifecycle']['retry_at'] ?? null, 'heartbeat' => $s['lifecycle']['heartbeat'] ?? 0,
        'category' => $s['failure']['category'] ?? null], JSON_THROW_ON_ERROR));
}
$phase = $argv[1] ?? 'exercise';
$csrf = fixture_login('http://nginx:8080', $cookie, 'CI_FIXTURE_ONLY_PASSWORD');
expect_life($csrf !== '', 'regenerated session retrieves authenticated dashboard');
if (in_array($phase, ['verify_recreate', 'verify_supervisor'], true)) {
    expect_life(await_life('life-a', 'running')['running'], 'desired state survives recreation');
    expect_life(await_life('life-b', 'stopped')['running'] === false, 'explicit stop survives recreation');
    expect_life(read_json_file(account_dir('life-a') . '/fixture-config.json') === ['value' => 'private-fixture'], 'persistent data survives recreation');
    if ($phase === 'verify_recreate') {
        expect_life(action_life('stop_all', 'life-a')['ok'], 'bulk stop after recreation');
        expect_life(await_life('life-a', 'stopped')['running'] === false, 'bulk stop clears persistent intent');
    }
    echo "$checks Compose recreation checks passed (synthetic Telegram layer)\n"; exit;
}
expect_life(read_json_file('/opt/app/.panel/supervisor.health.json')['scan_completed'] === true, 'supervisor health reflects completed scan');
foreach (['life-a', 'life-b', 'life-fail', 'life-exit', 'life-delay', 'life-lock', 'life-missing', 'life-permission', 'life-log', 'life-pid', 'life-super-error', 'life-poll'] as $key) {
    expect_life(action_life('create_account', $key)['ok'], 'create via authenticated API');
    file_put_contents(account_paths($key)['session'], 'SYNTHETIC_AUTHORIZED_SESSION');
    json_write_atomic(account_paths($key)['qr_state'], ['logged_in' => true]);
}
foreach (['life-log' => 'log', 'life-pid' => 'pid'] as $key => $field) {
    $file = account_paths($key)[$field];
    file_put_contents($file, ''); chmod($file, 0400);
    expect_life(action_life('start', $key)['ok'], 'pre-PID permission failure requested');
    $failed = await_life($key, 'failed');
    expect_life($failed['failure']['category'] === ($field === 'log' ? 'log_unwritable' : 'worker_bootstrap_failure'), 'pre-PID permission error propagated to status API');
    expect_life(!$failed['running'], 'pre-PID error cannot count as readiness');
    action_life('stop', $key); chmod($file, 0600);
}
$lockFile = account_dir('life-pid') . '/worker.lock'; chmod($lockFile, 0400);
expect_life(action_life('start', 'life-pid')['ok'], 'unwritable lock file requested');
$failedLock = await_life('life-pid', 'failed');
expect_life($failedLock['lifecycle']['stage'] === 'worker_lock' && $failedLock['failure']['category'] === 'worker_bootstrap_failure', 'lock permission is distinct from active flock contention');
action_life('stop', 'life-pid'); chmod($lockFile, 0600);
// Corrupt private state should fail this account while the supervisor processes peers.
file_put_contents(account_paths('life-super-error')['qr_state'], '{broken');
json_write_atomic(account_dir('life-super-error') . '/enabled', true);
lifecycle_write('life-super-error', '', static fn(array $s): array => ['state' => 'queued', 'requested_at' => time()]);
expect_life(await_life('life-super-error', 'failed')['failure']['category'] === 'supervisor_account_error', 'per-account supervisor exception visible');
json_write_atomic(account_dir('life-poll') . '/fixture-mode.json', ['mode' => 'crash_once']);
file_put_contents(account_dir('life-poll') . '/crash-once', 'fixture');
expect_life(action_life('start', 'life-poll')['ok'], 'launch polling crash requested');
$early = await_life('life-poll', 'failed', 8);
expect_life($early['failure']['category'] === 'process_exit' && $early['lifecycle']['retry_at'] > time()
    && $early['lifecycle']['ready_at'] !== null, 'launch polling death gets recoverable retry');
$earlyRecovered = await_life('life-poll', 'running');
expect_life($earlyRecovered['pid'] !== $early['lifecycle']['pid']
    && $earlyRecovered['lifecycle']['identity'] !== $early['lifecycle']['identity'], 'launch polling respawn has new process identity');
json_write_atomic(account_dir('life-fail') . '/fixture-mode.json', ['mode' => 'fail']);
json_write_atomic(account_dir('life-exit') . '/fixture-mode.json', ['mode' => 'early_exit']);
json_write_atomic(account_dir('life-delay') . '/fixture-mode.json', ['mode' => 'delay']);
json_write_atomic(account_dir('life-permission') . '/fixture-mode.json', ['mode' => 'permission']);
unlink(account_paths('life-missing')['session']);
expect_life(!action_life('start', 'life-missing')['ok'], 'missing session rejected instead of queued');
$lock = fopen(account_dir('life-lock') . '/worker.lock', 'c'); flock($lock, LOCK_EX);
expect_life(action_life('start', 'life-lock')['ok'], 'contention request recorded');
expect_life(await_life('life-lock', 'failed')['failure']['category'] === 'lock_contention', 'lock contention visible');
action_life('stop', 'life-lock'); flock($lock, LOCK_UN); fclose($lock);
expect_life(action_life('start', 'life-permission')['ok'], 'readonly failure request recorded');
expect_life(await_life('life-permission', 'failed')['failure']['category'] === 'startup_exception', 'readonly violation reported');
action_life('stop', 'life-permission');
// These stage failures verify reporting/recovery, not real Telegram IPC/session restoration.
foreach (['ipc_failure' => 'ipc_initialization', 'restoration_failure' => 'session_restoration'] as $mode => $stage) {
    json_write_atomic(account_dir('life-permission') . '/fixture-mode.json', ['mode' => $mode]);
    expect_life(action_life('start', 'life-permission')['ok'], 'synthetic stage failure accepted');
    $failedStage = await_life('life-permission', 'failed');
    expect_life($failedStage['lifecycle']['stage'] === $stage && !$failedStage['running'], 'failed stage visible without false readiness');
    action_life('stop', 'life-permission');
}
foreach (['life-fail', 'life-exit', 'life-a', 'life-b'] as $key) expect_life(action_life('start', $key)['accepted'] ?? false, 'start request accepted distinctly');
foreach (['life-a', 'life-b'] as $key) expect_life(await_life($key, 'running')['running'], 'ready across shared PID namespace');
$failed = await_life('life-fail', 'failed');
expect_life($failed['failure']['category'] === 'startup_exception', 'startup exception visible through API');
expect_life(!str_contains(json_encode($failed), 'FIXTURE_SECRET'), 'failure summary omits exception contents');
expect_life(await_life('life-exit', 'failed')['running'] === false, 'exit is never running');
$pid = (int) status_life('life-a')['pid'];
file_put_contents(account_paths('life-a')['pid'], (string) getmypid());
expect_life(status_life('life-a')['running'] && (int) status_life('life-a')['pid'] === $pid, 'stale PID file cannot hide owned worker');
expect_life(action_life('restart', 'life-a')['ok'], 'restart via API');
expect_life(posix_kill(getmypid(), 0), 'stale PID never signals unrelated driver');
expect_life((int) await_life('life-a', 'running')['pid'] !== $pid, 'new real worker generation');
$beforeCrash = status_life('life-a'); $pid = (int) $beforeCrash['pid'];
json_write_atomic(account_dir('life-a') . '/fixture-config.json', ['value' => 'private-fixture']);
expect_life(posix_kill($pid, SIGKILL), 'crash signal sent to fixture worker');
$deadline = hrtime(true) + 5000000000;
do { if (!process_owned($pid, 'life-a')) break; usleep(100000); } while (hrtime(true) < $deadline);
expect_life(!process_owned($pid, 'life-a'), 'exact killed worker terminated');
$detected = await_life('life-a', 'failed', 8);
expect_life($detected['failure']['category'] === 'process_exit' && $detected['lifecycle']['retry_at'] > time(), 'supervisor detects crash and schedules retry');
$deadline = time() + 35;
do { $recovered = status_life('life-a'); if ($recovered['running'] && (int) $recovered['pid'] !== $pid) break; usleep(200000); } while (time() < $deadline);
expect_life($recovered['running'] && (int) $recovered['pid'] !== $pid
    && $recovered['lifecycle']['identity'] !== $beforeCrash['lifecycle']['identity']
    && $recovered['lifecycle']['run_id'] !== $beforeCrash['lifecycle']['run_id']
    && $recovered['lifecycle']['heartbeat'] > time() - 5, 'crash recovery with new generation and heartbeat');
expect_life(read_json_file(account_dir('life-a') . '/fixture-config.json') === ['value' => 'private-fixture'], 'crash retains persistent account data');
expect_life(action_life('stop', 'life-b')['ok'], 'explicit stop');
await_life('life-b', 'stopped');
expect_life(read_json_file(account_dir('life-b') . '/fixture-flushed.json')['flushed'], 'stop does not hold worker data lock');
expect_life(action_life('start', 'life-delay')['ok'], 'start delay');
await_life('life-delay', 'starting');
expect_life(!status_life('life-delay')['running'], 'process alone is not readiness');
expect_life(action_life('stop', 'life-delay')['ok'], 'stop during initialization');
expect_life(await_life('life-delay', 'stopped')['running'] === false, 'late readiness cannot undo stop');
expect_life(action_life('start', 'life-delay')['ok'], 'new initialization request');
await_life('life-delay', 'starting');
expect_life(action_life('restart', 'life-delay')['ok'], 'restart during initialization');
expect_life(await_life('life-delay', 'running')['running'], 'replacement generation becomes ready');
action_life('stop', 'life-delay');
$deadline = time() + 10;
do { $observed = read_json_file(account_dir('life-a') . '/fixture-observed.json', []); if ($observed === ['value' => 'private-fixture']) break; usleep(200000); } while (time() < $deadline);
expect_life($observed === ['value' => 'private-fixture'], 'runtime config consumed in real worker');
$pid = (int) status_life('life-b')['pid'];
expect_life(!status_life('life-b')['running'], 'stopped account remains stopped while peers active');
$deadline = time() + 45;
do { $exhausted = status_life('life-fail'); if (($exhausted['lifecycle']['attempts'] ?? 0) >= 3 && $exhausted['state'] === 'failed') break; usleep(200000); } while (time() < $deadline);
expect_life($exhausted['state'] === 'failed' && $exhausted['lifecycle']['attempts'] === 3 && $exhausted['lifecycle']['retry_at'] === null, 'repeated crashes bounded at three attempts');
echo "$checks Compose lifecycle checks passed (synthetic Telegram layer)\n";
