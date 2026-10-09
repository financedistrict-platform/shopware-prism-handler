<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Infrastructure\Clock;

use Fd\PrismPayment\Core\Port\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
