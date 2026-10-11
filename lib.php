<?php
declare(strict_types=1);
umask(0077);
ini_set('zend.exception_ignore_args', '1');
if (PHP_SAPI !== 'cli') ini_set('display_errors', '0');
require_once __DIR__ . "/runtime.php";

/**
 * UserBot Control Center — core library.
 * Account lifecycle, process management, per-account JSON state, panel auth.
 */

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $defaults = [
            'admin_password' => 'CHANGE_THIS_PASSWORD',
            'api_id'         => 0,
            'api_hash'       => '',
            'base_dir'       => __DIR__,
            'php_bin'        => PHP_SAPI === 'cli' ? PHP_BINARY : '/usr/bin/php',
            'process_mode'   => 'direct',
            'panel_title'    => 'UserBot Control Center',
            'timezone'       => 'Asia/Tehran',
            'session_ttl'    => 86400,
        ];
        $loaded = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
        $cfg = array_merge($defaults, is_array($loaded) ? $loaded : []);
        // Deployment overrides work with existing config.php without rewriting it.
        foreach (['UBCC_PHP_BIN' => 'php_bin', 'UBCC_PROCESS_MODE' => 'process_mode'] as $env => $field) {
            if (getenv($env) !== false && getenv($env) !== '') $cfg[$field] = getenv($env);
        }
        date_default_timezone_set((string) $cfg['timezone']);
    }
    return $cfg;
}

function base_dir(): string
{
    $cfg = cfg();
    $root = realpath((string) $cfg['base_dir']);
    if ($root === false || !is_dir($root)) throw new RuntimeException('base_dir نامعتبر است؛ مسیر پوشه پروژه را اصلاح کنید.');
    return $root;
}

function accounts_dir(): string
{
    return base_dir() . '/accounts';
}

function accounts_file(): string
{
    if (is_file(base_dir() . '/.panel/accounts.json')) return base_dir() . '/.panel/accounts.json';
    if (is_file(base_dir() . '/.panel/storage.initialized') || cfg()['process_mode'] === 'supervisor') throw new RuntimeException('فهرست فعال حساب‌ها موجود نیست؛ بازیابی یا مهاجرت لازم است.');
    return base_dir() . '/accounts.json';
}

