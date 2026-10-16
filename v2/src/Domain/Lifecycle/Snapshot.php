<?php
declare(strict_types=1);

namespace Ubcc\V2\Domain\Lifecycle;

/**
 * Immutable state for one account. Persistence must use optimistic version checks
 * and transactional fencing; no process or Telegram calls belong in this model.
 */
final readonly class Snapshot
{
    public function __construct(
        public Intent $intent,
        public Phase $phase,
        public int $version,
        public int $generation,
        public int $attempts,
        public ?string $runId,
        public ?int $heartbeatAt,
        public ?int $retryAt,
        public ?FailureCode $failure,
    ) {
        if ($version < 0 || $generation < 0 || $attempts < 0) {
            throw new \InvalidArgumentException('Lifecycle counters must be non-negative');
        }
        if ($intent === Intent::Off && $phase === Phase::Running) {
            throw new \InvalidArgumentException('A stopped intent cannot be running');
        }
    }

    public static function initial(): self
    {
        return new self(Intent::Off, Phase::Stopped, 0, 0, 0, null, null, null, null);
    }
}
