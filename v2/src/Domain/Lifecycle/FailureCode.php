<?php
declare(strict_types=1);

namespace Ubcc\V2\Domain\Lifecycle;

/** Fixed, safe-to-display failure categories; never persist exception messages or secrets. */
enum FailureCode: string
{
    case ProcessExit = 'process_exit';
    case StartupException = 'startup_exception';
    case HeartbeatTimeout = 'heartbeat_timeout';
    case LockContention = 'lock_contention';
    case IpcFailure = 'ipc_failure';
    case SpawnFailure = 'spawn_failure';
    case MissingSession = 'session_missing';
    case StorageUnavailable = 'storage_unavailable';

    public function canRetry(): bool
    {
        return match ($this) {
            self::ProcessExit, self::StartupException, self::HeartbeatTimeout,
            self::LockContention, self::IpcFailure => true,
            default => false,
        };
    }
}
