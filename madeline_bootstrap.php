<?php
declare(strict_types=1);
// Used only by MadelineProto's spawned PHP process, before session restoration.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bot_template.php';
initialize_madeline_runtime();
// entry.php defines the worker constants after auto_prepend_file. Magic's light
// initialization has already run, so preserve the IPC identity explicitly.
if (($GLOBALS['argv'][1] ?? '') === 'madeline-ipc') \danog\MadelineProto\Magic::$isIpcWorker = true;