function h(string|int|float|null $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Atomic JSON write to avoid half-written files under concurrent access. */
function json_write_atomic(string $file, mixed $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    $ok = @rename($tmp, $file);
    if (!$ok) @unlink($tmp);
    return $ok;
}

function read_json_file(string $file, mixed $default = []): mixed
{
    if (!is_file($file)) {
        return $default;
    }
    // Never silently replace a corrupted state file with empty user data.
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

function load_accounts(): array
{
    $data = read_json_file(accounts_file(), []);
    return validate_account_registry($data);
}

function validate_account_registry(mixed $data): array
{
    if (!is_array($data)) throw new RuntimeException('ساختار فهرست حساب‌ها نامعتبر است.');
    $out = [];
    foreach ($data as $acc) {
        if (!is_array($acc) || !isset($acc['key']) || !is_string($acc['key']) || !valid_account_key($acc['key'])) throw new RuntimeException('رکورد نامعتبر در فهرست حساب‌ها؛ فایل حفظ شد.');
        if (isset($out[$acc['key']])) throw new RuntimeException('کلید تکراری در فهرست حساب‌ها؛ فایل حفظ شد.');
        $out[$acc['key']] = $acc;
    }
    return $out;
}

function save_accounts(array $accounts): void
{
    validate_account_registry(array_values($accounts));
    if (!json_write_atomic(accounts_file(), array_values($accounts))) throw new RuntimeException('ذخیره حساب‌ها ممکن نشد.');
}

function slugify(string $v): string
{
    $v = strtolower(trim($v));
    $v = preg_replace('/[^a-z0-9_\-]+/i', '_', $v) ?? '';
    $v = trim($v, '_-');
    return $v !== '' ? $v : 'acc_' . date('Ymd_His');
}

/** A key is only ever a slug; reject anything that could escape the accounts dir. */
function valid_account_key(string $key): bool
{
    return (bool) preg_match('/^[a-z0-9_\-]{1,64}$/i', $key);
}

function account_dir(string $key): string
{
    if (!valid_account_key($key)) throw new InvalidArgumentException('کلید اکانت نامعتبر است.');
    if (is_link(accounts_dir()) || is_link(accounts_dir() . '/' . $key)) throw new RuntimeException('پوشه حساب نباید پیوند نمادین باشد.');
    return accounts_dir() . '/' . $key;
}

function account_paths(string $key): array
{
    $dir = account_dir($key);
    return [
        'dir'            => $dir,
        'bot'            => $dir . '/bot.php',
        'session'        => $dir . '/session.madeline',
        'pid'            => $dir . '/bot.pid',
        'log'            => $dir . '/bot.log',
        'qr_state'       => $dir . '/qr_state.json',
        'qr_pid'         => $dir . '/qr_worker.pid',
        'qr_log'         => $dir . '/qr_worker.log',
        'qr_2fa'         => $dir . '/qr_2fa.secret',
        'started_at'     => $dir . '/started_at',
        'delete_state'   => $dir . '/delete_state.json',
        'edit_state'     => $dir . '/edit_state.json',
        'clock_state'    => $dir . '/clock_state.json',
        'auto_campaigns' => $dir . '/auto_campaigns.json',
        'cache'          => $dir . '/deleted_cache.json',
        'media'          => $dir . '/deleted_media',
        'chat_media'     => $dir . '/chat_media',
        'archive'        => $dir . '/deleted_archive.json',
        'archive_media'  => $dir . '/archive_media',
        'chats_cache'    => $dir . '/chats_cache.json',
    ];
}

/**
 * Resolve a file inside one of an account's media folders, guarding against
 * path traversal. Returns the absolute path only if it is really inside $baseDir.
 */
function safe_media_path(string $baseDir, string $name): ?string
{
    $name = basename($name); // strip any directory component
    if ($name === '' || $name[0] === '.') {
        return null;
    }
    $full = $baseDir . '/' . $name;
    $real = realpath($full);
    $base = realpath($baseDir);
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
        return null;
    }
    return $real;
}

/** Stream a media file to the browser with a sensible content type. */
function stream_media_file(string $path): never
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav',
    ];
    $mime = $types[$ext] ?? 'application/octet-stream';
    if (!headers_sent()) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
    readfile($path);
    exit;
}

function ensure_account_files(string $key): void
{
    $dir = account_dir($key);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Preserve legacy per-account code; the shared runner does not execute it.
}

function pid_running(?int $pid): bool
{
    if (!$pid || $pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    $out = [];
    @exec('ps -p ' . (int) $pid . ' -o pid=', $out);
    return trim(implode('', $out)) !== '';
}

function read_pid(string $key): ?int
{
    $p = account_paths($key)['pid'];
    if (!is_file($p)) {
        return null;
    }
    $pid = (int) trim((string) file_get_contents($p));
    return $pid > 0 ? $pid : null;
}

function is_logged_in(string $key): bool
{
    $state = account_paths($key)['qr_state'];
    if (is_file($state)) {
        $qr = read_json_file($state, []);
        if (array_key_exists('logged_in', $qr) && !$qr['logged_in']) return false;
    }
    $s = account_paths($key)['session'];
    return is_file($s) || is_dir($s);
}

function account_status(array $acc): array
{
    $key   = (string) $acc['key'];
    $paths = account_paths($key);
    $pid   = read_pid($key);
    $running = worker_running($key);

    $startedAt = null;
    $uptime = null;
    if ($running && is_file($paths['started_at'])) {
        $startedAt = (int) trim((string) file_get_contents($paths['started_at']));
        if ($startedAt > 0) {
            $uptime = time() - $startedAt;
        }
    }

    return [
        'running'        => $running,
        'pid'            => $pid,
        'logged_in'      => is_logged_in($key),
        'started_at'     => $startedAt,
        'uptime'         => $uptime,
        'cache_count'    => is_file($paths['cache']) ? count((array) read_json_file($paths['cache'], [])) : 0,
        'campaign_count' => is_file($paths['auto_campaigns']) ? count((array) read_json_file($paths['auto_campaigns'], [])) : 0,
    ];
}

function start_account(string $key): array
{
    return with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key): array {
        $accounts = load_accounts();
        if (!isset($accounts[$key])) return ['ok' => false, 'error' => 'اکانت پیدا نشد.'];
        ensure_account_files($key);
        if (!is_logged_in($key)) return ['ok' => false, 'error' => 'ابتدا ورود QR را کامل کنید.'];
        if (worker_running($key, 'qr')) return ['ok' => false, 'error' => 'ورود QR هنوز در حال اجراست؛ کمی صبر کنید.'];
        $p = account_paths($key);
        if (!json_write_atomic($p['dir'] . '/enabled', true)) throw new RuntimeException('ذخیره وضعیت اجرا ممکن نشد.');
        if (worker_running($key)) return ['ok' => true, 'message' => 'اکانت روشن است.', 'status' => account_status($accounts[$key])];
        if (cfg()['process_mode'] === 'supervisor') return ['ok' => true, 'message' => 'در صف اجرا؛ وضعیت چند ثانیه دیگر به‌روز می‌شود.'];
        return launch_worker($key, 'bot');
    });
}

