<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

start_panel_session();
if (!panel_authenticated()) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// Explicit method allowlist: a GET can never start, stop or delete anything.
$readActions = ['accounts', 'account', 'qr_status', 'log', 'campaigns', 'chats', 'history', 'archive', 'archive_media', 'chat_media'];
$writeActions = ['create_account', 'rename_account', 'start', 'stop', 'restart', 'restart_all', 'start_all', 'stop_all', 'remove', 'qr_start', 'complete_2fa', 'clear_log', 'set_delete', 'set_edit', 'set_clock', 'campaign_create', 'campaign_toggle', 'campaign_delete', 'archive_delete', 'archive_clear', 'send_message', 'logout'];
if (!in_array($action, array_merge($readActions, $writeActions), true)) json_out(['ok' => false, 'error' => 'action نامعتبر است.'], 400);
$expected = in_array($action, $readActions, true) ? 'GET' : 'POST';
if ($method !== $expected) { header('Allow: ' . $expected); json_out(['ok' => false, 'error' => 'method not allowed'], 405); }
if ($expected === 'POST' && !check_csrf()) json_out(['ok' => false, 'error' => 'csrf'], 403);

// Release the session lock now that auth + CSRF are validated. Otherwise a slow
// request (e.g. loading chats) would hold the exclusive session lock and block
// every other panel request until it finishes — even with multiple workers.
// 'logout' is the only action that still needs to write to the session.
if ($action !== 'logout') {
    session_write_close();
}

/** Resolve and validate the account key for endpoints that need one. */
function req_account(): array
{
    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    if (!valid_account_key($key)) {
        json_out(['ok' => false, 'error' => 'کلید اکانت نامعتبر است.'], 400);
    }
    $accounts = load_accounts();
    if (!isset($accounts[$key])) {
        json_out(['ok' => false, 'error' => 'اکانت پیدا نشد.'], 404);
    }
    return [$key, $accounts[$key], $accounts];
}

