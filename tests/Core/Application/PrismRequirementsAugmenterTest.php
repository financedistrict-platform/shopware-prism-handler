<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\HandlerDeclarationProvider;
use Fd\PrismPayment\Application\Ucp\PrismRequirementsAugmenter;
use Fd\PrismPayment\Application\Ucp\UcpVersionResolver;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\HandlerDeclarationSource;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use Fd\PrismPayment\Core\Ucp\HandlerDeclaration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelResolution;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/**
 * The quote's lifecycle: a cart change must drop the stored offer before Prism is asked for a new
 * one, so a re-quote that never completes cannot leave an older authorization something to settle
 * against.
 */
final class PrismRequirementsAugmenterTest extends TestCase
{
    private const SESSION = 'cs_1';

    private PrismGateway&MockObject $client;
    private CredentialStore&MockObject $store;
    private HandlerDeclarationSource&MockObject $source;

    protected function setUp(): void
    {
        $this->client = $this->createMock(PrismGateway::class);
        $this->store = $this->createMock(CredentialStore::class);
        $this->source = $this->createMock(HandlerDeclarationSource::class);
        $this->source->method('fetch')->willReturn(
            new HandlerDeclaration('xyz.fd.prism_payment', '2026-10-07', 'spec', 'schema', 'instrument'),
        );
    }

    /** SW-2: Prism is unreachable while re-quoting — the stale offer must not survive it. */
    public function testFailedRequoteLeavesNoOfferBehind(): void
    {
        $this->store->method('load')->willReturn($this->record('0.10'));
        $this->client->method('paymentRequirements')->willThrowException(new PrismApiException('gateway down'));

        $this->store->expects(self::once())->method('invalidateOffer')->with(self::SESSION);
        $this->store->expects(self::never())->method('recordOffer');

        $checkout = $this->augmenter()->augment($this->checkout(149.00), new RequestContext(new RuntimeConfiguration('2026-08-25')));

        self::assertArrayNotHasKey('payment_handlers', $checkout->extra, 'no handler is offered when the quote failed');
    }

    /** The quote is dropped before Prism is called, not after it answers. */
    public function testOfferIsInvalidatedBeforePrismIsAsked(): void
    {
        $this->store->method('load')->willReturn($this->record('0.10'));

        $calls = [];
        $this->store->method('invalidateOffer')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'invalidate';
        });
        $this->client->method('paymentRequirements')->willReturnCallback(static function () use (&$calls): array {
            $calls[] = 'prism';

            return ['accepts' => []];
        });
        $this->store->method('recordOffer')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'record';
        });

        $this->augmenter()->augment($this->checkout(149.00), new RequestContext(new RuntimeConfiguration('2026-08-25')));

        self::assertSame(['invalidate', 'prism', 'record'], $calls);
    }

    /**
     * A row that holds only an offer and no signature is invalidated too. It previously was not, so
     * its quote outlived the cart it was issued for.
     */
    public function testOfferOnlyRecordIsInvalidatedWhenTheCartChanges(): void
    {
        $this->store->method('load')->willReturn($this->record('0.10', credential: null));
        $this->client->method('paymentRequirements')->willThrowException(new PrismApiException('gateway down'));

        $this->store->expects(self::once())->method('invalidateOffer')->with(self::SESSION);
        $this->store->expects(self::never())->method('recordOffer');

        $this->augmenter()->augment($this->checkout(149.00), new RequestContext(new RuntimeConfiguration('2026-08-25')));
    }

    /** An unchanged cart reuses the stored offer: nothing is invalidated and Prism is not re-asked. */
    public function testUnchangedCartReusesTheStoredOffer(): void
    {
        $this->store->method('load')->willReturn($this->record('149.00'));

        $this->store->expects(self::never())->method('invalidateOffer');
        $this->client->expects(self::never())->method('paymentRequirements');
        $this->store->expects(self::never())->method('recordOffer');

        $checkout = $this->augmenter()->augment($this->checkout(149.00), new RequestContext(new RuntimeConfiguration('2026-08-25')));

        self::assertArrayHasKey('payment_handlers', $checkout->extra);
    }

    /** A completed session is never re-quoted — that is what keeps a settled row's offer frozen. */
    public function testCompletedSessionIsNeverRequoted(): void
    {
        $this->store->method('load')->willReturn($this->record('0.10'));

        $this->store->expects(self::never())->method('invalidateOffer');
        $this->client->expects(self::never())->method('paymentRequirements');

        $this->augmenter()->augment(
            $this->checkout(149.00, CheckoutStatus::Completed),
            new RequestContext(new RuntimeConfiguration('2026-08-25')),
        );
    }

    private function augmenter(): PrismRequirementsAugmenter
    {
        $config = $this->createMock(ConfigResolver::class);
        $config->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));
        $config->method('gatewayUrl')->willReturn('https://prism.test');

        $domains = $this->createMock(SalesChannelDomainResolver::class);
        $domains->method('resolveByBaseUri')->willReturn(new SalesChannelResolution('sc-1'));
        $salesChannels = new RequestSalesChannelResolver($domains);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback) => $callback($this->createMock(ItemInterface::class)),
        );

        $declarations = new HandlerDeclarationProvider(
            $config,
            $salesChannels,
            $this->source,
            $cache,
            $this->createMock(LoggerInterface::class),
            new UcpVersionResolver(),
        );

        return new PrismRequirementsAugmenter(
            $this->client,
            $config,
            $salesChannels,
            $this->store,
            $this->createMock(LoggerInterface::class),
            $declarations,
        );
    }

    /** @param array<string, mixed>|null $credential */
    private function record(string $quotedAmount, ?array $credential = ['paymentRequirements' => ['amount' => '1']]): PrismSettlementRecord
    {
        return new PrismSettlementRecord(
            checkoutSessionId: self::SESSION,
            credential: $credential,
            status: SettlementStatus::PENDING,
            transactionHash: null,
            network: null,
            offeredEntry: ['id' => 'xyz.fd.prism_payment', 'config' => ['accepts' => []]],
            quotedAmount: $quotedAmount,
            quotedCurrency: 'USD',
        );
    }

    private function checkout(float $total, CheckoutStatus $status = CheckoutStatus::ReadyForComplete): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: $status,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', $total, null, 'USD')],
            continueUrl: 'https://store.test/ucp/v1/checkout-sessions/' . self::SESSION,
        );
    }
}
