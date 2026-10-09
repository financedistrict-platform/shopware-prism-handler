<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * The write-ordering contract on the two entry points that carry a payment instrument: nothing is
 * persisted until the base adapter has accepted the request.
 *
 * Shopware owns whether a cart may change or a session may complete, so a request it refuses must
 * leave our settlement row exactly as it found it. Writing first made a refusal destructive: a
 * caller holding only the session id could clear a credential the real agent had signed, or plant
 * one on a session the base had already closed.
 */
final class PrismCheckoutAdapterWriteOrderTest extends CheckoutAdapterTestCase
{
    /**
     * The damaging case (lab run 012): a stranger holding only the session id sends an update the
     * base refuses. The owner's signed credential must survive it.
     */
    public function testRefusedUpdateDoesNotReleaseAStoredCredential(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential()));
        $this->inner->method('updateCheckout')->willThrowException(
            new ValidationException('The product is not purchasable in this sales channel.'),
        );

        $this->store->expects(self::never())->method('releaseToBase');
        $this->store->expects(self::never())->method('capture');

        $this->expectException(ValidationException::class);

        $this->adapter()->updateCheckout($this->update($this->instrument('com.shopware.invoice')), new RequestContext());
    }

    /**
     * The planting case (lab run 011): the base refuses the update, so no credential of the
     * caller's choosing may be left behind on the session.
     */
    public function testRefusedUpdateDoesNotCaptureACredential(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, null));
        $this->inner->method('updateCheckout')->willThrowException(
            new ValidationException('Completed checkout sessions cannot be updated.'),
        );

        $this->store->expects(self::never())->method('capture');

        $this->expectException(ValidationException::class);

        $this->adapter()->updateCheckout($this->update($this->instrument(HandlerId::PRISM)), new RequestContext());
    }

    /** An accepted update still captures — the ordering changed, not the behaviour. */
    public function testAcceptedUpdateCapturesTheCredential(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, null));
        $this->inner->method('updateCheckout')->willReturn($this->checkout(12.50));

        $this->store->expects(self::once())->method('capture')->with(self::SESSION, $this->credential());

        $this->adapter()->updateCheckout($this->update($this->instrument(HandlerId::PRISM)), new RequestContext());
    }

    /** An accepted update naming another handler still releases the session to the base. */
    public function testAcceptedUpdateWithAnotherHandlerReleasesToBase(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential()));
        $this->inner->method('updateCheckout')->willReturn($this->checkout(12.50));

        $this->store->expects(self::once())->method('releaseToBase')->with(self::SESSION);

        $this->adapter()->updateCheckout($this->update($this->instrument('com.shopware.invoice')), new RequestContext());
    }

    /** A completion the base refuses leaves no credential behind, so nothing can be settled later. */
    public function testRefusedCompletionDoesNotCaptureACredential(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, null));
        $this->inner->method('completeCheckout')->willThrowException(
            new ValidationException('$.checkout_session.buyer.email is required'),
        );

        $this->store->expects(self::never())->method('capture');
        $this->gateway->expects(self::never())->method('settle');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('buyer.email');

        $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument(HandlerId::PRISM)]),
            new RequestContext(),
        );
    }

    /**
     * A completion the base refuses must not clear a stored credential either — the release branch
     * waits on the base in the same way the capture branch does.
     */
    public function testRefusedCompletionDoesNotReleaseAStoredCredential(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential()));
        $this->inner->method('completeCheckoutFromRequest')->willThrowException(
            new ValidationException('$.checkout_session.buyer.email is required'),
        );

        $this->store->expects(self::never())->method('releaseToBase');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('buyer.email');

        $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument('com.shopware.invoice')]),
            new RequestContext(),
        );
    }

    /**
     * The happy path still stores the credential, and only once the order exists — the credential
     * arrives in memory, survives the F2 and quote checks, and is written after the base returns.
     */
    public function testAcceptedCompletionCapturesAfterTheOrderExists(): void
    {
        $order = $this->completed(12.50);
        $calls = [];

        $this->inner->method('completeCheckout')->willReturnCallback(
            static function () use (&$calls, $order): Checkout {
                $calls[] = 'order';

                return $order;
            },
        );
        $this->store->method('capture')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'capture';
        });
        $this->store->method('claim')->willReturn(true);
        // loaded by the state guard, then by complete(), then re-read after the settle lands
        $this->store->method('load')->willReturnOnConsecutiveCalls(
            $this->record(SettlementStatus::PENDING, null),
            $this->record(SettlementStatus::PENDING, null),
            $this->record(SettlementStatus::SETTLED, $this->credential(), '0xabc'),
        );
        $this->gateway->method('settle')->willReturn(new SettleResult(true, '0xabc', 'eip155:84532', null, null));

        $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument(HandlerId::PRISM)]),
            new RequestContext(),
        );

        self::assertSame(['order', 'capture'], $calls);
    }

    /**
     * The store refuses to overwrite a row that is mid-settle, so the in-memory view must refuse
     * too. Only a race reaches this: the state guard reads the row before a concurrent complete
     * claims it, and `complete()` reads it after. Folding the credential in anyway would present a
     * `settling` row as `pending` — the double-settle shape the claim exists to prevent.
     */
    public function testCredentialIsNotFoldedIntoARowThatWentToSettlingMeanwhile(): void
    {
        $this->store->method('load')->willReturnOnConsecutiveCalls(
            $this->record(SettlementStatus::PENDING, null),            // the state guard
            $this->record(SettlementStatus::SETTLING, $this->credential()), // claimed in between
            $this->record(SettlementStatus::SETTLING, $this->credential()),
        );
        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->store->method('claim')->willReturn(false);

        $this->store->expects(self::never())->method('capture');
        $this->gateway->expects(self::never())->method('settle');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument(HandlerId::PRISM)]),
            new RequestContext(),
        );
    }

    private function update(PaymentInstrument $payment): CheckoutUpdateRequest
    {
        return new CheckoutUpdateRequest(id: self::SESSION, lineItems: [], payment: $payment);
    }

    private function instrument(string $handlerId): PaymentInstrument
    {
        return new PaymentInstrument(
            type: 'x402',
            handlerId: $handlerId,
            credential: $this->credential(),
        );
    }
}
