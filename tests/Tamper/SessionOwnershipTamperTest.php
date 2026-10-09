<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
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

final class SessionOwnershipTamperTest extends TestCase
{
    private const SESSION = 'session-1';

    private const REQUIREMENTS = [
        'scheme' => 'exact',
        'network' => 'base',
        'asset' => '0xusdc',
        'payTo' => '0xmerchant',
        'maxAmountRequired' => '10000000',
    ];

    private const OWNER_CREDENTIAL = ['paymentPayload' => ['signature' => '0xowner'], 'paymentRequirements' => self::REQUIREMENTS];

    private const STRANGER_CREDENTIAL = ['paymentPayload' => ['signature' => '0xstranger'], 'paymentRequirements' => self::REQUIREMENTS];

    private InMemorySettlementStore $store;

    private FrozenClock $clock;

    private ShopwareOrderTables $tables;

    private OrderPlacingCheckoutAdapter $inner;

    private RecordingTransactionStateHandler $stateHandler;

    private StubPrismGateway $gateway;

    private PrismCheckoutAdapter $adapter;

    protected function setUp(): void
    {
        $this->store = new InMemorySettlementStore();
        $this->clock = new FrozenClock();
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

        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', [
            'id' => HandlerId::PRISM,
            'config' => ['accepts' => [self::REQUIREMENTS]],
        ], $this->clock->now());
        $this->store->capture(self::SESSION, self::OWNER_CREDENTIAL);
        $this->inner->foreignSessions = [self::SESSION];
    }

    public function testStrangerCannotReplaceTheCredentialOnUpdate(): void
    {
        $this->expectRefused(fn () => $this->adapter->updateCheckout(
            new CheckoutUpdateRequest(id: self::SESSION, payment: $this->prismInstrument()),
            new RequestContext(),
        ));

        $this->assertOwnerCredentialUntouched();
    }

    public function testStrangerCannotCaptureOnASessionWithoutPrismRecord(): void
    {
        $this->inner->foreignSessions = ['session-2'];

        $this->expectRefused(fn () => $this->adapter->updateCheckout(
            new CheckoutUpdateRequest(id: 'session-2', payment: $this->prismInstrument()),
            new RequestContext(),
        ));

        self::assertNull($this->store->load('session-2'));
    }

    public function testStrangerCannotClearTheCredentialOnUpdate(): void
    {
        $this->expectRefused(fn () => $this->adapter->updateCheckout(
            new CheckoutUpdateRequest(id: self::SESSION, payment: $this->invoiceInstrument()),
            new RequestContext(),
        ));

        $this->assertOwnerCredentialUntouched();
    }

    public function testStrangerCannotReplaceTheCredentialOnComplete(): void
    {
        $this->expectRefused(fn () => $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [$this->prismInstrument()]),
            new RequestContext(),
        ));

        $this->assertOwnerCredentialUntouched();
    }

    public function testStrangerCannotClearTheCredentialOnCompleteWithAnotherMethod(): void
    {
        $this->expectRefused(fn () => $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [$this->invoiceInstrument()]),
            new RequestContext(),
        ));

        $this->assertOwnerCredentialUntouched();
    }

    public function testStrangerCannotForceSettlement(): void
    {
        $this->expectRefused(fn () => $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION),
            new RequestContext(),
        ));

        $this->assertOwnerCredentialUntouched();
    }

    public function testStrangerCannotClearTheCredentialOnCancel(): void
    {
        $this->expectRefused(fn () => $this->adapter->cancelCheckout(self::SESSION, new RequestContext()));

        $this->assertOwnerCredentialUntouched();
    }

    public function testUnknownSessionTheBaseLetsThroughCannotCaptureOnUpdate(): void
    {
        $this->inner->foreignSessions = [];

        $this->expectNoOffer(fn () => $this->adapter->updateCheckout(
            new CheckoutUpdateRequest(id: 'session-unknown', payment: $this->prismInstrument()),
            new RequestContext(),
        ));

        self::assertNull($this->store->load('session-unknown'));
    }

    public function testUnknownSessionTheBaseLetsThroughCannotCaptureOnComplete(): void
    {
        $this->inner->foreignSessions = [];

        $this->expectNoOffer(fn () => $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: 'session-unknown', instruments: [$this->prismInstrument()]),
            new RequestContext(),
        ));

        self::assertNull($this->store->load('session-unknown'));
        self::assertSame(0, $this->gateway->settlements);
        self::assertSame(0, $this->tables->orderCount());
    }

    public function testWithdrawnOfferCannotBeAnsweredWithANewCredential(): void
    {
        $this->inner->foreignSessions = [];
        $this->store->releaseToBase(self::SESSION);
        $this->store->withdrawOffer(self::SESSION);

        $this->expectNoOffer(fn () => $this->adapter->updateCheckout(
            new CheckoutUpdateRequest(id: self::SESSION, payment: $this->prismInstrument()),
            new RequestContext(),
        ));

        self::assertNull($this->store->rows[self::SESSION]['credential']);
    }

    public function testOwnerStillSettlesAndIsMarkedPaid(): void
    {
        $this->inner->foreignSessions = [];

        $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [new PaymentInstrument(
                handlerId: HandlerId::PRISM,
                type: 'x402',
                credential: self::OWNER_CREDENTIAL,
            )]),
            new RequestContext(),
        );

        self::assertSame(1, $this->gateway->settlements);
        self::assertCount(1, $this->stateHandler->paid);
        self::assertSame(SettlementStatus::SETTLED, $this->store->rows[self::SESSION]['status']);
    }

    private function expectRefused(\Closure $action): void
    {
        try {
            $action();
            self::fail('A session action touched the Prism payment before the base adapter authorized the session.');
        } catch (\RuntimeException $e) {
            self::assertSame('Checkout session not found.', $e->getMessage());
        }
    }

    private function expectNoOffer(\Closure $action): void
    {
        try {
            $action();
            self::fail('A credential was captured for a checkout without a Prism offer.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('no Prism payment offer', $e->getMessage());
        }
    }

    private function assertOwnerCredentialUntouched(): void
    {
        self::assertSame(self::OWNER_CREDENTIAL, $this->store->rows[self::SESSION]['credential']);
        self::assertSame(SettlementStatus::PENDING, $this->store->rows[self::SESSION]['status']);
        self::assertSame(0, $this->gateway->settlements);
        self::assertSame([], $this->stateHandler->paid);
    }

    private function prismInstrument(): PaymentInstrument
    {
        return new PaymentInstrument(handlerId: HandlerId::PRISM, type: 'x402', credential: self::STRANGER_CREDENTIAL);
    }

    private function invoiceInstrument(): PaymentInstrument
    {
        return new PaymentInstrument(handlerId: 'com.example.invoice', type: 'invoice');
    }
}