try {
    // Serialize panel mutations; bot writes use the same data.lock per account.
    if ($expected === 'POST' && $action !== 'logout') {
        $keyForLock = $action === 'create_account' ? '' : (string) ($_GET['key'] ?? $_POST['key'] ?? '');
        if ($keyForLock !== '' && (!valid_account_key($keyForLock) || !isset(load_accounts()[$keyForLock]))) json_out(['ok' => false, 'error' => 'اکانت نامعتبر است.'], 400);
        if ($keyForLock !== '' && valid_account_key($keyForLock) && isset(load_accounts()[$keyForLock])) ensure_account_files($keyForLock);
        // Registry before data: a consistent order for rename/remove/create.
        if (in_array($action, ['rename_account', 'remove'], true)) { $registryLock = fopen(dirname(accounts_file()) . '/accounts.lock', 'c'); if (!$registryLock || !flock($registryLock, LOCK_EX)) throw new RuntimeException('قفل فهرست ممکن نشد.'); }
        $lockFile = $keyForLock !== '' && valid_account_key($keyForLock) ? account_dir($keyForLock) . '/data.lock' : dirname(accounts_file()) . '/accounts.lock';
        if (!in_array($action, ['start', 'stop', 'restart', 'start_all', 'stop_all', 'restart_all', 'qr_start', 'remove'], true)) {
            $requestLock = fopen($lockFile, 'c');
            if (!$requestLock || !flock($requestLock, LOCK_EX)) throw new RuntimeException('قفل داده ممکن نشد.');
        }
    }
    switch ($action) {

        /* ---- account listing & lifecycle ---- */

        case 'accounts': {
            $items = [];
            foreach (load_accounts() as $acc) {
                $items[] = $acc + ['status' => account_status($acc)];
            }
            json_out(['ok' => true, 'accounts' => $items]);
        }

        case 'account': {
            [$key, $acc] = req_account();
            json_out([
                'ok'        => true,
                'account'   => $acc + ['status' => account_status($acc)],
                'delete'    => get_feature_state($key, 'delete'),
                'edit'      => get_feature_state($key, 'edit'),
                'clock'     => get_feature_state($key, 'clock'),
                'campaigns' => array_values(load_campaigns($key)),
            ]);
        }

        case 'create_account': {
            $name = trim((string) ($_POST['name'] ?? ''));
            $key  = slugify((string) ($_POST['key'] ?? $name));
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'نام اکانت خالی است.']);
            }
            if (!valid_account_key($key)) {
                json_out(['ok' => false, 'error' => 'کلید نامعتبر است.']);
            }
            $accounts = load_accounts();
            if (isset($accounts[$key])) {
                json_out(['ok' => false, 'error' => 'این کلید قبلاً وجود دارد.']);
            }
            $accounts[$key] = ['key' => $key, 'name' => $name, 'created_at' => date('Y-m-d H:i:s')];
            ensure_account_files($key);
            save_accounts($accounts);
            json_out(['ok' => true, 'account' => $accounts[$key]]);
        }

        case 'rename_account': {
            [$key, $acc, $accounts] = req_account();
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'نام خالی است.']);
            }
            $accounts[$key]['name'] = $name;
            save_accounts($accounts);
            json_out(['ok' => true, 'message' => 'نام تغییر کرد.']);
        }

        case 'start':   { [$key] = req_account(); json_out(start_account($key)); }
        case 'stop':    { [$key] = req_account(); json_out(stop_account($key)); }
        case 'restart': { [$key] = req_account(); json_out(restart_account($key)); }

        case 'restart_all': {
            $res = restart_running_accounts();
            $n = count($res['restarted']);
            $f = count($res['failed']);
            json_out([
                'ok'      => empty($res['failed']),
                'message' => $n . ' درخواست ری‌استارت ثبت شد' . ($f ? "، {$f} ناموفق" : '') . '.',
                'result'  => $res,
            ]);
        }

        case 'start_all': {
            $res = start_all_accounts();
            $n = count($res['started']);
            $sk = count($res['skipped']);
            $f = count($res['failed']);
            json_out([
                'ok'      => empty($res['failed']),
                'message' => $n . ' درخواست شروع ثبت شد؛ آماده‌بودن را در وضعیت حساب بررسی کنید'
                    . ($sk ? "، {$sk} بدون لاگین رد شد" : '')
                    . ($f ? "، {$f} ناموفق" : '') . '.',
                'result'  => $res,
            ]);
        }

        case 'stop_all': {
            $res = stop_all_accounts();
            json_out([
                'ok'      => empty($res['failed']),
                'message' => count($res['stopped']) . ' اکانت خاموش شد.',
                'result'  => $res,
            ]);
        }

        case 'remove': {
            [$key, $acc, $accounts] = req_account();
            $stop = stop_account($key);
            if (!$stop['ok']) json_out($stop, 409);
            stop_qr_worker($key);
            $requestLock = fopen(account_dir($key) . '/data.lock', 'c');
            if (!$requestLock || !flock($requestLock, LOCK_EX)) throw new RuntimeException('قفل داده ممکن نشد.');
            unset($accounts[$key]);
            save_accounts($accounts);

            if (($_POST['purge'] ?? '') === '1') {
                purge_account_dir($key);
                json_out(['ok' => true, 'message' => 'اکانت و کل داده‌هایش حذف شد.']);
            }
            json_out(['ok' => true, 'message' => 'از لیست حذف شد. پوشه session نگه داشته شد.']);
        }

        /* ---- QR login ---- */

        case 'qr_start':  { [$key] = req_account(); json_out(start_qr_worker($key)); }
        case 'qr_status': { [$key] = req_account(); json_out(read_qr_state($key)); }

        case 'complete_2fa': {
            [$key] = req_account();
            $password = (string) ($_POST['password'] ?? '');
            if ($password === '') {
                json_out(['ok' => false, 'error' => 'رمز 2FA خالی است.']);
            }
            json_out(submit_2fa_password($key, $password));
        }

        /* ---- logs ---- */

        case 'log': {
            [$key] = req_account();
            $which = (string) ($_GET['which'] ?? 'bot');
            $p = account_paths($key);
            $file = $which === 'qr' ? $p['qr_log'] : $p['log'];
            json_out(['ok' => true, 'log' => tail_file($file, 12000)]);
        }

        case 'clear_log': {
            [$key] = req_account();
            $which = (string) ($_POST['which'] ?? 'bot');
            $p = account_paths($key);
            $file = $which === 'qr' ? $p['qr_log'] : $p['log'];
            @file_put_contents($file, '');
            json_out(['ok' => true, 'message' => 'لاگ پاک شد.']);
        }

        /* ---- feature config: deleted-message recovery ---- */

        case 'set_delete': {
            [$key] = req_account();
            $state = get_feature_state($key, 'delete');
            $state['enabled']     = ($_POST['enabled'] ?? '1') === '1';
            $state['chat_filter'] = normalize_chat_filter((string) ($_POST['chat_filter'] ?? $state['chat_filter']));
            $state['full_chat_enabled']   = ($_POST['full_chat_enabled'] ?? '1') === '1';
            $state['full_chat_threshold'] = max(5, min(500, (int) ($_POST['full_chat_threshold'] ?? $state['full_chat_threshold'])));
            $tt = (string) ($_POST['target_type'] ?? $state['target_type']);
            if ($tt === 'channel') {
                $peer = normalize_channel_peer((string) ($_POST['channel_peer'] ?? ''));
                if ($peer === '') {
                    json_out(['ok' => false, 'error' => 'برای مقصد کانال، نام کاربری/آیدی کانال لازم است.']);
                }
                $state['target_type']  = 'channel';
                $state['channel_peer'] = $peer;
            } else {
                $state['target_type']  = 'save_message';
                $state['channel_peer'] = null;
            }
            set_feature_state($key, 'delete', $state);
            json_out(['ok' => true, 'message' => 'تنظیمات حذف ذخیره شد.', 'delete' => $state]);
        }

        /* ---- feature config: edited-message recovery ---- */

        case 'set_edit': {
            [$key] = req_account();
            $state = get_feature_state($key, 'edit');
            $state['enabled']     = ($_POST['enabled'] ?? '1') === '1';
            $state['chat_filter'] = normalize_chat_filter((string) ($_POST['chat_filter'] ?? $state['chat_filter']));
            $tt = (string) ($_POST['target_type'] ?? $state['target_type']);
            if ($tt === 'channel') {
                $peer = normalize_channel_peer((string) ($_POST['channel_peer'] ?? ''));
                if ($peer === '') {
                    json_out(['ok' => false, 'error' => 'برای مقصد کانال، نام کاربری/آیدی کانال لازم است.']);
                }
                $state['target_type']  = 'channel';
                $state['channel_peer'] = $peer;
            } else {
                $state['target_type']  = 'save_message';
                $state['channel_peer'] = null;
            }
            set_feature_state($key, 'edit', $state);
            json_out(['ok' => true, 'message' => 'تنظیمات ویرایش ذخیره شد.', 'edit' => $state]);
        }

        /* ---- feature config: profile clock ---- */

        case 'set_clock': {
            [$key] = req_account();
            $on = ($_POST['enabled'] ?? '') === '1';
            $state = get_feature_state($key, 'clock');
            if ($on) {
                $state['enabled'] = true;
                $state['restore_pending'] = false;
            } else {
                // Ask the running bot to strip the clock suffix and restore the name once.
                $state['enabled'] = false;
                $state['restore_pending'] = true;
            }
            set_feature_state($key, 'clock', $state);
            json_out(['ok' => true, 'message' => $on ? 'ساعت روشن شد.' : 'ساعت خاموش شد.', 'clock' => $state]);
        }

        /* ---- auto-send campaigns ---- */

        case 'campaigns': {
            [$key] = req_account();
            json_out(['ok' => true, 'campaigns' => array_values(load_campaigns($key))]);
        }

        case 'campaign_create': {
            [$key] = req_account();
            $targetsRaw = (string) ($_POST['targets'] ?? '');
            $message    = (string) ($_POST['message'] ?? '');
            $interval   = max(10, (int) ($_POST['interval'] ?? 0));
            $targets    = normalize_targets_panel($targetsRaw);

            if ($targets === []) {
                json_out(['ok' => false, 'error' => 'هیچ نام کاربری معتبری پیدا نشد (فقط username عمومی).']);
            }
            if (trim($message) === '') {
                json_out(['ok' => false, 'error' => 'متن پیام خالی است.']);
            }

            $campaigns = load_campaigns($key);
            $id = 'msg_' . date('Ymd_His') . '_' . random_int(100, 999);
            $campaigns[$id] = [
                'id'               => $id,
                'enabled'          => true,
                'targets'          => $targets,
                'message'          => $message,
                'interval_minutes' => $interval,
                'created_at'       => time(),
                'last_run_at'      => null,
                'next_run_at'      => time(),
            ];
            save_campaigns($key, $campaigns);
            json_out(['ok' => true, 'message' => 'کمپین ساخته شد.', 'id' => $id, 'campaigns' => array_values($campaigns)]);
        }

        case 'campaign_toggle': {
            [$key] = req_account();
            $id = (string) ($_POST['id'] ?? '');
            $enable = ($_POST['enabled'] ?? '') === '1';
            $campaigns = load_campaigns($key);
            if (!isset($campaigns[$id])) {
                json_out(['ok' => false, 'error' => 'کمپین پیدا نشد.']);
            }
            $campaigns[$id]['enabled'] = $enable;
            $campaigns[$id]['next_run_at'] = $enable ? time() : (time() + 31536000);
            save_campaigns($key, $campaigns);
            json_out(['ok' => true, 'message' => $enable ? 'فعال شد.' : 'متوقف شد.', 'campaigns' => array_values($campaigns)]);
        }

        case 'campaign_delete': {
            [$key] = req_account();
            $id = (string) ($_POST['id'] ?? '');
            $campaigns = load_campaigns($key);
            if (!isset($campaigns[$id])) {
                json_out(['ok' => false, 'error' => 'کمپین پیدا نشد.']);
            }
            unset($campaigns[$id]);
            save_campaigns($key, $campaigns);
            json_out(['ok' => true, 'message' => 'حذف شد.', 'campaigns' => array_values($campaigns)]);
        }

        /* ---- live data (requires the bot process running, served over IPC) ---- */

        case 'chats':
        case 'history': {
            [$key, $acc] = req_account();
            $status = account_status($acc);
            if (!$status['logged_in']) {
                json_out(['ok' => false, 'error' => 'این اکانت Login نشده.']);
            }
            if (!$status['running']) {
                json_out(['ok' => false, 'error' => 'برای مشاهده چت‌ها اول اکانت را Start کن.']);
            }
            require_once __DIR__ . '/madeline_tools.php';

            if ($action === 'chats') {
                $refresh = ((string) ($_GET['refresh'] ?? '')) === '1';
                json_out(fetch_chats($key, 200, 9.0, $refresh));
            }
            $peer = decode_peer((string) ($_GET['peer'] ?? ''));
            json_out(fetch_history($key, $peer));
        }

        /* ---- archive of genuinely deleted / view-once messages ---- */

        case 'archive': {
            [$key] = req_account();
            $p = account_paths($key);
            $archive = (array) read_json_file($p['archive'], []);
            $filter = (string) ($_GET['filter'] ?? 'all'); // all | media | text | deleted | view_once
            $items = [];
            $mediaDir = $p['archive_media'];

            foreach (array_reverse($archive) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mediaFile = (string) ($entry['media_file'] ?? '');
                $hasMedia  = $mediaFile !== '' && is_file($mediaDir . '/' . $mediaFile);
                $type      = (string) ($entry['media_type'] ?? 'none');
                $isImage   = in_array($type, ['photo'], true);
                $kind      = (string) ($entry['type'] ?? 'deleted');

                if ($filter === 'media' && !$hasMedia) continue;
                if ($filter === 'text' && trim((string) ($entry['text'] ?? '')) === '') continue;
                if ($filter === 'deleted' && $kind !== 'deleted') continue;
                if ($filter === 'view_once' && $kind !== 'view_once') continue;

                $items[] = [
                    'archive_id'  => (string) ($entry['archive_id'] ?? ''),
                    'kind'        => $kind,
                    'id'          => $entry['id'] ?? null,
                    'chat'        => $entry['chat_info']['name'] ?? ($entry['chat_id'] ?? 'ناشناس'),
                    'chat_kind'   => $entry['chat_kind'] ?? 'unknown',
                    'sender'      => $entry['sender_info']['name'] ?? ($entry['sender_id'] ?? 'ناشناس'),
                    'text'        => (string) ($entry['text'] ?? ''),
                    'special'     => (string) ($entry['special_content'] ?? ''),
                    'media_type'  => $type,
                    'has_media'   => $hasMedia,
                    'is_image'    => $hasMedia && $isImage,
                    'media_url'   => $hasMedia ? ('api.php?action=archive_media&key=' . rawurlencode($key) . '&f=' . rawurlencode($mediaFile)) : null,
                    'original_date' => (string) ($entry['original_date'] ?? ''),
                    'deleted_at'  => (string) ($entry['deleted_at'] ?? ''),
                ];
            }
            json_out([
                'ok'    => true,
                'items' => array_slice($items, 0, 500),
                'total' => count($archive),
            ]);
        }

        case 'archive_media': {
            [$key] = req_account();
            $file = safe_media_path(account_paths($key)['archive_media'], (string) ($_GET['f'] ?? ''));
            if ($file === null) {
                json_out(['ok' => false, 'error' => 'فایل پیدا نشد.'], 404);
            }
            stream_media_file($file);
        }

        case 'archive_delete': {
            [$key] = req_account();
            $id = (string) ($_POST['archive_id'] ?? '');
            $p = account_paths($key);
            $archive = (array) read_json_file($p['archive'], []);
            $kept = [];
            foreach ($archive as $entry) {
                if (is_array($entry) && (string) ($entry['archive_id'] ?? '') === $id) {
                    if (!empty($entry['media_file'])) {
                        $mf = safe_media_path($p['archive_media'], (string) $entry['media_file']);
                        if ($mf !== null) @unlink($mf);
                    }
                    continue;
                }
                $kept[] = $entry;
            }
            json_write_atomic($p['archive'], $kept);
            json_out(['ok' => true, 'message' => 'حذف شد.']);
        }

        case 'archive_clear': {
            [$key] = req_account();
            $p = account_paths($key);
            json_write_atomic($p['archive'], []);
            if (is_dir($p['archive_media'])) {
                foreach (glob($p['archive_media'] . '/*') ?: [] as $f) {
                    @unlink($f);
                }
            }
            json_out(['ok' => true, 'message' => 'کل آرشیو پاک شد.']);
        }

        /* ---- send a message as the account (IPC) ---- */

        case 'send_message': {
            [$key, $acc] = req_account();
            $status = account_status($acc);
            if (!$status['running']) {
                json_out(['ok' => false, 'error' => 'برای ارسال پیام باید اکانت روشن باشد.']);
            }
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '') {
                json_out(['ok' => false, 'error' => 'متن پیام خالی است.']);
            }
            require_once __DIR__ . '/madeline_tools.php';
            $peer = decode_peer((string) ($_POST['peer'] ?? ''));
            json_out(send_account_message($key, $peer, $text));
        }

        case 'chat_media': {
            [$key] = req_account();
            $ph  = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['ph'] ?? ''));
            $dir = account_paths($key)['chat_media'] . '/' . $ph;
            $file = safe_media_path($dir, (string) ($_GET['f'] ?? ''));
            if ($file === null) {
                json_out(['ok' => false, 'error' => 'فایل پیدا نشد.'], 404);
            }
            stream_media_file($file);
        }

        case 'logout': {
            logout_panel();
            json_out(['ok' => true]);
        }
    }

    json_out(['ok' => false, 'error' => 'action نامعتبر است.'], 400);
} catch (Throwable $e) {
    error_log('Panel API failure: ' . get_class($e));
    json_out(['ok' => false, 'error' => 'عملیات انجام نشد؛ وضعیت فایل‌ها و لاگ سرویس را بررسی کنید.'], 500);
}

/* ---------------------------------------------------------------------------
 * helpers used only by the API layer
 * ------------------------------------------------------------------------- */

function purge_account_dir(string $key): void
{
    if (!valid_account_key($key)) {
        return;
    }
    $dir = account_dir($key);
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function normalize_targets_panel(string $raw): array
{
    $targets = [];
    foreach (preg_split('/[,\n\r]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        $part = preg_replace('#^https?://t\.me/#i', '', $part) ?? $part;
        $part = preg_replace('#^t\.me/#i', '', $part) ?? $part;
        $part = trim($part, " \t\n\r\0\x0B/@");
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $part)) {
            continue;
        }
        $targets['@' . $part] = true;
    }
    return array_slice(array_keys($targets), 0, 20);
}
