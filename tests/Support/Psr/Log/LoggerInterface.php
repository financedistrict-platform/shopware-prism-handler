<?php

declare(strict_types=1);

namespace Psr\Log;

interface LoggerInterface
{
    public function warning(string|\Stringable $message, array $context = []): void;
}
