<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\HandlerDeclarationProvider;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Application\Ucp\PrismRequirementsAugmenter;
use Fd\PrismPayment\Application\Ucp\UcpVersionResolver;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

final class RequoteTamperTest extends TestCase
{
    private const SESSION = 'session-1';

    private const CHEAP_CART_REQUIREMENTS = [
        'scheme' => 'exact',
        'network' => 'base',
        'asset' => '0xusdc',
        'payTo' => '0xmerchant',
        'maxAmountRequired' => '10000000',
    ];

    private InMemorySettlementStore $store;

    private OrderPlacingCheckoutAdapter $inner;

    private RecordingTransactionStateHandler $stateHandler;

    private StubPrismGateway $gateway;

    private PrismRequirementsAugmenter $augmenter;

    private PrismCheckoutAdapter $adapter;

    protected function setUp(): void
    {
        $this->store = new InMemorySettlementStore();
        $tables = new ShopwareOrderTables();
        $this->inner = new OrderPlacingCheckoutAdapter($tables);
        $this->stateHandler = new RecordingTransactionStateHandler();
        $this->gateway = new StubPrismGateway();
        $salesChannels = new RequestSalesChannelResolver(new SalesChannelDomainResolver());

        $this->augmenter = new PrismRequirementsAugmenter(
            $this->gateway,
            $this->gateway,
            $salesChannels,
            $this->store,
            new SilentLogger(),
            new HandlerDeclarationProvider(
                $this->gateway,
                $salesChannels,
                new UnreachableDeclarationSource(),
                new UncachedCache(),
                new SilentLogger(),
                new UcpVersionResolver(new RuntimeConfiguration(version: '2026-04-08')),
            ),
        );

        $this->adapter = new PrismCheckoutAdapter(
            $this->inner,
            $this->store,
            $this->gateway,
            $this->gateway,
            $salesChannels,
            $this->stateHandler,
            new EntityRepository(),
            $tables->connection(),
            new SettlementStateMachine(),
            new AcceptsMatcher(),
        );

        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', [
            'id' => HandlerId::PRISM,
            'config' => ['accepts' => [self::CHEAP_CART_REQUIREMENTS]],
        ]);
    }

    public function testFailedRequoteWithdrawsTheOfferForTheOldCart(): void
    {
        $this->inner->cartTotal = '500.00';
        $this->gateway->requirementsUnavailable = true;

        $this->augmentCurrentCart();

        $record = $this->store->load(self::SESSION);
        self::assertNotNull($record);
        self::assertNull($record->offeredAccepts(), 'The offer for the old cart survived a failed re-quote.');
    }

    public function testOldCartOfferNeverSettlesABiggerCartAfterFailedRequote(): void
    {
        $this->inner->cartTotal = '500.00';
        $this->gateway->requirementsUnavailable = true;
        $this->augmentCurrentCart();

        try {
            $this->completeWithCheapCartSignature();
            self::fail('A bigger cart was completed with the old cart offer.');
        } catch (ValidationException) {
        }

        self::assertSame(0, $this->gateway->settlements);
        self::assertSame([], $this->stateHandler->paid);
    }

    public function testUnchangedCartKeepsItsOfferWhilePrismIsDown(): void
    {
        $this->gateway->requirementsUnavailable = true;
        $this->augmentCurrentCart();

        $this->completeWithCheapCartSignature();

        self::assertSame(1, $this->gateway->settlements);
        self::assertCount(1, $this->stateHandler->paid);
    }

    private function augmentCurrentCart(): void
    {
        $this->augmenter->augment(
            new Checkout(id: self::SESSION, currency: $this->inner->cartCurrency, totals: $this->inner->totals()),
            new RequestContext(),
        );
    }

    private function completeWithCheapCartSignature(): void
    {
        $this->adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(id: self::SESSION, instruments: [new PaymentInstrument(
                handlerId: HandlerId::PRISM,
                type: 'x402',
                credential: ['paymentPayload' => ['signature' => '0xsig'], 'paymentRequirements' => self::CHEAP_CART_REQUIREMENTS],
            )]),
            new RequestContext(),
        );
    }
}
