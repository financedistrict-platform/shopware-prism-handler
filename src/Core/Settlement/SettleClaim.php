<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Settlement;

/**
 * @internal
 */
enum SettleClaim
{
    case None;
    case Fresh;
    case Resumed;
}
