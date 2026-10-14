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
                'entry' => ['id' => HandlerId::PRISM, 'config' => ['accepts' => [self::ACCEPT]]],
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
        });
        $this->transactions->method('fail')->willReturnCallback(function (): void {
            ++$this->failCalls;
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

    public static function unknownOutcomeReasons(): iterable
    {
        yield 'gateway catch-all' => ['unexpected_settle_error'];
        yield 'receipt not seen' => ['receipt_timeout'];
        yield 'pending' => ['settlement_pending'];
        yield 'execution failed' => ['execution_failed: connection reset'];
        yield 'settlement failed' => ['settlement_failed'];
        yield 'transaction reverted' => ['transaction_reverted'];
        yield 'facilitator default' => ['facilitator_settlement_failed'];
        yield 'unlisted reason' => ['some_reason_from_a_newer_gateway'];
        yield 'no reason' => [null];
    }

    #[DataProvider('unknownOutcomeReasons')]
    public function testUnknownOutcomeReasonsHoldTheOrderAndNeverFreeTheBuyerToResign(?string $reason): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, $reason)];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::CompleteInProgress, $held->status);
        self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
    }

    public static function preBroadcastReasons(): iterable
    {
        foreach ([
            'unsupported_network',
            'unsupported_token',
            'point_of_service_not_found',
            'seller_wallet_not_configured',
            'sanctions_hit',
            'risk_threshold_exceeded',
            'affiliate_residual_overflow',
            'affiliate_settlement_unavailable',
            'cashback_outside_campaign_bounds',
            'invalid_payment_accepted_mismatch',
            'invalid_signature',
            'insufficient_funds',
            'balance_check_failed',
            'balance_verification_failed',
            'invalid_exact_evm_payload_authorization_expired',
            'invalid_exact_evm_payload_authorization_not_yet_valid',
            'invalid_exact_evm_payload_authorization_signature',
            'invalid_exact_evm_payload_recipient_mismatch',
            'invalid_authorization_recipient',
            'validation_failed: InvalidMint',
            'unknown_token: 0xabc on chain 84532',
            'authorization_mismatch',
        ] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('preBroadcastReasons')]
    public function testReasonsKnownToHappenBeforeBroadcastStillDeclineTheOrder(string $reason): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, $reason)];

        try {
            $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
            self::fail('A decline known to precede broadcast must fail the checkout.');
        } catch (ValidationException) {
            self::assertSame(SettlementStatus::FAILED, $this->rowStatus());
            self::assertSame(1, $this->failCalls);
        }
    }

    public static function resumedFailures(): iterable
    {
        yield 'used nonce' => [new SettleResult(false, '', '', null, 'execution_failed: Transaction would revert: authorization is used or canceled')];
        yield 'decline reason' => [new SettleResult(false, '', '', null, 'insufficient_funds')];
        yield 'catch-all' => [new SettleResult(false, '', '', null, 'unexpected_settle_error')];
        yield 'pending again' => [new SettleResult(false, '', '', null, 'settlement_pending')];
        yield 'consumed' => [new SettleResult(false, '', '', null, 'authorization_consumed')];
        yield 'client error' => [new PrismApiException('Prism POST /api/v2/payment/settle failed: HTTP 400', 400)];
    }

    #[DataProvider('resumedFailures')]
    public function testResumedRetryThatIsNotSuccessIsHeldForReviewNeverFailed(SettleResult|\Throwable $second): void
    {
        $this->gateway->script = [new PrismApiException('timeout'), $second];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::RequiresEscalation, $held->status);
        self::assertSame(SettlementStatus::UNRESOLVED, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);
        self::assertSame($this->gateway->settled[0], $this->gateway->settled[1]);

        $this->expireClaim();
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        self::assertCount(2, $this->gateway->settled);
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

    public static function reviewReasons(): iterable
    {
        yield 'authorization consumed' => ['authorization_consumed'];
        yield 'settlement unresolved' => ['settlement_unresolved'];
    }

    #[DataProvider('reviewReasons')]
    public function testReviewOutcomeLeavesTheOrderHeldAndIsNeverRetried(string $reason): void
    {
        $this->gateway->script = [new SettleResult(false, '', '', null, $reason)];

        $held = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::RequiresEscalation, $held->status);
        self::assertSame(SettlementStatus::UNRESOLVED, $this->rowStatus());
        self::assertSame(0, $this->failCalls);
        self::assertSame(0, $this->paidCalls);

        $this->expireClaim();
        $again = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(CheckoutStatus::RequiresEscalation, $again->status);
        self::assertCount(1, $this->gateway->settled);
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

    public function testRetryWithADifferentCredentialIsRefusedWhileSettling(): void
    {
        $this->gateway->script = [new PrismApiException('timeout')];
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
        $this->expireClaim();

        $other = $this->credential();
        $other['paymentPayload']['payload']['signature'] = '0xother';

        $this->expectException(ValidationException::class);

        try {
            $this->adapter()->completeCheckoutFromRequest(
                new CheckoutCompleteRequest(self::SESSION, [$this->instrument($other)]),
                new RequestContext(),
            );
        } finally {
            self::assertCount(1, $this->gateway->settled);
            self::assertSame(SettlementStatus::SETTLING, $this->rowStatus());
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
        $connection->method('fetchAssociative')->willReturn(['id' => self::TRANSACTION, 'state' => 'open']);

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

    private function expireClaim(): void
    {
        $this->pdo->exec("UPDATE fd_prism_payment_settlement SET updated_at = '2026-09-30 00:00:00.000'");
    }

    private function credential(): array
    {
        return [
            'x402Version' => 2,
            'paymentPayload' => ['payload' => ['signature' => '0xsig']],
            'paymentRequirements' => self::ACCEPT,
        ];
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
