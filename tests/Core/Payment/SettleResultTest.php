<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Payment;

use Fd\PrismPayment\Core\Payment\SettleResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettleResultTest extends TestCase
{
    public static function holdReasons(): iterable
    {
        yield 'catch-all' => ['unexpected_settle_error'];
        yield 'receipt timeout' => ['receipt_timeout'];
        yield 'pending' => ['settlement_pending'];
        yield 'used nonce' => ['execution_failed: authorization is used or canceled'];
        yield 'unlisted' => ['brand_new_reason'];
        yield 'empty' => [''];
        yield 'null' => [null];
    }

    #[DataProvider('holdReasons')]
    public function testEveryFailureNotKnownToPrecedeBroadcastIsPending(?string $reason): void
    {
        $result = new SettleResult(false, '', '', null, $reason);

        self::assertTrue($result->isPending());
        self::assertFalse($result->isDefiniteDecline());
        self::assertFalse($result->needsReview());
    }

    public static function declineReasons(): iterable
    {
        yield 'unsupported network' => ['unsupported_network'];
        yield 'sanctions' => ['sanctions_hit'];
        yield 'insufficient funds' => ['insufficient_funds'];
        yield 'bad signature' => ['invalid_exact_evm_payload_authorization_signature'];
        yield 'prefixed validation' => ['validation_failed: InvalidMint'];
        yield 'mismatch' => ['authorization_mismatch'];
    }

    #[DataProvider('declineReasons')]
    public function testReasonsKnownToPrecedeBroadcastAreDefiniteDeclines(string $reason): void
    {
        $result = new SettleResult(false, '', '', null, $reason);

        self::assertTrue($result->isDefiniteDecline());
        self::assertFalse($result->isPending());
        self::assertFalse($result->needsReview());
    }

    public static function reviewReasons(): iterable
    {
        yield 'consumed' => ['authorization_consumed'];
        yield 'unresolved' => ['settlement_unresolved'];
    }

    #[DataProvider('reviewReasons')]
    public function testConsumedAndUnresolvedNeedReviewOnly(string $reason): void
    {
        $result = new SettleResult(false, '', '', null, $reason);

        self::assertTrue($result->needsReview());
        self::assertFalse($result->isPending());
        self::assertFalse($result->isDefiniteDecline());
    }

    public function testSuccessIsNoneOfThose(): void
    {
        $result = new SettleResult(true, '0xabc', 'eip155:84532', null, null);

        self::assertFalse($result->isPending());
        self::assertFalse($result->isDefiniteDecline());
        self::assertFalse($result->needsReview());
    }
}
