<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/** Which authorization is sent to Prism, which one the row keeps, and which one is refused. */
final class PrismCheckoutAdapterAuthorizationTest extends SettleRecoveryTestCase
{
    /**
     * The first settle landed but its answer was lost. A new authorization is forwarded to learn
     * that, Prism refuses it as already settled, and the order is paid by the first one's tx. The
     * new authorization is never stored.
     */
    public function testNewAuthorizationOnAHeldCheckoutPaysTheOrderWhenPrismReportsItSettled(): void
    {
        $this->prism->script = [new PrismApiException('timeout'), $this->alreadySettled('0xfirst')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $done = $this->complete($this->otherCredential());

        self::assertSame(CheckoutStatus::Completed, $done->status);
        self::assertSame($this->otherCredential(), $this->prism->settled[1]);
        self::assertSame(['settled', '0xfirst', null], $this->row());
        self::assertSame(1, $this->paidCalls);
    }

    public function testNewAuthorizationOnAHeldCheckoutIsNotStoredWhenPrismRefusesIt(): void
    {
        $this->prism->script = [new PrismApiException('timeout'), new SettleResult(false, '', '', null, 'credential_already_used')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->complete($this->otherCredential());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(['settling', null, json_encode($this->credential())], $this->row());
        self::assertSame(0, $this->failCalls);
    }

    /** Prism released the first attempt (nothing moved), so the new authorization settles and is kept. */
    public function testNewAuthorizationOnAHeldCheckoutIsKeptWhenItIsTheOneThatSettles(): void
    {
        $this->prism->script = [new PrismApiException('timeout'), $this->settleResult()];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $this->complete($this->otherCredential());

        self::assertSame(['settled', '0xabc', json_encode($this->otherCredential())], $this->row());
    }

    /** A decline fails the order's transaction; the authorization that later settles pays it. */
    public function testNewAuthorizationAfterADeclinePaysTheFailedTransaction(): void
    {
        $this->prism->script = [new SettleResult(false, '', '', null, 'insufficient_funds'), $this->settleResult()];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        } catch (ValidationException) {
        }
        self::assertSame('failed', $this->transactionState);

        // The new authorization as capture() stores it (its upsert is MySQL-only, so seeded here).
        $this->pdo->prepare("UPDATE fd_prism_payment_settlement SET status = 'pending', credential = :c")
            ->execute(['c' => json_encode($this->otherCredential())]);

        $done = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::Completed, $done->status);
        self::assertSame(['settled', '0xabc', json_encode($this->otherCredential())], $this->row());
        self::assertSame('paid', $this->transactionState);
    }

    /** I5: `resource.url` is not signed, so a credential pointing at another checkout is refused. */
    public function testCredentialNamingAnotherResourceIsRefusedBeforeTheOrder(): void
    {
        $this->inner->expects(self::never())->method('completeCheckout');

        $credential = $this->credential();
        $credential['paymentPayload']['resource']['url'] = 'https://shop.example/ucp/v1/checkout-sessions/cs_2';

        try {
            $this->complete($credential);
            self::fail('A credential for another resource must be refused.');
        } catch (ValidationException) {
            self::assertSame([], $this->prism->settled);
        }
    }

    /** @param array<string, mixed> $credential */
    private function complete(array $credential): Checkout
    {
        return $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument($credential)]),
            new RequestContext(),
        );
    }
}
