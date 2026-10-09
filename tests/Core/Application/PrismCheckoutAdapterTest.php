<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The complete path of the checkout decorator after SW-1: the order is placed before any money
 * moves, the payment is bound to the quote by identity (not amount), and a mismatch or a rejected
 * order settles nothing.
 */
final class PrismCheckoutAdapterTest extends CheckoutAdapterTestCase
{
    /** The cart moved after signing: refused before an order is ever placed, so the session survives. */
    public function testCartNoLongerMatchingQuoteIsRefusedBeforeOrderIsPlaced(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));

        $inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $inner->method('getCheckout')->willReturn($this->checkout(149.00)); // quote was 12.50
        $inner->expects(self::never())->method('completeCheckout');
        $this->inner = $inner;

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::never())->method('fail');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cart changed after this payment was authorized');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** Rule C: a credential outside the stored offer is refused before the order is ever placed. */
    public function testCredentialNotInStoredOfferIsRefusedBeforeOrderIsPlaced(): void
    {
        $foreign = self::ACCEPT;
        $foreign['amount'] = '1';
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential($foreign)));

        $this->inner->expects(self::never())->method('completeCheckout');
        $this->gateway->expects(self::never())->method('settle');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match any offer');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** D1: a base rejection throws before the order exists, so nothing is ever settled. */
    public function testRejectedOrderSettlesNothing(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')
            ->willThrowException(new ValidationException('$.checkout_session.buyer.email is required'));

        $this->gateway->expects(self::never())->method('settle');
        $this->store->expects(self::never())->method('claim');
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('buyer.email');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /**
     * The backstop: the cart agreed with the quote, but Shopware's recalculation produced a different
     * order total. Caught after placement — transaction failed, nothing settled.
     */
    public function testOrderTotalDifferentFromQuoteAbandonsWithoutSettling(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')->willReturn($this->completed(149.00)); // cart said 12.50

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::once())->method('fail')->with(self::TRANSACTION);
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match the amount');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** Happy path: order placed (open) → process → settle → paid. */
    public function testOrderPlacedThenSettledThenPaid(): void
    {
        $this->transactionState = 'open';
        $this->settledRowAfterClaim();
        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->store->method('claim')->willReturn(true);

        $this->transactions->expects(self::once())->method('process')->with(self::TRANSACTION);
        $this->gateway->expects(self::once())->method('settle')->willReturn($this->settleResult());
        $this->transactions->expects(self::once())->method('paid')->with(self::TRANSACTION);
        $this->store->expects(self::once())->method('linkOrder')->with(self::SESSION, self::ORDER);

        $checkout = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(self::ORDER, $checkout->order?->id);
    }

    /** A settle failure fails the transaction and never marks it paid. */
    public function testSettleFailureAbandonsTheTransaction(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->store->method('claim')->willReturn(true);
        $this->gateway->method('settle')->willReturn(new SettleResult(false, '', '', null, 'declined'));

        $this->transactions->expects(self::once())->method('fail')->with(self::TRANSACTION);
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** An offer-only record (agent never chose Prism) defers to the base and settles nothing. */
    public function testOfferOnlyRecordDefersToBase(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, null));
        $this->inner->expects(self::once())->method('completeCheckout')->willReturn($this->completed(12.50));

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::never())->method('paid');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** After a won claim, a reload of the row must read back as settled (the adapter asserts this). */
    private function settledRowAfterClaim(): void
    {
        $settled = $this->record(SettlementStatus::SETTLED, $this->credential(self::ACCEPT), transactionHash: '0xabc');
        $pending = $this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT));
        $loads = [$pending, $settled];
        $this->store->method('load')->willReturnCallback(static function () use (&$loads, $settled) {
            return array_shift($loads) ?? $settled;
        });
    }
}
