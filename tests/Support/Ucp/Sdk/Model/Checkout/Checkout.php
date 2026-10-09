<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

class Checkout
{
    public function __construct(
        public readonly string $id,
        public readonly mixed $status = null,
        public readonly string $currency = 'EUR',
        public readonly array $lineItems = [],
        public readonly array $totals = [],
        public readonly array $messages = [],
        public readonly array $links = [],
        public readonly mixed $buyer = null,
        public readonly ?string $continueUrl = null,
        public readonly mixed $expiresAt = null,
        public readonly ?object $order = null,
        public readonly array $extra = [],
    ) {
    }
}
