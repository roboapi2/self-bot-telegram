<?php
declare(strict_types=1);

/** Exclusive transaction lock. Keep the lock file: deleting it breaks mutual exclusion. */
function with_file_lock(string $file, callable $fn): mixed
{
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
        throw new RuntimeException('ساخت پوشه ممکن نشد.');
    }
    $handle = fopen($file, 'c');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('قفل داده ممکن نشد.');
    try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}

/** Merge only changes made by this writer; concurrent panel changes take precedence. */
function merge_json_changes(array $current, array $before, array $after): array
{
    // Archive entries have stable identities; concurrent appends must not collide
    // merely because both writers chose the next numeric array index.
    $all = array_merge(array_values($before), array_values($after), array_values($current));
    if ($all !== [] && array_is_list($before) && array_is_list($after)
        && count(array_filter($all, static fn($row): bool => is_array($row) && isset($row['archive_id']))) === count($all)) {
        $byId = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) $out['archive:' . (string) $row['archive_id']] = $row;
            return $out;
        };
        return array_values(merge_json_changes($byId($current), $byId($before), $byId($after)));
    }
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $had = array_key_exists($key, $before);
        $has = array_key_exists($key, $after);
        $now = array_key_exists($key, $current);
        if ($had === $has && (!$has || $before[$key] === $after[$key])) continue;
        if ($had && $has && $now && is_array($before[$key]) && is_array($after[$key]) && is_array($current[$key])) {
            $current[$key] = merge_json_changes($current[$key], $before[$key], $after[$key]);
        } elseif ($now === $had && (!$had || $current[$key] === $before[$key])) {
            if ($has) $current[$key] = $after[$key]; else unset($current[$key]);
        }
    }
    return $current;
}

/** Snapshot follows its coroutine, so another event handler cannot replace its baseline. */
function bot_json_snapshot(string $file, ?array $data = null, bool $write = false): ?array
{
    static $fibers = null;
    static $main = [];
    $fibers ??= new WeakMap();
    $fiber = Fiber::getCurrent();
    $snapshots = $fiber === null ? $main : ($fibers[$fiber] ?? []);
    if ($write) {
        $snapshots[$file] = $data;
        if ($fiber === null) $main = $snapshots; else $fibers[$fiber] = $snapshots;
    }
    return $snapshots[$file] ?? null;
}

function persist_bot_json(string $file, array $data, string $lockFile): void
{
    $before = bot_json_snapshot($file);
    $saved = with_file_lock($lockFile, static function () use ($file, $before, $data): array {
        $current = read_json_file($file, $before ?? []);
        if (!is_array($current)) throw new RuntimeException('Invalid JSON state type');
        $merged = $before === null ? $data : merge_json_changes($current, $before, $data);
        if (!json_write_atomic($file, $merged)) throw new RuntimeException('State write failed');
        return $merged;
    });
    bot_json_snapshot($file, $saved, true);
}

function process_owned(int $pid, string $key, string $kind = 'bot'): bool
{
    if (PHP_OS_FAMILY !== 'Linux') return false;
    if (!pid_running($pid)) return false;
    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdline)) return false;
    $args = explode("\0", $cmdline);
    return in_array(base_dir() . '/worker.php', $args, true)
        && in_array($kind, $args, true) && in_array($key, $args, true);
}

function worker_running(string $key, string $kind = 'bot'): bool
{
    clearstatcache();
    $paths = account_paths($key);
    $file = $kind === 'qr' ? $paths['qr_pid'] : $paths['pid'];
    return process_owned((int) @file_get_contents($file), $key, $kind);
}

function launch_worker(string $key, string $kind): array
{
    $cfg = cfg();
    if (PHP_OS_FAMILY !== 'Linux' || !is_executable((string) $cfg['php_bin']) || !function_exists('shell_exec')) {
        return ['ok' => false, 'error' => 'اجرای کارگر به Linux، PHP CLI و shell_exec نیاز دارد.'];
    }
    if ((int) $cfg['api_id'] <= 0 || !preg_match('/^[a-f0-9]{32}$/i', (string) $cfg['api_hash'])) {
        return ['ok' => false, 'error' => 'api_id و api_hash را در config.php تنظیم کنید.'];
    }
    $p = account_paths($key);
    $log = $kind === 'qr' ? $p['qr_log'] : $p['log'];
    // exec replaces the shell; worker.php records its own PID. No secrets in argv.
    $cmd = 'nohup ' . escapeshellarg((string) $cfg['php_bin']) . ' '
        . escapeshellarg(base_dir() . '/worker.php') . ' ' . escapeshellarg($kind) . ' ' . escapeshellarg($key)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $pid = trim((string) shell_exec($cmd));
    if (!ctype_digit($pid)) return ['ok' => false, 'error' => 'راه‌اندازی کارگر ممکن نشد.'];
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        if (worker_running($key, $kind)) return ['ok' => true, 'message' => 'کارگر اجرا شد.'];
        if (!pid_running((int) $pid)) break;
    }
    return ['ok' => false, 'error' => 'کارگر شروع نشد؛ لاگ حساب را بررسی کنید.'];
}

