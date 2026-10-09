<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

class CheckoutUpdateRequest
{
    public function __construct(
        public readonly string $id,
        public readonly ?PaymentInstrument $payment = null,
    ) {
    }
}
