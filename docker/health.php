<?php
declare(strict_types=1);
try {
    if (($argv[1] ?? '') === 'web') {
        $env = array_merge(getenv(), ['SCRIPT_NAME' => '/fpm-ping', 'SCRIPT_FILENAME' => '/fpm-ping', 'REQUEST_METHOD' => 'GET']);
        $process = proc_open(['/usr/bin/cgi-fcgi', '-bind', '-connect', '127.0.0.1:9000'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) exit(1);
        fclose($pipes[0]); $body = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        exit(proc_close($process) === 0 && str_contains($body, 'pong') ? 0 : 1);
    }
    require '/opt/app/lib.php';
    load_accounts();
    $health = read_json_file('/opt/app/.panel/supervisor.health.json');
    $pid = (int) ($health['pid'] ?? 0);
    $cmd = $pid > 0 ? @file_get_contents('/proc/' . $pid . '/cmdline') : false;
    exit(time() - (int) ($health['time'] ?? 0) < 90 && $cmd !== false && in_array('/opt/app/supervisor.php', explode("\0", $cmd), true) ? 0 : 1);
} catch (Throwable) { exit(1); }
