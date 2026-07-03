<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Payment;

use Fd\PrismPayment\Core\Payment\AcceptsMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The accept objects + submitted requirements here are taken verbatim from the real
 * 2026-06-16 e2e run (offered accepts[] from the create response; the submitted
 * paymentRequirements from the update request) — so this is a true regression anchor for F2.
 * Note the submitted object has the SAME fields as the offered FDUSD accept but a different
 * key order: the matcher must treat them as equal.
 */
final class AcceptsMatcherTest extends TestCase
{
    private AcceptsMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new AcceptsMatcher();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function offeredAccepts(): array
    {
        return [
            [
                'scheme' => 'exact',
                'network' => 'eip155:97',
                'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
                'maxTimeoutSeconds' => 300,
                'asset' => '0xaB27f55DB008704Ed8098f0dfBCf5e1aA387b9d9',
                'extra' => ['name' => 'First Digital USD', 'version' => '1'],
                'amount' => '14519727431181632408',
            ],
            [
                'scheme' => 'exact',
                'network' => 'eip155:84532',
                'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
                'maxTimeoutSeconds' => 300,
                'asset' => '0x036cbd53842c5426634e7929541ec2318f3dcf7e',
                'extra' => ['name' => 'USDC', 'version' => '2'],
                'amount' => '14498378',
            ],
            [
                'scheme' => 'exact',
                'network' => 'eip155:84532',
                'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
                'maxTimeoutSeconds' => 300,
                'asset' => '0x808456652fdb597867f38412077A9182bf77359F',
                'extra' => ['name' => 'EURC', 'version' => '2'],
                'amount' => '12506090',
            ],
        ];
    }

    /**
     * The submitted requirements (the agent's chosen accept) — same fields as the FDUSD accept
     * above, but with `amount` ordered differently. Deep equality must hold regardless of order.
     *
     * @return array<string, mixed>
     */
    private static function submittedFdusd(): array
    {
        return [
            'scheme' => 'exact',
            'network' => 'eip155:97',
            'amount' => '14519727431181632408',
            'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
            'maxTimeoutSeconds' => 300,
            'asset' => '0xaB27f55DB008704Ed8098f0dfBCf5e1aA387b9d9',
            'extra' => ['name' => 'First Digital USD', 'version' => '1'],
        ];
    }

    public function testMatchesAnOfferedAcceptIgnoringKeyOrder(): void
    {
        self::assertTrue($this->matcher->matches(self::offeredAccepts(), self::submittedFdusd()));
    }

    public function testMatchesEvenWithReorderedNestedObjectKeys(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['extra'] = ['version' => '1', 'name' => 'First Digital USD']; // nested keys reordered

        self::assertTrue($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsTamperedAmount(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['amount'] = '1'; // lowered

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsTamperedPayTo(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['payTo'] = '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsTamperedAsset(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['asset'] = '0x0000000000000000000000000000000000000000';

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsTamperedNetwork(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['network'] = 'eip155:1';

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsExtraAddedField(): void
    {
        $submitted = self::submittedFdusd();
        $submitted['surprise'] = true;

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testRejectsMissingField(): void
    {
        $submitted = self::submittedFdusd();
        unset($submitted['maxTimeoutSeconds']);

        self::assertFalse($this->matcher->matches(self::offeredAccepts(), $submitted));
    }

    public function testFailsClosedOnEmptyOfferedSet(): void
    {
        self::assertFalse($this->matcher->matches([], self::submittedFdusd()));
    }

    public function testMatchesOfferOtherThanTheFirst(): void
    {
        // The EURC accept (third in the list) must also match when it is the submitted one.
        $eurc = [
            'scheme' => 'exact',
            'network' => 'eip155:84532',
            'amount' => '12506090',
            'payTo' => '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd',
            'maxTimeoutSeconds' => 300,
            'asset' => '0x808456652fdb597867f38412077A9182bf77359F',
            'extra' => ['name' => 'EURC', 'version' => '2'],
        ];

        self::assertTrue($this->matcher->matches(self::offeredAccepts(), $eurc));
    }

    public function testSkipsNonArrayOfferedEntries(): void
    {
        $offered = ['not-an-object', 42, self::offeredAccepts()[0]];

        self::assertTrue($this->matcher->matches($offered, self::submittedFdusd()));
    }
}
