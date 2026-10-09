<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

class PaymentInstrument
{
    /**
     * @param array<string, mixed> $credential
     */
    public function __construct(
        public readonly ?string $handlerId = null,
        public readonly ?string $type = null,
        public readonly array $credential = [],
    ) {
    }
}
