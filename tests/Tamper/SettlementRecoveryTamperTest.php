<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class SettlementRecoveryTamperTest extends TestCase
{
    private const SESSION = 'session-1';

    private const REQUIREMENTS = [
        'scheme' => 'exact',
        'network' => 'base',
        'asset' => '0xusdc',
        'payTo' => '0xmerchant',
        'maxAmountRequired' => '10000000',
    ];

    private InMemorySettlementStore $store;

    private FrozenClock $clock;

    private ShopwareOrderTables $tables;

    private OrderPlacingCheckoutAdapter $inner;

    private RecordingTransactionStateHandler $stateHandler;

    private StubPrismGateway $gateway;

    private PrismCheckoutAdapter $adapter;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->store = new InMemorySettlementStore($this->clock);
        $this->tables = new ShopwareOrderTables();
        $this->inner = new OrderPlacingCheckoutAdapter($this->tables);
        $this->stateHandler = new RecordingTransactionStateHandler();
        $this->gateway = new StubPrismGateway();

        $this->adapter = new PrismCheckoutAdapter(
            $this->inner,
            $this->store,
            $this->gateway,
            $this->gateway,
            new RequestSalesChannelResolver(new SalesChannelDomainResolver()),
            $this->stateHandler,
            new EntityRepository(),
            $this->tables->connection(),
            new SettlementStateMachine(),
            new AcceptsMatcher(),
            $this->clock,
        );
    }

    public function testUnreachablePrismKeepsThePaymentInFlightAndRefusesASecondSignature(): void
    {
        $this->settleWhilePrismIsUnreachable();

        try {
            $this->adapter->updateCheckout(new CheckoutUpdateRequest(id: self::SESSION, payment: $this->signedInstrument('0xsecond')), new RequestContext());
            self::fail('A second signature was accepted while the first payment outcome is unknown.');
        } catch (ValidationException) {
        }

        self::assertSame(SettlementStatus::SETTLING, $this->store->rows[self::SESSION]['status']);
        self::assertSame(['signature' => '0xsig'], $this->store->rows[self::SESSION]['credential']['paymentPayload']);
    }

    public function testRetryInsideTheSettleWindowNeverResubmitsThePayment(): void
    {
        $this->settleWhilePrismIsUnreachable();
        $this->clock->advance(30);

        $this->expectCompleteRefused();

        self::assertSame(1, $this->gateway->settlements);
        self::assertSame(0, $this->tables->orderCount());
    }

    public function testStaleUnconfirmedPaymentIsReconciledWithTheSameSignatureAndPaid(): void
    {
        $this->settleWhilePrismIsUnreachable();
        $this->clock->advance(600);

        $this->adapter->completeCheckoutFromRequest(new CheckoutCompleteRequest(id: self::SESSION), new RequestContext());

        self::assertSame(2, $this->gateway->settlements);
        self::assertSame(SettlementStatus::SETTLED, $this->store->rows[self::SESSION]['status']);
        self::assertSame(['signature' => '0xsig'], $this->store->rows[self::SESSION]['credential']['paymentPayload']);
        self::assertSame(1, $this->tables->orderCount());
        self::assertCount(1, $this->stateHandler->paid);
    }

    public function testStaleUnconfirmedPaymentDeclinedOnRetryIsNeverReopenedForASecondPayment(): void
    {
        $this->settleWhilePrismIsUnreachable();
        $this->clock->advance(600);
        $this->gateway->settleOutcomes = ['declined'];

        $this->expectCompleteRefused();

        self::assertSame(SettlementStatus::SETTLING, $this->store->rows[self::SESSION]['status']);
        self::assertSame(0, $this->tables->orderCount());
        self::assertSame([], $this->stateHandler->paid);

        try {
            $this->adapter->updateCheckout(new CheckoutUpdateRequest(id: self::SESSION, payment: $this->signedInstrument('0xsecond')), new RequestContext());
            self::fail('A second signature was accepted after an unconfirmed payment was declined on retry.');
        } catch (ValidationException) {
        }
    }

    public function testDeclinedFirstSettleStillLetsTheBuyerSignAgain(): void
    {
        $this->recordOffer();
        $this->gateway->settleOutcomes = ['declined'];

        try {
            $this->complete('0xsig');
            self::fail('A declined settlement completed the checkout.');
        } catch (ValidationException) {
        }

        self::assertSame(SettlementStatus::FAILED, $this->store->rows[self::SESSION]['status']);

        $this->complete('0xsecond');

        self::assertSame(SettlementStatus::SETTLED, $this->store->rows[self::SESSION]['status']);
        self::assertSame(1, $this->tables->orderCount());
    }

    private function settleWhilePrismIsUnreachable(): void
    {
        $this->recordOffer();
        $this->gateway->settleOutcomes = ['unreachable'];

        try {
            $this->complete('0xsig');
            self::fail('The settlement was expected to hit a transport error.');
        } catch (PrismApiException) {
        }

        self::assertSame(SettlementStatus::SETTLING, $this->store->rows[self::SESSION]['status']);
        self::assertSame(0, $this->tables->orderCount());
    }

    private function recordOffer(): void
    {
        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', [
            'id' => HandlerId::PRISM,
            'config' => ['accepts' => [self::REQUIREMENTS]],
        ], $this->clock->now());
    }

    private function complete(string $signature): void
    {
        $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [$this->signedInstrument($signature)]),
            new RequestContext(),
        );
    }

    private function signedInstrument(string $signature): PaymentInstrument
    {
        return new PaymentInstrument(
            handlerId: HandlerId::PRISM,
            type: 'x402',
            credential: ['paymentPayload' => ['signature' => $signature], 'paymentRequirements' => self::REQUIREMENTS],
        );
    }

    private function expectCompleteRefused(): void
    {
        try {
            $this->adapter->completeCheckoutFromRequest(new CheckoutCompleteRequest(id: self::SESSION), new RequestContext());
            self::fail('A checkout with an unconfirmed payment was completed.');
        } catch (ValidationException) {
        }
    }
}
