<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

final class OrderPlacingCheckoutAdapter implements CheckoutAdapterInterface
{
    public string $cartTotal = '10.00';

    public string $cartCurrency = 'EUR';

    public ?string $cartTotalOnUpdate = null;

    public int $failingPlacements = 0;

    private int $orders = 0;

    public function __construct(
        private readonly ShopwareOrderTables $tables,
    ) {
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        return new Checkout(id: 'created');
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        return new Checkout(id: $id, currency: $this->cartCurrency);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        if (null !== $this->cartTotalOnUpdate) {
            $this->cartTotal = $this->cartTotalOnUpdate;
        }

        return new Checkout(id: $request->id, currency: $this->cartCurrency);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        if ($this->failingPlacements > 0) {
            --$this->failingPlacements;

            throw new \RuntimeException('Order placement failed.');
        }

        $orderId = sprintf('%032x', ++$this->orders);
        $this->tables->placeOrder($orderId, $this->cartTotal, $this->cartCurrency);

        return new Checkout(id: $id, currency: $this->cartCurrency, order: (object) ['id' => $orderId]);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        return new Checkout(id: $id);
    }
}
