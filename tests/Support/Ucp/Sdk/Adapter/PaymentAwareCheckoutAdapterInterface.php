<?php

declare(strict_types=1);

namespace Ucp\Sdk\Adapter;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

interface PaymentAwareCheckoutAdapterInterface extends CheckoutAdapterInterface
{
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout;
}
