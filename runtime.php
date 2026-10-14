<?php
declare(strict_types=1);

/** Exclusive transaction lock. Keep the lock file: deleting it breaks mutual exclusion. */
function with_file_lock(string $file, callable $fn, bool $nonBlocking = false): mixed
{
    if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
        throw new RuntimeException('ساخت پوشه ممکن نشد.');
    }
    $handle = fopen($file, 'c');
    if (!$handle) throw new RuntimeException('قفل داده ممکن نشد.');
    if (!flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0))) {
        fclose($handle);
        if ($nonBlocking) return null;
        throw new RuntimeException('قفل داده ممکن نشد.');
    }
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
    try { $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline'); }
    catch (Throwable) { return false; } // MadelineProto's handler can promote suppressed I/O warnings.
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
    $pid = is_file($file) ? (int) file_get_contents($file) : 0;
    if ($kind !== 'bot') return process_owned($pid, $key, $kind);
    $s = lifecycle_read($key);
    if (process_owned($pid, $key, $kind) && ($pid !== (int) ($s['pid'] ?? 0) || process_identity($pid) === ($s['identity'] ?? ''))) return true;
    $known = (int) ($s['pid'] ?? 0);
    return $known > 0 && process_owned($known, $key) && process_identity($known) === ($s['identity'] ?? '');
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
    if ($kind === 'bot') {
        $state = lifecycle_read($key);
        $run = bin2hex(random_bytes(16));
        lifecycle_write($key, '', static fn(array $s): array => array_merge($s, [
            'state' => 'starting', 'stage' => 'spawn', 'run_id' => $run, 'pid' => 0,
            'heartbeat' => 0, 'ready_at' => null, 'last_attempt' => time(), 'attempts' => (int) ($s['attempts'] ?? 0) + 1,
        ]));
    } else $run = '';
    $log = $kind === 'qr' ? $p['qr_log'] : $p['log'];
    // Shell redirection can fail before PHP gets any chance to record its PID.
    $logHandle = @fopen($log, 'ab');
    if ($logHandle === false) {
        if ($kind === 'bot') lifecycle_failure($key, $run, 'log_unwritable');
        return ['ok' => false, 'error' => 'مسیر لاگ کارگر قابل نوشتن نیست؛ مجوز پوشه حساب را بررسی کنید.'];
    }
    fclose($logHandle);
    // exec replaces the shell; worker.php records its own PID. No secrets in argv.
    $cmd = 'UBCC_RUN_ID=' . escapeshellarg($run) . ' nohup ' . escapeshellarg((string) $cfg['php_bin']) . ' '
        . escapeshellarg(base_dir() . '/worker.php') . ' ' . escapeshellarg($kind) . ' ' . escapeshellarg($key)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
    $pid = trim((string) shell_exec($cmd));
    if (!ctype_digit($pid)) {
        if ($kind === 'bot') record_launch_failure($key, $run);
        return ['ok' => false, 'error' => 'راه‌اندازی کارگر ممکن نشد.'];
    }
    for ($i = 0; $i < 20; $i++) {
        usleep(100000);
        if (worker_running($key, $kind)) return ['ok' => true, 'accepted' => true, 'message' => 'پردازش آغاز شد؛ آماده‌سازی هنوز کامل نشده است.'];
        if (!pid_running((int) $pid)) break;
    }
    if ($kind === 'bot') record_launch_failure($key, $run);
    return ['ok' => false, 'error' => 'کارگر شروع نشد؛ لاگ حساب را بررسی کنید.'];
}

/** A worker that entered bootstrap and died during spawn polling is a crash,
 * not a permanent inability to spawn PHP. Preserve errors recorded by it. */
function record_launch_failure(string $key, string $run): void
{
    $s = lifecycle_read($key);
    if (($s['run_id'] ?? '') !== $run || ($s['state'] ?? '') === 'failed') return;
    $entered = ($s['stage'] ?? 'spawn') !== 'spawn' || (int) ($s['pid'] ?? 0) > 0;
    lifecycle_failure($key, $run, $entered ? 'process_exit' : 'spawn_failure');
}

