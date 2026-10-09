<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

final class Money
{
    public function __construct(
        public readonly string $type,
        public readonly float $amount,
        public readonly ?string $displayText = null,
        public readonly string $currency = 'EUR',
    ) {
    }
}
