<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib.php';
try {
    $root = base_dir();
    if ($root !== realpath(dirname(__DIR__))) throw new RuntimeException('base_dir باید به همین پوشه پروژه اشاره کند.');
    $panel = $root . '/.panel';
    if (is_link($panel) || is_link($root . '/accounts')) throw new RuntimeException('مسیر داده نباید پیوند نمادین باشد.');
    with_file_lock($panel . '/migration.lock', static function () use ($root, $panel): void {
        $active = $panel . '/accounts.json';
        $marker = $panel . '/storage.initialized';
        foreach ([$active, $marker, $root . '/accounts.json'] as $path) {
            if (is_link($path)) throw new RuntimeException('فایل فهرست یا نشانگر نباید پیوند نمادین باشد.');
        }
        if (is_file($active)) {
            validate_account_registry(read_json_file($active));
        } else {
            if (is_file($marker)) throw new RuntimeException('فهرست فعال از بین رفته؛ آن را از پشتیبان بازیابی کنید. فایل قدیمی جایگزین نشد.');
            $legacy = $root . '/accounts.json';
            $data = is_file($legacy) ? read_json_file($legacy) : [];
            validate_account_registry($data);
            if (!json_write_atomic($active, $data)) throw new RuntimeException('مهاجرت فهرست حساب‌ها انجام نشد.');
        }
        if (!is_file($marker) && !json_write_atomic($marker, ['version' => 1])) throw new RuntimeException('ثبت نشانگر مهاجرت انجام نشد.');
    });
    echo "فهرست فعال حساب‌ها معتبر است؛ داده قدیمی حفظ شد.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
