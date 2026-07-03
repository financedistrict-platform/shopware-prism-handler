<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Settlement;

use Fd\PrismPayment\Core\Settlement\PrismSettlementRecord;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use PHPUnit\Framework\TestCase;

final class PrismSettlementRecordTest extends TestCase
{
    private const ENTRY = [
        'id' => 'x402',
        'version' => '2026-01-15',
        'config' => ['x402Version' => 2, 'accepts' => [['scheme' => 'exact', 'amount' => '12500000']]],
    ];

    public function testOfferOnlyRowHasNoCredential(): void
    {
        $record = new PrismSettlementRecord(
            checkoutSessionId: 'sess',
            paymentPayload: null,
            paymentRequirements: null,
            status: SettlementStatus::PENDING,
            transactionHash: null,
            network: null,
            offeredEntry: self::ENTRY,
            quotedAmount: '12.50',
            quotedCurrency: 'USD',
        );

        self::assertFalse($record->hasCredential());
        self::assertFalse($record->isSettled());
        self::assertFalse($record->isFailed());
    }

    public function testCapturedRowHasCredential(): void
    {
        $record = new PrismSettlementRecord(
            checkoutSessionId: 'sess',
            paymentPayload: ['x402Version' => 2],
            paymentRequirements: ['scheme' => 'exact'],
            status: SettlementStatus::PENDING,
            transactionHash: null,
            network: null,
        );

        self::assertTrue($record->hasCredential());
    }

    public function testOfferedAcceptsDerivesFromEntry(): void
    {
        $record = new PrismSettlementRecord('s', null, null, SettlementStatus::PENDING, null, null, self::ENTRY, '12.50', 'USD');

        self::assertSame([['scheme' => 'exact', 'amount' => '12500000']], $record->offeredAccepts());
    }

    public function testOfferedAcceptsNullWhenNoOffer(): void
    {
        $record = new PrismSettlementRecord('s', null, null, SettlementStatus::PENDING, null, null);

        self::assertNull($record->offeredAccepts());
    }

    public function testOfferMatchesQuoteOnlyForSameAmountAndCurrency(): void
    {
        $record = new PrismSettlementRecord('s', null, null, SettlementStatus::PENDING, null, null, self::ENTRY, '12.50', 'USD');

        self::assertTrue($record->offerMatchesQuote('12.50', 'USD'));
        self::assertFalse($record->offerMatchesQuote('25.00', 'USD'), 'a changed amount must re-quote');
        self::assertFalse($record->offerMatchesQuote('12.50', 'EUR'), 'a changed currency must re-quote');
    }

    public function testOfferMatchesQuoteFalseWhenNoOffer(): void
    {
        $record = new PrismSettlementRecord('s', null, null, SettlementStatus::PENDING, null, null);

        self::assertFalse($record->offerMatchesQuote('12.50', 'USD'));
    }

    public function testSettledAndFailedFlags(): void
    {
        $settled = new PrismSettlementRecord('s', ['a' => 1], ['b' => 2], SettlementStatus::SETTLED, '0xtx', 'eip155:97');
        self::assertTrue($settled->isSettled());
        self::assertFalse($settled->isFailed());

        $failed = new PrismSettlementRecord('s', ['a' => 1], ['b' => 2], SettlementStatus::FAILED, null, null);
        self::assertTrue($failed->isFailed());
        self::assertFalse($failed->isSettled());
    }
}
