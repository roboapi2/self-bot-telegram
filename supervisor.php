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
$retry = [];
while ($running) {
    clearstatcache();
    try {
        foreach (load_accounts() as $key => $account) {
            with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key, &$retry, &$running): void {
                if (!$running) return;
                $p = account_paths($key);
                if (is_file($p['dir'] . '/qr.requested')) {
                    if (!terminate_worker($key)) return;
                    if (!worker_running($key, 'qr')) {
                        $r = launch_worker($key, 'qr');
                        if (!$r['ok']) json_write_atomic($p['qr_state'], ['ok' => false, 'status' => 'error', 'message' => $r['error']]);
                    }
                    @unlink($p['dir'] . '/qr.requested');
                }
                if (is_file($p['dir'] . '/enabled')) {
                    if (is_logged_in($key) && !worker_running($key) && !worker_running($key, 'qr') && time() >= ($retry[$key] ?? 0)) {
                        $r = launch_worker($key, 'bot');
                        $retry[$key] = time() + 30;
                        if (!$r['ok']) error_log('Account worker launch failed; inspect account log.');
                    }
                } elseif (worker_running($key)) terminate_worker($key);
            });
        }
    } catch (Throwable $e) {
        error_log('Supervisor: ' . redact_secrets($e->getMessage()));
    }
    sleep(2);
}
foreach (load_accounts() as $key => $account) { terminate_worker($key); terminate_worker($key, 'qr'); }
