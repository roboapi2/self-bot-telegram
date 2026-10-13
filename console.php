<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
$command = $argv[1] ?? 'help';
try {
    switch ($command) {
        case 'doctor':
            $errors = [];
            if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE !== 8) $errors[] = 'PHP 8.2 یا جدیدتر، ۶۴ بیت لازم است';
            foreach (['mbstring', 'xml', 'dom', 'fileinfo', 'openssl', 'posix', 'pcntl'] as $ext) {
                if (!extension_loaded($ext)) $errors[] = 'افزونه نصب نیست: ' . $ext;
            }
            foreach (['shell_exec', 'exec', 'proc_open'] as $fn) if (!function_exists($fn)) $errors[] = 'تابع غیرفعال است: ' . $fn;
            if (!is_file(__DIR__ . '/vendor/autoload.php')) $errors[] = 'نصب وابستگی‌های Composer لازم است';
            $c = cfg();
            if (base_dir() !== realpath(__DIR__)) $errors[] = 'base_dir باید به پوشه همین پروژه اشاره کند';
            if ((string) $c['admin_password'] === '' || $c['admin_password'] === 'CHANGE_THIS_PASSWORD') $errors[] = 'رمز مدیر پنل را تنظیم کنید';
            if ((int) $c['api_id'] <= 0 || !preg_match('/^[a-f0-9]{32}$/i', (string) $c['api_hash'])) $errors[] = 'API ID و API Hash تلگرام را تنظیم کنید';
            if (PHP_OS_FAMILY !== 'Linux') $errors[] = 'اجرای کارگر تولیدی به Linux نیاز دارد';
            if (!is_executable((string) $c['php_bin'])) $errors[] = 'مسیر اجرایی PHP CLI را تنظیم کنید';
            elseif (function_exists('proc_open')) {
                $process = proc_open([(string) $c['php_bin'], '-r', 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 80200 && PHP_INT_SIZE === 8 ? 0 : 1);'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) $errors[] = 'اجرای PHP CLI ممکن نیست';
                else { foreach ($pipes as $pipe) fclose($pipe); if (proc_close($process) !== 0) $errors[] = 'نسخه یا نوع PHP CLI نامعتبر است'; }
            }
            foreach ([accounts_dir(), dirname(accounts_file())] as $dir) if (!is_writable($dir)) $errors[] = 'پوشه داده قابل نوشتن نیست';
            load_accounts(); // Validate registry without displaying its contents.
            foreach ($errors as $error) fwrite(STDERR, $error . "\n");
            echo $errors ? "بررسی اولیه ناموفق بود\n" : "بررسی اولیه موفق بود\n";
            exit($errors ? 1 : 0);
        case 'status': case 'diagnose':
            $rows = [];
            foreach (load_accounts() as $key => $acc) {
                $s = lifecycle_status($key);
                $rows[] = ['key' => $key, 'state' => $s['state'], 'running' => $s['running'], 'enabled' => $s['enabled'],
                    'process_alive' => $s['process_alive'], 'stage' => $s['stage'] ?? null, 'last_attempt' => $s['last_attempt'] ?? null,
                    'attempts' => $s['attempts'] ?? 0, 'retry_at' => $s['retry_at'] ?? null,
                    'failure' => isset($s['failure']) && is_array($s['failure'])
                        ? array_intersect_key($s['failure'], array_flip(['category', 'time', 'exit_code', 'class', 'file', 'line'])) : null];
            }
            $output = $command === 'diagnose' ? [
                'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'process_mode' => cfg()['process_mode'],
                'application_writable' => is_writable(base_dir()), 'accounts_writable' => is_writable(accounts_dir()),
                'panel_writable' => is_writable(base_dir() . '/.panel'),
                'supervisor' => array_intersect_key((array) read_json_file(base_dir() . '/.panel/supervisor.health.json', []), array_flip(['pid', 'time', 'scan_completed', 'accounts_checked'])),
                'accounts' => $rows,
            ] : $rows;
            echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            break;
        case 'start': case 'stop': case 'restart':
            $key = (string) ($argv[2] ?? '');
            if (!valid_account_key($key) || !isset(load_accounts()[$key])) throw new InvalidArgumentException('کلید حساب ناشناخته است');
            $fn = $command . '_account';
            $r = $fn($key);
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            exit($r['ok'] ? 0 : 1);
        case 'start-all':
            $r = start_all_accounts();
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            exit($r['failed'] ? 1 : 0);
        case 'stop-all':
            $r = stop_all_accounts();
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            exit($r['failed'] ? 1 : 0);
        case 'restart-running':
            $r = restart_running_accounts();
            echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            exit($r['failed'] ? 1 : 0);
        default: echo "Usage: php console.php doctor|status|diagnose|start KEY|stop KEY|restart KEY|start-all|stop-all|restart-running\n";
    }
} catch (Throwable $e) { fwrite(STDERR, redact_secrets($e->getMessage()) . "\n"); exit(1); }
