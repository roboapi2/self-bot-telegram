<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
if (!function_exists('pcntl_async_signals')) throw new RuntimeException('pcntl required');
$panel = base_dir() . '/.panel';
if (!is_dir($panel) && !mkdir($panel, 0700, true) && !is_dir($panel)) throw new RuntimeException('ساخت مسیر اجرا ممکن نشد.');
$instanceLock = fopen($panel . '/supervisor.lock', 'c+');
if ($instanceLock === false || !flock($instanceLock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "ناظر دیگری در حال اجرا است.\n"); exit(1); }
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
while ($running) {
    clearstatcache();
    try {
        $accounts = load_accounts();
        foreach ($accounts as $key => $account) {
            if (!$running) break;
            try { supervise_account($key); }
            catch (Throwable $e) {
                try { lifecycle_failure($key, '', 'supervisor_account_error', $e); }
                catch (Throwable) { error_log(json_encode(['account' => $key, 'category' => 'state_unwritable', 'class' => get_class($e)])); }
            }
        }
        // A fresh timestamp proves a completed scan, not just entry into the loop.
        if ($running && !json_write_atomic($panel . '/supervisor.health.json', ['pid' => getmypid(), 'time' => time(),
            'scan_completed' => true, 'accounts_checked' => count($accounts)])) throw new RuntimeException('Supervisor health write failed');
    } catch (Throwable $e) {
        error_log('Supervisor: ' . redact_secrets($e->getMessage()));
    }
    sleep(2);
}
foreach (load_accounts() as $key => $account) {
    try {
        if (worker_running($key)) {
            lifecycle_write($key, '', static fn(array $s): array => array_merge($s, ['state' => 'queued',
                'stage' => 'supervisor_shutdown', 'run_id' => bin2hex(random_bytes(16)), 'heartbeat' => 0, 'attempts' => 0, 'retry_at' => 0]));
        }
        terminate_worker($key); terminate_worker($key, 'qr');
    }
    catch (Throwable $e) { error_log(json_encode(['account' => $key, 'category' => 'shutdown_failure', 'class' => get_class($e)])); }
}
