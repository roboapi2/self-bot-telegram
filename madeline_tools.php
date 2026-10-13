<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/bot_template.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

/**
 * Build an API handle for an account. When the bot process is already running,
 * MadelineProto connects to it over IPC instead of opening a second session.
 */
function make_api(string $key): API
{
    $cfg   = cfg();
    $paths = account_paths($key);
    putenv('UBCC_ACCOUNT_DIR=' . $paths['dir']);
    putenv('UBCC_ACCOUNT_KEY=' . $key);
    putenv('TG_TIMEZONE=' . $cfg['timezone']);

    initialize_madeline_runtime();
    $settings = new Settings;
    $appInfo  = new AppInfo;
    $appInfo->setApiId((int) $cfg['api_id']);
    $appInfo->setApiHash((string) $cfg['api_hash']);
    $settings->setAppInfo($appInfo);
    configure_madeline_logger($settings);

    $api = new API($paths['session'], $settings);
    // A stopped session may remember UserBot. Retire the handler, not its auth.
    if (!is_file($paths['dir'] . '/enabled') && !worker_running($key)) $api->unsetEventHandler();
    return $api;
}

function find_value(array $data, string $key): mixed
{
    foreach ($data as $k => $v) {
        if ((string) $k === $key && (is_scalar($v) || $v === null)) {
            return $v;
        }
        if (is_array($v)) {
            $r = find_value($v, $key);
            if ($r !== null && $r !== '') {
                return $r;
            }
        }
    }
    return null;
}

/** Kind from a normalized getPwrChat() array (reliable type field). */
function pwr_kind(array $chat): string
{
    return match (strtolower((string) ($chat['type'] ?? ''))) {
        'user', 'bot'              => 'private',
        'chat', 'group'            => 'group',
        'supergroup'               => 'group',
        'channel'                  => 'channel',
        default                    => 'unknown',
    };
}

