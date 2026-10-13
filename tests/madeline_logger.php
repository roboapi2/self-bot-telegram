<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$tmp = sys_get_temp_dir() . '/ubcc-logger-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
foreach (['lib.php', 'runtime.php'] as $file) copy(dirname(__DIR__) . '/' . $file, $tmp . '/' . $file);
file_put_contents($tmp . '/config.php', "<?php return ['base_dir'=>__DIR__, 'api_hash'=>'FIXTURE_API_HASH', 'admin_password'=>'FIXTURE_ADMIN_PASSWORD'];");
require $tmp . '/lib.php';

use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Magic;
use Revolt\EventLoop;

$checks = 0;
function verify_logger(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('Logger regression failed: ' . $label);
    ++$checks;
}
$oldLog = ini_get('error_log');
$oldDriver = EventLoop::getDriver();
try {
    // Isolate bootstrap hooks only: real vendor settings, serialization, Logger
    // and Revolt queue/driver are exercised; no API/session/Telegram is created.
    $bootstrap = new ReflectionProperty(Magic::class, 'initedLight');
    $oldBootstrap = $bootstrap->getValue();
    $bootstrap->setValue(null, true);
    Magic::$script_cwd = $tmp;
    ini_set('error_log', $tmp . '/error.log');
    $settings = new Settings;
    $settings->getLogger()->setType(Logger::CALLABLE_LOGGER)
        ->setExtra('ubcc_madeline_log')->setLevel(Logger::ERROR);
    $legacySerialized = serialize($settings->getLogger());
    try {
        $original = new Logger($settings->getLogger());
        $original->logger('QR fixture failure', Logger::ERROR);
        verify_logger(false, 'original string must reproduce TypeError');
    } catch (TypeError $error) {
        verify_logger(str_contains($error->getMessage(), 'Closure') && str_contains($error->getMessage(), 'string'), 'original queue TypeError');
    }
    configure_madeline_logger($settings);
    $legacyRestored = unserialize($legacySerialized, ['allowed_classes' => true]);
    $legacyRestored->merge($settings->getLogger());
    verify_logger($legacyRestored->getExtra() instanceof Closure, 'legacy string settings upgrade on restore');
    verify_logger($settings->getLogger()->getExtra() instanceof Closure, 'Closure callback');
    verify_logger($settings->getLogger()->getLevel() === Logger::NOTICE, 'vendor level clamp accounted for');
    $serialized = serialize($settings->getLogger());
    verify_logger(!str_contains($serialized, 'ubcc_madeline_log'), 'closure excluded by vendor __sleep');
    $restored = unserialize($serialized, ['allowed_classes' => true]);
    verify_logger($restored->getExtra() === null, 'closure not persisted');
    $restored->merge($settings->getLogger());
    verify_logger($restored->getExtra() instanceof Closure && $restored->getType() === Logger::CALLABLE_LOGGER, 'fresh settings restore callback');
    $restoredSettings = unserialize(serialize($settings), ['allowed_classes' => true]);
    verify_logger($restoredSettings->getLogger()->getExtra() === null, 'full settings serialization excludes closure');
    $restoredSettings->merge($settings);
    verify_logger($restoredSettings->getLogger()->getExtra() instanceof Closure, 'full settings merge restores closure');
    $logger = new Logger($restored);
    $logger->logger('QR fixture failure FIXTURE_API_HASH FIXTURE_ADMIN_PASSWORD', Logger::ERROR);
    $logger->logger('password="FIXTURE PASSWORD WITH SPACES", auth_key=FIXTURE_AUTH_KEY, token=FIXTURE_TOKEN', Logger::ERROR);
    $logger->logger('Authorization: Bearer FIXTURE_BEARER', Logger::ERROR);
    $logger->logger('session_secret=FIXTURE_SESSION_SECRET, session_string=FIXTURE_SESSION_STRING', Logger::ERROR);
    $logger->logger('tg://login?token=FIXTURE_QR_TOKEN', Logger::ERROR);
    $logger->logger('{"password":"FIXTURE_JSON PASSWORD", "api_hash":"FIXTURE_JSON_HASH"}', Logger::ERROR);
    $logger->logger("password='FIXTURE SINGLE PASSWORD', access_token=FIXTURE_ACCESS_TOKEN, refresh_token=FIXTURE_REFRESH_TOKEN", Logger::ERROR);
    $logger->logger('Bearer FIXTURE_BARE_BEARER', Logger::ERROR);
    $logger->logger(['auth_key' => 'FIXTURE_STRUCTURED_SECRET'], Logger::ERROR);
    $logger->logger(new RuntimeException('FIXTURE_EXCEPTION_SECRET'), Logger::ERROR);
    $logger->logger('FIXTURE_NOTICE_MUST_NOT_LOG', Logger::NOTICE);
    EventLoop::run();
    $output = file_get_contents($tmp . '/error.log');
    verify_logger(str_contains($output, 'QR fixture failure') && str_contains($output, '[REDACTED]'), 'queued errors processed');
    foreach (['FIXTURE_API_HASH', 'FIXTURE_ADMIN_PASSWORD', 'FIXTURE PASSWORD WITH SPACES', 'FIXTURE_AUTH_KEY',
              'FIXTURE_TOKEN', 'FIXTURE_BEARER', 'FIXTURE_SESSION_SECRET', 'FIXTURE_SESSION_STRING',
              'FIXTURE_QR_TOKEN', 'FIXTURE_JSON PASSWORD', 'FIXTURE_JSON_HASH', 'FIXTURE SINGLE PASSWORD',
              'FIXTURE_ACCESS_TOKEN', 'FIXTURE_REFRESH_TOKEN', 'FIXTURE_BARE_BEARER',
              'FIXTURE_STRUCTURED_SECRET', 'FIXTURE_EXCEPTION_SECRET', 'FIXTURE_NOTICE_MUST_NOT_LOG'] as $secret) {
        verify_logger(!str_contains($output, $secret), 'private fixture suppressed');
    }
    verify_logger(substr_count($output, 'MadelineProto protocol error') === 2, 'structured/throwable contents omitted');
    echo "$checks MadelineProto logger checks passed (real vendor/Revolt; no Telegram connection)\n";
} finally {
    ini_set('error_log', (string) $oldLog);
    EventLoop::setDriver($oldDriver);
    if (isset($bootstrap, $oldBootstrap)) $bootstrap->setValue(null, $oldBootstrap);
    Logger::$default = null;
    foreach (glob($tmp . '/*') as $file) unlink($file);
    rmdir($tmp);
}
