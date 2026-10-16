<?php
declare(strict_types=1);

namespace Ubcc\V2\Domain\Lifecycle;

/**
 * Pure lifecycle state machine. Adapter code owns locks, processes, deadlines,
 * durable compare-and-swap, session isolation and external side effects.
 */
final class Machine
{
    private const MAX_ATTEMPTS = 3;

    public static function request(Snapshot $state, Intent $intent): Snapshot
    {
        if ($intent === Intent::Off) {
            return self::stop($state);
        }
        if ($state->intent === $intent
            && in_array($state->phase, [Phase::Queued, Phase::Starting, Phase::Running, Phase::Retrying], true)) {
            return $state;
        }
        // Incrementing generation fences readiness/heartbeat from previous runs.
        return new Snapshot($intent, Phase::Queued, $state->version + 1,
            $state->generation + 1, 0, null, null, null, null);
    }

    public static function stop(Snapshot $state): Snapshot
    {
        if ($state->intent === Intent::Off && $state->phase === Phase::Stopped) {
            return $state;
        }
        return new Snapshot(Intent::Off,
            $state->runId === null ? Phase::Stopped : Phase::Stopping,
            $state->version + 1, $state->generation + 1, 0, null, null, null, null);
    }

    public static function stopped(Snapshot $state): Snapshot
    {
        if ($state->intent !== Intent::Off || $state->phase === Phase::Stopped) {
            return $state;
        }
        return new Snapshot(Intent::Off, Phase::Stopped, $state->version + 1,
            $state->generation, 0, null, null, null, null);
    }

    public static function beginAttempt(Snapshot $state, string $runId, int $now): Snapshot
    {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $runId)) {
            throw new \InvalidArgumentException('Invalid opaque run identifier');
        }
        $retryable = $state->phase === Phase::Retrying
            || ($state->phase === Phase::Failed && $state->retryAt !== null && $state->retryAt <= $now);
        if ($state->intent === Intent::Off
            || (!$retryable && $state->phase !== Phase::Queued)
            || $state->attempts >= self::MAX_ATTEMPTS) {
            throw new \DomainException('Attempt not permitted by lifecycle state');
        }
        return new Snapshot($state->intent, Phase::Starting, $state->version + 1,
            $state->generation, $state->attempts + 1, $runId, null, null, null);
    }

    public static function ready(Snapshot $state, int $generation, string $runId, int $now): Snapshot
    {
        if (!self::owns($state, $generation, $runId) || $state->phase !== Phase::Starting) {
            return $state;
        }
        return new Snapshot($state->intent, Phase::Running, $state->version + 1,
            $state->generation, $state->attempts, $state->runId, $now, null, null);
    }

    public static function heartbeat(Snapshot $state, int $generation, string $runId, int $now): Snapshot
    {
        if (!self::owns($state, $generation, $runId) || $state->phase !== Phase::Running
            || ($state->heartbeatAt !== null && $now <= $state->heartbeatAt)) {
            return $state;
        }
        return new Snapshot($state->intent, Phase::Running, $state->version + 1,
            $state->generation, $state->attempts, $state->runId, $now, null, null);
    }

    public static function failed(
        Snapshot $state,
        int $generation,
        string $runId,
        FailureCode $reason,
        int $now,
    ): Snapshot {
        if (!self::owns($state, $generation, $runId)
            || !in_array($state->phase, [Phase::Starting, Phase::Running], true)) {
            return $state;
        }
        $retryAt = $reason->canRetry() && $state->attempts < self::MAX_ATTEMPTS
            ? $now + (10 * (2 ** ($state->attempts - 1)))
            : null;
        return new Snapshot($state->intent, Phase::Failed, $state->version + 1,
            $state->generation, $state->attempts, null, null, $retryAt, $reason);
    }

    public static function due(Snapshot $state, int $now): Snapshot
    {
        if ($state->intent === Intent::Off || $state->phase !== Phase::Failed
            || $state->retryAt === null || $state->retryAt > $now) {
            return $state;
        }
        return new Snapshot($state->intent, Phase::Retrying, $state->version + 1,
            $state->generation, $state->attempts, null, null, null, $state->failure);
    }

    private static function owns(Snapshot $state, int $generation, string $runId): bool
    {
        return $state->intent !== Intent::Off && $state->generation === $generation
            && $state->runId !== null && $state->runId === $runId;
    }
}