/** Linux process birth identity protects readiness against PID reuse. */
function process_identity(int $pid): string
{
    if ($pid <= 0) return '';
    try { $stat = @file_get_contents('/proc/' . $pid . '/stat'); }
    catch (Throwable) { return ''; }
    if (!is_string($stat) || ($end = strrpos($stat, ')')) === false) return '';
    $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));
    return (string) ($fields[19] ?? '');
}

function lifecycle_read(string $key): array
{
    try { $s = read_json_file(account_dir($key) . '/lifecycle.json', []); }
    catch (Throwable) { return ['state' => 'failed', 'failure' => ['category' => 'state_corrupt', 'time' => time()]]; }
    return is_array($s) ? $s : ['state' => 'failed', 'failure' => ['category' => 'state_corrupt']];
}

function lifecycle_write(string $key, string $run, Closure $change): bool
{
    return with_file_lock(account_dir($key) . '/state.lock', static function () use ($key, $run, $change): bool {
        $state = lifecycle_read($key);
        if ($run !== '' && ($state['run_id'] ?? '') !== $run) return false;
        $next = $change($state);
        $next['updated_at'] = time();
        if (!json_write_atomic(account_dir($key) . '/lifecycle.json', $next)) throw new RuntimeException('Lifecycle state write failed');
        return true;
    });
}

