<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
$kind = (string) ($argv[1] ?? '');
$key = (string) ($argv[2] ?? '');
if (!in_array($kind, ['bot', 'qr'], true) || !valid_account_key($key) || !isset(load_accounts()[$key])) exit(1);
ensure_account_files($key);
$p = account_paths($key);
// One process per session, including during QR activation.
$sessionLock = fopen($p['dir'] . '/worker.lock', 'c');
if (!$sessionLock || !flock($sessionLock, LOCK_EX | LOCK_NB)) exit(0);
$pidFile = $kind === 'qr' ? $p['qr_pid'] : $p['pid'];
file_put_contents($pidFile, (string) getmypid());
if ($kind === 'bot') file_put_contents($p['started_at'], (string) time());
register_shutdown_function(static function () use ($pidFile, $p, $kind): void {
    if ((int) @file_get_contents($pidFile) === getmypid()) {
        @unlink($pidFile);
        if ($kind === 'bot') @unlink($p['started_at']);
    }
    if ($kind === 'qr') @unlink($p['qr_2fa']);
});
$cfg = cfg();
putenv('UBCC_ACCOUNT_DIR=' . $p['dir']);
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
    } else require __DIR__ . '/bot_template.php';
} catch (Throwable $e) {
    error_log('Worker failure: ' . redact_secrets($e->getMessage()));
    exit(1);
}
