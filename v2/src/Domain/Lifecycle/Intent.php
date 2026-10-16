<?php
declare(strict_types=1);

namespace Ubcc\V2\Domain\Lifecycle;

enum Intent: string
{
    case Off = 'off';
    case Bot = 'bot';
    case Qr = 'qr';
}