function lifecycle_failure(string $key, string $run, string $category, ?Throwable $error = null, ?int $exit = null): void
{
    $failure = ['category' => $category, 'time' => time(), 'exit_code' => $exit];
    if ($error) $failure += ['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
    $written = lifecycle_write($key, $run, static function (array $s) use ($failure, $category): array {
        $attempts = (int) ($s['attempts'] ?? 0);
        return array_merge($s, ['state' => 'failed', 'failure' => $failure, 'heartbeat' => 0,
            'retry_at' => $attempts >= 3 || in_array($category, ['session_missing', 'spawn_failure', 'log_unwritable', 'state_corrupt', 'supervisor_account_error'], true) ? null : time() + min(60, 5 * (2 ** min($attempts, 3)))]);
    });
    if ($written) {
        $s = lifecycle_read($key);
        error_log(json_encode(['event' => 'worker_failure', 'account' => $key, 'worker' => 'bot',
            'stage' => $s['stage'] ?? null, 'attempts' => $s['attempts'] ?? 0,
            'last_attempt' => $s['last_attempt'] ?? null, 'retry_at' => $s['retry_at'] ?? null, 'failure' => $failure]));
    }
}

function worker_stage(string $stage): void
{
    $key = getenv('UBCC_ACCOUNT_KEY'); $run = getenv('UBCC_RUN_ID');
    if (!$key || !valid_account_key($key) || !$run) return;
    lifecycle_write($key, $run, static fn(array $s): array => array_merge($s, ['stage' => $stage]));
}

function worker_heartbeat(bool $ready = false): void
{
    $key = getenv('UBCC_ACCOUNT_KEY'); $run = getenv('UBCC_RUN_ID');
    if (!$key || !valid_account_key($key) || !$run) return;
    if (!is_file(account_dir($key) . '/enabled')) return;
    lifecycle_write($key, $run, static function (array $s) use ($key, $ready): array {
        $pid = (int) ($s['pid'] ?? 0);
        if (!process_owned($pid, $key) || process_identity($pid) !== ($s['identity'] ?? '')
            || !in_array($s['state'] ?? '', ['starting', 'running'], true)) return $s;
        if ($ready || ($s['state'] ?? '') === 'running') {
            $s['state'] = 'running'; $s['stage'] = 'handlers_ready'; $s['heartbeat'] = time();
            $s['ready_at'] ??= time();
            if (time() - $s['ready_at'] >= 60) $s['attempts'] = 0;
        }
        return $s;
    });
}

/** Invoked only after vendor handler/filter/plugin validation succeeds. */
function ubcc_event_handler_ready(): void
{
    worker_heartbeat(true);
    $key = getenv('UBCC_ACCOUNT_KEY');
    if ($key) {
        $s = lifecycle_read($key);
        if (($s['state'] ?? '') === 'running' && ($s['run_id'] ?? '') === getenv('UBCC_RUN_ID')) json_write_atomic(account_paths($key)['started_at'], $s['ready_at']);
    }
}

function lifecycle_status(string $key): array
{
    $s = lifecycle_read($key);
    $enabled = is_file(account_dir($key) . '/enabled');
    $alive = worker_running($key);
    $fresh = $alive && process_identity((int) ($s['pid'] ?? 0)) === ($s['identity'] ?? '')
        && (int) ($s['heartbeat'] ?? 0) > time() - 30;
    if (!$enabled) $s['state'] = $alive ? 'stopping' : 'stopped';
    elseif (($s['state'] ?? '') === 'running' && !$fresh) $s['state'] = $alive ? 'starting' : 'failed';
    elseif (!isset($s['state']) || $s['state'] === 'stopped') $s['state'] = 'queued';
    if ($enabled && !$alive && ($s['state'] ?? '') === 'queued' && time() - (int) ($s['requested_at'] ?? time()) > 30) {
        $s['state'] = 'failed'; $s['failure'] = ['category' => 'supervisor_unavailable', 'time' => time()];
    }
    $s['running'] = $enabled && $fresh && $s['state'] === 'running';
    $s['enabled'] = $enabled; $s['process_alive'] = $alive;
    return $s;
}

/** One account per try/catch in supervisor; retries survive supervisor restarts. */
function supervise_account(string $key): void
{
    with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key): void {
        $p = account_paths($key);
        if (is_file($p['dir'] . '/qr.requested')) {
            if (worker_running($key)) { terminate_worker($key, 'bot', false); return; }
            if (!worker_running($key, 'qr')) {
                $r = launch_worker($key, 'qr');
                if (!$r['ok']) json_write_atomic($p['qr_state'], ['ok' => false, 'status' => 'error', 'message' => $r['error']]);
            }
            unlink($p['dir'] . '/qr.requested');
        }
        if (!is_file($p['dir'] . '/enabled')) { if (worker_running($key)) terminate_worker($key, 'bot', false); return; }
        $s = lifecycle_read($key);
        if (worker_running($key, 'qr')) return;
        if (worker_running($key)) {
            if (!in_array($s['state'] ?? '', ['starting', 'running'], true)) { terminate_worker($key, 'bot', false); return; }
            if (($s['state'] ?? '') === 'starting' && time() - (int) ($s['last_attempt'] ?? time()) > 120) {
                lifecycle_failure($key, (string) ($s['run_id'] ?? ''), 'startup_timeout');
                terminate_worker($key, 'bot', false);
            } elseif (($s['state'] ?? '') === 'running' && time() - (int) ($s['heartbeat'] ?? 0) > 30) {
                lifecycle_failure($key, (string) ($s['run_id'] ?? ''), 'heartbeat_timeout'); terminate_worker($key, 'bot', false);
            }
            return;
        }
        if (in_array($s['state'] ?? '', ['starting', 'running'], true)) {
            lifecycle_failure($key, (string) ($s['run_id'] ?? ''), 'process_exit'); $s = lifecycle_read($key);
        }
        if (($s['state'] ?? '') === 'failed' && (($s['retry_at'] ?? null) === null || time() < $s['retry_at'])) return;
        if (!is_logged_in($key)) { lifecycle_failure($key, '', 'session_missing'); return; }
        if (($s['retry_at'] ?? 0) > 0) lifecycle_write($key, '', static fn(array $s): array => array_merge($s, ['state' => 'retrying']));
        $r = launch_worker($key, 'bot');
        if (!$r['ok'] && (lifecycle_read($key)['state'] ?? '') !== 'failed') lifecycle_failure($key, '', 'spawn_failure');
    }, true);
}

function terminate_worker(string $key, string $kind = 'bot', bool $wait = true): bool
{
    $p = account_paths($key);
    $file = $kind === 'qr' ? $p['qr_pid'] : $p['pid'];
    $pid = is_file($file) ? (int) file_get_contents($file) : 0;
    if ($kind === 'bot' && !process_owned($pid, $key, $kind)) {
        $s = lifecycle_read($key); $known = (int) ($s['pid'] ?? 0);
        if ($known > 0 && process_owned($known, $key) && process_identity($known) === ($s['identity'] ?? '')) $pid = $known;
    }
    if (!process_owned($pid, $key, $kind)) return true;
    if (!function_exists('posix_kill')) throw new RuntimeException('افزونه posix لازم است.');
    posix_kill($pid, SIGTERM);
    if (!$wait) return false;
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
