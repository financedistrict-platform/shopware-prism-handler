<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Tamper;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\HandlerDeclarationProvider;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Application\Ucp\PrismPaymentHandler;
use Fd\PrismPayment\Application\Ucp\PrismRequirementsAugmenter;
use Fd\PrismPayment\Application\Ucp\UcpVersionResolver;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
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

final class QuoteExpiryTamperTest extends TestCase
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

    private RecordingTransactionStateHandler $stateHandler;

    private StubPrismGateway $gateway;

    private OrderPlacingCheckoutAdapter $inner;

    private PrismRequirementsAugmenter $augmenter;

    private PrismCheckoutAdapter $adapter;

    protected function setUp(): void
    {
        $this->store = new InMemorySettlementStore();
        $this->clock = new FrozenClock();
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
            $this->clock,
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
            $this->clock,
            new SilentLogger(),
        );

        $this->store->recordOffer(self::SESSION, '10.00', 'EUR', [
            'id' => HandlerId::PRISM,
            'config' => ['accepts' => [self::REQUIREMENTS]],
        ], $this->clock->now());
    }

    public function testExpiredQuoteIsRefusedBeforeSettlement(): void
    {
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS + 1);

        $this->expectCompleteRefused();
    }

    public function testQuoteHeldForHoursIsRefusedBeforeSettlement(): void
    {
        $this->clock->advance(6 * 3600);

        $this->expectCompleteRefused();
    }

    public function testQuoteWithoutQuoteTimeIsRefusedBeforeSettlement(): void
    {
        $this->store->rows[self::SESSION]['quotedAt'] = null;

        $this->expectCompleteRefused();
    }

    public function testQuoteStillWithinItsLifetimeSettles(): void
    {
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS - 1);

        $this->completeWithSignature();

        self::assertSame(1, $this->gateway->settlements);
        self::assertCount(1, $this->stateHandler->paid);
    }

    public function testExpiredOfferIsRequotedInsteadOfServedAgain(): void
    {
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS + 1);

        $this->augmentCurrentCart();

        $record = $this->store->load(self::SESSION);
        self::assertNotNull($record);
        self::assertEquals($this->clock->now(), $record->quotedAt, 'An expired offer was served again instead of re-quoted.');
    }

    public function testOfferAboutToExpireIsRequotedInsteadOfServedAgain(): void
    {
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS - 30);

        $this->augmentCurrentCart();

        $record = $this->store->load(self::SESSION);
        self::assertNotNull($record);
        self::assertEquals($this->clock->now(), $record->quotedAt, 'An offer with seconds left to live was served again.');
    }

    public function testExpiredOfferDropsTheCredentialSignedForIt(): void
    {
        $this->store->capture(self::SESSION, ['paymentPayload' => ['signature' => '0xsig'], 'paymentRequirements' => self::REQUIREMENTS]);
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS + 1);

        $this->augmentCurrentCart();

        $record = $this->store->load(self::SESSION);
        self::assertNotNull($record);
        self::assertFalse($record->hasCredential(), 'A credential signed for an expired offer survived the re-quote.');
        self::assertSame(SettlementStatus::FAILED, $record->status);
    }

    public function testExpiredOfferIsWithdrawnWhenPrismCannotRequote(): void
    {
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS + 1);
        $this->gateway->requirementsUnavailable = true;

        $this->augmentCurrentCart();

        $record = $this->store->load(self::SESSION);
        self::assertNotNull($record);
        self::assertNull($record->offeredAccepts(), 'An expired offer stayed on the session after a failed re-quote.');
    }

    public function testSettledSessionKeepsServingTheOfferItSettledAfterExpiry(): void
    {
        $this->store->rows[self::SESSION]['status'] = SettlementStatus::SETTLED;
        $this->clock->advance(PrismSettlementRecord::QUOTE_TTL_SECONDS + 1);

        $checkout = $this->augmentCurrentCart();

        self::assertSame(
            [['id' => HandlerId::PRISM, 'config' => ['accepts' => [self::REQUIREMENTS]]]],
            $checkout->extra['payment_handlers'][PrismPaymentHandler::HANDLER_ID] ?? null,
            'A settled session advertised a new offer that is not on record.',
        );
    }

    private function augmentCurrentCart(): Checkout
    {
        return $this->augmenter->augment(
            new Checkout(id: self::SESSION, currency: $this->inner->cartCurrency, totals: $this->inner->totals()),
            new RequestContext(),
        );
    }

    private function completeWithSignature(): void
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
            $this->completeWithSignature();
            self::fail('A checkout settled against an expired quote.');
        } catch (ValidationException) {
        }

        self::assertSame(0, $this->gateway->settlements);
        self::assertSame([], $this->stateHandler->paid);
    }
}
