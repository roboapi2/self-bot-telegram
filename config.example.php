<?php
/**
 * Copy this file to config.php and fill in your own values.
 *   cp config.example.php config.php
 */
return [
    'admin_password' => getenv('UBCC_ADMIN_PASSWORD') ?: 'CHANGE_THIS_PASSWORD',

    'api_id'   => (int) (getenv('UBCC_API_ID') ?: 0),
    'api_hash' => getenv('UBCC_API_HASH') ?: '',

    'base_dir'    => __DIR__,
    'php_bin'     => getenv('UBCC_PHP_BIN') ?: (PHP_SAPI === 'cli' ? PHP_BINARY : '/usr/bin/php'),
    'process_mode' => getenv('UBCC_PROCESS_MODE') ?: 'direct',
    'panel_title' => getenv('UBCC_TITLE') ?: 'UserBot Control Center',
    'timezone'    => getenv('UBCC_TZ') ?: 'Asia/Tehran',
    'session_ttl' => 86400,
];
