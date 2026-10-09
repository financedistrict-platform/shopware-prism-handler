<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

final class OrderConfirmation
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
