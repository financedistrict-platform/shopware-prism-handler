<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
