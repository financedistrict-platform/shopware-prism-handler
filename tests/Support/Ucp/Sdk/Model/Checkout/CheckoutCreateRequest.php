<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class CheckoutCreateRequest
{
    public function __construct(
        public readonly array $lineItems,
        public readonly mixed $buyer = null,
        public readonly ?PaymentInstrument $payment = null,
    ) {
    }
}
