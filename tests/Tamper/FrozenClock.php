<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Core\Port\Clock;

final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-10-09T10:00:00+00:00');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
