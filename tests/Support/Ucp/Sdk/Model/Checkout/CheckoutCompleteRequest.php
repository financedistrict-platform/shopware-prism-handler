<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class CheckoutCompleteRequest
{
    public function __construct(
        public readonly string $id,
        public readonly array $instruments = [],
    ) {
    }
}
