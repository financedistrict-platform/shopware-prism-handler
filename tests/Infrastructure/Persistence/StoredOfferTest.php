<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Infrastructure\Persistence;

use Fd\PrismPayment\Infrastructure\Persistence\StoredOffer;
use PHPUnit\Framework\TestCase;

final class StoredOfferTest extends TestCase
{
    public function testQuoteSurvivesTheJsonRoundTrip(): void
    {
        $quotedAt = new \DateTimeImmutable('2026-10-09T17:00:00+07:00');
        $written = new StoredOffer(['config' => ['accepts' => [['payTo' => '0xmerchant']]]], '10.00', 'EUR', $quotedAt);

        $read = StoredOffer::fromArray(json_decode(json_encode($written->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));

        self::assertNotNull($read);
        self::assertSame($written->entry, $read->entry);
        self::assertSame('10.00', $read->quotedAmount);
        self::assertSame('EUR', $read->quotedCurrency);
        self::assertNotNull($read->quotedAt);
        self::assertSame($quotedAt->getTimestamp(), $read->quotedAt->getTimestamp());
    }

    public function testUnreadableQuoteTimeLoadsWithoutOne(): void
    {
        $read = StoredOffer::fromArray(['quotedAmount' => '10.00', 'quotedCurrency' => 'EUR', 'quotedAt' => 'yesterday', 'entry' => []]);

        self::assertNotNull($read);
        self::assertNull($read->quotedAt);
    }

    public function testOfferWithoutEntryIsNotAnOffer(): void
    {
        self::assertNull(StoredOffer::fromArray(['quotedAmount' => '10.00', 'quotedCurrency' => 'EUR']));
    }
}