function stop_account(string $key): array
{
    return with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key): array {
        $p = account_paths($key);
        @unlink($p['dir'] . '/enabled');
        return terminate_worker($key) ? ['ok' => true, 'message' => 'خاموش شد.']
            : ['ok' => false, 'error' => 'توقف هنوز کامل نشده است؛ لاگ را بررسی کنید.'];
    });
}

function restart_account(string $key): array
{
    $result = stop_account($key);
    return $result['ok'] ? start_account($key) : $result;
}

/** Restart every currently-running account — used to roll out a code update. */
function restart_running_accounts(): array
{
    $restarted = [];
    $failed = [];
    foreach (load_accounts() as $key => $acc) {
        if (!account_status($acc)['running']) {
            continue;
        }
        $r = restart_account($key);
        if (!empty($r['ok'])) {
            $restarted[] = $key;
        } else {
            $failed[$key] = $r['error'] ?? 'error';
        }
    }
    return ['restarted' => $restarted, 'failed' => $failed];
}

/** Start every logged-in account that isn't already running. */
function start_all_accounts(): array
{
    $started = [];
    $skipped = [];
    $failed = [];
    foreach (load_accounts() as $key => $acc) {
        $st = account_status($acc);
        if ($st['running']) {
            continue;
        }
        if (!$st['logged_in']) {
            $skipped[] = $key;
            continue;
        }
        $r = start_account($key);
        if (!empty($r['ok'])) {
            $started[] = $key;
        } else {
            $failed[$key] = $r['error'] ?? 'error';
        }
    }
    return ['started' => $started, 'skipped' => $skipped, 'failed' => $failed];
}

/** Stop every currently-running account. */
function stop_all_accounts(): array
{
    $stopped = [];
    $failed = [];
    foreach (load_accounts() as $key => $acc) {
        try {
            $result = stop_account($key);
            stop_qr_worker($key);
            if ($result['ok']) $stopped[] = $key; else $failed[$key] = $result['error'];
        } catch (Throwable) { $failed[$key] = 'توقف حساب کامل نشد؛ لاگ را بررسی کنید.'; }
    }
    return ['stopped' => $stopped, 'failed' => $failed];
}

/* ---------------------------------------------------------------------------
 * QR login worker
 * ------------------------------------------------------------------------- */

function start_qr_worker(string $key): array
{
    $stopped = stop_account($key);
    if (!$stopped['ok']) return $stopped;
    return with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key): array {
        ensure_account_files($key);
        if (!terminate_worker($key, 'qr')) return ['ok' => false, 'error' => 'کارگر قبلی هنوز متوقف نشده است.'];
        $p = account_paths($key);
        @unlink($p['qr_2fa']);
        json_write_atomic($p['qr_state'], ['ok' => true, 'status' => 'queued', 'message' => 'در صف ساخت QR...', 'svg' => null, 'logged_in' => false, 'needs_2fa' => false]);
        if (cfg()['process_mode'] === 'supervisor') {
            if (!json_write_atomic($p['dir'] . '/qr.requested', time())) throw new RuntimeException('ثبت درخواست ممکن نشد.');
            return ['ok' => true, 'message' => 'درخواست ورود ثبت شد.'];
        }
        return launch_worker($key, 'qr');
    });
}

