<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Payment;

use Fd\PrismPayment\Core\Payment\SettleResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettleResultTest extends TestCase
{
    public static function pendingReasons(): iterable
    {
        yield 'pending' => ['settlement_pending'];
        yield 'in progress' => ['checkout_settlement_in_progress'];
    }

    #[DataProvider('pendingReasons')]
    public function testOnlyTheStillRunningReasonsArePending(string $reason): void
    {
        $result = new SettleResult(false, '', '', null, $reason);

        self::assertTrue($result->isPending());
        self::assertFalse($result->isAlreadySettled());
    }

    public static function finalReasons(): iterable
    {
        yield 'quote not found' => ['quote_not_found'];
        yield 'quote mismatch' => ['quote_mismatch'];
        yield 'credential used' => ['credential_already_used'];
        yield 'before the facilitator' => ['unexpected_settle_error'];
        yield 'reverted' => ['transaction_reverted'];
        yield 'unlisted' => ['brand_new_reason'];
        yield 'null' => [null];
    }

    #[DataProvider('finalReasons')]
    public function testEveryOtherFailureIsFinal(?string $reason): void
    {
        $result = new SettleResult(false, '0xabc', '', null, $reason);

        self::assertFalse($result->isPending());
        self::assertFalse($result->isAlreadySettled());
    }

    public function testAlreadySettledIsNotPending(): void
    {
        $result = new SettleResult(false, '0xabc', 'eip155:97', null, 'checkout_already_settled');

        self::assertTrue($result->isAlreadySettled());
        self::assertFalse($result->isPending());
    }

    public function testSuccessIsNeitherPendingNorAlreadySettled(): void
    {
        $result = new SettleResult(true, '0xabc', 'eip155:84532', null, null);

        self::assertFalse($result->isPending());
        self::assertFalse($result->isAlreadySettled());
    }
}
