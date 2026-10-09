<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Payment\SettleResult;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Ucp\HandlerId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelResolution;
use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Money;

/**
 * The decorator under test, wired to mocks, plus the session/offer/credential fixtures its
 * behaviour is described against. Shared so the complete path and the write-ordering contract
 * describe the same checkout rather than two that drift apart.
 */
abstract class CheckoutAdapterTestCase extends TestCase
{
    protected const SESSION = 'cs_1';
    protected const ORDER = 'order-1';
    protected const TRANSACTION = 'tx-1';
    protected const QUOTE = '12.50';

    protected const ACCEPT = [
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'asset' => '0x036cbd53842c5426634e7929541ec2318f3dcf7e',
        'amount' => '100142',
        'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
    ];

    protected const OFFER = ['id' => HandlerId::PRISM, 'config' => ['accepts' => [self::ACCEPT]]];

    protected PaymentAwareCheckoutAdapterInterface&MockObject $inner;
    protected CredentialStore&MockObject $store;
    protected PrismGateway&MockObject $gateway;
    protected OrderTransactionStateHandler&MockObject $transactions;
    protected EntityRepository&MockObject $transactionRepository;
    protected Connection&MockObject $connection;
    protected string $transactionState = 'open';

    protected function setUp(): void
    {
        $this->inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $this->store = $this->createMock(CredentialStore::class);
        $this->gateway = $this->createMock(PrismGateway::class);
        $this->transactions = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('fetchAssociative')->willReturnCallback(
            fn (): array => ['id' => self::TRANSACTION, 'state' => $this->transactionState],
        );
        // The pre-check reads the session; by default the cart still matches the quote.
        $this->inner->method('getCheckout')->willReturn($this->checkout(12.50));
    }

    protected function adapter(): PrismCheckoutAdapter
    {
        $config = $this->createMock(ConfigResolver::class);
        $config->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));

        $domains = $this->createMock(SalesChannelDomainResolver::class);
        $domains->method('resolveByBaseUri')->willReturn(new SalesChannelResolution('sc-1'));

        return new PrismCheckoutAdapter(
            $this->inner,
            $this->store,
            $this->gateway,
            $config,
            new RequestSalesChannelResolver($domains),
            $this->transactions,
            $this->transactionRepository,
            $this->connection,
            new SettlementStateMachine(),
            new AcceptsMatcher(),
        );
    }

    /** @param array<string, mixed>|null $credential */
    protected function record(string $status, ?array $credential, ?string $transactionHash = null): PrismSettlementRecord
    {
        return new PrismSettlementRecord(
            checkoutSessionId: self::SESSION,
            credential: $credential,
            status: $status,
            transactionHash: $transactionHash,
            network: null !== $transactionHash ? 'eip155:84532' : null,
            offeredEntry: self::OFFER,
            quotedAmount: self::QUOTE,
            quotedCurrency: 'USD',
        );
    }

    /**
     * @param array<string, mixed> $requirements
     *
     * @return array<string, mixed>
     */
    protected function credential(array $requirements = self::ACCEPT): array
    {
        return [
            'x402Version' => 2,
            'paymentPayload' => ['payload' => ['signature' => '0xsig']],
            'paymentRequirements' => $requirements,
        ];
    }

    /** The session once the order exists. */
    protected function completed(float $total): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: CheckoutStatus::Completed,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', $total, null, 'USD')],
            order: new OrderConfirmation(self::ORDER),
        );
    }

    /** The session as the pre-check reads it: a cart, no order yet. */
    protected function checkout(float $total): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: CheckoutStatus::ReadyForComplete,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', $total, null, 'USD')],
        );
    }

    protected function settleResult(): SettleResult
    {
        return new SettleResult(true, '0xabc', 'eip155:84532', null, null);
    }
}
