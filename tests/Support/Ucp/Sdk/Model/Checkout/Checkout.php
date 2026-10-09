<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Checkout;

use Ucp\Sdk\Enum\CheckoutStatus;

final class Checkout
{
    public function __construct(
        public readonly string $id,
        public readonly CheckoutStatus $status,
        public readonly string $currency,
        public readonly array $lineItems,
        public readonly array $totals,
        public readonly array $messages = [],
        public readonly array $links = [],
        public readonly mixed $buyer = null,
        public readonly ?string $continueUrl = null,
        public readonly ?string $expiresAt = null,
        public readonly ?OrderConfirmation $order = null,
        public readonly array $extra = [],
    ) {
    }
}
