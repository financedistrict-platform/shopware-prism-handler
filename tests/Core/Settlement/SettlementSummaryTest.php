<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Settlement;

use Fd\PrismPayment\Core\Settlement\SettlementSummary;
use PHPUnit\Framework\TestCase;

final class SettlementSummaryTest extends TestCase
{
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

    public function testConfirmedBodyExposesTheSplit(): void
    {
        self::assertSame(
            ['state' => 'confirmed', 'net' => '93.50', 'cashback' => '5.00', 'fee' => '1.50', 'asset' => 'USDC'],
            SettlementSummary::fromPrism(self::CONFIRMED)->toArray(),
        );
    }

    public function testBroadcastedIsPending(): void
    {
        self::assertSame(['state' => 'pending'], SettlementSummary::fromPrism(['status' => 'broadcasted'] + self::CONFIRMED)->toArray());
    }

    public function testRejectedIsUnavailable(): void
    {
        self::assertSame(['state' => 'unavailable'], SettlementSummary::fromPrism(['status' => 'rejected'] + self::CONFIRMED)->toArray());
    }

    public function testConfirmedWithoutNetIsUnavailable(): void
    {
        $body = self::CONFIRMED;
        unset($body['net']);

        self::assertSame(['state' => 'unavailable'], SettlementSummary::fromPrism($body)->toArray());
    }
}
