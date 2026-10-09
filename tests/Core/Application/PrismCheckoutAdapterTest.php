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
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
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
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

/**
 * The complete path of the checkout decorator after SW-1: the order is placed before any money
 * moves, the payment is bound to the quote by identity (not amount), and a mismatch or a rejected
 * order settles nothing.
 */
final class PrismCheckoutAdapterTest extends TestCase
{
    private const SESSION = 'cs_1';
    private const ORDER = 'order-1';
    private const TRANSACTION = 'tx-1';
    private const QUOTE = '12.50';

    private const ACCEPT = [
        'scheme' => 'exact',
        'network' => 'eip155:84532',
        'asset' => '0x036cbd53842c5426634e7929541ec2318f3dcf7e',
        'amount' => '100142',
        'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
    ];

    private const OFFER = ['id' => 'xyz.fd.prism_payment', 'config' => ['accepts' => [self::ACCEPT]]];

    private PaymentAwareCheckoutAdapterInterface&MockObject $inner;
    private CredentialStore&MockObject $store;
    private PrismGateway&MockObject $gateway;
    private OrderTransactionStateHandler&MockObject $transactions;
    private EntityRepository&MockObject $transactionRepository;
    private Connection&MockObject $connection;
    private string $transactionState = 'open';

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

    /** The cart moved after signing: refused before an order is ever placed, so the session survives. */
    public function testCartNoLongerMatchingQuoteIsRefusedBeforeOrderIsPlaced(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));

        $inner = $this->createMock(PaymentAwareCheckoutAdapterInterface::class);
        $inner->method('getCheckout')->willReturn($this->checkout(149.00)); // quote was 12.50
        $inner->expects(self::never())->method('completeCheckout');
        $this->inner = $inner;

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::never())->method('fail');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cart changed after this payment was authorized');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** Rule C: a credential outside the stored offer is refused before the order is ever placed. */
    public function testCredentialNotInStoredOfferIsRefusedBeforeOrderIsPlaced(): void
    {
        $foreign = self::ACCEPT;
        $foreign['amount'] = '1';
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential($foreign)));

        $this->inner->expects(self::never())->method('completeCheckout');
        $this->gateway->expects(self::never())->method('settle');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match any offer');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** D1: a base rejection throws before the order exists, so nothing is ever settled. */
    public function testRejectedOrderSettlesNothing(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')
            ->willThrowException(new ValidationException('$.checkout_session.buyer.email is required'));

        $this->gateway->expects(self::never())->method('settle');
        $this->store->expects(self::never())->method('claim');
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('buyer.email');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /**
     * The backstop: the cart agreed with the quote, but Shopware's recalculation produced a different
     * order total. Caught after placement — transaction failed, nothing settled.
     */
    public function testOrderTotalDifferentFromQuoteAbandonsWithoutSettling(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')->willReturn($this->completed(149.00)); // cart said 12.50

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::once())->method('fail')->with(self::TRANSACTION);
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match the amount');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** Happy path: order placed (open) → process → settle → paid. */
    public function testOrderPlacedThenSettledThenPaid(): void
    {
        $this->transactionState = 'open';
        $this->settledRowAfterClaim();
        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->store->method('claim')->willReturn(true);

        $this->transactions->expects(self::once())->method('process')->with(self::TRANSACTION);
        $this->gateway->expects(self::once())->method('settle')->willReturn($this->settleResult());
        $this->transactions->expects(self::once())->method('paid')->with(self::TRANSACTION);
        $this->store->expects(self::once())->method('linkOrder')->with(self::SESSION, self::ORDER);

        $checkout = $this->adapter()->completeCheckout(self::SESSION, new RequestContext());

        self::assertSame(self::ORDER, $checkout->order?->id);
    }

    /** A settle failure fails the transaction and never marks it paid. */
    public function testSettleFailureAbandonsTheTransaction(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT)));
        $this->inner->method('completeCheckout')->willReturn($this->completed(12.50));
        $this->store->method('claim')->willReturn(true);
        $this->gateway->method('settle')->willReturn(new SettleResult(false, '', '', null, 'declined'));

        $this->transactions->expects(self::once())->method('fail')->with(self::TRANSACTION);
        $this->transactions->expects(self::never())->method('paid');

        $this->expectException(ValidationException::class);
        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    /** An offer-only record (agent never chose Prism) defers to the base and settles nothing. */
    public function testOfferOnlyRecordDefersToBase(): void
    {
        $this->store->method('load')->willReturn($this->record(SettlementStatus::PENDING, null));
        $this->inner->expects(self::once())->method('completeCheckout')->willReturn($this->completed(12.50));

        $this->gateway->expects(self::never())->method('settle');
        $this->transactions->expects(self::never())->method('paid');

        $this->adapter()->completeCheckout(self::SESSION, new RequestContext());
    }

    private function adapter(): PrismCheckoutAdapter
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

    /** After a won claim, a reload of the row must read back as settled (the adapter asserts this). */
    private function settledRowAfterClaim(): void
    {
        $settled = $this->record(SettlementStatus::SETTLED, $this->credential(self::ACCEPT), transactionHash: '0xabc');
        $pending = $this->record(SettlementStatus::PENDING, $this->credential(self::ACCEPT));
        $loads = [$pending, $settled];
        $this->store->method('load')->willReturnCallback(static function () use (&$loads, $settled) {
            return array_shift($loads) ?? $settled;
        });
    }

    /** @param array<string, mixed>|null $credential */
    private function record(string $status, ?array $credential, ?string $transactionHash = null): PrismSettlementRecord
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
    private function credential(array $requirements): array
    {
        return [
            'x402Version' => 2,
            'paymentPayload' => ['payload' => ['signature' => '0xsig']],
            'paymentRequirements' => $requirements,
        ];
    }

    private function completed(float $total): Checkout
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
    private function checkout(float $total): Checkout
    {
        return new Checkout(
            id: self::SESSION,
            status: CheckoutStatus::ReadyForComplete,
            currency: 'USD',
            lineItems: [],
            totals: [new Money('total', $total, null, 'USD')],
        );
    }

    private function settleResult(): SettleResult
    {
        return new SettleResult(true, '0xabc', 'eip155:84532', null, null);
    }
}
