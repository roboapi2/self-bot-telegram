<?php
declare(strict_types=1);
if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "SKIP Linux lifecycle test: Linux + pcntl + posix required\n";
    exit;
}
$tmp = sys_get_temp_dir() . '/ubcc-lifecycle-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
foreach (['lib.php', 'runtime.php', 'worker.php', 'supervisor.php'] as $file) copy(dirname(__DIR__) . '/' . $file, $tmp . '/' . $file);
file_put_contents($tmp . '/config.php', '<?php return ["base_dir"=>__DIR__,"api_id"=>1,"api_hash"=>"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","php_bin"=>PHP_BINARY];');
file_put_contents($tmp . '/bot_template.php', '<?php pcntl_async_signals(true); pcntl_signal(SIGTERM, static function(){exit;}); while(true){sleep(1);}');
file_put_contents($tmp . '/accounts.json', '[{"key":"test","name":"fixture"}]');
require $tmp . '/lib.php';
ensure_account_files('test');
file_put_contents(account_paths('test')['session'], 'fixture, not a Telegram session');
$supervisor = null;
function expect(bool $value, string $name): void { if (!$value) throw new RuntimeException('FAIL: ' . $name); }
function await_worker(bool $running): void {
    for ($i = 0; $i < 450; $i++) { if (worker_running('test') === $running) return; usleep(100000); }
    throw new RuntimeException('Worker timeout');
}
try {
    expect(start_account('test')['ok'], 'start');
    await_worker(true); $original = read_pid('test');
    expect(start_account('test')['ok'] && read_pid('test') === $original, 'idempotent start');
    $duplicate = proc_open([PHP_BINARY, $tmp . '/worker.php', 'bot', 'test'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    expect(is_resource($duplicate) && proc_close($duplicate) === 0 && read_pid('test') === $original, 'session lock rejects duplicate worker');
    $featureFile = account_dir('test') . '/feature-fixture.json';
    json_write_atomic($featureFile, ['enabled' => true, 'next' => 123]);
    expect(restart_account('test')['ok'], 'restart'); await_worker(true);
    expect(read_pid('test') !== $original && read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'restart preserves state');
    $original = read_pid('test');
    file_put_contents(account_paths('test')['pid'], (string) getmypid());
    expect(stop_account('test')['ok'] && pid_running($original), 'stale PID must not kill another process');
    file_put_contents(account_paths('test')['pid'], (string) $original);
    expect(stop_account('test')['ok'], 'graceful stop'); await_worker(false);
    json_write_atomic(account_dir('test') . '/enabled', true);
    $supervisor = proc_open([PHP_BINARY, $tmp . '/supervisor.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tmp . '/supervisor.log', 'a'], 2 => ['file', $tmp . '/supervisor.log', 'a']], $pipes);
    await_worker(true);
    $crashed = read_pid('test');
    posix_kill($crashed, SIGKILL); await_worker(false); await_worker(true);
    expect(read_pid('test') !== $crashed, 'supervisor recovers crashed worker');
    expect(read_json_file($featureFile) === ['enabled' => true, 'next' => 123], 'persisted state survives worker recovery');
    expect(stop_account('test')['ok'], 'explicit stop clears desired state'); await_worker(false);
    sleep(3); expect(!worker_running('test'), 'supervisor honors stop');
    json_write_atomic(account_dir('test') . '/enabled', true); await_worker(true);
    proc_terminate($supervisor, SIGTERM); expect(proc_close($supervisor) === 0, 'supervisor shutdown'); $supervisor = null;
    await_worker(false);
    expect(is_file(account_dir('test') . '/enabled'), 'boot intent preserved');
    echo "Linux PID ownership, locking and supervisor lifecycle passed\n";
} finally {
    if (is_resource($supervisor)) { proc_terminate($supervisor); proc_close($supervisor); }
    terminate_worker('test');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
}
