<?php
declare(strict_types=1);
// Compatibility patch for pinned 8.7.0 only; fail closed on upstream changes.
$root = dirname(__DIR__);
$patches = [
    'src/Logger.php' => [
        'a090bb4e7e5e20c70dc64f9a4fe72d005d65f624da66e554bc91419b21822f46',
        [
            '$this->mode = $settings->getType();' => '$this->mode = \function_exists("ubcc_madeline_log") ? self::CALLABLE_LOGGER : $settings->getType();',
            '$optional = $settings->getExtra();' => '$optional = \function_exists("ubcc_madeline_log") ? \Closure::fromCallable("ubcc_madeline_log") : $settings->getExtra();',
        ],
    ],
    'src/Ipc/Runner/ProcessRunner.php' => [
        'dc1ecd16054d9883afda9b2cfc8223f7f836781b57fa2b7eb9f329f0b28b7531',
        [
            "        \$runner = self::getScriptPath();" => "        if (\\defined('UBCC_MADELINE_BOOTSTRAP')) \$options['auto_prepend_file'] = UBCC_MADELINE_BOOTSTRAP;\n\n        \$runner = self::getScriptPath();",
            "'cwd' => Magic::getcwd()," => "'cwd' => \\defined('UBCC_MADELINE_BOOTSTRAP') ? null : Magic::getcwd(),",
            "array_filter(\$_SERVER, static fn (\$v, \$k): bool =>" => "array_filter(array_merge(\$_SERVER, getenv()), static fn (\$v, \$k): bool =>",
        ],
    ],
    'src/EventHandler.php' => [
        '6fce62b2f1d29da258f0786a988b035e5242a121b3decaf251b488820163d2a2',
        ["            \$this->startedInternal = true;" => "            \$this->startedInternal = true;\n            if (static::class === 'UserBot' && \\function_exists('ubcc_event_handler_ready')) \\ubcc_event_handler_ready();"],
    ],
];
foreach ($patches as $relative => [$hash, $changes]) {
    $file = $root . '/vendor/danog/madelineproto/' . $relative;
    $source = str_replace("\r\n", "\n", file_get_contents($file));
    $original = str_replace(array_values($changes), array_keys($changes), $source);
    if (hash('sha256', $original) !== $hash) throw new RuntimeException('Unreviewed MadelineProto source: ' . $relative);
    $patched = str_replace(array_keys($changes), array_values($changes), $original);
    if ($source !== $patched && file_put_contents($file, $patched) === false) throw new RuntimeException('Cannot patch MadelineProto');
}
echo "Pinned MadelineProto readonly compatibility patch verified\n";
