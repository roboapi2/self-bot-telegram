<?php
declare(strict_types=1);
$checks = 0;
function verify(bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('Docker configuration contract failed'); ++$checks; }
$directory = sys_get_temp_dir() . '/ubcc-config-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    putenv('UBCC_CONFIG_FILE=' . $directory . '/missing.php');
    putenv('UBCC_API_ID=123'); putenv('UBCC_API_HASH=ENV_FIXTURE_ONLY'); putenv('UBCC_ADMIN_PASSWORD=ENV_FIXTURE_ONLY_PASSWORD');
    $c = require __DIR__ . '/../docker/config.php';
    verify($c['api_id'] === 123 && $c['api_hash'] === 'ENV_FIXTURE_ONLY');
    verify($c['base_dir'] === '/opt/app' && $c['process_mode'] === 'supervisor');
    verify($c['php_bin'] === '/usr/local/bin/php');
    file_put_contents($directory . '/secret', "FILE_FIXTURE_ONLY\n");
    putenv('UBCC_API_HASH_FILE=' . $directory . '/secret');
    $c = require __DIR__ . '/../docker/config.php';
    verify($c['api_hash'] === 'FILE_FIXTURE_ONLY');
    file_put_contents($directory . '/external.php', '<?php return ["api_id"=>456,"base_dir"=>"HOST_PATH","php_bin"=>"HOST_PHP"];');
    putenv('UBCC_CONFIG_FILE=' . $directory . '/external.php');
    $c = require __DIR__ . '/../docker/config.php';
    verify($c['api_id'] === 456);
    verify($c['base_dir'] === '/opt/app' && $c['php_bin'] === '/usr/local/bin/php');
    file_put_contents($directory . '/external.php', '<?php return false;');
    $rejected = false;
    try { require __DIR__ . '/../docker/config.php'; } catch (RuntimeException) { $rejected = true; }
    verify($rejected);
    echo "$checks Docker configuration checks passed\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
