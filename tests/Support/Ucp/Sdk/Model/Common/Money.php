<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

class Money
{
    public function __construct(
        public readonly string $type,
        public readonly float $amount,
    ) {
    }
}
