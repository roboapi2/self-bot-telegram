<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    fwrite(STDERR, "vendor/autoload.php پیدا نشد. داخل پوشه اصلی پنل composer install را اجرا کن.\n");
    exit(1);
}

use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\LocalFile;

use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\IsEdited;
use danog\MadelineProto\EventHandler\SimpleFilter\IsNotEdited;
use danog\MadelineProto\EventHandler\Delete\DeleteMessages;
use danog\MadelineProto\EventHandler\Delete\DeleteChannelMessages;

use function Amp\delay;
use function Amp\File\exists;
use function Amp\File\isFile;
use function Amp\File\isDirectory;
use function Amp\File\createDirectory;
use function Amp\File\read;
use function Amp\File\write;
use function Amp\File\deleteFile;

class UserBot extends SimpleEventHandler
{
    private const AUTO_MIN_INTERVAL_MINUTES = 10;
    private const AUTO_MAX_TARGETS = 20;
    private const DEFAULT_FULL_CHAT_DELETE_THRESHOLD = 25;
    private const PERSONAL_MAX_TARGETS = 50;

    private bool $autoProcessing = false;
    private bool $personalProcessing = false;

    /**
     * Per-account data directory. Set via the UBCC_ACCOUNT_DIR env var so a
     * single shared bot_template.php serves every account — updating this file
     * updates all accounts at once, with no per-account copy to keep in sync.
     */
    private function dataDir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $env = getenv('UBCC_ACCOUNT_DIR');
            $dir = ($env !== false && $env !== '') ? rtrim($env, '/\\') : __DIR__;
        }
        return $dir;
    }

    private function cacheFile(): string
    {
        return $this->dataDir() . '/deleted_cache.json';
    }

    private function mediaDir(): string
    {
        return $this->dataDir() . '/deleted_media';
    }

    private function deletedArchiveFile(): string
    {
        return $this->dataDir() . '/deleted_archive.json';
    }

    private function archiveMediaDir(): string
    {
        return $this->dataDir() . '/archive_media';
    }

    /**
     * Permanently record a message that was actually deleted (or a view-once
     * self-destruct), with its own copy of the media. The panel's archive reads
     * this store, so it only ever shows genuinely deleted content — never the
     * rolling cache of normal incoming messages.
     */
    private function archiveDeletedItem(array $item, string $type = 'deleted'): void
    {
        try {
            $archive = $this->loadJsonFile($this->deletedArchiveFile(), []);

            $mediaArchivedName = null;
            $mediaPath = $item['media_path'] ?? null;
            if ($mediaPath !== null && isFile($mediaPath)) {
                $dir = $this->archiveMediaDir();
                if (!exists($dir) || !isDirectory($dir)) {
                    createDirectory($dir);
                }
                $ext = pathinfo((string) $mediaPath, PATHINFO_EXTENSION);
                $mediaArchivedName = 'arch_' . date('Ymd_His') . '_' . substr(sha1((string) $mediaPath . random_int(1, 999999)), 0, 8) . ($ext !== '' ? '.' . $ext : '');
                try {
                    write($dir . '/' . $mediaArchivedName, read((string) $mediaPath));
                } catch (\Throwable $e) {
                    $mediaArchivedName = null;
                }
            }

            $archive[] = [
                'archive_id'      => 'd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)),
                'type'            => $type,
                'id'              => $item['id'] ?? null,
                'chat_id'         => $item['chat_id'] ?? null,
                'sender_id'       => $item['sender_id'] ?? null,
                'sender_info'     => $item['sender_info'] ?? null,
                'chat_info'       => $item['chat_info'] ?? null,
                'chat_kind'       => $item['chat_kind'] ?? 'unknown',
                'text'            => (string) ($item['text'] ?? ''),
                'special_content' => (string) ($item['special_content'] ?? ''),
                'media_type'      => (string) ($item['media_type'] ?? 'none'),
                'media_file'      => $mediaArchivedName,
                'original_date'   => $item['date'] ?? null,
                'deleted_at'      => date('Y-m-d H:i:s'),
            ];

            // Cap the store; when trimming oldest, remove their media files too.
            $max = 3000;
            if (count($archive) > $max) {
                $remove = array_splice($archive, 0, count($archive) - $max);
                foreach ($remove as $old) {
                    if (!empty($old['media_file'])) {
                        $f = $this->archiveMediaDir() . '/' . $old['media_file'];
                        try {
                            if (isFile($f)) {
                                deleteFile($f);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }

            $this->saveJsonFile($this->deletedArchiveFile(), $archive);
            echo "ARCHIVED ({$type}) id=" . ($item['id'] ?? '?') . PHP_EOL;
        } catch (\Throwable $e) {
            echo "Archive deleted item failed: {$e->getMessage()}" . PHP_EOL;
        }
    }

    private function clockStateFile(): string
    {
        return $this->dataDir() . '/clock_state.json';
    }

    private function autoStateFile(): string
    {
        return $this->dataDir() . '/auto_state.json';
    }

    private function autoCampaignsFile(): string
    {
        return $this->dataDir() . '/auto_campaigns.json';
    }

    private function deleteStateFile(): string
    {
        return $this->dataDir() . '/delete_state.json';
    }

    private function editStateFile(): string
    {
        return $this->dataDir() . '/edit_state.json';
    }

    private function selfIgnoreFile(): string
    {
        return $this->dataDir() . '/self_ignore.json';
    }

    private function loadJsonFile(string $file, array $default): array
    {
        $data = read_json_file($file, $default);
        if (!is_array($data)) throw new RuntimeException('Invalid JSON state type');
        bot_json_snapshot($file, $data, true);
        return $data;
    }

    private function saveJsonFile(string $file, array $data): void
    {
        persist_bot_json($file, $data, $this->dataDir() . '/data.lock');
    }

    private function sendSelf(string $text): void
    {
        // پیام‌هایی که خود اسکریپت داخل Saved Messages می‌فرستد، دوباره به عنوان ورودی مرحله‌ای پردازش نشوند.
        $ignore = $this->loadJsonFile($this->selfIgnoreFile(), []);
        $hash = sha1($text);
        $ignore[$hash] = ($ignore[$hash] ?? 0) + 1;

        // فایل خیلی بزرگ نشود.
        if (count($ignore) > 200) {
            $ignore = array_slice($ignore, -200, null, true);
        }

        $this->saveJsonFile($this->selfIgnoreFile(), $ignore);

        $this->messages->sendMessage(
            peer: 'me',
            message: $text
        );
    }

    private function sendSelfHtml(string $html): void
    {
        $plain = strip_tags($html);

        $ignore = $this->loadJsonFile($this->selfIgnoreFile(), []);
        $ignore[sha1($html)] = ($ignore[sha1($html)] ?? 0) + 1;
        $ignore[sha1($plain)] = ($ignore[sha1($plain)] ?? 0) + 1;

        if (count($ignore) > 200) {
            $ignore = array_slice($ignore, -200, null, true);
        }

        $this->saveJsonFile($this->selfIgnoreFile(), $ignore);

        $this->messages->sendMessage(
            peer: 'me',
            message: $html,
            parse_mode: 'HTML'
        );
    }


    private function shouldIgnoreSelfMessage(string $text): bool
    {
        $ignore = $this->loadJsonFile($this->selfIgnoreFile(), []);
        $hash = sha1($text);

        if (!isset($ignore[$hash]) || (int) $ignore[$hash] <= 0) {
            return false;
        }

        $ignore[$hash] = (int) $ignore[$hash] - 1;

        if ($ignore[$hash] <= 0) {
            unset($ignore[$hash]);
        }

        $this->saveJsonFile($this->selfIgnoreFile(), $ignore);
        return true;
    }

    private function isBotPromptText(string $text): bool
    {
        $prefixes = [
            'نام کاربری عمومی کانال یا گروه‌هایی',
            'هیچ نام کاربری معتبری پیدا نشد',
            '✅ نام کاربری مقصدها ثبت شد',
            'متن پیام ثبت شد',
            'فقط عدد دقیقه را بفرست',
            '✅ ارسال اتوماتیک ساخته شد',
            '📋 لیست ارسال‌های اتوماتیک',
            '📭 هیچ ارسال اتوماتیکی ثبت نشده',
            '⛔ همه ارسال‌های اتوماتیک متوقف شدند',
            '⛔ متوقف شد',
            '✅ دوباره فعال شد',
            'حذف شد:',
            'موردی با ID',
            'لغو شد.',
            '✅ Clock روشن شد',
            '⛔ Clock خاموش شد',
            '🤖 راهنمای ربات',
            '✅ ذخیره پیام‌های حذف‌شده روشن شد',
            '⛔ ذخیره پیام‌های حذف‌شده خاموش شد',
            '✅ مقصد پیام‌های حذف‌شده روی Saved Messages تنظیم شد',
            '✅ مقصد پیام‌های حذف‌شده روی کانال تنظیم شد',
            '📌 وضعیت پیام‌های حذف‌شده',
            '✅ ذخیره پیام‌های ویرایش‌شده روشن شد',
            '⛔ ذخیره پیام‌های ویرایش‌شده خاموش شد',
            '✅ مقصد پیام‌های ویرایش‌شده روی Saved Messages تنظیم شد',
            '✅ مقصد پیام‌های ویرایش‌شده روی کانال تنظیم شد',
            '📌 وضعیت پیام‌های ویرایش‌شده',
            '✅ فیلتر پیام‌های حذف‌شده تنظیم شد',
            '✅ فیلتر پیام‌های ویرایش‌شده تنظیم شد',
            '✅ بازیابی کل چت هنگام حذف تاریخچه روشن شد',
            '⛔ بازیابی کل چت هنگام حذف تاریخچه خاموش شد',
            '✅ حد تشخیص حذف کل چت تنظیم شد',
            '✉️ ارسال شخصی زمان‌بندی‌شده',
            'متن پیام ثبت شد.',
            '✅ ارسال شخصی زمان‌بندی شد.',
            '📋 لیست ارسال‌های شخصی',
            '📭 هیچ ارسال شخصی زمان‌بندی‌شده‌ای وجود ندارد',
            '✅ پیام شخصی برای',
            '⚠️ ارسال به',
            'هیچ یوزرنیم معتبری پیدا نشد',
            'فرمت درست:',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function helpText(): string
    {
        return "🤖 <b>راهنمای ربات</b>\n\n" .
            "🕒 <b>ساعت روی پروفایل</b>\n" .
            "<code>/clock on</code>\n" .
            "<code>/clock off</code>\n\n" .
            "🗑 <b>پیام‌های حذف‌شده</b>\n" .
            "<code>/delete on</code>\n" .
            "<code>/delete off</code>\n" .
            "<code>/delete save_message</code>\n" .
            "<code>/delete channel CHANNEL_USERNAME_OR_ID</code>\n" .
            "<code>/delete status</code>\n" .
            "<code>/delete filter all</code>\n" .
            "<code>/delete filter channels</code>\n" .
            "<code>/delete filter groups</code>\n" .
            "<code>/delete filter private</code>\n" .
            "<code>/delete full_chat on</code>\n" .
            "<code>/delete full_chat off</code>\n" .
            "<code>/delete full_chat_threshold 25</code>\n\n" .
            "✏️ <b>پیام‌های ویرایش‌شده</b>\n" .
            "<code>/edit on</code>\n" .
            "<code>/edit off</code>\n" .
            "<code>/edit save_message</code>\n" .
            "<code>/edit channel CHANNEL_USERNAME_OR_ID</code>\n" .
            "<code>/edit status</code>\n" .
            "<code>/edit filter all</code>\n" .
            "<code>/edit filter channels</code>\n" .
            "<code>/edit filter groups</code>\n" .
            "<code>/edit filter private</code>\n\n" .
            "📤 <b>ارسال خودکار</b>\n" .
            "<code>/auto message_new</code>\n" .
            "<code>/auto message_list</code>\n" .
            "<code>/auto stop_all</code>\n" .
            "<code>/auto message_pause ID</code>\n" .
            "<code>/auto message_resume ID</code>\n" .
            "<code>/auto message_delete ID</code>\n" .
            "<code>/auto cancel</code>\n\n" .
            "✉️ <b>ارسال شخصی زمان‌بندی‌شده</b>\n" .
            "<code>/send_personal</code>\n" .
            "<code>/send_personal list</code>\n" .
            "<code>/send_personal delete ID</code>\n" .
            "<code>/send_personal cancel</code>\n" .
            "اول پیام را می‌گیرد، بعد یوزرنیم افراد را؛ سپس با فاصله‌های زمانی فزاینده خودکار برای هرکس می‌فرستد.\n\n" .
            "فیلترها: <code>all</code> همه، <code>channels</code> کانال‌ها، <code>groups</code> گروه‌ها، <code>private</code> چت شخصی.\n\n" .
            "در حذف و ویرایش، متن، عکس، ویدیو، گیف، داکیومنت، صوت و اطلاعات غیرقابل دانلود مثل لوکیشن/کانتکت تا حد ممکن ثبت می‌شود.\n\n" .
            "اگر تعداد زیادی پیام از یک چت یک‌جا حذف شود، بات آن را به عنوان حذف کل تاریخچه همان چت تشخیص می‌دهد و همه پیام‌های cache شده همان چت را ارسال می‌کند.\n\n" .
            "اگر عکس/ویدیو یکبارمصرف دریافت شود، همان لحظه در مقصد تنظیم‌شده برای حذف‌ها ارسال می‌شود.";
    }


    private function loadCache(): array
    {
        return $this->loadJsonFile($this->cacheFile(), []);
    }

    private function saveCache(array $cache): void
    {
        $this->saveJsonFile($this->cacheFile(), $cache);
    }

    private function defaultClockState(): array
    {
        return [
            'enabled' => false,
            'base_first_name' => null,
            'base_last_name' => null,
        ];
    }

    private function loadClockState(): array
    {
        return $this->loadJsonFile($this->clockStateFile(), $this->defaultClockState());
    }

    private function saveClockState(array $state): void
    {
        $this->saveJsonFile($this->clockStateFile(), $state);
    }

    private function defaultAutoState(): array
    {
        return [
            'step' => null,
            'targets' => [],
            'message' => null,
        ];
    }

    private function loadAutoState(): array
    {
        return $this->loadJsonFile($this->autoStateFile(), $this->defaultAutoState());
    }

    private function saveAutoState(array $state): void
    {
        $this->saveJsonFile($this->autoStateFile(), $state);
    }

    private function clearAutoState(): void
    {
        $this->saveAutoState($this->defaultAutoState());
    }

    private function loadAutoCampaigns(): array
    {
        return $this->loadJsonFile($this->autoCampaignsFile(), []);
    }

    private function saveAutoCampaigns(array $campaigns): void
    {
        $this->saveJsonFile($this->autoCampaignsFile(), $campaigns);
    }

    /* ---- personal (staggered) send ---- */

    private function personalStateFile(): string
    {
        return $this->dataDir() . '/send_personal_state.json';
    }

    private function personalSendsFile(): string
    {
        return $this->dataDir() . '/personal_sends.json';
    }

    private function defaultPersonalState(): array
    {
        return ['step' => null, 'message' => null];
    }

    private function loadPersonalState(): array
    {
        return $this->loadJsonFile($this->personalStateFile(), $this->defaultPersonalState());
    }

    private function savePersonalState(array $state): void
    {
        $this->saveJsonFile($this->personalStateFile(), $state);
    }

    private function clearPersonalState(): void
    {
        $this->savePersonalState($this->defaultPersonalState());
    }

    private function loadPersonalSends(): array
    {
        $data = $this->loadJsonFile($this->personalSendsFile(), []);
        return is_array($data) ? $data : [];
    }

    private function savePersonalSends(array $jobs): void
    {
        $this->saveJsonFile($this->personalSendsFile(), $jobs);
    }

    /** Parse usernames (comma / newline separated). Accepts @user, t.me/user, plain. */
    private function normalizePersonalTargets(string $raw): array
    {
        $parts = preg_split('/[,\n\r]+/', $raw) ?: [];
        $targets = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $part = preg_replace('/^https?:\/\/t\.me\//i', '', $part) ?? $part;
            $part = preg_replace('/^t\.me\//i', '', $part) ?? $part;
            $part = trim($part, " \t\n\r\0\x0B/@");

            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $part)) {
                continue;
            }

            $targets['@' . $part] = true;
        }

        return array_slice(array_keys($targets), 0, self::PERSONAL_MAX_TARGETS);
    }

    /**
     * Build a per-recipient schedule with progressively growing gaps, plus a
     * little jitter so it looks natural. Example for 4 people: roughly 1, 3, 5,
     * 8 minutes from now (the more people, the wider the later gaps).
     */
    private function schedulePersonalSend(array $targets): array
    {
        $recipients = [];
        $now = time();
        $cumulative = 0; // seconds from now

        foreach (array_values($targets) as $i => $username) {
            if ($i === 0) {
                $gap = random_int(50, 80); // first send ~1 minute in
            } else {
                // Base gap grows every couple of recipients (2,2,3,3,4,4...) minutes.
                $baseMinutes = 2 + intdiv($i - 1, 2);
                $gap = $baseMinutes * 60 + random_int(-15, 35);
            }

            $cumulative += max(30, $gap);

            $recipients[] = [
                'username' => $username,
                'send_at'  => $now + $cumulative,
                'sent'     => false,
                'sent_at'  => null,
                'attempts' => 0,
                'error'    => null,
            ];
        }

        return $recipients;
    }

    private function formatPersonalList(array $jobs): string
    {
        if ($jobs === []) {
            return "📭 هیچ ارسال شخصی زمان‌بندی‌شده‌ای وجود ندارد.";
        }

        $out = "📋 لیست ارسال‌های شخصی:\n\n";
        foreach ($jobs as $job) {
            $id = $job['id'] ?? 'unknown';
            $total = count($job['recipients'] ?? []);
            $sent = 0;
            $next = null;
            foreach (($job['recipients'] ?? []) as $r) {
                if (!empty($r['sent'])) {
                    $sent++;
                } elseif ($next === null || (int) $r['send_at'] < $next) {
                    $next = (int) $r['send_at'];
                }
            }
            $preview = mb_substr((string) ($job['message'] ?? ''), 0, 60);
            $out .= "ID: {$id}\n";
            $out .= "پیشرفت: {$sent}/{$total}\n";
            $out .= "ارسال بعدی: " . ($next ? date('H:i:s', $next) : '—') . "\n";
            $out .= "پیام: {$preview}\n\n";
        }
        $out .= "برای لغو یک مورد:\n/send_personal delete ID";
        return $out;
    }

    private function defaultDeleteState(): array
    {
        return [
            'enabled' => true,
            'target_type' => 'save_message',
            'channel_peer' => null,
            'chat_filter' => 'all',
            'full_chat_enabled' => true,
            'full_chat_threshold' => self::DEFAULT_FULL_CHAT_DELETE_THRESHOLD,
        ];
    }

    private function loadDeleteState(): array
    {
        $state = $this->loadJsonFile($this->deleteStateFile(), $this->defaultDeleteState());
        return array_merge($this->defaultDeleteState(), $state);
    }

    private function saveDeleteState(array $state): void
    {
        $this->saveJsonFile($this->deleteStateFile(), array_merge($this->defaultDeleteState(), $state));
    }

    private function getDeletedTargetPeer(): string
    {
        $state = $this->loadDeleteState();

        if (($state['target_type'] ?? 'save_message') === 'channel' && !empty($state['channel_peer'])) {
            return (string) $state['channel_peer'];
        }

        return 'me';
    }

    private function defaultEditState(): array
    {
        return [
            'enabled' => true,
            'target_type' => 'save_message',
            'channel_peer' => null,
            'chat_filter' => 'all',
        ];
    }

    private function loadEditState(): array
    {
        $state = $this->loadJsonFile($this->editStateFile(), $this->defaultEditState());
        return array_merge($this->defaultEditState(), $state);
    }

    private function saveEditState(array $state): void
    {
        $this->saveJsonFile($this->editStateFile(), array_merge($this->defaultEditState(), $state));
    }

    private function getEditedTargetPeer(): string
    {
        $state = $this->loadEditState();

        if (($state['target_type'] ?? 'save_message') === 'channel' && !empty($state['channel_peer'])) {
            return (string) $state['channel_peer'];
        }

        return 'me';
    }

    private function normalizeChatFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return match ($filter) {
            'all', 'همه' => 'all',
            'channel', 'channels', 'کانال', 'کانالها', 'کانال‌ها' => 'channels',
            'group', 'groups', 'گروه', 'گروهها', 'گروه‌ها' => 'groups',
            'private', 'privates', 'pv', 'پیوی', 'شخصی', 'خصوصی' => 'private',
            default => '',
        };
    }

    private function chatKindFromInfo(array $info): string
    {
        $type = strtolower((string) ($info['type'] ?? $info['_'] ?? ''));

        if ($type !== '') {
            // getInfo type is one of: user / bot / chat / supergroup / channel.
            if (str_contains($type, 'user') || str_contains($type, 'bot')) {
                return 'private';
            }
            if (str_contains($type, 'supergroup')) {
                return 'groups';
            }
            if (str_contains($type, 'channel')) {
                return 'channels';
            }
            if (str_contains($type, 'group') || str_contains($type, 'chat')) {
                return 'groups';
            }
        }

        // Fallback when the type couldn't be resolved (e.g. min-peer): a positive
        // peer id is always a private user chat.
        $id = $info['id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            return 'private';
        }

        return 'unknown';
    }

    private function chatAllowedByFilter(array $item, string $filter): bool
    {
        $filter = $this->normalizeChatFilter($filter);

        if ($filter === '' || $filter === 'all') {
            return true;
        }

        $kind = $item['chat_kind'] ?? ($item['chat_info']['kind'] ?? 'unknown');

        return match ($filter) {
            'channels' => $kind === 'channels',
            'groups' => $kind === 'groups',
            'private' => $kind === 'private',
            default => true,
        };
    }

    /** True only for media that carries a downloadable file (not polls/geo/contacts). */
    private function mediaIsDownloadable(Message $message): bool
    {
        $media = $message->media ?? null;
        if (!is_object($media)) {
            return false;
        }
        $cls = strtolower((new \ReflectionClass($media))->getShortName());
        foreach (['photo', 'video', 'voice', 'audio', 'document', 'sticker', 'gif', 'animation'] as $kw) {
            if (str_contains($cls, $kw)) {
                return true;
            }
        }
        return false;
    }

    /** Try hard to download a message's file: whole-message first, then the media object. */
    private function downloadMediaRobust(Message $message, ?object $media): ?string
    {
        $dir = $this->mediaDir();

        try {
            $p = $this->downloadToDir($message, $dir);
            if (is_string($p) && $p !== '' && isFile($p)) {
                return $p;
            }
        } catch (\Throwable $e) {
            echo "DL(message) failed {$message->id}: {$e->getMessage()}" . PHP_EOL;
        }

        // Self-destruct / view-once / protected media often only resolves when the
        // media object itself is handed to the downloader.
        if (is_object($media)) {
            try {
                $p = $this->downloadToDir($media, $dir);
                if (is_string($p) && $p !== '' && isFile($p)) {
                    echo "DL(media-object) ok {$message->id}" . PHP_EOL;
                    return $p;
                }
            } catch (\Throwable $e) {
                echo "DL(media-object) failed {$message->id}: {$e->getMessage()}" . PHP_EOL;
            }
        }

        return null;
    }

    private function mediaKindFromMessage(Message $message, ?string $downloadedPath): string
    {
        if ($downloadedPath !== null && $downloadedPath !== '') {
            return $this->detectMediaType($downloadedPath);
        }

        // Infer from the high-level v8 Media object class (works even when the
        // file could not be downloaded, e.g. an expired view-once photo).
        $media = $message->media ?? null;
        if (is_object($media)) {
            $cls = strtolower((new \ReflectionClass($media))->getShortName());
            $kind = match (true) {
                str_contains($cls, 'photo')    => 'photo',
                str_contains($cls, 'gif')      => 'gif',
                str_contains($cls, 'sticker')  => 'sticker',
                str_contains($cls, 'voice')    => 'audio',
                str_contains($cls, 'audio')    => 'audio',
                str_contains($cls, 'video')    => 'video',
                str_contains($cls, 'document') => 'document',
                str_contains($cls, 'geo'), str_contains($cls, 'location') => 'location',
                str_contains($cls, 'contact')  => 'contact',
                str_contains($cls, 'poll')     => 'poll',
                default => '',
            };
            if ($kind !== '') {
                return $kind;
            }
        }

        $vars = get_object_vars($message);
        $json = strtolower((string) json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return match (true) {
            str_contains($json, 'messagegeopoint'), str_contains($json, 'geo_point'), str_contains($json, 'geopoint') => 'location',
            str_contains($json, 'messagevenue'), str_contains($json, 'venue') => 'venue',
            str_contains($json, 'messagecontact'), str_contains($json, 'phone_number'), str_contains($json, 'vcard') => 'contact',
            str_contains($json, 'messagepoll'), str_contains($json, 'poll') => 'poll',
            str_contains($json, 'messagedice'), str_contains($json, 'emoticon') => 'dice',
            str_contains($json, 'webpage') => 'webpage',
            str_contains($json, 'sticker') => 'sticker',
            default => 'none',
        };
    }

    private function specialContentDescription(Message $message, string $mediaType): string
    {
        if ($mediaType === 'none' || in_array($mediaType, ['photo', 'gif', 'video', 'audio', 'document'], true)) {
            return '';
        }

        $vars = get_object_vars($message);
        $json = (string) json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $desc = "نوع محتوا: {$mediaType}";

        if (preg_match('/"lat"\\s*:\\s*([-0-9.]+)/', $json, $m1) && preg_match('/"long"\\s*:\\s*([-0-9.]+)/', $json, $m2)) {
            $desc .= "\nLocation: {$m1[1]}, {$m2[1]}";
        }

        if (preg_match('/"phone_number"\\s*:\\s*"([^"]+)"/u', $json, $m)) {
            $desc .= "\nPhone: {$m[1]}";
        }

        if (preg_match('/"first_name"\\s*:\\s*"([^"]+)"/u', $json, $m)) {
            $desc .= "\nFirst name: {$m[1]}";
        }

        if (preg_match('/"last_name"\\s*:\\s*"([^"]+)"/u', $json, $m)) {
            $desc .= "\nLast name: {$m[1]}";
        }

        if (preg_match('/"title"\\s*:\\s*"([^"]+)"/u', $json, $m)) {
            $desc .= "\nTitle: {$m[1]}";
        }

        return $desc;
    }


    private function messageJson(Message $message): string
    {
        return (string) json_encode(
            get_object_vars($message),
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    private function isViewOnceMedia(Message $message): bool
    {
        $media = $message->media ?? null;
        if (!is_object($media)) {
            return false;
        }

        // MadelineProto v8 high-level Media objects expose a numeric TTL for
        // self-destructing / view-once photos & videos. The old check looked for
        // raw MTProto constructor names (messageMediaPhoto, ttl_seconds) which do
        // NOT exist on the high-level object, so detection always failed.
        foreach (get_object_vars($media) as $k => $v) {
            if (stripos($k, 'ttl') !== false && is_numeric($v) && (int) $v > 0) {
                echo "VIEW-ONCE detected via media->{$k}={$v}" . PHP_EOL;
                return true;
            }
        }

        // Fallback: scan the serialized message for any positive "ttl*" field
        // (covers raw-array shapes such as ttl_seconds / ttlSeconds).
        $json = strtolower((string) json_encode(get_object_vars($message), JSON_PARTIAL_OUTPUT_ON_ERROR));
        if (preg_match('/"ttl[a-z_]*"\s*:\s*[1-9][0-9]*/', $json) === 1) {
            echo "VIEW-ONCE detected via json ttl field" . PHP_EOL;
            return true;
        }

        return false;
    }

    private function sendViewOnceItemImmediately(array $item): void
    {
        $deleteState = $this->loadDeleteState();

        // Always archive view-once media (it self-destructs, so the panel should keep it).
        $this->archiveDeletedItem($item, 'view_once');

        $filter = (string) ($deleteState['chat_filter'] ?? 'all');
        $allowed = $this->chatAllowedByFilter($item, $filter);
        echo "VIEWONCE-SEND: filter={$filter} kind=" . ($item['chat_kind'] ?? '?') . " allowed=" . ($allowed ? 'yes' : 'no')
            . " media_path=" . ($item['media_path'] ?? 'NULL') . " type=" . ($item['media_type'] ?? 'none') . PHP_EOL;

        if (!$allowed) {
            echo "View-once media skipped by chat filter ({$filter})." . PHP_EOL;
            return;
        }

        $targetPeer = $this->getDeletedTargetPeer();
        echo "VIEWONCE-SEND: target={$targetPeer}" . PHP_EOL;

        $mediaPath = $item['media_path'] ?? null;
        $mediaType = (string) ($item['media_type'] ?? 'none');
        $text = trim((string) ($item['text'] ?? ''));

        $senderLine = $this->formatPeerInfo($item['sender_info'] ?? [
            'id' => $item['sender_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $chatLine = $this->formatPeerInfo($item['chat_info'] ?? [
            'id' => $item['chat_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $report = "👁 مدیای یکبارمصرف دریافت و ذخیره شد\n\n";
        $report .= "Message ID: " . ($item['id'] ?? 'unknown') . "\n";
        $report .= "Chat ID: " . ($item['chat_id'] ?? 'unknown') . "\n";
        $report .= "Sender ID: " . ($item['sender_id'] ?? 'unknown') . "\n";
        $report .= "Sender: " . $senderLine . "\n";
        $report .= "Chat: " . $chatLine . "\n";
        $report .= "Media Type: " . $mediaType . "\n";
        $report .= "Received at: " . ($item['date'] ?? date('Y-m-d H:i:s')) . "\n";

        if ($text !== '') {
            $report .= "\nمتن/کپشن:\n" . $text;
        }

        $specialContent = trim((string) ($item['special_content'] ?? ''));
        if ($specialContent !== '') {
            $report .= "\n\nمحتوا:\n" . $specialContent;
        }

        // Report and media are sent independently so a failure in one never
        // blocks the other (and every failure is logged for diagnosis).
        try {
            $this->sendReportText($targetPeer, $report);
            echo "VIEWONCE-SEND: report sent OK" . PHP_EOL;
        } catch (\Throwable $e) {
            echo "VIEWONCE-SEND: report FAILED: {$e->getMessage()}" . PHP_EOL;
        }

        if ($mediaPath !== null && isFile($mediaPath)) {
            try {
                $this->sendStoredMedia(
                    $targetPeer,
                    $mediaPath,
                    $mediaType,
                    "مدیای یکبارمصرف\nMessage ID: " . ($item['id'] ?? 'unknown')
                );
                echo "VIEWONCE-SEND: media sent OK ({$mediaType})" . PHP_EOL;
            } catch (\Throwable $e) {
                echo "VIEWONCE-SEND: media FAILED: {$e->getMessage()}" . PHP_EOL;
                try {
                    $this->sendReportText($targetPeer, "⚠️ ارسال مدیای یکبارمصرف خطا داد:\n" . $e->getMessage());
                } catch (\Throwable $e2) {
                }
            }
        } else {
            echo "VIEWONCE-SEND: no media file on disk (path=" . ($mediaPath ?? 'NULL') . ")" . PHP_EOL;
            try {
                $this->sendReportText(
                    $targetPeer,
                    "⚠️ مدیای یکبارمصرف تشخیص داده شد، اما فایل قابل دانلود/ارسال نبود.\nMessage ID: " . ($item['id'] ?? 'unknown')
                );
            } catch (\Throwable $e) {
            }
        }
    }


    private function ensureMediaDir(): void
    {
        $dir = $this->mediaDir();

        if (!exists($dir) || !isDirectory($dir)) {
            createDirectory($dir);
        }
    }

    private function key(?int $chatId, int $messageId): string
    {
        return ((string) ($chatId ?? 0)) . ':' . ((string) $messageId);
    }

    private function detectMediaType(?string $path): string
    {
        if (!$path) {
            return 'none';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg', 'png', 'webp' => 'photo',
            'gif' => 'gif',
            'mp4', 'mov', 'mkv', 'webm' => 'video',
            'mp3', 'm4a', 'wav', 'ogg', 'flac' => 'audio',
            default => 'document',
        };
    }

    private function tehranTime(): string
    {
        $dt = new DateTime('now', new DateTimeZone((string) (getenv('TG_TIMEZONE') ?: cfg()['timezone'])));
        return $dt->format('H:i');
    }

    private function cleanNameFromClock(string $name): string
    {
        return trim(preg_replace('/\s*[|⏰]\s*\d{2}:\d{2}$/u', '', $name));
    }

    private function findValueRecursive(array $data, string $key): ?string
    {
        foreach ($data as $k => $v) {
            if ((string) $k === $key && (is_string($v) || is_numeric($v))) {
                return (string) $v;
            }

            if (is_array($v)) {
                $found = $this->findValueRecursive($v, $key);
                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }

        return null;
    }

    private function peerInfo(int|string|null $peer): array
    {
        if ($peer === null || $peer === '') {
            return ['id' => null, 'name' => 'unknown', 'username' => null];
        }

        $result = ['id' => (string) $peer, 'name' => 'unknown', 'username' => null, 'type' => ''];

        try {
            $info = $this->getInfo($peer);

            if (!is_array($info)) {
                return $result;
            }

            $username = $this->findValueRecursive($info, 'username');
            $firstName = $this->findValueRecursive($info, 'first_name');
            $lastName = $this->findValueRecursive($info, 'last_name');
            $title = $this->findValueRecursive($info, 'title');

            $name = trim((string) $firstName . ' ' . (string) $lastName);
            if ($name === '') {
                $name = (string) ($title ?? 'unknown');
            }

            $id = $this->findValueRecursive($info, 'id');

            $result['id'] = $id !== null ? (string) $id : (string) $peer;
            $result['name'] = $name;
            $result['username'] = $username ? '@' . ltrim($username, '@') : null;
            // Top-level type from getInfo: user / bot / chat / supergroup / channel.
            $result['type'] = strtolower((string) ($info['type'] ?? ''));
        } catch (\Throwable $e) {
            echo "Peer info failed for {$peer}: {$e->getMessage()}" . PHP_EOL;
        }

        return $result;
    }

    private function formatPeerInfo(array $info): string
    {
        $name = $info['name'] ?? 'unknown';
        $username = $info['username'] ?? null;
        $id = $info['id'] ?? 'unknown';

        $line = (string) $name;

        if ($username) {
            $line .= " ({$username})";
        }

        $line .= " [ID: {$id}]";
        return $line;
    }

    private function isSavedMessages(Message $message): bool
    {
        $text = trim($message->message ?? '');
        $state = $this->loadAutoState();
        $hasActiveAutoSetup = ($state['step'] ?? null) !== null;
        $hasActivePersonalSetup = ($this->loadPersonalState()['step'] ?? null) !== null;

        // دستورهای مدیریتی و جواب‌های مرحله‌ای ارسال خودکار را قبول می‌کنیم.
        // در بعضی نسخه‌های MadelineProto، chatId در Saved Messages با self id برابر نمی‌شود.
        if (str_starts_with($text, '/auto') || str_starts_with($text, '/clock') || str_starts_with($text, '/delete') || str_starts_with($text, '/edit') || str_starts_with($text, '/help') || str_starts_with($text, '/send_personal') || $hasActiveAutoSetup || $hasActivePersonalSetup) {
            echo "COMMAND accepted: {$text} chatId=" . ($message->chatId ?? 'null') . " step=" . ($state['step'] ?? '-') . PHP_EOL;
            return true;
        }

        return false;
    }

    private function normalizeTargets(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));
        $targets = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $part = trim($part);

            // لینک t.me را به username تبدیل می‌کنیم.
            $part = preg_replace('/^https?:\/\/t\.me\//i', '', $part);
            $part = preg_replace('/^t\.me\//i', '', $part);
            $part = trim($part, " \t\n\r\0\x0B/@");

            // فقط username عمومی تلگرام قبول است، نه اسم نمایشی و نه ID عددی.
            // قوانین رایج username تلگرام: 5 تا 32 کاراکتر، حروف/عدد/underscore، شروع با حرف.
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $part)) {
                continue;
            }

            // username کاملاً عددی یا ID عددی قبول نشود.
            if (preg_match('/^\d+$/', $part)) {
                continue;
            }

            $targets[] = '@' . $part;
        }

        $targets = array_values(array_unique($targets));
        return array_slice($targets, 0, self::AUTO_MAX_TARGETS);
    }

    private function formatAutoList(array $campaigns): string
    {
        if ($campaigns === []) {
            return "📭 هیچ ارسال اتوماتیکی ثبت نشده.";
        }

        $out = "📋 لیست ارسال‌های اتوماتیک:\n\n";

        foreach ($campaigns as $campaign) {
            $id = $campaign['id'] ?? 'unknown';
            $enabled = ($campaign['enabled'] ?? false) ? 'روشن' : 'خاموش';
            $targets = implode(', ', $campaign['targets'] ?? []);
            $interval = $campaign['interval_minutes'] ?? '?';
            $lastRun = isset($campaign['last_run_at']) && $campaign['last_run_at'] ? date('Y-m-d H:i:s', (int) $campaign['last_run_at']) : '-';
            $nextRun = isset($campaign['next_run_at']) && $campaign['next_run_at'] ? date('Y-m-d H:i:s', (int) $campaign['next_run_at']) : '-';
            $msg = (string) ($campaign['message'] ?? '');
            $preview = mb_substr($msg, 0, 80);

            if (mb_strlen($msg) > 80) {
                $preview .= '...';
            }

            $out .= "ID: {$id}\n";
            $out .= "Status: {$enabled}\n";
            $out .= "Every: {$interval} دقیقه\n";
            $out .= "Last: {$lastRun}\n";
            $out .= "Next: {$nextRun}\n";
            $out .= "Targets: {$targets}\n";
            $out .= "Message: {$preview}\n\n";
        }

        $out .= "دستورها:\n";
        $out .= "/auto message_pause ID\n";
        $out .= "/auto message_resume ID\n";
        $out .= "/auto message_delete ID\n";
        $out .= "/auto stop_all";

        return $out;
    }

    #[Handler]
    public function editCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if (!str_starts_with($text, '/edit')) {
            return;
        }

        echo "EDIT COMMAND seen: {$text} chatId=" . ($message->chatId ?? 'null') . PHP_EOL;

        if ($this->isBotPromptText($text) || $this->shouldIgnoreSelfMessage($text)) {
            return;
        }

        $state = $this->loadEditState();

        if ($text === '/edit on') {
            $state['enabled'] = true;
            $this->saveEditState($state);
            $this->sendSelf("✅ ذخیره پیام‌های ویرایش‌شده روشن شد.");
            return;
        }

        if ($text === '/edit off') {
            $state['enabled'] = false;
            $this->saveEditState($state);
            $this->sendSelf("⛔ ذخیره پیام‌های ویرایش‌شده خاموش شد.");
            return;
        }

        if ($text === '/edit save_message' || $text === '/edit saved_message') {
            $state['target_type'] = 'save_message';
            $state['channel_peer'] = null;
            $this->saveEditState($state);
            $this->sendSelf("✅ مقصد پیام‌های ویرایش‌شده روی Saved Messages تنظیم شد.");
            return;
        }

        if (preg_match('/^\/edit\s+channel\s+(.+)$/u', $text, $m)) {
            $peer = trim($m[1]);
            $peer = preg_replace('/^https?:\/\/t\.me\//i', '', $peer);
            $peer = preg_replace('/^t\.me\//i', '', $peer);
            $peer = trim($peer, " \t\n\r\0\x0B/@");

            if ($peer === '') {
                $this->sendSelf("فرمت درست:\n/edit channel CHANNEL_USERNAME_OR_ID");
                return;
            }

            if (!preg_match('/^-?\d+$/', $peer)) {
                $peer = '@' . $peer;
            }

            $state['target_type'] = 'channel';
            $state['channel_peer'] = $peer;
            $this->saveEditState($state);

            $this->sendSelf("✅ مقصد پیام‌های ویرایش‌شده روی کانال تنظیم شد:\n{$peer}\n\nاگر کانال است، اکانت باید ادمین و مجاز به ارسال پست باشد.");
            return;
        }

        if (preg_match('/^\/edit\s+filter\s+(.+)$/u', $text, $m)) {
            $filter = $this->normalizeChatFilter($m[1]);

            if ($filter === '') {
                $this->sendSelf("فرمت درست:\n/edit filter all\n/edit filter channels\n/edit filter groups\n/edit filter private");
                return;
            }

            $state['chat_filter'] = $filter;
            $this->saveEditState($state);
            $this->sendSelf("✅ فیلتر پیام‌های ویرایش‌شده تنظیم شد: {$filter}");
            return;
        }

        if ($text === '/edit status') {
            $enabled = ($state['enabled'] ?? true) ? 'روشن' : 'خاموش';
            $target = ($state['target_type'] ?? 'save_message') === 'channel'
                ? 'کانال: ' . ($state['channel_peer'] ?? 'unknown')
                : 'Saved Messages';

            $filter = $state['chat_filter'] ?? 'all';

            $this->sendSelf("📌 وضعیت پیام‌های ویرایش‌شده\n\nStatus: {$enabled}\nTarget: {$target}\nFilter: {$filter}");
            return;
        }

        $this->sendSelf(
            "فرمت درست:\n" .
            "/edit on\n" .
            "/edit off\n" .
            "/edit save_message\n" .
            "/edit channel CHANNEL_USERNAME_OR_ID\n" .
            "/edit status"
        );
    }

    #[Handler]
    public function deleteCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if (!str_starts_with($text, '/delete')) {
            return;
        }

        echo "DELETE COMMAND seen: {$text} chatId=" . ($message->chatId ?? 'null') . PHP_EOL;

        if ($this->isBotPromptText($text) || $this->shouldIgnoreSelfMessage($text)) {
            return;
        }

        $state = $this->loadDeleteState();

        if ($text === '/delete on') {
            $state['enabled'] = true;
            $this->saveDeleteState($state);
            $this->sendSelf("✅ ذخیره پیام‌های حذف‌شده روشن شد.");
            return;
        }

        if ($text === '/delete off') {
            $state['enabled'] = false;
            $this->saveDeleteState($state);
            $this->sendSelf("⛔ ذخیره پیام‌های حذف‌شده خاموش شد.");
            return;
        }

        if ($text === '/delete save_message' || $text === '/delete saved_message') {
            $state['target_type'] = 'save_message';
            $state['channel_peer'] = null;
            $this->saveDeleteState($state);
            $this->sendSelf("✅ مقصد پیام‌های حذف‌شده روی Saved Messages تنظیم شد.");
            return;
        }

        if (preg_match('/^\/delete\s+channel\s+(.+)$/u', $text, $m)) {
            $peer = trim($m[1]);
            $peer = preg_replace('/^https?:\/\/t\.me\//i', '', $peer);
            $peer = preg_replace('/^t\.me\//i', '', $peer);
            $peer = trim($peer, " \t\n\r\0\x0B/@");

            if ($peer === '') {
                $this->sendSelf("فرمت درست:\n/delete channel CHANNEL_USERNAME_OR_ID");
                return;
            }

            if (!preg_match('/^-?\d+$/', $peer)) {
                $peer = '@' . $peer;
            }

            $state['target_type'] = 'channel';
            $state['channel_peer'] = $peer;
            $this->saveDeleteState($state);

            $this->sendSelf("✅ مقصد پیام‌های حذف‌شده روی کانال تنظیم شد:\n{$peer}\n\nاگر کانال است، اکانت باید ادمین و مجاز به ارسال پست باشد.");
            return;
        }

        if (preg_match('/^\/delete\s+filter\s+(.+)$/u', $text, $m)) {
            $filter = $this->normalizeChatFilter($m[1]);

            if ($filter === '') {
                $this->sendSelf("فرمت درست:\n/delete filter all\n/delete filter channels\n/delete filter groups\n/delete filter private");
                return;
            }

            $state['chat_filter'] = $filter;
            $this->saveDeleteState($state);
            $this->sendSelf("✅ فیلتر پیام‌های حذف‌شده تنظیم شد: {$filter}");
            return;
        }

        if ($text === '/delete full_chat on') {
            $state['full_chat_enabled'] = true;
            $this->saveDeleteState($state);
            $this->sendSelf("✅ بازیابی کل چت هنگام حذف تاریخچه روشن شد.");
            return;
        }

        if ($text === '/delete full_chat off') {
            $state['full_chat_enabled'] = false;
            $this->saveDeleteState($state);
            $this->sendSelf("⛔ بازیابی کل چت هنگام حذف تاریخچه خاموش شد.");
            return;
        }

        if (preg_match('/^\/delete\s+full_chat_threshold\s+(\d+)$/u', $text, $m)) {
            $threshold = max(5, min(500, (int) $m[1]));
            $state['full_chat_threshold'] = $threshold;
            $this->saveDeleteState($state);
            $this->sendSelf("✅ حد تشخیص حذف کل چت تنظیم شد: {$threshold}");
            return;
        }

        if ($text === '/delete status') {
            $enabled = ($state['enabled'] ?? true) ? 'روشن' : 'خاموش';
            $target = ($state['target_type'] ?? 'save_message') === 'channel'
                ? 'کانال: ' . ($state['channel_peer'] ?? 'unknown')
                : 'Saved Messages';

            $filter = $state['chat_filter'] ?? 'all';
            $fullChat = ($state['full_chat_enabled'] ?? true) ? 'روشن' : 'خاموش';
            $threshold = (int) ($state['full_chat_threshold'] ?? self::DEFAULT_FULL_CHAT_DELETE_THRESHOLD);

            $this->sendSelf("📌 وضعیت پیام‌های حذف‌شده\n\nStatus: {$enabled}\nTarget: {$target}\nFilter: {$filter}\nFull chat recovery: {$fullChat}\nFull chat threshold: {$threshold}");
            return;
        }

        $this->sendSelf(
            "فرمت درست:\n" .
            "/delete on\n" .
            "/delete off\n" .
            "/delete save_message\n" .
            "/delete channel CHANNEL_USERNAME_OR_ID\n" .
            "/delete status"
        );
    }

    #[Handler]
    public function helpCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if ($text !== '/help') {
            return;
        }

        echo "HELP COMMAND seen: chatId=" . ($message->chatId ?? 'null') . PHP_EOL;

        $this->sendSelfHtml($this->helpText());
    }

    #[Handler]
    public function autoCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if ($text === '') {
            return;
        }

        // پیام‌های راهنما/پاسخ‌هایی که خود ربات در Saved Messages می‌فرستد
        // نباید به عنوان جواب مرحله بعدی پردازش شوند، وگرنه لوپ تکرار می‌سازند.
        if ($this->isBotPromptText($text) || $this->shouldIgnoreSelfMessage($text)) {
            echo "Ignored bot/self prompt in auto handler." . PHP_EOL;
            return;
        }

        $state = $this->loadAutoState();
        $hasActiveAutoSetup = ($state['step'] ?? null) !== null;

        // اگر دستور /auto نیست و وسط ساخت ارسال خودکار هم نیستیم، کاری نکن.
        if (!str_starts_with($text, '/auto') && !$hasActiveAutoSetup) {
            return;
        }

        echo "AUTO HANDLER seen: {$text} chatId=" . ($message->chatId ?? 'null') . " step=" . ($state['step'] ?? '-') . PHP_EOL;

        if (!$this->isSavedMessages($message)) {
            return;
        }

        if ($text === '/auto cancel') {
            $this->clearAutoState();
            $this->sendSelf('لغو شد.');
            return;
        }

        if ($text === '/auto stop' || $text === '/auto stop_all') {
            $campaigns = $this->loadAutoCampaigns();

            foreach ($campaigns as $id => $campaign) {
                $campaigns[$id]['enabled'] = false;
            }

            $this->saveAutoCampaigns($campaigns);
            $this->clearAutoState();
            $this->sendSelf("⛔ همه ارسال‌های اتوماتیک متوقف شدند.");
            return;
        }

        if ($text === '/auto message_list') {
            $this->sendSelf($this->formatAutoList($this->loadAutoCampaigns()));
            return;
        }

        if (preg_match('/^\/auto\s+(message_delete|delete)\s+([A-Za-z0-9_\-]+)$/', $text, $m)) {
            $id = $m[2];
            $campaigns = $this->loadAutoCampaigns();

            if (!isset($campaigns[$id])) {
                $this->sendSelf("موردی با ID {$id} پیدا نشد.");
                return;
            }

            unset($campaigns[$id]);
            $this->saveAutoCampaigns($campaigns);
            $this->sendSelf("حذف شد: {$id}");
            return;
        }

        if (preg_match('/^\/auto\s+(message_pause|message_stop|pause|stop)\s+([A-Za-z0-9_\-]+)$/', $text, $m)) {
            $id = $m[2];
            $campaigns = $this->loadAutoCampaigns();

            if (!isset($campaigns[$id])) {
                $this->sendSelf("موردی با ID {$id} پیدا نشد.");
                return;
            }

            $campaigns[$id]['enabled'] = false;
            $campaigns[$id]['next_run_at'] = time() + 31536000;
            $this->saveAutoCampaigns($campaigns);
            $this->sendSelf("⛔ متوقف شد: {$id}");
            return;
        }

        if (preg_match('/^\/auto\s+(message_resume|resume|start)\s+([A-Za-z0-9_\-]+)$/', $text, $m)) {
            $id = $m[2];
            $campaigns = $this->loadAutoCampaigns();

            if (!isset($campaigns[$id])) {
                $this->sendSelf("موردی با ID {$id} پیدا نشد.");
                return;
            }

            $campaigns[$id]['enabled'] = true;
            $campaigns[$id]['next_run_at'] = time();
            $this->saveAutoCampaigns($campaigns);
            $this->sendSelf("✅ دوباره فعال شد: {$id}");
            return;
        }

        if ($text === '/auto message_new') {
            $this->saveAutoState([
                'step' => 'waiting_targets',
                'targets' => [],
                'message' => null,
            ]);

            $this->sendSelf(
                "نام کاربری عمومی کانال یا گروه‌هایی که می‌خوای اتوماتیک پیام داخل آن‌ها فرستاده بشه رو بفرست.\n\n" .
                "مثال:\nramin48,fasfsafk,asjfaksjf\n\n" .
                "نیازی به @ نیست؛ اگر لینک t.me هم بفرستی خودش username را جدا می‌کند.\n\nبرای کانال‌ها باید ادمین و مجاز به ارسال پست باشی؛ برای گروه‌ها فقط باید اجازه ارسال پیام داشته باشی.\n\n" .
                "برای لغو:\n/auto cancel"
            );
            return;
        }

        if (($state['step'] ?? null) === 'waiting_targets') {
            $targets = $this->normalizeTargets($text);

            if ($targets === []) {
                $this->sendSelf(
                    "هیچ نام کاربری معتبری پیدا نشد. فقط username عمومی گروه/کانال را با ویرگول بفرست، مثل:\nramin48,fasfsafk,asjfaksjf"
                );
                return;
            }

            $state['step'] = 'waiting_message';
            $state['targets'] = $targets;
            $this->saveAutoState($state);

            $this->sendSelf(
                "✅ نام کاربری مقصدها ثبت شد:\n" . implode(', ', $targets) . "\n\n" .
                "حالا پیامی که می‌خوای اتوماتیک فرستاده بشه رو بفرست."
            );
            return;
        }

        if (($state['step'] ?? null) === 'waiting_message') {
            $state['step'] = 'waiting_interval';
            $state['message'] = $text;
            $this->saveAutoState($state);

            $this->sendSelf(
                "متن پیام ثبت شد.\n\n" .
                "هر چند دقیقه یک‌بار ارسال شود؟\n" .
                "حداقل مجاز: " . self::AUTO_MIN_INTERVAL_MINUTES . " دقیقه"
            );
            return;
        }

        if (($state['step'] ?? null) === 'waiting_interval') {
            if (!preg_match('/^\d+$/', $text)) {
                $this->sendSelf('فقط عدد دقیقه را بفرست. مثال: 30');
                return;
            }

            $interval = max((int) $text, self::AUTO_MIN_INTERVAL_MINUTES);
            $targets = $state['targets'] ?? [];
            $autoMessage = (string) ($state['message'] ?? '');

            if ($targets === [] || $autoMessage === '') {
                $this->clearAutoState();
                $this->sendSelf('اطلاعات ناقص بود؛ دوباره از /auto message_new شروع کن.');
                return;
            }

            $id = 'msg_' . date('Ymd_His') . '_' . random_int(100, 999);
            $campaigns = $this->loadAutoCampaigns();

            $campaigns[$id] = [
                'id' => $id,
                'enabled' => true,
                'targets' => $targets,
                'message' => $autoMessage,
                'interval_minutes' => $interval,
                'created_at' => time(),
                'last_run_at' => null,
                'next_run_at' => time(),
            ];

            $this->saveAutoCampaigns($campaigns);
            $this->clearAutoState();

            $this->sendSelf(
                "✅ ارسال اتوماتیک ساخته شد.\n\n" .
                "ID: {$id}\n" .
                "Targets: " . implode(', ', $targets) . "\n" .
                "Interval: {$interval} دقیقه\n\n" .
                "برای دیدن لیست:\n/auto message_list\n\n" .
                "برای توقف:\n/auto message_pause {$id}"
            );
        }
    }

    #[Handler]
    public function personalSendCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if ($text === '') {
            return;
        }

        // Ignore the bot's own prompts/answers echoed back into Saved Messages.
        if ($this->isBotPromptText($text) || $this->shouldIgnoreSelfMessage($text)) {
            return;
        }

        $state = $this->loadPersonalState();
        $hasActiveSetup = ($state['step'] ?? null) !== null;

        if (!str_starts_with($text, '/send_personal') && !$hasActiveSetup) {
            return;
        }

        if (!$this->isSavedMessages($message)) {
            return;
        }

        if ($text === '/send_personal cancel') {
            $this->clearPersonalState();
            $this->sendSelf('لغو شد.');
            return;
        }

        if ($text === '/send_personal list') {
            $this->sendSelf($this->formatPersonalList($this->loadPersonalSends()));
            return;
        }

        if (preg_match('/^\/send_personal\s+delete\s+([A-Za-z0-9_\-]+)$/', $text, $m)) {
            $id = $m[1];
            $jobs = $this->loadPersonalSends();
            if (!isset($jobs[$id])) {
                $this->sendSelf("موردی با ID {$id} پیدا نشد.");
                return;
            }
            unset($jobs[$id]);
            $this->savePersonalSends($jobs);
            $this->sendSelf("حذف شد: {$id}");
            return;
        }

        if ($text === '/send_personal' || $text === '/send_personal new') {
            $this->savePersonalState(['step' => 'ps_waiting_message', 'message' => null]);
            $this->sendSelf(
                "✉️ ارسال شخصی زمان‌بندی‌شده\n\n" .
                "پیامی که می‌خوای برای افراد ارسال بشه رو الان بفرست.\n\n" .
                "برای لغو:\n/send_personal cancel"
            );
            return;
        }

        if (($state['step'] ?? null) === 'ps_waiting_message') {
            $state['step'] = 'ps_waiting_targets';
            $state['message'] = $text;
            $this->savePersonalState($state);

            $this->sendSelf(
                "متن پیام ثبت شد.\n\n" .
                "حالا یوزرنیم افرادی که می‌خوای این پیام براشون ارسال بشه رو با ویرگول بفرست.\n\n" .
                "مثال:\nali_reza, sara_m, mohammad123\n\n" .
                "نیازی به @ نیست؛ لینک t.me هم قبول است. حداکثر " . self::PERSONAL_MAX_TARGETS . " نفر."
            );
            return;
        }

        if (($state['step'] ?? null) === 'ps_waiting_targets') {
            $targets = $this->normalizePersonalTargets($text);

            if ($targets === []) {
                $this->sendSelf("هیچ یوزرنیم معتبری پیدا نشد. فقط یوزرنیم عمومی را با ویرگول بفرست، مثل:\nali_reza, sara_m");
                return;
            }

            $personalMessage = (string) ($state['message'] ?? '');
            if ($personalMessage === '') {
                $this->clearPersonalState();
                $this->sendSelf('پیام ناقص بود؛ دوباره از /send_personal شروع کن.');
                return;
            }

            $recipients = $this->schedulePersonalSend($targets);

            $id = 'ps_' . date('Ymd_His') . '_' . random_int(100, 999);
            $jobs = $this->loadPersonalSends();
            $jobs[$id] = [
                'id'         => $id,
                'message'    => $personalMessage,
                'created_at' => time(),
                'recipients' => $recipients,
            ];
            $this->savePersonalSends($jobs);
            $this->clearPersonalState();

            $lines = "✅ ارسال شخصی زمان‌بندی شد.\n\nID: {$id}\nتعداد: " . count($recipients) . " نفر\n\n⏱ زمان‌بندی:\n";
            foreach ($recipients as $r) {
                $lines .= "• {$r['username']} → " . date('H:i:s', (int) $r['send_at']) . "\n";
            }
            $lines .= "\nبرای دیدن وضعیت:\n/send_personal list";

            $this->sendSelf($lines);
            return;
        }
    }

    private function processPersonalSends(): void
    {
        $jobs = $this->loadPersonalSends();

        if ($jobs === []) {
            return;
        }

        $now = time();
        $changed = false;

        foreach ($jobs as $jid => $job) {
            $recipients = $job['recipients'] ?? [];
            $allDone = true;

            foreach ($recipients as $ri => $r) {
                if (!empty($r['sent'])) {
                    continue;
                }
                $allDone = false;

                if ((int) ($r['send_at'] ?? 0) > $now) {
                    continue;
                }

                try {
                    $this->messages->sendMessage(
                        peer: (string) $r['username'],
                        message: (string) $job['message']
                    );

                    $jobs[$jid]['recipients'][$ri]['sent'] = true;
                    $jobs[$jid]['recipients'][$ri]['sent_at'] = date('Y-m-d H:i:s');
                    $changed = true;
                    $this->savePersonalSends($jobs);

                    echo "PERSONAL {$jid}: sent to {$r['username']}" . PHP_EOL;
                    $this->sendSelf("✅ پیام شخصی برای {$r['username']} ارسال شد.");
                    delay(2);
                } catch (\Throwable $e) {
                    echo "PERSONAL {$jid}: failed to send to {$r['username']}: {$e->getMessage()}" . PHP_EOL;

                    if (preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $fm)) {
                        $wait = (int) $fm[1] + 5;
                        $jobs[$jid]['recipients'][$ri]['send_at'] = time() + $wait;
                        $this->savePersonalSends($jobs);
                        delay($wait);
                        return;
                    }

                    $attempts = (int) ($jobs[$jid]['recipients'][$ri]['attempts'] ?? 0) + 1;
                    $jobs[$jid]['recipients'][$ri]['attempts'] = $attempts;
                    $jobs[$jid]['recipients'][$ri]['error'] = $e->getMessage();

                    if ($attempts >= 3) {
                        // Give up on this recipient after 3 tries.
                        $jobs[$jid]['recipients'][$ri]['sent'] = true;
                        $this->sendSelf("⚠️ ارسال به {$r['username']} ناموفق بود: " . $e->getMessage());
                    } else {
                        $jobs[$jid]['recipients'][$ri]['send_at'] = time() + 120;
                    }
                    $changed = true;
                    $this->savePersonalSends($jobs);
                }
            }

            // Drop jobs whose recipients are all handled.
            if ($allDone) {
                unset($jobs[$jid]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->savePersonalSends($jobs);
        }
    }

    private function processAutoCampaigns(): void
    {
        $campaigns = $this->loadAutoCampaigns();

        if ($campaigns === []) {
            return;
        }

        $now = time();
        $changed = false;

        foreach ($campaigns as $id => $campaign) {
            if (!($campaign['enabled'] ?? false)) {
                continue;
            }

            $nextRunAt = (int) ($campaign['next_run_at'] ?? 0);

            if ($nextRunAt > $now) {
                continue;
            }

            $targets = $campaign['targets'] ?? [];
            $campaignMessage = (string) ($campaign['message'] ?? '');
            $intervalMinutes = max((int) ($campaign['interval_minutes'] ?? self::AUTO_MIN_INTERVAL_MINUTES), self::AUTO_MIN_INTERVAL_MINUTES);

            if ($targets === [] || $campaignMessage === '') {
                $campaigns[$id]['enabled'] = false;
                $changed = true;
                continue;
            }

            foreach ($targets as $target) {
                // قبل از هر مقصد، فایل را دوباره بخوان تا اگر stop/pause زدی، سریع‌تر اثر کند.
                $freshCampaigns = $this->loadAutoCampaigns();
                if (!($freshCampaigns[$id]['enabled'] ?? false)) {
                    echo "AUTO {$id}: stopped before sending to {$target}" . PHP_EOL;
                    return;
                }

                try {
                    $this->messages->sendMessage(
                        peer: $target,
                        message: $campaignMessage
                    );

                    echo "AUTO {$id}: sent to {$target}" . PHP_EOL;
                    delay(2);
                } catch (\Throwable $e) {
                    echo "AUTO {$id}: failed to send to {$target}: {$e->getMessage()}" . PHP_EOL;

                    if (preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m)) {
                        $wait = (int) $m[1] + 5;
                        $campaigns = $this->loadAutoCampaigns();
                        if (!isset($campaigns[$id]) || !($campaigns[$id]['enabled'] ?? false)) return;
                        $campaigns[$id]['next_run_at'] = time() + $wait;
                        $changed = true;
                        $this->saveAutoCampaigns($campaigns);
                        delay($wait);
                        return;
                    }
                }
            }

            // بعد از ارسال کامل هم دوباره چک می‌کنیم شاید وسط کار pause شده باشد.
            $freshCampaigns = $this->loadAutoCampaigns();
            if (!($freshCampaigns[$id]['enabled'] ?? false)) {
                return;
            }

            $campaigns = $freshCampaigns;
            $campaigns[$id]['last_run_at'] = time();
            $campaigns[$id]['next_run_at'] = time() + ($intervalMinutes * 60);
            $changed = true;
        }

        if ($changed) {
            $this->saveAutoCampaigns($campaigns);
        }
    }

    #[Handler]
    public function clockCommand(Message $message): void
    {
        $text = trim($message->message ?? '');

        if ($text !== '/clock on' && $text !== '/clock off') {
            return;
        }

        if (!$this->isSavedMessages($message)) {
            return;
        }

        if ($this->shouldIgnoreSelfMessage($text)) {
            return;
        }

        $me = $this->getSelf();

        if ($text === '/clock on') {
            $firstName = $this->cleanNameFromClock((string) ($me['first_name'] ?? ''));
            $lastName = $this->cleanNameFromClock((string) ($me['last_name'] ?? ''));

            $this->saveClockState([
                'enabled' => true,
                'base_first_name' => $firstName,
                'base_last_name' => $lastName,
            ]);

            $this->sendSelf('✅ Clock روشن شد. ساعت تهران کنار نام پروفایل نمایش داده می‌شود.');
            $this->updateProfileClockOnce();
            return;
        }

        $state = $this->loadClockState();
        $state['enabled'] = false;
        $this->saveClockState($state);

        $baseFirstName = (string) ($state['base_first_name'] ?? ($me['first_name'] ?? ''));
        $baseLastName = (string) ($state['base_last_name'] ?? ($me['last_name'] ?? ''));

        $this->account->updateProfile(
            first_name: $this->cleanNameFromClock($baseFirstName),
            last_name: $this->cleanNameFromClock($baseLastName)
        );

        $this->sendSelf('⛔ Clock خاموش شد و نام پروفایل به حالت قبل برگشت.');
    }

    private function updateProfileClockOnce(): void
    {
        $state = $this->loadClockState();

        if (!($state['enabled'] ?? false)) {
            return;
        }

        $baseFirstName = trim((string) ($state['base_first_name'] ?? ''));
        $baseLastName = trim((string) ($state['base_last_name'] ?? ''));

        if ($baseFirstName === '') {
            // First run (e.g. enabled from the panel): capture and persist the clean base name.
            $me = $this->getSelf();
            $baseFirstName = $this->cleanNameFromClock((string) ($me['first_name'] ?? 'User'));
            $baseLastName = $this->cleanNameFromClock((string) ($me['last_name'] ?? ''));
            $state['base_first_name'] = $baseFirstName;
            $state['base_last_name'] = $baseLastName;
            $this->saveClockState($state);
        }

        $time = $this->tehranTime();
        $newFirstName = trim($baseFirstName . ' | ' . $time);

        $this->account->updateProfile(
            first_name: $newFirstName,
            last_name: $baseLastName
        );

        echo "Profile clock updated: {$newFirstName}" . PHP_EOL;
    }

    public function onStart(): void
    {
        echo "UserBot started. Commands are active. Cron jobs are active." . PHP_EOL;
    }

    #[Cron(period: 60.0)]
    public function clockCron(): void
    {
        try {
            $state = $this->loadClockState();

            // Panel turned the clock off → strip the suffix and restore the name once.
            if (!empty($state['restore_pending'])) {
                $me = $this->getSelf();
                $baseFirst = trim((string) ($state['base_first_name'] ?? '')) !== ''
                    ? (string) $state['base_first_name']
                    : $this->cleanNameFromClock((string) ($me['first_name'] ?? ''));
                $baseLast = (string) ($state['base_last_name'] ?? ($me['last_name'] ?? ''));

                $this->account->updateProfile(
                    first_name: $this->cleanNameFromClock($baseFirst),
                    last_name: $this->cleanNameFromClock($baseLast)
                );

                $state['enabled'] = false;
                $state['restore_pending'] = false;
                $this->saveClockState($state);
                echo "Clock restored by panel request." . PHP_EOL;
                return;
            }

            if ($state['enabled'] ?? false) {
                $this->updateProfileClockOnce();
            }
        } catch (\Throwable $e) {
            echo "Clock cron error: {$e->getMessage()}" . PHP_EOL;
        }
    }

    #[Cron(period: 10.0)]
    public function autoCron(): void
    {
        if ($this->autoProcessing) {
            return;
        }

        $this->autoProcessing = true;

        try {
            $this->processAutoCampaigns();
        } catch (\Throwable $e) {
            echo "Auto campaign cron error: {$e->getMessage()}" . PHP_EOL;
        } finally {
            $this->autoProcessing = false;
        }
    }

    #[Cron(period: 15.0)]
    public function personalSendCron(): void
    {
        if ($this->personalProcessing) {
            return;
        }

        $this->personalProcessing = true;

        try {
            $this->processPersonalSends();
        } catch (\Throwable $e) {
            echo "Personal send cron error: {$e->getMessage()}" . PHP_EOL;
        } finally {
            $this->personalProcessing = false;
        }
    }

    #[Handler]
    public function saveIncoming(Incoming&IsNotEdited&Message $message): void
    {
        $this->ensureMediaDir();

        $text = trim($message->message ?? '');
        $mediaPath = null;
        $mediaType = 'none';
        $media = $message->media ?? null;

        // Only attempt a download for media that actually carries a file. This
        // avoids the noisy extractBotAPIFile errors that polls / geo / contacts
        // throw, and focuses retries on real files.
        if ($this->mediaIsDownloadable($message)) {
            $mediaPath = $this->downloadMediaRobust($message, $media);
        }

        $mediaType = $this->mediaKindFromMessage($message, $mediaPath);
        $specialContent = $this->specialContentDescription($message, $mediaType);
        $isViewOnce = $this->isViewOnceMedia($message);

        if ($text === '' && $mediaPath === null && $specialContent === '' && !$isViewOnce) {
            echo "Skipped empty message {$message->id}" . PHP_EOL;
            return;
        }

        $chatId = $message->chatId ?? null;
        $messageId = $message->id;

        $cache = $this->loadCache();
        $key = $this->key($chatId, $messageId);

        $senderId = $message->senderId ?? null;
        $senderInfo = $this->peerInfo($senderId);
        $chatInfo = $this->peerInfo($chatId);
        $chatKind = $this->chatKindFromInfo($chatInfo);

        $cache[$key] = [
            'id' => $messageId,
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'sender_info' => $senderInfo,
            'chat_info' => $chatInfo,
            'chat_kind' => $chatKind,
            'text' => $text,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'special_content' => $specialContent,
            'is_view_once' => $isViewOnce,
            'date' => date('Y-m-d H:i:s'),
        ];

        if (count($cache) > 1000) {
            $cache = array_slice($cache, -1000, null, true);
        }

        $this->saveCache($cache);

        echo "SAVED key={$key} text_len=" . strlen($text) . " media={$mediaType} path=" . ($mediaPath ?? '-') . " view_once=" . ($isViewOnce ? 'yes' : 'no') . PHP_EOL;

        if ($isViewOnce) {
            $this->sendViewOnceItemImmediately($cache[$key]);
        }
    }

    #[Handler]
    public function editedIncoming(Incoming&IsEdited&Message $message): void
    {
        $editState = $this->loadEditState();

        if (!($editState['enabled'] ?? true)) {
            echo "Edited-message saving is OFF, skipped." . PHP_EOL;
            return;
        }

        $chatId = $message->chatId ?? null;
        $messageId = $message->id;
        $key = $this->key($chatId, $messageId);

        $cache = $this->loadCache();
        $foundKey = null;

        if (isset($cache[$key])) {
            $foundKey = $key;
        } else {
            foreach ($cache as $cacheKey => $item) {
                if ((string) ($item['id'] ?? '') === (string) $messageId) {
                    if ($chatId !== null && isset($item['chat_id']) && (string) $item['chat_id'] !== (string) $chatId) {
                        continue;
                    }

                    $foundKey = $cacheKey;
                    break;
                }
            }
        }

        $newText = trim($message->message ?? '');
        $newMediaPath = null;
        $newMediaType = 'none';

        $this->ensureMediaDir();
        if ($this->mediaIsDownloadable($message)) {
            $newMediaPath = $this->downloadMediaRobust($message, $message->media ?? null);
        }

        $newMediaType = $this->mediaKindFromMessage($message, $newMediaPath);
        $newSpecialContent = $this->specialContentDescription($message, $newMediaType);

        $senderId = $message->senderId ?? null;
        $senderInfo = $this->peerInfo($senderId);
        $chatInfo = $this->peerInfo($chatId);
        $chatKind = $this->chatKindFromInfo($chatInfo);

        if ($foundKey === null) {
            echo "Edited message {$messageId} not found in cache; storing latest version only." . PHP_EOL;

            $cache[$key] = [
                'id' => $messageId,
                'chat_id' => $chatId,
                'sender_id' => $senderId,
                'sender_info' => $senderInfo,
                'chat_info' => $chatInfo,
                'chat_kind' => $chatKind,
                'text' => $newText,
                'media_path' => $newMediaPath,
                'media_type' => $newMediaType,
                'special_content' => $newSpecialContent,
                'date' => date('Y-m-d H:i:s'),
            ];

            $this->saveCache($cache);
            return;
        }

        $oldItem = $cache[$foundKey];
        $oldText = trim((string) ($oldItem['text'] ?? ''));

        // اگر متن/کپشن تغییری نکرده و فقط یک آپدیت تکراری بود، گزارش نده.
        $oldSpecialContent = trim((string) ($oldItem['special_content'] ?? ''));

        $oldMediaPath = $oldItem['media_path'] ?? null;
        $mediaChanged = false;

        if ($newMediaPath !== null && $oldMediaPath === null) {
            $mediaChanged = true;
        }

        if ($newMediaPath !== null && $oldMediaPath !== null && basename((string) $newMediaPath) !== basename((string) $oldMediaPath)) {
            $mediaChanged = true;
        }

        if ($oldText === $newText && $oldSpecialContent === $newSpecialContent && !$mediaChanged) {
            echo "Edited message {$messageId} has no useful diff, skipped." . PHP_EOL;
        } else {
            $this->sendEditedItemToTarget($oldItem, $newText, $newMediaPath, $newMediaType, $newSpecialContent);
        }

        // نسخه جدید را برای ویرایش‌های بعدی و حذف احتمالی بعدی ذخیره می‌کنیم.
        $cache[$foundKey] = [
            'id' => $messageId,
            'chat_id' => $chatId,
            'sender_id' => $senderId,
            'sender_info' => $senderInfo,
            'chat_info' => $chatInfo,
            'chat_kind' => $chatKind,
            'text' => $newText,
            'media_path' => $newMediaPath ?? ($oldItem['media_path'] ?? null),
            'media_type' => $newMediaPath !== null ? $newMediaType : ($oldItem['media_type'] ?? 'none'),
            'special_content' => $newSpecialContent,
            'date' => $oldItem['date'] ?? date('Y-m-d H:i:s'),
            'edited_at' => date('Y-m-d H:i:s'),
        ];

        $this->saveCache($cache);

        echo "EDIT recovered key={$foundKey}" . PHP_EOL;
    }

    #[Handler]
    public function deletedPrivateOrGroup(DeleteMessages $event): void
    {
        echo "DELETE event private/group ids=" . implode(',', $event->ids) . PHP_EOL;

        if ($this->recoverFullChatIfHistoryDeleted(array_map('intval', $event->ids), null)) {
            return;
        }

        foreach ($event->ids as $id) {
            $this->recoverDeletedById((int) $id, null);
        }
    }

    #[Handler]
    public function deletedChannel(DeleteChannelMessages $event): void
    {
        echo "DELETE event channel chat={$event->chatId} ids=" . implode(',', $event->ids) . PHP_EOL;

        if ($this->recoverFullChatIfHistoryDeleted(array_map('intval', $event->ids), $event->chatId)) {
            return;
        }

        foreach ($event->ids as $id) {
            $this->recoverDeletedById((int) $id, $event->chatId);
        }
    }

    private function recoverFullChatIfHistoryDeleted(array $deletedIds, ?int $chatId): bool
    {
        $deleteState = $this->loadDeleteState();

        if (!($deleteState['enabled'] ?? true)) {
            return false;
        }

        if (!($deleteState['full_chat_enabled'] ?? true)) {
            return false;
        }

        $threshold = (int) ($deleteState['full_chat_threshold'] ?? self::DEFAULT_FULL_CHAT_DELETE_THRESHOLD);

        if (count($deletedIds) < $threshold) {
            return false;
        }

        $cache = $this->loadCache();

        if ($cache === []) {
            return false;
        }

        $targetChatId = $chatId;

        if ($targetChatId === null) {
            $scores = [];

            foreach ($cache as $item) {
                $itemId = (int) ($item['id'] ?? 0);

                if (!in_array($itemId, $deletedIds, true)) {
                    continue;
                }

                if (!isset($item['chat_id'])) {
                    continue;
                }

                $candidateChatId = (string) $item['chat_id'];
                $scores[$candidateChatId] = ($scores[$candidateChatId] ?? 0) + 1;
            }

            if ($scores === []) {
                return false;
            }

            arsort($scores);
            $targetChatId = (int) array_key_first($scores);

            if ((int) reset($scores) < min(3, count($deletedIds))) {
                return false;
            }
        }

        $chatItems = [];

        foreach ($cache as $key => $item) {
            if (!isset($item['chat_id'])) {
                continue;
            }

            if ((string) $item['chat_id'] !== (string) $targetChatId) {
                continue;
            }

            $chatItems[$key] = $item;
        }

        if ($chatItems === []) {
            return false;
        }

        uasort($chatItems, static function (array $a, array $b): int {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $firstItem = reset($chatItems);
        $chatLine = $this->formatPeerInfo($firstItem['chat_info'] ?? [
            'id' => $targetChatId,
            'name' => 'unknown',
            'username' => null,
        ]);

        if (!$this->chatAllowedByFilter($firstItem, (string) ($deleteState['chat_filter'] ?? 'all'))) {
            echo "Full chat recovery skipped by chat filter." . PHP_EOL;
            return true;
        }

        $targetPeer = $this->getDeletedTargetPeer();

        $this->sendReportText(
            $targetPeer,
            "🧹 حذف کل تاریخچه چت تشخیص داده شد\n\n" .
            "Chat: {$chatLine}\n" .
            "Chat ID: {$targetChatId}\n" .
            "Deleted IDs in update: " . count($deletedIds) . "\n" .
            "Cached messages to send: " . count($chatItems) . "\n\n" .
            "فقط پیام‌هایی قابل بازیابی‌اند که وقتی بات روشن بوده cache شده باشند."
        );

        foreach ($chatItems as $key => $item) {
            $this->archiveDeletedItem($item, 'deleted');
            $this->sendDeletedItemToSaved($item);
            unset($cache[$key]);

            // جلوگیری از FloodWait در بازیابی‌های حجیم
            delay(0.35);
        }

        $this->saveCache($cache);

        echo "FULL CHAT RECOVERED chat={$targetChatId} count=" . count($chatItems) . PHP_EOL;
        return true;
    }

    private function recoverDeletedById(int $deletedId, ?int $chatId): void
    {
        $cache = $this->loadCache();
        $foundKey = null;

        if ($chatId !== null) {
            $directKey = $this->key($chatId, $deletedId);

            if (isset($cache[$directKey])) {
                $foundKey = $directKey;
            }
        }

        if ($foundKey === null) {
            foreach ($cache as $key => $item) {
                if ((string) ($item['id'] ?? '') !== (string) $deletedId) {
                    continue;
                }

                if ($chatId !== null && isset($item['chat_id'])) {
                    if ((string) $item['chat_id'] !== (string) $chatId) {
                        continue;
                    }
                }

                $foundKey = $key;
                break;
            }
        }

        if ($foundKey === null) {
            echo "DELETE id={$deletedId} not found in cache" . PHP_EOL;
            return;
        }

        $this->archiveDeletedItem($cache[$foundKey], 'deleted');
        $this->sendDeletedItemToSaved($cache[$foundKey]);

        unset($cache[$foundKey]);
        $this->saveCache($cache);

        echo "RECOVERED deleted key={$foundKey}" . PHP_EOL;
    }

    private function shortCaption(string $caption, int $limit = 900): string
    {
        if (mb_strlen($caption, 'UTF-8') <= $limit) {
            return $caption;
        }

        return mb_substr($caption, 0, $limit, 'UTF-8') . "\n...";
    }

    private function sendReportText(string $targetPeer, string $text): void
    {
        // پیام گزارش را جدا می‌فرستیم تا محدودیت caption تلگرام باعث ارسال‌نشدن عکس/ویدیو/فایل نشود.
        $chunks = mb_str_split($text, 3500, 'UTF-8');

        foreach ($chunks as $chunk) {
            $this->messages->sendMessage(
                peer: $targetPeer,
                message: $chunk
            );
        }
    }

    private function sendStoredMedia(string $targetPeer, string $mediaPath, string $mediaType, string $caption): void
    {
        if (!isFile($mediaPath)) {
            $this->messages->sendMessage(
                peer: $targetPeer,
                message: "⚠️ فایل ذخیره‌شده پیدا نشد:\n" . $mediaPath
            );
            return;
        }

        $file = new LocalFile($mediaPath);
        $shortCaption = $this->shortCaption($caption);

        try {
            match ($mediaType) {
                'photo' => $this->sendPhoto(peer: $targetPeer, file: $file, caption: $shortCaption),
                'gif' => $this->sendGif(peer: $targetPeer, file: $file, caption: $shortCaption),
                'video' => $this->sendVideo(peer: $targetPeer, file: $file, caption: $shortCaption),
                'audio' => $this->sendAudio(peer: $targetPeer, file: $file, caption: $shortCaption),
                default => $this->sendDocument(peer: $targetPeer, file: $file, caption: $shortCaption),
            };
        } catch (\Throwable $e) {
            echo "Send typed media failed, fallback to document: {$e->getMessage()}" . PHP_EOL;

            try {
                $this->sendDocument(
                    peer: $targetPeer,
                    file: $file,
                    caption: $shortCaption
                );
            } catch (\Throwable $e2) {
                echo "Send document fallback failed: {$e2->getMessage()}" . PHP_EOL;
                $this->messages->sendMessage(
                    peer: $targetPeer,
                    message: "⚠️ فایل ذخیره شد ولی ارسالش خطا داد:\n" . $e2->getMessage() . "\n\nPath: " . $mediaPath
                );
            }
        }
    }

    private function sendEditedItemToTarget(array $oldItem, string $newText, ?string $newMediaPath, string $newMediaType, string $newSpecialContent = ''): void
    {
        $editState = $this->loadEditState();

        if (!$this->chatAllowedByFilter($oldItem, (string) ($editState['chat_filter'] ?? 'all'))) {
            echo "Edited message skipped by chat filter." . PHP_EOL;
            return;
        }

        $targetPeer = $this->getEditedTargetPeer();

        $oldText = trim((string) ($oldItem['text'] ?? ''));
        $oldMediaPath = $oldItem['media_path'] ?? null;
        $oldMediaType = $oldItem['media_type'] ?? 'none';
        $oldSpecialContent = trim((string) ($oldItem['special_content'] ?? ''));

        $senderLine = $this->formatPeerInfo($oldItem['sender_info'] ?? [
            'id' => $oldItem['sender_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $chatLine = $this->formatPeerInfo($oldItem['chat_info'] ?? [
            'id' => $oldItem['chat_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $report = "✏️ پیام ویرایش شد\n\n";
        $report .= "Message ID: " . ($oldItem['id'] ?? 'unknown') . "\n";
        $report .= "Chat ID: " . ($oldItem['chat_id'] ?? 'unknown') . "\n";
        $report .= "Sender ID: " . ($oldItem['sender_id'] ?? 'unknown') . "\n";
        $report .= "Sender: " . $senderLine . "\n";
        $report .= "Chat: " . $chatLine . "\n";
        $report .= "Original saved at: " . ($oldItem['date'] ?? 'unknown') . "\n";
        $report .= "Edited at: " . date('Y-m-d H:i:s') . "\n\n";

        $report .= "قبل از ویرایش:\n";
        $report .= $oldText !== '' ? $oldText : '[بدون متن/کپشن]';
        $report .= "\n\nبعد از ویرایش:\n";
        $report .= $newText !== '' ? $newText : '[بدون متن/کپشن]';

        if ($oldSpecialContent !== '') {
            $report .= "\n\nمحتوای قبلی:\n" . $oldSpecialContent;
        }

        if (trim($newSpecialContent) !== '') {
            $report .= "\n\nمحتوای جدید:\n" . trim($newSpecialContent);
        }

        $this->sendReportText($targetPeer, $report);

        if ($oldMediaPath !== null && isFile($oldMediaPath)) {
            $this->sendStoredMedia(
                $targetPeer,
                $oldMediaPath,
                (string) $oldMediaType,
                "فایل/مدیای قبل از ویرایش\nMessage ID: " . ($oldItem['id'] ?? 'unknown')
            );
        }

        if ($newMediaPath !== null && isFile($newMediaPath)) {
            $this->sendStoredMedia(
                $targetPeer,
                $newMediaPath,
                $newMediaType,
                "فایل/مدیای بعد از ویرایش\nMessage ID: " . ($oldItem['id'] ?? 'unknown')
            );
        }
    }

    private function sendDeletedItemToSaved(array $item): void
    {
        $deleteState = $this->loadDeleteState();

        if (!($deleteState['enabled'] ?? true)) {
            echo "Deleted-message saving is OFF, skipped." . PHP_EOL;
            return;
        }

        if (!$this->chatAllowedByFilter($item, (string) ($deleteState['chat_filter'] ?? 'all'))) {
            echo "Deleted message skipped by chat filter." . PHP_EOL;
            return;
        }

        $targetPeer = $this->getDeletedTargetPeer();

        $text = trim((string) ($item['text'] ?? ''));
        $mediaPath = $item['media_path'] ?? null;
        $mediaType = (string) ($item['media_type'] ?? 'none');

        $senderLine = $this->formatPeerInfo($item['sender_info'] ?? [
            'id' => $item['sender_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $chatLine = $this->formatPeerInfo($item['chat_info'] ?? [
            'id' => $item['chat_id'] ?? 'unknown',
            'name' => 'unknown',
            'username' => null,
        ]);

        $report = "🗑 پیام حذف‌شده ذخیره شد\n\n";
        $report .= "Message ID: " . ($item['id'] ?? 'unknown') . "\n";
        $report .= "Chat ID: " . ($item['chat_id'] ?? 'unknown') . "\n";
        $report .= "Sender ID: " . ($item['sender_id'] ?? 'unknown') . "\n";
        $report .= "Sender: " . $senderLine . "\n";
        $report .= "Chat: " . $chatLine . "\n";
        $report .= "Media Type: " . $mediaType . "\n";
        $report .= "Saved at: " . ($item['date'] ?? 'unknown') . "\n";

        if ($text !== '') {
            $report .= "\nمتن پیام:\n" . $text;
        }

        $specialContent = trim((string) ($item['special_content'] ?? ''));
        if ($specialContent !== '') {
            $report .= "\n\nمحتوا:\n" . $specialContent;
        }

        $this->sendReportText($targetPeer, $report);

        if ($mediaPath !== null && isFile($mediaPath)) {
            $this->sendStoredMedia(
                $targetPeer,
                $mediaPath,
                $mediaType,
                "فایل/مدیای پیام حذف‌شده\nMessage ID: " . ($item['id'] ?? 'unknown')
            );

            try {
                deleteFile($mediaPath);
            } catch (\Throwable $e) {
                echo "Delete local media failed: {$e->getMessage()}" . PHP_EOL;
            }
        }
    }
}

$apiId = (int) (getenv('TG_API_ID') ?: 0);
$apiHash = (string) (getenv('TG_API_HASH') ?: '');
$sessionPath = (string) (getenv('TG_SESSION') ?: (__DIR__ . '/session.madeline'));

if ($apiId <= 0 || $apiHash === '') {
    fwrite(STDERR, "TG_API_ID یا TG_API_HASH تنظیم نشده است.\n");
    exit(1);
}

initialize_madeline_runtime();
$settings = new Settings;

$appInfo = new AppInfo;
$appInfo->setApiId($apiId);
$appInfo->setApiHash($apiHash);

$settings->setAppInfo($appInfo);
configure_madeline_logger($settings);

UserBot::startAndLoop($sessionPath, $settings);