function stop_qr_worker(string $key): void
{
    with_file_lock(account_dir($key) . '/lifecycle.lock', static function () use ($key): void {
        @unlink(account_dir($key) . '/qr.requested');
        if (!terminate_worker($key, 'qr')) throw new RuntimeException('توقف کارگر QR کامل نشد.');
        @unlink(account_paths($key)['qr_2fa']);
    });
}

function read_qr_state(string $key): array
{
    $file = account_paths($key)['qr_state'];
    if (!is_file($file)) {
        return ['ok' => true, 'status' => 'empty', 'message' => 'هنوز QR ساخته نشده.', 'svg' => null, 'logged_in' => false, 'needs_2fa' => false];
    }
    $data = read_json_file($file, null);
    return is_array($data) ? $data : ['ok' => false, 'status' => 'error', 'message' => 'qr_state.json خراب است.'];
}

/** The web layer never logs in directly; it hands the password to the worker. */
function submit_2fa_password(string $key, string $password): array
{
    $p = account_paths($key);
    $worker = is_file($p['qr_pid']) ? (int) trim((string) file_get_contents($p['qr_pid'])) : 0;
    if (!process_owned($worker, $key, 'qr')) {
        return ['ok' => false, 'error' => 'QR worker در حال اجرا نیست. دوباره QR Login را شروع کن.'];
    }
    if (strlen($password) > 1024) return ['ok' => false, 'error' => 'رمز بیش از حد طولانی است.'];
    $temporary = $p['qr_2fa'] . '.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporary, $password, LOCK_EX) === false || !@rename($temporary, $p['qr_2fa'])) {
        @unlink($temporary);
        return ['ok' => false, 'error' => 'ذخیره رمز 2FA ممکن نشد.'];
    }
    @chmod($p['qr_2fa'], 0600);
    return ['ok' => true, 'message' => 'رمز 2FA ارسال شد؛ در حال تکمیل ورود...'];
}

/* ---------------------------------------------------------------------------
 * Per-account feature state (shared live with the running bot via JSON files)
 * ------------------------------------------------------------------------- */

function default_delete_state(): array
{
    return [
        'enabled'              => true,
        'target_type'          => 'save_message',
        'channel_peer'         => null,
        'chat_filter'          => 'all',
        'full_chat_enabled'    => true,
        'full_chat_threshold'  => 25,
    ];
}

function default_edit_state(): array
{
    return [
        'enabled'      => true,
        'target_type'  => 'save_message',
        'channel_peer' => null,
        'chat_filter'  => 'all',
    ];
}

function default_clock_state(): array
{
    return [
        'enabled'         => false,
        'base_first_name' => null,
        'base_last_name'  => null,
        'restore_pending' => false,
    ];
}

function get_feature_state(string $key, string $which): array
{
    $p = account_paths($key);
    return match ($which) {
        'delete' => array_merge(default_delete_state(), (array) read_json_file($p['delete_state'], [])),
        'edit'   => array_merge(default_edit_state(), (array) read_json_file($p['edit_state'], [])),
        'clock'  => array_merge(default_clock_state(), (array) read_json_file($p['clock_state'], [])),
        default  => [],
    };
}

function set_feature_state(string $key, string $which, array $state): void
{
    $p = account_paths($key);
    $file = match ($which) {
        'delete' => $p['delete_state'],
        'edit'   => $p['edit_state'],
        'clock'  => $p['clock_state'],
        default  => null,
    };
    if ($file !== null) {
        if (!json_write_atomic($file, $state)) throw new RuntimeException('ذخیره تنظیمات ممکن نشد.');
    }
}

function normalize_channel_peer(string $peer): string
{
    $peer = trim($peer);
    $peer = preg_replace('#^https?://t\.me/#i', '', $peer) ?? $peer;
    $peer = preg_replace('#^t\.me/#i', '', $peer) ?? $peer;
    $peer = trim($peer, " \t\n\r\0\x0B/@");
    if ($peer === '') {
        return '';
    }
    return preg_match('/^-?\d+$/', $peer) ? $peer : '@' . $peer;
}