/** Best human name from a getPwrChat() array, falling back gracefully. */
function pwr_title(array $chat, mixed $peer = null): string
{
    $name = trim((string) ($chat['first_name'] ?? '') . ' ' . (string) ($chat['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    if (!empty($chat['title'])) {
        return (string) $chat['title'];
    }
    if (!empty($chat['username'])) {
        return '@' . ltrim((string) $chat['username'], '@');
    }
    if (!empty($chat['id'])) {
        return 'گفتگو ' . $chat['id'];
    }
    return is_scalar($peer) ? (string) $peer : 'ناشناس';
}

function peer_kind(array $info): string
{
    $type      = strtolower((string) ($info['type'] ?? ''));
    $_         = strtolower((string) ($info['_'] ?? ''));
    $broadcast = strtolower((string) (find_value($info, 'broadcast') ?? ''));
    $megagroup = strtolower((string) (find_value($info, 'megagroup') ?? ''));

    if (str_contains($type, 'user') || str_contains($_, 'user')) {
        return 'private';
    }
    if (str_contains($type, 'chat') || str_contains($_, 'chat')) {
        return 'group';
    }
    if (str_contains($type, 'channel') || str_contains($_, 'channel')) {
        if ($broadcast === '1' || $broadcast === 'true') {
            return 'channel';
        }
        if ($megagroup === '1' || $megagroup === 'true') {
            return 'group';
        }
        return 'channel';
    }
    return 'unknown';
}

function message_media_kind(array $m): string
{
    $media = $m['media'] ?? [];
    if (!is_array($media)) return '';
    $type = strtolower((string) ($media['_'] ?? ''));
    if ($type === 'messagemediaphoto') return 'photo';
    if ($type === 'messagemediadocument') {
        foreach ($media['document']['attributes'] ?? [] as $attribute) {
            switch ($attribute['_'] ?? '') {
                case 'documentAttributeSticker': return 'sticker';
                case 'documentAttributeAnimated': return 'gif';
                case 'documentAttributeVideo': return 'video';
                case 'documentAttributeAudio': return !empty($attribute['voice']) ? 'voice' : 'audio';
            }
        }
        return 'document';
    }
    if (in_array($type, ['messagemediageo', 'messagemediageolive', 'messagemediavenue'], true)) return 'location';
    if ($type === 'messagemediacontact') return 'contact';
    if ($type === 'messagemediapoll') return 'poll';
    return '';
}

function normalize_msg(array $m): array
{
    return [
        'id'    => (int) ($m['id'] ?? 0),
        'date'  => isset($m['date']) ? date('Y-m-d H:i:s', (int) $m['date']) : '',
        'out'   => (bool) ($m['out'] ?? false),
        'text'  => (string) ($m['message'] ?? ''),
        'media' => message_media_kind($m),
    ];
}

function encode_peer(mixed $peer): string
{
    return base64_encode((string) json_encode($peer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function decode_peer(string $enc): mixed
{
    $raw = (string) base64_decode($enc, true);
    $decoded = json_decode($raw, true);
    return $decoded === null ? $raw : $decoded;
}

function peer_hash(mixed $peer): string
{
    return substr(sha1((string) json_encode($peer, JSON_UNESCAPED_UNICODE)), 0, 16);
}

/**
 * Dialog list with reliable names. Resolving each peer's name is a per-dialog
 * round-trip, so this is cached to disk (short TTL) and bounded by a time
 * budget — a huge account can never make the panel hang for long, and repeat
 * visits are instant.
 */
function fetch_chats(string $key, int $max = 200, float $budgetSec = 9.0, bool $forceRefresh = false, int $ttl = 120): array
{
    $cacheFile = account_paths($key)['chats_cache'];

    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = read_json_file($cacheFile, null);
        if (is_array($cached) && !empty($cached['ok']) && (time() - (int) ($cached['cached_at'] ?? 0)) < $ttl) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }

    $api = make_api($key);
    $api->start();

    $dialogs = method_exists($api, 'getDialogIds') ? $api->getDialogIds() : [];
    $items = [];
    $count = 0;
    $partial = false;
    $deadline = microtime(true) + $budgetSec;

    foreach ($dialogs as $peer) {
        if ($count >= $max) {
            break;
        }
        if (microtime(true) > $deadline) {
            $partial = true;
            break;
        }
        try {
            $chat = $api->getPwrChat($peer, false);
            if (!is_array($chat)) {
                continue;
            }
            $kind = pwr_kind($chat);
            if (!in_array($kind, ['private', 'channel', 'group'], true)) {
                continue;
            }
            $items[] = [
                'peer'     => encode_peer($peer),
                'title'    => pwr_title($chat, $peer),
                'kind'     => $kind,
                'username' => (string) ($chat['username'] ?? ''),
                'id'       => (string) ($chat['id'] ?? ''),
            ];
            $count++;
        } catch (Throwable $e) {
            // Skip peers we can't resolve.
        }
    }
    usort($items, fn($a, $b) => strcasecmp($a['title'], $b['title']));

    $result = [
        'ok'        => true,
        'items'     => $items,
        'capped'    => count($dialogs) > $max,
        'partial'   => $partial,
        'total'     => count($dialogs),
        'cached_at' => time(),
        'from_cache' => false,
    ];
    json_write_atomic($cacheFile, $result);
    return $result;
}

/**
 * Classify the media of a raw history message into a concrete kind plus any
 * inline data (geo for locations, contact details) we can render without a
 * download.
 */
function analyze_media(array $m): array
{
    $media = $m['media'] ?? null;
    if (!is_array($media)) {
        return ['kind' => 'none', 'size' => 0];
    }
    $t = (string) ($media['_'] ?? '');

    if ($t === 'messageMediaPhoto') {
        return ['kind' => 'photo', 'size' => 0];
    }
    if ($t === 'messageMediaGeo' || $t === 'messageMediaGeoLive') {
        $geo = $media['geo'] ?? [];
        return ['kind' => 'location', 'lat' => $geo['lat'] ?? null, 'long' => $geo['long'] ?? null, 'size' => 0];
    }
    if ($t === 'messageMediaVenue') {
        $geo = $media['geo'] ?? [];
        return ['kind' => 'location', 'lat' => $geo['lat'] ?? null, 'long' => $geo['long'] ?? null, 'venue' => (string) ($media['title'] ?? ''), 'size' => 0];
    }
    if ($t === 'messageMediaContact') {
        return ['kind' => 'contact', 'phone' => (string) ($media['phone_number'] ?? ''), 'name' => trim((string) ($media['first_name'] ?? '') . ' ' . (string) ($media['last_name'] ?? '')), 'size' => 0];
    }
    if ($t === 'messageMediaPoll') {
        return ['kind' => 'poll', 'size' => 0];
    }
    if ($t === 'messageMediaDocument') {
        $doc   = $media['document'] ?? [];
        $size  = (int) ($doc['size'] ?? 0);
        $isGif = $isVideo = $isVoice = $isAudio = $isSticker = false;
        foreach (($doc['attributes'] ?? []) as $a) {
            switch ($a['_'] ?? '') {
                case 'documentAttributeAnimated': $isGif = true; break;
                case 'documentAttributeVideo':    $isVideo = true; break;
                case 'documentAttributeSticker':  $isSticker = true; break;
                case 'documentAttributeAudio':    $isAudio = true; if (!empty($a['voice'])) $isVoice = true; break;
            }
        }
        $kind = $isSticker ? 'sticker' : ($isGif ? 'gif' : ($isVoice ? 'voice' : ($isVideo ? 'video' : ($isAudio ? 'audio' : 'document'))));
        return ['kind' => $kind, 'size' => $size];
    }
    return ['kind' => 'none', 'size' => 0];
}

/**
 * Chat history with real names and downloaded media of every type
 * (photo/video/voice/audio/gif/sticker/document). Files are cached under
 * accounts/<key>/chat_media/<peerHash>/ so re-opening is fast. Downloads are
 * capped by count and per-file size to keep the panel responsive.
 */
function fetch_history(string $key, mixed $peer, int $limit = 50, int $maxFiles = 25, int $maxBytes = 26214400): array
{
    $api = make_api($key);
    $api->start();

    try {
        $chat = $api->getPwrChat($peer, false);
        $chat = is_array($chat) ? $chat : [];
    } catch (Throwable $e) {
        $chat = [];
    }

    $history = $api->messages->getHistory(peer: $peer, limit: $limit, offset_id: 0);
    $raw = [];
    foreach (($history['messages'] ?? []) as $m) {
        if (is_array($m)) {
            $raw[] = $m;
        }
    }
    usort($raw, fn($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

    $ph  = peer_hash($peer);
    $dir = account_paths($key)['chat_media'] . '/' . $ph;

    $downloadable = ['photo', 'video', 'voice', 'audio', 'gif', 'sticker', 'document'];

    // Decide which messages to download (newest-first within the budget).
    $budget = $maxFiles;
    $wanted = [];
    $analysis = [];
    for ($i = count($raw) - 1; $i >= 0; $i--) {
        $a = analyze_media($raw[$i]);
        $analysis[$i] = $a;
        if ($budget > 0 && in_array($a['kind'], $downloadable, true)) {
            $size = (int) ($a['size'] ?? 0);
            if ($a['kind'] === 'photo' || $size === 0 || $size <= $maxBytes) {
                $wanted[(int) ($raw[$i]['id'] ?? 0)] = true;
                $budget--;
            }
        }
    }

    $messages = [];
    foreach ($raw as $i => $m) {
        $a = $analysis[$i] ?? analyze_media($m);
        $row = normalize_msg($m);
        $row['media_kind'] = $a['kind'];
        if ($a['kind'] === 'location') {
            $row['geo'] = ['lat' => $a['lat'] ?? null, 'long' => $a['long'] ?? null];
            if (!empty($a['venue'])) $row['venue'] = $a['venue'];
        } elseif ($a['kind'] === 'contact') {
            $row['contact'] = ['name' => $a['name'] ?? '', 'phone' => $a['phone'] ?? ''];
        }

        $id = (int) ($m['id'] ?? 0);
        if (isset($wanted[$id])) {
            $file = ensure_chat_media($api, $dir, $id, $m);
            if ($file !== null) {
                $row['media_url'] = 'api.php?action=chat_media&key=' . rawurlencode($key)
                    . '&ph=' . $ph . '&f=' . rawurlencode($file);
            }
        }
        $messages[] = $row;
    }

    return [
        'ok'   => true,
        'chat' => [
            'title'    => pwr_title($chat, $peer),
            'kind'     => pwr_kind($chat),
            'username' => (string) ($chat['username'] ?? ''),
        ],
        'messages' => $messages,
    ];
}

/** Download (or reuse cached) a single message's media; returns basename or null. */
function ensure_chat_media($api, string $dir, int $id, array $message): ?string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    foreach (glob($dir . '/' . $id . '.*') ?: [] as $cached) {
        return basename($cached);
    }
    try {
        $path = $api->downloadToDir($message, $dir);
        if (is_string($path) && is_file($path)) {
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin';
            $dest = $dir . '/' . $id . '.' . $ext;
            if ($path !== $dest) {
                @rename($path, $dest);
            }
            return basename($dest);
        }
    } catch (Throwable $e) {
        // ignore unresolved/expired media
    }
    return null;
}

/** Send a text message as the account (works over IPC when the bot is running). */
function send_account_message(string $key, mixed $peer, string $text): array
{
    try {
        $api = make_api($key);
        $api->start();
        $api->messages->sendMessage(peer: $peer, message: $text);
        return ['ok' => true, 'message' => 'ارسال شد.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'ارسال انجام نشد؛ دسترسی مقصد و محدودیت تلگرام را بررسی کنید.'];
    }
}
