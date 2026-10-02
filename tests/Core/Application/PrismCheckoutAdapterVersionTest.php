<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Doctrine\DBAL\Connection;
use Fd\PrismPayment\Application\SalesChannel\RequestSalesChannelResolver;
use Fd\PrismPayment\Application\Ucp\PrismCheckoutAdapter;
use Fd\PrismPayment\Application\Ucp\UcpVersionResolver;
use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\CredentialStore;
use Fd\PrismPayment\Core\Port\PrismGateway;
use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Model\RequestContext;

final class PrismCheckoutAdapterVersionTest extends TestCase
{
    public function testNoClaimWhenUcpVersionCannotBeResolved(): void
    {
        $accept = ['scheme' => 'exact', 'network' => 'base', 'amount' => '100'];
        $record = new PrismSettlementRecord(
            'cs_1',
            ['paymentRequirements' => $accept],
            SettlementStatus::PENDING,
            null,
            null,
            ['id' => 'prism', 'config' => ['accepts' => [$accept]]],
            '1.00',
            'USD',
        );

        $store = $this->createMock(CredentialStore::class);
        $store->method('load')->willReturn($record);
        $store->expects(self::never())->method('claim');
        $store->expects(self::never())->method('markFailed');

        $gateway = $this->createMock(PrismGateway::class);
        $gateway->expects(self::never())->method('settle');

        $adapter = new PrismCheckoutAdapter(
            $this->createMock(CheckoutAdapterInterface::class),
            $store,
            $gateway,
            $this->createMock(ConfigResolver::class),
            (new \ReflectionClass(RequestSalesChannelResolver::class))->newInstanceWithoutConstructor(),
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(),
            new SettlementStateMachine(),
            new AcceptsMatcher(),
            new UcpVersionResolver(),
        );

        $this->expectException(\LogicException::class);

        $adapter->completeCheckout('cs_1', new RequestContext());
    }
}