function normalize_chat_filter(string $filter): string
{
    return match (strtolower(trim($filter))) {
        'all'              => 'all',
        'channel', 'channels' => 'channels',
        'group', 'groups'  => 'groups',
        'private', 'pv'    => 'private',
        default            => 'all',
    };
}

function load_campaigns(string $key): array
{
    $data = read_json_file(account_paths($key)['auto_campaigns'], []);
    return is_array($data) ? $data : [];
}

function save_campaigns(string $key, array $campaigns): void
{
    if (!json_write_atomic(account_paths($key)['auto_campaigns'], $campaigns)) throw new RuntimeException('ذخیره کمپین ممکن نشد.');
}

function tail_file(string $file, int $bytes = 8000): string
{
    if (!is_file($file)) {
        return '';
    }
    $size = filesize($file);
    $fh = fopen($file, 'rb');
    if (!$fh) {
        return '';
    }
    if ($size > $bytes) {
        fseek($fh, -$bytes, SEEK_END);
    }
    $data = stream_get_contents($fh);
    fclose($fh);
    return redact_secrets((string) $data);
}

/* ---------------------------------------------------------------------------
 * Panel authentication (session + CSRF + brute-force throttle)
 * ------------------------------------------------------------------------- */

function start_panel_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function csrf_token(): string
{
    start_panel_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): bool
{
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
}

function panel_authenticated(): bool
{
    start_panel_session();
    $cfg = cfg();
    if (empty($_SESSION['panel_ok'])) {
        return false;
    }
    if (isset($_SESSION['panel_at']) && (time() - (int) $_SESSION['panel_at']) > (int) $cfg['session_ttl']) {
        $_SESSION = [];
        return false;
    }
    return true;
}

function require_login(): void
{
    start_panel_session();
    $cfg = cfg();

    if (panel_authenticated()) {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['panel_password'] ?? '') !== '') {
        // Persistent per-address throttle survives cookie resets.
        $throttleDir = base_dir() . '/.panel/throttle';
        if (!is_dir($throttleDir)) mkdir($throttleDir, 0700, true);
        $throttleFile = $throttleDir . '/' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')) . '.json';
        $throttleLock = fopen($throttleFile . '.lock', 'c');
        if (!$throttleLock || !flock($throttleLock, LOCK_EX)) throw new RuntimeException('Login throttle unavailable');
        $throttle = read_json_file($throttleFile, []);
        $fails = time() - (int) ($throttle['at'] ?? 0) < 300 ? (int) ($throttle['fails'] ?? 0) : 0;
        $lockUntil = (int) ($throttle['lock'] ?? 0);
        if (time() < $lockUntil) {
            $GLOBALS['login_error'] = 'تلاش زیاد. کمی صبر کن.';
            $GLOBALS['need_login'] = true;
            return;
        }
        $configured = (string) $cfg['admin_password'];
        if ($configured !== '' && $configured !== 'CHANGE_THIS_PASSWORD' && check_csrf() && (str_starts_with($configured, '$') ? password_verify((string) $_POST['panel_password'], $configured) : hash_equals($configured, (string) $_POST['panel_password']))) {
            session_regenerate_id(true);
            $_SESSION['panel_ok'] = true;
            $_SESSION['panel_at'] = time();
            $_SESSION['login_fails'] = 0;
            json_write_atomic($throttleFile, []);
            flock($throttleLock, LOCK_UN);
            fclose($throttleLock);
            csrf_token();
            header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
            exit;
        }
        $fails++;
        json_write_atomic($throttleFile, ['fails' => $fails >= 5 ? 0 : $fails, 'lock' => $fails >= 5 ? time() + 60 : 0, 'at' => time()]);
        flock($throttleLock, LOCK_UN);
        fclose($throttleLock);
        $GLOBALS['login_error'] = 'رمز اشتباه است.';
    }

    $GLOBALS['need_login'] = true;
}

function logout_panel(): void
{
    start_panel_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
