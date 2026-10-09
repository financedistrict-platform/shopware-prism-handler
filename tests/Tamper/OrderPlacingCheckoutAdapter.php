<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

final class OrderPlacingCheckoutAdapter implements CheckoutAdapterInterface
{
    public string $cartTotal = '10.00';

    public string $cartCurrency = 'EUR';

    public ?string $cartTotalOnUpdate = null;

    public bool $cartHasTotal = true;

    public int $failingPlacements = 0;

    public array $foreignSessions = [];

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
        $this->authorize($id);

        return new Checkout(id: $id, currency: $this->cartCurrency, totals: $this->totals());
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        $this->authorize($request->id);

        if (null !== $this->cartTotalOnUpdate) {
            $this->cartTotal = $this->cartTotalOnUpdate;
        }

        return new Checkout(id: $request->id, currency: $this->cartCurrency);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        $this->authorize($id);

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
        $this->authorize($id);

        return new Checkout(id: $id);
    }

    public function totals(): array
    {
        return $this->cartHasTotal
            ? [new Money('subtotal', (float) $this->cartTotal), new Money('total', (float) $this->cartTotal)]
            : [new Money('subtotal', (float) $this->cartTotal)];
    }

    private function authorize(string $id): void
    {
        if (\in_array($id, $this->foreignSessions, true)) {
            throw new \RuntimeException('Checkout session not found.');
        }
    }
}
