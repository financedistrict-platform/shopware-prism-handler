<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class PaymentTamperTest extends TestCase
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

    private ShopwareOrderTables $tables;

    private OrderPlacingCheckoutAdapter $inner;

    private RecordingTransactionStateHandler $stateHandler;

    private StubPrismGateway $gateway;

    private PrismCheckoutAdapter $adapter;

    protected function setUp(): void
    {
        $this->store = new InMemorySettlementStore();
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
        );
    }

    public function testSettledSessionWhoseOrderFailedRefusesCartUpdate(): void
    {
        $this->settleCheapCartWithFailedPlacement();
        $this->inner->cartTotalOnUpdate = '500.00';

        try {
            $this->adapter->updateCheckout(new CheckoutUpdateRequest(id: self::SESSION), new RequestContext());
            self::fail('A cart update after settlement was accepted.');
        } catch (ValidationException) {
        }

        self::assertSame('10.00', $this->inner->cartTotal);
    }

    public function testSettledSessionNeverMarksABiggerOrderPaid(): void
    {
        $this->settleCheapCartWithFailedPlacement();
        $this->inner->cartTotal = '500.00';

        $this->expectCompleteRefused();

        self::assertSame([], $this->stateHandler->paid);
        self::assertSame(1, $this->gateway->settlements);
    }

    public function testSettledSessionNeverMarksAnOrderInAnotherCurrencyPaid(): void
    {
        $this->settleCheapCartWithFailedPlacement();
        $this->inner->cartCurrency = 'JPY';

        $this->expectCompleteRefused();

        self::assertSame([], $this->stateHandler->paid);
    }

    public function testSettledSessionWithoutSettledAmountIsNeverMarkedPaid(): void
    {
        $this->settleCheapCartWithFailedPlacement();
        $this->store->rows[self::SESSION]['settledAmount'] = null;
        $this->store->rows[self::SESSION]['settledCurrency'] = null;

        $this->expectCompleteRefused();

        self::assertSame([], $this->stateHandler->paid);
    }

    public function testSettledSessionCompletesTheSameCartAsPaid(): void
    {
        $this->settleCheapCartWithFailedPlacement();

        $this->adapter->completeCheckoutFromRequest(new CheckoutCompleteRequest(id: self::SESSION), new RequestContext());

        self::assertCount(1, $this->stateHandler->paid);
        self::assertSame(1, $this->gateway->settlements);
        self::assertSame(SettlementStatus::SETTLED, $this->store->rows[self::SESSION]['status']);
    }

    #[DataProvider('settledOrderCases')]
    public function testSettledRecordCoversOnlyItsOwnOrderTotal(
        ?string $quotedAmount,
        ?string $quotedCurrency,
        ?string $settledAmount,
        ?string $settledCurrency,
        string $orderAmount,
        string $orderCurrency,
        bool $covered,
    ): void {
        $record = new PrismSettlementRecord(
            checkoutSessionId: self::SESSION,
            credential: null,
            status: SettlementStatus::SETTLED,
            transactionHash: '0xsettled',
            network: 'base',
            quotedAmount: $quotedAmount,
            quotedCurrency: $quotedCurrency,
            settledAmount: $settledAmount,
            settledCurrency: $settledCurrency,
        );

        self::assertSame($covered, $record->settledFor($orderAmount, $orderCurrency));
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string, string, string, bool}>
     */
    public static function settledOrderCases(): iterable
    {
        yield 'same cart' => ['10.00', 'EUR', '10.00', 'EUR', '10', 'EUR', true];
        yield 'bigger order' => ['10.00', 'EUR', '10.00', 'EUR', '500', 'EUR', false];
        yield 'one cent more' => ['10.00', 'EUR', '10.00', 'EUR', '10.01', 'EUR', false];
        yield 'currency switch' => ['10.00', 'EUR', '10.00', 'EUR', '10.00', 'USD', false];
        yield 'zero-decimal currency' => ['1000.00', 'JPY', '1000.00', 'JPY', '1000', 'JPY', true];
        yield 'zero-decimal currency bigger order' => ['1000.00', 'JPY', '1000.00', 'JPY', '1001', 'JPY', false];
        yield 'three-decimal currency rounded quote' => ['1.23', 'KWD', '1.23', 'KWD', '1.234', 'KWD', false];
        yield 'quote re-recorded after settle' => ['500.00', 'EUR', '10.00', 'EUR', '500', 'EUR', false];
        yield 'missing settled amount' => ['10.00', 'EUR', null, null, '10', 'EUR', false];
        yield 'missing quote' => [null, null, '10.00', 'EUR', '10', 'EUR', false];
        yield 'unreadable order total' => ['10.00', 'EUR', '10.00', 'EUR', 'abc', 'EUR', false];
    }

    public function testCartChangedAfterQuoteIsRefusedBeforeSettlement(): void
    {
        $this->inner->cartTotal = '500.00';

        $this->expectSettlementRefused();
    }

    public function testCurrencySwitchedAfterQuoteIsRefusedBeforeSettlement(): void
    {
        $this->inner->cartCurrency = 'USD';

        $this->expectSettlementRefused();
    }

    public function testCartWithoutTotalIsRefusedBeforeSettlement(): void
    {
        $this->inner->cartHasTotal = false;

        $this->expectSettlementRefused();
    }

    public function testQuoteWithoutAmountIsRefusedBeforeSettlement(): void
    {
        $this->store->recordOffer(self::SESSION, '', 'EUR', $this->cheapCartOffer());

        $this->expectSettlementRefused();
    }

    public function testUnchangedCartSettlesAndIsMarkedPaid(): void
    {
        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', $this->cheapCartOffer());

        $this->completeWithCheapCartSignature();

        self::assertSame(1, $this->gateway->settlements);
        self::assertCount(1, $this->stateHandler->paid);
    }

    #[DataProvider('quotedCartCases')]
    public function testQuoteCoversOnlyTheCartItWasIssuedFor(
        ?string $quotedAmount,
        ?string $quotedCurrency,
        string $cartAmount,
        string $cartCurrency,
        bool $covered,
    ): void {
        $record = new PrismSettlementRecord(
            checkoutSessionId: self::SESSION,
            credential: null,
            status: SettlementStatus::PENDING,
            transactionHash: null,
            network: null,
            offeredEntry: $this->cheapCartOffer(),
            quotedAmount: $quotedAmount,
            quotedCurrency: $quotedCurrency,
        );

        self::assertSame($covered, $record->quotedFor($cartAmount, $cartCurrency));
    }

    public static function quotedCartCases(): iterable
    {
        yield 'same cart' => ['10.00', 'EUR', '10', 'EUR', true];
        yield 'bigger cart' => ['10.00', 'EUR', '500', 'EUR', false];
        yield 'one cent more' => ['10.00', 'EUR', '10.01', 'EUR', false];
        yield 'currency switch' => ['10.00', 'EUR', '10', 'USD', false];
        yield 'zero-decimal currency' => ['1000.00', 'JPY', '1000', 'JPY', true];
        yield 'three-decimal currency rounded quote' => ['1.23', 'KWD', '1.234', 'KWD', false];
        yield 'missing quote' => [null, null, '10', 'EUR', false];
        yield 'unreadable cart total' => ['10.00', 'EUR', 'abc', 'EUR', false];
    }

    public function testCartChangesAreRefusedOnceSettlementStarted(): void
    {
        $machine = new SettlementStateMachine();

        self::assertTrue($machine->mayChangeCart(SettlementStatus::PENDING));
        self::assertTrue($machine->mayChangeCart(SettlementStatus::FAILED));
        self::assertFalse($machine->mayChangeCart(SettlementStatus::SETTLING));
        self::assertFalse($machine->mayChangeCart(SettlementStatus::SETTLED));
    }

    private function settleCheapCartWithFailedPlacement(): void
    {
        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', [
            'id' => HandlerId::PRISM,
            'config' => ['accepts' => [self::REQUIREMENTS]],
        ]);
        $this->inner->failingPlacements = 1;

        try {
            $this->adapter->completeCheckoutFromRequest(
                new CheckoutCompleteRequest(id: self::SESSION, instruments: [new PaymentInstrument(
                    handlerId: HandlerId::PRISM,
                    type: 'x402',
                    credential: ['paymentPayload' => ['signature' => '0xsig'], 'paymentRequirements' => self::REQUIREMENTS],
                )]),
                new RequestContext(),
            );
            self::fail('The first order placement was expected to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Order placement failed.', $e->getMessage());
        }

        self::assertSame(SettlementStatus::SETTLED, $this->store->rows[self::SESSION]['status']);
    }

    private function expectSettlementRefused(): void
    {
        if (null === $this->store->load(self::SESSION)) {
            $this->store->recordOffer(self::SESSION, '10.00', 'EUR', $this->cheapCartOffer());
        }

        try {
            $this->completeWithCheapCartSignature();
            self::fail('A payment quoted for another cart was settled.');
        } catch (ValidationException) {
        }

        self::assertSame(0, $this->gateway->settlements, 'Prism settled a payment quoted for another cart.');
        self::assertSame([], $this->stateHandler->paid);
    }

    private function cheapCartOffer(): array
    {
        return ['id' => HandlerId::PRISM, 'config' => ['accepts' => [self::REQUIREMENTS]]];
    }

    private function completeWithCheapCartSignature(): void
    {
        $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [new PaymentInstrument(
                handlerId: HandlerId::PRISM,
                type: 'x402',
                credential: ['paymentPayload' => ['signature' => '0xsig'], 'paymentRequirements' => self::REQUIREMENTS],
            )]),
            new RequestContext(),
        );
    }

    private function expectCompleteRefused(): void
    {
        try {
            $this->adapter->completeCheckoutFromRequest(new CheckoutCompleteRequest(id: self::SESSION), new RequestContext());
            self::fail('The order was completed against a settlement for a different cart.');
        } catch (ValidationException) {
        }
    }
}
