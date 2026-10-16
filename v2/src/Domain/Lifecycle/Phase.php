<?php
declare(strict_types=1);

namespace Ubcc\V2\Domain\Lifecycle;

enum Phase: string
{
    case Stopped = 'stopped';
    case Queued = 'queued';
    case Starting = 'starting';
    case Running = 'running';
    case Stopping = 'stopping';
    case Failed = 'failed';
    case Retrying = 'retrying';
}
