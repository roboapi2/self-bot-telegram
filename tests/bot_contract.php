<?php
declare(strict_types=1);
// Real class definition and vendor validator; no API/Telegram construction.
require dirname(__DIR__) . '/bot_template.php';
require dirname(__DIR__) . '/madeline_tools.php';
$count = 0;
function bot_check(bool $ok, string $name): void { global $count; if (!$ok) throw new RuntimeException('Bot contract: ' . $name); ++$count; }
bot_check(class_exists('UserBot', false), 'class loads without launcher credentials');
foreach (\danog\MadelineProto\Tools::validateEventHandlerClass(UserBot::class) as $issue) bot_check(!$issue->severe, 'vendor handler validation');
foreach ((new ReflectionClass(UserBot::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    foreach ($method->getAttributes() as $attribute) {
        $attribute->newInstance();
        if ($attribute->getName() === \danog\MadelineProto\EventHandler\Attributes\Handler::class) {
            $type = $method->getParameters()[0]->getType();
            bot_check(\danog\MadelineProto\EventHandler\Filter\Filter::fromReflectionType($type) instanceof \danog\MadelineProto\EventHandler\Filter\Filter, 'handler filter available');
        }
    }
}
$tmp = sys_get_temp_dir() . '/ubcc-bot-contract-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700); putenv('UBCC_ACCOUNT_DIR=' . $tmp);
$bot = (new ReflectionClass(UserBot::class))->newInstanceWithoutConstructor();
try {
    $call = static fn(string $method, mixed ...$args): mixed => (new ReflectionMethod(UserBot::class, $method))->invoke($bot, ...$args);
    bot_check($call('dataDir') === $tmp, 'account data path independent of cwd');
    bot_check(!$call('runtimeActive'), 'restored stopped handler is not operational');
    $bot->clockCron(); $bot->autoCron(); $bot->personalSendCron();
    bot_check(!is_file($tmp . '/clock_state.json'), 'stopped timers cannot modify profile/state');
    foreach (['cacheFile', 'mediaDir', 'archiveMediaDir', 'deletedArchiveFile', 'clockStateFile', 'autoStateFile', 'autoCampaignsFile', 'deleteStateFile', 'editStateFile'] as $method) {
        bot_check(str_starts_with($call($method), $tmp . '/'), 'private path ' . $method);
    }
    $call('archiveDeletedItem', ['id' => 1, 'text' => 'SYNTHETIC_PRIVATE_MESSAGE', 'chat_id' => 2], 'deleted');
    $archive = read_json_file($tmp . '/deleted_archive.json');
    bot_check($archive[0]['text'] === 'SYNTHETIC_PRIVATE_MESSAGE' && isset($archive[0]['archive_id']), 'deleted message archival');
    file_put_contents($tmp . '/synthetic-media.bin', str_repeat('fixture', 10000));
    $call('archiveDeletedItem', ['id' => 3, 'text' => '', 'media_path' => $tmp . '/synthetic-media.bin'], 'deleted');
    $archive = read_json_file($tmp . '/deleted_archive.json');
    bot_check(hash_file('sha256', $tmp . '/archive_media/' . $archive[1]['media_file']) === hash_file('sha256', $tmp . '/synthetic-media.bin'), 'archive media copied without whole-file buffering');
    bot_check(message_media_kind(['message' => 'photo video poll sticker']) === '', 'text cannot invent media');
    bot_check(message_media_kind(['media' => ['_' => 'messageMediaPhoto']]) === 'photo', 'photo constructor');
    bot_check(message_media_kind(['media' => ['_' => 'messageMediaDocument', 'document' => ['attributes' => [['_'=>'documentAttributeAudio','voice'=>true]]]]]) === 'voice', 'document media attributes');
    $call('saveJsonFile', $tmp . '/feature.json', ['enabled' => true]);
    bot_check($call('loadJsonFile', $tmp . '/feature.json', [])['enabled'], 'feature configuration persistence');
    // putenv updates are not present in $_SERVER: the old IPC export loses them.
    putenv('UBCC_ENV_FORWARD_FIXTURE=fixture');
    bot_check(!isset($_SERVER['UBCC_ENV_FORWARD_FIXTURE']) && getenv('UBCC_ENV_FORWARD_FIXTURE') === 'fixture', 'old environment export defect reproduced');
    bot_check(array_merge($_SERVER, getenv())['UBCC_ENV_FORWARD_FIXTURE'] === 'fixture', 'IPC environment forwarding');
    echo "$count bot definition, vendor filter, private storage and environment checks passed (no Telegram)\n";
} finally {
    putenv('UBCC_ACCOUNT_DIR'); putenv('UBCC_ENV_FORWARD_FIXTURE');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($tmp);
}
