<?php
declare(strict_types=1);
// This code, not production values, is baked into the image.
$value = static function (string $name, string $default = ''): string {
    $file = getenv($name . '_FILE');
    if ($file !== false && $file !== '') {
        if (!is_file($file) || !is_readable($file)) throw new RuntimeException('فایل secret خواندنی نیست.');
        return rtrim((string) file_get_contents($file), "\r\n");
    }
    $env = getenv($name);
    return $env === false ? $default : $env;
};
$config = ['admin_password' => $value('UBCC_ADMIN_PASSWORD', 'CHANGE_THIS_PASSWORD'),
    'api_id' => (int) $value('UBCC_API_ID', '0'), 'api_hash' => $value('UBCC_API_HASH'),
    'timezone' => $value('UBCC_TZ', 'Asia/Tehran'), 'panel_title' => $value('UBCC_TITLE', 'UserBot Control Center')];
$external = getenv('UBCC_CONFIG_FILE') ?: '/run/secrets/config.php';
if (is_file($external)) {
    $loaded = require $external;
    if (!is_array($loaded)) throw new RuntimeException('ساختار پیکربندی Docker نامعتبر است.');
    $config = array_merge($config, $loaded);
}
// Host installation paths never leak into the container configuration.
return array_merge($config, ['base_dir' => '/opt/app', 'php_bin' => '/usr/local/bin/php', 'process_mode' => 'supervisor']);
