<?php
declare(strict_types=1);
if (PHP_OS_FAMILY !== 'Linux') {
    echo "SKIP Linux lifecycle test: non-Linux local environment\n";
    exit;
}
if (!extension_loaded('pcntl') || !extension_loaded('posix')) throw new RuntimeException('Linux lifecycle gate requires pcntl and posix; refusing to skip');
$tmp = sys_get_temp_dir() . '/ubcc-lifecycle-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
foreach (['lib.php', 'runtime.php', 'worker.php', 'supervisor.php'] as $file) copy(dirname(__DIR__) . '/' . $file, $tmp . '/' . $file);
file_put_contents($tmp . '/config.php', '<?php return ["base_dir"=>__DIR__,"api_id"=>1,"api_hash"=>"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","php_bin"=>PHP_BINARY];');
file_put_contents($tmp . '/bot_template.php', <<<'PHP'
<?php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function(){exit;});
ubcc_event_handler_ready();
if (is_file(account_dir('test').'/crash-once')) {
    unlink(account_dir('test').'/crash-once');
    posix_kill(getmypid(), SIGKILL); // No shutdown callback: exercise death during launcher polling.
}
while(true){worker_heartbeat(); sleep(1);}
PHP);
file_put_contents($tmp . '/accounts.json', '[{"key":"test","name":"fixture"}]');
file_put_contents($tmp . '/qr_worker.php', '<?php pcntl_async_signals(true); pcntl_signal(SIGTERM, static function(){exit;}); while(true){sleep(1);}');
require $tmp . '/lib.php';
ensure_account_files('test');
file_put_contents(account_paths('test')['session'], 'fixture, not a Telegram session');
$supervisor = null;
function kernel_state_life(int $pid): ?string {
    $stat = $pid > 0 ? @file_get_contents('/proc/'.$pid.'/stat') : false;
    if (!is_string($stat) || ($end = strrpos($stat, ')')) === false) return null;
    $state = substr(ltrim(substr($stat, $end + 1)), 0, 1);
    return preg_match('/^[RSDZTtXxKWPI]$/', $state) ? $state : null;
}
function diagnostic_life(string $step): string {
    $s = lifecycle_status('test');
    return json_encode(['step' => $step, 'state' => $s['state'], 'stage' => $s['stage'] ?? null,
        'enabled' => $s['enabled'], 'owned' => $s['process_alive'], 'ready' => $s['running'],
        'pid_file' => read_pid('test'), 'pid' => $s['pid'] ?? null, 'identity' => $s['identity'] ?? null,
        'kernel_state' => kernel_state_life((int)($s['pid'] ?? 0)),
        'birth_identity_now' => process_identity((int)($s['pid'] ?? 0)),
        'attempts' => $s['attempts'] ?? 0, 'heartbeat' => $s['heartbeat'] ?? 0,
        'retry_at' => $s['retry_at'] ?? null, 'category' => $s['failure']['category'] ?? null,
        'supervisor_scan' => read_json_file(base_dir().'/.panel/supervisor.health.json', [])['time'] ?? null], JSON_THROW_ON_ERROR);
}
function expect(bool $value, string $name): void {
    if (!$value) throw new RuntimeException('FAIL: ' . diagnostic_life($name));
}
function await_lifecycle(string $step, Closure $condition, int $seconds = 45): void {
    $deadline = hrtime(true) + $seconds * 1000000000;
    do { clearstatcache(); if ($condition()) { echo "Lifecycle milestone passed: $step\n"; return; } usleep(100000); } while (hrtime(true) < $deadline);
    throw new RuntimeException('Lifecycle timeout: ' . diagnostic_life($step));
}
function await_ready(string $step): void {
    await_lifecycle($step, static fn(): bool => lifecycle_status('test')['running']);
}
function await_stopped(string $step): void {
    await_lifecycle($step, static fn(): bool => !worker_running('test'), 10);
}
function assert_recovery(array $old, string $step): void {
    await_lifecycle($step, static function () use ($old): bool {
        $s = lifecycle_status('test');
        return $s['running'] && $s['pid'] !== $old['pid'] && $s['identity'] !== ''
            && $s['identity'] !== $old['identity'] && $s['run_id'] !== $old['run_id'];
    });
    $s = lifecycle_read('test'); $heartbeat = $s['heartbeat'];
    expect($s['stage'] === 'handlers_ready' && $s['ready_at'] !== null, $step . '_readiness');
    expect($s['attempts'] === $old['attempts'] + 1, $step . '_single_respawn_attempt');
    await_lifecycle($step . '_heartbeat_advances', static fn(): bool => lifecycle_read('test')['heartbeat'] > $heartbeat, 5);
}
try {
    expect(start_account('test')['ok'], 'start');
    await_ready('direct_start_readiness'); $original = read_pid('test');
    expect(start_account('test')['ok'] && read_pid('test') === $original, 'idempotent start');
    $duplicate = proc_open([PHP_BINARY, $tmp . '/worker.php', 'bot', 'test'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    expect(is_resource($duplicate) && proc_close($duplicate) === 75 && read_pid('test') === $original, 'session lock rejects duplicate worker');
    $featureFile = account_dir('test') . '/feature-fixture.json';
    json_write_atomic($featureFile, ['enabled' => true, 'next' => 123]);
    expect(restart_account('test')['ok'], 'restart'); await_ready('direct_restart_readiness');
    expect(read_pid('test') !== $original && read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'restart preserves state');
    expect(start_qr_worker('test')['ok'], 'atomic_bot_to_qr_transition');
    expect(!worker_running('test') && worker_running('test', 'qr') && !is_file(account_dir('test').'/enabled'), 'QR transition leaves no bot intent');
    stop_qr_worker('test');
    expect(!worker_running('test', 'qr') && !is_file(account_paths('test')['qr_pid']), 'synthetic QR worker stops cleanly');
    // No real Telegram login: restore only the synthetic authorization status.
    json_write_atomic(account_paths('test')['qr_state'], ['logged_in' => true]);
    expect(start_account('test')['ok'], 'bot_start_after_synthetic_qr_stop'); await_ready('post_qr_bot_readiness');
    expect(read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'QR transition retains persistent feature state');
    $original = read_pid('test');
    file_put_contents(account_paths('test')['pid'], (string) getmypid());
    expect(stop_account('test')['ok'] && pid_running(getmypid()) && !worker_running('test'), 'stale PID cannot kill another process; known worker still stops');
    expect(stop_account('test')['ok'], 'graceful stop'); await_stopped('direct_stop');
    lifecycle_write('test', '', static fn(array $s): array => array_merge($s, ['state' => 'queued', 'attempts' => 0, 'retry_at' => 0]));
    json_write_atomic(account_dir('test') . '/enabled', true);
    $supervisor = proc_open([PHP_BINARY, $tmp . '/supervisor.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tmp . '/supervisor.log', 'a'], 2 => ['file', $tmp . '/supervisor.log', 'a']], $pipes);
    expect(is_resource($supervisor), 'supervisor_spawn');
    await_ready('supervised_start_readiness');
    // Readiness can precede return from launch_worker(). Wait for a later completed scan.
    await_lifecycle('supervisor_launch_acknowledged', static fn(): bool =>
        (read_json_file(base_dir().'/.panel/supervisor.health.json', [])['time'] ?? 0) > lifecycle_read('test')['last_attempt'], 10);
    $crashed = lifecycle_read('test');
    expect(posix_kill($crashed['pid'], SIGKILL), 'crash_signal_sent');
    // Observe the exact killed PID, not a transient global false state that a respawn can erase.
    await_lifecycle('killed_worker_terminated', static fn(): bool =>
        !process_owned($crashed['pid'], 'test') && (process_identity($crashed['pid']) !== $crashed['identity']
            || in_array(kernel_state_life($crashed['pid']), [null, 'Z', 'X', 'x'], true)), 5);
    await_lifecycle('supervisor_crash_detected_and_retry_scheduled', static function () use ($crashed): bool {
        $s = lifecycle_read('test');
        return $s['run_id'] === $crashed['run_id'] && $s['state'] === 'failed'
            && ($s['failure']['category'] ?? '') === 'process_exit' && ($s['retry_at'] ?? 0) > time();
    }, 8);
    expect(lifecycle_read('test')['attempts'] === $crashed['attempts'], 'crash_detection_does_not_increment_spawn_attempts');
    expect(!lifecycle_status('test')['running'] && lifecycle_read('test')['heartbeat'] === 0, 'crash_clears_readiness');
    assert_recovery($crashed, 'supervisor_crash_recovery');
    expect(read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'persisted state survives worker recovery');
    expect(stop_account('test')['ok'], 'explicit stop clears desired state'); await_stopped('explicit_stop');
    $scan = read_json_file(base_dir().'/.panel/supervisor.health.json')['time'];
    for ($i = 0; $i < 2; ++$i) {
        await_lifecycle('stopped_account_completed_scan_' . $i, static function () use ($scan): bool {
            expect(!worker_running('test') && !is_file(account_dir('test').'/enabled'), 'stop_cannot_be_undone');
            return read_json_file(base_dir().'/.panel/supervisor.health.json')['time'] > $scan;
        }, 8);
        $scan = read_json_file(base_dir().'/.panel/supervisor.health.json')['time'];
    }
    expect(!is_file(account_paths('test')['pid']) && !is_file(account_paths('test')['started_at']), 'explicit_stop_cleanup');
    // Separate regression for the original race: this child kills itself immediately after readiness.
    file_put_contents(account_dir('test').'/crash-once', 'fixture');
    lifecycle_write('test', '', static fn(array $s): array => array_merge($s, ['state' => 'queued', 'attempts' => 0, 'failure' => null, 'retry_at' => 0]));
    json_write_atomic(account_dir('test') . '/enabled', true);
    await_lifecycle('death_during_launch_polling_retry', static function (): bool {
        $s = lifecycle_read('test');
        return $s['state'] === 'failed' && ($s['failure']['category'] ?? '') === 'process_exit'
            && ($s['retry_at'] ?? 0) > time() && $s['ready_at'] !== null;
    }, 8);
    $early = lifecycle_read('test');
    expect($early['attempts'] === 1 && !is_file(account_dir('test').'/crash-once'), 'early_crash_was_executed_once');
    assert_recovery($early, 'launch_polling_crash_recovery');
    expect(read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'early_crash_preserves_persistent_state');
    proc_terminate($supervisor, SIGTERM);
    $exit = null;
    await_lifecycle('supervisor_clean_shutdown', static function () use ($supervisor, &$exit): bool {
        $status = proc_get_status($supervisor); if (!$status['running']) $exit = $status['exitcode'];
        return !$status['running'];
    }, 15);
    $closed = proc_close($supervisor); expect($exit === 0 || $closed === 0, 'supervisor_shutdown_exit'); $supervisor = null;
    await_stopped('supervisor_shutdown_worker_exit');
    expect(!is_file(account_paths('test')['pid']) && !is_file(account_paths('test')['started_at']), 'shutdown_cleanup');
    expect(is_file(account_dir('test') . '/enabled'), 'boot intent preserved');
    echo "Linux PID ownership, locking and supervisor lifecycle passed\n";
} finally {
    // Test processes only. Prevent fixture recovery while cleaning up a failed assertion.
    if (is_file(account_dir('test').'/enabled')) unlink(account_dir('test').'/enabled');
    if (is_resource($supervisor)) {
        proc_terminate($supervisor);
        $deadline = hrtime(true) + 15000000000;
        do { $status = proc_get_status($supervisor); if (!$status['running']) break; usleep(100000); } while (hrtime(true) < $deadline);
        if ($status['running']) proc_terminate($supervisor, SIGKILL);
        proc_close($supervisor);
    }
    if (!terminate_worker('test')) {
        // Only this disposable synthetic worker, identified by its unique temp path.
        $s = lifecycle_read('test'); $pid = (int)($s['pid'] ?? 0);
        if (process_owned($pid, 'test') && process_identity($pid) === ($s['identity'] ?? '')) posix_kill($pid, SIGKILL);
        await_stopped('fixture_worker_cleanup');
    }
    if (!terminate_worker('test', 'qr')) {
        $pid = (int) @file_get_contents(account_paths('test')['qr_pid']);
        if (process_owned($pid, 'test', 'qr')) posix_kill($pid, SIGKILL);
        await_lifecycle('fixture_qr_cleanup', static fn(): bool => !worker_running('test', 'qr'), 5);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
}
