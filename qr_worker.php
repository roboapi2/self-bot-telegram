<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/madeline_tools.php';

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use danog\MadelineProto\API;

$key = (string) ($argv[1] ?? '');
if ($key === '' || !valid_account_key($key)) {
    fwrite(STDERR, "account key missing or invalid\n");
    exit(1);
}

$paths     = account_paths($key);
$stateFile = $paths['qr_state'];
$twofaFile = $paths['qr_2fa'];

function write_qr_state(string $file, array $state): void
{
    $state['updated_at'] = date('Y-m-d H:i:s');
    json_write_atomic($file, $state);
}

/**
 * When Telegram asks for the 2FA password, the panel drops it in a file.
 * This worker (which holds the session) reads and submits it — no second
 * process ever touches the session, so there's no lock conflict.
 */
function wait_for_2fa_and_complete(API $api, string $twofaFile, string $stateFile): bool
{
    write_qr_state($stateFile, [
        'ok'        => true,
        'status'    => 'needs_2fa',
        'message'   => 'QR اسکن شد. رمز دو مرحله‌ای را در پنل وارد کن.',
        'svg'       => null,
        'needs_2fa' => true,
        'logged_in' => false,
    ]);

    $deadline = time() + 300; // give the user 5 minutes to type the password
    while (time() < $deadline) {
        clearstatcache(true, $twofaFile);
        if (is_file($twofaFile)) {
            $password = (string) @file_get_contents($twofaFile);
            @unlink($twofaFile);
            if ($password === '') {
                Amp\delay(1);
                continue;
            }
            try {
                $api->complete2faLogin($password);
                write_qr_state($stateFile, [
                    'ok'        => true,
                    'status'    => 'logged_in',
                    'message'   => 'ورود کامل شد.',
                    'svg'       => null,
                    'needs_2fa' => false,
                    'logged_in' => true,
                ]);
                return true;
            } catch (\Throwable $e) {
                write_qr_state($stateFile, [
                    'ok'        => true,
                    'status'    => 'needs_2fa',
                    'message'   => 'تکمیل ورود انجام نشد؛ رمز را بررسی و دوباره وارد کنید.',
                    'svg'       => null,
                    'needs_2fa' => true,
                    'logged_in' => false,
                ]);
            }
        }
        Amp\delay(1);
    }

    write_qr_state($stateFile, [
        'ok'        => false,
        'status'    => 'error',
        'message'   => 'زمان وارد کردن رمز 2FA تمام شد. دوباره QR Login را بزن.',
        'svg'       => null,
        'needs_2fa' => false,
        'logged_in' => false,
    ]);
    return false;
}

try {
    @unlink($twofaFile);

    write_qr_state($stateFile, [
        'ok'        => true,
        'status'    => 'starting',
        'message'   => 'در حال آماده‌سازی اتصال به تلگرام...',
        'svg'       => null,
        'needs_2fa' => false,
        'logged_in' => false,
    ]);

    $api = make_api($key);

    $qrDeadline = time() + 600;
    while (time() < $qrDeadline) {
        $qr = $api->qrLogin();

        if (!$qr) {
            // Either already authorized, or Telegram now wants the 2FA password.
            if ($api->getAuthorization() === API::WAITING_PASSWORD) {
                wait_for_2fa_and_complete($api, $twofaFile, $stateFile);
                exit(0);
            }

            write_qr_state($stateFile, [
                'ok'        => true,
                'status'    => 'logged_in',
                'message'   => 'ورود کامل شد.',
                'svg'       => null,
                'needs_2fa' => false,
                'logged_in' => true,
            ]);
            exit(0);
        }

        write_qr_state($stateFile, [
            'ok'        => true,
            'status'    => 'qr',
            'message'   => 'QR آماده است. با Telegram موبایل اسکن کن.',
            'svg'       => $qr->getQRSvg(320, 2),
            'needs_2fa' => false,
            'logged_in' => false,
        ]);

        try {
            $next = $qr->waitForLoginOrQrCodeExpiration(new TimeoutCancellation(25.0));
            if ($next) {
                continue; // expired; loop builds a fresh QR
            }
        } catch (CancelledException) {
            // periodic timeout; re-check authorization below
        }

        if ($api->getAuthorization() === API::WAITING_PASSWORD) {
            wait_for_2fa_and_complete($api, $twofaFile, $stateFile);
            exit(0);
        }
    }
    throw new RuntimeException('QR login timed out');
} catch (\Throwable $e) {
    write_qr_state($stateFile, [
        'ok'        => false,
        'status'    => 'error',
        'message'   => 'ورود انجام نشد؛ تنظیمات API و اتصال شبکه را بررسی و دوباره تلاش کنید.',
        'svg'       => null,
        'needs_2fa' => false,
        'logged_in' => false,
    ]);
    fwrite(STDERR, 'QR failure: ' . get_class($e) . "\n");
    exit(1);
}
