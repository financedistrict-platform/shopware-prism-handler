<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction;

use Shopware\Core\Framework\Context;

class OrderTransactionStateHandler
{
    public function process(string $transactionId, Context $context): void
    {
    }

    public function paid(string $transactionId, Context $context): void
    {
    }

    public function fail(string $transactionId, Context $context): void
    {
    }
}
