<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

final class RecordingTransactionStateHandler extends OrderTransactionStateHandler
{
    /** @var list<string> */
    public array $paid = [];

    public function paid(string $transactionId, Context $context): void
    {
        $this->paid[] = $transactionId;
    }
}
