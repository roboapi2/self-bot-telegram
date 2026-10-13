<?php
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/ubcc-unit-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
foreach (['lib.php', 'runtime.php', 'console.php', 'worker.php'] as $file) copy(dirname(__DIR__) . '/' . $file, $tmp . '/' . $file);
file_put_contents($tmp . '/config.php', "<?php return ['base_dir' => __DIR__, 'admin_password' => 'test-secret-only', 'api_hash' => 'fixture-hash'];");
require $tmp . '/lib.php';
$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $count++;
}
function remove_fixture(string $dir): void {
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) remove_fixture($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($dir);
}
try {
    check(valid_account_key('acc-123'), 'valid key');
    foreach (['../bad', '', 'a/b', str_repeat('a', 65)] as $key) check(!valid_account_key($key), 'invalid key');
    try { account_dir('../bad'); check(false, 'path rejection'); } catch (InvalidArgumentException) { check(true, 'path rejection'); }
    $file = $tmp . '/state.json';
    check(json_write_atomic($file, ['fa' => 'سلام']), 'atomic write');
    check(read_json_file($file)['fa'] === 'سلام', 'unicode roundtrip');
    file_put_contents($file, '{broken');
    try { read_json_file($file); check(false, 'corrupt state'); } catch (JsonException) { check(true, 'corrupt state'); }
    check(file_get_contents($file) === '{broken', 'corrupt state preserved');
    check(read_json_file($tmp . '/missing', ['x' => 1]) === ['x' => 1], 'missing defaults');
    $before = ['a' => ['enabled' => true, 'next' => 10], 'b' => ['enabled' => true]];
    $current = ['a' => ['enabled' => false, 'next' => 10], 'c' => ['enabled' => true]];
    $after = ['a' => ['enabled' => true, 'next' => 20], 'b' => ['enabled' => true]];
    $merged = merge_json_changes($current, $before, $after);
    check(!$merged['a']['enabled'] && $merged['a']['next'] === 20, 'merge preserves panel pause and worker schedule');
    check(!isset($merged['b']) && isset($merged['c']), 'merge preserves removal and addition');
    $f1 = new Fiber(static function (): array { bot_json_snapshot('state', ['original' => 1], true); Fiber::suspend(); return bot_json_snapshot('state'); });
    $f2 = new Fiber(static function (): array { bot_json_snapshot('state', ['original' => 2], true); Fiber::suspend(); return bot_json_snapshot('state'); });
    $f1->start(); $f2->start(); $f1->resume(); $f2->resume();
    check($f1->getReturn() === ['original' => 1] && $f2->getReturn() === ['original' => 2], 'coroutines keep independent merge baselines');
    check(bot_json_snapshot('never-loaded') === null, 'reset without prior read has no stale baseline');
    $newState = $tmp . '/new-state.json';
    bot_json_snapshot($newState, ['enabled' => false], true);
    persist_bot_json($newState, ['enabled' => true], $tmp . '/data.lock');
    check(read_json_file($newState) === ['enabled' => true], 'first write persists changes to missing default state');
    $reset = $tmp . '/reset.json'; json_write_atomic($reset, ['step' => 'old']);
    persist_bot_json($reset, [], $tmp . '/data.lock');
    check(read_json_file($reset) === [], 'state reset works without prior read');
    $shared = $tmp . '/shared.json'; json_write_atomic($shared, ['a' => 0, 'b' => 0]);
    $writers = [];
    foreach (['a', 'b'] as $field) $writers[] = new Fiber(static function () use ($shared, $tmp, $field): void {
        $data = read_json_file($shared); bot_json_snapshot($shared, $data, true); Fiber::suspend();
        $data[$field] = 1; persist_bot_json($shared, $data, $tmp . '/data.lock');
    });
    foreach ($writers as $writer) $writer->start();
    foreach ($writers as $writer) $writer->resume();
    check(read_json_file($shared) === ['a' => 1, 'b' => 1], 'interleaved event writes preserve both changes');
    $archive = merge_json_changes([['archive_id' => 'b', 'text' => 'second']], [], [['archive_id' => 'a', 'text' => 'first']]);
    check(count($archive) === 2 && $archive[0]['archive_id'] === 'b' && $archive[1]['archive_id'] === 'a', 'concurrent archive appends both retained');
    mkdir($tmp . '/media'); file_put_contents($tmp . '/media/photo.jpg', 'fixture');
    check(safe_media_path($tmp . '/media', 'photo.jpg') === realpath($tmp . '/media/photo.jpg'), 'valid media');
    check(safe_media_path($tmp . '/media', '../state.json') === null, 'traversal media');
    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($tmp . '/media-sibling'); file_put_contents($tmp . '/media-sibling/file', 'fixture');
        symlink($tmp . '/media-sibling/file', $tmp . '/media/escape');
        check(safe_media_path($tmp . '/media', 'escape') === null, 'symlink prefix boundary');
    }
    mkdir($tmp . '/accounts'); ensure_account_files('legacy');
    file_put_contents(account_dir('legacy') . '/bot.php', 'custom legacy source');
    ensure_account_files('legacy');
    check(is_file(account_dir('legacy') . '/bot.php'), 'legacy code preserved');
    file_put_contents($tmp . '/accounts.json', '[{"key":"legacy","name":"original"}]');
    mkdir($tmp . '/.panel');
    file_put_contents($tmp . '/.panel/accounts.json', '[{"key":"legacy","name":"active"}]');
    check(load_accounts()['legacy']['name'] === 'active', 'active registry preferred');
    save_accounts(['legacy' => ['key' => 'legacy', 'name' => 'updated']]);
    check(load_accounts()['legacy']['name'] === 'updated', 'active registry writable');
    check(str_contains(file_get_contents($tmp . '/accounts.json'), 'original'), 'original registry preserved');
    unlink($tmp . '/.panel/accounts.json');
    file_put_contents($tmp . '/.panel/storage.initialized', '{"version":1}');
    try { load_accounts(); check(false, 'missing active registry'); }
    catch (RuntimeException) { check(true, 'missing active registry cannot fall back to legacy'); }
    json_write_atomic($tmp . '/.panel/accounts.json', [['key' => 'legacy', 'name' => 'updated']]);
    foreach ([null, [['key' => '../bad']], [['key' => 'x'], ['key' => 'x']]] as $badRegistry) {
        try { validate_account_registry($badRegistry); check(false, 'invalid registry'); }
        catch (RuntimeException) { check(true, 'invalid registry rejected'); }
    }
    file_put_contents(account_paths('legacy')['session'], 'fixture');
    json_write_atomic(account_paths('legacy')['qr_state'], ['logged_in' => false]);
    check(!is_logged_in('legacy'), 'incomplete QR session is not authorized');
    check(str_contains(redact_secrets('test-secret-only fixture-hash'), '[REDACTED]') && !str_contains(redact_secrets('test-secret-only fixture-hash'), 'fixture-hash'), 'secret redaction');
    check(!process_owned(getmypid(), 'legacy'), 'unrelated PID rejected');
    lifecycle_write('legacy', '', static fn(array $s): array => ['state' => 'running', 'run_id' => 'old', 'pid' => getmypid(), 'identity' => 'stale', 'heartbeat' => time(), 'attempts' => 3]);
    json_write_atomic(account_dir('legacy') . '/enabled', true);
    check(!lifecycle_status('legacy')['running'], 'stale readiness and unrelated PID cannot be running');
    check(!lifecycle_write('legacy', 'different', static fn(array $s): array => ['state' => 'running']), 'obsolete generation rejected');
    lifecycle_failure('legacy', 'old', 'startup_exception', new RuntimeException('FIXTURE_SECRET_PRIVATE_MESSAGE'));
    $failure = lifecycle_read('legacy');
    check($failure['retry_at'] === null && $failure['state'] === 'failed', 'retries bounded and persisted');
    check(!str_contains(json_encode($failure), 'FIXTURE_SECRET_PRIVATE_MESSAGE'), 'exception contents absent from diagnostics');
    check(stop_account('legacy')['ok'] && lifecycle_status('legacy')['state'] === 'stopped', 'stop clears queued intent');
    check(!lifecycle_write('legacy', 'old', static fn(array $s): array => ['state' => 'running']), 'late readiness cannot undo stop');
    file_put_contents(account_paths('legacy')['cache'], '{broken');
    file_put_contents(account_paths('legacy')['qr_state'], '{broken');
    $status = account_status(['key' => 'legacy']);
    check($status['cache_count'] === null && $status['data_errors'] === ['qr_state', 'cache'], 'corrupt account data cannot break status listing');
    check(file_get_contents(account_paths('legacy')['cache']) === '{broken' && file_get_contents(account_paths('legacy')['qr_state']) === '{broken', 'corrupt account data preserved');
    lifecycle_write('legacy', '', static fn(array $s): array => array_merge($s, ['failure' => ['category' => 'startup_exception', 'message' => 'PRIVATE_DIAGNOSTIC_FIXTURE']]));
    $diagnostic = proc_open([PHP_BINARY, $tmp . '/console.php', 'diagnose'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($diagnostic) === 0 && json_decode($output, true)['accounts'][0]['key'] === 'legacy', 'safe CLI diagnose executes on damaged account data');
    check(!str_contains($output . $errors, 'test-secret-only') && !str_contains($output . $errors, 'fixture-hash') && !str_contains($output . $errors, 'PRIVATE_DIAGNOSTIC_FIXTURE'), 'CLI diagnose omits credentials and raw failure messages');
    ensure_account_files('healthy');
    file_put_contents(account_paths('healthy')['session'], 'SYNTHETIC_SESSION');
    save_accounts(['legacy' => ['key' => 'legacy'], 'healthy' => ['key' => 'healthy']]);
    $bulk = start_all_accounts();
    check(isset($bulk['failed']['legacy'], $bulk['failed']['healthy']), 'bulk start reports damaged account and continues to next account');
    json_write_atomic(account_dir('healthy') . '/enabled', true);
    lifecycle_write('healthy', '', static fn(array $s): array => ['state' => 'starting', 'run_id' => 'bootstrap-fixture', 'attempts' => 1]);
    mkdir(account_paths('healthy')['pid']); // PID target is a directory: reproduce pre-PID failure on all platforms.
    $bootstrap = proc_open([PHP_BINARY, $tmp . '/worker.php', 'bot', 'healthy'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['UBCC_RUN_ID' => 'bootstrap-fixture']));
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($bootstrap) === 1 && str_contains($stderr, 'worker_bootstrap_failure'), 'real worker reports failure before PID creation');
    $bootstrapState = lifecycle_read('healthy');
    check($bootstrapState['state'] === 'failed' && $bootstrapState['stage'] === 'pid_registration' && $bootstrapState['failure']['category'] === 'worker_bootstrap_failure', 'pre-PID failure persisted with startup stage');
    unlink(account_dir('healthy') . '/worker.lock'); mkdir(account_dir('healthy') . '/worker.lock');
    lifecycle_write('healthy', '', static fn(array $s): array => ['state' => 'starting', 'run_id' => 'lock-fixture', 'attempts' => 1]);
    $bootstrap = proc_open([PHP_BINARY, $tmp . '/worker.php', 'bot', 'healthy'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['UBCC_RUN_ID' => 'lock-fixture']));
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($bootstrap) === 1, 'unopenable worker lock is a bootstrap failure');
    $bootstrapState = lifecycle_read('healthy');
    check($bootstrapState['stage'] === 'worker_lock' && $bootstrapState['failure']['category'] === 'worker_bootstrap_failure', 'lock path failure is distinct from active contention');
    check(normalize_channel_peer('https://t.me/example') === '@example', 'peer normalization');
    check(normalize_chat_filter('groups') === 'groups', 'filter normalization');
    $value = with_file_lock($tmp . '/data.lock', static fn(): int => 42);
    check($value === 42, 'transaction lock');
    echo "$count unit checks passed\n";
} finally { remove_fixture($tmp); }
