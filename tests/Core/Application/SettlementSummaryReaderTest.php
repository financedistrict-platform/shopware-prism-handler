<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Application;

use Fd\PrismPayment\Application\Admin\SettlementSummaryReader;
use Fd\PrismPayment\Core\Exception\PrismApiException;
use Fd\PrismPayment\Core\Payment\PrismConfig;
use Fd\PrismPayment\Core\Port\ConfigResolver;
use Fd\PrismPayment\Core\Port\SettlementReadModel;
use Fd\PrismPayment\Core\Port\SettlementSummarySource;
use Fd\PrismPayment\Core\Settlement\SettlementView;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SettlementSummaryReaderTest extends TestCase
{
    private const ORDER_ID = '11112222333344445555666677778888';
    private const CHANNEL_ID = 'aaaabbbbccccddddeeeeffff00001111';

    private const CONFIRMED = [
        'transaction' => '0xabc',
        'network' => 'eip155:84532',
        'asset' => 'USDC',
        'status' => 'confirmed',
        'gross' => '100.00',
        'fee' => '1.50',
        'cashback' => '5.00',
        'net' => '93.50',
    ];

    private SettlementReadModel&MockObject $readModel;
    private ConfigResolver&MockObject $resolver;
    private SettlementSummarySource&MockObject $source;

    protected function setUp(): void
    {
        $this->readModel = $this->createMock(SettlementReadModel::class);
        $this->resolver = $this->createMock(ConfigResolver::class);
        $this->source = $this->createMock(SettlementSummarySource::class);
    }

    public function testConfirmedUsesTheOrderSalesChannelKey(): void
    {
        $this->readModel->method('findByOrderId')->willReturn($this->view(self::CHANNEL_ID));
        $this->resolver->expects(self::once())->method('resolve')->with(self::CHANNEL_ID)
            ->willReturn(new PrismConfig('https://prism.test', 'key'));
        $this->source->expects(self::once())->method('settlementSummary')->willReturn(self::CONFIRMED);

        $summary = $this->reader()->forOrder(self::ORDER_ID);

        self::assertSame('confirmed', $summary?->state);
        self::assertSame('93.50', $summary->net);
    }

    public function testPendingWhileBroadcasted(): void
    {
        $this->readModel->method('findByOrderId')->willReturn($this->view(self::CHANNEL_ID));
        $this->resolver->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));
        $this->source->method('settlementSummary')->willReturn(['status' => 'broadcasted']);

        self::assertSame(['state' => 'pending'], $this->reader()->forOrder(self::ORDER_ID)?->toArray());
    }

    public function testPrismDownIsUnavailable(): void
    {
        $this->readModel->method('findByOrderId')->willReturn($this->view(self::CHANNEL_ID));
        $this->resolver->method('resolve')->willReturn(new PrismConfig('https://prism.test', 'key'));
        $this->source->method('settlementSummary')->willThrowException(new PrismApiException('down'));

        self::assertSame(['state' => 'unavailable'], $this->reader()->forOrder(self::ORDER_ID)?->toArray());
    }

    public function testChannelWithoutKeyIsUnavailableAndPrismIsNotCalled(): void
    {
        $this->readModel->method('findByOrderId')->willReturn($this->view(self::CHANNEL_ID));
        $this->resolver->method('resolve')->willThrowException(new PrismApiException('no key'));
        $this->source->expects(self::never())->method('settlementSummary');

        self::assertSame(['state' => 'unavailable'], $this->reader()->forOrder(self::ORDER_ID)?->toArray());
    }

    public function testUnknownSalesChannelIsUnavailableAndNoKeyIsRead(): void
    {
        $this->readModel->method('findByOrderId')->willReturn($this->view(null));
        $this->resolver->expects(self::never())->method('resolve');
        $this->source->expects(self::never())->method('settlementSummary');

        self::assertSame(['state' => 'unavailable'], $this->reader()->forOrder(self::ORDER_ID)?->toArray());
    }

    public function testNotAPrismOrderReturnsNull(): void
    {
        $this->readModel->method('findByOrderId')->willReturn(null);

        self::assertNull($this->reader()->forOrder(self::ORDER_ID));
    }

    private function reader(): SettlementSummaryReader
    {
        return new SettlementSummaryReader($this->readModel, $this->resolver, $this->source);
    }

    private function view(?string $salesChannelId): SettlementView
    {
        return new SettlementView(
            network: 'eip155:84532',
            asset: null,
            amountAtomic: null,
            transactionHash: '0xabc',
            settledAt: null,
            fiatAmount: null,
            fiatCurrency: null,
            payer: null,
            salesChannelId: $salesChannelId,
        );
    }
}
