<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Psr\Log\LoggerInterface;

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<mixed>}> */
    public array $errors = [];

    public function warning(string|\Stringable $message, array $context = []): void
    {
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->errors[] = ['message' => (string) $message, 'context' => $context];
    }
}
