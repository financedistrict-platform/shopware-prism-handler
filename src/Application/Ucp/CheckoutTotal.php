<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Money;

final class CheckoutTotal
{
    public static function of(Checkout $checkout): ?float
    {
        foreach ($checkout->totals as $money) {
            if ($money instanceof Money && 'total' === $money->type) {
                return $money->amount;
            }
        }

        return null;
    }
}
