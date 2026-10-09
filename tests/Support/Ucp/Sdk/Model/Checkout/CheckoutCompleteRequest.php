<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

class CheckoutCompleteRequest
{
    /**
     * @param list<PaymentInstrument> $instruments
     */
    public function __construct(
        public readonly string $id,
        public readonly array $instruments = [],
    ) {
    }
}