function terminate_worker(string $key, string $kind = 'bot'): bool
{
    $p = account_paths($key);
    $file = $kind === 'qr' ? $p['qr_pid'] : $p['pid'];
    $pid = (int) @file_get_contents($file);
    if (!process_owned($pid, $key, $kind)) return true;
    if (!function_exists('posix_kill')) throw new RuntimeException('افزونه posix لازم است.');
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 100; $i++) {
        usleep(100000);
        if (!process_owned($pid, $key, $kind)) return true;
    }
    // Do not force-kill a process writing a Telegram session. Report failure.
    return false;
}

function redact_secrets(string $text): string
{
    foreach (['admin_password', 'api_hash'] as $field) {
        $secret = (string) (cfg()[$field] ?? '');
        if ($secret !== '') $text = str_replace($secret, '[REDACTED]', $text);
    }
    // Consume entire quoted values (including spaces), not just their first word.
    $text = preg_replace('/\b(password|api_hash|auth_key|authorization|(?:access_|refresh_|session_|bot_)?token|session_secret|session_string|secret)(["\x27]?\s*[:=]\s*|\s+)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^\r\n,}]+)/i', '$1$2[REDACTED]', $text) ?? '';
    $text = preg_replace('/\bBearer\s+[^\s,}\r\n]+/i', 'Bearer [REDACTED]', $text) ?? '';
    return preg_replace('~tg://login\?token=[^\s"\x27<>]+~i', 'tg://login?token=[REDACTED]', $text) ?? '';
}

function ubcc_madeline_log(mixed $message, int $level = 0): void
{
    // MadelineProto 8.7 clamps setLevel(ERROR) to NOTICE; enforce it here too.
    if ($level > \danog\MadelineProto\Logger::ERROR) return;
    // Structured protocol objects may contain authorization material.
    error_log(is_string($message) ? redact_secrets($message) : 'MadelineProto protocol error');
}

function configure_madeline_logger(\danog\MadelineProto\Settings $settings): void
{
    $settings->getLogger()->setType(\danog\MadelineProto\Logger::CALLABLE_LOGGER)
        // Logger settings omit closures on serialization; startup reapplies this.
        ->setExtra(Closure::fromCallable('ubcc_madeline_log'))->setLevel(\danog\MadelineProto\Logger::ERROR);
}

/** Private per-process scratch space; sessions always keep their absolute account path. */
function initialize_madeline_runtime(): void
{
    static $directory = null;
    if ($directory === null) {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/ubcc-madeline-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create private MadelineProto scratch directory');
        register_shutdown_function(static function () use ($directory): void {
            // Only known scratch files; never recurse into sessions or arbitrary paths.
            try {
                foreach (['.prime.py', '.alt_prime.py', 'MadelineProto.log'] as $name) {
                    $file = $directory . '/' . $name;
                    if (is_file($file) || is_link($file)) unlink($file);
                }
                if (is_dir($directory) && count(scandir($directory)) === 2) rmdir($directory);
            } catch (Throwable) { /* Scratch cleanup must not interrupt session shutdown. */ }
        });
    }
    if (!chdir($directory)) throw new RuntimeException('Cannot enter MadelineProto scratch directory');
    \danog\MadelineProto\Magic::start(light: true);
    // Magic derives this from the outer script filename, not chdir().
    \danog\MadelineProto\Magic::$script_cwd = $directory;
    \danog\MadelineProto\Magic::$cwd = $directory;
    if (!defined('UBCC_MADELINE_BOOTSTRAP')) define('UBCC_MADELINE_BOOTSTRAP', __DIR__ . '/madeline_bootstrap.php');
}
