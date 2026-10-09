<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Application\Ucp;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Money;

/**
 * The checkout total as the fiat string Prism is quoted with. The offer is recorded in this form
 * and the order is checked against it in the same form, so the two can never disagree on rounding.
 *
 * @internal
 */
final class CheckoutTotal
{
    public static function fiat(Checkout $checkout): ?string
    {
        foreach ($checkout->totals as $money) {
            if ($money instanceof Money && 'total' === $money->type) {
                return number_format($money->amount, 2, '.', '');
            }
        }

        return null;
    }
}
