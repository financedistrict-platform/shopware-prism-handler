<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use Fd\PrismPayment\Infrastructure\Persistence\DbalCredentialStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelResolution;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

final class PrismCheckoutAdapterSettleRecoveryTest extends TestCase
{
    private const SESSION = 'cs_1';
    private const ORDER = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const TRANSACTION = 'tx-1';
    private const RESOURCE = 'https://shop.example/ucp/v1/checkout-sessions/cs_1';

    private const ACCEPT = [
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'asset' => '0x036cbd53842c5426634e7929541ec2318f3dcf7e',
        'amount' => '100142',
        'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
    ];

    private \PDO $pdo;
    private DbalCredentialStore $store;
    private PaymentAwareCheckoutAdapterInterface&MockObject $inner;
    private OrderTransactionStateHandler&MockObject $transactions;
    private object $gateway;
    private int $paidCalls = 0;
    private int $failCalls = 0;
    private string $transactionState = 'open';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('UNHEX', static fn (string $hex): string => (string) hex2bin($hex), 1);
        $this->pdo->exec(
            'CREATE TABLE fd_prism_payment_settlement (
                checkout_session_id TEXT PRIMARY KEY,
                credential TEXT NULL,
                status TEXT NOT NULL,
                transaction_hash TEXT NULL,
                network TEXT NULL,
                offered_accepts TEXT NULL,
                order_id BLOB NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NULL
            )',
        );
        $this->store = new DbalCredentialStore(new Connection($this->pdo));

        $this->pdo->prepare(
            'INSERT INTO fd_prism_payment_settlement (checkout_session_id, credential, status, offered_accepts, created_at)
             VALUES (:id, :credential, :status, :offer, :now)',
        )->execute([
            'id' => self::SESSION,
            'credential' => json_encode($this->credential()),
            'status' => SettlementStatus::PENDING,
            'offer' => json_encode([
                'quotedAmount' => '12.50',
                'quotedCurrency' => 'USD',
                'entry' => ['id' => HandlerId::PRISM, 'config' => ['resource' => ['url' => self::RESOURCE], 'accepts' => [self::ACCEPT]]],
            ]),
            'now' => '2026-09-30 00:00:00.000',
        ]);

        $this->gateway = new class implements PrismGateway {
            public array $script = [];

            public array $settled = [];

            public function paymentRequirements(
                PrismConfig $config,
                string $amount,
                string $currency,
                string $resourceUrl,
                ?string $resourceDescription,
            ): array {
                return [];
            }

            public function settle(PrismConfig $config, array $credential): SettleResult
            {
                $this->settled[] = $credential;
                $next = array_shift($this->script);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };

        $this->inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $this->inner->method('getCheckout')->willReturn($this->cart());
        $this->inner->method('completeCheckout')->willReturn($this->completed());

        $this->transactions = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactions->method('paid')->willReturnCallback(function (): void {
            ++$this->paidCalls;
            $this->transactionState = 'paid';
        });
        $this->transactions->method('fail')->willReturnCallback(function (): void {
            ++$this->failCalls;
            $this->transactionState = 'failed';
        });
    }

    public function testTransportFailureHoldsTheOrderAndRetryReusesTheSameCredential(): void
    {
        $this->gateway->script = [new PrismApiException('Prism POST /api/v2/payment/settle transport error: timeout'), $this->settled()];

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
        self::assertCount(2, $this->gateway->settled);
        self::assertSame($this->gateway->settled[0], $this->gateway->settled[1]);
    }

    public function testRetryInsideTheClaimWindowDoesNotCallPrismAgain(): void
    {
        $this->gateway->script = [new PrismApiException('Prism POST /api/v2/payment/settle transport error: timeout')];

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $again = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $again->status);
        self::assertCount(1, $this->gateway->settled);
        self::assertSame(0, $this->failCalls);
    }

    public function testSettlementPendingFromPrismHoldsTheOrder(): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, 'settlement_pending')];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
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
        $this->gateway->script = [new PrismApiException('Prism POST /api/v2/payment/settle failed', $httpStatus)];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
    }

    public function testClientErrorFromPrismIsADefiniteRejection(): void
    {
        $this->gateway->script = [new PrismApiException('Prism POST /api/v2/payment/settle failed: HTTP 400', 400)];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
            self::fail('A refused settle must fail the checkout.');
        } catch (ValidationException) {
            self::assertSame(SettlementStatus::FAILED, $this->rowStatus());
            self::assertSame(1, $this->failCalls);
        }
    }

    public function testDefiniteDeclineStillFailsTheOrder(): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, 'insufficient_funds')];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
            self::fail('A declined settle must fail the checkout.');
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
        $this->gateway->script = [new SettleResult(false, '', '', null, $reason)];

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
        $this->gateway->script = [new SettleResult(false, '', '', null, $reason)];

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
        $this->gateway->script = [new PrismApiException('timeout'), $second];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(json_encode($this->credential()), $this->rowCredential());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
    }

    public function testResumedRetryWithoutAnAnswerStaysHeldForTheSameCredential(): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), new PrismApiException('timeout again')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
    }

    public function testRetryWithTheSameCredentialInTheRequestResumesTheSettle(): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), $this->settled()];
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

    /**
     * The first settle landed but its answer was lost. A new authorization is forwarded to learn
     * that, Prism refuses it as already settled, and the order is paid by the first one's tx. The
     * new authorization is never stored.
     */
    public function testNewAuthorizationOnAHeldCheckoutPaysTheOrderWhenPrismReportsItSettled(): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), $this->alreadySettled('0xfirst')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $done = $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument($this->otherCredential())]),
            new RequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $done->status);
        self::assertSame($this->otherCredential(), $this->gateway->settled[1]);
        self::assertSame(['settled', '0xfirst', null], $this->row());
        self::assertSame(1, $this->paidCalls);
    }

    public function testNewAuthorizationOnAHeldCheckoutIsNotStoredWhenPrismRefusesIt(): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), new SettleResult(false, '', '', null, 'credential_already_used')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument($this->otherCredential())]),
            new RequestContext(),
        );

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(['settling', null, json_encode($this->credential())], $this->row());
        self::assertSame(0, $this->failCalls);
    }

    /** Prism released the first attempt (nothing moved), so the new authorization settles and is kept. */
    public function testNewAuthorizationOnAHeldCheckoutIsKeptWhenItIsTheOneThatSettles(): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), $this->settled()];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $this->adapter()->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::SESSION, [$this->instrument($this->otherCredential())]),
            new RequestContext(),
        );

        self::assertSame(['settled', '0xabc', json_encode($this->otherCredential())], $this->row());
    }

    /**
     * Declined, then paid with a new authorization: Prism reports the first one landed after all.
     * The transaction the decline failed is paid, and the unused authorization is dropped.
     */
    public function testAlreadySettledAfterADeclinePaysTheFailedTransaction(): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, 'insufficient_funds'), $this->alreadySettled('0xfirst')];

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
        self::assertSame(['settled', '0xfirst', null], $this->row());
        self::assertSame('paid', $this->transactionState);
    }

    /** I5: `resource.url` is not signed, so a credential pointing at another checkout is refused. */
    public function testCredentialNamingAnotherResourceIsRefusedBeforeTheOrder(): void
    {
        $this->inner->expects(self::never())->method('completeCheckout');

        $credential = $this->credential();
        $credential['paymentPayload']['resource']['url'] = 'https://shop.example/ucp/v1/checkout-sessions/cs_2';

        try {
            $this->adapter()->completeCheckoutFromRequest(
                new CheckoutCompleteRequest(self::SESSION, [$this->instrument($credential)]),
                new RequestContext(),
            );
            self::fail('A credential for another resource must be refused.');
        } catch (ValidationException) {
            self::assertSame([], $this->gateway->settled);
        }
    }

    public function testHeldCheckoutIsReportedAsInProgressWhenRead(): void
    {
        $this->gateway->script = [new PrismApiException('timeout')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        $this->inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $this->inner->method('getCheckout')->willReturn($this->completed());

        $read = $this->adapter()->getCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $read->status);
        self::assertNull($read->order);
    }

    public function testCancelIsRefusedWhileSettleOutcomeIsUnknown(): void
    {
        $this->gateway->script = [new PrismApiException('timeout')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        $this->inner->expects(self::never())->method('cancelCheckout');
        $this->expectException(ValidationException::class);

        $this->adapter()->cancelCheckout(self::SESSION, new RequestContext());
    }

    private function adapter(): PrismCheckoutAdapter
    {
        $config = $this->createMock(ConfigResolver::class);
        $config->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));

        $domains = $this->createMock(SalesChannelDomainResolver::class);
        $domains->method('resolveByBaseUri')->willReturn(new SalesChannelResolution('sc-1'));

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            fn (): array => ['id' => self::TRANSACTION, 'state' => $this->transactionState],
        );

        return new PrismCheckoutAdapter(
            $this->inner,
            $this->store,
            $this->gateway,
            $config,
            new RequestSalesChannelResolver($domains),
            $this->transactions,
            $this->createMock(EntityRepository::class),
            $connection,
            new SettlementStateMachine(),
            new AcceptsMatcher(),
        );
    }

    private function rowStatus(): string
    {
        return (string) $this->pdo->query(
            "SELECT status FROM fd_prism_payment_settlement WHERE checkout_session_id = '" . self::SESSION . "'",
        )->fetchColumn();
    }

    /** @return array{string, ?string, ?string} status, transaction hash, credential */
    private function row(): array
    {
        return $this->pdo->query(
            "SELECT status, transaction_hash, credential FROM fd_prism_payment_settlement WHERE checkout_session_id = '" . self::SESSION . "'",
        )->fetch(\PDO::FETCH_NUM);
    }

    private function rowCredential(): ?string
    {
        return $this->row()[2];
    }

    private function expireClaim(): void
    {
        $this->pdo->exec("UPDATE fd_prism_payment_settlement SET updated_at = '2026-09-30 00:00:00.000'");
    }

    private function credential(): array
    {
        return [
            'x402Version' => 2,
            'paymentPayload' => ['resource' => ['url' => self::RESOURCE], 'payload' => ['signature' => '0xsig']],
            'paymentRequirements' => self::ACCEPT,
        ];
    }

    private function otherCredential(): array
    {
        $credential = $this->credential();
        $credential['paymentPayload']['payload']['signature'] = '0xother';

        return $credential;
    }

    private function alreadySettled(string $transaction): SettleResult
    {
        return new SettleResult(false, $transaction, 'eip155:84532', null, 'checkout_already_settled');
    }

    private function instrument(array $credential): PaymentInstrument
    {
        return new PaymentInstrument(type: 'x402', handlerId: HandlerId::PRISM, credential: $credential);
    }

    private function settled(): SettleResult
    {
        return new SettleResult(true, '0xabc', 'eip155:84532', null, null);
    }

    private function completed(): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: CheckoutStatus::Completed,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', 12.50, null, 'USD')],
            order: new OrderConfirmation(self::ORDER),
        );
    }

    private function cart(): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: CheckoutStatus::ReadyForComplete,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', 12.50, null, 'USD')],
        );
    }
}
