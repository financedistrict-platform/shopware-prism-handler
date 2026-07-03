<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Settlement;

use Fd\PrismPayment\Core\Settlement\SettlementStateMachine;
use Fd\PrismPayment\Core\Settlement\SettlementStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettlementStateMachineTest extends TestCase
{
    private SettlementStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new SettlementStateMachine();
    }

    /**
     * Exhaustive (from, to) table over the full status vocabulary. Anything not explicitly
     * allowed is rejected — including every move OUT of `settled` (terminal, the F0 guarantee).
     */
    #[DataProvider('transitions')]
    public function testCanTransition(string $from, string $to, bool $expected): void
    {
        self::assertSame($expected, $this->sm->canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function transitions(): iterable
    {
        $all = [
            SettlementStatus::PENDING,
            SettlementStatus::SETTLING,
            SettlementStatus::SETTLED,
            SettlementStatus::FAILED,
        ];

        $allowed = [
            SettlementStatus::PENDING . '>' . SettlementStatus::SETTLING => true,
            SettlementStatus::PENDING . '>' . SettlementStatus::FAILED => true,
            SettlementStatus::SETTLING . '>' . SettlementStatus::SETTLED => true,
            SettlementStatus::SETTLING . '>' . SettlementStatus::FAILED => true,
            SettlementStatus::FAILED . '>' . SettlementStatus::PENDING => true,
        ];

        foreach ($all as $from) {
            foreach ($all as $to) {
                $key = $from . '>' . $to;
                yield $key => [$from, $to, $allowed[$key] ?? false];
            }
        }
    }

    public function testSettledIsTerminal(): void
    {
        self::assertTrue($this->sm->isTerminal(SettlementStatus::SETTLED));

        foreach ([SettlementStatus::PENDING, SettlementStatus::SETTLING, SettlementStatus::SETTLED, SettlementStatus::FAILED] as $to) {
            self::assertFalse(
                $this->sm->canTransition(SettlementStatus::SETTLED, $to),
                sprintf('settled must never transition to %s', $to),
            );
        }
    }

    #[DataProvider('terminalCases')]
    public function testIsTerminal(string $status, bool $expected): void
    {
        self::assertSame($expected, $this->sm->isTerminal($status));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function terminalCases(): iterable
    {
        yield 'pending' => [SettlementStatus::PENDING, false];
        yield 'settling' => [SettlementStatus::SETTLING, false];
        yield 'failed' => [SettlementStatus::FAILED, false];
        yield 'settled' => [SettlementStatus::SETTLED, true];
    }

    #[DataProvider('captureCases')]
    public function testMayCapture(string $status, bool $expected): void
    {
        self::assertSame($expected, $this->sm->mayCapture($status));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function captureCases(): iterable
    {
        yield 'pending may be (re)captured' => [SettlementStatus::PENDING, true];
        yield 'settling may NOT be recaptured (double-settle race)' => [SettlementStatus::SETTLING, false];
        yield 'failed may be recaptured (retry)' => [SettlementStatus::FAILED, true];
        yield 'settled may NOT be recaptured (F0)' => [SettlementStatus::SETTLED, false];
    }

    #[DataProvider('claimCases')]
    public function testMayClaimSettle(string $status, bool $expected): void
    {
        self::assertSame($expected, $this->sm->mayClaimSettle($status));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function claimCases(): iterable
    {
        yield 'only pending may be claimed' => [SettlementStatus::PENDING, true];
        yield 'settling may not be re-claimed' => [SettlementStatus::SETTLING, false];
        yield 'settled may not be claimed' => [SettlementStatus::SETTLED, false];
        yield 'failed may not be claimed (must recapture first)' => [SettlementStatus::FAILED, false];
    }
}
