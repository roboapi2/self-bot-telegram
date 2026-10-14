<?php
declare(strict_types=1);
// Mounted over bot_template.php only by disposable CI Compose projects.
// Real worker.php, locks, spawning, supervisor, API and readiness state remain.
$key = (string) getenv('UBCC_ACCOUNT_KEY');
$dir = (string) getenv('UBCC_ACCOUNT_DIR');
if (!valid_account_key($key) || $dir !== account_dir($key)) throw new RuntimeException('Fixture isolation failed');
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($dir): void {
    with_file_lock($dir . '/data.lock', static function () use ($dir): void { json_write_atomic($dir . '/fixture-flushed.json', ['flushed' => true]); });
    exit(0);
});
pcntl_signal(SIGINT, static function (): void { exit(0); });
worker_stage('synthetic_session');
$mode = read_json_file($dir . '/fixture-mode.json', [])['mode'] ?? 'ready';
if ($mode === 'fail') throw new RuntimeException('FIXTURE_PRIVATE_MESSAGE token=FIXTURE_SECRET');
if ($mode === 'early_exit') exit(42);
if ($mode === 'delay') sleep(4);
if ($mode === 'permission') { file_put_contents('/opt/app/readonly-fixture', 'fixture'); throw new RuntimeException('Readonly violation'); }
if ($mode === 'ipc_failure') { worker_stage('ipc_initialization'); throw new RuntimeException('Synthetic IPC startup failure'); }
if ($mode === 'restoration_failure') { worker_stage('session_restoration'); throw new RuntimeException('Synthetic session restoration failure'); }
if (file_get_contents(account_paths($key)['session']) !== 'SYNTHETIC_AUTHORIZED_SESSION') throw new RuntimeException('Synthetic session restoration failed');
ubcc_event_handler_ready();
if ($mode === 'crash_once' && is_file($dir . '/crash-once')) {
    unlink($dir . '/crash-once'); posix_kill(getmypid(), SIGKILL);
}
while (true) {
    worker_heartbeat();
    // Synthetic shared config/message persistence; never sends a Telegram message.
    $config = read_json_file($dir . '/fixture-config.json', []);
    if (!json_write_atomic($dir . '/fixture-observed.json', $config)) throw new RuntimeException('Fixture state not writable');
    sleep(1);
}
