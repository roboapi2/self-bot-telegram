<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/runtime.php';
if (($argv[1] ?? '') === 'child') {
    $file = $argv[2];
    for ($i = 0; $i < 40; $i++) with_file_lock($file . '.lock', static function () use ($file): void {
        $n = (int) file_get_contents($file);
        usleep(random_int(500, 3000));
        file_put_contents($file, (string) ($n + 1));
    });
    exit;
}
$tmp = sys_get_temp_dir() . '/ubcc-concurrency-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700); $file = $tmp . '/counter'; file_put_contents($file, '0');
$workers = [];
try {
    for ($i = 0; $i < 4; $i++) $workers[] = proc_open([PHP_BINARY, __FILE__, 'child', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    foreach ($workers as $worker) if (proc_close($worker) !== 0) throw new RuntimeException('Concurrent child failed');
    if (trim(file_get_contents($file)) !== '160') throw new RuntimeException('Lost concurrent update');
    echo "4 writers / 160 locked updates passed\n";
} finally { foreach (glob($tmp . '/*') as $f) unlink($f); rmdir($tmp); }
