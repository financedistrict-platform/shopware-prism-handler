<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Psr\Log\LoggerInterface;

final class SilentLogger implements LoggerInterface
{
    public function warning(string|\Stringable $message, array $context = []): void
    {
    }
}
