<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class CheckoutUpdateRequest
{
    public function __construct(
        public readonly string $id,
        public readonly array $lineItems,
        public readonly mixed $buyer = null,
        public readonly array $discounts = [],
        public readonly mixed $fulfillment = null,
        public readonly mixed $consent = null,
        public readonly ?PaymentInstrument $payment = null,
    ) {
    }
}
