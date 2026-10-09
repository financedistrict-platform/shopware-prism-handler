<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction;

final class OrderTransactionStates
{
    public const STATE_OPEN = 'open';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_AUTHORIZED = 'authorized';

    public const STATE_UNCONFIRMED = 'unconfirmed';

    public const STATE_REMINDED = 'reminded';

    public const STATE_PAID = 'paid';
}
