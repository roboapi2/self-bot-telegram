<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
$kind = (string) ($argv[1] ?? '');
$key = (string) ($argv[2] ?? '');
if (!in_array($kind, ['bot', 'qr'], true) || !valid_account_key($key)) exit(1);
$run = (string) getenv('UBCC_RUN_ID');
try {
    if (!isset(load_accounts()[$key])) exit(1);
    ensure_account_files($key);
    $p = account_paths($key);
    // One process per session, including during QR activation.
    if ($kind === 'bot' && $run !== '') lifecycle_write($key, $run, static fn(array $s): array => array_merge($s, ['stage' => 'worker_lock']));
    $sessionLock = @fopen($p['dir'] . '/worker.lock', 'c');
    if ($sessionLock === false) throw new RuntimeException('Worker lock file unavailable');
    if (!flock($sessionLock, LOCK_EX | LOCK_NB)) {
        if ($kind === 'bot' && getenv('UBCC_RUN_ID') && !worker_running($key)) lifecycle_failure($key, (string) getenv('UBCC_RUN_ID'), 'lock_contention', null, 75);
        exit(75);
    }
    if ($kind === 'bot' && (!is_file($p['dir'] . '/enabled') || (lifecycle_read($key)['run_id'] ?? '') !== $run)) exit(0);
    $pidFile = $kind === 'qr' ? $p['qr_pid'] : $p['pid'];
    if ($kind === 'bot') lifecycle_write($key, $run, static fn(array $s): array => array_merge($s, ['stage' => 'pid_registration']));
    if (@file_put_contents($pidFile, (string) getmypid()) === false) throw new RuntimeException('Worker PID registration failed');
    if ($kind === 'bot') {
        lifecycle_write($key, $run, static fn(array $s): array => array_merge($s, ['pid' => getmypid(), 'identity' => process_identity(getmypid()), 'stage' => 'worker_bootstrap']));
    }
} catch (Throwable $e) {
    try { if ($kind === 'bot' && $run !== '') lifecycle_failure($key, $run, 'worker_bootstrap_failure', $e, 1); }
    catch (Throwable) { /* Account storage may itself be unwritable. Keep stderr safe. */ }
    error_log(json_encode(['event' => 'worker_bootstrap_failure', 'account' => $key, 'worker' => $kind,
        'class' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]));
    exit(1);
}
register_shutdown_function(static function () use ($pidFile, $p, $kind, $key, $run): void {
    if ($kind === 'bot' && (lifecycle_read($key)['run_id'] ?? '') === $run) {
        $state = lifecycle_read($key);
        if (!in_array($state['state'] ?? '', ['failed', 'stopped', 'stopping'], true)) {
            $error = error_get_last();
            $fatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
            lifecycle_failure($key, $run, $fatal ? 'php_fatal' : 'worker_exit',
                $fatal ? new ErrorException('Worker fatal', 0, $error['type'], $error['file'], $error['line']) : null, $fatal ? 1 : null);
        }
    }
    if ((int) @file_get_contents($pidFile) === getmypid()) {
        @unlink($pidFile);
        if ($kind === 'bot') @unlink($p['started_at']);
    }
    if ($kind === 'qr') @unlink($p['qr_2fa']);
});
$cfg = cfg();
putenv('UBCC_ACCOUNT_DIR=' . $p['dir']);
putenv('UBCC_ACCOUNT_KEY=' . $key);
putenv('TG_API_ID=' . $cfg['api_id']);
putenv('TG_API_HASH=' . $cfg['api_hash']);
putenv('TG_SESSION=' . $p['session']);
putenv('TG_TIMEZONE=' . $cfg['timezone']);
// Redact application stdout/stderr forwarded by PHP's error handler.
ob_start(static fn(string $text): string => redact_secrets($text), 4096);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) return false;
    error_log(redact_secrets($message));
    return true;
});
try {
    if ($kind === 'qr') {
        $argv = [__DIR__ . '/qr_worker.php', $key];
        require __DIR__ . '/qr_worker.php';
    } else {
        define('UBCC_WORKER_RUNNER', true);
        worker_stage('handler_loading');
        require __DIR__ . '/bot_template.php';
    }
} catch (Throwable $e) {
    if ($kind === 'bot') lifecycle_failure($key, $run, 'startup_exception', $e, 1);
    error_log(json_encode(['event' => 'worker_exception', 'account' => $key, 'worker' => $kind,
        'class' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]));
    exit(1);
}
