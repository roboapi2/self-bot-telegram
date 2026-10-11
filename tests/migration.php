<?php
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/ubcc-migration-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700); mkdir($tmp . '/scripts');
foreach (['lib.php', 'runtime.php'] as $file) copy(dirname(__DIR__) . '/' . $file, $tmp . '/' . $file);
copy(dirname(__DIR__) . '/scripts/migrate-storage.php', $tmp . '/scripts/migrate-storage.php');
file_put_contents($tmp . '/config.php', '<?php return ["base_dir"=>__DIR__];');
$legacy = '[{"key":"fixture","name":"آزمایش","extra":{"preserve":true}}]';
file_put_contents($tmp . '/accounts.json', $legacy);
function migrate(string $tmp): int {
    $p = proc_open([PHP_BINARY, $tmp . '/scripts/migrate-storage.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('proc_open failed');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($p);
}
function verify(bool $ok, string $name): void { if (!$ok) throw new RuntimeException('FAIL: ' . $name); }
try {
    verify(migrate($tmp) === 0, 'initial migration');
    verify(json_decode(file_get_contents($tmp . '/.panel/accounts.json'), true) === json_decode($legacy, true), 'all fields retained');
    verify(file_get_contents($tmp . '/accounts.json') === $legacy, 'legacy bytes preserved');
    $active = '[{"key":"new","name":"active"}]'; file_put_contents($tmp . '/.panel/accounts.json', $active);
    verify(migrate($tmp) === 0 && file_get_contents($tmp . '/.panel/accounts.json') === $active, 'reinstall retains active registry');
    file_put_contents($tmp . '/.panel/accounts.json', '{broken');
    verify(migrate($tmp) !== 0 && file_get_contents($tmp . '/.panel/accounts.json') === '{broken', 'corruption preserved and rejected');
    unlink($tmp . '/.panel/accounts.json');
    verify(migrate($tmp) !== 0 && !file_exists($tmp . '/.panel/accounts.json'), 'missing initialized storage cannot resurrect legacy');
    echo "6 migration checks passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
}
