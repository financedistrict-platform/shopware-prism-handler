<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/** What each Prism answer, or none, does to the checkout, the row and the order transaction. */
final class PrismCheckoutAdapterSettleRecoveryTest extends SettleRecoveryTestCase
{
    public function testTransportFailureHoldsTheOrderAndRetryReusesTheSameCredential(): void
    {
        $this->prism->script = [new PrismApiException('Prism POST /api/v2/payment/settle transport error: timeout'), $this->settleResult()];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertNull($held->order);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);

        $this->expireClaim();
        $done = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::Completed, $done->status);
        self::assertSame(SettlementStatus::SETTLED, $this->rowStatus());
        self::assertSame(1, $this->paidCalls);
        self::assertSame(0, $this->failCalls);
        self::assertCount(2, $this->prism->settled);
        self::assertSame($this->prism->settled[0], $this->prism->settled[1]);
    }

    public function testRetryInsideTheClaimWindowDoesNotCallPrismAgain(): void
    {
        $this->prism->script = [new PrismApiException('Prism POST /api/v2/payment/settle transport error: timeout')];

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $again = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $again->status);
        self::assertCount(1, $this->prism->settled);
        self::assertSame(0, $this->failCalls);
    }

    public static function unknownOutcomeStatuses(): iterable
    {
        yield 'no response' => [0];
        yield 'request timeout' => [408];
        yield 'too many requests' => [429];
        yield 'server error' => [500];
        yield 'bad gateway' => [502];
        yield 'gateway timeout' => [504];
    }

    #[DataProvider('unknownOutcomeStatuses')]
    public function testUnknownOutcomeStatusesAreHeldNotFailed(int $httpStatus): void
    {
        $this->prism->script = [new PrismApiException('Prism POST /api/v2/payment/settle failed', $httpStatus)];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
    }

    public function testClientErrorFromPrismIsADefiniteRejection(): void
    {
        $this->prism->script = [new PrismApiException('Prism POST /api/v2/payment/settle failed: HTTP 400', 400)];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
            self::fail('A refused settle must fail the checkout.');
        } catch (ValidationException) {
            self::assertSame(SettlementStatus::FAILED, $this->rowStatus());
            self::assertSame(1, $this->failCalls);
        }
    }

    public static function pendingReasons(): iterable
    {
        yield 'pending' => ['settlement_pending'];
        yield 'in progress' => ['checkout_settlement_in_progress'];
    }

    #[DataProvider('pendingReasons')]
    public function testStillRunningAnswersHoldTheOrder(string $reason): void
    {
        $this->prism->script = [new SettleResult(false, '', '', null, $reason)];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
    }

    public static function finalReasons(): iterable
    {
        yield 'insufficient funds' => ['insufficient_funds'];
        yield 'quote not found' => ['quote_not_found'];
        yield 'quote mismatch' => ['quote_mismatch'];
        yield 'credential used elsewhere' => ['credential_already_used'];
        yield 'before the facilitator' => ['unexpected_settle_error'];
        yield 'reverted' => ['transaction_reverted'];
        yield 'unlisted reason' => ['some_reason_from_a_newer_gateway'];
        yield 'no reason' => [null];
    }

    #[DataProvider('finalReasons')]
    public function testEveryOtherAnswerDeclinesTheOrder(?string $reason): void
    {
        $this->prism->script = [new SettleResult(false, '', '', null, $reason)];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
            self::fail('A final answer from Prism must fail the checkout.');
        } catch (ValidationException) {
            self::assertSame(SettlementStatus::FAILED, $this->rowStatus());
            self::assertSame(1, $this->failCalls);
        }
    }

    public static function resumedFailures(): iterable
    {
        yield 'decline reason' => [new SettleResult(false, '', '', null, 'insufficient_funds')];
        yield 'quote mismatch' => [new SettleResult(false, '', '', null, 'quote_mismatch')];
        yield 'pending again' => [new SettleResult(false, '', '', null, 'settlement_pending')];
        yield 'in progress' => [new SettleResult(false, '', '', null, 'checkout_settlement_in_progress')];
        yield 'client error' => [new PrismApiException('Prism POST /api/v2/payment/settle failed: HTTP 400', 400)];
    }

    /** A retry that fails says nothing about the earlier attempt, so the order stays held. */
    #[DataProvider('resumedFailures')]
    public function testResumedRetryThatIsNotSettledKeepsTheOrderHeld(SettleResult|\Throwable $second): void
    {
        $this->prism->script = [new PrismApiException('timeout'), $second];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(['settling', null, json_encode($this->credential())], $this->row());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
    }

    public function testResumedRetryWithoutAnAnswerStaysHeldForTheSameCredential(): void
    {
        $this->prism->script = [new PrismApiException('timeout'), new PrismApiException('timeout again')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
    }

    public function testRetryWithTheSameCredentialInTheRequestResumesTheSettle(): void
    {
        $this->prism->script = [new PrismApiException('timeout'), $this->settleResult()];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $done = $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument($this->credential())]),
            new RequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $done->status);
        self::assertSame(SettlementStatus::SETTLED, $this->rowStatus());
        self::assertSame(1, $this->paidCalls);
    }

    public function testHeldCheckoutIsReportedAsInProgressWhenRead(): void
    {
        $this->prism->script = [new PrismApiException('timeout')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        $this->inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $this->inner->method('getCheckout')->willReturn($this->completed(12.50));

        $read = $this->adapter()->getCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $read->status);
        self::assertNull($read->order);
    }

    public function testCancelIsRefusedWhileSettleOutcomeIsUnknown(): void
    {
        $this->prism->script = [new PrismApiException('timeout')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        $this->inner->expects(self::never())->method('cancelCheckout');
        $this->expectException(ValidationException::class);

        $this->adapter()->cancelCheckout(self::SESSION, new RequestContext());
    }
}
