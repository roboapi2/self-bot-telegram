<?php
declare(strict_types=1);
// CI runs this against the actual runtime image: UID10001, readonly, no network.
require '/opt/app/vendor/autoload.php';
if (($argv[1] ?? '') === 'legacy') {
    try {
        $settings = new \danog\MadelineProto\Settings\Logger;
        $settings->setType(\danog\MadelineProto\Logger::FILE_LOGGER)->setExtra('/opt/app/MadelineProto.log');
        new \danog\MadelineProto\Logger($settings);
        throw new RuntimeException('Legacy readonly failure not reproduced');
    } catch (\danog\MadelineProto\Exception $e) {
        if (!str_contains($e->getMessage(), 'Read-only file system')) throw $e;
        echo "Legacy Logger fopen readonly failure reproduced\n";
        exit(0);
    }
}
require '/opt/app/lib.php';
initialize_madeline_runtime();
$scratch = getcwd();
if (!str_starts_with($scratch, '/tmp/ubcc-madeline-') || (fileperms($scratch) & 0777) !== 0700
    || \danog\MadelineProto\Magic::$script_cwd !== $scratch) throw new RuntimeException('Unsafe scratch directory');
// Includes the forced FILE_LOGGER path used when MADELINE_WORKER is defined.
define('MADELINE_WORKER', 1);
$settings = new \danog\MadelineProto\Settings;
configure_madeline_logger($settings);
$settings->getLogger()->setExtra('/opt/app/MadelineProto.log');
$restored = unserialize(serialize($settings->getLogger()));
$logger = new \danog\MadelineProto\Logger($restored);
$logger->logger('Readonly fixture token=FIXTURE_SECRET', \danog\MadelineProto\Logger::ERROR);
// Force Python failures to exercise both real PrimeModule fallback copies.
$oldPath = getenv('PATH');
putenv('PATH=/nonexistent');
\danog\PrimeModule::python_single(15);
\danog\PrimeModule::python_single_alt(15);
putenv('PATH=' . $oldPath);
if (file_exists($scratch . '/.prime.py') || file_exists($scratch . '/.alt_prime.py')) throw new RuntimeException('Prime scratch file not removed');
$settings->getAppInfo()->setShowPrompt(false);
try {
    new \danog\MadelineProto\API($scratch . '/fixture-session', $settings);
    throw new RuntimeException('API unexpectedly accepted missing credentials');
} catch (\danog\MadelineProto\Exception $e) {
    if (!str_contains($e->getMessage(), 'No API ID or API hash')) throw $e;
    echo "Real API constructor reached credential validation without readonly failure\n";
}
// Exercise QR helper initialization/rendering with a synthetic token only.
$qr = (new ReflectionClass(\danog\MadelineProto\TL\Types\LoginQrCode::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($qr, 'link'))->setValue($qr, 'tg://login?token=FIXTURE_TOKEN');
(new ReflectionProperty($qr, 'expiry'))->setValue($qr, time() + 60);
if ($qr->isExpired() || !str_contains($qr->getQRSvg(), '<svg')) throw new RuntimeException('QR rendering failed');
echo "Readonly logger restoration, prime fallback, API preflight and offline QR rendering passed\n";
